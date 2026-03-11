<?php

/**
 * WCAIG Webhook Handler — Receives webhook callbacks from PIAPI.
 *
 * @package WC_AI_Image_Gen
 */

if (! defined('ABSPATH')) {
    exit;
}

class WCAIG_Webhook_Handler
{
    private static ?WCAIG_Webhook_Handler $instance = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('rest_api_init', [ $this, 'register_routes' ]);
    }

    /**
     * Register the webhook route.
     */
    public function register_routes(): void
    {
        register_rest_route('wcaig/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_webhook' ],
            'permission_callback' => [ $this, 'verify_webhook_secret' ],
        ]);
    }

    /**
     * Permission callback: verify x-webhook-secret header.
     */
    public function verify_webhook_secret(WP_REST_Request $request): bool
    {
        $expected = '';
        if (function_exists('get_field')) {
            $expected = get_field('wcaig_webhook_secret', 'option');
        }

        // If no secret is configured, allow the webhook through.
        if (empty($expected)) {
            return true;
        }

        $secret = $request->get_header('x-webhook-secret');
        if (empty($secret) || $secret !== $expected) {
            return false;
        }

        return true;
    }

    /**
     * Handle the webhook callback.
     *
     * @param WP_REST_Request $request The request.
     * @return WP_REST_Response
     */
    public function handle_webhook(WP_REST_Request $request): WP_REST_Response
    {
        $params  = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_params();
        }

        $task_id = $params['task_id'] ?? '';
        $status  = $params['status'] ?? '';
        $hash    = $params['metadata']['wcaig_hash'] ?? '';

        WCAIG_Logger::instance()->debug("Webhook received: task={$task_id}, status={$status}, hash={$hash}");

        // Find the CPT post by task_id.
        $post = $this->find_post_by_task_id($task_id);

        if (! $post && $hash) {
            $post = WCAIG_CPT::find_by_hash($hash);
        }

        if (! $post) {
            WCAIG_Logger::instance()->warning("Webhook: no post found for task={$task_id}, hash={$hash}");
            return new WP_REST_Response([ 'ok' => true ], 200);
        }

        if ($status === 'completed') {
            return $this->handle_completed($post, $params, $hash);
        }

        if ($status === 'failed') {
            return $this->handle_failed($post, $params);
        }

        WCAIG_Logger::instance()->warning("Webhook: unknown status '{$status}' for task={$task_id}");
        return new WP_REST_Response([ 'ok' => true ], 200);
    }

    /**
     * Handle completed status.
     */
    private function handle_completed(WP_Post $post, array $params, string $hash): WP_REST_Response
    {
        // Idempotency check.
        if ($post->post_status === 'publish') {
            WCAIG_Logger::instance()->info("Webhook: post {$post->ID} already published, skipping duplicate.");
            return new WP_REST_Response([ 'ok' => true ], 200);
        }

        // Extract image URL from response.
        $image_url = $this->extract_image_url($params);

        if (empty($image_url)) {
            WCAIG_Logger::instance()->error("Webhook: no image URL found in completed response for post {$post->ID}");
            return new WP_REST_Response([ 'ok' => true ], 200);
        }

        // Determine hash from post slug if not provided.
        if (empty($hash)) {
            $hash = str_replace('variation_', '', $post->post_name);
        }

        // Sideload image.
        $attachment_id = WCAIG_API_Client::instance()->sideload_image($image_url, $post->ID, $hash);

        if (is_wp_error($attachment_id)) {
            WCAIG_Logger::instance()->error("Webhook: sideload failed for post {$post->ID}: {$attachment_id->get_error_message()}");
            return new WP_REST_Response([ 'ok' => true ], 200);
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

        WCAIG_Logger::instance()->info("Webhook: published variation {$hash} (post {$post->ID})");

        return new WP_REST_Response([ 'ok' => true ], 200);
    }

    /**
     * Handle failed status.
     */
    private function handle_failed(WP_Post $post, array $params = []): WP_REST_Response
    {
        // Check if this is a rate-limit failure — don't burn retries.
        if ($this->is_rate_limit_failure($params)) {
            wp_update_post([
                'ID'          => $post->ID,
                'post_status' => 'draft',
            ]);
            update_field('wcaig_task_id', '', $post->ID);

            // Set cooldown transient so the worker backs off.
            $cooldown = 120;
            if (function_exists('get_field')) {
                $setting = get_field('wcaig_rate_limit_cooldown', 'option');
                if (is_numeric($setting) && (int) $setting > 0) {
                    $cooldown = (int) $setting;
                }
            }
            set_transient('wcaig_rate_limit_cooldown', time(), $cooldown);

            WCAIG_Logger::instance()->warning("Webhook: rate-limited for variation {$post->ID}, reset to draft without burning retry (cooldown {$cooldown}s).");
            return new WP_REST_Response([ 'ok' => true ], 200);
        }

        $retry_count = (int) get_field('wcaig_retry_count', $post->ID);
        $retry_count++;

        update_field('wcaig_retry_count', $retry_count, $post->ID);

        $max_retries = 3;
        if (function_exists('get_field')) {
            $setting = get_field('wcaig_retry_count', 'option');
            if (is_numeric($setting)) {
                $max_retries = (int) $setting;
            }
        }

        if ($retry_count >= $max_retries) {
            wp_update_post([
                'ID'          => $post->ID,
                'post_status' => 'failed',
            ]);
            WCAIG_Logger::instance()->error("Webhook: variation {$post->ID} failed after {$retry_count} retries.");
        } else {
            wp_update_post([
                'ID'          => $post->ID,
                'post_status' => 'draft',
            ]);
            WCAIG_Logger::instance()->info("Webhook: variation {$post->ID} retrying ({$retry_count}/{$max_retries}).");
        }

        // Clear task ID.
        update_field('wcaig_task_id', '', $post->ID);

        return new WP_REST_Response([ 'ok' => true ], 200);
    }

    /**
     * Find a CPT post by task_id.
     */
    private function find_post_by_task_id(string $task_id): ?WP_Post
    {
        if (empty($task_id)) {
            return null;
        }

        $posts = get_posts([
            'post_type'      => 'image_variation',
            'post_status'    => [ 'draft', 'publish', 'processing', 'failed' ],
            'meta_query'     => [
                [
                    'key'   => 'wcaig_task_id',
                    'value' => $task_id,
                ],
            ],
            'posts_per_page' => 1,
        ]);

        return ! empty($posts) ? $posts[0] : null;
    }

    /**
     * Check if PIAPI webhook payload indicates a rate-limit failure.
     */
    private function is_rate_limit_failure(array $params): bool
    {
        // Check logs array.
        $logs = $params['logs'] ?? [];
        foreach ($logs as $log) {
            if (is_string($log) && stripos($log, 'too many requests') !== false) {
                return true;
            }
        }

        // Check error message.
        $error_msg = $params['error']['message'] ?? '';
        if (stripos($error_msg, 'too many requests') !== false) {
            return true;
        }

        $raw_msg = $params['error']['raw_message'] ?? '';
        if (stripos($raw_msg, 'too many requests') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Extract image URL from PIAPI response (deep search).
     */
    private function extract_image_url(array $params): string
    {
        // Direct output.image_url
        if (! empty($params['output']['image_url'])) {
            return $params['output']['image_url'];
        }

        // output.image_urls[0] (PIAPI Gemini format)
        if (! empty($params['output']['image_urls'][0])) {
            return (string) $params['output']['image_urls'][0];
        }

        // output.images[0]
        if (! empty($params['output']['images'][0])) {
            $img = $params['output']['images'][0];
            return is_array($img) ? ($img['url'] ?? '') : (string) $img;
        }

        // result.image_url
        if (! empty($params['result']['image_url'])) {
            return $params['result']['image_url'];
        }

        // data.output.image_url
        if (! empty($params['data']['output']['image_url'])) {
            return $params['data']['output']['image_url'];
        }

        // data.output.image_urls[0]
        if (! empty($params['data']['output']['image_urls'][0])) {
            return (string) $params['data']['output']['image_urls'][0];
        }

        return '';
    }
}
