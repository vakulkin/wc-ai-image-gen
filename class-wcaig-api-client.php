<?php

/**
 * WCAIG API Client — PIAPI client for task creation and image sideloading.
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

    /**
     * Create a PIAPI task for image generation.
     *
     * @param string $prompt     The constructed prompt.
     * @param string $image_url  The base image URL.
     * @param string $hash       The variation hash.
     * @return string|WP_Error Task ID on success, WP_Error on failure.
     */
    public function create_task(string $prompt, string $image_url, string $hash): string|WP_Error
    {
        $api_key     = $this->get_api_key();
        $webhook_url = $this->get_webhook_url();
        $model       = $this->get_option('wcaig_model', 'gemini');
        $task_type   = $this->get_option('wcaig_task_type', 'gemini-2.5-flash-image');
        $secret      = $this->get_option('wcaig_webhook_secret', '');

        $payload = [
            'model'     => $model,
            'task_type' => $task_type,
            'input'     => [
                'prompt'     => $prompt,
                'image_urls' => [ $image_url ],
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
                'x-api-key'     => $api_key,
                'Content-Type'  => 'application/json',
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

        // Detect HTTP-level rate limiting (429).
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
     * Download image from URL, convert to WebP, and sideload into WP media library.
     *
     * @param string $url     The image URL from PIAPI.
     * @param int    $post_id The parent image_variation post ID.
     * @param string $hash    The variation hash.
     * @return int|WP_Error Attachment ID on success, WP_Error on failure.
     */
    public function sideload_image(string $url, int $post_id, string $hash): int|WP_Error
    {
        // Download the image.
        $response = wp_remote_get($url, [
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            WCAIG_Logger::instance()->error("Image download failed: {$response->get_error_message()}");
            return $response;
        }

        $image_data = wp_remote_retrieve_body($response);
        if (empty($image_data)) {
            return new WP_Error('empty_image', 'Downloaded image is empty');
        }

        // Save to temp file.
        $temp_file = wp_tempnam('wcaig_');
        file_put_contents($temp_file, $image_data);

        // Attempt WebP conversion.
        $webp_file = $temp_file . '.webp';
        $converted = false;

        $editor = wp_get_image_editor($temp_file);
        if (! is_wp_error($editor)) {
            $result = $editor->save($webp_file, 'image/webp');
            if (! is_wp_error($result)) {
                $converted  = true;
                $webp_file  = $result['path'];
            }
        }

        if (! $converted) {
            WCAIG_Logger::instance()->warning("WebP conversion failed for hash {$hash}, saving original format.");
            $webp_file = $temp_file;
        }

        // Build file array for sideload.
        $filename   = WCAIG_Hash::get_filename($hash);
        $file_array = [
            'name'     => $filename,
            'tmp_name' => $webp_file,
        ];

        // Load required functions.
        if (! function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attachment_id = media_handle_sideload($file_array, $post_id);

        // Clean up temp files.
        @unlink($temp_file);
        if ($converted && file_exists($webp_file)) {
            @unlink($webp_file);
        }

        if (is_wp_error($attachment_id)) {
            WCAIG_Logger::instance()->error("Sideload failed for hash {$hash}: {$attachment_id->get_error_message()}");
            return $attachment_id;
        }

        // Set attachment title.
        wp_update_post([
            'ID'         => $attachment_id,
            'post_title' => WCAIG_Hash::get_attachment_title($hash),
        ]);

        WCAIG_Logger::instance()->debug("Image sideloaded: attachment {$attachment_id} for hash {$hash}");
        return $attachment_id;
    }

    /**
     * Fetch task status from PIAPI.
     *
     * @param string $task_id The PIAPI task ID.
     * @return array|WP_Error Task data array on success, WP_Error on failure.
     */
    public function fetch_task(string $task_id): array|WP_Error
    {
        $api_key = $this->get_api_key();

        $response = wp_remote_get(self::PIAPI_URL . '/' . $task_id, [
            'headers' => [
                'x-api-key' => $api_key,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            WCAIG_Logger::instance()->error("PIAPI fetch_task failed: {$response->get_error_message()}");
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

    /**
     * Get the API key.
     *
     * @return string
     */
    private function get_api_key(): string
    {
        if (defined('WCAIG_API_KEY')) {
            return WCAIG_API_KEY;
        }

        return $this->get_option('wcaig_api_key', '');
    }

    /**
     * Get the webhook URL.
     *
     * @return string
     */
    private function get_webhook_url(): string
    {
        $override = $this->get_option('wcaig_webhook_url_override', '');
        if (! empty($override)) {
            return $override;
        }

        return site_url('/wp-json/wcaig/v1/webhook');
    }

    /**
     * Get an ACF option field value with fallback.
     *
     * @param string $field   ACF field name.
     * @param mixed  $default Default value.
     * @return mixed
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
