<?php
/**
 * Admin Metabox — "AI Generated Images" on product edit screen.
 *
 * Native WordPress metabox (NOT ACF) — immune to ACF save conflicts.
 */

defined( 'ABSPATH' ) || exit;

class WCAIG_Admin_Metabox {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'wp_ajax_wcaig_delete_cache_row', array( $this, 'ajax_delete_row' ) );
		add_action( 'wp_ajax_wcaig_flush_product_cache', array( $this, 'ajax_flush_product' ) );
	}

	/* ------------------------------------------------------------------
	 *  Metabox Registration
	 * ----------------------------------------------------------------*/

	public function register_metabox(): void {
		add_meta_box(
			'wcaig_cache_metabox',
			__( 'AI Generated Images', 'wc-ai-image-gen' ),
			array( $this, 'render_metabox' ),
			'product',
			'normal',
			'default'
		);
	}

	/* ------------------------------------------------------------------
	 *  Render
	 * ----------------------------------------------------------------*/

	public function render_metabox( WP_Post $post ): void {
		$cache = WCAIG_Image_Cache::instance();
		$rows  = $cache->get_product_rows( $post->ID );

		wp_nonce_field( 'wcaig_metabox_nonce', 'wcaig_metabox_nonce_field' );
		?>
		<style>
			.wcaig-cache-table { width: 100%; border-collapse: collapse; }
			.wcaig-cache-table th,
			.wcaig-cache-table td { padding: 8px 10px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: middle; }
			.wcaig-cache-table th { background: #f9f9f9; }
			.wcaig-cache-thumb { width: 60px; height: 60px; object-fit: cover; border-radius: 4px; }
			.wcaig-flush-btn { margin-top: 10px; }
		</style>

		<?php if ( empty( $rows ) ) : ?>
			<p><em><?php esc_html_e( 'No cached AI-generated images for this product.', 'wc-ai-image-gen' ); ?></em></p>
		<?php else : ?>
			<table class="wcaig-cache-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Thumbnail', 'wc-ai-image-gen' ); ?></th>
						<th><?php esc_html_e( 'Hash', 'wc-ai-image-gen' ); ?></th>
						<th><?php esc_html_e( 'Created At', 'wc-ai-image-gen' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wc-ai-image-gen' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) :
						$thumb_url = wp_get_attachment_image_url( $row->attachment_id, 'thumbnail' );
					?>
						<tr data-row-id="<?php echo esc_attr( $row->id ); ?>">
							<td>
								<?php if ( $thumb_url ) : ?>
									<img src="<?php echo esc_url( $thumb_url ); ?>" class="wcaig-cache-thumb" alt="">
								<?php else : ?>
									<em><?php esc_html_e( 'Missing', 'wc-ai-image-gen' ); ?></em>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $row->attributes_hash ); ?></code></td>
							<td><?php echo esc_html( $row->created_at ); ?></td>
							<td>
								<button type="button" class="button button-small wcaig-delete-row" data-row-id="<?php echo esc_attr( $row->id ); ?>">
									<?php esc_html_e( 'Delete', 'wc-ai-image-gen' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<button type="button" class="button button-secondary wcaig-flush-btn" id="wcaig-flush-all" data-product-id="<?php echo esc_attr( $post->ID ); ?>">
				<?php esc_html_e( 'Flush All', 'wc-ai-image-gen' ); ?>
			</button>
		<?php endif; ?>

		<script>
		(function($) {
			var nonce = $('#wcaig_metabox_nonce_field').val();

			$('.wcaig-delete-row').on('click', function() {
				var $btn = $(this);
				var rowId = $btn.data('row-id');

				if (!confirm('<?php echo esc_js( __( 'Delete this cached image and its attachment?', 'wc-ai-image-gen' ) ); ?>')) {
					return;
				}

				$btn.prop('disabled', true).text('…');

				$.post(ajaxurl, {
					action: 'wcaig_delete_cache_row',
					nonce: nonce,
					row_id: rowId
				}, function(response) {
					if (response.success) {
						$btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
					} else {
						alert(response.data || 'Error');
						$btn.prop('disabled', false).text('Delete');
					}
				}).fail(function() {
					alert('Request failed.');
					$btn.prop('disabled', false).text('Delete');
				});
			});

			$('#wcaig-flush-all').on('click', function() {
				var $btn = $(this);
				var productId = $btn.data('product-id');

				if (!confirm('<?php echo esc_js( __( 'Delete ALL cached images for this product? This cannot be undone.', 'wc-ai-image-gen' ) ); ?>')) {
					return;
				}

				$btn.prop('disabled', true).text('…');

				$.post(ajaxurl, {
					action: 'wcaig_flush_product_cache',
					nonce: nonce,
					product_id: productId
				}, function(response) {
					if (response.success) {
						$('.wcaig-cache-table').fadeOut(300, function() { $(this).remove(); });
						$btn.replaceWith('<p><em><?php echo esc_js( __( 'All cached images deleted.', 'wc-ai-image-gen' ) ); ?></em></p>');
					} else {
						alert(response.data || 'Error');
						$btn.prop('disabled', false).text('Flush All');
					}
				}).fail(function() {
					alert('Request failed.');
					$btn.prop('disabled', false).text('Flush All');
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	/* ------------------------------------------------------------------
	 *  AJAX Handlers
	 * ----------------------------------------------------------------*/

	public function ajax_delete_row(): void {
		check_ajax_referer( 'wcaig_metabox_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$row_id = absint( $_POST['row_id'] ?? 0 );
		if ( ! $row_id ) {
			wp_send_json_error( 'Invalid row ID.' );
		}

		WCAIG_Image_Cache::instance()->delete_row( $row_id, true );

		wp_send_json_success();
	}

	public function ajax_flush_product(): void {
		check_ajax_referer( 'wcaig_metabox_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );
		if ( ! $product_id ) {
			wp_send_json_error( 'Invalid product ID.' );
		}

		$count = WCAIG_Image_Cache::instance()->flush_product( $product_id );

		wp_send_json_success( array( 'deleted' => $count ) );
	}
}
