<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google Merchant Center sync for promotions (Content API for Shopping v2.1).
 *
 * One Merchant Center account per language: each scheduled-sale promotion is
 * pushed as a GMC "promotion" to the account mapped to the language it is
 * active in. Authentication uses a Google service account (JWT → OAuth2 access
 * token) — no bundled Google client library.
 *
 * Credentials are read from the DZE_GMC_SERVICE_ACCOUNT constant (a file path
 * or the raw JSON) when defined, otherwise from a settings field. They are
 * never committed to the repository.
 */
final class DZE_Gmc {

	public const MENU_SLUG   = 'dazont-ecom-gmc';
	public const NONCE       = 'dze_gmc';
	public const CRON_HOOK   = 'dze_gmc_sync';
	public const OPT_ACCOUNTS    = 'dze_gmc_accounts';
	public const OPT_CREDENTIALS = 'dze_gmc_credentials';
	public const OPT_OAUTH       = 'dze_gmc_oauth';

	private const API_BASE   = 'https://shoppingcontent.googleapis.com/content/v2.1';
	private const SCOPE      = 'https://www.googleapis.com/auth/content';
	private const OAUTH_SCOPE = 'https://www.googleapis.com/auth/content https://www.googleapis.com/auth/userinfo.email';
	private const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
	private const TOKEN_TTL  = 3300; // seconds

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON_HOOK, [ $this, 'cron_sync_all' ] );
		add_action( 'init',          [ $this, 'maybe_schedule_cron' ] );

		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_dze_gmc_sync',      [ $this, 'ajax_sync' ] );
		add_action( 'wp_ajax_dze_gmc_test',      [ $this, 'ajax_test' ] );
		add_action( 'admin_post_dze_gmc_oauth',       [ $this, 'handle_oauth_callback' ] );
		add_action( 'admin_post_dze_gmc_disconnect',  [ $this, 'handle_disconnect' ] );
	}

	public function maybe_schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public static function clear_cron(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public static function get_accounts(): array {
		$a = get_option( self::OPT_ACCOUNTS, [] );
		return is_array( $a ) ? $a : [];
	}

	/** WPML active → language codes; otherwise a single 'default' account. */
	public static function account_keys(): array {
		if ( DZE_Wpml::is_active() ) {
			return array_map( static fn( $l ) => $l['code'], DZE_Wpml::get_active_languages() );
		}
		return [ 'default' ];
	}

	public function is_authenticated(): bool {
		$o = self::get_oauth();
		return ! empty( $o['refresh_token'] ) || null !== $this->get_credentials();
	}

	public function is_configured(): bool {
		if ( ! $this->is_authenticated() ) {
			return false;
		}
		foreach ( self::get_accounts() as $acc ) {
			if ( ! empty( $acc['merchant_id'] ) ) {
				return true;
			}
		}
		return false;
	}

	private function get_credentials(): ?array {
		$raw = '';
		if ( defined( 'DZE_GMC_SERVICE_ACCOUNT' ) ) {
			$raw = DZE_GMC_SERVICE_ACCOUNT;
			if ( is_string( $raw ) && strlen( $raw ) < 512 && @is_readable( $raw ) ) {
				$raw = (string) file_get_contents( $raw );
			}
		}
		if ( ! $raw ) {
			$raw = (string) get_option( self::OPT_CREDENTIALS, '' );
		}
		$sa = json_decode( (string) $raw, true );
		if ( is_array( $sa ) && ! empty( $sa['client_email'] ) && ! empty( $sa['private_key'] ) && ! empty( $sa['token_uri'] ) ) {
			return $sa;
		}
		return null;
	}

	public function register_menu(): void {
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			__( 'Google Merchant Center', 'dazont-ecom' ),
			__( 'Google Merchant Center', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings(): void {
		register_setting( 'dze_gmc_options', self::OPT_CREDENTIALS, [ 'sanitize_callback' => [ $this, 'sanitize_credentials' ] ] );
		register_setting( 'dze_gmc_options', self::OPT_ACCOUNTS, [ 'sanitize_callback' => [ $this, 'sanitize_accounts' ] ] );
		register_setting( 'dze_gmc_options', self::OPT_OAUTH, [ 'sanitize_callback' => [ $this, 'sanitize_oauth' ], 'autoload' => false ] );
	}

	/**
	 * Persist the OAuth client id/secret from the form while preserving the
	 * refresh token / connected email obtained through the "Connect" flow.
	 */
	public function sanitize_oauth( $value ): array {
		$existing = self::get_oauth();
		$in       = is_array( $value ) ? $value : [];
		return [
			'client_id'     => sanitize_text_field( $in['client_id'] ?? ( $existing['client_id'] ?? '' ) ),
			'client_secret' => sanitize_text_field( $in['client_secret'] ?? ( $existing['client_secret'] ?? '' ) ),
			'refresh_token' => (string) ( $existing['refresh_token'] ?? '' ),
			'email'         => (string) ( $existing['email'] ?? '' ),
		];
	}

	public static function get_oauth(): array {
		// Force a fresh read from the DB: this option is written by a redirect
		// (admin-post.php) and read moments later by an AJAX request, which can
		// land on a different PHP process/server with a stale persistent object
		// cache (Redis/Memcached) if one is active — bypass it defensively.
		// Autoloaded options are cached as a single 'alloptions' blob rather
		// than under their own key, so both must be cleared or the option's
		// own cache key delete above is a no-op and this keeps reading the
		// pre-connect (empty) snapshot forever.
		wp_cache_delete( self::OPT_OAUTH, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$o = get_option( self::OPT_OAUTH, [] );
		return is_array( $o ) ? $o : [];
	}

	public function oauth_redirect_uri(): string {
		return admin_url( 'admin-post.php?action=dze_gmc_oauth' );
	}

	public function oauth_authorize_url(): string {
		$o = self::get_oauth();
		return self::AUTH_URL . '?' . http_build_query( [
			'client_id'     => $o['client_id'] ?? '',
			'redirect_uri'  => $this->oauth_redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::OAUTH_SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => wp_create_nonce( 'dze_gmc_oauth' ),
		] );
	}

	public function handle_oauth_callback(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$settings_url = admin_url( 'admin.php?page=' . self::MENU_SLUG );

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! wp_verify_nonce( $state, 'dze_gmc_oauth' ) ) {
			wp_safe_redirect( add_query_arg( 'gmc_error', rawurlencode( __( 'Security check failed.', 'dazont-ecom' ) ), $settings_url ) );
			exit;
		}
		if ( ! empty( $_GET['error'] ) ) {
			wp_safe_redirect( add_query_arg( 'gmc_error', rawurlencode( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ), $settings_url ) );
			exit;
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$o    = self::get_oauth();
		if ( $code === '' || empty( $o['client_id'] ) || empty( $o['client_secret'] ) ) {
			wp_safe_redirect( add_query_arg( 'gmc_error', rawurlencode( __( 'Missing code or client credentials.', 'dazont-ecom' ) ), $settings_url ) );
			exit;
		}

		$response = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 25,
			'body'    => [
				'code'          => $code,
				'client_id'     => $o['client_id'],
				'client_secret' => $o['client_secret'],
				'redirect_uri'  => $this->oauth_redirect_uri(),
				'grant_type'    => 'authorization_code',
			],
		] );
		if ( is_wp_error( $response ) ) {
			wp_safe_redirect( add_query_arg( 'gmc_error', rawurlencode( $response->get_error_message() ), $settings_url ) );
			exit;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['refresh_token'] ) ) {
			$msg = $data['error_description'] ?? ( $data['error'] ?? __( 'No refresh token returned. Remove the app from your Google account and reconnect.', 'dazont-ecom' ) );
			wp_safe_redirect( add_query_arg( 'gmc_error', rawurlencode( $msg ), $settings_url ) );
			exit;
		}

		$o['refresh_token'] = $data['refresh_token'];
		$o['email']         = $this->fetch_account_email( $data['access_token'] ?? '' );
		update_option( self::OPT_OAUTH, $o, false );

		if ( ! empty( $data['access_token'] ) && ! empty( $data['expires_in'] ) ) {
			set_transient( 'dze_gmc_oauth_token', $data['access_token'], min( (int) $data['expires_in'] - 60, self::TOKEN_TTL ) );
		}

		wp_safe_redirect( add_query_arg( 'gmc_connected', '1', $settings_url ) );
		exit;
	}

	private function fetch_account_email( string $access_token ): string {
		if ( $access_token === '' ) {
			return '';
		}
		$r = wp_remote_get( 'https://www.googleapis.com/oauth2/v2/userinfo', [
			'timeout' => 15,
			'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
		] );
		if ( is_wp_error( $r ) ) {
			return '';
		}
		$d = json_decode( wp_remote_retrieve_body( $r ), true );
		return isset( $d['email'] ) ? sanitize_email( $d['email'] ) : '';
	}

	public function handle_disconnect(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'dze_gmc_disconnect' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$o = self::get_oauth();
		$o['refresh_token'] = '';
		$o['email']         = '';
		update_option( self::OPT_OAUTH, $o, false );
		delete_transient( 'dze_gmc_oauth_token' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	private function oauth_access_token(): string {
		$cached = get_transient( 'dze_gmc_oauth_token' );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}
		$o = self::get_oauth();
		if ( empty( $o['refresh_token'] ) || empty( $o['client_id'] ) || empty( $o['client_secret'] ) ) {
			throw new RuntimeException( __( 'Google account is not connected.', 'dazont-ecom' ) );
		}
		$response = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 20,
			'body'    => [
				'client_id'     => $o['client_id'],
				'client_secret' => $o['client_secret'],
				'refresh_token' => $o['refresh_token'],
				'grant_type'    => 'refresh_token',
			],
		] );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $response->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			$msg = $data['error_description'] ?? ( $data['error'] ?? 'Unknown refresh error' );
			throw new RuntimeException( sprintf( __( 'Google token refresh failed: %s', 'dazont-ecom' ), $msg ) );
		}
		set_transient( 'dze_gmc_oauth_token', $data['access_token'], min( (int) ( $data['expires_in'] ?? 3600 ) - 60, self::TOKEN_TTL ) );
		return $data['access_token'];
	}

	public function sanitize_credentials( $value ): string {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return (string) get_option( self::OPT_CREDENTIALS, '' ); // keep existing when left blank
		}
		$json = json_decode( $value, true );
		return is_array( $json ) ? wp_json_encode( $json ) : '';
	}

	public function sanitize_accounts( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$clean = [];
		foreach ( $value as $key => $acc ) {
			$key = sanitize_key( $key );
			$clean[ $key ] = [
				'merchant_id' => preg_replace( '/[^0-9]/', '', (string) ( $acc['merchant_id'] ?? '' ) ),
				'country'     => strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $acc['country'] ?? '' ) ) ),
				'language'    => sanitize_key( $acc['language'] ?? $key ),
			];
		}
		return $clean;
	}

	public function enqueue_assets( string $hook ): void {
		// Load on the GMC settings page and on the Discounts list (sync buttons).
		if ( strpos( $hook, self::MENU_SLUG ) === false && strpos( $hook, DZE_Discounts::MENU_SLUG ) === false ) {
			return;
		}
		wp_enqueue_script( 'dze-gmc', DZE_URL . 'admin/js/gmc.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-gmc', 'dzeGmc', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				'syncing' => __( 'Syncing…', 'dazont-ecom' ),
				'testing' => __( 'Testing…', 'dazont-ecom' ),
				'done'    => __( 'Done', 'dazont-ecom' ),
				'error'   => __( 'Error', 'dazont-ecom' ),
			],
		] );
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$accounts      = self::get_accounts();
		$keys          = self::account_keys();
		$languages     = DZE_Wpml::get_active_languages();
		$has_creds     = ( null !== $this->get_credentials() );
		$creds_locked  = defined( 'DZE_GMC_SERVICE_ACCOUNT' );
		$oauth         = self::get_oauth();
		$redirect_uri  = $this->oauth_redirect_uri();
		$oauth_ready   = ! empty( $oauth['client_id'] ) && ! empty( $oauth['client_secret'] );
		$connected     = ! empty( $oauth['refresh_token'] );
		$authorize_url = $oauth_ready ? $this->oauth_authorize_url() : '';
		require DZE_DIR . 'admin/views/gmc-settings.php';
	}

	// =========================================================================
	// OAuth2 (service account)
	// =========================================================================

	private function get_access_token(): string {
		// Prefer the connected Google account (OAuth) — the natural in-plugin flow.
		$oauth = self::get_oauth();
		if ( ! empty( $oauth['refresh_token'] ) ) {
			return $this->oauth_access_token();
		}

		// Fallback: service-account credentials (JWT).
		$sa = $this->get_credentials();
		if ( null === $sa ) {
			throw new RuntimeException( sprintf(
				/* translators: internal diagnostic state, not translated */
				__( 'No Google authentication configured. Connect your Google account above. (debug: oauth_refresh_token=%s, oauth_client=%s, service_account=%s)', 'dazont-ecom' ),
				empty( $oauth['refresh_token'] ) ? 'missing' : 'present',
				( ! empty( $oauth['client_id'] ) && ! empty( $oauth['client_secret'] ) ) ? 'present' : 'missing',
				defined( 'DZE_GMC_SERVICE_ACCOUNT' ) ? 'constant' : ( get_option( self::OPT_CREDENTIALS, '' ) !== '' ? 'option-set-but-invalid' : 'none' )
			) );
		}

		$cache_key = 'dze_gmc_token_' . md5( $sa['client_email'] );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}

		if ( ! function_exists( 'openssl_sign' ) ) {
			throw new RuntimeException( __( 'PHP OpenSSL is required to sign the Google token request.', 'dazont-ecom' ) );
		}

		$now    = time();
		$header = $this->b64url( (string) wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
		$claim  = $this->b64url( (string) wp_json_encode( [
			'iss'   => $sa['client_email'],
			'scope' => self::SCOPE,
			'aud'   => $sa['token_uri'],
			'iat'   => $now,
			'exp'   => $now + 3600,
		] ) );

		$signature = '';
		if ( ! openssl_sign( $header . '.' . $claim, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			throw new RuntimeException( __( 'Could not sign the Google authentication request (bad private key?).', 'dazont-ecom' ) );
		}
		$jwt = $header . '.' . $claim . '.' . $this->b64url( $signature );

		$response = wp_remote_post( $sa['token_uri'], [
			'timeout' => 20,
			'body'    => [
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			],
		] );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $response->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			$msg = $data['error_description'] ?? ( $data['error'] ?? 'Unknown token error' );
			throw new RuntimeException( sprintf( __( 'Google token error: %s', 'dazont-ecom' ), $msg ) );
		}

		set_transient( $cache_key, $data['access_token'], self::TOKEN_TTL );
		return $data['access_token'];
	}

	private function b64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	// =========================================================================
	// Promotion sync
	// =========================================================================

	/**
	 * Pushes a sale rule to the Merchant Center account of each language it is
	 * active in. Returns [ langKey => [status,message,...] ] and stores it on
	 * the rule.
	 */
	public function sync_rule( string $rule_id ): array {
		$rules = DZE_Discounts::get_rules();
		if ( ! isset( $rules[ $rule_id ] ) ) {
			return [];
		}
		$rule = $rules[ $rule_id ];
		if ( ( $rule['type'] ?? '' ) !== 'sale' ) {
			return [];
		}

		$accounts = self::get_accounts();
		$statuses = [];

		foreach ( $this->target_language_keys( $rule ) as $key ) {
			$account = $accounts[ $key ] ?? null;
			if ( empty( $account['merchant_id'] ) || empty( $account['country'] ) ) {
				$statuses[ $key ] = [ 'status' => 'error', 'message' => __( 'No merchant / country configured for this language.', 'dazont-ecom' ), 'time' => time() ];
				continue;
			}
			try {
				$token   = $this->get_access_token();
				$payload = $this->build_payload( $rule, $key, $account );
				$this->request( 'POST', $account['merchant_id'] . '/promotions', $token, $payload );
				$statuses[ $key ] = [ 'status' => 'synced', 'message' => '', 'promotion_id' => $payload['promotionId'], 'time' => time() ];
			} catch ( \Throwable $e ) {
				$statuses[ $key ] = [ 'status' => 'error', 'message' => $e->getMessage(), 'time' => time() ];
			}
		}

		$rules[ $rule_id ]['gmc_sync'] = $statuses;
		update_option( DZE_Discounts::OPTION, $rules, false );

		return $statuses;
	}

	/** Language keys the promo targets (effective WPML languages, or 'default'). */
	private function target_language_keys( array $rule ): array {
		if ( DZE_Wpml::is_active() ) {
			$eff = DZE_Discounts::instance()->rule_effective_languages( $rule );
			return ! empty( $eff ) ? $eff : [ DZE_Wpml::default_language() ];
		}
		return [ 'default' ];
	}

	private function build_payload( array $rule, string $key, array $account ): array {
		[ $start_ts, $end_ts ] = DZE_Discounts::instance()->window_ts( $rule );
		if ( $start_ts === PHP_INT_MIN || $end_ts === PHP_INT_MAX ) {
			throw new RuntimeException( __( 'GMC promotions need both a start and an end date.', 'dazont-ecom' ) );
		}

		$content_language = ( $key !== 'default' ) ? $key : ( $account['language'] ?: substr( get_locale(), 0, 2 ) );

		// Long title: translated banner text if available, else the promo title.
		$title = $rule['banner_text'] ?? '';
		$i18n  = (array) ( $rule['banner_text_i18n'] ?? [] );
		if ( ! empty( $i18n[ $key ] ) ) {
			$title = $i18n[ $key ];
		}
		if ( trim( (string) $title ) === '' ) {
			$title = $rule['title'] ?? 'Promotion';
		}
		$title = mb_substr( wp_strip_all_tags( (string) $title ), 0, 60 );

		return [
			'promotionId'          => 'dze_' . preg_replace( '/[^A-Za-z0-9_]/', '', (string) $rule['id'] ),
			'targetCountry'        => $account['country'],
			'contentLanguage'      => $content_language,
			'redemptionChannel'    => [ 'ONLINE' ],
			'longTitle'            => $title,
			'productApplicability' => 'ALL_PRODUCTS',
			'offerType'            => 'NO_CODE',
			'couponValueType'      => 'PERCENT_OFF',
			'percentOff'           => (int) round( (float) ( $rule['percent'] ?? 0 ) ),
			'promotionEffectiveTimePeriod' => [
				'startTime' => gmdate( 'Y-m-d\TH:i:s\Z', $start_ts ),
				'endTime'   => gmdate( 'Y-m-d\TH:i:s\Z', $end_ts ),
			],
		];
	}

	private function request( string $method, string $path, string $token, ?array $body = null ): array {
		$response = wp_remote_request( self::API_BASE . '/' . ltrim( $path, '/' ), [
			'method'  => $method,
			'timeout' => 25,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'body'    => $body ? wp_json_encode( $body ) : null,
		] );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = $data['error']['message'] ?? ( 'HTTP ' . $code );
			throw new RuntimeException( $msg );
		}
		return is_array( $data ) ? $data : [];
	}

	public function cron_sync_all(): void {
		foreach ( DZE_Discounts::get_rules() as $id => $rule ) {
			if ( ( $rule['type'] ?? '' ) === 'sale' && ! empty( $rule['enabled'] ) ) {
				$this->sync_rule( (string) $id );
			}
		}
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	public function ajax_sync(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['ids'] ) ) : [];
		if ( empty( $ids ) ) {
			wp_send_json_error( [ 'message' => __( 'No promotion selected.', 'dazont-ecom' ) ] );
		}

		$results = [];
		foreach ( $ids as $id ) {
			$results[ $id ] = $this->sync_rule( $id );
		}
		wp_send_json_success( [ 'results' => $results ] );
	}

	public function ajax_test(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		try {
			$this->get_access_token();
			wp_send_json_success( [ 'message' => __( 'Authenticated with Google successfully.', 'dazont-ecom' ) ] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	// =========================================================================
	// Display helpers (used by the Discounts list)
	// =========================================================================

	/** Small per-language sync badges for a rule, for the Discounts list. */
	public function sync_badges_html( array $rule ): string {
		$sync = (array) ( $rule['gmc_sync'] ?? [] );
		$keys = $this->target_language_keys( $rule );
		$out  = '';
		foreach ( $keys as $key ) {
			$state = $sync[ $key ]['status'] ?? 'pending';
			$label = strtoupper( $key === 'default' ? '•' : $key );
			$color = $state === 'synced' ? '#0a7040' : ( $state === 'error' ? '#b32d2e' : '#999' );
			$title = $state === 'error' ? ( $sync[ $key ]['message'] ?? 'error' ) : ucfirst( $state );
			$dot   = $state === 'synced' ? '●' : ( $state === 'error' ? '✕' : '○' );
			$out  .= sprintf(
				'<span title="%s" style="color:%s;margin-right:6px;white-space:nowrap;">%s %s</span>',
				esc_attr( $key . ': ' . $title ),
				esc_attr( $color ),
				esc_html( $dot ),
				esc_html( $label )
			);
		}
		return $out !== '' ? $out : '<span style="color:#999;">—</span>';
	}
}
