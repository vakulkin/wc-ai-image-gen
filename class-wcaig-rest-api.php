<?php

/**
 * WCAIG REST API — REST endpoints for variation requests and polling.
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
        add_action('rest_api_init', [ $this, 'register_routes' ]);
    }

    /**
     * Register REST API routes.
     */
    public function register_routes(): void
    {
        register_rest_route('wcaig/v1', '/variation', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_post_variation' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'product_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'attributes' => [
                    'required' => true,
                    'type'     => 'object',
                ],
            ],
        ]);

        register_rest_route('wcaig/v1', '/variation/(?P<hash>[a-f0-9]{1,32})', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_get_variation' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'hash' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => function ($value) {
                        return preg_match('/^[a-f0-9]{1,32}$/', $value);
                    },
                ],
            ],
        ]);
    }

    /**
     * Handle POST /wcaig/v1/variation.
     *
     * @param WP_REST_Request $request The request.
     * @return WP_REST_Response
     */
    public function handle_post_variation(WP_REST_Request $request): WP_REST_Response
    {
        // Rate limiting.
        $rate_check = $this->check_rate_limit();
        if (is_wp_error($rate_check)) {
            return new WP_REST_Response(
                [ 'error' => 'rate_limit_exceeded', 'code' => 'rate_limit_exceeded' ],
                429
            );
        }

        $product_id = (int) $request->get_param('product_id');
        $attributes = $request->get_param('attributes');

        if (! is_array($attributes)) {
            $attributes = (array) $attributes;
        }

        // Validate product exists and is variable.
        $product = wc_get_product($product_id);
        if (! $product) {
            return new WP_REST_Response(
                [ 'status' => 'error', 'message' => 'Product not found.' ],
                400
            );
        }

        if (! $product->is_type('variable')) {
            return new WP_REST_Response(
                [ 'status' => 'error', 'message' => 'Product must be a variable product.' ],
                400
            );
        }

        // Derive enabled attributes from base_attr fields.
        $enabled = WCAIG_Hash::get_enabled_attributes($product_id);
        if (empty($enabled)) {
            return new WP_REST_Response(
                [ 'status' => 'error', 'message' => 'No base attributes configured for AI generation.' ],
                400
            );
        }

        // Base-match check.
        if ($this->is_base_match($product_id, $attributes, $enabled)) {
            $base_url = $this->get_base_image_url($product_id);
            return new WP_REST_Response([
                'status'    => 'base_match',
                'image_url' => $base_url,
            ], 200);
        }

        // Compute hash.
        $hash = WCAIG_Hash::compute($product_id, $attributes, $enabled);

        // Check existing CPT post — do this before cap check to serve cached images.
        $existing = WCAIG_CPT::find_by_hash($hash);
        if ($existing) {
            return $this->handle_existing_post($existing, $hash);
        }

        // Check global cap (only for new variations).
        if (WCAIG_Statistics::instance()->is_cap_reached()) {
            return new WP_REST_Response([
                'status'  => 'error',
                'code'    => 'cap_reached',
                'message' => 'Image generation limit reached.',
            ], 200);
        }

        // Create new CPT post.
        $post_id = WCAIG_CPT::create_variation($product_id, $attributes, $hash);

        if (is_wp_error($post_id)) {
            return new WP_REST_Response([
                'status'  => 'error',
                'message' => 'Failed to create variation.',
            ], 500);
        }

        // Race condition check: verify slug wasn't suffixed.
        $created_post = get_post($post_id);
        $expected_slug = WCAIG_Hash::get_slug($hash);

        if ($created_post->post_name !== $expected_slug) {
            // Race lost — delete our duplicate, return the winner's status.
            wp_delete_post($post_id, true);

            $winner = WCAIG_CPT::find_by_hash($hash);
            if ($winner) {
                return $this->handle_existing_post($winner, $hash);
            }

            return new WP_REST_Response([
                'status' => 'pending',
                'hash'   => $hash,
            ], 200);
        }

        WCAIG_Logger::instance()->info("New variation created: hash={$hash}, product={$product_id}");

        // Trigger async worker so the draft is processed immediately.
        $this->trigger_async_processing();

        return new WP_REST_Response([
            'status' => 'created',
            'hash'   => $hash,
        ], 200);
    }

    /**
     * Handle GET /wcaig/v1/variation/{hash}.
     *
     * @param WP_REST_Request $request The request.
     * @return WP_REST_Response
     */
    public function handle_get_variation(WP_REST_Request $request): WP_REST_Response
    {
        // Rate limiting.
        $rate_check = $this->check_rate_limit();
        if (is_wp_error($rate_check)) {
            return new WP_REST_Response(
                [ 'error' => 'rate_limit_exceeded', 'code' => 'rate_limit_exceeded' ],
                429
            );
        }

        $hash = $request->get_param('hash');

        // Check transient cache first.
        $cached_url = get_transient("wcaig_image_{$hash}");
        if ($cached_url) {
            // Verify the post exists and is published.
            $post = WCAIG_CPT::find_by_hash($hash);
            if ($post && $post->post_status === 'publish') {
                return new WP_REST_Response([
                    'status'    => 'published',
                    'image_url' => $cached_url,
                ], 200);
            }
        }

        // Look up CPT post.
        $post = WCAIG_CPT::find_by_hash($hash);

        if (! $post) {
            return new WP_REST_Response([ 'status' => 'not_found' ], 200);
        }

        return match ($post->post_status) {
            'publish' => new WP_REST_Response([
                'status'    => 'published',
                'image_url' => $this->get_variation_image_url($post, $hash),
            ], 200),
            'draft', 'processing' => new WP_REST_Response([ 'status' => 'pending' ], 200),
            'failed'  => new WP_REST_Response([ 'status' => 'failed' ], 200),
            default   => new WP_REST_Response([ 'status' => 'not_found' ], 200),
        };
    }

    /**
     * Handle an existing CPT post in POST endpoint.
     */
    private function handle_existing_post(WP_Post $post, string $hash): WP_REST_Response
    {
        return match ($post->post_status) {
            'publish' => new WP_REST_Response([
                'status'    => 'published',
                'image_url' => $this->get_variation_image_url($post, $hash),
                'hash'      => $hash,
            ], 200),
            'draft', 'processing' => new WP_REST_Response([
                'status' => 'pending',
                'hash'   => $hash,
            ], 200),
            'failed' => $this->handle_failed_auto_retry($post, $hash),
            default => new WP_REST_Response([
                'status' => 'pending',
                'hash'   => $hash,
            ], 200),
        };
    }

    /**
     * Auto-retry a failed variation.
     */
    private function handle_failed_auto_retry(WP_Post $post, string $hash): WP_REST_Response
    {
        wp_update_post([
            'ID'          => $post->ID,
            'post_status' => 'draft',
        ]);
        update_field('wcaig_task_id', '', $post->ID);
        update_field('wcaig_retry_count', 0, $post->ID);

        WCAIG_Logger::instance()->info("Auto-retry: reset failed variation {$hash} to draft.");

        // Trigger async worker so the retried draft is processed immediately.
        $this->trigger_async_processing();

        return new WP_REST_Response([
            'status' => 'pending',
            'hash'   => $hash,
        ], 200);
    }

    /**
     * Check if selected attributes match the base image attributes.
     */
    private function is_base_match(int $product_id, array $attributes, array $enabled): bool
    {
        foreach ($enabled as $attr_name) {
            $base_term = get_field("wcaig_base_attr_{$attr_name}", $product_id);
            if (! ($base_term instanceof WP_Term)) {
                return false;
            }

            // Find the selected value for this attribute.
            $selected_value = null;
            foreach ($attributes as $key => $val) {
                $normalized_key = strtolower(trim(preg_replace('/^pa_/', '', $key)));
                if ($normalized_key === $attr_name) {
                    $selected_value = $val;
                    break;
                }
            }

            if ($selected_value === null) {
                return false;
            }

            // Compare base term slug with selected value.
            if ($base_term->slug !== strtolower(trim((string) $selected_value))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the base image URL for a product.
     */
    private function get_base_image_url(int $product_id): string
    {
        $base_image = get_field('wcaig_base_image', $product_id);
        if ($base_image) {
            if (is_numeric($base_image)) {
                return wp_get_attachment_url($base_image) ?: '';
            }
            if (is_array($base_image) && ! empty($base_image['url'])) {
                return $base_image['url'];
            }
            if (is_string($base_image)) {
                return $base_image;
            }
        }

        // Fallback to product featured image.
        $product = wc_get_product($product_id);
        if ($product) {
            $thumb_id = $product->get_image_id();
            if ($thumb_id) {
                return wp_get_attachment_url($thumb_id) ?: '';
            }
        }

        return '';
    }

    /**
     * Get the image URL for a published variation.
     */
    private function get_variation_image_url(WP_Post $post, string $hash): string
    {
        // Check transient cache.
        $cached = get_transient("wcaig_image_{$hash}");
        if ($cached) {
            return $cached;
        }

        // Get from featured image.
        $thumb_id = get_post_thumbnail_id($post->ID);
        if ($thumb_id) {
            $url = wp_get_attachment_url($thumb_id);
            if ($url) {
                // Set transient cache.
                set_transient("wcaig_image_{$hash}", $url, DAY_IN_SECONDS);
                return $url;
            }
        }

        return '';
    }

    /**
     * Trigger async processing so draft variations are picked up immediately.
     *
     * 1. Ensures the cron event is scheduled (self-healing after migration).
     * 2. Fires a non-blocking loopback to wp-cron.php to run the worker now.
     */
    private function trigger_async_processing(): void
    {
        // Ensure cron event exists (self-healing after migration).
        if (! wp_next_scheduled('wcaig_worker_cron')) {
            wp_schedule_event(time(), 'wcaig_every_minute', 'wcaig_worker_cron');
            WCAIG_Logger::instance()->info('REST API: re-scheduled missing wcaig_worker_cron event.');
        }

        // Fire a non-blocking loopback request to trigger cron immediately.
        $cron_url = site_url('/wp-cron.php');

        wp_remote_post($cron_url, [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'body'      => [],
        ]);

        WCAIG_Logger::instance()->debug('REST API: triggered async cron loopback.');
    }

    /**
     * Check rate limit for the current IP.
     *
     * @return true|WP_Error True if allowed, WP_Error if rate limited.
     */
    private function check_rate_limit(): true|WP_Error
    {
        // $ip      = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        // $ip_hash = md5($ip);
        // $key     = "wcaig_rate_{$ip_hash}";

        // $count = get_transient($key);

        // if ($count === false) {
        //     set_transient($key, 1, 60);
        //     return true;
        // }

        // if ((int) $count >= 30) {
        //     return new WP_Error('rate_limit', 'Rate limit exceeded.');
        // }

        // set_transient($key, (int) $count + 1, 60);
        return true;
    }
}
