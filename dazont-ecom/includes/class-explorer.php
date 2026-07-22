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

	/** Term meta: unix timestamp of the last manual "novelty search" for a category. */
	private const META_RESEARCHED = '_dze_researched';

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
		add_action( 'wp_ajax_dze_explorer_products',        [ $this, 'ajax_products' ] );
		add_action( 'wp_ajax_dze_explorer_variations',      [ $this, 'ajax_variations' ] );
		add_action( 'wp_ajax_dze_explorer_mark_researched', [ $this, 'ajax_mark_researched' ] );
		add_action( 'wp_ajax_dze_explorer_ai_insights',     [ $this, 'ajax_ai_insights' ] );
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
				'never'      => __( 'never', 'dazont-ecom' ),
				'justNow'    => __( 'just now', 'dazont-ecom' ),
				'sold'       => __( 'sold', 'dazont-ecom' ),
				'products'   => __( 'products', 'dazont-ecom' ),
				'noCats'     => __( 'No categories match.', 'dazont-ecom' ),
				'aiThinking' => __( 'Analysing this category…', 'dazont-ecom' ),
				'sortBy'     => __( 'Sort by', 'dazont-ecom' ),
				'byId'       => __( 'ID', 'dazont-ecom' ),
			],
		] );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$categories = $this->category_tree();
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
					'id'                => $cid,
					'name'              => $t->name,
					'count'             => $rec_count( $cid ),
					'count_direct'      => (int) ( $t->count ?? 0 ),
					'sales_qty'         => (float) ( $sales[ $cid ]['qty'] ?? 0 ),
					'sales_rev'         => (float) ( $sales[ $cid ]['rev'] ?? 0 ),
					'sales_qty_direct'  => (float) ( $sales[ $cid ]['qty_direct'] ?? 0 ),
					'sales_rev_direct'  => (float) ( $sales[ $cid ]['rev_direct'] ?? 0 ),
					'researched'        => (int) get_term_meta( $cid, self::META_RESEARCHED, true ),
					'image'             => $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '',
					'children'          => $build( $cid ),
				];
			}
			return $out;
		};
		return $build( 0 );
	}

	/**
	 * Per-category sales from WooCommerce Analytics' order-product lookup table,
	 * in two flavours:
	 *   - qty / rev              : rolled up over the whole subtree (a product is
	 *                              counted once per ancestor category).
	 *   - qty_direct / rev_direct: only products directly assigned to that exact
	 *                              category (no rollup) — to see precisely what a
	 *                              single category sells, independently of children.
	 * Cached for a few hours because this drives ordering, not live figures.
	 *
	 * @param array<int,int> $parent term_id => parent term_id map.
	 * @return array<int,array{qty:float,rev:float,qty_direct:float,rev_direct:float}>
	 */
	private function category_sales( array $parent ): array {
		$cached = get_transient( 'dze_x_cat_sales_v2' );
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
			set_transient( 'dze_x_cat_sales_v2', [], 3 * HOUR_IN_SECONDS );
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
		$bump = static function ( array &$out, int $tid, array $s, bool $direct ): void {
			if ( ! isset( $out[ $tid ] ) ) {
				$out[ $tid ] = [ 'qty' => 0.0, 'rev' => 0.0, 'qty_direct' => 0.0, 'rev_direct' => 0.0 ];
			}
			if ( $direct ) {
				$out[ $tid ]['qty_direct'] += $s['qty'];
				$out[ $tid ]['rev_direct'] += $s['rev'];
			} else {
				$out[ $tid ]['qty'] += $s['qty'];
				$out[ $tid ]['rev'] += $s['rev'];
			}
		};
		foreach ( $prod as $pid => $s ) {
			$tids = array_unique( $prod_terms[ $pid ] ?? [] );
			// Direct: only the categories the product is actually filed under.
			foreach ( $tids as $tid ) {
				$bump( $out, (int) $tid, $s, true );
			}
			// Rolled up: those categories plus every ancestor, once each.
			$targets = [];
			foreach ( $tids as $tid ) {
				$targets[ $tid ] = true;
				foreach ( $ancestors( (int) $tid ) as $a ) {
					$targets[ $a ] = true;
				}
			}
			foreach ( array_keys( $targets ) as $tid ) {
				$bump( $out, (int) $tid, $s, false );
			}
		}
		set_transient( 'dze_x_cat_sales_v2', $out, 3 * HOUR_IN_SECONDS );
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
		$sales     = class_exists( 'DZE_Restock' ) ? DZE_Restock::get_line_sales( $product->get_id() ) : 0;

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
			<div class="dze-x-sales"><?php
				/* translators: %s: number of units sold */
				echo esc_html( sprintf( _n( '%s sold', '%s sold', $sales, 'dazont-ecom' ), number_format_i18n( $sales ) ) );
			?></div>
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
		$out   = [];
		$attrs = []; // attribute label => true, to expose the available sort keys.
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof \WC_Product ) {
				continue;
			}
			$img_id = (int) $variation->get_image_id() ?: (int) get_post_thumbnail_id( $product_id );
			$vattrs = [];
			foreach ( $variation->get_attributes() as $tax => $value ) {
				if ( '' === $value ) {
					continue;
				}
				$label = wc_attribute_label( $tax, $product );
				$term  = taxonomy_exists( $tax ) ? get_term_by( 'slug', $value, $tax ) : null;
				$vattrs[ $label ] = $term && ! is_wp_error( $term ) ? $term->name : (string) $value;
				$attrs[ $label ]  = true;
			}
			$out[] = [
				'id'    => (int) $variation_id,
				'title' => wp_strip_all_tags( wc_get_formatted_variation( $variation, true, true, false ) ),
				'thumb' => $img_id ? ( wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) ?: wp_get_attachment_image_url( $img_id, 'thumbnail' ) ) : '',
				'full'  => $img_id ? wp_get_attachment_image_url( $img_id, 'full' ) : '',
				'attrs' => $vattrs,
			];
		}
		usort( $out, static fn( $a, $b ) => $a['id'] <=> $b['id'] );
		wp_send_json_success( [ 'images' => $out, 'attributes' => array_keys( $attrs ) ] );
	}

	// =========================================================================
	// AJAX: mark a category as "novelty-searched" today
	// =========================================================================

	public function ajax_mark_researched(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$cat = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0;
		if ( ! $cat || ! term_exists( $cat, 'product_cat' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown category.', 'dazont-ecom' ) ] );
		}
		$now = time();
		update_term_meta( $cat, self::META_RESEARCHED, $now );
		wp_send_json_success( [
			'ts'   => $now,
			'date' => date_i18n( get_option( 'date_format' ), $now ),
		] );
	}

	// =========================================================================
	// AJAX: short AI recap of the selected category + sourcing hints
	// =========================================================================

	public function ajax_ai_insights(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		if ( ! class_exists( 'DZE_Marketing_Ai' ) || DZE_Marketing_Ai::api_key() === '' ) {
			wp_send_json_error( [ 'message' => __( 'Add your Anthropic API key in the AI Marketing Assistant settings first.', 'dazont-ecom' ) ] );
		}
		$cat  = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0;
		$term = $cat ? get_term( $cat, 'product_cat' ) : null;
		if ( ! $term instanceof WP_Term ) {
			wp_send_json_error( [ 'message' => __( 'Unknown category.', 'dazont-ecom' ) ] );
		}

		// Category path (ancestors → current).
		$names = [];
		foreach ( array_reverse( get_ancestors( $cat, 'product_cat', 'taxonomy' ) ) as $aid ) {
			$anc = get_term( (int) $aid, 'product_cat' );
			if ( $anc instanceof WP_Term ) {
				$names[] = $anc->name;
			}
		}
		$names[] = $term->name;
		$path    = implode( ' > ', $names );

		// A sample of current product titles in the category (incl. sub-categories).
		$q = new WP_Query( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 40,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat, 'include_children' => true ] ],
		] );
		$titles = [];
		foreach ( $q->posts as $pid ) {
			$titles[] = '- ' . wp_strip_all_tags( get_the_title( (int) $pid ) );
		}

		$system = 'You are a senior product-sourcing assistant for an e-commerce catalogue. '
			. 'You are extremely concise, concrete and practical. Never invent facts about the shop.';
		$user = "Product category: {$path}\n\n";
		$user .= $titles
			? ( "A sample of products currently in this category:\n" . implode( "\n", $titles ) . "\n\n" )
			: "This category currently has no products.\n\n";
		$user .= "In 3 short sentences maximum and no more than ~70 words total: "
			. "(1) summarise the kind of products this category is about, "
			. "(2) give concrete, specific suggestions (product types, styles, search keywords) the operator could source to fit this category well and fill gaps. "
			. "Write in the same language as the product titles above. Plain prose, no preamble, no bullet points, no headings.";

		try {
			$text = $this->call_claude( $system, $user );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		$text = trim( $text );
		if ( $text === '' ) {
			wp_send_json_error( [ 'message' => __( 'The AI returned nothing. Try again.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [ 'text' => $text ] );
	}

	/** Minimal Anthropic Messages call, reusing the Marketing AI key + model. */
	private function call_claude( string $system, string $user ): string {
		$resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
			'timeout' => 60,
			'headers' => [
				'x-api-key'         => DZE_Marketing_Ai::api_key(),
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			],
			'body'    => wp_json_encode( [
				'model'      => DZE_Marketing_Ai::chosen_model(),
				'max_tokens' => 400,
				'system'     => $system,
				'messages'   => [ [ 'role' => 'user', 'content' => $user ] ],
			] ),
		] );
		if ( is_wp_error( $resp ) ) {
			throw new RuntimeException( $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			throw new RuntimeException( (string) ( $data['error']['message'] ?? ( 'HTTP ' . $code ) ) );
		}
		$text = '';
		foreach ( (array) ( $data['content'] ?? [] ) as $block ) {
			if ( ( $block['type'] ?? '' ) === 'text' ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}
		return $text;
	}
}
