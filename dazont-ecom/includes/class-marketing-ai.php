<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI Marketing Assistant.
 *
 * Generates a marketing calendar (a set of promotion "events") from the shop
 * profile — shop type, target countries, languages — using the Anthropic
 * Claude API. Each suggestion can be accepted (turned into a real scheduled
 * sale in the Discounts module), edited, or refused. A front-end shortcode
 * renders the resulting calendar for the site's home page.
 *
 * The Anthropic API key is read from the DZE_ANTHROPIC_API_KEY constant
 * (wp-config.php) when defined, otherwise from a settings field. It is never
 * committed to the repository and never sent anywhere except api.anthropic.com.
 */
final class DZE_Marketing_Ai {

	public const MENU_SLUG       = 'dazont-ecom-marketing-ai';
	public const NONCE           = 'dze_mai';
	public const OPT_SETTINGS    = 'dze_mai_settings';
	public const OPT_SUGGESTIONS = 'dze_mai_suggestions';

	private const API_URL       = 'https://api.anthropic.com/v1/messages';
	private const API_VERSION   = '2023-06-01';
	private const MODEL         = 'claude-opus-4-8';
	private const SHORTCODE     = 'dze_marketing_calendar';

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
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_dze_mai_generate', [ $this, 'ajax_generate' ] );
		add_action( 'wp_ajax_dze_mai_accept',   [ $this, 'ajax_accept' ] );
		add_action( 'wp_ajax_dze_mai_refuse',   [ $this, 'ajax_refuse' ] );
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public static function get_settings(): array {
		$s = get_option( self::OPT_SETTINGS, [] );
		$s = is_array( $s ) ? $s : [];
		return wp_parse_args( $s, [
			'api_key'         => '',
			'shop_type'       => '',
			'countries'       => '',
			'languages'       => '',
			'horizon_months'  => 6,
			'max_events'      => 8,
		] );
	}

	private function api_key(): string {
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
		return [
			'api_key'        => sanitize_text_field( $key ),
			'shop_type'      => sanitize_textarea_field( $in['shop_type'] ?? '' ),
			'countries'      => sanitize_text_field( $in['countries'] ?? '' ),
			'languages'      => sanitize_text_field( $in['languages'] ?? '' ),
			'horizon_months' => min( 24, max( 1, (int) ( $in['horizon_months'] ?? 6 ) ) ),
			'max_events'     => min( 24, max( 1, (int) ( $in['max_events'] ?? 8 ) ) ),
		];
	}

	/** Best-effort default target countries, drawn from the GMC configuration. */
	private function default_countries(): array {
		$out = [];
		if ( class_exists( 'DZE_Gmc' ) ) {
			foreach ( DZE_Gmc::get_accounts() as $acc ) {
				foreach ( DZE_Gmc::account_countries( $acc ) as $c ) {
					$out[ $c ] = $c;
				}
			}
		}
		return array_values( $out );
	}

	/** Best-effort default languages, from WPML or the site locale. */
	private function default_languages(): array {
		if ( class_exists( 'DZE_Wpml' ) && DZE_Wpml::is_active() ) {
			return array_map( static fn( $l ) => $l['code'], DZE_Wpml::get_active_languages() );
		}
		return [ strtolower( substr( get_locale(), 0, 2 ) ) ];
	}

