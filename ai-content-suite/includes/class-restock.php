<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Restockage" admin module.
 *
 * Lists product-lines (simple products OR variable parents) that have at least
 * one out-of-stock element, so the shop owner can drive the restock backlog
 * without manual exports. Sales figures are cached via WP-Cron for speed on
 * large catalogues (600+ product-lines).
 *
 * See admin/views/restock-page.php + class-restock-list-table.php.
 */
final class AICS_Restock {

	public const CRON_HOOK   = 'aics_restock_recalc_sales';
	public const SALES_META  = '_kula_total_sales_cached';
	public const LAST_RECALC = 'aics_restock_last_recalc';
	public const MENU_SLUG   = 'aics-restockage';

	/** Order statuses considered as "paid" for sales aggregation. */
	private const PAID_STATUSES = [ 'completed', 'processing', 'on-hold' ];

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_submenu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'init',                  [ $this, 'maybe_schedule_cron' ] );

		add_action( self::CRON_HOOK, [ $this, 'recalc_sales' ] );

		add_action( 'wp_ajax_aics_restock_variations', [ $this, 'ajax_variations' ] );
		add_action( 'wp_ajax_aics_restock_recalc',     [ $this, 'ajax_recalc' ] );
	}

	// -------------------------------------------------------------------------
	// Cron scheduling
	// -------------------------------------------------------------------------

	public function maybe_schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Weekly cadence — coherent with a monthly/quarterly restock rhythm.
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
	}

	public static function clear_cron(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// -------------------------------------------------------------------------
	// Menu + assets
	// -------------------------------------------------------------------------

	public function register_submenu(): void {
		add_submenu_page(
			'aics-settings',
			__( 'Restockage', 'ai-content-suite' ),
			__( 'Restockage', 'ai-content-suite' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	private function is_restock_screen( string $hook ): bool {
		return strpos( $hook, self::MENU_SLUG ) !== false;
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! $this->is_restock_screen( $hook ) ) {
			return;
		}
		wp_enqueue_style( 'aics-admin', AICS_URL . 'admin/css/admin.css', [], AICS_VERSION );
		wp_enqueue_script( 'aics-restock', AICS_URL . 'admin/js/restock.js', [ 'jquery' ], AICS_VERSION, true );
		wp_localize_script( 'aics-restock', 'aicsRestock', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'aics_admin' ),
			'i18n'    => [
				'loading'    => __( 'Loading variations…', 'ai-content-suite' ),
				'recalc'     => __( 'Recalculating sales… this may take a moment.', 'ai-content-suite' ),
				'recalcDone' => __( 'Sales cache updated.', 'ai-content-suite' ),
				'error'      => __( 'Error', 'ai-content-suite' ),
				'noVar'      => __( 'No out-of-stock variations found.', 'ai-content-suite' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Page
	// -------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-content-suite' ) );
		}

		require_once AICS_DIR . 'includes/class-restock-list-table.php';

		$table = new AICS_Restock_List_Table();
		$table->prepare_items();

		$last_recalc = (int) get_option( self::LAST_RECALC, 0 );

		require AICS_DIR . 'admin/views/restock-page.php';
	}

	// -------------------------------------------------------------------------
	// Data gathering (shared)
	// -------------------------------------------------------------------------

	/**
	 * Builds the index of out-of-stock product-lines.
	 *
	 * @return array<int, array{id:int,type:string,oos:int[],total:int}>
	 *         Keyed by line id (simple product id or variable parent id).
	 */
	public static function get_line_index(): array {
		global $wpdb;

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

		$lines = [];
		foreach ( $rows as $row ) {
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

		// Total variation count per variable parent (single grouped query).
		$parents = [];
		foreach ( $lines as $line ) {
			if ( $line['type'] === 'variable' ) {
				$parents[] = $line['id'];
			}
		}
		if ( $parents ) {
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

	/**
	 * Returns the cached sales figure for a product-line.
	 * Simple products use WooCommerce's native counter; variable parents use the
	 * cron-cached meta (0 until the first recalculation runs).
	 */
	public static function get_line_sales( int $id, string $type ): int {
		if ( $type === 'simple' ) {
			return (int) get_post_meta( $id, 'total_sales', true );
		}
		return (int) get_post_meta( $id, self::SALES_META, true );
	}

	// -------------------------------------------------------------------------
	// AJAX: lazy-load variations sub-table
	// -------------------------------------------------------------------------

	public function ajax_variations(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-content-suite' ) ], 403 );
		}

		$parent_id = (int) ( $_POST['parent_id'] ?? 0 );
		if ( ! $parent_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product.', 'ai-content-suite' ) ] );
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

		$edit_link = get_edit_post_link( $parent_id, 'raw' );
		$rows_html = '';

		foreach ( $variation_ids as $vid ) {
			$variation = wc_get_product( (int) $vid );
			if ( ! $variation ) {
				continue;
			}
			$name  = wc_get_formatted_variation( $variation, true );
			if ( $name === '' ) {
				$name = $variation->get_name();
			}
			$sku   = $variation->get_sku();
			$price = $variation->get_price_html();
			$sales = (int) get_post_meta( (int) $vid, self::SALES_META, true );

			$rows_html .= '<tr>'
				. '<td>' . esc_html( $name ) . '</td>'
				. '<td>' . esc_html( $sku ?: '—' ) . '</td>'
				. '<td>' . wp_kses_post( $price ?: '—' ) . '</td>'
				. '<td>' . esc_html( (string) $sales ) . '</td>'
				. '<td><a href="' . esc_url( $edit_link . '#variable_product_options' ) . '" target="_blank">'
					. esc_html__( 'Edit', 'ai-content-suite' ) . '</a></td>'
				. '</tr>';
		}

		wp_send_json_success( [ 'rows' => $rows_html, 'count' => count( $variation_ids ) ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: manual recalculation
	// -------------------------------------------------------------------------

	public function ajax_recalc(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-content-suite' ) ], 403 );
		}

		$processed = $this->recalc_sales();

		wp_send_json_success( [
			'message'   => sprintf(
				/* translators: %d: number of variations processed */
				__( 'Done. %d variations processed.', 'ai-content-suite' ),
				$processed
			),
			'timestamp' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), time() ),
		] );
	}

	// -------------------------------------------------------------------------
	// Sales aggregation worker (cron + manual)
	// -------------------------------------------------------------------------

	/**
	 * Re-computes lifetime sales per variation from paid orders using the
	 * WooCommerce CRUD API (HPOS-safe), then caches totals on each variation
	 * and on the variable parent. Returns the number of variations written.
	 */
	public function recalc_sales(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$sales_by_variation = [];
		$page  = 1;
		$limit = 100;

		do {
			$orders = wc_get_orders( [
				'limit'   => $limit,
				'page'    => $page,
				'status'  => self::PAID_STATUSES,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'objects',
			] );

			foreach ( $orders as $order ) {
				foreach ( $order->get_items() as $item ) {
					if ( ! $item instanceof WC_Order_Item_Product ) {
						continue;
					}
					$vid = $item->get_variation_id();
					if ( ! $vid ) {
						continue; // simple product line — native total_sales already tracks it.
					}
					$sales_by_variation[ $vid ] = ( $sales_by_variation[ $vid ] ?? 0 ) + (int) $item->get_quantity();
				}
			}

			$page++;
		} while ( count( $orders ) === $limit );

		// Persist per-variation totals and accumulate per-parent totals.
		$parent_totals = [];
		foreach ( $sales_by_variation as $vid => $qty ) {
			update_post_meta( (int) $vid, self::SALES_META, (int) $qty );
			$parent = wp_get_post_parent_id( (int) $vid );
			if ( $parent ) {
				$parent_totals[ $parent ] = ( $parent_totals[ $parent ] ?? 0 ) + (int) $qty;
			}
		}
		foreach ( $parent_totals as $pid => $tot ) {
			update_post_meta( (int) $pid, self::SALES_META, (int) $tot );
		}

		update_option( self::LAST_RECALC, time(), false );

		return count( $sales_by_variation );
	}
}
