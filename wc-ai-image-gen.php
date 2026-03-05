<?php
/**
 * Plugin Name: WC AI Image Generator
 * Description: Generate AI-modified product images on WooCommerce variable product pages using PIAPI.
 * Version: 1.0.0
 * Author: Pimotki
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: wc-ai-image-gen
 *
 * WC requires at least: 8.0
 * WC tested up to: 9.6
 */

defined( 'ABSPATH' ) || exit;

define( 'WCAIG_VERSION', '1.0.0' );
define( 'WCAIG_PLUGIN_FILE', __FILE__ );
define( 'WCAIG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCAIG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCAIG_INCLUDES_DIR', WCAIG_PLUGIN_DIR . 'includes/' );
define( 'WCAIG_ASSETS_URL', WCAIG_PLUGIN_URL . 'assets/' );

/**
 * Main plugin class — singleton.
 */
final class WC_AI_Image_Gen {

	/** @var self|null */
	private static $instance = null;

	/** @var string Custom cache table name (without prefix). */
	const CACHE_TABLE = 'wcaig_cache';

	/** @var string Usage log table name (without prefix). */
	const USAGE_TABLE = 'wcaig_usage_log';

	/**
	 * Get singleton instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — wire lifecycle hooks, load on plugins_loaded.
	 */
	private function __construct() {
		register_activation_hook( WCAIG_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( WCAIG_PLUGIN_FILE, array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'boot' ), 20 );
	}

	/* ------------------------------------------------------------------
	 *  Boot
	 * ----------------------------------------------------------------*/

	/**
	 * Check dependencies, then load sub-modules.
	 */
	public function boot(): void {
		if ( ! $this->check_dependencies() ) {
			return;
		}

		$this->load_includes();
		$this->init_modules();
	}

	/**
	 * Verify WooCommerce & ACF PRO are active.
	 */
	private function check_dependencies(): bool {
		$missing = array();

		if ( ! class_exists( 'WooCommerce' ) ) {
			$missing[] = 'WooCommerce';
		}

		if ( ! class_exists( 'ACF' ) || ! defined( 'ACF_PRO' ) ) {
			$missing[] = 'Advanced Custom Fields PRO';
		}

		if ( ! empty( $missing ) ) {
			add_action( 'admin_notices', function () use ( $missing ) {
				printf(
					'<div class="notice notice-error"><p><strong>WC AI Image Generator</strong> requires: %s.</p></div>',
					esc_html( implode( ', ', $missing ) )
				);
			} );
			return false;
		}

		return true;
	}

	/**
	 * Require class files.
	 */
	private function load_includes(): void {
		$files = array(
			'class-image-cache.php',
			'class-admin-settings.php',
			'class-acf-fields.php',
			'class-prompt-builder.php',
			'class-api-client.php',
			'class-usage-tracker.php',
			'class-ajax-handler.php',
			'class-admin-metabox.php',
		);

		foreach ( $files as $file ) {
			require_once WCAIG_INCLUDES_DIR . $file;
		}
	}

	/**
	 * Instantiate sub-modules.
	 */
	private function init_modules(): void {
		WCAIG_Image_Cache::instance();
		WCAIG_Admin_Settings::instance();
		WCAIG_ACF_Fields::instance();
		WCAIG_Usage_Tracker::instance();
		WCAIG_Ajax_Handler::instance();
		WCAIG_Admin_Metabox::instance();

		// Front-end assets only on single product pages.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_frontend' ) );
	}

	/* ------------------------------------------------------------------
	 *  Front-end Assets
	 * ----------------------------------------------------------------*/

	/**
	 * Conditionally enqueue JS / CSS on single product pages where AI gen is enabled.
	 */
	public function maybe_enqueue_frontend(): void {
		if ( ! is_product() ) {
			return;
		}

		global $post;
		if ( ! $post || ! get_field( 'wcaig_enabled', $post->ID ) ) {
			return;
		}

		$product = wc_get_product( $post->ID );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return;
		}

		// SweetAlert2 (CDN).
		wp_enqueue_script(
			'sweetalert2',
			'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js',
			array(),
			'11',
			true
		);
		wp_enqueue_style(
			'sweetalert2',
			'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css',
			array(),
			'11'
		);

		// Plugin assets.
		wp_enqueue_style(
			'wcaig-frontend',
			WCAIG_ASSETS_URL . 'css/frontend.css',
			array(),
			WCAIG_VERSION
		);

		wp_enqueue_script(
			'wcaig-frontend',
			WCAIG_ASSETS_URL . 'js/frontend.js',
			array( 'jquery', 'sweetalert2' ),
			WCAIG_VERSION,
			true
		);

