<?php
/**
 * Admin Settings — WooCommerce → Settings → AI Image Gen tab.
 */

defined( 'ABSPATH' ) || exit;

class WCAIG_Admin_Settings {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_tab' ), 50 );
		add_action( 'woocommerce_settings_tabs_wcaig', array( $this, 'render_settings' ) );
		add_action( 'woocommerce_update_options_wcaig', array( $this, 'save_settings' ) );
		add_action( 'woocommerce_settings_tabs_wcaig', array( $this, 'render_usage_stats' ), 20 );
	}

	/* ------------------------------------------------------------------
	 *  Tab
	 * ----------------------------------------------------------------*/

	public function add_tab( array $tabs ): array {
		$tabs['wcaig'] = __( 'AI Image Gen', 'wc-ai-image-gen' );
		return $tabs;
	}

	/* ------------------------------------------------------------------
	 *  Settings fields
	 * ----------------------------------------------------------------*/

	private function get_fields(): array {
		$default_preservation = implode( "\n", array(
			'- On the original photo, all leather elements are black and all hardware (metal fittings, buckles, zippers, rivets, etc.) is silver.',
			'- Preserve the original product geometry, proportions, camera angle, lighting conditions, shadows, and overall composition.',
			'- Do not alter the structural form of the object.',
			'- Apply all material and texture changes realistically across visible surfaces with accurate scaling and natural material behavior.',
			'- Texture patterns must remain visually continuous while exhibiting slight natural misalignment at seams, consistent with handcrafted products.',
			'- Ensure physically accurate reflections, correct texture scaling, and natural seam alignment.',
			'- Replace metal hardware and fittings with the specified finish if instructed, ensuring realistic reflections and metallic properties.',
			'- No distortion of proportions.',
		) );

		return array(
			array(
				'title' => __( 'AI Image Generation Settings', 'wc-ai-image-gen' ),
				'type'  => 'title',
				'id'    => 'wcaig_section_start',
			),
			array(
				'title'    => __( 'API Key', 'wc-ai-image-gen' ),
				'desc'     => __( 'PIAPI API key.', 'wc-ai-image-gen' ),
				'id'       => 'wcaig_api_key',
				'type'     => 'password',
				'default'  => '',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Model', 'wc-ai-image-gen' ),
				'desc'     => __( 'PIAPI model identifier.', 'wc-ai-image-gen' ),
				'id'       => 'wcaig_model',
				'type'     => 'text',
				'default'  => 'gemini',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Task Type', 'wc-ai-image-gen' ),
				'desc'     => __( 'PIAPI task type.', 'wc-ai-image-gen' ),
				'id'       => 'wcaig_task_type',
				'type'     => 'text',
				'default'  => 'gemini-2.5-flash-image',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Poll Interval (sec)', 'wc-ai-image-gen' ),
				'desc'     => __( 'Seconds between status polls.', 'wc-ai-image-gen' ),
				'id'       => 'wcaig_poll_interval',
				'type'     => 'number',
				'default'  => 5,
				'custom_attributes' => array( 'min' => 1, 'max' => 60 ),
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Max Poll Attempts', 'wc-ai-image-gen' ),
				'desc'     => __( 'Maximum number of status polls before timeout.', 'wc-ai-image-gen' ),
				'id'       => 'wcaig_max_poll_attempts',
				'type'     => 'number',
				'default'  => 60,
				'custom_attributes' => array( 'min' => 1, 'max' => 300 ),
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Retry Count', 'wc-ai-image-gen' ),
				'desc'     => __( 'Number of retries on transient API failures.', 'wc-ai-image-gen' ),
				'id'       => 'wcaig_retry_count',
				'type'     => 'number',
				'default'  => 2,
				'custom_attributes' => array( 'min' => 0, 'max' => 10 ),
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Preservation Rules', 'wc-ai-image-gen' ),
				'desc'     => __( 'Rules appended to every prompt. Leave blank for defaults.', 'wc-ai-image-gen' ),
				'id'       => 'wcaig_preservation_rules',
				'type'     => 'textarea',
				'default'  => $default_preservation,
				'css'      => 'width:100%;height:200px;',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Enable Debug Logging', 'wc-ai-image-gen' ),
				'desc'     => __( 'Write debug messages to WooCommerce → Status → Logs (wcaig).', 'wc-ai-image-gen' ),
				'id'       => 'wcaig_enable_logging',
				'type'     => 'checkbox',
				'default'  => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wcaig_section_end',
			),
		);
	}

	/* ------------------------------------------------------------------
	 *  Render / Save
	 * ----------------------------------------------------------------*/

	public function render_settings(): void {
		\WC_Admin_Settings::output_fields( $this->get_fields() );
	}

	public function save_settings(): void {
		\WC_Admin_Settings::save_fields( $this->get_fields() );
	}

	/* ------------------------------------------------------------------
	 *  Usage Statistics
	 * ----------------------------------------------------------------*/

	public function render_usage_stats(): void {
		$tracker = WCAIG_Usage_Tracker::instance();
		$stats   = $tracker->get_stats();
		?>
		<h2><?php esc_html_e( 'Usage Statistics', 'wc-ai-image-gen' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Total Generations (all time)', 'wc-ai-image-gen' ); ?></th>
				<td><strong><?php echo esc_html( $stats['total'] ); ?></strong></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Generations This Month', 'wc-ai-image-gen' ); ?></th>
				<td><strong><?php echo esc_html( $stats['this_month'] ); ?></strong></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Top 10 Products', 'wc-ai-image-gen' ); ?></th>
				<td>
					<?php if ( empty( $stats['top_products'] ) ) : ?>
						<em><?php esc_html_e( 'No data yet.', 'wc-ai-image-gen' ); ?></em>
					<?php else : ?>
						<table class="widefat striped" style="max-width:500px;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Product', 'wc-ai-image-gen' ); ?></th>
									<th><?php esc_html_e( 'Generations', 'wc-ai-image-gen' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $stats['top_products'] as $row ) : ?>
									<tr>
										<td>
											<?php
											$title = get_the_title( $row->product_id );
											echo esc_html( $title ?: "#{$row->product_id}" );
											?>
										</td>
										<td><?php echo esc_html( $row->cnt ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}
}
