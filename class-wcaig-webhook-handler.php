<?php

/**
 * WCAIG Webhook Handler — Receives webhook callbacks from PIAPI.
 *
 * Uses centralized helpers from WCAIG_Hash and WCAIG_API_Client.
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
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route('wcaig/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_webhook'],
            'permission_callback' => [$this, 'verify_webhook_secret'],
        ]);
    }

    public function verify_webhook_secret(WP_REST_Request $request): bool
    {
        $expected = WCAIG_Hash::get_option('wcaig_webhook_secret', '');

        if (empty($expected)) {
            return true;
        }

        $secret = $request->get_header('x-webhook-secret');
        return ! empty($secret) && $secret === $expected;
    }

    public function handle_webhook(WP_REST_Request $request): WP_REST_Response
    {
        $params  = $request->get_json_params() ?: $request->get_params();
        $task_id = $params['task_id'] ?? '';
        $status  = $params['status'] ?? '';
        $hash    = $params['metadata']['wcaig_hash'] ?? '';

        WCAIG_Logger::instance()->debug("Webhook received: task={$task_id}, status={$status}, hash={$hash}");

        $queue = WCAIG_Queue::instance();
        $entry = $queue->find_by_task_id($task_id);

        if (! $entry && $hash) {
            $entry = $queue->find($hash);
        }

        if (! $entry) {
            return new WP_REST_Response(['ok' => true], 200);
        }

        if ($status === 'completed') {
            return $this->handle_completed($entry, $params);
        }

        if ($status === 'failed') {
            return $this->handle_failed($entry, $params);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    private function handle_completed(object $entry, array $params): WP_REST_Response
    {
        $queue = WCAIG_Queue::instance();
        $hash  = $entry->hash;

        // Idempotency.
        if (WCAIG_Hash::find_attachment($hash)) {
            $queue->delete($hash);
            return new WP_REST_Response(['ok' => true], 200);
        }

        $image_url = WCAIG_API_Client::extract_image_url($params);
        if (empty($image_url)) {
            WCAIG_Logger::instance()->error("Webhook: no image URL for hash {$hash}");
            return new WP_REST_Response(['ok' => true], 200);
        }

        $attributes = json_decode($entry->attributes, true) ?: [];

        $attachment_id = WCAIG_API_Client::instance()->sideload_image(
            $image_url,
            (int) $entry->product_id,
            $hash,
            $attributes
        );

        if (is_wp_error($attachment_id)) {
            WCAIG_Logger::instance()->error("Webhook: sideload failed for hash {$hash}: {$attachment_id->get_error_message()}");
            return new WP_REST_Response(['ok' => true], 200);
        }

        $queue->delete($hash);
        WCAIG_Logger::instance()->info("Webhook: completed hash {$hash}, attachment {$attachment_id}");

        return new WP_REST_Response(['ok' => true], 200);
    }

    private function handle_failed(object $entry, array $params): WP_REST_Response
    {
        $queue = WCAIG_Queue::instance();
        $hash  = $entry->hash;

        if (WCAIG_API_Client::is_rate_limit_failure($params)) {
            WCAIG_API_Client::set_rate_limit_cooldown();
            $queue->reset_for_rate_limit($hash);
            return new WP_REST_Response(['ok' => true], 200);
        }

        $max_retries = (int) WCAIG_Hash::get_option('wcaig_retry_count', 3);
        $error_msg   = $params['error']['message'] ?? 'Task failed';
        $new_retries = $queue->increment_retry($hash, $error_msg);

        if ($new_retries >= $max_retries) {
            $queue->mark_failed($hash, $error_msg);
            WCAIG_Logger::instance()->error("Webhook: hash {$hash} failed after {$new_retries} retries.");
        }

        return new WP_REST_Response(['ok' => true], 200);
    }
}
