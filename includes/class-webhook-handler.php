<?php
/**
 * Webhook Handler — REST API endpoint that receives PiAPI task completions.
 *
 * Endpoint: /wp-json/wcaig/v1/webhook
 *
 * When PiAPI completes (or fails) a task, it POSTs to this endpoint.
 * On success: sideloads the generated image, stores it in the cache table,
 * logs usage, and clears the generation lock so frontend polling picks it up.
 */

defined( 'ABSPATH' ) || exit;

class WCAIG_Webhook_Handler {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/* ------------------------------------------------------------------
	 *  REST Route
	 * ----------------------------------------------------------------*/

	public function register_routes(): void {
		register_rest_route( 'wcaig/v1', '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_webhook' ),
			'permission_callback' => '__return_true', // Auth via secret header.
		) );
	}

	/**
	 * Get the public webhook endpoint URL.
	 */
	public static function get_endpoint_url(): string {
		return rest_url( 'wcaig/v1/webhook' );
	}

	/* ------------------------------------------------------------------
	 *  Handler
	 * ----------------------------------------------------------------*/

	/**
	 * Process incoming PiAPI webhook POST.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		WC_AI_Image_Gen::log( 'Webhook received.' );

		// 1. Verify secret.
		if ( ! $this->verify_secret( $request ) ) {
			WC_AI_Image_Gen::log( 'Webhook secret mismatch — rejecting.' );
			return new WP_REST_Response( array( 'error' => 'Invalid secret.' ), 403 );
		}

		// 2. Parse body.
		$body = $request->get_json_params();

		if ( empty( $body['data'] ) ) {
			WC_AI_Image_Gen::log( 'Webhook body missing "data" field.' );
			return new WP_REST_Response( array( 'error' => 'Invalid payload.' ), 400 );
		}

		$task_data = $body['data'];
		$task_id   = $task_data['task_id'] ?? '';
		$status    = $task_data['status'] ?? '';
		$timestamp = $body['timestamp'] ?? 0;

		WC_AI_Image_Gen::log( "Webhook: task_id={$task_id}, status={$status}, ts={$timestamp}" );

		if ( empty( $task_id ) ) {
			return new WP_REST_Response( array( 'error' => 'Missing task_id.' ), 400 );
		}

		// 3. Retrieve stored task metadata.
		$meta_key  = 'wcaig_task_' . $task_id;
		$task_meta = get_transient( $meta_key );

		if ( ! $task_meta ) {
			WC_AI_Image_Gen::log( "Webhook: no metadata transient for task {$task_id} — possibly expired or duplicate." );
			// Still return 200 so PiAPI doesn't retry.
			return new WP_REST_Response( array( 'ok' => true, 'note' => 'No pending metadata.' ), 200 );
		}

		// 4. Dedup: check timestamp to avoid processing the same webhook twice.
		$dedup_key = 'wcaig_webhook_ts_' . $task_id;
		$prev_ts   = get_transient( $dedup_key );
		if ( $prev_ts && (string) $prev_ts === (string) $timestamp ) {
			WC_AI_Image_Gen::log( "Webhook: duplicate timestamp for {$task_id} — skipping." );
			return new WP_REST_Response( array( 'ok' => true, 'note' => 'Duplicate.' ), 200 );
		}
		set_transient( $dedup_key, $timestamp, 600 ); // 10 min TTL.

		$product_id    = (int) $task_meta['product_id'];
		$base_image_id = (int) $task_meta['base_image_id'];
		$attributes    = $task_meta['attributes'];
		$hash          = $task_meta['hash'];
		$prompt_text   = $task_meta['prompt_text'];
		$lock_key      = 'wcaig_generating_' . $product_id . '_' . $hash;

		// 5. Handle status.
		if ( 'completed' === $status ) {
			$this->handle_completed( $task_data, $product_id, $base_image_id, $attributes, $hash, $prompt_text, $task_id );
		} elseif ( 'failed' === $status ) {
			$this->handle_failed( $task_data, $product_id, $hash, $task_id );
		} else {
			// processing / pending — PiAPI may send intermediate updates for some models.
			WC_AI_Image_Gen::log( "Webhook: intermediate status '{$status}' for {$task_id} — acknowledged." );
		}

		// Return 200 quickly as per PiAPI best practices.
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* ------------------------------------------------------------------
	 *  Completed
	 * ----------------------------------------------------------------*/

	private function handle_completed( array $task_data, int $product_id, int $base_image_id, array $attributes, string $hash, string $prompt_text, string $task_id ): void {
		$output    = $task_data['output'] ?? array();
		$image_url = $this->extract_image_url( $output );

		if ( ! $image_url ) {
			WC_AI_Image_Gen::log( "Webhook completed but no image URL found for task {$task_id}." );
			$this->store_error( $product_id, $hash, 'Task completed but no image URL in output.' );
			$this->cleanup_transients( $task_id, $product_id, $hash );
			return;
		}

		WC_AI_Image_Gen::log( "Webhook: sideloading image for task {$task_id}: {$image_url}" );

		// Sideload into Media Library.
		$api           = new WCAIG_API_Client();
		$attachment_id = $api->sideload_image( $image_url, $hash, $product_id );

		if ( is_wp_error( $attachment_id ) ) {
			WC_AI_Image_Gen::log( "Webhook: sideload failed for task {$task_id}: " . $attachment_id->get_error_message() );
			$this->store_error( $product_id, $hash, 'Image sideload failed: ' . $attachment_id->get_error_message() );
			$this->cleanup_transients( $task_id, $product_id, $hash );
			return;
		}

		// Store in cache.
		$cache = WCAIG_Image_Cache::instance();
		$cache->store( $product_id, $base_image_id, $attributes, $attachment_id, $prompt_text );

		// Log usage.
		WCAIG_Usage_Tracker::instance()->log_usage(
			$product_id,
			$task_id,
			get_option( 'wcaig_model', 'gemini' )
		);

		WC_AI_Image_Gen::log( "Webhook: cached image for product {$product_id}, hash={$hash}, attachment={$attachment_id}." );

		// Clean up.
		$this->cleanup_transients( $task_id, $product_id, $hash );
	}

	/* ------------------------------------------------------------------
	 *  Failed
	 * ----------------------------------------------------------------*/

	private function handle_failed( array $task_data, int $product_id, string $hash, string $task_id ): void {
		$error_msg = 'Unknown error.';

		if ( ! empty( $task_data['error']['message'] ) ) {
			$error_msg = $task_data['error']['message'];
		} elseif ( ! empty( $task_data['error'] ) && is_string( $task_data['error'] ) ) {
			$error_msg = $task_data['error'];
		}

		WC_AI_Image_Gen::log( "Webhook: task {$task_id} failed — {$error_msg}" );

		$this->store_error( $product_id, $hash, $error_msg );
		$this->cleanup_transients( $task_id, $product_id, $hash );
	}

	/* ------------------------------------------------------------------
	 *  Helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Verify the X-Webhook-Secret header matches our configured secret.
	 */
	private function verify_secret( WP_REST_Request $request ): bool {
		$expected = get_option( 'wcaig_webhook_secret', '' );

		// If no secret configured, skip verification (not recommended for production).
		if ( empty( $expected ) ) {
			return true;
		}

		$received = $request->get_header( 'x-webhook-secret' );

		return hash_equals( $expected, (string) $received );
	}

	/**
	 * Extract image URL from PiAPI output (multiple response shapes).
	 */
	private function extract_image_url( $output ): string {
		if ( is_string( $output ) && filter_var( $output, FILTER_VALIDATE_URL ) ) {
			return $output;
		}

		if ( is_array( $output ) ) {
			if ( ! empty( $output['image_url'] ) ) {
				return $output['image_url'];
			}
			if ( ! empty( $output['image_urls'][0] ) ) {
				return $output['image_urls'][0];
			}
			if ( ! empty( $output['images'][0]['url'] ) ) {
				return $output['images'][0]['url'];
			}
		}

		return '';
	}

	/**
	 * Store an error so the frontend poll can report it.
	 */
	private function store_error( int $product_id, string $hash, string $message ): void {
		$error_key = 'wcaig_error_' . $product_id . '_' . $hash;
		set_transient( $error_key, $message, 300 ); // 5 min TTL.
	}

	/**
	 * Remove task metadata and lock transients.
	 */
	private function cleanup_transients( string $task_id, int $product_id, string $hash ): void {
		delete_transient( 'wcaig_task_' . $task_id );
		delete_transient( 'wcaig_generating_' . $product_id . '_' . $hash );
		delete_transient( 'wcaig_webhook_ts_' . $task_id );
	}
}
