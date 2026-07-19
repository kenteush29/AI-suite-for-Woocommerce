<?php
defined( 'ABSPATH' ) || exit;

/**
 * Frequently Bought Together.
 *
 * Shows a small, automatic "Frequently bought together" block on the single
 * product page. Recommendations are 100% automatic and chosen for relevance:
 *
 *   1. Co-purchase — products most often bought in the SAME orders as the one
 *      being viewed (behavioural relevance: a military pant that ships with a
 *      military jacket will surface that jacket). Read once from the WooCommerce
 *      Analytics product-lookup table.
 *   2. Fallback / top-up — when there isn't enough order history yet, the block
 *      is filled with products from the same category (thematic relevance).
 *
 * Performance is the priority: the recommendation IDs for a product are computed
 * at most once per PERF_TTL and then served from a transient, so ordinary page
 * views never run the analysis query. The block only ever loads on single
 * product pages, adds no front-end JavaScript of its own, and renders with a
 * handful of already-cached WooCommerce calls. Everything is tunable/removable
 * through filters (see below) — no settings screen, nothing to configure.
 *
 * Filters:
 *   dze_fbt_enabled  (bool)            master on/off (default true)
 *   dze_fbt_limit    (int)             how many products to show (default 4)
 *   dze_fbt_heading  (string)          block heading
 *   dze_fbt_ttl      (int, seconds)    how long recommendations are cached
 *   dze_fbt_ids      (int[] , $pid)    final recommended IDs (last word to devs)
 */
final class DZE_Fbt {

	private const CACHE_PREFIX = 'dze_fbt_';
	private const DEFAULT_LIMIT = 4;
	private const PERF_TTL = DAY_IN_SECONDS; // recommendations refresh once a day.

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Front-end only, and only when WooCommerce is present.
		add_action( 'woocommerce_after_single_product_summary', [ $this, 'render' ], 15 );
	}

	private function limit(): int {
		return max( 1, (int) apply_filters( 'dze_fbt_limit', self::DEFAULT_LIMIT ) );
	}

	/** Renders the block under the product summary. Cheap: cached IDs + WC calls. */
	public function render(): void {
		if ( ! apply_filters( 'dze_fbt_enabled', true ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			$product = wc_get_product( get_the_ID() );
		}
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$ids = $this->recommendations( $product->get_id(), $this->limit() );
		if ( empty( $ids ) ) {
			return;
		}

		$heading = (string) apply_filters( 'dze_fbt_heading', __( 'Frequently bought together', 'dazont-ecom' ) );

		echo '<section class="dze-fbt">';
		if ( $heading !== '' ) {
			echo '<h2 class="dze-fbt__title">' . esc_html( $heading ) . '</h2>';
		}
		echo '<ul class="dze-fbt__grid">';
		foreach ( $ids as $pid ) {
			$p = wc_get_product( $pid );
			if ( ! $p instanceof \WC_Product || ! $p->is_visible() ) {
				continue;
			}
			$this->card( $p );
		}
		echo '</ul>';
		echo '</section>';
		$this->print_styles_once();
	}

	/** One product card: image, title, price and a native add-to-cart button. */
	private function card( \WC_Product $p ): void {
		$link  = get_permalink( $p->get_id() );
		$image = $p->get_image( 'woocommerce_thumbnail' );

		echo '<li class="dze-fbt__item">';
		printf( '<a class="dze-fbt__link" href="%s">%s<span class="dze-fbt__name">%s</span></a>',
			esc_url( $link ),
			$image, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC image markup.
			esc_html( $p->get_name() )
		);
		echo '<span class="dze-fbt__price">' . $p->get_price_html() . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC price markup.

		// Reuse WooCommerce's own loop add-to-cart button (keeps AJAX behaviour
		// and theme styling; no custom cart JS needed).
		$prev = $GLOBALS['product'] ?? null;
		$GLOBALS['product'] = $p;
		woocommerce_template_loop_add_to_cart();
		$GLOBALS['product'] = $prev;

		echo '</li>';
	}

	/**
	 * Recommended product IDs for a product, cached for PERF_TTL. Co-purchase
	 * first, topped up with same-category products when history is thin.
	 */
	public function recommendations( int $product_id, int $limit ): array {
		$key    = self::CACHE_PREFIX . $product_id . '_' . $limit;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids = $this->co_purchased_ids( $product_id, $limit );
		if ( count( $ids ) < $limit ) {
			$ids = array_values( array_unique( array_merge( $ids, $this->same_category_ids( $product_id, $limit * 2 ) ) ) );
		}
		$ids = array_values( array_filter( $ids, static fn( $id ) => (int) $id !== $product_id ) );
		$ids = array_slice( $ids, 0, $limit );

		/** @var int[] $ids */
		$ids = array_map( 'intval', (array) apply_filters( 'dze_fbt_ids', $ids, $product_id ) );

		$ttl = (int) apply_filters( 'dze_fbt_ttl', self::PERF_TTL );
		set_transient( $key, $ids, max( HOUR_IN_SECONDS, $ttl ) );
		return $ids;
	}

	/** Products most often bought in the same orders as $product_id (WC Analytics). */
	private function co_purchased_ids( int $product_id, int $limit ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table derives from $wpdb->prefix.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT l2.product_id
			 FROM {$table} l1
			 INNER JOIN {$table} l2 ON l2.order_id = l1.order_id AND l2.product_id <> l1.product_id
			 INNER JOIN {$wpdb->posts} p ON p.ID = l2.product_id AND p.post_status = 'publish' AND p.post_type = 'product'
			 WHERE l1.product_id = %d
			 GROUP BY l2.product_id
			 ORDER BY COUNT(DISTINCT l2.order_id) DESC
			 LIMIT %d",
			$product_id,
			$limit
		) );
		return array_map( 'intval', (array) $ids );
	}

	/** Same-category products (thematic fallback when co-purchase data is thin). */
	private function same_category_ids( int $product_id, int $limit ): array {
		$terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}
		$q = new WP_Query( [
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'post__not_in'        => [ $product_id ],
			'orderby'             => 'rand',
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'tax_query'           => [ [
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => $terms,
			] ],
		] );
		return array_map( 'intval', $q->posts );
	}

	private static bool $styles_done = false;

	private function print_styles_once(): void {
		if ( self::$styles_done ) {
			return;
		}
		self::$styles_done = true;
		echo '<style>'
			. '.dze-fbt{margin:2.5em 0;}'
			. '.dze-fbt__title{margin:0 0 1em;font-size:1.25em;}'
			. '.dze-fbt__grid{list-style:none;margin:0;padding:0;display:grid;gap:16px;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));}'
			. '.dze-fbt__item{display:flex;flex-direction:column;gap:6px;text-align:center;}'
			. '.dze-fbt__link{display:block;color:inherit;text-decoration:none;}'
			. '.dze-fbt__item img{width:100%;height:auto;border-radius:8px;}'
			. '.dze-fbt__name{display:block;margin-top:8px;font-size:.9em;line-height:1.3;}'
			. '.dze-fbt__price{font-weight:600;}'
			. '.dze-fbt__item .button{width:100%;box-sizing:border-box;}'
			. '</style>';
	}
}