	private function csv_codes( string $csv, int $len ): array {
		$out = [];
		foreach ( preg_split( '/[\s,;]+/', $csv ) as $c ) {
			$c = preg_replace( '/[^A-Za-z]/', '', (string) $c );
			if ( $c !== '' ) {
				$out[] = $len === 2 ? strtoupper( substr( $c, 0, 2 ) ) : strtolower( substr( $c, 0, 2 ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	// =========================================================================
	// Admin page
	// =========================================================================

	public function register_menu(): void {
		add_submenu_page(
			class_exists( 'DZE_Restock' ) ? DZE_Restock::MENU_SLUG : 'options-general.php',
			__( 'AI Marketing Assistant', 'dazont-ecom' ),
			__( 'AI Marketing', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::MENU_SLUG ) === false ) {
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
				'confirmRef' => __( 'Discard this suggestion?', 'dazont-ecom' ),
			],
		] );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$settings     = self::get_settings();
		$suggestions  = self::get_suggestions();
		$key_locked   = defined( 'DZE_ANTHROPIC_API_KEY' );
		$has_key      = $this->api_key() !== '';
		$def_country  = implode( ', ', $this->default_countries() );
		$def_lang     = implode( ', ', $this->default_languages() );
		require DZE_DIR . 'admin/views/marketing-ai-page.php';
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
	// AJAX: generate the calendar
	// =========================================================================

	public function ajax_generate(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$s = self::get_settings();
		if ( $this->api_key() === '' ) {
			wp_send_json_error( [ 'message' => __( 'Add your Anthropic API key first.', 'dazont-ecom' ) ] );
		}

		$countries = $this->csv_codes( $s['countries'], 2 ) ?: $this->default_countries();
		$languages = $this->csv_codes( $s['languages'], 5 ) ?: $this->default_languages();
		if ( empty( $countries ) ) {
			wp_send_json_error( [ 'message' => __( 'Set at least one target country.', 'dazont-ecom' ) ] );
		}

		try {
			$events = $this->generate_events( $s, $countries, $languages );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}

		// Store as pending suggestions (prepend the newest).
		$existing = self::get_suggestions();
		foreach ( array_reverse( $events ) as $ev ) {
			$id = 'sug_' . substr( md5( $ev['title'] . '|' . $ev['start_date'] . '|' . wp_json_encode( $ev['countries'] ) ), 0, 10 );
			$ev['id']            = $id;
			$ev['klaviyo_email'] = false;
			$existing = [ $id => $ev ] + $existing;
		}
		self::save_suggestions( $existing );

		wp_send_json_success( [
			'count'   => count( $events ),
			/* translators: %d: number of generated marketing events */
			'message' => sprintf( __( '%d suggestions generated.', 'dazont-ecom' ), count( $events ) ),
		] );
	}

	/** Builds the prompt, calls Claude, returns a validated list of events. */
	private function generate_events( array $s, array $countries, array $languages ): array {
		$today   = current_time( 'Y-m-d' );
		$horizon = (int) $s['horizon_months'];
		$max     = (int) $s['max_events'];
		$shop    = trim( (string) $s['shop_type'] ) ?: __( 'a general WooCommerce online store', 'dazont-ecom' );

		$system = 'You are an expert e-commerce marketing strategist. You design realistic, '
			. 'high-impact promotional calendars tied to real commercial moments (seasonal sales, '
			. 'public holidays, shopping events like Black Friday and Cyber Monday, back-to-school, '
			. 'end-of-season clearances) appropriate to each target country. You reply with JSON only.';

		$schema = '{"events":[{"title":string (<=60 chars),"type":"sale",'
			. '"start_date":"YYYY-MM-DD","end_date":"YYYY-MM-DD","percent":integer 5-70,'
			. '"countries":[ISO 3166-1 alpha-2 codes],"languages":[ISO 639-1 codes],'
			. '"email_subject":string (a marketing email subject line, <=80 chars),'
			. '"rationale":string (one sentence: why this event, for this audience)}]}';

		$user = sprintf(
			"Shop profile: %s\nTarget countries: %s\nContent languages: %s\nToday's date: %s\n"
			. "Plan the next %d months. Propose up to %d distinct promotional events.\n\n"
			. "Rules:\n"
			. "- Every date must be in the future relative to today and within the next %d months.\n"
			. "- Events must not overlap in time (each has a clear start and end date).\n"
			. "- Tie each event to a real commercial moment relevant to its target countries.\n"
			. "- Pick a realistic discount percentage for the occasion.\n"
			. "- Use only the target countries and languages listed above.\n"
			. "- Order events chronologically by start_date.\n\n"
			. "Respond with ONLY a JSON object of this exact shape, no markdown, no commentary:\n%s",
			$shop,
			implode( ', ', $countries ),
			implode( ', ', $languages ),
			$today,
			$horizon,
			$max,
			$horizon,
			$schema
		);

		$raw    = $this->call_claude( $system, $user );
		$parsed = $this->parse_json( $raw );
		if ( ! is_array( $parsed ) || empty( $parsed['events'] ) || ! is_array( $parsed['events'] ) ) {
			throw new RuntimeException( __( 'The AI response could not be understood. Please try again.', 'dazont-ecom' ) );
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
			$clean[] = [
				'title'         => mb_substr( $title, 0, 80 ),
				'start_date'    => $start,
				'end_date'      => $end,
				'percent'       => min( 90, max( 1, (int) round( (float) ( $ev['percent'] ?? 0 ) ) ) ),
				'countries'     => $this->list_codes( $ev['countries'] ?? [], 2 ),
				'languages'     => $this->list_codes( $ev['languages'] ?? [], 5 ),
				'email_subject' => mb_substr( sanitize_text_field( (string) ( $ev['email_subject'] ?? '' ) ), 0, 120 ),
				'rationale'     => mb_substr( sanitize_text_field( (string) ( $ev['rationale'] ?? '' ) ), 0, 240 ),
			];
		}
		if ( empty( $clean ) ) {
			throw new RuntimeException( __( 'The AI returned no usable events. Please try again.', 'dazont-ecom' ) );
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
				'model'      => defined( 'DZE_ANTHROPIC_MODEL' ) ? DZE_ANTHROPIC_MODEL : self::MODEL,
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
			'klaviyo_email' => ! empty( $_POST['klaviyo_email'] ),
		];
		if ( $ev['start_date'] === '' || $ev['end_date'] === '' ) {
			wp_send_json_error( [ 'message' => __( 'This event needs a valid start and end date.', 'dazont-ecom' ) ] );
		}

		$rule_id = $this->create_sale_rule( $ev );

		unset( $suggestions[ $id ] );
		self::save_suggestions( $suggestions );

		wp_send_json_success( [
			'message'  => __( 'Added to your calendar (as a disabled sale — review and enable it in Marketing & Discounts).', 'dazont-ecom' ),
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
	 * Creates a scheduled-sale rule in the Discounts module from an accepted
	 * event. Saved DISABLED: the Discounts module only allows one active sale at
	 * a time, so the user reviews and enables it there. Carries `source=ai` and
	 * the `klaviyo_email` tracking flag.
	 */
	private function create_sale_rule( array $ev ): string {
		if ( ! class_exists( 'DZE_Discounts' ) ) {
			throw new RuntimeException( __( 'The Discounts module is unavailable.', 'dazont-ecom' ) );
		}
		$rules = DZE_Discounts::get_rules();
		$id    = 'ai' . uniqid();

		$rules[ $id ] = [
			'id'            => $id,
			'created_at'    => time(),
			'title'         => $ev['title'],
			'type'          => 'sale',
			'enabled'       => false, // one active sale at a time — enable manually.
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
			'klaviyo_email'  => (bool) $ev['klaviyo_email'],
			'email_subject'  => $ev['email_subject'],
		];
		update_option( DZE_Discounts::OPTION, $rules, false );
		return $id;
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
			$klaviyo = ! empty( $rule['klaviyo_email'] );
			printf(
				'<div class="dze-mktcal__item%s">'
					. '<div class="dze-mktcal__dates">%s → %s</div>'
					. '<div class="dze-mktcal__title">%s</div>'
					. '<div class="dze-mktcal__meta"><span class="dze-mktcal__pct">-%d%%</span>%s%s</div>'
					. '</div>',
				$live ? ' is-live' : '',
				esc_html( $fmt( (string) $rule['start'] ) ),
				esc_html( $fmt( (string) $rule['end'] ) ),
				esc_html( (string) ( $rule['title'] ?? '' ) ),
				$percent,
				$live ? ' <span class="dze-mktcal__live">' . esc_html__( 'Live now', 'dazont-ecom' ) . '</span>' : '',
				$klaviyo ? ' <span class="dze-mktcal__mail">' . esc_html__( '✉ Email', 'dazont-ecom' ) . '</span>' : ''
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
			. '.dze-mktcal__mail{color:#555;font-size:12px;}'
			. '</style>';
		return (string) ob_get_clean();
	}
}
