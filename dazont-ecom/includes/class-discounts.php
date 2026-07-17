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
		$now          = time();
		$current_lang = DZE_Wpml::current_language();
		$active       = [];
		foreach ( self::get_rules() as $id => $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}
			if ( ( $rule['type'] ?? '' ) === 'sale' ) {
				[ $start, $end ] = $this->window_ts( $rule );
				if ( $now < $start || $now > $end ) {
					continue;
				}
			}
			// Per-language activation: the default language is always eligible;
			// a non-default language is eligible only when the banner text is
			// translated for it (see rule_effective_languages). WPML only.
			if ( $current_lang !== '' && ! in_array( $current_lang, $this->rule_effective_languages( $rule ), true ) ) {
				continue;
			}
			$active[ $id ] = $rule;
		}
		$this->active = $active;
		return $active;
	}

	/**
	 * A rule's active window as [ start_ts, end_ts ] in the site timezone.
	 * Dates are day-granular: start snaps to 00:00:00, end to 23:59:59
	 * (inclusive). Missing bounds become ±infinity.
	 */
	public function window_ts( array $rule ): array {
		$tz    = wp_timezone();
		$start = PHP_INT_MIN;
		$end   = PHP_INT_MAX;
		try {
			if ( ! empty( $rule['start'] ) ) {
				$start = ( new DateTimeImmutable( $rule['start'] . ' 00:00:00', $tz ) )->getTimestamp();
			}
			if ( ! empty( $rule['end'] ) ) {
				$end = ( new DateTimeImmutable( $rule['end'] . ' 23:59:59', $tz ) )->getTimestamp();
			}
		} catch ( \Exception $e ) {
			return [ PHP_INT_MIN, PHP_INT_MAX ];
		}
		return [ $start, $end ];
	}

	/** Status label for a rule: active | scheduled | passed | inactive. */
	public function rule_status( array $rule ): string {
		if ( empty( $rule['enabled'] ) ) {
			return 'inactive';
		}
		if ( ( $rule['type'] ?? '' ) === 'sale' ) {
			[ $start, $end ] = $this->window_ts( $rule );
			$now = time();
			if ( $now < $start ) {
				return 'scheduled';
			}
			if ( $now > $end ) {
				return 'passed';
			}
		}
		return 'active';
	}

	private function rules_of_type( string $type ): array {
		return array_filter( $this->get_active_rules(), static fn( $r ) => ( $r['type'] ?? '' ) === $type );
	}

	/**
	 * Language codes a rule is effectively active in (WPML). The default
	 * language is always included; a non-default language is included only when
	 * the rule's banner text is translated for it. Empty when WPML is inactive.
	 */
	public function rule_effective_languages( array $rule ): array {
		if ( ! DZE_Wpml::is_active() ) {
			return [];
		}
		$default    = DZE_Wpml::default_language();
		$selected   = (array) ( $rule['languages'] ?? [] );
		$i18n       = (array) ( $rule['banner_text_i18n'] ?? [] );
		$has_banner = ! empty( $rule['banner_enabled'] ) && trim( (string) ( $rule['banner_text'] ?? '' ) ) !== '';

		$out = [];
		foreach ( DZE_Wpml::get_active_languages() as $lang ) {
			$code = $lang['code'];
			if ( ! empty( $selected ) && ! in_array( $code, $selected, true ) ) {
				continue; // not targeted by this rule.
			}
			if ( $code !== $default && $has_banner && empty( $i18n[ $code ] ) ) {
				continue; // non-default language requires a translated banner.
			}
			$out[] = $code;
		}
		return $out;
	}

	/**
	 * Standard product-page banner positions → [hook, priority].
	 */
	public static function product_positions(): array {
		return [
			'before_product'     => [ 'label' => __( 'Above the product (default)', 'dazont-ecom' ), 'hook' => 'woocommerce_before_single_product', 'prio' => 10 ],
			'before_title'       => [ 'label' => __( 'Before the title', 'dazont-ecom' ),            'hook' => 'woocommerce_single_product_summary', 'prio' => 4 ],
			'after_title'        => [ 'label' => __( 'After the title', 'dazont-ecom' ),             'hook' => 'woocommerce_single_product_summary', 'prio' => 6 ],
			'before_price'       => [ 'label' => __( 'Before the price', 'dazont-ecom' ),            'hook' => 'woocommerce_single_product_summary', 'prio' => 9 ],
			'before_add_to_cart' => [ 'label' => __( 'Before Add to cart', 'dazont-ecom' ),         'hook' => 'woocommerce_single_product_summary', 'prio' => 29 ],
			'after_add_to_cart'  => [ 'label' => __( 'After Add to cart', 'dazont-ecom' ),          'hook' => 'woocommerce_single_product_summary', 'prio' => 31 ],
		];
	}

	/** True when two sale windows overlap (open-ended = ±infinity). */
	private function sale_windows_overlap( array $a, array $b ): bool {
		[ $a_start, $a_end ] = $this->window_ts( $a );
		[ $b_start, $b_end ] = $this->window_ts( $b );
		return $a_start <= $b_end && $b_start <= $a_end;
	}

	/**
	 * Returns the title of an enabled sale whose window overlaps $rule, or ''
	 * (used to forbid two promotions running at the same time).
	 */
	private function conflicting_sale( array $rule ): string {
		if ( ( $rule['type'] ?? '' ) !== 'sale' ) {
			return '';
		}
		foreach ( self::get_rules() as $oid => $other ) {
			if ( $oid === ( $rule['id'] ?? '' ) ) {
				continue;
			}
			if ( ( $other['type'] ?? '' ) === 'sale' && ! empty( $other['enabled'] ) && $this->sale_windows_overlap( $rule, $other ) ) {
				return (string) ( $other['title'] !== '' ? $other['title'] : $oid );
			}
		}
		return '';
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
			add_filter( 'woocommerce_get_variation_prices_hash',        [ $this, 'filter_prices_hash' ], 20, 2 );

			// Coupons: make our dynamic sale honour the coupon "Exclude sale
			// items" setting (WooCommerce otherwise only knows native sales).
			add_filter( 'woocommerce_coupon_is_valid_for_product', [ $this, 'coupon_exclude_sale' ], 20, 4 );

			// Homepage / hero image swap for big events (auto-reverts after).
			if ( $this->build_hero_map() ) {
				add_filter( 'wp_get_attachment_image_src', [ $this, 'swap_image_src' ], 20, 4 );
				add_filter( 'wp_get_attachment_url',       [ $this, 'swap_image_url' ], 20, 2 );
			}

			// Banner: single location per rule (Top / Below header / Product).
			$positions = self::product_positions();
			add_action( 'wp_body_open', function () { $this->render_location( 'top' ); } );
			if ( defined( 'ASTRA_THEME_VERSION' ) || function_exists( 'astra_header_markup' ) ) {
				add_action( 'astra_header_after', function () { $this->render_location( 'below_header' ); } );
			}
			foreach ( $this->rules_of_type( 'sale' ) as $rule ) {
				if ( empty( $rule['banner_enabled'] ) ) {
					continue;
				}
				// Product-page banner at the chosen standard WooCommerce position.
				if ( ( $rule['banner_location'] ?? '' ) === 'product' ) {
					$pos = $positions[ $rule['product_position'] ?? 'before_product' ] ?? $positions['before_product'];
					add_action( $pos['hook'], function () use ( $rule ) { $this->render_single_banner( $rule ); }, $pos['prio'] );
				}
				// Optional user-defined hooks (free choice — any Astra hook).
				if ( ! empty( $rule['banner_hooks'] ) ) {
					foreach ( $this->parse_hooks( $rule['banner_hooks'] ) as $hook ) {
						add_action( $hook, function () use ( $rule ) { $this->render_single_banner( $rule ); } );
					}
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
		if ( ! $product instanceof \WC_Product ) {
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
		if ( ! $variation instanceof \WC_Product ) {
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
			if ( ( $rule['banner_location'] ?? '' ) === $location ) {
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

		// Banner text, translated in-plugin per WPML language.
		$text = trim( (string) ( $rule['banner_text'] ?? '' ) );
		if ( DZE_Wpml::is_active() ) {
			$lang = DZE_Wpml::current_language();
			$i18n = (array) ( $rule['banner_text_i18n'] ?? [] );
			if ( $lang !== '' && ! empty( $i18n[ $lang ] ) ) {
				$text = trim( (string) $i18n[ $lang ] );
			}
		}
		if ( $text === '' ) {
			return;
		}

		self::$rendered[ $id ] = true;

		$bg    = $rule['banner_bg'] ?? '#111111';
		$color = $rule['banner_color'] ?? '#ffffff';

		$timer = '';
		if ( ! empty( $rule['banner_timer'] ) && ! empty( $rule['end'] ) ) {
			[ , $end_ts ] = $this->window_ts( $rule );
			if ( $end_ts > time() ) {
				$timer = ' <span class="dze-timer" data-end="' . esc_attr( (string) $end_ts ) . '"></span>';
				$this->print_timer_script();
			}
		}

		// Typography is intentionally left to the theme (no font-size / weight
		// override) — only the background, colour and padding are set.
		printf(
			'<div class="dze-promo-banner" style="background:%1$s;color:%2$s;text-align:center;padding:10px 16px;">%3$s%4$s</div>',
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
		wp_enqueue_media(); // Media Library picker for the hero image fields.
		wp_enqueue_script( 'dze-discounts', DZE_URL . 'admin/js/discounts.js', [ 'jquery' ], DZE_VERSION, true );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}

		$rules       = self::get_rules();
		$type_labels = self::type_labels();
		$languages   = DZE_Wpml::get_active_languages();

		$edit_id = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
		$is_new  = isset( $_GET['new'] );
		$editing = ( $edit_id !== '' && isset( $rules[ $edit_id ] ) ) ? $rules[ $edit_id ] : null;

		// Edit / create screen is a separate page from the list.
		if ( $is_new || $editing ) {
			$categories       = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
			$product_positions = self::product_positions();
			require DZE_DIR . 'admin/views/discounts-edit.php';
			return;
		}

		$notice = get_transient( 'dze_discount_notice' );
		if ( $notice ) {
			delete_transient( 'dze_discount_notice' );
		}
		require DZE_DIR . 'admin/views/discounts-page.php';
	}

	/** Public helper for the list view: flags of languages a rule is active in. */
	public function rule_language_flags( array $rule ): array {
		if ( ! DZE_Wpml::is_active() ) {
			return [];
		}
		$codes = $this->rule_effective_languages( $rule );
		$flags = [];
		foreach ( DZE_Wpml::get_active_languages() as $lang ) {
			if ( in_array( $lang['code'], $codes, true ) ) {
				$flags[] = $lang;
			}
		}
		return $flags;
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

		$id      = ! empty( $in['rule_id'] ) ? sanitize_key( $in['rule_id'] ) : 'r' . uniqid();
		$type    = in_array( $in['type'] ?? '', array_keys( self::type_labels() ), true ) ? $in['type'] : 'sale';
		$scope   = in_array( $in['scope'] ?? 'all', [ 'all', 'categories', 'products' ], true ) ? $in['scope'] : 'all';
		$b_loc   = in_array( $in['banner_location'] ?? '', [ 'top', 'below_header', 'product' ], true ) ? $in['banner_location'] : 'top';
		$b_pos   = array_key_exists( $in['product_position'] ?? '', self::product_positions() ) ? $in['product_position'] : 'before_product';
		$created = ( isset( $rules[ $id ]['created_at'] ) && $rules[ $id ]['created_at'] ) ? (int) $rules[ $id ]['created_at'] : time();

		$rule = [
			'id'            => $id,
			'created_at'    => $created,
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
			'banner_location'  => $b_loc,
			'product_position' => $b_pos,
			'banner_hooks'      => sanitize_text_field( $in['banner_hooks'] ?? '' ),
			'banner_timer'      => ! empty( $in['banner_timer'] ),
			'banner_text_i18n'  => $this->sanitize_i18n( $in['banner_text_i18n'] ?? [] ),
			'languages'         => array_values( array_filter( array_map( 'sanitize_key', (array) ( $in['languages'] ?? [] ) ) ) ),
			'hero_swap_enabled' => ! empty( $in['hero_swap_enabled'] ),
			'hero_source_id'    => absint( $in['hero_source_id'] ?? 0 ),
			'hero_event_id'     => absint( $in['hero_event_id'] ?? 0 ),
		];

		// Only one promotion (sale) may be active at a time: if this enabled sale
		// overlaps another enabled sale, save it disabled and report the clash.
		$args = [ 'page' => self::MENU_SLUG, 'saved' => 1 ];
		if ( $rule['enabled'] ) {
			$clash = $this->conflicting_sale( $rule );
			if ( $clash !== '' ) {
				$rule['enabled'] = false;
				set_transient( 'dze_discount_notice', sprintf(
					/* translators: %s: conflicting promotion title */
					__( 'Saved as disabled: its dates overlap the active promotion "%s". Only one promotion can run at a time.', 'dazont-ecom' ),
					$clash
				), 60 );
				$args['saved'] = 0;
			}
		}

		$rules[ $id ] = $rule;
		self::save_rules( $rules );

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
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
			$enabling = empty( $rules[ $id ]['enabled'] );
			// Enabling a sale that overlaps another active sale is forbidden.
			if ( $enabling ) {
				$clash = $this->conflicting_sale( $rules[ $id ] );
				if ( $clash !== '' ) {
					set_transient( 'dze_discount_notice', sprintf(
						/* translators: %s: conflicting promotion title */
						__( 'Cannot enable: its dates overlap the active promotion "%s". Only one promotion can run at a time.', 'dazont-ecom' ),
						$clash
					), 60 );
					wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG ], admin_url( 'admin.php' ) ) );
					exit;
				}
			}
			$rules[ $id ]['enabled'] = $enabling;
			self::save_rules( $rules );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ---- sanitizers ----

	private function sanitize_i18n( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$clean = [];
		foreach ( $value as $lang => $text ) {
			$lang = sanitize_key( $lang );
			$text = sanitize_text_field( $text );
			if ( $lang !== '' && $text !== '' ) {
				$clean[ $lang ] = $text;
			}
		}
		return $clean;
	}

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
		// Day-granular schedule from <input type="date">: YYYY-MM-DD.
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	private function sanitize_hex( string $value ): string {
		$value = sanitize_hex_color( $value );
		return $value ?: '#111111';
	}
}
