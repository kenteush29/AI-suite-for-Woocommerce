<?php
defined( 'ABSPATH' ) || exit;

/**
 * Product Explorer.
 *
 * A full-screen, storefront-like admin tool for browsing the catalogue at a
 * glance: big product images with titles, a category rail (image + recursive
 * count) and filters down the left, image zoom and a variations popup. A focus
 * mode hides the WordPress chrome to use the whole screen. Built for the daily
 * job of spotting gaps and deciding what to add next; the SEO gap finder plugs
 * in here later.
 *
 * Products load over AJAX in pages (server-rendered cards) so a large catalogue
 * stays responsive.
 */
final class DZE_Explorer {

	public const MENU_SLUG = 'dazont-ecom-explorer';
	private const NONCE     = 'dze_explorer';
	private const PER_PAGE  = 30;

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
		add_action( 'wp_ajax_dze_explorer_products',   [ $this, 'ajax_products' ] );
		add_action( 'wp_ajax_dze_explorer_variations', [ $this, 'ajax_variations' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			__( 'Product Explorer', 'dazont-ecom' ),
			__( 'Product Explorer', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	private function is_explorer_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && strpos( (string) $screen->id, self::MENU_SLUG ) !== false;
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::MENU_SLUG ) === false ) {
			return;
		}
		// This is a focused, full-screen tool — strip other plugins' admin
		// notices so nothing steals space or attention.
		add_action( 'in_admin_header', static function () {
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
			remove_all_actions( 'user_admin_notices' );
		}, 999 );

		wp_enqueue_style( 'dze-explorer', DZE_URL . 'admin/css/explorer.css', [], DZE_VERSION );
		wp_enqueue_script( 'dze-explorer', DZE_URL . 'admin/js/explorer.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-explorer', 'dzeExplorer', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				'loading'    => __( 'Loading…', 'dazont-ecom' ),
				'loadMore'   => __( 'Load more', 'dazont-ecom' ),
				'noResults'  => __( 'No products match these filters.', 'dazont-ecom' ),
				'none'       => __( 'No variation images.', 'dazont-ecom' ),
				'error'      => __( 'Something went wrong.', 'dazont-ecom' ),
				'variations' => __( 'Variations', 'dazont-ecom' ),
				'focus'      => __( 'Focus mode', 'dazont-ecom' ),
				'exit'       => __( 'Exit focus', 'dazont-ecom' ),
				'all'        => __( 'All products', 'dazont-ecom' ),
				'units'      => __( 'sold', 'dazont-ecom' ),
			],
		] );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$categories = $this->category_tree();
		$attributes = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : [];
		require DZE_DIR . 'admin/views/explorer-page.php';
	}

	// =========================================================================
	// Category rail
	// =========================================================================

	/**
	 * Nested product-category tree with image, recursive product count and
	 * recursive sales (units + revenue) so the rail can be re-ordered by what
	 * actually sells.
	 */
	private function category_tree(): array {
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}
		$by_id    = [];
		$children = [];
		$parent   = [];
		foreach ( $terms as $t ) {
			$by_id[ $t->term_id ]     = $t;
			$children[ $t->parent ][] = $t->term_id;
			$parent[ $t->term_id ]    = (int) $t->parent;
		}
		$sales = $this->category_sales( $parent );

		$rec_count = function ( int $id ) use ( &$rec_count, $children, $by_id ): int {
			$c = (int) ( $by_id[ $id ]->count ?? 0 );
			foreach ( $children[ $id ] ?? [] as $cid ) {
				$c += $rec_count( $cid );
			}
			return $c;
		};
		$build = function ( int $parent_id ) use ( &$build, $children, $by_id, $rec_count, $sales ): array {
			$out = [];
			foreach ( $children[ $parent_id ] ?? [] as $cid ) {
				$t      = $by_id[ $cid ];
				$img_id = (int) get_term_meta( $cid, 'thumbnail_id', true );
				$out[]  = [
					'id'         => $cid,
					'name'       => $t->name,
					'count'      => $rec_count( $cid ),
					'sales_qty'  => (float) ( $sales[ $cid ]['qty'] ?? 0 ),
					'sales_rev'  => (float) ( $sales[ $cid ]['rev'] ?? 0 ),
					'image'      => $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '',
					'children'   => $build( $cid ),
				];
			}
			return $out;
		};
		return $build( 0 );
	}

	/**
	 * Per-category sales rolled up over the whole subtree, from WooCommerce
	 * Analytics' order-product lookup table. Each product's sales are counted
	 * once per ancestor category (no double counting). Cached for a few hours
	 * because this drives ordering, not live figures.
	 *
	 * @param array<int,int> $parent term_id => parent term_id map.
	 * @return array<int,array{qty:float,rev:float}>
	 */
	private function category_sales( array $parent ): array {
		$cached = get_transient( 'dze_x_cat_sales' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}

		// Per-product totals (product_id is the parent product; variations roll up).
		$rows = $wpdb->get_results(
			"SELECT product_id, SUM(product_qty) AS qty, SUM(product_net_revenue) AS rev
			 FROM {$table} GROUP BY product_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			set_transient( 'dze_x_cat_sales', [], 3 * HOUR_IN_SECONDS );
			return [];
		}
		$prod = [];
		foreach ( $rows as $r ) {
			$prod[ (int) $r['product_id'] ] = [ 'qty' => (float) $r['qty'], 'rev' => (float) $r['rev'] ];
		}

		// Product → assigned product_cat term ids.
		$pids         = array_keys( $prod );
		$placeholders = implode( ',', array_fill( 0, count( $pids ), '%d' ) );
		$rel = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tr.object_id, tt.term_id
				 FROM {$wpdb->term_relationships} tr
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 WHERE tt.taxonomy = 'product_cat' AND tr.object_id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from ints.
				$pids
			),
			ARRAY_A
		);
		$prod_terms = [];
		foreach ( (array) $rel as $r ) {
			$prod_terms[ (int) $r['object_id'] ][] = (int) $r['term_id'];
		}

		$ancestors = static function ( int $id ) use ( $parent ): array {
			$chain = [];
			$seen  = [];
			while ( isset( $parent[ $id ] ) && $parent[ $id ] > 0 && empty( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$id          = $parent[ $id ];
				$chain[]     = $id;
			}
			return $chain;
		};

		$out = [];
		foreach ( $prod as $pid => $s ) {
			$targets = [];
			foreach ( $prod_terms[ $pid ] ?? [] as $tid ) {
				$targets[ $tid ] = true;
				foreach ( $ancestors( $tid ) as $a ) {
					$targets[ $a ] = true;
				}
			}
			foreach ( array_keys( $targets ) as $tid ) {
				if ( ! isset( $out[ $tid ] ) ) {
					$out[ $tid ] = [ 'qty' => 0.0, 'rev' => 0.0 ];
				}
				$out[ $tid ]['qty'] += $s['qty'];
				$out[ $tid ]['rev'] += $s['rev'];
			}
		}
		set_transient( 'dze_x_cat_sales', $out, 3 * HOUR_IN_SECONDS );
		return $out;
	}

	// =========================================================================
	// AJAX: products page
	// =========================================================================

	public function ajax_products(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}

		$paged  = max( 1, (int) ( $_POST['paged'] ?? 1 ) );
		$search = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		$cat    = (int) ( $_POST['cat'] ?? 0 );
		$sort   = isset( $_POST['sort'] ) ? sanitize_key( wp_unslash( $_POST['sort'] ) ) : 'date_desc';
		$stock  = isset( $_POST['stock'] ) ? sanitize_key( wp_unslash( $_POST['stock'] ) ) : '';
		$attrs  = ( isset( $_POST['attr'] ) && is_array( $_POST['attr'] ) ) ? wp_unslash( $_POST['attr'] ) : [];

		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
		];
		switch ( $sort ) {
			case 'title_asc':  $args['orderby'] = 'title'; $args['order'] = 'ASC'; break;
			case 'title_desc': $args['orderby'] = 'title'; $args['order'] = 'DESC'; break;
			case 'date_asc':   $args['orderby'] = 'date';  $args['order'] = 'ASC'; break;
			default:           $args['orderby'] = 'date';  $args['order'] = 'DESC';
		}
		if ( $search !== '' ) {
			$args['s'] = $search;
		}
		$tax = [];
		if ( $cat > 0 ) {
			$tax[] = [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat, 'include_children' => true ];
		}
		foreach ( $attrs as $t => $slug ) {
			$t    = sanitize_key( $t );
			$slug = sanitize_text_field( $slug );
			if ( $slug !== '' && taxonomy_exists( $t ) ) {
				$tax[] = [ 'taxonomy' => $t, 'field' => 'slug', 'terms' => $slug ];
			}
		}
		if ( $tax ) {
			$args['tax_query'] = $tax;
		}
		if ( 'in' === $stock ) {
			$args['meta_query'] = [ [ 'key' => '_stock_status', 'value' => 'instock' ] ];
		} elseif ( 'out' === $stock ) {
			$args['meta_query'] = [ [ 'key' => '_stock_status', 'value' => 'outofstock' ] ];
		}

		$query = new WP_Query( $args );
		$html  = '';
		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post );
			if ( $product instanceof \WC_Product ) {
				$html .= $this->card_html( $product );
			}
		}
		wp_send_json_success( [
			'html'    => $html,
			'found'   => (int) $query->found_posts,
			'hasMore' => $paged < (int) $query->max_num_pages,
		] );
	}

	private function card_html( \WC_Product $product ): string {
		$img_id    = (int) $product->get_image_id();
		$thumb     = $img_id ? wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src( 'woocommerce_thumbnail' );
		$full      = $img_id ? wp_get_attachment_image_url( $img_id, 'full' ) : $thumb;
		$is_var    = $product->is_type( 'variable' );
		$var_count = $is_var ? count( $product->get_children() ) : 0;
		$edit      = get_edit_post_link( $product->get_id() );
		$view      = get_permalink( $product->get_id() );

		ob_start();
		?>
		<div class="dze-x-card">
			<div class="dze-x-thumb dze-thumb-wrap">
				<img class="dze-thumb dze-x-img" src="<?php echo esc_url( $thumb ); ?>" data-full="<?php echo esc_url( $full ); ?>" alt="" loading="lazy" />
			</div>
			<div class="dze-x-name"><?php echo esc_html( $product->get_name() ); ?></div>
			<div class="dze-x-meta">
				<span><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
				<span class="dze-x-id">#<?php echo (int) $product->get_id(); ?></span>
			</div>
			<div class="dze-x-date"><?php
				/* translators: %s: product publication date */
				printf( esc_html__( 'Published: %s', 'dazont-ecom' ), esc_html( get_the_date( '', $product->get_id() ) ) );
			?></div>
			<div class="dze-x-actions">
				<?php if ( $edit ) : ?><a class="button button-small" href="<?php echo esc_url( $edit ); ?>" target="_blank"><?php esc_html_e( 'Edit', 'dazont-ecom' ); ?></a><?php endif; ?>
				<?php if ( $view ) : ?><a class="button button-small" href="<?php echo esc_url( $view ); ?>" target="_blank"><?php esc_html_e( 'View', 'dazont-ecom' ); ?></a><?php endif; ?>
				<?php if ( $is_var && $var_count > 0 ) : ?>
					<button type="button" class="button button-small dze-x-vars" data-product="<?php echo (int) $product->get_id(); ?>"><?php
						/* translators: %d: number of variations */
						echo esc_html( sprintf( __( 'Variations (%d)', 'dazont-ecom' ), $var_count ) );
					?></button>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// =========================================================================
	// AJAX: variation images (same shape as the Gallery module)
	// =========================================================================

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
			$img_id = (int) $variation->get_image_id() ?: (int) get_post_thumbnail_id( $product_id );
			$out[]  = [
				'title' => wp_strip_all_tags( wc_get_formatted_variation( $variation, true, true, false ) ),
				'thumb' => $img_id ? ( wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) ?: wp_get_attachment_image_url( $img_id, 'thumbnail' ) ) : '',
				'full'  => $img_id ? wp_get_attachment_image_url( $img_id, 'full' ) : '',
			];
		}
		usort( $out, static fn( $a, $b ) => strcasecmp( $a['title'], $b['title'] ) );
		wp_send_json_success( [ 'images' => $out ] );
	}
}
