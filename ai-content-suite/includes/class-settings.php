<?php
defined( 'ABSPATH' ) || exit;

final class AICS_Settings {

	private const MENU_SLUG    = 'aics-settings';
	private const OPTION_GROUP = 'aics_options';

	public const OPT_API_KEY         = 'aics_anthropic_api_key';
	public const OPT_DEFAULT_MODEL   = 'aics_default_model';
	public const OPT_MODEL_OVERRIDES = 'aics_model_overrides';
	public const OPT_PREVIEW_MODE    = 'aics_preview_mode';
	public const OPT_MAX_CALLS_HOUR  = 'aics_max_calls_hour';
	public const OPT_MAX_CALLS_DAY   = 'aics_max_calls_day';
	public const OPT_CALLS_COUNT     = 'aics_calls_count';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_aics_test_connection', [ $this, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_aics_clear_log',       [ $this, 'ajax_clear_log' ] );
	}

	public static function get_api_key(): string {
		$encrypted = get_option( self::OPT_API_KEY, '' );
		return $encrypted ? self::decrypt( $encrypted ) : '';
	}

	public static function get_default_model(): string {
		return get_option( self::OPT_DEFAULT_MODEL, 'claude-haiku-4-5' );
	}

	public static function get_model_for_task( string $task ): string {
		$overrides = get_option( self::OPT_MODEL_OVERRIDES, [] );
		return $overrides[ $task ] ?? self::get_default_model();
	}

	public static function is_preview_mode(): bool {
		return (bool) get_option( self::OPT_PREVIEW_MODE, true );
	}

	public static function check_and_increment_call_count(): bool {
		$max_hour = (int) get_option( self::OPT_MAX_CALLS_HOUR, 0 );
		$max_day  = (int) get_option( self::OPT_MAX_CALLS_DAY, 0 );
		if ( $max_hour === 0 && $max_day === 0 ) { return true; }
		$counts = get_option( self::OPT_CALLS_COUNT, [
			'hour_ts' => 0, 'hour_count' => 0, 'day_ts' => 0, 'day_count' => 0,
		] );
		$now = time();
		if ( $now - $counts['hour_ts'] >= HOUR_IN_SECONDS ) { $counts['hour_ts'] = $now; $counts['hour_count'] = 0; }
		if ( $now - $counts['day_ts']  >= DAY_IN_SECONDS  ) { $counts['day_ts']  = $now; $counts['day_count']  = 0; }
		if ( $max_hour > 0 && $counts['hour_count'] >= $max_hour ) { return false; }
		if ( $max_day  > 0 && $counts['day_count']  >= $max_day  ) { return false; }
		$counts['hour_count']++;
		$counts['day_count']++;
		update_option( self::OPT_CALLS_COUNT, $counts, false );
		return true;
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'AI Content Suite', 'ai-content-suite' ),
			__( 'AI Content Suite', 'ai-content-suite' ),
			'manage_woocommerce', self::MENU_SLUG,
			[ $this, 'render_settings_page' ],
			'dashicons-rest-api', 58
		);
	}

	public function register_settings(): void {
		register_setting( self::OPTION_GROUP, self::OPT_API_KEY,         [ 'sanitize_callback' => [ $this, 'sanitize_api_key' ] ] );
		register_setting( self::OPTION_GROUP, self::OPT_DEFAULT_MODEL,   [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( self::OPTION_GROUP, self::OPT_MODEL_OVERRIDES, [ 'sanitize_callback' => [ $this, 'sanitize_model_overrides' ] ] );
		register_setting( self::OPTION_GROUP, self::OPT_PREVIEW_MODE,    [ 'sanitize_callback' => 'absint' ] );
		register_setting( self::OPTION_GROUP, self::OPT_MAX_CALLS_HOUR,  [ 'sanitize_callback' => 'absint' ] );
		register_setting( self::OPTION_GROUP, self::OPT_MAX_CALLS_DAY,   [ 'sanitize_callback' => 'absint' ] );
	}

	public function sanitize_api_key( string $value ): string {
		$value = sanitize_text_field( trim( $value ) );
		if ( empty( $value ) ) { return get_option( self::OPT_API_KEY, '' ); }
		return self::encrypt( $value );
	}

	public function sanitize_model_overrides( $value ): array {
		if ( ! is_array( $value ) ) { return []; }
		$clean = [];
		foreach ( $value as $task => $model ) {
			$clean[ sanitize_key( $task ) ] = sanitize_text_field( $model );
		}
		return $clean;
	}

	private static function encrypt( string $plain ): string {
		$key = self::derive_key(); $cipher = ''; $len = strlen( $key );
		for ( $i = 0; $i < strlen( $plain ); $i++ ) {
			$cipher .= chr( ord( $plain[$i] ) ^ ord( $key[ $i % $len ] ) );
		}
		return base64_encode( $cipher );
	}

	private static function decrypt( string $encoded ): string {
		return self::encrypt( (string) base64_decode( $encoded ) );
	}

	private static function derive_key(): string {
		$seed = defined( 'AUTH_KEY' ) ? AUTH_KEY : ( defined( 'DB_PASSWORD' ) ? DB_PASSWORD : 'aics_fallback_key' );
		return hash( 'sha256', $seed . 'aics_v1', true );
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'aics' ) === false ) { return; }
		wp_enqueue_style( 'aics-admin', AICS_URL . 'admin/css/admin.css', [], AICS_VERSION );
		wp_enqueue_script( 'aics-settings', AICS_URL . 'admin/js/settings.js', [ 'jquery' ], AICS_VERSION, true );
		wp_localize_script( 'aics-settings', 'aicsSettings', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'aics_admin' ),
			'i18n'    => [
				'testing'  => __( 'Testing...', 'ai-content-suite' ),
				'success'  => __( 'Connection successful!', 'ai-content-suite' ),
				'error'    => __( 'Connection failed:', 'ai-content-suite' ),
				'clearing' => __( 'Clearing...', 'ai-content-suite' ),
				'cleared'  => __( 'Log cleared.', 'ai-content-suite' ),
			],
		] );
	}

	public function ajax_test_connection(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-content-suite' ) ], 403 );
		}
		$api_key = self::get_api_key();
		if ( empty( $api_key ) ) {
			wp_send_json_error( [ 'message' => __( 'No API key configured.', 'ai-content-suite' ) ] );
		}
		try {
			$client = new AICS_Api_Client( $api_key, self::get_default_model() );
			$result = $client->generate(
				'Reply with exactly: "AI Content Suite connection OK."',
				'You are a brief responder.',
				null,
				[ 'prompt_slug' => 'connection_test', 'product_id' => 0 ]
			);
			wp_send_json_success( [ 'message' => $result['text'], 'model' => $result['model'], 'usage' => $result['usage'] ] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_clear_log(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
		AICS_Logger::instance()->clear();
		wp_send_json_success();
	}

	public function render_settings_page(): void {
		$api_key_set     = ! empty( self::get_api_key() );
		$default_model   = self::get_default_model();
		$model_overrides = get_option( self::OPT_MODEL_OVERRIDES, [] );
		$preview_mode    = self::is_preview_mode();
		$max_hour        = get_option( self::OPT_MAX_CALLS_HOUR, 0 );
		$max_day         = get_option( self::OPT_MAX_CALLS_DAY, 0 );
		$log             = AICS_Logger::instance()->get_log();
		$available_models = [
			'claude-haiku-4-5'  => 'Claude Haiku 4.5 (fast / cheap)',
			'claude-sonnet-4-6' => 'Claude Sonnet 4.6 (balanced)',
			'claude-opus-4-8'   => 'Claude Opus 4.8 (best quality)',
		];
		$task_labels = [
			'seo_title'         => __( 'SEO title', 'ai-content-suite' ),
			'short_description' => __( 'Short description', 'ai-content-suite' ),
			'long_description'  => __( 'Long description', 'ai-content-suite' ),
			'custom_1'          => __( 'Custom field 1', 'ai-content-suite' ),
			'custom_2'          => __( 'Custom field 2', 'ai-content-suite' ),
			'translation'       => __( 'Translation', 'ai-content-suite' ),
		];
		require AICS_DIR . 'admin/views/settings.php';
	}
}
