<?php

/**
 * WCAIG Worker — Background queue worker for processing image generation tasks.
 *
 * @package WC_AI_Image_Gen
 */

if (! defined('ABSPATH')) {
    exit;
}

class WCAIG_Worker
{
    private static ?WCAIG_Worker $instance = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('wcaig_worker_cron', [ $this, 'run' ]);

        // Self-healing: ensure cron event is always scheduled (survives migration).
        add_action('init', [ $this, 'ensure_cron_scheduled' ]);
    }

    /**
     * Ensure the worker cron event is scheduled.
     * Self-healing mechanism that re-schedules if the event was lost (e.g. after site migration).
     */
    public function ensure_cron_scheduled(): void
    {
        if (! wp_next_scheduled('wcaig_worker_cron')) {
            wp_schedule_event(time(), 'wcaig_every_minute', 'wcaig_worker_cron');
            WCAIG_Logger::instance()->info('Worker: re-scheduled missing wcaig_worker_cron event.');
        }
    }

    /**
     * Run one worker cycle.
     */
    public function run(): void
    {
        // Acquire mutex.
        if (get_transient('wcaig_worker_running')) {
            WCAIG_Logger::instance()->debug('Worker: mutex held, skipping run.');
            return;
        }

        set_transient('wcaig_worker_running', true, 5 * MINUTE_IN_SECONDS);

        try {
            // Step 1: Poll processing tasks for completion.
            $this->poll_processing_tasks();

            // Step 2: Handle timed-out processing posts.
            $this->handle_timeouts();

            // Step 3: Process draft queue.
            $this->process_queue();
        } finally {
            // Release mutex.
            delete_transient('wcaig_worker_running');
        }
    }

    /**
     * Poll PIAPI for status of all processing tasks (webhook fallback).
     */
    private function poll_processing_tasks(): void
    {
        $processing_posts = get_posts([
            'post_type'      => 'image_variation',
            'post_status'    => 'processing',
            'posts_per_page' => 20,
            'orderby'        => 'modified',
            'order'          => 'ASC',
        ]);

        if (empty($processing_posts)) {
            return;
        }

        WCAIG_Logger::instance()->debug('Worker poll: checking ' . count($processing_posts) . ' processing tasks.');

        $api = WCAIG_API_Client::instance();

        foreach ($processing_posts as $post) {
            $task_id = get_field('wcaig_task_id', $post->ID);
            if (empty($task_id)) {
                continue;
            }

            $task_data = $api->fetch_task($task_id);
            if (is_wp_error($task_data)) {
                WCAIG_Logger::instance()->warning("Worker poll: fetch failed for task={$task_id}: {$task_data->get_error_message()}");
                continue;
            }

            $status = $task_data['status'] ?? '';

            if ($status === 'completed') {
                $this->handle_poll_completed($post, $task_data);
            } elseif ($status === 'failed') {
                $this->handle_poll_failed($post, $task_id, $task_data);

                // If rate-limited, stop polling remaining tasks.
                if ($this->is_rate_limited()) {
                    WCAIG_Logger::instance()->info('Worker poll: rate-limit detected, stopping poll cycle.');
                    break;
                }
            }
            // 'processing' / 'pending' — do nothing, wait for next cycle.
        }
    }

    /**
     * Handle a completed task discovered by polling.
     */
    private function handle_poll_completed(WP_Post $post, array $task_data): void
    {
        // Idempotency: skip already-published posts.
        if ($post->post_status === 'publish') {
            return;
        }

        $hash = str_replace('variation_', '', $post->post_name);

        // Extract image URL (same structure as webhook response).
        $image_url = $this->extract_image_url_from_task($task_data);

        if (empty($image_url)) {
            WCAIG_Logger::instance()->error("Worker poll: no image URL in task data for post {$post->ID}");
            return;
        }

        // Sideload image.
        $attachment_id = WCAIG_API_Client::instance()->sideload_image($image_url, $post->ID, $hash);

        if (is_wp_error($attachment_id)) {
            WCAIG_Logger::instance()->error("Worker poll: sideload failed for post {$post->ID}: {$attachment_id->get_error_message()}");
            return;
        }

        // Set as featured image.
        set_post_thumbnail($post->ID, $attachment_id);

        // Publish the post.
        wp_update_post([
            'ID'          => $post->ID,
            'post_status' => 'publish',
        ]);

        // Cache the image URL.
        $attachment_url = wp_get_attachment_url($attachment_id);
        if ($attachment_url) {
            set_transient("wcaig_image_{$hash}", $attachment_url, DAY_IN_SECONDS);
        }

        WCAIG_Logger::instance()->info("Worker poll: published variation {$hash} (post {$post->ID})");
    }

    /**
     * Handle a failed task discovered by polling.
     */
    private function handle_poll_failed(WP_Post $post, string $task_id, array $task_data = []): void
    {
        // Check if it's a rate-limit failure ("too many requests" in PIAPI logs).
        if ($this->is_rate_limit_failure($task_data)) {
            $this->set_rate_limit_cooldown();

            // Reset to draft without burning a retry — this was not our fault.
            wp_update_post([
                'ID'          => $post->ID,
                'post_status' => 'draft',
            ]);
            update_field('wcaig_task_id', '', $post->ID);
            WCAIG_Logger::instance()->warning("Worker poll: rate-limited for variation {$post->ID} (task={$task_id}), reset to draft without burning retry.");
            return;
        }

        $max_retries = $this->get_option('wcaig_retry_count', 3);
        $retry_count = (int) get_field('wcaig_retry_count', $post->ID);
        $retry_count++;

        update_field('wcaig_retry_count', $retry_count, $post->ID);

        if ($retry_count >= $max_retries) {
            wp_update_post([
                'ID'          => $post->ID,
                'post_status' => 'failed',
            ]);
            WCAIG_Logger::instance()->error("Worker poll: variation {$post->ID} failed after {$retry_count} retries (task={$task_id}).");
        } else {
            wp_update_post([
                'ID'          => $post->ID,
                'post_status' => 'draft',
            ]);
            update_field('wcaig_task_id', '', $post->ID);
            WCAIG_Logger::instance()->info("Worker poll: variation {$post->ID} failed, reset for retry ({$retry_count}/{$max_retries}).");
        }
    }

    /**
     * Check if PIAPI task data indicates a rate-limit failure.
     */
    private function is_rate_limit_failure(array $task_data): bool
    {
        // Check logs array for "too many requests".
        $logs = $task_data['logs'] ?? [];
        foreach ($logs as $log) {
            if (is_string($log) && stripos($log, 'too many requests') !== false) {
                return true;
            }
        }

        // Check error message.
        $error_msg = $task_data['error']['message'] ?? '';
        if (stripos($error_msg, 'too many requests') !== false) {
            return true;
        }

        $raw_msg = $task_data['error']['raw_message'] ?? '';
        if (stripos($raw_msg, 'too many requests') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Set a rate-limit cooldown transient to pause queue processing.
     */
    private function set_rate_limit_cooldown(): void
    {
        $cooldown_seconds = (int) $this->get_option('wcaig_rate_limit_cooldown', 120);
        set_transient('wcaig_rate_limit_cooldown', time(), $cooldown_seconds);
        WCAIG_Logger::instance()->info("Worker: rate-limit cooldown set for {$cooldown_seconds}s.");
    }

    /**
     * Check if rate-limit cooldown is active.
     */
    private function is_rate_limited(): bool
    {
        return (bool) get_transient('wcaig_rate_limit_cooldown');
    }

    /**
     * Extract image URL from PIAPI task data.
     */
    private function extract_image_url_from_task(array $data): string
    {
        // output.image_url
        if (! empty($data['output']['image_url'])) {
            return $data['output']['image_url'];
        }

        // output.image_urls[0] (PIAPI Gemini format)
        if (! empty($data['output']['image_urls'][0])) {
            return (string) $data['output']['image_urls'][0];
        }

        // output.images[0]
        if (! empty($data['output']['images'][0])) {
            $img = $data['output']['images'][0];
            return is_array($img) ? ($img['url'] ?? '') : (string) $img;
        }

        // result.image_url
        if (! empty($data['result']['image_url'])) {
            return $data['result']['image_url'];
        }

        // result.image_urls[0]
        if (! empty($data['result']['image_urls'][0])) {
            return (string) $data['result']['image_urls'][0];
        }

        return '';
    }

    /**
     * Check for timed-out processing posts and handle them.
     */
    private function handle_timeouts(): void
    {
        $timeout_minutes = $this->get_option('wcaig_task_timeout', 30);
        $max_retries     = $this->get_option('wcaig_retry_count', 3);

        $processing_posts = get_posts([
            'post_type'      => 'image_variation',
            'post_status'    => 'processing',
            'posts_per_page' => -1,
        ]);

        foreach ($processing_posts as $post) {
            $modified_time = strtotime($post->post_modified_gmt);
            $elapsed       = (time() - $modified_time) / 60; // minutes

            if ($elapsed > $timeout_minutes) {
                $retry_count = (int) get_field('wcaig_retry_count', $post->ID);
                $retry_count++;

                update_field('wcaig_retry_count', $retry_count, $post->ID);

                if ($retry_count >= $max_retries) {
                    wp_update_post([
                        'ID'          => $post->ID,
                        'post_status' => 'failed',
                    ]);
                    WCAIG_Logger::instance()->error("Worker: variation {$post->ID} timed out, marked failed ({$retry_count}/{$max_retries}).");
                } else {
                    wp_update_post([
                        'ID'          => $post->ID,
                        'post_status' => 'draft',
                    ]);
                    update_field('wcaig_task_id', '', $post->ID);
                    WCAIG_Logger::instance()->info("Worker: variation {$post->ID} timed out, reset to draft ({$retry_count}/{$max_retries}).");
                }
            }
        }
    }

    /**
     * Process the draft queue.
     */
    private function process_queue(): void
    {
        // Skip queue if rate-limit cooldown is active.
        if ($this->is_rate_limited()) {
            WCAIG_Logger::instance()->debug('Worker: rate-limit cooldown active, skipping queue.');
            return;
        }

        $max_concurrent = $this->get_option('wcaig_max_concurrent', 5);
        $max_retries    = $this->get_option('wcaig_retry_count', 3);

        // Count currently processing posts.
        $active_query = new WP_Query([
            'post_type'      => 'image_variation',
            'post_status'    => 'processing',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);
        $active_count = $active_query->found_posts;

        if ($active_count >= $max_concurrent) {
            WCAIG_Logger::instance()->debug("Worker: max concurrent reached ({$active_count}/{$max_concurrent}), skipping queue.");
            return;
        }

        $slots = $max_concurrent - $active_count;

        // Pick oldest drafts that haven't exhausted retries.
        $drafts = get_posts([
            'post_type'      => 'image_variation',
            'post_status'    => 'draft',
            'posts_per_page' => $slots,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => 'wcaig_retry_count',
                    'value'   => $max_retries,
                    'compare' => '<',
                    'type'    => 'NUMERIC',
                ],
                [
                    'key'     => 'wcaig_retry_count',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        foreach ($drafts as $index => $draft) {
            // Add delay between API calls to avoid upstream rate limits.
            if ($index > 0) {
                sleep(5);
            }

            $result = $this->process_draft($draft);

            // Stop processing if we hit a rate limit.
            if ($result === 'rate_limited') {
                WCAIG_Logger::instance()->info('Worker: rate-limit hit during queue processing, stopping.');
                break;
            }
        }
    }

    /**
     * Process a single draft post.
     *
     * @return string 'ok', 'error', or 'rate_limited'
     */
    private function process_draft(WP_Post $draft): string
    {
        $post_id    = $draft->ID;
        $product_id = get_field('wcaig_parent_product', $post_id);
        $max_retries = $this->get_option('wcaig_retry_count', 3);

        if (! $product_id) {
            WCAIG_Logger::instance()->error("Worker: variation {$post_id} has no parent product.");
            wp_update_post([ 'ID' => $post_id, 'post_status' => 'failed' ]);
            return 'error';
        }

        $product = wc_get_product($product_id);
        if (! $product) {
            WCAIG_Logger::instance()->error("Worker: product {$product_id} not found for variation {$post_id}.");
            wp_update_post([ 'ID' => $post_id, 'post_status' => 'failed' ]);
            return 'error';
        }

        // Get base image URL.
        $base_image_url = $this->get_base_image_url($product_id, $product);
        if (empty($base_image_url)) {
            WCAIG_Logger::instance()->error("Worker: no base image for product {$product_id}, marking variation {$post_id} as failed.");
            wp_update_post([ 'ID' => $post_id, 'post_status' => 'failed' ]);
            return 'error';
        }

        // Derive enabled attributes from base_attr fields.
        $enabled = WCAIG_Hash::get_enabled_attributes($product_id);
        if (empty($enabled)) {
            WCAIG_Logger::instance()->error("Worker: no base attributes configured for product {$product_id}.");
            wp_update_post([ 'ID' => $post_id, 'post_status' => 'failed' ]);
            return 'error';
        }

        // Build prompt.
        $prompt = $this->build_prompt($post_id, $product_id, $enabled);
        $hash   = str_replace('variation_', '', $draft->post_name);

        // Send to PIAPI.
        $task_id = WCAIG_API_Client::instance()->create_task($prompt, $base_image_url, $hash);

        if (is_wp_error($task_id)) {
            // If rate-limited at the HTTP level, set cooldown and leave draft as-is.
            if ($task_id->get_error_code() === 'piapi_rate_limit') {
                $this->set_rate_limit_cooldown();
                WCAIG_Logger::instance()->warning("Worker: PIAPI rate-limited for {$post_id}, cooldown activated.");
                return 'rate_limited';
            }

            $retry_count = (int) get_field('wcaig_retry_count', $post_id);
            $retry_count++;
            update_field('wcaig_retry_count', $retry_count, $post_id);

            if ($retry_count >= $max_retries) {
                wp_update_post([ 'ID' => $post_id, 'post_status' => 'failed' ]);
                WCAIG_Logger::instance()->error("Worker: PIAPI failed for {$post_id}, marked failed ({$retry_count}/{$max_retries}).");
            } else {
                WCAIG_Logger::instance()->warning("Worker: PIAPI failed for {$post_id}, will retry ({$retry_count}/{$max_retries}).");
            }
            return 'error';
        }

        // Success: save task_id and transition to processing.
        update_field('wcaig_task_id', $task_id, $post_id);
        wp_update_post([
            'ID'          => $post_id,
            'post_status' => 'processing',
        ]);

        WCAIG_Logger::instance()->info("Worker: submitted variation {$post_id} to PIAPI, task={$task_id}");
        return 'ok';
    }

    /**
     * Build the prompt for a variation.
     */
    private function build_prompt(int $post_id, int $product_id, array $enabled): string
    {
        $prompt_parts = [];

        foreach ($enabled as $attr_name) {
            $base_term   = get_field("wcaig_base_attr_{$attr_name}", $product_id);
            $target_term = get_field("wcaig_attr_{$attr_name}", $post_id);

            if (! ($base_term instanceof WP_Term) || ! ($target_term instanceof WP_Term)) {
                continue;
            }

            // Get term metadata for base and target.
            $base_meta   = $this->get_term_meta_for_term($base_term);
            $target_meta = $this->get_term_meta_for_term($target_term);

            $base_str   = ucfirst($base_term->name);
            $target_str = ucfirst($target_term->name);

            if (! empty($base_meta)) {
                $base_str .= " ({$base_meta})";
            }

            if (! empty($target_meta)) {
                $target_str .= " ({$target_meta})";
            }

            $prompt_parts[] = "Replace all {$attr_name} {$base_str} with {$target_str}.";
        }

        $prompt = implode(' ', $prompt_parts);
        $prompt .= ' Preserve the product shape, texture, lighting, shadows, reflections, and background.';

        // Append custom preservation rules.
        $custom_rules = '';
        if (function_exists('get_field')) {
            $custom_rules = get_field('wcaig_preservation_rules', 'option');
        }
        if (! empty($custom_rules)) {
            $prompt .= ' ' . trim($custom_rules);
        }

        WCAIG_Logger::instance()->debug("Worker: built prompt: {$prompt}");
        return $prompt;
    }

    /**
     * Get formatted term metadata string from a WP_Term object.
     */
    private function get_term_meta_for_term(WP_Term $term): string
    {
        $taxonomy = $term->taxonomy;
        $acf_id   = "{$taxonomy}_{$term->term_id}";

        $meta = [];
        $hex  = get_field('wcaig_term_color_hex', $acf_id);
        $rgb  = get_field('wcaig_term_color_rgb', $acf_id);
        $desc = get_field('wcaig_term_description', $acf_id);

        if (! empty($hex)) {
            $meta[] = $hex;
        }
        if (! empty($rgb)) {
            $meta[] = $rgb;
        }
        if (! empty($desc)) {
            $meta[] = $desc;
        }

        if (empty($meta)) {
            WCAIG_Logger::instance()->warning("Worker: term '{$term->name}' in {$taxonomy} has no metadata.");
        }

        return implode(', ', $meta);
    }

    /**
     * Get the base image URL for a product.
     */
    private function get_base_image_url(int $product_id, WC_Product $product): string
    {
        $base_image = get_field('wcaig_base_image', $product_id);

        if ($base_image) {
            if (is_numeric($base_image)) {
                $url = wp_get_attachment_url($base_image);
                if ($url) {
                    return $url;
                }
            }
            if (is_array($base_image) && ! empty($base_image['url'])) {
                return $base_image['url'];
            }
            if (is_string($base_image) && ! empty($base_image)) {
                return $base_image;
            }
        }

        // Fallback: product featured image.
        $thumb_id = $product->get_image_id();
        if ($thumb_id) {
            return wp_get_attachment_url($thumb_id) ?: '';
        }

        return '';
    }

    /**
     * Get an ACF option value with default.
     */
    private function get_option(string $field, mixed $default = ''): mixed
    {
        if (function_exists('get_field')) {
            $value = get_field($field, 'option');
            if ($value !== null && $value !== '' && $value !== false) {
                return $value;
            }
        }
        return $default;
    }
}
