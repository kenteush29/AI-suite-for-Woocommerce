<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Marketing & Discounts" module (original implementation).
 *
 * Rule types:
 *   - sale          : scheduled site-wide % sale (shown as a struck-through
 *                     price across catalog + product pages), with an optional
 *                     promo banner at chosen locations.
 *   - cart_qty      : % off the in-scope cart subtotal once total in-scope
 *                     quantity reaches a threshold.
 *   - cart_subtotal : % off the in-scope cart subtotal once it reaches an amount.
 *   - bulk          : % off a product's line once its own quantity reaches N
 *                     (the "buy 2+ of the same product" offer).
 *
 * Scope for every rule: whole store, specific categories, or specific products.
 * Discounts are percentage-only by design.
 *
 * Front-end footprint: the pricing/cart/banner hooks are registered ONLY when
 * at least one rule is currently active (a single autoloaded option read).
 * Nothing is wired on the front end while no promotion is running.
 */
final class DZE_Discounts {

	public const OPTION    = 'dze_discount_rules';
	public const MENU_SLUG = 'dazont-ecom-discounts';
	public const SAVE_NONCE = 'dze_discounts_save';

	/** @var array<string,array>|null Cached active rules for this request. */
	private ?array $active = null;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_menu',            [ $this, 'register_menu' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
			add_action( 'admin_post_dze_discount_save',   [ $this, 'handle_save' ] );
			add_action( 'admin_post_dze_discount_delete', [ $this, 'handle_delete' ] );
			add_action( 'admin_post_dze_discount_toggle', [ $this, 'handle_toggle' ] );
		}

		// The pricing engine must run on the front end, on cart AJAX and on the
		// Store API (REST) — everywhere WooCommerce computes prices/totals.
		add_action( 'init', [ $this, 'register_engine' ] );

		add_shortcode( 'dze_promo_banner', [ $this, 'shortcode_banner' ] );
	}

	// =========================================================================
	// Rule storage
	// =========================================================================

	public static function get_rules(): array {
		$rules = get_option( self::OPTION, [] );
		return is_array( $rules ) ? $rules : [];
	}

	private static function save_rules( array $rules ): void {
		update_option( self::OPTION, $rules, false );
	}

	public static function type_labels(): array {
		return [
			'sale'          => __( 'Scheduled sale (site-wide %)', 'dazont-ecom' ),
			'cart_qty'      => __( 'Cart quantity discount', 'dazont-ecom' ),
			'cart_subtotal' => __( 'Cart subtotal discount', 'dazont-ecom' ),
			'bulk'          => __( 'Bulk (same product, qty ≥ N)', 'dazont-ecom' ),
		];
	}

	/**
	 * Rules that are enabled and (for sales) within their schedule window.
	 */
	public function get_active_rules(): array {
		if ( null !== $this->active ) {
			return $this->active;
		}
		$now    = time();
		$active = [];
		foreach ( self::get_rules() as $id => $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}
			if ( ( $rule['type'] ?? '' ) === 'sale' ) {
				if ( ! empty( $rule['start'] ) && $this->to_ts( $rule['start'] ) > $now ) {
					continue;
				}
				if ( ! empty( $rule['end'] ) && $this->to_ts( $rule['end'] ) < $now ) {
					continue;
				}
			}
			$active[ $id ] = $rule;
		}
		$this->active = $active;
		return $active;
	}

	private function to_ts( string $local_datetime ): int {
		try {
			return ( new DateTimeImmutable( $local_datetime, wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return 0;
		}
	}

	private function rules_of_type( string $type ): array {
		return array_filter( $this->get_active_rules(), static fn( $r ) => ( $r['type'] ?? '' ) === $type );
	}

	// =========================================================================
	// Front-end engine registration
	// =========================================================================

	public function register_engine(): void {
		// Skip pure admin screens (but keep cart AJAX and REST/Store API).
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$active = $this->get_active_rules();
		if ( empty( $active ) ) {
			return; // zero front-end footprint when no promo is running.
		}

		$has_sale = ! empty( $this->rules_of_type( 'sale' ) );
		$has_cart = ! empty( $this->rules_of_type( 'cart_qty' ) ) || ! empty( $this->rules_of_type( 'cart_subtotal' ) );
		$has_bulk = ! empty( $this->rules_of_type( 'bulk' ) );

		if ( $has_sale ) {
			add_filter( 'woocommerce_product_get_price',                 [ $this, 'filter_price' ], 20, 2 );
			add_filter( 'woocommerce_product_get_sale_price',            [ $this, 'filter_price' ], 20, 2 );
			add_filter( 'woocommerce_product_variation_get_price',       [ $this, 'filter_price' ], 20, 2 );
			add_filter( 'woocommerce_product_variation_get_sale_price',  [ $this, 'filter_price' ], 20, 2 );
			add_filter( 'woocommerce_variation_prices_price',            [ $this, 'filter_variation_price' ], 20, 3 );
			add_filter( 'woocommerce_variation_prices_sale_price',       [ $this, 'filter_variation_price' ], 20, 3 );
			add_filter( 'woocommerce_product_is_on_sale',               [ $this, 'filter_is_on_sale' ], 20, 2 );
			add_filter( 'woocommerce_get_variation_prices_hash',        [ $this, 'filter_prices_hash' ], 20, 2 );

			// Coupons: make our dynamic sale honour the coupon "Exclude sale
			// items" setting (WooCommerce otherwise only knows native sales).
			add_filter( 'woocommerce_coupon_is_valid_for_product', [ $this, 'coupon_exclude_sale' ], 20, 4 );

			// Homepage / hero image swap for big events (auto-reverts after).
			if ( $this->build_hero_map() ) {
				add_filter( 'wp_get_attachment_image_src', [ $this, 'swap_image_src' ], 20, 4 );
				add_filter( 'wp_get_attachment_url',       [ $this, 'swap_image_url' ], 20, 2 );
			}

			// Banners at named locations.
			add_action( 'woocommerce_before_single_product', function () { $this->render_location( 'product' ); } );
			add_action( 'woocommerce_before_shop_loop',      function () { $this->render_location( 'shop' ); } );
			add_action( 'woocommerce_before_cart',           function () { $this->render_location( 'cart' ); } );
			add_action( 'wp_body_open', function () {
				if ( is_front_page() || is_home() ) {
					$this->render_location( 'home' );
				}
			} );

			// Site-wide banner "under the menu": Astra fires astra_masthead_after
			// right after the header; fall back to wp_body_open on other themes.
			$sitewide_hook = ( defined( 'ASTRA_THEME_VERSION' ) || function_exists( 'astra_masthead_content' ) )
				? 'astra_masthead_after'
				: 'wp_body_open';
			add_action( $sitewide_hook, function () { $this->render_location( 'sitewide' ); } );

			// User-defined hooks (free choice — e.g. any Astra hook).
			foreach ( $this->rules_of_type( 'sale' ) as $rule ) {
				if ( empty( $rule['banner_enabled'] ) || empty( $rule['banner_hooks'] ) ) {
					continue;
				}
				foreach ( $this->parse_hooks( $rule['banner_hooks'] ) as $hook ) {
					add_action( $hook, function () use ( $rule ) { $this->render_single_banner( $rule ); } );
				}
			}
		}

		if ( $has_cart ) {
			add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_cart_fees' ], 20, 1 );
		}
		if ( $has_bulk ) {
			add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_bulk' ], 20, 1 );
		}
	}

	// =========================================================================
	// Scope + pricing helpers
	// =========================================================================

	private function discounted( float $price, float $percent ): float {
		if ( $price <= 0 || $percent <= 0 ) {
			return $price;
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return round( $price * ( 1 - $percent / 100 ), $decimals );
	}

	private function product_in_scope( array $rule, int $product_id, int $parent_id = 0 ): bool {
		$scope = $rule['scope'] ?? 'all';
		if ( $scope === 'all' ) {
			return true;
		}
		$match_id = $parent_id ?: $product_id;
		if ( $scope === 'categories' ) {
			$cats = array_map( 'intval', $rule['category_ids'] ?? [] );
			return ! empty( $cats ) && has_term( $cats, 'product_cat', $match_id );
		}
		if ( $scope === 'products' ) {
			$ids = array_map( 'intval', $rule['product_ids'] ?? [] );
			return in_array( $match_id, $ids, true ) || in_array( $product_id, $ids, true );
		}
		return false;
	}

	private function sale_percent_for( \WC_Product $product ): float {
		$pid    = $product->get_id();
		$parent = $product->get_parent_id();
		$best   = 0.0;
		foreach ( $this->rules_of_type( 'sale' ) as $rule ) {
			if ( $this->product_in_scope( $rule, $pid, $parent ) ) {
				$best = max( $best, (float) ( $rule['percent'] ?? 0 ) );
			}
		}
		return $best;
	}

	private function bulk_percent_for( \WC_Product $product, int $qty ): float {
		$pid    = $product->get_id();
		$parent = $product->get_parent_id();
		$best   = 0.0;
		foreach ( $this->rules_of_type( 'bulk' ) as $rule ) {
			$threshold = (int) ( $rule['threshold'] ?? 0 );
			if ( $threshold > 0 && $qty >= $threshold && $this->product_in_scope( $rule, $pid, $parent ) ) {
				$best = max( $best, (float) ( $rule['percent'] ?? 0 ) );
			}
		}
		return $best;
	}

	// =========================================================================
	// Price filters (sale)
	// =========================================================================

	public function filter_price( $price, $product ) {
		if ( ! $product instanceof \WC_Product || $price === '' ) {
			return $price;
		}
		$percent = $this->sale_percent_for( $product );
		if ( $percent <= 0 ) {
			return $price;
		}
		$regular = (float) $product->get_regular_price();
		if ( $regular <= 0 ) {
			return $price;
		}
		return $this->discounted( $regular, $percent );
	}

	public function filter_variation_price( $price, $variation, $product ) {
		if ( ! $variation instanceof \WC_Product || $price === '' ) {
			return $price;
		}
		$percent = $this->sale_percent_for( $variation );
		if ( $percent <= 0 ) {
			return $price;
		}
		$regular = (float) $variation->get_regular_price();
		if ( $regular <= 0 ) {
			return $price;
		}
		return $this->discounted( $regular, $percent );
	}

	public function filter_is_on_sale( $on_sale, $product ) {
		if ( $product instanceof \WC_Product && $this->sale_percent_for( $product ) > 0 ) {
			return true;
		}
		return $on_sale;
	}

	public function filter_prices_hash( $hash, $product ) {
		$sig = [];
		foreach ( $this->rules_of_type( 'sale' ) as $id => $rule ) {
			$sig[] = $id . ':' . ( $rule['percent'] ?? 0 );
		}
		if ( $sig ) {
			$hash['dze_sale'] = md5( implode( '|', $sig ) );
		}
		return $hash;
	}

	// =========================================================================
	// Cart discounts
	// =========================================================================

	public function apply_cart_fees( $cart ): void {
		if ( ! $cart instanceof \WC_Cart || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}

		foreach ( [ 'cart_qty', 'cart_subtotal' ] as $type ) {
			foreach ( $this->rules_of_type( $type ) as $rule ) {
				$threshold = (float) ( $rule['threshold'] ?? 0 );
				if ( $threshold <= 0 ) {
					continue;
				}

				$scope_subtotal = 0.0;
				$scope_qty      = 0;
				foreach ( $cart->get_cart() as $item ) {
					$product = $item['data'] ?? null;
					if ( ! $product instanceof \WC_Product ) {
						continue;
					}
					if ( ! $this->product_in_scope( $rule, $product->get_id(), $product->get_parent_id() ) ) {
						continue;
					}
					$scope_subtotal += (float) $product->get_price() * (int) $item['quantity'];
					$scope_qty      += (int) $item['quantity'];
				}

				$meets = ( $type === 'cart_qty' ) ? ( $scope_qty >= $threshold ) : ( $scope_subtotal >= $threshold );
				if ( ! $meets || $scope_subtotal <= 0 ) {
					continue;
				}

				$percent = (float) ( $rule['percent'] ?? 0 );
				$amount  = $scope_subtotal * ( $percent / 100 );
				if ( $amount > 0 ) {
					$label = $rule['title'] !== '' ? $rule['title'] : __( 'Discount', 'dazont-ecom' );
					$cart->add_fee( $label, -1 * round( $amount, wc_get_price_decimals() ), false );
				}
			}
		}
	}

	public function apply_bulk( $cart ): void {
		if ( ! $cart instanceof \WC_Cart || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$qty     = (int) $item['quantity'];
			$percent = $this->bulk_percent_for( $product, $qty );
			if ( $percent <= 0 ) {
				continue;
			}

			// Base off regular price (+ any active site sale) so repeated
			// recalculations stay idempotent — never off the already-set price.
			$regular = (float) $product->get_regular_price();
			if ( $regular <= 0 ) {
				$regular = (float) $product->get_price();
			}
			$sale = $this->sale_percent_for( $product );
			$base = $sale > 0 ? $this->discounted( $regular, $sale ) : $regular;

			$product->set_price( $this->discounted( $base, $percent ) );
		}
	}

	// =========================================================================
	// Banners
	// =========================================================================

	/** @var array<string,bool> Rule ids already rendered this request (dedupe). */
	private static array $rendered = [];
	private static bool $timer_script_done = false;

	public function shortcode_banner( $atts ): string {
		ob_start();
		foreach ( $this->rules_of_type( 'sale' ) as $rule ) {
			$this->render_single_banner( $rule );
		}
		return (string) ob_get_clean();
	}

	private function render_location( string $location ): void {
		foreach ( $this->rules_of_type( 'sale' ) as $rule ) {
			if ( in_array( $location, (array) ( $rule['banner_locations'] ?? [] ), true ) ) {
				$this->render_single_banner( $rule );
			}
		}
	}

	private function render_single_banner( array $rule ): void {
		if ( empty( $rule['banner_enabled'] ) ) {
			return;
		}
		$id = (string) ( $rule['id'] ?? md5( (string) wp_json_encode( $rule ) ) );
		if ( isset( self::$rendered[ $id ] ) ) {
			return; // one banner per rule per page load.
		}

		$text = trim( (string) ( $rule['banner_text'] ?? '' ) );
		if ( $text === '' ) {
			return;
		}

		// WPML: register + translate the banner text (String Translation).
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$name = 'banner_' . $id;
			do_action( 'wpml_register_single_string', 'dazont-ecom', $name, $text );
			$text = (string) apply_filters( 'wpml_translate_single_string', $text, 'dazont-ecom', $name );
		}

		self::$rendered[ $id ] = true;

		$bg    = $rule['banner_bg'] ?? '#111111';
		$color = $rule['banner_color'] ?? '#ffffff';

		$timer = '';
		if ( ! empty( $rule['banner_timer'] ) && ! empty( $rule['end'] ) ) {
			$end_ts = $this->to_ts( $rule['end'] );
			if ( $end_ts > time() ) {
				$timer = ' <span class="dze-timer" data-end="' . esc_attr( (string) $end_ts ) . '"></span>';
				$this->print_timer_script();
			}
		}

		printf(
			'<div class="dze-promo-banner" style="background:%1$s;color:%2$s;text-align:center;padding:10px 16px;font-weight:600;">%3$s%4$s</div>',
			esc_attr( $bg ),
			esc_attr( $color ),
			esc_html( $text ),
			$timer // already-escaped markup built above.
		);
	}

	private function print_timer_script(): void {
		if ( self::$timer_script_done ) {
			return;
		}
		self::$timer_script_done = true;
		?>
<script>
(function(){function u(){var n=Date.now();document.querySelectorAll('.dze-timer').forEach(function(el){var e=parseInt(el.getAttribute('data-end'),10)*1000,d=Math.max(0,e-n),s=Math.floor(d/1000),dd=Math.floor(s/86400);s%=86400;var h=Math.floor(s/3600);s%=3600;var m=Math.floor(s/60);s%=60;el.textContent=(dd>0?dd+'d ':'')+h+'h '+m+'m '+s+'s';});}u();setInterval(u,1000);})();
</script>
		<?php
	}

	// =========================================================================
	// Coupons + hero image swap
	// =========================================================================

	public function coupon_exclude_sale( $valid, $product, $coupon, $values ) {
		if ( $product instanceof \WC_Product
			&& $coupon instanceof \WC_Coupon
			&& $coupon->get_exclude_sale_items()
			&& $this->sale_percent_for( $product ) > 0
		) {
			return false;
		}
		return $valid;
	}

	private ?array $hero_map = null;

	private function build_hero_map(): array {
		if ( null !== $this->hero_map ) {
			return $this->hero_map;
		}
		$map = [];
		foreach ( $this->rules_of_type( 'sale' ) as $rule ) {
			if ( ! empty( $rule['hero_swap_enabled'] ) && ! empty( $rule['hero_source_id'] ) && ! empty( $rule['hero_event_id'] ) ) {
				$map[ (int) $rule['hero_source_id'] ] = (int) $rule['hero_event_id'];
			}
		}
		return $this->hero_map = $map;
	}

	public function swap_image_src( $image, $attachment_id, $size, $icon ) {
		$map = $this->build_hero_map();
		if ( isset( $map[ (int) $attachment_id ] ) ) {
			$new = wp_get_attachment_image_src( $map[ (int) $attachment_id ], $size, $icon );
			if ( $new ) {
				return $new;
			}
		}
		return $image;
	}

	public function swap_image_url( $url, $attachment_id ) {
		$map = $this->build_hero_map();
		if ( isset( $map[ (int) $attachment_id ] ) ) {
			$new = wp_get_attachment_url( $map[ (int) $attachment_id ] );
			if ( $new ) {
				return $new;
			}
		}
		return $url;
	}

	// =========================================================================
	// Admin: menu + assets
	// =========================================================================

	public function register_menu(): void {
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			__( 'Marketing & Discounts', 'dazont-ecom' ),
			__( 'Marketing & Discounts', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::MENU_SLUG ) === false ) {
			return;
		}
		wp_enqueue_style( 'dze-admin', DZE_URL . 'admin/css/admin.css', [], DZE_VERSION );
		wp_enqueue_script( 'dze-discounts', DZE_URL . 'admin/js/discounts.js', [ 'jquery' ], DZE_VERSION, true );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}

		$rules       = self::get_rules();
		$type_labels = self::type_labels();
		$edit_id     = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
		$editing     = $edit_id !== '' && isset( $rules[ $edit_id ] ) ? $rules[ $edit_id ] : null;
		$categories  = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );

		require DZE_DIR . 'admin/views/discounts-page.php';
	}

	// =========================================================================
	// Admin: save / delete / toggle
	// =========================================================================

	public function handle_save(): void {
		check_admin_referer( self::SAVE_NONCE );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}

		$in    = wp_unslash( $_POST );
		$rules = self::get_rules();

		$id   = ! empty( $in['rule_id'] ) ? sanitize_key( $in['rule_id'] ) : 'r' . uniqid();
		$type = in_array( $in['type'] ?? '', array_keys( self::type_labels() ), true ) ? $in['type'] : 'sale';

		$scope = in_array( $in['scope'] ?? 'all', [ 'all', 'categories', 'products' ], true ) ? $in['scope'] : 'all';

		$rule = [
			'id'            => $id,
			'title'         => sanitize_text_field( $in['title'] ?? '' ),
			'type'          => $type,
			'enabled'       => ! empty( $in['enabled'] ),
			'percent'       => min( 100, max( 0, (float) ( $in['percent'] ?? 0 ) ) ),
			'scope'         => $scope,
			'category_ids'  => array_map( 'absint', (array) ( $in['category_ids'] ?? [] ) ),
			'product_ids'   => $this->parse_ids( $in['product_ids'] ?? '' ),
			'start'         => $this->sanitize_dt( $in['start'] ?? '' ),
			'end'           => $this->sanitize_dt( $in['end'] ?? '' ),
			'threshold'     => max( 0, (float) ( $in['threshold'] ?? 0 ) ),
			'banner_enabled'   => ! empty( $in['banner_enabled'] ),
			'banner_text'      => sanitize_text_field( $in['banner_text'] ?? '' ),
			'banner_bg'        => $this->sanitize_hex( $in['banner_bg'] ?? '#111111' ),
			'banner_color'     => $this->sanitize_hex( $in['banner_color'] ?? '#ffffff' ),
			'banner_locations' => array_values( array_intersect(
				[ 'product', 'shop', 'home', 'cart', 'sitewide' ],
				array_map( 'sanitize_key', (array) ( $in['banner_locations'] ?? [] ) )
			) ),
			'banner_hooks'      => sanitize_text_field( $in['banner_hooks'] ?? '' ),
			'banner_timer'      => ! empty( $in['banner_timer'] ),
			'hero_swap_enabled' => ! empty( $in['hero_swap_enabled'] ),
			'hero_source_id'    => absint( $in['hero_source_id'] ?? 0 ),
			'hero_event_id'     => absint( $in['hero_event_id'] ?? 0 ),
		];

		$rules[ $id ] = $rule;
		self::save_rules( $rules );

		wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'saved' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_delete(): void {
		check_admin_referer( 'dze_discount_delete' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$id    = isset( $_GET['rule'] ) ? sanitize_key( wp_unslash( $_GET['rule'] ) ) : '';
		$rules = self::get_rules();
		unset( $rules[ $id ] );
		self::save_rules( $rules );
		wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'deleted' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_toggle(): void {
		check_admin_referer( 'dze_discount_toggle' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$id    = isset( $_GET['rule'] ) ? sanitize_key( wp_unslash( $_GET['rule'] ) ) : '';
		$rules = self::get_rules();
		if ( isset( $rules[ $id ] ) ) {
			$rules[ $id ]['enabled'] = empty( $rules[ $id ]['enabled'] );
			self::save_rules( $rules );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ---- sanitizers ----

	private function parse_hooks( $raw ): array {
		$parts = preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$hooks = [];
		foreach ( $parts as $p ) {
			$p = preg_replace( '/[^a-z0-9_]/', '', strtolower( $p ) );
			if ( $p !== '' ) {
				$hooks[] = $p;
			}
		}
		return array_values( array_unique( $hooks ) );
	}

	private function parse_ids( $raw ): array {
		$parts = preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_unique( array_map( 'absint', $parts ) ) );
	}

	private function sanitize_dt( string $value ): string {
		$value = trim( $value );
		// Expected format from <input type="datetime-local">: YYYY-MM-DDTHH:MM
		return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ? $value : '';
	}

	private function sanitize_hex( string $value ): string {
		$value = sanitize_hex_color( $value );
		return $value ?: '#111111';
	}
}
