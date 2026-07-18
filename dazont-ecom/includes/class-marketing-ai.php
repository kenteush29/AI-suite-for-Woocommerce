<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI Marketing Assistant.
 *
 * Generates a marketing calendar (a set of promotion "events") for the shop
 * using the Anthropic Claude API, from context detected automatically from
 * the site itself (name, categories, sample products, price range, store
 * country) plus the site's own languages (WPML) and a per-language pool of
 * likely target countries. Each suggestion can be accepted (turned into a
 * real scheduled event in the Marketing Events module), edited, or refused.
 * A front-end shortcode renders the resulting calendar for the home page.
 *
 * Configuration (API key, country pools) lives under Settings → AI Marketing
 * Assistant. The generate/review workflow lives on the Marketing Events page
 * — this class only supplies the two render_*() methods those pages embed.
 *
 * The Anthropic API key is read from the DZE_ANTHROPIC_API_KEY constant
 * (wp-config.php) when defined, otherwise from a settings field. It is never
 * committed to the repository and never sent anywhere except api.anthropic.com.
 */
final class DZE_Marketing_Ai {

	public const NONCE           = 'dze_mai';
	public const OPT_SETTINGS    = 'dze_mai_settings';
	public const OPT_SUGGESTIONS = 'dze_mai_suggestions';

	private const API_URL       = 'https://api.anthropic.com/v1/messages';
	private const API_VERSION   = '2023-06-01';
	private const MODEL         = 'claude-opus-4-8';

	/** Selectable Claude models (label shown in settings). */
	public const MODELS = [
		'claude-opus-4-8'  => 'Claude Opus 4.8 — best quality (default)',
		'claude-sonnet-5'  => 'Claude Sonnet 5 — faster, cheaper',
		'claude-haiku-4-5' => 'Claude Haiku 4.5 — fastest, cheapest',
	];
	private const SHORTCODE     = 'dze_marketing_calendar';
	/** Internal safety cap on one generation call — not exposed as a setting. */
	private const MAX_EVENTS = 20;

	/** Default target-country pool per language, seeded on first use. */
	public const LANGUAGE_COUNTRY_POOLS = [
		'en' => [ 'US', 'GB', 'CA', 'IE', 'AU', 'NZ' ],
		'fr' => [ 'FR', 'BE', 'CH', 'LU', 'CA' ],
		'de' => [ 'DE', 'AT', 'CH' ],
		'es' => [ 'ES', 'MX', 'AR', 'CO', 'CL', 'PE' ],
		'it' => [ 'IT', 'CH' ],
		'pt' => [ 'PT', 'BR' ],
		'nl' => [ 'NL', 'BE' ],
		'pl' => [ 'PL' ],
		'sv' => [ 'SE' ],
		'da' => [ 'DK' ],
		'fi' => [ 'FI' ],
		'nb' => [ 'NO' ],
		'no' => [ 'NO' ],
		'el' => [ 'GR' ],
		'tr' => [ 'TR' ],
		'ru' => [ 'RU' ],
		'ja' => [ 'JP' ],
		'zh' => [ 'CN', 'TW', 'HK', 'SG' ],
		'ko' => [ 'KR' ],
		'ar' => [ 'AE', 'SA', 'EG' ],
		'cs' => [ 'CZ' ],
		'ro' => [ 'RO' ],
		'hu' => [ 'HU' ],
		'he' => [ 'IL' ],
		'th' => [ 'TH' ],
		'vi' => [ 'VN' ],
		'id' => [ 'ID' ],
		'hi' => [ 'IN' ],
	];

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Front end + admin: the calendar shortcode must render on the home page.
		add_shortcode( self::SHORTCODE, [ $this, 'render_calendar_shortcode' ] );

		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_dashboard_setup',    [ $this, 'register_dashboard_widget' ] );
		add_action( 'admin_footer-index.php', [ $this, 'dashboard_fullwidth_script' ] );
		add_action( 'wp_ajax_dze_mai_generate', [ $this, 'ajax_generate' ] );
		add_action( 'wp_ajax_dze_mai_accept',   [ $this, 'ajax_accept' ] );
		add_action( 'wp_ajax_dze_mai_refuse',   [ $this, 'ajax_refuse' ] );
		// No own admin menu: settings render inside DZE_Settings (tab=ai) via
		// render_settings_section(); the generate/review UI renders inside the
		// Marketing Events page via render_calendar_panel().
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public static function get_settings(): array {
		$s = get_option( self::OPT_SETTINGS, [] );
		$s = is_array( $s ) ? $s : [];
		return wp_parse_args( $s, [
			'api_key'       => '',
			'model'         => self::MODEL,
			'use_catalog'   => true, // feed shop categories/best-sellers to the AI.
			'country_pools' => [], // lang_code => [ ISO-3166 alpha-2, ... ]
		] );
	}

	/** Model to call: wp-config constant overrides the settings choice. */
	public static function chosen_model(): string {
		if ( defined( 'DZE_ANTHROPIC_MODEL' ) && DZE_ANTHROPIC_MODEL ) {
			return (string) DZE_ANTHROPIC_MODEL;
		}
		$m = (string) ( self::get_settings()['model'] ?? '' );
		return ( $m !== '' && strpos( $m, 'claude' ) === 0 ) ? $m : self::MODEL;
	}

