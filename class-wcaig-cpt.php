<?php

/**
 * WCAIG CPT — Custom Post Type for image variations.
 *
 * @package WC_AI_Image_Gen
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAIG_CPT {
	private static ?WCAIG_CPT $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ $this, 'register_custom_statuses' ] );
		// add_action( 'save_post_image_variation', [ $this, 'on_save_post' ], 10, 1 );
	}

	/**
	 * Register the image_variation CPT.
	 */
	public function register_post_type(): void {
		register_post_type( 'image_variation', [
			'labels' => [
				'name' => __( 'Image Variations', 'wc-ai-image-gen' ),
				'singular_name' => __( 'Image Variation', 'wc-ai-image-gen' ),
			],
			'public' => false,
			'show_ui' => true,
			'supports' => [ 'title', 'thumbnail' ],
		] );
	}

	/**
	 * Register custom post statuses: processing, failed.
	 */
	public function register_custom_statuses(): void {
		register_post_status( 'processing', [
			'label' => __( 'Processing', 'wc-ai-image-gen' ),
			'public' => false,
			'internal' => true, 
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
			'label_count' => _n_noop( 'Processing <span class="count">(%s)</span>', 'Processing <span class="count">(%s)</span>', 'wc-ai-image-gen' ),
		] );

		register_post_status( 'failed', [
			'label' => __( 'Failed', 'wc-ai-image-gen' ),
			'public' => false,
			'internal' => true,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
			'label_count' => _n_noop( 'Failed <span class="count">(%s)</span>', 'Failed <span class="count">(%s)</span>', 'wc-ai-image-gen' ),
		] );
	}

	/**
	 * Find an image_variation post by hash (via slug).
	 *
	 * @param string $hash The variation hash.
	 * @return WP_Post|null The post object or null.
	 */
	public static function find_by_hash( string $hash ): ?WP_Post {
		$slug = WCAIG_Hash::get_slug( $hash );

		$posts = get_posts( [
			'post_type' => 'image_variation',
			'name' => $slug,
			'post_status' => [ 'draft', 'publish', 'processing', 'failed', 'trash' ],
			'posts_per_page' => 1,
			'orderby' => 'ID',
			'order' => 'ASC',
		] );

		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Create a new image_variation CPT post.
	 *
	 * @param int    $product_id WC product ID.
	 * @param array  $attributes Key-value attribute pairs.
	 * @param string $hash       The computed hash.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function create_variation( int $product_id, array $attributes, string $hash ): int|WP_Error {
		$post_id = wp_insert_post( [
			'post_type' => 'image_variation',
			'post_title' => "Variation {$hash}",
			'post_name' => WCAIG_Hash::get_slug( $hash ),
			'post_status' => 'draft',
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set ACF fields.
		if ( function_exists( 'update_field' ) ) {
			update_field( 'wcaig_parent_product', $product_id, $post_id );
			update_field( 'wcaig_retry_count', 0, $post_id );

			foreach ( $attributes as $attr_name => $value ) {
				$normalized = strtolower( trim( preg_replace( '/^pa_/', '', $attr_name ) ) );
				$taxonomy = 'pa_' . $normalized;
				$term = get_term_by( 'slug', $value, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					update_field( "wcaig_attr_{$normalized}", $term->term_id, $post_id );
				}
			}
		}

		return $post_id;
	}

	/**
	 * Handle post save: recompute hash and reset if changed.
	 *
	 * @param int $post_id The post ID.
	 */
	public function on_save_post( int $post_id ): void {
		// Avoid infinite loops.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'image_variation' !== $post->post_type ) {
			return;
		}

		// Get stored attributes and product.
		$product_id = get_field( 'wcaig_parent_product', $post_id );
		if ( ! $product_id ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$enabled = WCAIG_Hash::get_enabled_attributes( $product_id );

		// Read stored attribute values from ACF fields using enabled list.
		$attributes = [];
		foreach ( $enabled as $attr_name ) {
			$term = get_field( "wcaig_attr_{$attr_name}", $post_id );
			if ( $term instanceof WP_Term ) {
				$attributes[ $attr_name ] = $term->slug;
			} elseif ( $term ) {
				$attributes[ $attr_name ] = $term;
			}
		}

		$new_hash = WCAIG_Hash::compute( $product_id, $attributes, $enabled );
		$old_slug = $post->post_name;
		$new_slug = WCAIG_Hash::get_slug( $new_hash );

		// If hash changed, reset the variation.
		if ( $old_slug !== $new_slug ) {
			$old_hash = str_replace( 'variation_', '', $old_slug );

			// Delete old featured image.
			$thumb_id = get_post_thumbnail_id( $post_id );
			if ( $thumb_id ) {
				wp_delete_attachment( $thumb_id, true );
			}

			// Delete old transient.
			delete_transient( "wcaig_image_{$old_hash}" );

			// Reset the post.
			remove_action( 'save_post_image_variation', [ $this, 'on_save_post' ] );
			wp_update_post( [
				'ID' => $post_id,
				'post_name' => $new_slug,
				'post_title' => "Variation {$new_hash}",
				'post_status' => 'draft',
			] );
			add_action( 'save_post_image_variation', [ $this, 'on_save_post' ], 10, 1 );

			update_field( 'wcaig_task_id', '', $post_id );
			update_field( 'wcaig_retry_count', 0, $post_id );
		}
	}
}
