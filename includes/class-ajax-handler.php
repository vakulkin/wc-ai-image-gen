<?php
/**
 * AJAX Handler — validates requests, orchestrates async generation via webhook.
 *
 * Flow:
 *   1. wcaig_check     → cache lookup (same as before)
 *   2. wcaig_generate  → creates PIAPI task with webhook, returns task_id immediately
 *   3. wcaig_poll      → frontend polls this to check if webhook has delivered the result
 *
 * Concurrent-request dedup:
 *   If a lock transient already exists for this product+hash, the existing task_id is
 *   returned so the new client can poll the same in-flight task.
 */

defined( 'ABSPATH' ) || exit;

class WCAIG_Ajax_Handler {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Public AJAX (customer-facing).
		add_action( 'wp_ajax_wcaig_check', array( $this, 'handle_check' ) );
		add_action( 'wp_ajax_nopriv_wcaig_check', array( $this, 'handle_check' ) );

		add_action( 'wp_ajax_wcaig_generate', array( $this, 'handle_generate' ) );
		add_action( 'wp_ajax_nopriv_wcaig_generate', array( $this, 'handle_generate' ) );

		add_action( 'wp_ajax_wcaig_poll', array( $this, 'handle_poll' ) );
		add_action( 'wp_ajax_nopriv_wcaig_poll', array( $this, 'handle_poll' ) );
	}

	/* ------------------------------------------------------------------
	 *  Check (cache lookup)
	 * ----------------------------------------------------------------*/

	public function handle_check(): void {
		check_ajax_referer( 'wcaig_nonce', 'nonce' );

		$product_id = $this->validate_product();
		if ( is_wp_error( $product_id ) ) {
			wp_send_json_error( $product_id->get_error_message() );
		}

		$attributes    = $this->sanitize_attributes();
		$base_image_id = (int) get_post_thumbnail_id( $product_id );

		if ( ! $base_image_id ) {
			wp_send_json_error( 'Product has no featured image.' );
		}

		// Collect ref thumbs for this specific attribute selection.
		$ref_thumbs = $this->collect_selection_thumbs( $product_id, $base_image_id, $attributes );

		$cache         = WCAIG_Image_Cache::instance();
		$attachment_id = $cache->find( $product_id, $base_image_id, $attributes );

		if ( $attachment_id ) {
			$image_url = wp_get_attachment_url( $attachment_id );
			wp_send_json_success( array(
				'hit'        => true,
				'image_url'  => $image_url,
				'ref_thumbs' => $ref_thumbs,
			) );
		}

		wp_send_json_success( array(
			'hit'        => false,
			'ref_thumbs' => $ref_thumbs,
		) );
	}

	/* ------------------------------------------------------------------
	 *  Generate (async — returns task_id immediately)
	 * ----------------------------------------------------------------*/

	public function handle_generate(): void {
		check_ajax_referer( 'wcaig_nonce', 'nonce' );

		$product_id = $this->validate_product();
		if ( is_wp_error( $product_id ) ) {
			wp_send_json_error( $product_id->get_error_message() );
		}

		$attributes    = $this->sanitize_attributes();
		$base_image_id = (int) get_post_thumbnail_id( $product_id );

		if ( ! $base_image_id ) {
			wp_send_json_error( 'Product has no featured image.' );
		}

		$cache = WCAIG_Image_Cache::instance();

		// Race condition guard: re-check cache.
		$existing = $cache->find( $product_id, $base_image_id, $attributes );
		if ( $existing ) {
			wp_send_json_success( array(
				'status'    => 'completed',
				'image_url' => wp_get_attachment_url( $existing ),
			) );
		}

		// Compute hash for lock key.
		$hash     = $cache->compute_hash( $product_id, $base_image_id, $attributes );
		$lock_key = 'wcaig_generating_' . $product_id . '_' . $hash;

		// Dedup: if already in-flight, return the existing task_id for polling.
		$existing_task_id = get_transient( $lock_key );
		if ( $existing_task_id && is_string( $existing_task_id ) ) {
			WC_AI_Image_Gen::log( "Generate: dedup — returning existing task {$existing_task_id} for hash {$hash}." );
			wp_send_json_success( array(
				'status'  => 'pending',
				'task_id' => $existing_task_id,
			) );
		}

		// Build prompt.
		$builder     = new WCAIG_Prompt_Builder();
		$prompt_data = $builder->build( $product_id, $base_image_id, $attributes );

		if ( empty( $prompt_data['prompt'] ) || empty( $prompt_data['image_urls'] ) ) {
			wp_send_json_error( 'Failed to build prompt.' );
		}

		// Create task at PIAPI (with webhook config — returns immediately).
		$api    = new WCAIG_API_Client();
		$result = $api->create_task( $prompt_data['prompt'], $prompt_data['image_urls'] );

		if ( ! $result['success'] ) {
			wp_send_json_error( $result['error'] ?? 'API task creation failed.' );
		}

		$task_id = $result['task_id'];

		// Store lock transient with task_id (so concurrent requests can dedup).
		// TTL = 1 hour — generous; webhook cleanup will delete it earlier.
		set_transient( $lock_key, $task_id, 3600 );

		// Store task metadata so the webhook handler knows what to do.
		$meta_key = 'wcaig_task_' . $task_id;
		set_transient( $meta_key, array(
			'product_id'    => $product_id,
			'base_image_id' => $base_image_id,
			'attributes'    => $attributes,
			'hash'          => $hash,
			'prompt_text'   => $prompt_data['prompt'],
		), 3600 );

		WC_AI_Image_Gen::log( "Generate: created task {$task_id} for product {$product_id}, hash={$hash}." );

		// Return immediately — frontend will poll wcaig_poll.
		wp_send_json_success( array(
			'status'  => 'pending',
			'task_id' => $task_id,
		) );
	}

	/* ------------------------------------------------------------------
	 *  Poll (frontend checks if webhook has delivered the result)
	 * ----------------------------------------------------------------*/

	public function handle_poll(): void {
		check_ajax_referer( 'wcaig_nonce', 'nonce' );

		$product_id = $this->validate_product();
		if ( is_wp_error( $product_id ) ) {
			wp_send_json_error( $product_id->get_error_message() );
		}

		$attributes    = $this->sanitize_attributes();
		$base_image_id = (int) get_post_thumbnail_id( $product_id );

		if ( ! $base_image_id ) {
			wp_send_json_error( 'Product has no featured image.' );
		}

		$cache = WCAIG_Image_Cache::instance();
		$hash  = $cache->compute_hash( $product_id, $base_image_id, $attributes );

		// 1. Check if the image is now in cache (webhook completed successfully).
		$attachment_id = $cache->find( $product_id, $base_image_id, $attributes );
		if ( $attachment_id ) {
			wp_send_json_success( array(
				'status'    => 'completed',
				'image_url' => wp_get_attachment_url( $attachment_id ),
			) );
		}

		// 2. Check if the webhook reported an error.
		$error_key = 'wcaig_error_' . $product_id . '_' . $hash;
		$error_msg = get_transient( $error_key );
		if ( $error_msg ) {
			delete_transient( $error_key );
			wp_send_json_success( array(
				'status' => 'failed',
				'error'  => $error_msg,
			) );
		}

		// 3. Check if the lock is still active (task still in-flight).
		$lock_key = 'wcaig_generating_' . $product_id . '_' . $hash;
		$lock_val = get_transient( $lock_key );
		if ( $lock_val ) {
			wp_send_json_success( array(
				'status' => 'processing',
			) );
		}

		// 4. Lock gone, no cache, no error — something unexpected happened.
		wp_send_json_success( array(
			'status' => 'failed',
			'error'  => 'Generation status unknown. The task may have expired. Please try again.',
		) );
	}

	/* ------------------------------------------------------------------
	 *  Validation Helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Validate product_id from request. Returns product_id or WP_Error.
	 *
	 * @return int|WP_Error
	 */
	private function validate_product() {
		$product_id = absint( $_POST['product_id'] ?? 0 );

		if ( ! $product_id ) {
			return new WP_Error( 'invalid_product', 'Invalid product ID.' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', 'Product not found.' );
		}

		if ( ! $product->is_type( 'variable' ) ) {
			return new WP_Error( 'not_variable', 'Product is not a variable product.' );
		}

		if ( ! get_field( 'wcaig_enabled', $product_id ) ) {
			return new WP_Error( 'disabled', 'AI image generation is not enabled for this product.' );
		}

		return $product_id;
	}

	/**
	 * Sanitize and return the attributes array from POST.
	 */
	private function sanitize_attributes(): array {
		$raw = $_POST['attributes'] ?? array();
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();
		foreach ( $raw as $key => $value ) {
			$key   = sanitize_text_field( $key );
			$value = sanitize_text_field( $value );
			if ( $key && $value ) {
				$clean[ $key ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Collect reference thumbnails relevant to the current attribute selection.
	 */
	private function collect_selection_thumbs( int $product_id, int $base_image_id, array $attributes ): array {
		$thumbs = array();

		// Base image.
		$url = wp_get_attachment_image_url( $base_image_id, 'thumbnail' );
		if ( $url ) {
			$thumbs[] = $url;
		}

		// Prompt-config images.
		$rows = get_field( 'wcaig_prompt_config', $product_id );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! empty( $row['reference_image'] ) ) {
					$img_url = wp_get_attachment_image_url( $row['reference_image'], 'thumbnail' );
					if ( $img_url ) {
						$thumbs[] = $img_url;
					}
				}
			}
		}

		// Selected attribute term images.
		foreach ( $attributes as $attr_key => $term_slug ) {
			if ( empty( $term_slug ) ) {
				continue;
			}
			$taxonomy = preg_replace( '/^attribute_/', '', $attr_key );
			$term     = get_term_by( 'slug', $term_slug, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$img_id = get_field( 'wcaig_attr_ref_image', $term );
				if ( $img_id ) {
					$img_url = wp_get_attachment_image_url( $img_id, 'thumbnail' );
					if ( $img_url ) {
						$thumbs[] = $img_url;
					}
				}
			}
		}

		return array_values( array_unique( $thumbs ) );
	}
}
