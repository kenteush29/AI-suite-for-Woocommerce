<?php
defined( 'ABSPATH' ) || exit;

/**
 * Products Gallery — a "Gallery" view mode added to the STANDARD WooCommerce
 * products list (Products → All Products), next to the normal list.
 *
 * It does not add a separate page: on the native products screen it injects a
 * "List / Gallery" toggle. In Gallery mode the standard table is hidden and the
 * exact same products (same search, filters, sorting and pagination) are shown
 * as a storefront-like image grid — click an image to zoom, and, for variable
 * products, a button loads the variation images in a popup on demand.
 *
 * Built to stay light: the grid is rendered from the list query that already
 * ran (no extra product query), grid images use the small thumbnail size with
 * native lazy-loading, full images load only on click, and variation images are
 * fetched by AJAX only when their button is clicked.
 */
final class DZE_Gallery {

	private const NONCE = 'dze_gallery';

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
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_footer-edit.php', [ $this, 'render_inline_gallery' ] );
		add_action( 'restrict_manage_posts', [ $this, 'list_filters' ] );
		add_action( 'pre_get_posts',         [ $this, 'apply_filters' ] );
		add_action( 'wp_ajax_dze_gallery_variations', [ $this, 'ajax_variations' ] );
	}

	/** Only on the Products list screen. */
	private function is_products_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'edit-product' === $screen->id;
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'edit.php' !== $hook || ! $this->is_products_screen() ) {
			return;
		}
		wp_enqueue_script( 'dze-gallery', DZE_URL . 'admin/js/gallery.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-gallery', 'dzeGallery', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				'loading'    => __( 'Loading…', 'dazont-ecom' ),
				'none'       => __( 'No variation images.', 'dazont-ecom' ),
				'error'      => __( 'Could not load variations.', 'dazont-ecom' ),
				'variations' => __( 'Variations', 'dazont-ecom' ),
				'list'       => __( 'List', 'dazont-ecom' ),
				'gallery'    => __( 'Gallery', 'dazont-ecom' ),
			],
		] );
	}

	/** Renders the (hidden) gallery grid built from the current list query. */
	public function render_inline_gallery(): void {
		if ( ! $this->is_products_screen() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$wp_query = $GLOBALS['wp_query'] ?? null;
		$posts    = ( $wp_query instanceof WP_Query ) ? $wp_query->posts : [];
		?>
		<div id="dze-gal-view" class="dze-gal" style="display:none;">
			<?php
			if ( empty( $posts ) ) {
				echo '<p>' . esc_html__( 'No products on this page.', 'dazont-ecom' ) . '</p>';
			}
			foreach ( $posts as $post ) {
				$product = wc_get_product( $post );
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
				$img_id    = (int) $product->get_image_id();
				$thumb     = $img_id ? wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src( 'woocommerce_thumbnail' );
				$full      = $img_id ? wp_get_attachment_image_url( $img_id, 'full' ) : $thumb;
				$is_var    = $product->is_type( 'variable' );
				$var_count = $is_var ? count( $product->get_children() ) : 0;
				$edit_link = get_edit_post_link( $product->get_id() );
				?>
				<div class="dze-gal__card">
					<div class="dze-thumb-wrap">
						<img class="dze-thumb dze-gal__img" src="<?php echo esc_url( $thumb ); ?>" data-full="<?php echo esc_url( $full ); ?>" alt="" loading="lazy" />
					</div>
					<div class="dze-gal__name"><?php echo esc_html( $product->get_name() ); ?></div>
					<div class="dze-gal__meta">
						<span class="dze-gal__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
						<span class="dze-gal__id">#<?php echo (int) $product->get_id(); ?></span>
					</div>
					<div class="dze-gal__actions">
						<?php if ( $edit_link ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $edit_link ); ?>"><?php esc_html_e( 'Edit', 'dazont-ecom' ); ?></a>
						<?php endif; ?>
						<?php if ( $is_var && $var_count > 0 ) : ?>
							<button type="button" class="button button-small dze-gal__vars" data-product="<?php echo (int) $product->get_id(); ?>">
								<?php
								/* translators: %d: number of variations */
								echo esc_html( sprintf( __( 'Variations (%d)', 'dazont-ecom' ), $var_count ) );
								?>
							</button>
						<?php endif; ?>
					</div>
				</div>
				<?php
			}
			?>
		</div>

		<div class="dze-gal-modal" id="dze-gal-modal" style="display:none;"><div class="dze-gal-modal__inner"></div></div>

		<style>
			#dze-gal-view{max-width:100%;}
			.dze-gal{display:grid;gap:18px;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));margin:12px 0;}
			.dze-gal__card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:10px;display:flex;flex-direction:column;gap:6px;}
			.dze-gal .dze-thumb-wrap{aspect-ratio:1/1;overflow:hidden;border-radius:8px;background:#f6f7f7;display:flex;align-items:center;justify-content:center;}
			.dze-gal__img{width:100%;height:100%;object-fit:cover;cursor:zoom-in;}
			.dze-gal__name{font-weight:600;font-size:13px;line-height:1.3;}
			.dze-gal__meta{display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#555;}
			.dze-gal__actions{display:flex;gap:6px;margin-top:auto;flex-wrap:wrap;}
			.dze-view-toggle{margin-left:8px;}
			.dze-view-toggle .button.active{background:#2271b1;color:#fff;border-color:#2271b1;}
			.dze-lightbox,.dze-gal-modal{position:fixed;inset:0;background:rgba(0,0,0,.8);display:flex;align-items:center;justify-content:center;z-index:100000;padding:24px;}
			.dze-lightbox img{max-width:92vw;max-height:92vh;border-radius:6px;}
			.dze-gal-modal__inner{background:#fff;border-radius:10px;width:min(1100px,94vw);max-height:88vh;overflow:auto;padding:18px 22px;}
			.dze-gal-vargrid{display:grid;gap:16px;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));margin-top:10px;}
			.dze-gal-vargrid figure{margin:0;text-align:center;}
			.dze-gal-vargrid img{width:100%;height:auto;border-radius:6px;cursor:zoom-in;}
			.dze-gal-vargrid figcaption{font-size:12px;color:#555;margin-top:6px;line-height:1.3;word-break:break-word;}
			body.dze-gallery-on .wp-list-table{display:none;}
		</style>
		<?php
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
				$img_id = (int) get_post_thumbnail_id( $product_id ); // fall back to the parent image.
			}
			$out[] = [
				// Full, human-readable variation title (attribute names + values).
				'title' => wp_strip_all_tags( wc_get_formatted_variation( $variation, true, true, false ) ),
				'thumb' => $img_id ? ( wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) ?: wp_get_attachment_image_url( $img_id, 'thumbnail' ) ) : '',
				'full'  => $img_id ? wp_get_attachment_image_url( $img_id, 'full' ) : '',
			];
		}
		// List variations alphabetically by their full title.
		usort( $out, static fn( $a, $b ) => strcasecmp( $a['title'], $b['title'] ) );

		wp_send_json_success( [ 'images' => $out ] );
	}

	/**
	 * Adds sort + variation-attribute filters to the native products list
	 * toolbar. They post through the standard "Filter" button, so they apply to
	 * both the table and the Gallery view (which mirrors the same query).
	 */
	public function list_filters( string $post_type ): void {
		if ( 'product' !== $post_type || ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filtering via GET.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$order   = ( isset( $_GET['order'] ) && 'asc' === strtolower( (string) $_GET['order'] ) ) ? 'asc' : 'desc';
		$sorts   = [
			'date_desc'  => __( 'Newest added', 'dazont-ecom' ),
			'date_asc'   => __( 'Oldest added', 'dazont-ecom' ),
			'title_asc'  => __( 'Title A → Z', 'dazont-ecom' ),
			'title_desc' => __( 'Title Z → A', 'dazont-ecom' ),
		];
		$current = ( $orderby ? $orderby : 'date' ) . '_' . $order;
		echo '<select name="dze_sort">';
		echo '<option value="">' . esc_html__( 'Sort by…', 'dazont-ecom' ) . '</option>';
		foreach ( $sorts as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $current, $key, false ), esc_html( $label ) );
		}
		echo '</select> ';

		foreach ( wc_get_attribute_taxonomies() as $attr ) {
			$tax = wc_attribute_taxonomy_name( $attr->attribute_name );
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => true ] );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$cur = isset( $_GET['dze_attr'][ $tax ] ) ? sanitize_text_field( wp_unslash( $_GET['dze_attr'][ $tax ] ) ) : '';
			echo '<select name="dze_attr[' . esc_attr( $tax ) . ']">';
			printf( '<option value="">%s</option>', esc_html( sprintf( /* translators: %s: attribute name */ __( '%s: any', 'dazont-ecom' ), $attr->attribute_label ) ) );
			foreach ( $terms as $t ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $t->slug ), selected( $cur, $t->slug, false ), esc_html( $t->name ) );
			}
			echo '</select> ';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/** Applies the sort + attribute filters to the products list query. */
	public function apply_filters( $query ): void {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}
		if ( 'product' !== $query->get( 'post_type' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filtering via GET.
		if ( ! empty( $_GET['dze_sort'] ) ) {
			switch ( sanitize_key( wp_unslash( $_GET['dze_sort'] ) ) ) {
				case 'title_asc':  $query->set( 'orderby', 'title' ); $query->set( 'order', 'ASC' ); break;
				case 'title_desc': $query->set( 'orderby', 'title' ); $query->set( 'order', 'DESC' ); break;
				case 'date_asc':   $query->set( 'orderby', 'date' );  $query->set( 'order', 'ASC' ); break;
				case 'date_desc':  $query->set( 'orderby', 'date' );  $query->set( 'order', 'DESC' ); break;
			}
		}
		if ( ! empty( $_GET['dze_attr'] ) && is_array( $_GET['dze_attr'] ) ) {
			$tax_query = (array) $query->get( 'tax_query' );
			foreach ( wp_unslash( $_GET['dze_attr'] ) as $tax => $slug ) {
				$tax  = sanitize_key( $tax );
				$slug = sanitize_text_field( $slug );
				if ( $slug !== '' && taxonomy_exists( $tax ) ) {
					$tax_query[] = [ 'taxonomy' => $tax, 'field' => 'slug', 'terms' => $slug ];
				}
			}
			if ( count( $tax_query ) ) {
				$query->set( 'tax_query', $tax_query );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
