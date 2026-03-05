<?php
/**
 * API Client — PIAPI integration with retry & backoff.
 */

defined( 'ABSPATH' ) || exit;

class WCAIG_API_Client {

	const API_BASE = 'https://api.piapi.ai/api/v1';

	/**
	 * Create a task, poll until complete, return remote image URL.
	 *
	 * @param string   $prompt
	 * @param string[] $image_urls
	 * @return array { success: bool, image_url?: string, task_id?: string, error?: string }
	 */
	public function generate( string $prompt, array $image_urls ): array {
		$api_key       = get_option( 'wcaig_api_key', '' );
		$model         = get_option( 'wcaig_model', 'gemini' );
		$task_type     = get_option( 'wcaig_task_type', 'gemini-2.5-flash-image' );
		$retry_count   = (int) get_option( 'wcaig_retry_count', 2 );

		if ( empty( $api_key ) ) {
			return array( 'success' => false, 'error' => 'API key not configured.' );
		}

		$attempt = 0;
		$last_error = '';

		while ( $attempt <= $retry_count ) {
			if ( $attempt > 0 ) {
				$delay = pow( 2, $attempt ); // 2s, 4s, 8s…
				WC_AI_Image_Gen::log( "Retry #{$attempt} — sleeping {$delay}s." );
				sleep( $delay );
			}

			// Create task.
			$create_result = $this->create_task( $api_key, $model, $task_type, $prompt, $image_urls );
			if ( ! $create_result['success'] ) {
				$last_error = $create_result['error'];

				// Only retry on transient errors.
				if ( $this->is_transient_error( $create_result ) ) {
					$attempt++;
					continue;
				}
				return $create_result;
			}

			$task_id = $create_result['task_id'];
			WC_AI_Image_Gen::log( "Task created: {$task_id}" );

			// Poll until complete.
			$poll_result = $this->poll_task( $api_key, $task_id );
			if ( $poll_result['success'] ) {
				$poll_result['task_id'] = $task_id;
				return $poll_result;
			}

			$last_error = $poll_result['error'] ?? 'Unknown poll error.';

			if ( $this->is_transient_error( $poll_result ) ) {
				$attempt++;
				continue;
			}

			return $poll_result;
		}

		return array( 'success' => false, 'error' => 'All retries exhausted. Last error: ' . $last_error );
	}

	/* ------------------------------------------------------------------
	 *  Create Task
	 * ----------------------------------------------------------------*/

	private function create_task( string $api_key, string $model, string $task_type, string $prompt, array $image_urls ): array {
		$body = array(
			'model'     => $model,
			'task_type' => $task_type,
			'input'     => array(
				'prompt'     => $prompt,
				'image_urls' => $image_urls,
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
	 *  Poll Task
	 * ----------------------------------------------------------------*/

	private function poll_task( string $api_key, string $task_id ): array {
		$poll_interval    = (int) get_option( 'wcaig_poll_interval', 5 );
		$max_poll_attempts = (int) get_option( 'wcaig_max_poll_attempts', 60 );

		for ( $i = 0; $i < $max_poll_attempts; $i++ ) {
			sleep( $poll_interval );

			$response = wp_remote_get( self::API_BASE . '/task/' . $task_id, array(
				'headers' => array( 'X-API-Key' => $api_key ),
				'timeout' => 30,
			) );

			if ( is_wp_error( $response ) ) {
				WC_AI_Image_Gen::log( "Poll error for {$task_id}: " . $response->get_error_message() );
				continue; // Keep trying.
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 500 ) {
				WC_AI_Image_Gen::log( "Poll server error ({$code}) for {$task_id}." );
				continue;
			}

			$data   = json_decode( wp_remote_retrieve_body( $response ), true );
			$status = $data['data']['status'] ?? ( $data['status'] ?? 'unknown' );

			WC_AI_Image_Gen::log( "Poll #{$i} for {$task_id}: status={$status}" );

			if ( 'completed' === $status ) {
				$output    = $data['data']['output'] ?? ( $data['output'] ?? array() );
				$image_url = $this->extract_image_url( $output );

				if ( ! $image_url ) {
					return array( 'success' => false, 'error' => 'Task completed but no image URL found in output.' );
				}

				return array( 'success' => true, 'image_url' => $image_url );
			}

			if ( 'failed' === $status ) {
				$msg = $data['data']['error'] ?? ( $data['error'] ?? 'Task failed.' );
				return array(
					'success'   => false,
					'error'     => $msg,
					'transient' => true,
				);
			}

			// pending / processing → keep polling.
		}

		return array( 'success' => false, 'error' => 'Polling timed out after ' . $max_poll_attempts . ' attempts.' );
	}

	/* ------------------------------------------------------------------
	 *  Extract Image URL
	 * ----------------------------------------------------------------*/

	/**
	 * Try multiple response shapes to find the generated image URL.
	 */
	private function extract_image_url( $output ): string {
		if ( is_string( $output ) && filter_var( $output, FILTER_VALIDATE_URL ) ) {
			return $output;
		}

		if ( is_array( $output ) ) {
			// output.image_url
			if ( ! empty( $output['image_url'] ) ) {
				return $output['image_url'];
			}

			// output.image_urls[0]
			if ( ! empty( $output['image_urls'][0] ) ) {
				return $output['image_urls'][0];
			}

			// output.images[0].url
			if ( ! empty( $output['images'][0]['url'] ) ) {
				return $output['images'][0]['url'];
			}
		}

		return '';
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
