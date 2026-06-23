<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adds the "AI Content Generator" metabox on the WooCommerce product edit screen.
 * Provides AJAX endpoints for per-field generation and field writing.
 */
final class AICS_Product_Metabox {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes',          [ $this, 'register_metabox' ] );
		add_action( 'admin_enqueue_scripts',   [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_aics_generate_field', [ $this, 'ajax_generate_field' ] );
		add_action( 'wp_ajax_aics_apply_field',    [ $this, 'ajax_apply_field' ] );
	}

	// -------------------------------------------------------------------------
	// Metabox registration
	// -------------------------------------------------------------------------

	public function register_metabox(): void {
		add_meta_box(
			'aics-content-generator',
			__( 'AI Content Generator', 'ai-content-suite' ),
			[ $this, 'render' ],
			'product',
			'normal',
			'high'
		);
	}

	public function enqueue_assets( string $hook ): void {
		global $post;
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		if ( ! $post || $post->post_type !== 'product' ) {
			return;
		}

		wp_enqueue_script(
			'aics-metabox',
			AICS_URL . 'admin/js/metabox.js',
			[ 'jquery' ],
			AICS_VERSION,
			true
		);

		wp_localize_script( 'aics-metabox', 'aicsMetabox', [
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'aics_admin' ),
			'postId'      => $post->ID,
			'previewMode' => AICS_Settings::is_preview_mode(),
			'i18n'        => [
				'generating' => __( 'Generating…', 'ai-content-suite' ),
				'generate'   => __( 'Generate', 'ai-content-suite' ),
				'applying'   => __( 'Applying…', 'ai-content-suite' ),
				'applied'    => __( 'Applied!', 'ai-content-suite' ),
				'apply'      => __( 'Apply to field', 'ai-content-suite' ),
				'error'      => __( 'Error:', 'ai-content-suite' ),
				'noMapping'  => __( 'This slot is not mapped. Configure it in Field Mapping.', 'ai-content-suite' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	public function render( \WP_Post $post ): void {
		$mapper  = AICS_Field_Mapper::instance();
		$mapping = $mapper->get_mapping();

		$dest_slots = [
			'dest_seo_title'          => __( 'SEO Title', 'ai-content-suite' ),
			'dest_short_description'  => __( 'Short Description', 'ai-content-suite' ),
			'dest_long_description'   => __( 'Long Description', 'ai-content-suite' ),
			'dest_custom_1'           => __( 'Custom Field 1', 'ai-content-suite' ),
			'dest_custom_2'           => __( 'Custom Field 2', 'ai-content-suite' ),
		];

		require AICS_DIR . 'admin/views/metabox.php';
	}

	// -------------------------------------------------------------------------
	// AJAX: generate content for a single field
	// -------------------------------------------------------------------------

	public function ajax_generate_field(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-content-suite' ) ], 403 );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		$slot    = sanitize_key( $_POST['slot'] ?? '' );

		if ( ! $post_id || ! $slot ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'ai-content-suite' ) ] );
		}

		if ( ! AICS_Settings::check_and_increment_call_count() ) {
			wp_send_json_error( [ 'message' => __( 'API call limit reached. Check cost guardrails in Settings.', 'ai-content-suite' ) ] );
		}

		$api_key = AICS_Settings::get_api_key();
		if ( empty( $api_key ) ) {
			wp_send_json_error( [ 'message' => __( 'No API key configured.', 'ai-content-suite' ) ] );
		}

		$mapper         = AICS_Field_Mapper::instance();
		$supplier_data  = $mapper->read( 'source_supplier_data', $post_id );
		$product_title  = $mapper->read( 'source_product_title', $post_id );

		// Fall back to WP post title if source slots are not mapped.
		if ( empty( $product_title ) ) {
			$product_title = get_the_title( $post_id );
		}

		$task          = str_replace( 'dest_', '', $slot );
		$model         = AICS_Settings::get_model_for_task( $task );
		$user_prompt   = $this->build_user_prompt( $task, $product_title, $supplier_data );
		$system_prompt = $this->get_system_prompt( $task );

		try {
			$client = new AICS_Api_Client( $api_key, $model );
			$result = $client->generate(
				$user_prompt,
				$system_prompt,
				null,
				[ 'prompt_slug' => $task, 'product_id' => $post_id ]
			);

			wp_send_json_success( [
				'text'  => $result['text'],
				'model' => $result['model'],
				'usage' => $result['usage'],
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	// -------------------------------------------------------------------------
	// AJAX: write a value to a mapped field
	// -------------------------------------------------------------------------

	public function ajax_apply_field(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-content-suite' ) ], 403 );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		$slot    = sanitize_key( $_POST['slot'] ?? '' );
		$value   = wp_kses_post( wp_unslash( $_POST['value'] ?? '' ) );

		if ( ! $post_id || ! $slot ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'ai-content-suite' ) ] );
		}

		$ok = AICS_Field_Mapper::instance()->write( $slot, $value, $post_id );

		if ( $ok ) {
			wp_send_json_success( [ 'message' => __( 'Field updated.', 'ai-content-suite' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Could not write to field. Check the Field Mapping configuration.', 'ai-content-suite' ) ] );
		}
	}

	// -------------------------------------------------------------------------
	// Prompt builders
	// -------------------------------------------------------------------------

	private function build_user_prompt( string $task, string $title, string $supplier_data ): string {
		$lines = [];

		if ( $title ) {
			$lines[] = 'Product name: ' . $title;
		}
		if ( $supplier_data ) {
			$lines[] = "Supplier / source data:\n" . $supplier_data;
		}

		$what = match ( $task ) {
			'seo_title'         => 'an SEO title (maximum 60 characters)',
			'short_description' => 'a short product description (1 to 2 sentences)',
			'long_description'  => 'a detailed product description (3 to 5 paragraphs)',
			default             => 'relevant product content',
		};

		$lines[] = "\nWrite {$what} for this product.";

		return implode( "\n", $lines );
	}

	private function get_system_prompt( string $task ): string {
		return match ( $task ) {
			'seo_title' =>
				'You are an SEO specialist. Write a concise, keyword-rich SEO title for a WooCommerce product. ' .
				'Maximum 60 characters. Return only the title — no quotes, no extra text.',

			'short_description' =>
				'You are a product copywriter. Write a compelling 1–2 sentence short description for a WooCommerce product. ' .
				'Highlight the key benefit. Return only the description text.',

			'long_description' =>
				'You are a product copywriter. Write a detailed, persuasive product description for WooCommerce. ' .
				'Use short paragraphs. Avoid markdown headers. Return only the description text.',

			default =>
				'You are a product content specialist. Generate clear, professional product content ' .
				'based on the provided information. Return only the content text.',
		};
	}
}
