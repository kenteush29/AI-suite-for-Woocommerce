<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page.
 * Stores: API key (plaintext, server-side only), default model, per-task model overrides,
 * preview toggle, cost guardrails, editable prompt templates, and the test-connection button.
 */
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
	public const OPT_PROMPTS         = 'aics_prompts';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_aics_test_connection', [ $this, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_aics_clear_log',       [ $this, 'ajax_clear_log' ] );
	}

	// -------------------------------------------------------------------------
	// Accessors
	// -------------------------------------------------------------------------

	public static function get_api_key(): string {
		$value = (string) get_option( self::OPT_API_KEY, '' );
		// Reject old encrypted garbage — valid Anthropic keys always start with "sk-ant-".
		if ( $value !== '' && strpos( $value, 'sk-ant-' ) !== 0 ) {
			return '';
		}
		return $value;
	}

	public static function get_default_model(): string {
		return get_option( self::OPT_DEFAULT_MODEL, 'claude-haiku-4-5' );
	}

	public static function get_model_for_task( string $task ): string {
		$overrides = get_option( self::OPT_MODEL_OVERRIDES, [] );
		return ( ! empty( $overrides[ $task ] ) ) ? $overrides[ $task ] : self::get_default_model();
	}

	public static function is_preview_mode(): bool {
		return (bool) get_option( self::OPT_PREVIEW_MODE, true );
	}

	/**
	 * Returns the stored prompts for a given task, falling back to built-in defaults.
	 * Returns [ 'system' => string, 'user_template' => string ]
	 */
	public static function get_prompt( string $task ): array {
		$stored   = get_option( self::OPT_PROMPTS, [] );
		$defaults = self::default_prompts();

		return [
			'system'        => $stored[ $task ]['system']        ?? $defaults[ $task ]['system']        ?? '',
			'user_template' => $stored[ $task ]['user_template'] ?? $defaults[ $task ]['user_template'] ?? '',
		];
	}

	public static function check_and_increment_call_count(): bool {
		$max_hour = (int) get_option( self::OPT_MAX_CALLS_HOUR, 0 );
		$max_day  = (int) get_option( self::OPT_MAX_CALLS_DAY, 0 );

		if ( $max_hour === 0 && $max_day === 0 ) {
			return true;
		}

		$counts = get_option( self::OPT_CALLS_COUNT, [
			'hour_ts'    => 0,
			'hour_count' => 0,
			'day_ts'     => 0,
			'day_count'  => 0,
		] );

		$now = time();

		if ( $now - $counts['hour_ts'] >= HOUR_IN_SECONDS ) {
			$counts['hour_ts']    = $now;
			$counts['hour_count'] = 0;
		}

		if ( $now - $counts['day_ts'] >= DAY_IN_SECONDS ) {
			$counts['day_ts']    = $now;
			$counts['day_count'] = 0;
		}

		if ( $max_hour > 0 && $counts['hour_count'] >= $max_hour ) {
			return false;
		}

		if ( $max_day > 0 && $counts['day_count'] >= $max_day ) {
			return false;
		}

		$counts['hour_count']++;
		$counts['day_count']++;
		update_option( self::OPT_CALLS_COUNT, $counts, false );

		return true;
	}

	// -------------------------------------------------------------------------
	// Default prompt templates
	// Available placeholders: {{product_name}}, {{supplier_data}}
	// -------------------------------------------------------------------------

	public static function default_prompts(): array {
		return [
			'seo_title' => [
				'system'        => 'You are an SEO specialist. Write a concise, keyword-rich SEO title for a WooCommerce product. Maximum 60 characters. Return only the title — no quotes, no extra text.',
				'user_template' => "Product name: {{product_name}}\nSupplier data:\n{{supplier_data}}\n\nWrite an SEO title (maximum 60 characters) for this product.",
			],
			'short_description' => [
				'system'        => 'You are a product copywriter. Write a compelling 1–2 sentence short description for a WooCommerce product. Highlight the key benefit. Return only the description text.',
				'user_template' => "Product name: {{product_name}}\nSupplier data:\n{{supplier_data}}\n\nWrite a short product description (1 to 2 sentences) for this product.",
			],
			'long_description' => [
				'system'        => 'You are a product copywriter. Write a detailed, persuasive product description for WooCommerce. Use short paragraphs. Avoid markdown headers. Return only the description text.',
				'user_template' => "Product name: {{product_name}}\nSupplier data:\n{{supplier_data}}\n\nWrite a detailed product description (3 to 5 paragraphs) for this product.",
			],
			'custom_1' => [
				'system'        => 'You are a product content specialist. Generate clear, professional product content based on the provided information. Return only the content text.',
				'user_template' => "Product name: {{product_name}}\nSupplier data:\n{{supplier_data}}\n\nWrite relevant product content for this product.",
			],
			'custom_2' => [
				'system'        => 'You are a product content specialist. Generate clear, professional product content based on the provided information. Return only the content text.',
				'user_template' => "Product name: {{product_name}}\nSupplier data:\n{{supplier_data}}\n\nWrite relevant product content for this product.",
			],
		];
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	public function register_menu(): void {
		add_menu_page(
			__( 'AI Content Suite', 'ai-content-suite' ),
			__( 'AI Content Suite', 'ai-content-suite' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_settings_page' ],
			'dashicons-rest-api',
			58
		);
	}

	// -------------------------------------------------------------------------
	// Settings API registration
	// -------------------------------------------------------------------------

	public function register_settings(): void {
		register_setting( self::OPTION_GROUP, self::OPT_API_KEY, [
			'sanitize_callback' => [ $this, 'sanitize_api_key' ],
		] );
		register_setting( self::OPTION_GROUP, self::OPT_DEFAULT_MODEL, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_setting( self::OPTION_GROUP, self::OPT_MODEL_OVERRIDES, [
			'sanitize_callback' => [ $this, 'sanitize_model_overrides' ],
		] );
		register_setting( self::OPTION_GROUP, self::OPT_PREVIEW_MODE, [
			'sanitize_callback' => 'absint',
		] );
		register_setting( self::OPTION_GROUP, self::OPT_MAX_CALLS_HOUR, [
			'sanitize_callback' => 'absint',
		] );
		register_setting( self::OPTION_GROUP, self::OPT_MAX_CALLS_DAY, [
			'sanitize_callback' => 'absint',
		] );
		register_setting( self::OPTION_GROUP, self::OPT_PROMPTS, [
			'sanitize_callback' => [ $this, 'sanitize_prompts' ],
		] );
	}

	public function sanitize_api_key( $value ): string {
		$value = sanitize_text_field( trim( (string) $value ) );
		if ( empty( $value ) ) {
			return (string) get_option( self::OPT_API_KEY, '' );
		}
		return $value;
	}

	public function sanitize_model_overrides( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$clean = [];
		foreach ( $value as $task => $model ) {
			$clean[ sanitize_key( $task ) ] = sanitize_text_field( $model );
		}
		return $clean;
	}

	public function sanitize_prompts( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$clean    = [];
		$defaults = self::default_prompts();
		foreach ( $defaults as $task => $_ ) {
			$clean[ $task ] = [
				'system'        => sanitize_textarea_field( wp_unslash( $value[ $task ]['system'] ?? '' ) ),
				'user_template' => sanitize_textarea_field( wp_unslash( $value[ $task ]['user_template'] ?? '' ) ),
			];
		}
		return $clean;
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'aics' ) === false ) {
			return;
		}

		wp_enqueue_style( 'aics-admin', AICS_URL . 'admin/css/admin.css', [], AICS_VERSION );

		wp_enqueue_script( 'aics-settings', AICS_URL . 'admin/js/settings.js', [ 'jquery' ], AICS_VERSION, true );

		wp_localize_script( 'aics-settings', 'aicsSettings', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'aics_admin' ),
			'i18n'    => [
				'testing'  => __( 'Testing…', 'ai-content-suite' ),
				'success'  => __( 'Connection successful!', 'ai-content-suite' ),
				'error'    => __( 'Connection failed:', 'ai-content-suite' ),
				'clearing' => __( 'Clearing…', 'ai-content-suite' ),
				'cleared'  => __( 'Log cleared.', 'ai-content-suite' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

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
			wp_send_json_success( [
				'message' => $result['text'],
				'model'   => $result['model'],
				'usage'   => $result['usage'],
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_clear_log(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		AICS_Logger::instance()->clear();
		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// Settings page view
	// -------------------------------------------------------------------------

	public function render_settings_page(): void {
		$api_key_set     = ! empty( self::get_api_key() );
		$default_model   = self::get_default_model();
		$model_overrides = get_option( self::OPT_MODEL_OVERRIDES, [] );
		$preview_mode    = self::is_preview_mode();
		$max_hour        = get_option( self::OPT_MAX_CALLS_HOUR, 0 );
		$max_day         = get_option( self::OPT_MAX_CALLS_DAY, 0 );
		$log             = AICS_Logger::instance()->get_log();
		$stored_prompts  = get_option( self::OPT_PROMPTS, [] );
		$default_prompts = self::default_prompts();

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
		];

		require AICS_DIR . 'admin/views/settings.php';
	}
}
