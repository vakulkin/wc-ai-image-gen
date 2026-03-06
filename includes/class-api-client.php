<?php
/**
 * API Client — PIAPI integration (task creation + sideload).
 *
 * Task completion is handled asynchronously via webhook (class-webhook-handler.php).
 * This class only creates the task and sideloads images — no server-side polling.
 */

defined( 'ABSPATH' ) || exit;

class WCAIG_API_Client {

	const API_BASE = 'https://api.piapi.ai/api/v1';

	/**
	 * Create a PIAPI task with webhook config. Returns immediately with task_id.
	 *
	 * @param string   $prompt
	 * @param string[] $image_urls
	 * @return array { success: bool, task_id?: string, error?: string }
	 */
	public function create_task( string $prompt, array $image_urls ): array {
		$api_key   = get_option( 'wcaig_api_key', '' );
		$model     = get_option( 'wcaig_model', 'gemini' );
		$task_type = get_option( 'wcaig_task_type', 'gemini-2.5-flash-image' );

		if ( empty( $api_key ) ) {
			return array( 'success' => false, 'error' => 'API key not configured.' );
		}

		$retry_count = (int) get_option( 'wcaig_retry_count', 2 );
		$attempt     = 0;
		$last_error  = '';

		while ( $attempt <= $retry_count ) {
			if ( $attempt > 0 ) {
				$delay = pow( 2, $attempt ); // 2s, 4s, 8s…
				WC_AI_Image_Gen::log( "Task create retry #{$attempt} — sleeping {$delay}s." );
				sleep( $delay );
			}

			$result = $this->send_create_request( $api_key, $model, $task_type, $prompt, $image_urls );

			if ( $result['success'] ) {
				WC_AI_Image_Gen::log( 'Task created: ' . $result['task_id'] );
				return $result;
			}

			$last_error = $result['error'];

			if ( ! $this->is_transient_error( $result ) ) {
				return $result;
			}

			$attempt++;
		}

		return array( 'success' => false, 'error' => 'All retries exhausted. Last error: ' . $last_error );
	}

	/* ------------------------------------------------------------------
	 *  HTTP: Create Task
	 * ----------------------------------------------------------------*/

	private function send_create_request( string $api_key, string $model, string $task_type, string $prompt, array $image_urls ): array {
		// Build webhook config.
		$webhook_config = array(
			'endpoint' => WCAIG_Webhook_Handler::get_endpoint_url(),
		);

		$webhook_secret = get_option( 'wcaig_webhook_secret', '' );
		if ( ! empty( $webhook_secret ) ) {
			$webhook_config['secret'] = $webhook_secret;
		}

		$body = array(
			'model'     => $model,
			'task_type' => $task_type,
			'input'     => array(
				'prompt'     => $prompt,
				'image_urls' => $image_urls,
			),
			'config'    => array(
				'webhook_config' => $webhook_config,
			),
		);

		$response = wp_remote_post( self::API_BASE . '/task', array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-API-Key'    => $api_key,
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'success'   => false,
				'error'     => 'HTTP error: ' . $response->get_error_message(),
				'transient' => true,
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 500 ) {
			return array(
				'success'   => false,
				'error'     => "Server error ({$code}).",
				'transient' => true,
			);
		}

		if ( $code >= 400 ) {
			$msg = $data['message'] ?? $data['error'] ?? "Client error ({$code}).";
			return array( 'success' => false, 'error' => $msg );
		}

		$task_id = $data['data']['task_id'] ?? ( $data['task_id'] ?? '' );
		if ( empty( $task_id ) ) {
			return array( 'success' => false, 'error' => 'No task_id in create response.' );
		}

		return array( 'success' => true, 'task_id' => $task_id );
	}

	/* ------------------------------------------------------------------
	 *  Sideload
	 * ----------------------------------------------------------------*/

	/**
	 * Download a remote image and add it to the Media Library.
	 *
	 * @param string $image_url  Remote URL.
	 * @param string $hash       Cache hash (used for title).
	 * @param int    $product_id Associated product.
	 * @return int|WP_Error      Attachment ID or error.
	 */
	public function sideload_image( string $image_url, string $hash, int $product_id ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Download to temp file.
		$tmp = download_url( $image_url, 60 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// Determine extension from URL or content type.
		$ext      = pathinfo( wp_parse_url( $image_url, PHP_URL_PATH ), PATHINFO_EXTENSION );
		$ext      = $ext ?: 'jpg';
		$filename = "wcaig_{$hash}.{$ext}";

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, $product_id );

		// Clean up temp file on error.
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return $attachment_id;
		}

		// Set the title convention.
		wp_update_post( array(
			'ID'         => $attachment_id,
			'post_title' => 'wcaig_' . $hash,
		) );

		WC_AI_Image_Gen::log( "Sideloaded image: attachment={$attachment_id}, hash={$hash}." );

		return $attachment_id;
	}

	/* ------------------------------------------------------------------
	 *  Helpers
	 * ----------------------------------------------------------------*/

	private function is_transient_error( array $result ): bool {
		return ! empty( $result['transient'] );
	}
}