	/**
	 * Selectable Claude models, id => label. Pulled live from the Anthropic API
	 * (cached 12h) so the list stays current; falls back to the built-in set
	 * when no key is set or the request fails.
	 */
	public static function available_models(): array {
		$key = self::api_key();
		if ( $key === '' ) {
			return self::MODELS;
		}
		$cached = get_transient( 'dze_mai_models' );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}
		$resp = wp_remote_get( 'https://api.anthropic.com/v1/models?limit=100', [
			'timeout' => 15,
			'headers' => [
				'x-api-key'         => $key,
				'anthropic-version' => self::API_VERSION,
			],
		] );
		if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) {
			return self::MODELS;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$out  = [];
		foreach ( (array) ( $body['data'] ?? [] ) as $m ) {
			$id = (string) ( $m['id'] ?? '' );
			if ( $id === '' || strpos( $id, 'claude' ) !== 0 ) {
				continue;
			}
			$out[ $id ] = (string) ( $m['display_name'] ?? $id ); // API lists newest first.
		}
		if ( empty( $out ) ) {
			return self::MODELS;
		}
		set_transient( 'dze_mai_models', $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/** Primary site language code (WPML default, else the site locale). */
	public static function primary_language(): string {
		if ( class_exists( 'DZE_Wpml' ) && DZE_Wpml::is_active() ) {
			$d = DZE_Wpml::default_language();
			if ( $d ) {
				return $d;
			}
		}
		return strtolower( substr( get_locale(), 0, 2 ) );
	}

	public static function api_key(): string {
		if ( defined( 'DZE_ANTHROPIC_API_KEY' ) && DZE_ANTHROPIC_API_KEY ) {
			return (string) DZE_ANTHROPIC_API_KEY;
		}
		return (string) ( self::get_settings()['api_key'] ?? '' );
	}

	public function register_settings(): void {
		register_setting( 'dze_mai_options', self::OPT_SETTINGS, [ 'sanitize_callback' => [ $this, 'sanitize_settings' ], 'autoload' => false ] );
	}

	public function sanitize_settings( $value ): array {
		$in       = is_array( $value ) ? $value : [];
		$existing = self::get_settings();

		// Keep the stored key when the field is left blank (so it isn't wiped).
		$key = trim( (string) ( $in['api_key'] ?? '' ) );
		if ( $key === '' ) {
			$key = (string) $existing['api_key'];
		}

		$pools = [];
		foreach ( (array) ( $in['country_pools'] ?? [] ) as $lang => $codes ) {
			$lang  = sanitize_key( $lang );
			$clean = [];
			foreach ( (array) $codes as $c ) {
				$c = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $c ) );
				if ( strlen( $c ) === 2 ) {
					$clean[ $c ] = $c;
				}
			}
			// Free-text "add more countries" field for this language, if posted.
			if ( ! empty( $in['country_pools_extra'][ $lang ] ) ) {
				foreach ( preg_split( '/[\s,;]+/', (string) $in['country_pools_extra'][ $lang ] ) as $c ) {
					$c = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $c ) );
					if ( strlen( $c ) === 2 ) {
						$clean[ $c ] = $c;
					}
				}
			}
			if ( ! empty( $clean ) ) {
				$pools[ $lang ] = array_values( $clean );
			}
		}

		$model = (string) ( $in['model'] ?? '' );
		if ( $model === '' || strpos( $model, 'claude' ) !== 0 ) {
			$model = self::MODEL;
		}

		// A new key may unlock a different model list — refresh it next read.
		if ( $key !== (string) $existing['api_key'] ) {
			delete_transient( 'dze_mai_models' );
		}

		return [
			'api_key'       => sanitize_text_field( $key ),
			'model'         => $model,
			'use_catalog'   => ! empty( $in['use_catalog'] ),
			'country_pools' => $pools,
		];
	}

	/** Active site languages: WPML's list if active, else the single site locale. */
	public static function active_languages(): array {
		if ( class_exists( 'DZE_Wpml' ) && DZE_Wpml::is_active() ) {
			return DZE_Wpml::get_active_languages();
		}
		$code = strtolower( substr( get_locale(), 0, 2 ) );
		return [ [ 'code' => $code, 'native_name' => strtoupper( $code ), 'flag' => '' ] ];
	}

	/** Countries configured (or, first time, defaulted) for one language. */
	public static function country_pool_for( string $lang ): array {
		$saved = self::get_settings()['country_pools'][ $lang ] ?? null;
		if ( is_array( $saved ) && ! empty( $saved ) ) {
			return $saved;
		}
		return self::LANGUAGE_COUNTRY_POOLS[ $lang ] ?? [];
	}

	// =========================================================================
	// Settings tab (rendered inside DZE_Settings)
	// =========================================================================

	public function render_settings_section(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		// Manual refresh of the auto-detected shop context.
		if ( isset( $_GET['dze_mai_refresh'] ) ) {
			delete_transient( self::CTX_TRANSIENT );
		}
		$settings   = self::get_settings();
		$key_locked = defined( 'DZE_ANTHROPIC_API_KEY' );
		$has_key    = $this->api_key() !== '';
		$languages  = self::active_languages();
		$context    = $this->shop_context_text();
		require DZE_DIR . 'admin/views/marketing-ai-settings.php';
	}

	// =========================================================================
	// Shop context auto-detection
	// =========================================================================

	public const CTX_TRANSIENT = 'dze_mai_shop_context';

	/**
	 * Structured, auto-detected facts about the shop. Categories and products
	 * are ranked by real sales volume (WooCommerce Analytics lookup table),
	 * descending, with graceful fallbacks when analytics hasn't synced. Cached
	 * for an hour; cleared on demand from the settings tab.
	 */
	private function shop_context(): array {
		$cached = get_transient( self::CTX_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$categories = $this->top_categories_by_sales( 15 ) ?: $this->fallback_categories( 15 );
		$products   = $this->top_products_by_sales( 12 ) ?: $this->fallback_products( 12 );
		[ $price_min, $price_max ] = $this->price_range();

		$product_count = 0;
		$counts = wp_count_posts( 'product' );
		if ( $counts && isset( $counts->publish ) ) {
			$product_count = (int) $counts->publish;
		}

		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$country  = '';
		if ( function_exists( 'wc_get_base_location' ) ) {
			$loc     = wc_get_base_location();
			$country = (string) ( $loc['country'] ?? '' );
		}

		$context = [
			'name'          => get_bloginfo( 'name' ),
			'tagline'       => get_bloginfo( 'description' ),
			'categories'    => $categories, // best-selling first
			'products'      => $products,   // best-selling first
			'product_count' => $product_count,
			'price_min'     => $price_min,
			'price_max'     => $price_max,
			'currency'      => $currency,
			'country'       => $country,
		];
		set_transient( self::CTX_TRANSIENT, $context, HOUR_IN_SECONDS );
		return $context;
	}

	/** WooCommerce Analytics product-lookup table name, or null if unavailable. */
	private function analytics_table(): ?string {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) ? $table : null;
	}

	/** Product categories ranked by units sold (all-time), descending. */
	private function top_categories_by_sales( int $limit ): array {
		$table = $this->analytics_table();
		if ( ! $table ) {
			return [];
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is derived from $wpdb->prefix.
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT t.name
			 FROM {$table} l
			 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = l.product_id
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
			 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			 WHERE t.slug != 'uncategorized'
			 GROUP BY t.term_id
			 ORDER BY SUM(l.product_qty) DESC
			 LIMIT %d",
			$limit
		) );
		return array_values( array_filter( array_map( 'trim', (array) $rows ) ) );
	}

	/** Products ranked by units sold (all-time), descending. */
	private function top_products_by_sales( int $limit ): array {
		$table = $this->analytics_table();
		if ( ! $table ) {
			return [];
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is derived from $wpdb->prefix.
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.post_title
			 FROM {$table} l
			 INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
			 WHERE p.post_status = 'publish' AND p.post_type = 'product'
			 GROUP BY l.product_id
			 ORDER BY SUM(l.product_qty) DESC
			 LIMIT %d",
			$limit
		) );
		return array_values( array_filter( array_map( 'trim', (array) $rows ) ) );
	}

	private function fallback_categories( int $limit ): array {
		$out   = [];
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => $limit, 'orderby' => 'count', 'order' => 'DESC' ] );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( $t->slug !== 'uncategorized' ) {
					$out[] = $t->name;
				}
			}
		}
		return $out;
	}

	private function fallback_products( int $limit ): array {
		$out = [];
		if ( function_exists( 'wc_get_products' ) ) {
			foreach ( wc_get_products( [ 'limit' => $limit, 'status' => 'publish', 'orderby' => 'popularity', 'order' => 'DESC' ] ) as $p ) {
				$out[] = $p->get_name();
			}
		}
		return $out;
	}

	/** Min/max published product price, ignoring free (0) items. */
	private function price_range(): array {
		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT MIN(CAST(pm.meta_value AS DECIMAL(10,2))) AS min_p, MAX(CAST(pm.meta_value AS DECIMAL(10,2))) AS max_p
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_price' AND pm.meta_value != '' AND CAST(pm.meta_value AS DECIMAL(10,2)) > 0
			   AND p.post_status = 'publish' AND p.post_type = 'product'"
		);
		if ( $row && $row->min_p !== null ) {
			return [ round( (float) $row->min_p, 2 ), round( (float) $row->max_p, 2 ) ];
		}
		return [ null, null ];
	}

	/** Human-readable version of shop_context(), sent to Claude and shown as a preview. */
	private function shop_context_text(): string {
		$c     = $this->shop_context();
		$lines = [];
		if ( $c['name'] !== '' ) {
			$lines[] = sprintf( 'Store name: %s', $c['name'] );
		}
		if ( $c['tagline'] !== '' ) {
			$lines[] = sprintf( 'Tagline: %s', $c['tagline'] );
		}
		if ( $c['country'] !== '' ) {
			$lines[] = sprintf( 'Store based in: %s', $c['country'] );
		}
		if ( $c['product_count'] > 0 ) {
			$lines[] = sprintf( 'Catalog size: %d published products', $c['product_count'] );
		}
		if ( null !== $c['price_min'] ) {
			$lines[] = sprintf( 'Typical price range: %s–%s %s', $c['price_min'], $c['price_max'], $c['currency'] );
		}
		// Catalog / sales signals are optional — some shops find them noise for a
		// calendar driven by well-known commercial moments.
		if ( ! empty( self::get_settings()['use_catalog'] ) ) {
			if ( ! empty( $c['categories'] ) ) {
				$lines[] = sprintf( 'Product categories (best-selling first): %s', implode( ', ', $c['categories'] ) );
			}
			if ( ! empty( $c['products'] ) ) {
				$lines[] = sprintf( 'Best-selling products (highest first): %s', implode( ', ', $c['products'] ) );
			}
		}
		return implode( "\n", $lines );
	}

	// =========================================================================
	// AJAX: generate the calendar
	// =========================================================================

	public function ajax_generate(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		if ( $this->api_key() === '' ) {
			wp_send_json_error( [ 'message' => __( 'Add your Anthropic API key first, under Settings → AI Marketing Assistant.', 'dazont-ecom' ) ] );
		}

		$start = $this->clean_date( wp_unslash( $_POST['start_date'] ?? '' ) );
		$end   = $this->clean_date( wp_unslash( $_POST['end_date'] ?? '' ) );
		if ( $start === '' || $end === '' ) {
			wp_send_json_error( [ 'message' => __( 'Pick a start and end date for the calendar.', 'dazont-ecom' ) ] );
		}
		if ( $start > $end ) {
			wp_send_json_error( [ 'message' => __( 'The start date must be before the end date.', 'dazont-ecom' ) ] );
		}

		// One language per generation (keeps the calendar simple to read).
		$lang  = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );
		$valid = array_map( static fn( $l ) => $l['code'], self::active_languages() );
		if ( ! in_array( $lang, $valid, true ) ) {
			$lang = self::primary_language();
		}
		// Optional country filter; empty = the language's configured pool (or worldwide).
		$countries = $this->list_codes( explode( ',', (string) wp_unslash( $_POST['countries'] ?? '' ) ), 2 );

		// The Claude call can take up to ~60s; give PHP room beyond typical
		// 30s shared-host limits so it isn't killed mid-request.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		try {
			$events = $this->generate_events( $start, $end, $lang, $countries );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}

		// Store as pending suggestions (prepend the newest).
		$existing = self::get_suggestions();
		foreach ( array_reverse( $events ) as $ev ) {
			$id = 'sug_' . substr( md5( $ev['title'] . '|' . $ev['start_date'] . '|' . wp_json_encode( $ev['countries'] ) ), 0, 10 );
			$ev['id'] = $id;
			$existing = [ $id => $ev ] + $existing;
		}
		self::save_suggestions( $existing );

		$count = count( $events );
		wp_send_json_success( [
			'count'   => $count,
			'message' => $count === 0
				? __( 'No notable commercial moment found in this window — nothing to suggest. Try a longer or different date range.', 'dazont-ecom' )
				/* translators: %d: number of generated marketing events */
				: sprintf( _n( '%d suggestion generated.', '%d suggestions generated.', $count, 'dazont-ecom' ), $count ),
		] );
	}

	/**
	 * Builds the prompt, calls Claude, returns a validated list of events — for
	 * a single language, optionally restricted to given countries.
	 */
	private function generate_events( string $start_date, string $end_date, string $lang, array $countries ): array {
		$native = strtoupper( $lang );
		foreach ( self::active_languages() as $l ) {
			if ( $l['code'] === $lang ) {
				$native = $l['native_name'];
				break;
			}
		}
		if ( empty( $countries ) ) {
			$countries = self::country_pool_for( $lang );
		}
		$country_line = $countries ? implode( ', ', $countries ) : 'all relevant markets worldwide for this language';

		$system = 'You are an expert e-commerce marketing strategist. You build promotional '
			. 'calendars strictly around genuine, widely-recognised commercial moments for the '
			. 'target market — nationally observed sales seasons (e.g. les soldes in France), major '
			. 'public/retail holidays, and global shopping events like Black Friday, Cyber Monday, '
			. 'back-to-school, Valentine\'s Day, Mother\'s/Father\'s Day, and end-of-season clearances. '
			. 'You are conservative: you NEVER invent generic filler promotions ("mid-summer sale", '
			. '"weekend flash deal") just to fill the calendar. If a period genuinely has no notable '
			. 'commercial moment, you return few events — or none at all. Quality over quantity. '
			. 'You reply with JSON only.';

		$schema = '{"events":[{"title":string (<=60 chars),"type":"sale",'
			. '"start_date":"YYYY-MM-DD","end_date":"YYYY-MM-DD","percent":integer 5-70,'
			. '"email_subject":string (a marketing email subject line, <=80 chars),'
			. '"rationale":string (one short sentence naming the real occasion it maps to)}]}';

		$context = $this->shop_context_text();
		if ( $context === '' ) {
			$context = 'No shop details could be auto-detected.';
		}

		$user = sprintf(
			"Shop context (auto-detected from the website — trust it):\n%s\n\n"
			. "Write the calendar for ONE language only: %s (%s).\n"
			. "Target market / countries: %s.\n"
			. "All titles, email subjects and rationales must be written in %s.\n\n"
			. "Plan promotional events strictly between %s and %s (inclusive) — every date must "
			. "fall in this window.\n\n"
			. "Rules:\n"
			. "- Only propose events anchored to a REAL, well-known commercial moment for this "
			. "market and this date window. Name that occasion in the rationale.\n"
			. "- Do NOT pad the calendar. If the window holds only one or two real moments, return "
			. "only those. If it holds none, return an empty events array.\n"
			. "- Never invent vague or generic promotions to reach a quota.\n"
			. "- Events must not overlap in time (each has a clear start and end date).\n"
			. "- Pick a realistic discount percentage for the occasion and this shop's positioning.\n"
			. "- Order events chronologically by start_date. Hard maximum: %d events.\n\n"
			. "Respond with ONLY a JSON object of this exact shape, no markdown, no commentary:\n%s",
			$context,
			$native,
			strtoupper( $lang ),
			$country_line,
			$native,
			$start_date,
			$end_date,
			self::MAX_EVENTS,
			$schema
		);

		$raw    = $this->call_claude( $system, $user );
		$parsed = $this->parse_json( $raw );
		if ( ! is_array( $parsed ) || ! isset( $parsed['events'] ) || ! is_array( $parsed['events'] ) ) {
			throw new RuntimeException( __( 'The AI response could not be understood. Please try again.', 'dazont-ecom' ) );
		}
		// An empty list is a legitimate answer: no notable commercial moment in
		// this window. Let the caller report it plainly instead of erroring.
		if ( empty( $parsed['events'] ) ) {
			return [];
		}

		$clean = [];
		foreach ( $parsed['events'] as $ev ) {
			if ( ! is_array( $ev ) ) {
				continue;
			}
			$start = $this->clean_date( $ev['start_date'] ?? '' );
			$end   = $this->clean_date( $ev['end_date'] ?? '' );
			$title = sanitize_text_field( (string) ( $ev['title'] ?? '' ) );
			if ( $title === '' || $start === '' || $end === '' ) {
				continue;
			}
			// Defensive: drop anything the model placed outside the requested window.
			if ( $start < $start_date || $end > $end_date ) {
				continue;
			}
			$clean[] = [
				'title'         => mb_substr( $title, 0, 80 ),
				'start_date'    => $start,
				'end_date'      => $end,
				'percent'       => min( 90, max( 1, (int) round( (float) ( $ev['percent'] ?? 0 ) ) ) ),
				'countries'     => $countries,   // as chosen at generate time
				'languages'     => [ $lang ],    // one language per generation
				'email_subject' => mb_substr( sanitize_text_field( (string) ( $ev['email_subject'] ?? '' ) ), 0, 120 ),
				'rationale'     => mb_substr( sanitize_text_field( (string) ( $ev['rationale'] ?? '' ) ), 0, 240 ),
			];
		}
		if ( empty( $clean ) ) {
			throw new RuntimeException( __( 'The AI returned no usable events in this date range. Try a wider range.', 'dazont-ecom' ) );
		}
		return $clean;
	}

	private function call_claude( string $system, string $user ): string {
		$response = wp_remote_post( self::API_URL, [
			'timeout' => 90,
			'headers' => [
				'x-api-key'         => $this->api_key(),
				'anthropic-version' => self::API_VERSION,
				'content-type'      => 'application/json',
			],
			'body'    => wp_json_encode( [
				'model'      => self::chosen_model(),
				'max_tokens' => 8000,
				'system'     => $system,
				'messages'   => [ [ 'role' => 'user', 'content' => $user ] ],
			] ),
		] );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = $data['error']['message'] ?? ( 'HTTP ' . $code );
			throw new RuntimeException( sprintf( __( 'Anthropic API error: %s', 'dazont-ecom' ), $msg ) );
		}
		// Concatenate all returned text blocks.
		$text = '';
		foreach ( (array) ( $data['content'] ?? [] ) as $block ) {
			if ( ( $block['type'] ?? '' ) === 'text' ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}
		return $text;
	}

	/** Tolerant JSON extraction: handles code fences and surrounding prose. */
	private function parse_json( string $raw ): ?array {
		$raw = trim( $raw );
		$raw = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $raw );
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		// Fall back to the first {...} block.
		if ( preg_match( '/\{.*\}/s', $raw, $m ) ) {
			$decoded = json_decode( $m[0], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return null;
	}

	private function clean_date( $value ): string {
		$value = trim( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	private function list_codes( $value, int $len ): array {
		$out = [];
		foreach ( (array) $value as $c ) {
			$c = preg_replace( '/[^A-Za-z]/', '', (string) $c );
			if ( $c !== '' ) {
				$out[] = $len === 2 ? strtoupper( substr( $c, 0, 2 ) ) : strtolower( substr( $c, 0, 2 ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	// =========================================================================
	// Suggestions store
	// =========================================================================

	public static function get_suggestions(): array {
		$s = get_option( self::OPT_SUGGESTIONS, [] );
		return is_array( $s ) ? $s : [];
	}

	private static function save_suggestions( array $s ): void {
		update_option( self::OPT_SUGGESTIONS, $s, false );
	}

	// =========================================================================
	// AJAX: accept / refuse
	// =========================================================================

	public function ajax_accept(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$id          = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$suggestions = self::get_suggestions();
		if ( $id === '' || ! isset( $suggestions[ $id ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown suggestion.', 'dazont-ecom' ) ] );
		}

		// The row may carry user edits (accept/modify): trust posted fields, fall
		// back to the stored suggestion.
		$src = $suggestions[ $id ];
		$ev  = [
			'title'         => sanitize_text_field( wp_unslash( $_POST['title'] ?? $src['title'] ) ),
			'percent'       => min( 90, max( 1, (int) ( $_POST['percent'] ?? $src['percent'] ) ) ),
			'start_date'    => $this->clean_date( wp_unslash( $_POST['start_date'] ?? $src['start_date'] ) ),
			'end_date'      => $this->clean_date( wp_unslash( $_POST['end_date'] ?? $src['end_date'] ) ),
			'languages'     => $this->list_codes( explode( ',', (string) ( $_POST['languages'] ?? implode( ',', $src['languages'] ) ) ), 5 ),
			'email_subject' => sanitize_text_field( wp_unslash( $_POST['email_subject'] ?? $src['email_subject'] ) ),
		];
		if ( $ev['start_date'] === '' || $ev['end_date'] === '' ) {
			wp_send_json_error( [ 'message' => __( 'This event needs a valid start and end date.', 'dazont-ecom' ) ] );
		}

		$rule_id = $this->create_sale_rule( $ev );

		unset( $suggestions[ $id ] );
		self::save_suggestions( $suggestions );

		wp_send_json_success( [
			'message'  => __( 'Added to your calendar (as a disabled event — review and enable it below).', 'dazont-ecom' ),
			'rule_id'  => $rule_id,
		] );
	}

	public function ajax_refuse(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$id          = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$suggestions = self::get_suggestions();
		unset( $suggestions[ $id ] );
		self::save_suggestions( $suggestions );
		wp_send_json_success();
	}

	/**
	 * Creates a scheduled-sale rule in the Discounts store (shown on the
	 * Marketing Events page) from an accepted event. Saved DISABLED: only one
	 * event can be active at a time, so the user reviews and enables it there.
	 */
	private function create_sale_rule( array $ev ): string {
		if ( ! class_exists( 'DZE_Discounts' ) ) {
			throw new RuntimeException( __( 'The Marketing Events module is unavailable.', 'dazont-ecom' ) );
		}
		$rules = DZE_Discounts::get_rules();
		$id    = 'ai' . uniqid();

		$rules[ $id ] = [
			'id'            => $id,
			'created_at'    => time(),
			'title'         => $ev['title'],
			'type'          => 'sale',
			'enabled'       => false, // one active event at a time — enable manually.
			'percent'       => (float) $ev['percent'],
			'scope'         => 'all',
			'category_ids'  => [],
			'product_ids'   => [],
			'start'         => $ev['start_date'],
			'end'           => $ev['end_date'],
			'threshold'     => 0.0,
			'banner_enabled'   => true,
			'banner_text'      => $ev['title'],
			'banner_bg'        => '#111111',
			'banner_color'     => '#ffffff',
			'banner_location'  => 'top',
			'product_position' => 'before_product',
			'banner_hooks'     => '',
			'banner_timer'     => true,
			'banner_text_i18n' => [],
			'languages'        => $ev['languages'],
			'hero_swap_enabled'=> false,
			'hero_source_id'   => 0,
			'hero_event_id'    => 0,
			// Marketing-AI metadata (ignored by Discounts, used by the calendar).
			'source'         => 'ai',
			'email_subject'  => $ev['email_subject'],
		];
		update_option( DZE_Discounts::OPTION, $rules, false );
		return $id;
	}

	// =========================================================================
	// Marketing Events panel (rendered at the top of the Marketing Events page)
	// =========================================================================

	public function enqueue_assets( string $hook ): void {
		if ( ! class_exists( 'DZE_Discounts' ) || strpos( $hook, DZE_Discounts::MENU_SLUG_EVENTS ) === false ) {
			return;
		}
		wp_enqueue_script( 'dze-marketing-ai', DZE_URL . 'admin/js/marketing-ai.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-marketing-ai', 'dzeMai', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				'generating' => __( 'Generating…', 'dazont-ecom' ),
				'accepting'  => __( 'Adding…', 'dazont-ecom' ),
				'error'      => __( 'Error', 'dazont-ecom' ),
				'confirmRef'     => __( 'Discard this suggestion?', 'dazont-ecom' ),
				'confirmRefBulk' => __( 'Discard the selected suggestions?', 'dazont-ecom' ),
				'needDates'      => __( 'Pick a start and end date first.', 'dazont-ecom' ),
			],
		] );
	}

	/** Generate button + date range + suggestions review table + calendar view. */
	public function render_calendar_panel(): void {
		$has_key     = $this->api_key() !== '';
		$suggestions = self::get_suggestions();
		$languages   = self::active_languages();
		$primary     = self::primary_language();
		require DZE_DIR . 'admin/views/marketing-ai-panel.php';

		echo '<h2 class="title" style="margin-top:24px;">' . esc_html__( 'Calendar', 'dazont-ecom' ) . '</h2>';
		echo $this->calendar_grid_html( 4 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with per-value escaping internally.
	}

	// =========================================================================
	// Calendar grid (shared by the Marketing Events page and the Dashboard widget)
	// =========================================================================

	/** Scheduled sale rules flattened into calendar events. */
	private function sale_events(): array {
		$events = [];
		if ( ! class_exists( 'DZE_Discounts' ) ) {
			return $events;
		}
		foreach ( DZE_Discounts::get_rules() as $rule ) {
			if ( ( $rule['type'] ?? '' ) !== 'sale' ) {
				continue;
			}
			$s = (string) ( $rule['start'] ?? '' );
			$e = (string) ( $rule['end'] ?? '' );
			if ( $s === '' || $e === '' ) {
				continue;
			}
			$events[] = [
				'start'   => $s,
				'end'     => $e,
				'title'   => (string) ( $rule['title'] ?? '' ),
				'percent' => (int) round( (float) ( $rule['percent'] ?? 0 ) ),
				'enabled' => ! empty( $rule['enabled'] ),
			];
		}
		return $events;
	}

	/**
	 * Renders `$months` consecutive month grids (starting from the current
	 * month) with scheduled events drawn as colored chips on the days they run.
	 */
	public function calendar_grid_html( int $months = 3 ): string {
		$events = $this->sale_events();
		if ( empty( $events ) ) {
			return '<p class="description">' . esc_html__( 'No scheduled events yet — accept an AI suggestion or add an event.', 'dazont-ecom' ) . '</p>';
		}

		$palette = [ '#2563eb', '#0a7040', '#b26a00', '#7c3aed', '#b32d2e', '#0e7490', '#be185d', '#4d7c0f' ];
		usort( $events, static fn( $a, $b ) => strcmp( $a['start'], $b['start'] ) );
		foreach ( $events as $i => &$ev ) {
			$ev['color'] = $palette[ $i % count( $palette ) ];
		}
		unset( $ev );

		$tz          = wp_timezone();
		$now         = new DateTimeImmutable( 'now', $tz );
		$today       = $now->format( 'Y-m-d' );
		$month_start = new DateTimeImmutable( $now->format( 'Y-m-01' ), $tz );
		$sow         = (int) get_option( 'start_of_week', 1 );

		global $wp_locale;
		$weekdays = [];
		for ( $d = 0; $d < 7; $d++ ) {
			$idx        = ( $sow + $d ) % 7;
			$weekdays[] = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $idx ) ) : (string) $idx;
		}

		ob_start();
		echo '<div class="dze-cal">';
		for ( $m = 0; $m < max( 1, $months ); $m++ ) {
			$ms        = $month_start->modify( "+{$m} months" );
			$year      = (int) $ms->format( 'Y' );
			$month     = (int) $ms->format( 'n' );
			$days      = (int) $ms->format( 't' );
			$first_dow = (int) $ms->format( 'w' );
			$lead      = ( $first_dow - $sow + 7 ) % 7;

			echo '<div class="dze-cal__month">';
			echo '<div class="dze-cal__mname">' . esc_html( wp_date( 'F Y', $ms->getTimestamp() ) ) . '</div>';
			echo '<table class="dze-cal__grid"><thead><tr>';
			foreach ( $weekdays as $wd ) {
				echo '<th>' . esc_html( $wd ) . '</th>';
			}
			echo '</tr></thead><tbody><tr>';

			$col = 0;
			for ( $b = 0; $b < $lead; $b++ ) {
				echo '<td class="dze-cal__empty"></td>';
				$col++;
			}
			for ( $day = 1; $day <= $days; $day++ ) {
				if ( 7 === $col ) {
					echo '</tr><tr>';
					$col = 0;
				}
				$ymd = sprintf( '%04d-%02d-%02d', $year, $month, $day );
				echo '<td class="dze-cal__day' . ( $ymd === $today ? ' is-today' : '' ) . '">';
				echo '<span class="dze-cal__num">' . (int) $day . '</span>';
				foreach ( $events as $ev ) {
					if ( $ev['start'] <= $ymd && $ymd <= $ev['end'] ) {
						$tip = $ev['title'] . ' (-' . $ev['percent'] . '%)' . ( $ev['enabled'] ? '' : ' — ' . __( 'disabled', 'dazont-ecom' ) );
						printf(
							'<span class="dze-cal__chip%s" style="background:%s" title="%s">%s</span>',
							$ev['enabled'] ? '' : ' is-off',
							esc_attr( $ev['color'] ),
							esc_attr( $tip ),
							esc_html( $ev['title'] )
						);
					}
				}
				echo '</td>';
				$col++;
			}
			while ( $col < 7 ) {
				echo '<td class="dze-cal__empty"></td>';
				$col++;
			}
			echo '</tr></tbody></table></div>';
		}
		echo '</div>';
		echo '<style>'
			. '.dze-cal{display:flex;flex-wrap:wrap;gap:18px;}'
			. '.dze-cal__month{min-width:250px;flex:1 1 250px;}'
			. '.dze-cal__mname{font-weight:600;margin-bottom:6px;}'
			. '.dze-cal__grid{width:100%;border-collapse:collapse;table-layout:fixed;}'
			. '.dze-cal__grid th{font-size:11px;color:#888;font-weight:600;padding:2px;text-align:center;}'
			. '.dze-cal__grid td{border:1px solid #eee;height:52px;vertical-align:top;padding:2px;overflow:hidden;}'
			. '.dze-cal__day.is-today{background:#fff8e1;}'
			. '.dze-cal__empty{background:#fafafa;}'
			. '.dze-cal__num{color:#777;font-size:11px;}'
			. '.dze-cal__chip{display:block;color:#fff;border-radius:3px;padding:1px 4px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:10px;line-height:1.5;}'
			. '.dze-cal__chip.is-off{opacity:.5;}'
			. '</style>';
		return (string) ob_get_clean();
	}

	// =========================================================================
	// Dashboard widget (WordPress admin home)
	// =========================================================================

	public function register_dashboard_widget(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'dze_marketing_calendar_widget',
			__( 'Marketing calendar', 'dazont-ecom' ),
			[ $this, 'dashboard_widget' ]
		);
	}

	public function dashboard_widget(): void {
		echo $this->calendar_grid_html( 3 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with per-value escaping internally.
		if ( class_exists( 'DZE_Discounts' ) ) {
			$url = add_query_arg( [ 'page' => DZE_Discounts::MENU_SLUG_EVENTS ], admin_url( 'admin.php' ) );
			echo '<p style="margin:10px 0 0;"><a href="' . esc_url( $url ) . '">' . esc_html__( 'Open Marketing Events →', 'dazont-ecom' ) . '</a></p>';
		}
	}

	/**
	 * Promotes the calendar widget to a full-width row above the dashboard
	 * columns, so the month grids have room to breathe. Best-effort: if the
	 * dashboard markup changes, the widget simply stays in its column.
	 */
	public function dashboard_fullwidth_script(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<style>#dze_marketing_calendar_widget{width:100%;box-sizing:border-box;}#dze-cal-fullrow{clear:both;margin:0 0 16px;}</style>
		<script>
		(function () {
			function move() {
				var w = document.getElementById('dze_marketing_calendar_widget');
				var host = document.getElementById('dashboard-widgets');
				if ( ! w || ! host || ! host.parentNode ) { return; }
				var row = document.getElementById('dze-cal-fullrow');
				if ( ! row ) {
					row = document.createElement('div');
					row.id = 'dze-cal-fullrow';
					host.parentNode.insertBefore(row, host);
				}
				row.appendChild(w);
			}
			if ( document.readyState !== 'loading' ) { move(); }
			else { document.addEventListener('DOMContentLoaded', move); }
		}());
		</script>
		<?php
	}

	// =========================================================================
	// Front-end calendar shortcode
	// =========================================================================

	/**
	 * [dze_marketing_calendar] — renders upcoming scheduled sales as a marketing
	 * calendar. Attributes: limit (default 12), past (include finished events:
	 * "0"/"1", default 0).
	 */
	public function render_calendar_shortcode( $atts ): string {
		if ( ! class_exists( 'DZE_Discounts' ) ) {
			return '';
		}
		$atts = shortcode_atts( [ 'limit' => 12, 'past' => 0 ], $atts, self::SHORTCODE );
		$limit    = max( 1, (int) $atts['limit'] );
		$show_past = ! empty( $atts['past'] );
		$today    = current_time( 'Y-m-d' );

		$events = [];
		foreach ( DZE_Discounts::get_rules() as $rule ) {
			if ( ( $rule['type'] ?? '' ) !== 'sale' ) {
				continue;
			}
			$start = (string) ( $rule['start'] ?? '' );
			$end   = (string) ( $rule['end'] ?? '' );
			if ( $start === '' || $end === '' ) {
				continue;
			}
			if ( ! $show_past && $end < $today ) {
				continue;
			}
			$events[] = $rule;
		}
		if ( empty( $events ) ) {
			return '';
		}
		usort( $events, static fn( $a, $b ) => strcmp( (string) $a['start'], (string) $b['start'] ) );
		$events = array_slice( $events, 0, $limit );

		$fmt = static function ( string $ymd ): string {
			$ts = strtotime( $ymd . ' 00:00:00' );
			return $ts ? wp_date( get_option( 'date_format' ), $ts ) : $ymd;
		};

		ob_start();
		echo '<div class="dze-mktcal">';
		foreach ( $events as $rule ) {
			$live    = ( $rule['start'] <= $today && $today <= $rule['end'] && ! empty( $rule['enabled'] ) );
			$percent = (int) round( (float) ( $rule['percent'] ?? 0 ) );
			printf(
				'<div class="dze-mktcal__item%s">'
					. '<div class="dze-mktcal__dates">%s → %s</div>'
					. '<div class="dze-mktcal__title">%s</div>'
					. '<div class="dze-mktcal__meta"><span class="dze-mktcal__pct">-%d%%</span>%s</div>'
					. '</div>',
				$live ? ' is-live' : '',
				esc_html( $fmt( (string) $rule['start'] ) ),
				esc_html( $fmt( (string) $rule['end'] ) ),
				esc_html( (string) ( $rule['title'] ?? '' ) ),
				$percent,
				$live ? ' <span class="dze-mktcal__live">' . esc_html__( 'Live now', 'dazont-ecom' ) . '</span>' : ''
			);
		}
		echo '</div>';
		echo '<style>'
			. '.dze-mktcal{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));}'
			. '.dze-mktcal__item{border:1px solid #e2e2e2;border-radius:10px;padding:14px 16px;background:#fff;}'
			. '.dze-mktcal__item.is-live{border-color:#0a7040;box-shadow:0 0 0 2px rgba(10,112,64,.12);}'
			. '.dze-mktcal__dates{font-size:12px;color:#666;}'
			. '.dze-mktcal__title{font-weight:600;margin:4px 0 8px;}'
			. '.dze-mktcal__meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}'
			. '.dze-mktcal__pct{background:#111;color:#fff;border-radius:6px;padding:2px 8px;font-weight:700;font-size:13px;}'
			. '.dze-mktcal__live{color:#0a7040;font-weight:600;font-size:12px;}'
			. '</style>';
		return (string) ob_get_clean();
	}
}
