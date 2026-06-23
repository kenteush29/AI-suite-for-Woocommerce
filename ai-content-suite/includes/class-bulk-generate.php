<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bulk Generate: process multiple products at once from an admin page.
 */
final class AICS_Bulk_Generate {

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
		add_action( 'wp_ajax_aics_bulk_get_products', [ $this, 'ajax_get_products' ] );
		add_action( 'wp_ajax_aics_bulk_generate',     [ $this, 'ajax_bulk_generate' ] );
	}

	public function register_submenu(): void {
		add_submenu_page(
			'aics-settings',
			__( 'Bulk Generate', 'ai-content-suite' ),
			__( 'Bulk Generate', 'ai-content-suite' ),
			'manage_woocommerce',
			'aics-bulk-generate',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'aics-bulk-generate' ) === false ) {
			return;
		}

		wp_enqueue_style( 'aics-admin', AICS_URL . 'admin/css/admin.css', [], AICS_VERSION );

		wp_enqueue_script(
			'aics-bulk-generate',
			AICS_URL . 'admin/js/bulk-generate.js',
			[ 'jquery' ],
			AICS_VERSION,
			true
		);

		wp_localize_script( 'aics-bulk-generate', 'aicsBulk', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'aics_admin' ),
			'i18n'    => [
				'loading'    => __( 'Loading products…', 'ai-content-suite' ),
				'generating' => __( 'Generating…', 'ai-content-suite' ),
				'done'       => __( 'Done', 'ai-content-suite' ),
				'error'      => __( 'Error', 'ai-content-suite' ),
				'confirm'    => __( 'This will generate and apply content for all selected products and fields. Continue?', 'ai-content-suite' ),
				'noProducts' => __( 'No products selected.', 'ai-content-suite' ),
				'noSlots'    => __( 'No fields selected.', 'ai-content-suite' ),
			],
			'slots' => [
				'dest_seo_title'         => __( 'SEO meta description', 'ai-content-suite' ),
				'dest_short_description' => __( 'Short description', 'ai-content-suite' ),
				'dest_long_description'  => __( 'Long description', 'ai-content-suite' ),
				'dest_custom_1'          => __( 'Custom field 1', 'ai-content-suite' ),
				'dest_custom_2'          => __( 'Custom field 2', 'ai-content-suite' ),
			],
		] );
	}

	public function render_page(): void {
		$categories = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true ] );
		require AICS_DIR . 'admin/views/bulk-generate.php';
	}

	public function ajax_get_products(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-content-suite' ) ], 403 );
		}

		$category = sanitize_key( $_POST['category'] ?? '' );

		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		if ( $category ) {
			$args['tax_query'] = [ [
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => $category,
			] ];
		}

		$query    = new WP_Query( $args );
		$products = [];

		foreach ( $query->posts as $post ) {
			$products[] = [
				'id'    => $post->ID,
				'title' => get_the_title( $post->ID ),
			];
		}

		wp_send_json_success( [ 'products' => $products ] );
	}

	public function ajax_bulk_generate(): void {
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
			wp_send_json_error( [ 'message' => __( 'API call limit reached.', 'ai-content-suite' ) ] );
		}

		$api_key = AICS_Settings::get_api_key();
		if ( empty( $api_key ) ) {
			wp_send_json_error( [ 'message' => __( 'No API key configured.', 'ai-content-suite' ) ] );
		}

		$mapper        = AICS_Field_Mapper::instance();
		$supplier_data = $mapper->read( 'source_supplier_data', $post_id );
		$product_title = $mapper->read( 'source_product_title', $post_id );

		if ( empty( $product_title ) ) {
			$product_title = get_the_title( $post_id );
		}

		$task          = str_replace( 'dest_', '', $slot );
		$model         = AICS_Settings::get_model_for_task( $task );
		$prompt        = AICS_Settings::get_prompt( $task );
		$user_prompt   = str_replace(
			[ '{{product_name}}', '{{supplier_data}}' ],
			[ $product_title, $supplier_data ],
			$prompt['user_template']
		);

		try {
			$client = new AICS_Api_Client( $api_key, $model );
			$result = $client->generate(
				$user_prompt,
				$prompt['system'],
				null,
				[ 'prompt_slug' => $task, 'product_id' => $post_id ]
			);

			$ok = $mapper->write( $slot, $result['text'], $post_id );

			if ( $ok ) {
				wp_send_json_success( [
					'text'  => $result['text'],
					'model' => $result['model'],
					'usage' => $result['usage'],
				] );
			} else {
				wp_send_json_error( [ 'message' => __( 'Generated but could not write to field.', 'ai-content-suite' ) ] );
			}
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}
}
