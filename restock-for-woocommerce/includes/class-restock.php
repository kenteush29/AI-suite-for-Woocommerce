<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Restock" admin module.
 *
 * Lists product-lines (simple products OR variable parents) that have at least
 * one out-of-stock element, ranked by total sales, so the shop owner can drive
 * the restock backlog. Sales are cached via WP-Cron for speed on large
 * catalogues and aggregated across all WPML languages.
 */
final class RSTK_Restock {

	public const CRON_HOOK   = 'rstk_recalc_sales';
	public const SALES_META  = '_rstk_total_sales_cached';
	public const LAST_RECALC = 'rstk_last_recalc';
	public const MENU_SLUG   = 'restock';
	public const NONCE       = 'rstk_admin';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'init',                  [ $this, 'maybe_schedule_cron' ] );

		add_action( self::CRON_HOOK, [ $this, 'recalc_sales' ] );

		add_action( 'wp_ajax_rstk_variations', [ $this, 'ajax_variations' ] );
		add_action( 'wp_ajax_rstk_recalc',     [ $this, 'ajax_recalc' ] );
	}

	// -------------------------------------------------------------------------
	// Cron
	// -------------------------------------------------------------------------

	public function maybe_schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
	}

	// -------------------------------------------------------------------------
	// Menu + assets
	// -------------------------------------------------------------------------

	public function register_menu(): void {
		add_menu_page(
			__( 'Restock', 'restock-for-woocommerce' ),
			__( 'Restock', 'restock-for-woocommerce' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ],
			'dashicons-update',
			56
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::MENU_SLUG ) === false ) {
			return;
		}
		wp_enqueue_style( 'rstk-admin', RSTK_URL . 'admin/css/admin.css', [], RSTK_VERSION );
		wp_enqueue_script( 'rstk-admin', RSTK_URL . 'admin/js/restock.js', [ 'jquery' ], RSTK_VERSION, true );
		wp_localize_script( 'rstk-admin', 'rstkRestock', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				'loading'    => __( 'Loading variations…', 'restock-for-woocommerce' ),
				'recalc'     => __( 'Recalculating sales… this may take a moment.', 'restock-for-woocommerce' ),
				'error'      => __( 'Error', 'restock-for-woocommerce' ),
				'noVar'      => __( 'No out-of-stock variations found.', 'restock-for-woocommerce' ),
				'subNote'    => __( 'Only out-of-stock variations are listed. The product\'s Total Sales above covers all its variations (in stock included).', 'restock-for-woocommerce' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Page
	// -------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'restock-for-woocommerce' ) );
		}

		require_once RSTK_DIR . 'includes/class-restock-list-table.php';

		$table = new RSTK_Restock_List_Table();
		$table->prepare_items();

		$last_recalc = (int) get_option( self::LAST_RECALC, 0 );

		require RSTK_DIR . 'admin/views/restock-page.php';
	}

	// -------------------------------------------------------------------------
	// Data gathering
	// -------------------------------------------------------------------------

	/**
	 * @return array<int, array{id:int,type:string,oos:int[],total:int}>
	 */
	public static function get_line_index(): array {
		global $wpdb;

		// No user input in this query; static literals only.
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_type, p.post_parent
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} sm
			     ON sm.post_id = p.ID
			     AND sm.meta_key = '_stock_status'
			     AND sm.meta_value = 'outofstock'
			 WHERE p.post_type IN ('product','product_variation')
			   AND p.post_status = 'publish'"
		);

		$default_lang = RSTK_Wpml::default_language();

		$lines = [];
		foreach ( $rows as $row ) {
			if ( $default_lang ) {
				$lang = RSTK_Wpml::post_language( (int) $row->ID, $row->post_type );
				if ( $lang && $lang !== $default_lang ) {
					continue;
				}
			}

			if ( $row->post_type === 'product' ) {
				$id = (int) $row->ID;
				if ( ! isset( $lines[ $id ] ) ) {
					$lines[ $id ] = [ 'id' => $id, 'type' => 'simple', 'oos' => [], 'total' => 0 ];
				}
			} else {
				$parent = (int) $row->post_parent;
				if ( ! $parent ) {
					continue;
				}
				if ( ! isset( $lines[ $parent ] ) ) {
					$lines[ $parent ] = [ 'id' => $parent, 'type' => 'variable', 'oos' => [], 'total' => 0 ];
				}
				$lines[ $parent ]['type']  = 'variable';
				$lines[ $parent ]['oos'][] = (int) $row->ID;
			}
		}

		$parents = [];
		foreach ( $lines as $line ) {
			if ( $line['type'] === 'variable' ) {
				$parents[] = (int) $line['id'];
			}
		}
		if ( $parents ) {
			// Ints only, sanitised via intval — safe to inline.
			$in     = implode( ',', array_map( 'intval', $parents ) );
			$counts = $wpdb->get_results(
				"SELECT post_parent, COUNT(*) AS c
				 FROM {$wpdb->posts}
				 WHERE post_type = 'product_variation'
				   AND post_status = 'publish'
				   AND post_parent IN ($in)
				 GROUP BY post_parent"
			);
			foreach ( $counts as $c ) {
				$pid = (int) $c->post_parent;
				if ( isset( $lines[ $pid ] ) ) {
					$lines[ $pid ]['total'] = (int) $c->c;
				}
			}
		}

		return $lines;
	}

	public static function get_line_sales( int $id ): int {
		return (int) get_post_meta( $id, self::SALES_META, true );
	}

	// -------------------------------------------------------------------------
	// AJAX: lazy-load variations
	// -------------------------------------------------------------------------

	public function ajax_variations(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'restock-for-woocommerce' ) ], 403 );
		}

		$parent_id = isset( $_POST['parent_id'] ) ? absint( $_POST['parent_id'] ) : 0;
		if ( ! $parent_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product.', 'restock-for-woocommerce' ) ] );
		}

		global $wpdb;
		$variation_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} sm
			     ON sm.post_id = p.ID AND sm.meta_key = '_stock_status' AND sm.meta_value = 'outofstock'
			 WHERE p.post_type = 'product_variation'
			   AND p.post_status = 'publish'
			   AND p.post_parent = %d
			 ORDER BY p.menu_order ASC, p.ID ASC",
			$parent_id
		) );

		$rows_html = '';
		foreach ( $variation_ids as $vid ) {
			$variation = wc_get_product( (int) $vid );
			if ( ! $variation ) {
				continue;
			}
			$name = wc_get_formatted_variation( $variation, true );
			if ( $name === '' ) {
				$name = $variation->get_name();
			}
			$sku   = $variation->get_sku();
			$price = $variation->get_price_html();
			$sales = (int) get_post_meta( (int) $vid, self::SALES_META, true );

			$rows_html .= '<tr>'
				. '<td>' . esc_html( $name ) . '</td>'
				. '<td>' . esc_html( $sku !== '' ? $sku : '—' ) . '</td>'
				. '<td>' . wp_kses_post( $price !== '' ? $price : '—' ) . '</td>'
				. '<td>' . esc_html( (string) $sales ) . '</td>'
				. '</tr>';
		}

		wp_send_json_success( [ 'rows' => $rows_html, 'count' => count( $variation_ids ) ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: manual recalculation
	// -------------------------------------------------------------------------

	public function ajax_recalc(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'restock-for-woocommerce' ) ], 403 );
		}

		$processed = $this->recalc_sales();

		wp_send_json_success( [
			'message'   => sprintf(
				/* translators: %d: number of product-lines processed */
				__( 'Done. %d product-lines processed.', 'restock-for-woocommerce' ),
				$processed
			),
			'timestamp' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), time() ),
		] );
	}

	// -------------------------------------------------------------------------
	// Sales aggregation worker
	// -------------------------------------------------------------------------

	/**
	 * Counted order statuses. This is a demand/interest signal, not revenue, so
	 * EVERY real order counts — including refunded, cancelled and failed — and
	 * refunds are never subtracted (ordered quantity is the signal).
	 */
	private function counted_statuses(): array {
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			return array_map(
				static fn( string $s ): string => preg_replace( '/^wc-/', '', $s ),
				array_keys( wc_get_order_statuses() )
			);
		}
		return [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ];
	}

	/**
	 * Rebuilds cached sales from all orders via the WooCommerce CRUD API
	 * (HPOS-safe). Every ordered line item is folded onto its canonical,
	 * default-language product/variation. Returns product-lines written.
	 */
	public function recalc_sales(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$line_totals = [];
		$var_totals  = [];

		$statuses = $this->counted_statuses();
		$page     = 1;
		$limit    = 100;

		do {
			$orders = wc_get_orders( [
				'limit'   => $limit,
				'page'    => $page,
				'status'  => $statuses,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'objects',
			] );

			foreach ( $orders as $order ) {
				foreach ( $order->get_items() as $item ) {
					if ( ! $item instanceof WC_Order_Item_Product ) {
						continue;
					}
					$qty = (int) $item->get_quantity();
					if ( $qty <= 0 ) {
						continue;
					}
					$pid = (int) $item->get_product_id();
					$vid = (int) $item->get_variation_id();

					if ( $vid ) {
						$canon_parent = RSTK_Wpml::canonical_id( $pid, 'product' );
						$canon_var    = RSTK_Wpml::canonical_id( $vid, 'product_variation' );
						$line_totals[ $canon_parent ] = ( $line_totals[ $canon_parent ] ?? 0 ) + $qty;
						$var_totals[ $canon_var ]     = ( $var_totals[ $canon_var ] ?? 0 ) + $qty;
					} elseif ( $pid ) {
						$canon = RSTK_Wpml::canonical_id( $pid, 'product' );
						$line_totals[ $canon ] = ( $line_totals[ $canon ] ?? 0 ) + $qty;
					}
				}
			}

			$page++;
		} while ( count( $orders ) === $limit );

		foreach ( $line_totals as $id => $qty ) {
			update_post_meta( (int) $id, self::SALES_META, (int) $qty );
		}
		foreach ( $var_totals as $id => $qty ) {
			update_post_meta( (int) $id, self::SALES_META, (int) $qty );
		}

		update_option( self::LAST_RECALC, time(), false );

		return count( $line_totals );
	}
}
