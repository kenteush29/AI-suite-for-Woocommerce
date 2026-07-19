<?php
defined( 'ABSPATH' ) || exit;

/**
 * Product Gallery (admin).
 *
 * An alternative, visual way to browse the WooCommerce catalogue from the admin
 * — like scrolling the storefront: big product images in a grid, click to zoom
 * (same lightbox idea as the Restock module), and, for variable products, a
 * button that loads the variation images in a popup on demand.
 *
 * Built to stay light on big catalogues:
 *   - the list is paginated (a bounded WP_Query, no meta joins), so a 10k-product
 *     shop only ever loads one page at a time;
 *   - grid images use the small "thumbnail" size and native lazy-loading; the
 *     full-size image is fetched only when a thumbnail is clicked to zoom;
 *   - variation images are loaded via AJAX only when their product's button is
 *     clicked — nothing variation-related is queried up front.
 */
final class DZE_Gallery {

	public const MENU_SLUG = 'dazont-ecom-gallery';
	private const NONCE     = 'dze_gallery';
	private const PER_PAGE  = 24;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_dze_gallery_variations', [ $this, 'ajax_variations' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			__( 'Products gallery', 'dazont-ecom' ),
			__( 'Products gallery', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::MENU_SLUG ) === false ) {
			return;
		}
		wp_enqueue_script( 'dze-gallery', DZE_URL . 'admin/js/gallery.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-gallery', 'dzeGallery', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				'loading' => __( 'Loading…', 'dazont-ecom' ),
				'none'    => __( 'No variation images.', 'dazont-ecom' ),
				'error'   => __( 'Could not load variations.', 'dazont-ecom' ),
				'variations' => __( 'Variations', 'dazont-ecom' ),
			],
		] );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}

		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$cat    = isset( $_GET['product_cat'] ) ? sanitize_text_field( wp_unslash( $_GET['product_cat'] ) ) : '';

		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];
		if ( $search !== '' ) {
			$args['s'] = $search;
		}
		if ( $cat !== '' ) {
			$args['tax_query'] = [ [ 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $cat ] ];
		}
		$query      = new WP_Query( $args );
		$categories = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 300 ] );
		$base_url   = admin_url( 'admin.php' );

		require DZE_DIR . 'admin/views/gallery-page.php';
	}

	/** AJAX: variation images for one product (thumb + full URLs, per variation). */
	public function ajax_variations(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$product_id = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			wp_send_json_error( [ 'message' => __( 'Not a variable product.', 'dazont-ecom' ) ] );
		}

		$out = [];
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof \WC_Product ) {
				continue;
			}
			$img_id = (int) $variation->get_image_id();
			if ( ! $img_id ) {
				continue;
			}
			$out[] = [
				'title' => wc_get_formatted_variation( $variation, true, false, true ),
				'thumb' => wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) ?: wp_get_attachment_image_url( $img_id, 'thumbnail' ),
				'full'  => wp_get_attachment_image_url( $img_id, 'full' ),
			];
		}
		wp_send_json_success( [ 'images' => $out ] );
	}
}
