<?php

/**
 * WCAIG Worker — Background cron worker for processing image generation tasks.
 *
 * Reads from the queue table, sends drafts to PIAPI, polls processing tasks,
 * sideloads completed images as attachments, and handles failures.
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
        add_action('wcaig_worker_cron', [$this, 'run']);
        add_action('init', [$this, 'ensure_cron_scheduled']);
    }

    /**
     * Ensure the worker cron event is scheduled (self-healing after migration).
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
        if (get_transient('wcaig_worker_running')) {
            return;
        }

        set_transient('wcaig_worker_running', true, 5 * MINUTE_IN_SECONDS);

        try {
            $this->poll_processing_tasks();
            $this->handle_timeouts();
            $this->process_queue();
        } finally {
            delete_transient('wcaig_worker_running');
        }
    }

    // ──────────────────────────────────────────────
    // Poll processing tasks (webhook fallback)
    // ──────────────────────────────────────────────

    private function poll_processing_tasks(): void
    {
        $queue   = WCAIG_Queue::instance();
        $entries = $queue->get_processing();

        if (empty($entries)) {
            return;
        }

        $api = WCAIG_API_Client::instance();

        foreach ($entries as $entry) {
            if (empty($entry->task_id)) {
                continue;
            }

            $task_data = $api->fetch_task($entry->task_id);
            if (is_wp_error($task_data)) {
                continue;
            }

            $status = $task_data['status'] ?? '';

            if ($status === 'completed') {
                $this->handle_poll_completed($entry, $task_data);
            } elseif ($status === 'failed') {
                $this->handle_poll_failed($entry, $task_data);

                if (WCAIG_API_Client::is_rate_limited()) {
                    break;
                }
            }
        }
    }

    private function handle_poll_completed(object $entry, array $task_data): void
    {
        $queue = WCAIG_Queue::instance();
        $hash  = $entry->hash;

        // Idempotency: check if attachment already exists (any status).
        if (WCAIG_Hash::find_attachment($hash)) {
            $queue->delete($hash);
            return;
        }

        $image_url = WCAIG_API_Client::extract_image_url($task_data);
        if (empty($image_url)) {
            WCAIG_Logger::instance()->error("Worker poll: no image URL for hash {$hash}");
            return;
        }

        $attributes = json_decode($entry->attributes, true) ?: [];

        $attachment_id = WCAIG_API_Client::instance()->sideload_image(
            $image_url,
            (int) $entry->product_id,
            $hash,
            $attributes
        );

        if (is_wp_error($attachment_id)) {
            WCAIG_Logger::instance()->error("Worker poll: sideload failed for hash {$hash}: {$attachment_id->get_error_message()}");
            return;
        }

        $queue->delete($hash);
        WCAIG_Logger::instance()->info("Worker poll: completed hash {$hash}, attachment {$attachment_id}");
    }

    private function handle_poll_failed(object $entry, array $task_data): void
    {
        $queue = WCAIG_Queue::instance();
        $hash  = $entry->hash;

        if (WCAIG_API_Client::is_rate_limit_failure($task_data)) {
            WCAIG_API_Client::set_rate_limit_cooldown();
            $queue->reset_for_rate_limit($hash);
            return;
        }

        $max_retries = (int) WCAIG_Hash::get_option('wcaig_retry_count', 3);
        $error_msg   = $task_data['error']['message'] ?? 'Task failed';
        $new_retries = $queue->increment_retry($hash, $error_msg);

        if ($new_retries >= $max_retries) {
            $queue->mark_failed($hash, $error_msg);
            WCAIG_Logger::instance()->error("Worker poll: hash {$hash} failed after {$new_retries} retries.");
        }
    }

    // ──────────────────────────────────────────────
    // Timeout handling
    // ──────────────────────────────────────────────

    private function handle_timeouts(): void
    {
        $timeout_minutes = (int) WCAIG_Hash::get_option('wcaig_task_timeout', 30);
        $max_retries     = (int) WCAIG_Hash::get_option('wcaig_retry_count', 3);
        $queue           = WCAIG_Queue::instance();

        foreach ($queue->get_processing() as $entry) {
            $elapsed = (time() - strtotime($entry->updated_at . ' UTC')) / 60;

            if ($elapsed > $timeout_minutes) {
                $new_retries = $queue->increment_retry($entry->hash, 'timeout');

                if ($new_retries >= $max_retries) {
                    $queue->mark_failed($entry->hash, 'timeout');
                    WCAIG_Logger::instance()->error("Worker: hash {$entry->hash} timed out, marked failed.");
                }
            }
        }
    }

    // ──────────────────────────────────────────────
    // Process draft queue
    // ──────────────────────────────────────────────

    private function process_queue(): void
    {
        if (WCAIG_API_Client::is_rate_limited()) {
            return;
        }

        $max_concurrent = (int) WCAIG_Hash::get_option('wcaig_max_concurrent', 5);
        $max_retries    = (int) WCAIG_Hash::get_option('wcaig_retry_count', 3);
        $queue          = WCAIG_Queue::instance();

        $active_count = $queue->count_processing();
        if ($active_count >= $max_concurrent) {
            return;
        }

        $drafts = $queue->get_drafts($max_concurrent - $active_count, $max_retries);

        foreach ($drafts as $index => $entry) {
            if ($index > 0) {
                sleep(5);
            }

            if ($this->process_draft($entry) === 'rate_limited') {
                break;
            }
        }
    }

    private function process_draft(object $entry): string
    {
        $queue      = WCAIG_Queue::instance();
        $hash       = $entry->hash;
        $product_id = (int) $entry->product_id;
        $max_retries = (int) WCAIG_Hash::get_option('wcaig_retry_count', 3);

        $product = wc_get_product($product_id);
        if (! $product) {
            $queue->mark_failed($hash, 'Product not found');
            return 'error';
        }

        $base_image_url = WCAIG_Hash::get_base_image_url($product_id);
        if (empty($base_image_url)) {
            $queue->mark_failed($hash, 'No base image');
            return 'error';
        }

        $enabled = WCAIG_Hash::get_enabled_attributes($product_id);
        if (empty($enabled)) {
            $queue->mark_failed($hash, 'No base attributes configured');
            return 'error';
        }

        $target_attributes = json_decode($entry->attributes, true) ?: [];
        $prompt = $this->build_prompt($product_id, $enabled, $target_attributes);

        $task_id = WCAIG_API_Client::instance()->create_task($prompt, $base_image_url, $hash);

        if (is_wp_error($task_id)) {
            if ($task_id->get_error_code() === 'piapi_rate_limit') {
                WCAIG_API_Client::set_rate_limit_cooldown();
                return 'rate_limited';
            }

            $error_msg   = $task_id->get_error_message();
            $new_retries = $queue->increment_retry($hash, $error_msg);

            if ($new_retries >= $max_retries) {
                $queue->mark_failed($hash, $error_msg);
            }
            return 'error';
        }

        $queue->update_status($hash, 'processing', $task_id);
        WCAIG_Logger::instance()->info("Worker: submitted hash {$hash}, task={$task_id}");
        return 'ok';
    }

    // ──────────────────────────────────────────────
    // Prompt building
    // ──────────────────────────────────────────────

    private function build_prompt(int $product_id, array $enabled, array $target_attributes): string
    {
        $prompt_parts = [];

        foreach ($enabled as $attr_name) {
            $base_term   = get_field("wcaig_base_attr_{$attr_name}", $product_id);
            $target_slug = $target_attributes[$attr_name] ?? null;

            if (! $target_slug || ! ($base_term instanceof WP_Term)) {
                continue;
            }

            $target_term = get_term_by('slug', $target_slug, "pa_{$attr_name}");
            if (! ($target_term instanceof WP_Term)) {
                continue;
            }

            $base_str   = ucfirst($base_term->name) . $this->format_term_meta($base_term);
            $target_str = ucfirst($target_term->name) . $this->format_term_meta($target_term);

            $prompt_parts[] = "Replace all {$attr_name} {$base_str} with {$target_str}.";
        }

        $prompt  = implode(' ', $prompt_parts);
        $prompt .= ' Preserve the product shape, texture, lighting, shadows, reflections, and background.';

        $custom_rules = WCAIG_Hash::get_option('wcaig_preservation_rules', '');
        if (! empty($custom_rules)) {
            $prompt .= ' ' . trim($custom_rules);
        }

        return $prompt;
    }

    /**
     * Format term metadata as a parenthetical string (hex, rgb, description).
     */
    private function format_term_meta(WP_Term $term): string
    {
        $acf_id = "{$term->taxonomy}_{$term->term_id}";

        $parts = array_filter([
            get_field('wcaig_term_color_hex', $acf_id) ?: '',
            get_field('wcaig_term_color_rgb', $acf_id) ?: '',
            get_field('wcaig_term_description', $acf_id) ?: '',
        ]);

        return ! empty($parts) ? ' (' . implode(', ', $parts) . ')' : '';
    }
}
