<?php

/**
 * WCAIG REST API — Endpoints for variation image requests and polling.
 *
 * Uses queue table for pending state, attachments with wcaig_status
 * for approval workflow. Only 'published' images are returned to the frontend.
 *
 * @package WC_AI_Image_Gen
 */

if (! defined('ABSPATH')) {
    exit;
}

class WCAIG_REST_API
{
    private static ?WCAIG_REST_API $instance = null;

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
        register_rest_route('wcaig/v1', '/variation', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_post_variation'],
            'permission_callback' => '__return_true',
            'args'                => [
                'product_id' => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                'attributes' => ['required' => true, 'type' => 'object'],
            ],
        ]);

        register_rest_route('wcaig/v1', '/variation/(?P<hash>[a-f0-9]{1,32})', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_get_variation'],
            'permission_callback' => '__return_true',
            'args'                => [
                'hash' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => fn($v) => preg_match('/^[a-f0-9]{1,32}$/', $v),
                ],
            ],
        ]);
    }

    /**
     * POST /wcaig/v1/variation — request generation of a variation image.
     */
    public function handle_post_variation(WP_REST_Request $request): WP_REST_Response
    {
        $product_id = (int) $request->get_param('product_id');
        $attributes = (array) $request->get_param('attributes');

        // Validate product.
        $product = wc_get_product($product_id);
        if (! $product || ! $product->is_type('variable')) {
            return new WP_REST_Response(
                ['status' => 'error', 'message' => 'Product not found or not variable.'],
                400
            );
        }

        // Enabled attributes.
        $enabled = WCAIG_Hash::get_enabled_attributes($product_id);
        if (empty($enabled)) {
            return new WP_REST_Response(
                ['status' => 'error', 'message' => 'No base attributes configured for AI generation.'],
                400
            );
        }

        // Base-match check.
        if (WCAIG_Hash::is_base_match($product_id, $attributes, $enabled)) {
            return new WP_REST_Response([
                'status'    => 'base_match',
                'image_url' => WCAIG_Hash::get_base_image_url($product_id),
            ], 200);
        }

        $hash = WCAIG_Hash::compute($product_id, $attributes, $enabled);

        // 1. Published attachment → return immediately.
        $published = WCAIG_Hash::find_attachment($hash, 'published');
        if ($published) {
            $url = wp_get_attachment_url($published->ID);
            if ($url) {
                return new WP_REST_Response([
                    'status'    => 'published',
                    'image_url' => $url,
                    'hash'      => $hash,
                ], 200);
            }
        }

        // 2. Pending attachment (generated but not yet approved).
        $pending = WCAIG_Hash::find_attachment($hash, 'pending');
        if ($pending) {
            return new WP_REST_Response(['status' => 'pending_review', 'hash' => $hash], 200);
        }

        // 3. Queue entry exists.
        $queue = WCAIG_Queue::instance();
        $entry = $queue->find($hash);

        if ($entry) {
            if ($entry->status === 'failed') {
                $queue->update_status($hash, 'draft');
                $this->trigger_async_processing();
            }
            return new WP_REST_Response(['status' => 'pending', 'hash' => $hash], 200);
        }

        // 4. Cap check.
        if (WCAIG_Hash::is_cap_reached()) {
            return new WP_REST_Response([
                'status'  => 'error',
                'code'    => 'cap_reached',
                'message' => 'Image generation limit reached.',
            ], 200);
        }

        // 5. Insert into queue (INSERT IGNORE for atomic dedup).
        $inserted = $queue->insert($hash, $product_id, $attributes);

        if (! $inserted) {
            return new WP_REST_Response(['status' => 'pending', 'hash' => $hash], 200);
        }

        WCAIG_Logger::instance()->info("New queue entry: hash={$hash}, product={$product_id}");
        $this->trigger_async_processing();

        return new WP_REST_Response(['status' => 'created', 'hash' => $hash], 200);
    }

    /**
     * GET /wcaig/v1/variation/{hash} — poll for variation image status.
     */
    public function handle_get_variation(WP_REST_Request $request): WP_REST_Response
    {
        $hash = $request->get_param('hash');

        // 1. Published attachment.
        $published = WCAIG_Hash::find_attachment($hash, 'published');
        if ($published) {
            $url = wp_get_attachment_url($published->ID);
            if ($url) {
                return new WP_REST_Response([
                    'status'    => 'published',
                    'image_url' => $url,
                ], 200);
            }
        }

        // 2. Pending attachment (generated, awaiting approval).
        $pending = WCAIG_Hash::find_attachment($hash, 'pending');
        if ($pending) {
            return new WP_REST_Response(['status' => 'pending_review'], 200);
        }

        // 3. Queue entry.
        $entry = WCAIG_Queue::instance()->find($hash);
        if ($entry) {
            return match ($entry->status) {
                'draft', 'processing' => new WP_REST_Response(['status' => 'pending'], 200),
                'failed'              => new WP_REST_Response(['status' => 'failed'], 200),
                default               => new WP_REST_Response(['status' => 'not_found'], 200),
            };
        }

        return new WP_REST_Response(['status' => 'not_found'], 200);
    }

    /**
     * Trigger async cron processing.
     */
    private function trigger_async_processing(): void
    {
        if (! wp_next_scheduled('wcaig_worker_cron')) {
            wp_schedule_event(time(), 'wcaig_every_minute', 'wcaig_worker_cron');
        }

        wp_remote_post(site_url('/wp-cron.php'), [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'body'      => [],
        ]);
    }
}
