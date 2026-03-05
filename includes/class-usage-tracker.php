<?php
/**
 * Usage Tracker — logs every successful API call, provides stats.
 */

defined( 'ABSPATH' ) || exit;

class WCAIG_Usage_Tracker {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function table(): string {
		return WC_AI_Image_Gen::usage_table();
	}

	/* ------------------------------------------------------------------
	 *  Log
	 * ----------------------------------------------------------------*/

	/**
	 * Record a successful generation.
	 */
	public function log_usage( int $product_id, string $task_id, string $model ): void {
		global $wpdb;

		$wpdb->insert(
			$this->table(),
			array(
				'product_id' => $product_id,
				'task_id'    => $task_id,
				'model'      => $model,
			),
			array( '%d', '%s', '%s' )
		);

		WC_AI_Image_Gen::log( "Usage logged: product={$product_id}, task={$task_id}, model={$model}." );
	}

	/* ------------------------------------------------------------------
	 *  Stats
	 * ----------------------------------------------------------------*/

	/**
	 * Get summary statistics for the admin settings page.
	 *
	 * @return array { total: int, this_month: int, top_products: array }
	 */
	public function get_stats(): array {
		global $wpdb;

		$table = $this->table();

		// Guard: table may not exist yet.
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SHOW TABLES LIKE %s",
			$table
		) );

		if ( ! $table_exists ) {
			return array(
				'total'        => 0,
				'this_month'   => 0,
				'top_products' => array(),
			);
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$first_of_month = gmdate( 'Y-m-01 00:00:00' );
		$this_month     = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
			$first_of_month
		) );

		$top_products = $wpdb->get_results(
			"SELECT product_id, COUNT(*) AS cnt FROM {$table} GROUP BY product_id ORDER BY cnt DESC LIMIT 10"
		);

		return array(
			'total'        => $total,
			'this_month'   => $this_month,
			'top_products' => $top_products ?: array(),
		);
	}
}
