<?php

/**
 * WCAIG ACF — ACF PRO field groups, options page, and AJAX handlers.
 *
 * @package WC_AI_Image_Gen
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAIG_ACF {
	private static ?WCAIG_ACF $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'acf/init', [ $this, 'register_options_page' ] );
		add_action( 'acf/init', [ $this, 'register_field_groups' ] );

		// AJAX handlers.
		add_action( 'wp_ajax_wcaig_run_worker', [ $this, 'ajax_run_worker' ] );
		add_action( 'wp_ajax_wcaig_force_run_worker', [ $this, 'ajax_force_run_worker' ] );
		add_action( 'wp_ajax_wcaig_run_gc', [ $this, 'ajax_run_gc' ] );
		add_action( 'wp_ajax_wcaig_purge_all', [ $this, 'ajax_purge_all' ] );
		add_action( 'wp_ajax_wcaig_regenerate', [ $this, 'ajax_regenerate' ] );
	}

	// ──────────────────────────────────────────────
	// ACF options page
	// ──────────────────────────────────────────────

	public function register_options_page(): void {
		if ( ! function_exists( 'acf_add_options_page' ) ) {
			return;
		}

		acf_add_options_page( [
			'page_title' => __( 'AI Image Gen Settings', 'wc-ai-image-gen' ),
			'menu_title' => __( 'AI Image Gen', 'wc-ai-image-gen' ),
			'menu_slug' => 'wcaig-settings',
			'parent_slug' => 'woocommerce',
			'capability' => 'manage_woocommerce',
		] );
	}

	// ──────────────────────────────────────────────
	// Field groups
	// ──────────────────────────────────────────────

	public function register_field_groups(): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		$this->register_options_fields();
		$this->register_product_fields();
		$this->register_term_fields();
		$this->register_attachment_fields();
	}

	private function register_options_fields(): void {
		acf_add_local_field_group( [
			'key' => 'group_wcaig_options',
			'title' => 'WCAIG Settings',
			'fields' => [
				[ 'key' => 'field_wcaig_api_key', 'label' => 'API Key', 'name' => 'wcaig_api_key', 'type' => 'text', 'instructions' => 'PIAPI API key. Can be overridden via WCAIG_API_KEY constant.' ],
				[ 'key' => 'field_wcaig_model', 'label' => 'Model', 'name' => 'wcaig_model', 'type' => 'text', 'default_value' => 'gemini' ],
				[ 'key' => 'field_wcaig_task_type', 'label' => 'Task Type', 'name' => 'wcaig_task_type', 'type' => 'text', 'default_value' => 'gemini-2.5-flash-image' ],
				[ 'key' => 'field_wcaig_webhook_secret', 'label' => 'Webhook Secret', 'name' => 'wcaig_webhook_secret', 'type' => 'text' ],
				[ 'key' => 'field_wcaig_webhook_url_override', 'label' => 'Webhook URL Override', 'name' => 'wcaig_webhook_url_override', 'type' => 'url', 'instructions' => 'Optional override URL sent to PIAPI.' ],
				[ 'key' => 'field_wcaig_retry_count', 'label' => 'Max Retries', 'name' => 'wcaig_retry_count', 'type' => 'number', 'default_value' => 3, 'min' => 0, 'max' => 20 ],
				[ 'key' => 'field_wcaig_max_concurrent', 'label' => 'Max Concurrent Tasks', 'name' => 'wcaig_max_concurrent', 'type' => 'number', 'default_value' => 5, 'min' => 1, 'max' => 50 ],
				[ 'key' => 'field_wcaig_task_timeout', 'label' => 'Task Timeout (min)', 'name' => 'wcaig_task_timeout', 'type' => 'number', 'default_value' => 30, 'min' => 5, 'max' => 1440 ],
				[ 'key' => 'field_wcaig_rate_limit_cooldown', 'label' => 'Rate-Limit Cooldown (sec)', 'name' => 'wcaig_rate_limit_cooldown', 'type' => 'number', 'default_value' => 120, 'min' => 10, 'max' => 3600 ],
				[ 'key' => 'field_wcaig_preservation_rules', 'label' => 'Custom Preservation Rules', 'name' => 'wcaig_preservation_rules', 'type' => 'textarea', 'instructions' => 'Text appended to every prompt.' ],
				[ 'key' => 'field_wcaig_global_image_cap', 'label' => 'Global Image Cap', 'name' => 'wcaig_global_image_cap', 'type' => 'number', 'default_value' => 1000, 'instructions' => '0 = unlimited.' ],
				[ 'key' => 'field_wcaig_poll_interval', 'label' => 'Frontend Poll Interval (sec)', 'name' => 'wcaig_poll_interval', 'type' => 'number', 'default_value' => 10, 'min' => 3, 'max' => 120 ],
				[ 'key' => 'field_wcaig_max_poll_attempts', 'label' => 'Max Poll Attempts', 'name' => 'wcaig_max_poll_attempts', 'type' => 'number', 'default_value' => 0, 'instructions' => '0 = unlimited.' ],
				[ 'key' => 'field_wcaig_debug_logging', 'label' => 'Debug Logging', 'name' => 'wcaig_debug_logging', 'type' => 'true_false', 'default_value' => 0 ],
				[ 'key' => 'field_wcaig_dryrun_gc', 'label' => 'Dry-Run GC', 'name' => 'wcaig_dryrun_gc', 'type' => 'true_false', 'default_value' => 0 ],
			],
			'location' => [ [ [ 'param' => 'options_page', 'operator' => '==', 'value' => 'wcaig-settings' ] ] ],
		] );
	}

	private function register_product_fields(): void {
		$fields = [
			[
				'key' => 'field_wcaig_base_image',
				'label' => 'Base Image for AI Generation',
				'name' => 'wcaig_base_image',
				'type' => 'image',
				'return_format' => 'id',
				'instructions' => 'Source photo for AI generation. Fallback: product featured image.',
			],
		];

		foreach ( $this->get_attribute_choices() as $key => $label ) {
			$fields[] = [
				'key' => "field_wcaig_base_attr_{$key}",
				'label' => "Base {$label} Value",
				'name' => "wcaig_base_attr_{$key}",
				'type' => 'taxonomy',
				'taxonomy' => "pa_{$key}",
				'field_type' => 'select',
				'allow_null' => 1,
				'return_format' => 'object',
				'save_terms' => 0,
				'load_terms' => 0,
				'instructions' => "What the base image currently shows for {$label}.",
			];
		}

		acf_add_local_field_group( [
			'key' => 'group_wcaig_product',
			'title' => 'AI Image Gen Settings',
			'fields' => $fields,
			'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'product' ] ] ],
			'position' => 'side',
		] );
	}

	private function register_term_fields(): void {
		$locations = [];
		foreach ( $this->get_pa_taxonomies() as $taxonomy ) {
			$locations[] = [ [ 'param' => 'taxonomy', 'operator' => '==', 'value' => $taxonomy ] ];
		}

		if ( empty( $locations ) ) {
			return;
		}

		acf_add_local_field_group( [
			'key' => 'group_wcaig_term',
			'title' => 'AI Image Gen Term Settings',
			'fields' => [
				[ 'key' => 'field_wcaig_term_color_hex', 'label' => 'Color HEX', 'name' => 'wcaig_term_color_hex', 'type' => 'color_picker' ],
				[ 'key' => 'field_wcaig_term_color_rgb', 'label' => 'Color RGB', 'name' => 'wcaig_term_color_rgb', 'type' => 'text', 'instructions' => 'e.g., RGB 0, 184, 217' ],
				[ 'key' => 'field_wcaig_term_description', 'label' => 'Description', 'name' => 'wcaig_term_description', 'type' => 'text', 'instructions' => 'e.g., bright turquoise cyan' ],
				[
					'key'           => 'field_wcaig_term_ref_image',
					'label'         => 'Reference Image',
					'name'          => 'wcaig_term_ref_image',
					'type'          => 'image',
					'return_format' => 'array',
					'preview_size'  => 'thumbnail',
					'instructions'  => 'Upload a swatch photo of the color or pattern. When set, the algorithm will derive the color/pattern from this image instead of HEX/RGB/description.',
				],
				[
					'key'           => 'field_wcaig_term_ref_type',
					'label'         => 'Reference Image Type',
					'name'          => 'wcaig_term_ref_type',
					'type'          => 'select',
					'choices'       => [
						'color'   => 'Color',
						'pattern' => 'Pattern',
					],
					'default_value' => 'color',
					'allow_null'    => 0,
					'return_format' => 'value',
					'conditional_logic' => [
						[
							[
								'field'    => 'field_wcaig_term_ref_image',
								'operator' => '!=empty',
							],
						],
					],
					'instructions'  => 'Is the reference image a solid color swatch or a repeating pattern/texture?',
				],
			],
			'location' => $locations,
		] );
	}

	// ──────────────────────────────────────────────
	// Attachment fields (webp only — approval workflow)
	// ──────────────────────────────────────────────

	private function register_attachment_fields(): void {
		acf_add_local_field_group( [
			'key'      => 'group_wcaig_attachment',
			'title'    => 'AI Image Gen',
			'fields'   => [
				[
					'key'           => 'field_wcaig_att_status',
					'label'         => 'Status',
					'name'          => 'wcaig_status',
					'type'          => 'select',
					'choices'       => [
						'pending'   => 'Pending',
						'published' => 'Published',
					],
					'default_value' => 'pending',
					'return_format' => 'value',
					'allow_null'    => 0,
					'instructions'  => 'Pending = hidden on frontend. Published = visible to customers.',
				],
			],
			'location' => [
				[
					[
						'param'    => 'attachment',
						'operator' => '==',
						'value'    => 'image/webp',
					],
				],
			],
			'position' => 'normal',
			'style'    => 'default',
		] );
	}

	// ──────────────────────────────────────────────
	// AJAX handlers
	// ──────────────────────────────────────────────

	public function ajax_run_worker(): void {
		$this->verify_admin_ajax();
		WCAIG_Worker::instance()->run();
		wp_send_json_success( [ 'message' => 'Worker run completed.' ] );
	}

	public function ajax_force_run_worker(): void {
		$this->verify_admin_ajax();
		WCAIG_Worker::instance()->force_run();
		wp_send_json_success( [ 'message' => 'Worker force run completed (lock cleared).' ] );
	}

	public function ajax_run_gc(): void {
		$this->verify_admin_ajax();
		WCAIG_Garbage_Collector::instance()->run();
		wp_send_json_success( [ 'message' => 'GC run completed.' ] );
	}

	public function ajax_purge_all(): void {
		$this->verify_admin_ajax();

		WCAIG_Queue::instance()->purge_all();

		global $wpdb;
		$ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wcaig_hash'" );
		foreach ( $ids as $aid ) {
			wp_delete_attachment( (int) $aid, true );
		}

		WCAIG_Logger::instance()->info( 'Purge all: deleted all generated images and queue entries.' );
		wp_send_json_success( [ 'message' => 'All generated images purged.' ] );
	}

	public function ajax_regenerate(): void {
		$this->verify_admin_ajax();

		$hash = sanitize_text_field( $_POST['hash'] ?? '' );
		if ( empty( $hash ) ) {
			wp_send_json_error( [ 'message' => 'Missing hash.' ] );
		}

		// Delete existing attachment(s).
		$attachments = get_posts( [
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'meta_key' => '_wcaig_hash',
			'meta_value' => $hash,
			'posts_per_page' => -1,
		] );
		foreach ( $attachments as $att ) {
			wp_delete_attachment( $att->ID, true );
		}

		WCAIG_Queue::instance()->delete( $hash );

		WCAIG_Logger::instance()->info( "Regenerate: cleared hash {$hash}." );
		wp_send_json_success( [ 'message' => 'Variation cleared for regeneration.' ] );
	}

	// ──────────────────────────────────────────────
	// Private helpers
	// ──────────────────────────────────────────────

	private function verify_admin_ajax(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized', 403 );
		}
		check_ajax_referer( 'wcaig_admin_nonce', '_wpnonce' );
	}

	private function get_attribute_choices(): array {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return [];
		}
		$choices = [];
		foreach ( wc_get_attribute_taxonomies() as $attr ) {
			$choices[ $attr->attribute_name ] = $attr->attribute_label ?: ucfirst( $attr->attribute_name );
		}
		return $choices;
	}

	private function get_pa_taxonomies(): array {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return [];
		}
		return array_map( fn( $a ) => 'pa_' . $a->attribute_name, wc_get_attribute_taxonomies() );
	}
}