		// Collect reference thumb URLs for the orbit loader.
		$ref_thumbs = $this->collect_ref_thumbs( $post->ID );

		wp_localize_script( 'wcaig-frontend', 'wcaig', array(
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'wcaig_nonce' ),
			'product_id' => $post->ID,
			'ref_thumbs' => $ref_thumbs,
		) );
	}

	/**
	 * Gather all possible reference image thumbnails for the orbit loader.
	 */
	private function collect_ref_thumbs( int $product_id ): array {
		$thumbs = array();

		// Base product image.
		$thumb_id = get_post_thumbnail_id( $product_id );
		if ( $thumb_id ) {
			$url = wp_get_attachment_image_url( $thumb_id, 'thumbnail' );
			if ( $url ) {
				$thumbs[] = $url;
			}
		}

		// Prompt-config reference images.
		$rows = get_field( 'wcaig_prompt_config', $product_id );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! empty( $row['reference_image'] ) ) {
					$url = wp_get_attachment_image_url( $row['reference_image'], 'thumbnail' );
					if ( $url ) {
						$thumbs[] = $url;
					}
				}
			}
		}

		// Attribute term reference images — we grab all attribute terms for this product.
		$product = wc_get_product( $product_id );
		if ( $product && $product->is_type( 'variable' ) ) {
			$attrs = $product->get_variation_attributes();
			foreach ( $attrs as $taxonomy => $values ) {
				$tax_name = wc_attribute_taxonomy_name( '' ) ? $taxonomy : $taxonomy;
				foreach ( $values as $slug ) {
					$term = get_term_by( 'slug', $slug, $taxonomy );
					if ( $term ) {
						$img_id = get_field( 'wcaig_attr_ref_image', $term );
						if ( $img_id ) {
							$url = wp_get_attachment_image_url( $img_id, 'thumbnail' );
							if ( $url ) {
								$thumbs[] = $url;
							}
						}
					}
				}
			}
		}

		return array_values( array_unique( $thumbs ) );
	}

	/* ------------------------------------------------------------------
	 *  Activation / Deactivation
	 * ----------------------------------------------------------------*/

	/**
	 * Plugin activation: create DB tables, schedule cron.
	 */
	public function activate(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Cache table.
		$cache_table = $wpdb->prefix . self::CACHE_TABLE;
		$sql_cache   = "CREATE TABLE {$cache_table} (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id      BIGINT UNSIGNED NOT NULL,
			base_image_id   BIGINT UNSIGNED NOT NULL,
			attributes_hash VARCHAR(32) NOT NULL,
			attachment_id   BIGINT UNSIGNED NOT NULL,
			prompt_text     TEXT,
			created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_hash (product_id, attributes_hash),
			KEY idx_product (product_id),
			KEY idx_attachment (attachment_id)
		) {$charset_collate};";
		dbDelta( $sql_cache );

		// Usage log table.
		$usage_table = $wpdb->prefix . self::USAGE_TABLE;
		$sql_usage   = "CREATE TABLE {$usage_table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id  BIGINT UNSIGNED NOT NULL,
			task_id     VARCHAR(255),
			model       VARCHAR(100),
			created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_product (product_id),
			KEY idx_date (created_at)
		) {$charset_collate};";
		dbDelta( $sql_usage );

		// Schedule weekly orphan cleanup.
		if ( ! wp_next_scheduled( 'wcaig_orphan_cleanup' ) ) {
			wp_schedule_event( time(), 'weekly', 'wcaig_orphan_cleanup' );
		}

		// Store DB version for future migrations.
		update_option( 'wcaig_db_version', WCAIG_VERSION );
	}

	/**
	 * Plugin deactivation: unschedule cron.
	 */
	public function deactivate(): void {
		$timestamp = wp_next_scheduled( 'wcaig_orphan_cleanup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wcaig_orphan_cleanup' );
		}
	}

	/* ------------------------------------------------------------------
	 *  Helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Return full cache table name.
	 */
	public static function cache_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::CACHE_TABLE;
	}

	/**
	 * Return full usage table name.
	 */
	public static function usage_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::USAGE_TABLE;
	}

	/**
	 * Simple debug logger.
	 */
	public static function log( string $message ): void {
		if ( 'yes' !== get_option( 'wcaig_enable_logging', 'no' ) ) {
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug( $message, array( 'source' => 'wcaig' ) );
		} else {
			error_log( '[WCAIG] ' . $message );
		}
	}
}

// Kick off.
WC_AI_Image_Gen::instance();
