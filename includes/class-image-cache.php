<?php
/**
 * Image Cache — custom DB table CRUD, self-healing, orphan cleanup.
 */

defined( 'ABSPATH' ) || exit;

class WCAIG_Image_Cache {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wcaig_orphan_cleanup', array( $this, 'cleanup_orphans' ) );
	}

	/* ------------------------------------------------------------------
	 *  Table helpers
	 * ----------------------------------------------------------------*/

	private function table(): string {
		return WC_AI_Image_Gen::cache_table();
	}

	/* ------------------------------------------------------------------
	 *  Hash
	 * ----------------------------------------------------------------*/

	/**
	 * Compute deterministic cache hash.
	 *
	 * @param int   $product_id
	 * @param int   $base_image_id
	 * @param array $attributes  e.g. ['attribute_pa_color' => 'blekitny', …]
	 * @return string  32-char MD5 hex string.
	 */
	public function compute_hash( int $product_id, int $base_image_id, array $attributes ): string {
		// Strip "attribute_" prefix, lowercase, sort.
		$normalized = array();
		foreach ( $attributes as $key => $value ) {
			$key   = strtolower( preg_replace( '/^attribute_/', '', $key ) );
			$value = strtolower( $value );
			$normalized[ $key ] = $value;
		}
		ksort( $normalized );

		$pairs = array();
		foreach ( $normalized as $k => $v ) {
			$pairs[] = "{$k}={$v}";
		}

		$raw = $product_id . ':' . $base_image_id . ':' . implode( '|', $pairs );

		return md5( $raw );
	}

	/* ------------------------------------------------------------------
	 *  Find (self-healing)
	 * ----------------------------------------------------------------*/

	/**
	 * Look up a cached image. Returns attachment ID on hit, false on miss.
	 * Automatically removes rows whose attachment no longer exists.
	 *
	 * @param int   $product_id
	 * @param int   $base_image_id
	 * @param array $attributes
	 * @return int|false  Attachment ID or false.
	 */
	public function find( int $product_id, int $base_image_id, array $attributes ) {
		global $wpdb;

		$hash  = $this->compute_hash( $product_id, $base_image_id, $attributes );
		$table = $this->table();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE product_id = %d AND attributes_hash = %s",
			$product_id,
			$hash
		) );

		if ( ! $row ) {
			return false;
		}

		// Self-heal: attachment deleted from Media Library?
		if ( ! wp_get_attachment_url( $row->attachment_id ) ) {
			$wpdb->delete( $table, array( 'id' => $row->id ), array( '%d' ) );
			WC_AI_Image_Gen::log( "Self-heal: removed stale cache row {$row->id} (attachment {$row->attachment_id} missing)." );
			return false;
		}

		return (int) $row->attachment_id;
	}

	/* ------------------------------------------------------------------
	 *  Store
	 * ----------------------------------------------------------------*/

	/**
	 * Insert or update a cache entry.
	 *
	 * @param int    $product_id
	 * @param int    $base_image_id
	 * @param array  $attributes
	 * @param int    $attachment_id
	 * @param string $prompt_text
	 */
	public function store( int $product_id, int $base_image_id, array $attributes, int $attachment_id, string $prompt_text ): void {
		global $wpdb;

		$hash = $this->compute_hash( $product_id, $base_image_id, $attributes );

		$wpdb->replace(
			$this->table(),
			array(
				'product_id'      => $product_id,
				'base_image_id'   => $base_image_id,
				'attributes_hash' => $hash,
				'attachment_id'   => $attachment_id,
				'prompt_text'     => $prompt_text,
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);

		WC_AI_Image_Gen::log( "Stored cache: product={$product_id}, hash={$hash}, attachment={$attachment_id}." );
	}

	/* ------------------------------------------------------------------
	 *  Delete helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Delete a single cache row by ID and optionally its attachment.
	 */
	public function delete_row( int $row_id, bool $delete_attachment = true ): void {
		global $wpdb;

		$table = $this->table();

		if ( $delete_attachment ) {
			$attachment_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT attachment_id FROM {$table} WHERE id = %d",
				$row_id
			) );
			if ( $attachment_id ) {
				wp_delete_attachment( (int) $attachment_id, true );
			}
		}

		$wpdb->delete( $table, array( 'id' => $row_id ), array( '%d' ) );
	}

	/**
	 * Flush all cache rows for a product, deleting attachments.
	 */
	public function flush_product( int $product_id ): int {
		global $wpdb;

		$table = $this->table();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, attachment_id FROM {$table} WHERE product_id = %d",
			$product_id
		) );

		$count = 0;
		foreach ( $rows as $row ) {
			wp_delete_attachment( (int) $row->attachment_id, true );
			$wpdb->delete( $table, array( 'id' => $row->id ), array( '%d' ) );
			$count++;
		}

		WC_AI_Image_Gen::log( "Flushed {$count} cache entries for product {$product_id}." );
		return $count;
	}

	/**
	 * Get all cache rows for a product (used by metabox).
	 */
	public function get_product_rows( int $product_id ): array {
		global $wpdb;

		$table = $this->table();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE product_id = %d ORDER BY created_at DESC",
			$product_id
		) );

		return $rows ?: array();
	}

	/* ------------------------------------------------------------------
	 *  Orphan Cleanup (cron)
	 * ----------------------------------------------------------------*/

	/**
	 * Remove rows whose attachment no longer exists.
	 */
	public function cleanup_orphans(): void {
		global $wpdb;

		$table = $this->table();
		$rows  = $wpdb->get_results( "SELECT id, attachment_id FROM {$table}" );

		$removed = 0;
		foreach ( $rows as $row ) {
			if ( ! wp_get_attachment_url( $row->attachment_id ) ) {
				$wpdb->delete( $table, array( 'id' => $row->id ), array( '%d' ) );
				$removed++;
			}
		}

		if ( $removed > 0 ) {
			WC_AI_Image_Gen::log( "Orphan cleanup: removed {$removed} stale rows." );
		}
	}
}
