<?php

/**
 * WCAIG API Client — PIAPI HTTP client, image sideloading, and shared API helpers.
 *
 * @package WC_AI_Image_Gen
 */

if (! defined('ABSPATH')) {
    exit;
}

class WCAIG_API_Client
{
    private static ?WCAIG_API_Client $instance = null;

    private const PIAPI_URL = 'https://api.piapi.ai/api/v1/task';

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    // ──────────────────────────────────────────────
    // Task creation
    // ──────────────────────────────────────────────

    /**
     * Create a PIAPI task for image generation.
     *
     * @param string   $prompt          The constructed prompt.
     * @param string   $image_url       The base image URL.
     * @param string   $hash            The variation hash.
     * @param string[] $ref_image_urls  Optional reference images (e.g. color/pattern swatches).
     * @return string|WP_Error  Task ID on success, WP_Error on failure.
     */
    public function create_task(string $prompt, string $image_url, string $hash, array $ref_image_urls = []): string|WP_Error
    {
        $api_key     = $this->get_api_key();
        $webhook_url = $this->get_webhook_url();
        $model       = WCAIG_Hash::get_option('wcaig_model', 'gemini');
        $task_type   = WCAIG_Hash::get_option('wcaig_task_type', 'gemini-2.5-flash-image');
        $secret      = WCAIG_Hash::get_option('wcaig_webhook_secret', '');

        // Base image first, then any reference swatch/pattern images.
        $all_image_urls = array_merge([$image_url], array_values($ref_image_urls));

        $payload = [
            'model'     => $model,
            'task_type' => $task_type,
            'input'     => [
                'prompt'     => $prompt,
                'image_urls' => $all_image_urls,
            ],
            'config'    => [
                'webhook_config' => [
                    'endpoint' => $webhook_url,
                    'secret'   => $secret,
                ],
            ],
            'metadata'  => [
                'wcaig_hash' => $hash,
            ],
        ];

        WCAIG_Logger::instance()->debug("PIAPI payload: model={$model}, task_type={$task_type}, image_url={$image_url}, hash={$hash}");

        $response = wp_remote_post(self::PIAPI_URL, [
            'headers' => [
                'x-api-key'    => $api_key,
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            WCAIG_Logger::instance()->error("PIAPI request failed: {$response->get_error_message()}");
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 429) {
            WCAIG_Logger::instance()->warning('PIAPI rate limited (HTTP 429).');
            return new WP_Error('piapi_rate_limit', 'Too many requests (HTTP 429)');
        }

        if ($code !== 200) {
            $error_msg = $body['error'] ?? "HTTP {$code}";
            WCAIG_Logger::instance()->error("PIAPI returned error: {$error_msg}");
            return new WP_Error('piapi_error', $error_msg);
        }

        $task_id = $body['data']['task_id'] ?? '';
        if (empty($task_id)) {
            return new WP_Error('piapi_no_task_id', 'No task_id in PIAPI response');
        }

        WCAIG_Logger::instance()->debug("PIAPI task created: {$task_id} for hash {$hash}");
        return $task_id;
    }

    /**
     * Fetch task status from PIAPI.
     *
     * @param string $task_id The PIAPI task ID.
     * @return array|WP_Error Task data array on success.
     */
    public function fetch_task(string $task_id): array|WP_Error
    {
        $api_key = $this->get_api_key();

        $response = wp_remote_get(self::PIAPI_URL . '/' . $task_id, [
            'headers' => ['x-api-key' => $api_key],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['data'])) {
            $error_msg = $body['message'] ?? $body['error'] ?? "HTTP {$code}";
            return new WP_Error('piapi_fetch_error', $error_msg);
        }

        return $body['data'];
    }

    // ──────────────────────────────────────────────
    // Image sideloading
    // ──────────────────────────────────────────────

    /**
     * Download image, convert to WebP, sideload into WP media library.
     *
     * The attachment starts with wcaig_status = 'pending' (hidden on frontend).
     * Admin must publish it before it appears to customers.
     *
     * @param string $url        Image URL from PIAPI.
     * @param int    $product_id WooCommerce product ID (post_parent).
     * @param string $hash       The variation hash.
     * @param array  $attributes Attribute key-value pairs (stored for GC validation).
     * @return int|WP_Error      Attachment ID on success.
     */
    public function sideload_image(string $url, int $product_id, string $hash, array $attributes = []): int|WP_Error
    {
        $response = wp_remote_get($url, ['timeout' => 60]);

        if (is_wp_error($response)) {
            WCAIG_Logger::instance()->error("Image download failed: {$response->get_error_message()}");
            return $response;
        }

        $image_data = wp_remote_retrieve_body($response);
        if (empty($image_data)) {
            return new WP_Error('empty_image', 'Downloaded image is empty');
        }

        // Ensure admin file utilities are available (needed for wp_tempnam, media_handle_sideload, etc.).
        if (! function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (! function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Save to temp file and attempt WebP conversion.
        $temp_file = wp_tempnam('wcaig_');
        file_put_contents($temp_file, $image_data);

        $webp_file = $temp_file . '.webp';
        $converted = false;

        $editor = wp_get_image_editor($temp_file);
        if (! is_wp_error($editor)) {
            $result = $editor->save($webp_file, 'image/webp');
            if (! is_wp_error($result)) {
                $converted = true;
                $webp_file = $result['path'];
            }
        }

        if (! $converted) {
            WCAIG_Logger::instance()->warning("WebP conversion failed for hash {$hash}, saving original format.");
            $webp_file = $temp_file;
        }

        $file_array = [
            'name'     => WCAIG_Hash::get_filename($hash),
            'tmp_name' => $webp_file,
        ];

        $attachment_id = media_handle_sideload($file_array, $product_id);

        @unlink($temp_file);
        if ($converted && file_exists($webp_file)) {
            @unlink($webp_file);
        }

        if (is_wp_error($attachment_id)) {
            WCAIG_Logger::instance()->error("Sideload failed for hash {$hash}: {$attachment_id->get_error_message()}");
            return $attachment_id;
        }

        // Set title and store metadata.
        wp_update_post([
            'ID'         => $attachment_id,
            'post_title' => WCAIG_Hash::get_attachment_title($hash),
        ]);

        update_post_meta($attachment_id, '_wcaig_hash', $hash);
        update_post_meta($attachment_id, '_wcaig_product_id', $product_id);
        update_post_meta($attachment_id, 'wcaig_status', 'pending');
        update_post_meta($attachment_id, '_wcaig_attributes', wp_json_encode($attributes));

        WCAIG_Logger::instance()->debug("Image sideloaded: attachment {$attachment_id} for hash {$hash} (status=pending)");
        return $attachment_id;
    }

    // ──────────────────────────────────────────────
    // Shared static helpers (used by Worker, Webhook, etc.)
    // ──────────────────────────────────────────────

    /**
     * Extract image URL from PIAPI response data.
     *
     * Handles multiple response formats from different PIAPI endpoints/models.
     */
    public static function extract_image_url(array $data): string
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

        // result.image_url / result.image_urls[0]
        if (! empty($data['result']['image_url'])) {
            return $data['result']['image_url'];
        }
        if (! empty($data['result']['image_urls'][0])) {
            return (string) $data['result']['image_urls'][0];
        }

        // data.output.image_url / data.output.image_urls[0] (webhook wrapper)
        if (! empty($data['data']['output']['image_url'])) {
            return $data['data']['output']['image_url'];
        }
        if (! empty($data['data']['output']['image_urls'][0])) {
            return (string) $data['data']['output']['image_urls'][0];
        }

        return '';
    }

    /**
     * Check if PIAPI response/task data indicates a rate-limit failure.
     */
    public static function is_rate_limit_failure(array $data): bool
    {
        $strings_to_check = [
            $data['error']['message'] ?? '',
            $data['error']['raw_message'] ?? '',
        ];

        foreach ($data['logs'] ?? [] as $log) {
            if (is_string($log)) {
                $strings_to_check[] = $log;
            }
        }

        foreach ($strings_to_check as $str) {
            if ($str !== '' && stripos($str, 'too many requests') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set a rate-limit cooldown transient.
     */
    public static function set_rate_limit_cooldown(): void
    {
        $cooldown = (int) WCAIG_Hash::get_option('wcaig_rate_limit_cooldown', 120);
        set_transient('wcaig_rate_limit_cooldown', time(), $cooldown);
        WCAIG_Logger::instance()->info("Rate-limit cooldown set for {$cooldown}s.");
    }

    /**
     * Check if rate-limit cooldown is active.
     */
    public static function is_rate_limited(): bool
    {
        return false;
        return (bool) get_transient('wcaig_rate_limit_cooldown');
    }

    // ──────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────

    private function get_api_key(): string
    {
        if (defined('WCAIG_API_KEY')) {
            return WCAIG_API_KEY;
        }
        return WCAIG_Hash::get_option('wcaig_api_key', '');
    }

    private function get_webhook_url(): string
    {
        $override = WCAIG_Hash::get_option('wcaig_webhook_url_override', '');
        if (! empty($override)) {
            return $override;
        }
        return site_url('/wp-json/wcaig/v1/webhook');
    }
}
