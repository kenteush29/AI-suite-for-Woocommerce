<?php
defined( 'ABSPATH' ) || exit;

/**
 * WPML AI translation: translates generated content to all active WPML languages.
 */
final class AICS_Wpml_Translate {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_aics_get_wpml_languages', [ $this, 'ajax_get_languages' ] );
		add_action( 'wp_ajax_aics_translate_content',  [ $this, 'ajax_translate_content' ] );
	}

	public static function is_wpml_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'icl_object_id' );
	}

	public static function get_active_languages(): array {
		if ( ! self::is_wpml_active() ) {
			return [];
		}
		$languages = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
		if ( ! is_array( $languages ) ) {
			return [];
		}
		$result = [];
		foreach ( $languages as $code => $data ) {
			$result[] = [
				'code'        => $code,
				'native_name' => $data['native_name'] ?? $code,
				'translated_name' => $data['translated_name'] ?? $code,
			];
		}
		return $result;
	}

	public static function get_translated_post_id( int $source_id, string $language_code ): int {
		if ( ! self::is_wpml_active() ) {
			return 0;
		}
		$translated_id = apply_filters( 'wpml_object_id', $source_id, 'product', false, $language_code );
		return (int) ( $translated_id ?? 0 );
	}

	public function ajax_get_languages(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-content-suite' ) ], 403 );
		}

		if ( ! self::is_wpml_active() ) {
			wp_send_json_error( [ 'message' => __( 'WPML is not active.', 'ai-content-suite' ) ] );
		}

		wp_send_json_success( [ 'languages' => self::get_active_languages() ] );
	}

	public function ajax_translate_content(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-content-suite' ) ], 403 );
		}

		$post_id       = (int) ( $_POST['post_id'] ?? 0 );
		$slot          = sanitize_key( $_POST['slot'] ?? '' );
		$source_text   = wp_kses_post( wp_unslash( $_POST['source_text'] ?? '' ) );
		$language_code = sanitize_key( $_POST['language_code'] ?? '' );

		if ( ! $post_id || ! $slot || ! $source_text || ! $language_code ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'ai-content-suite' ) ] );
		}

		if ( ! AICS_Settings::check_and_increment_call_count() ) {
			wp_send_json_error( [ 'message' => __( 'API call limit reached. Check cost guardrails in Settings.', 'ai-content-suite' ) ] );
		}

		$api_key = AICS_Settings::get_api_key();
		if ( empty( $api_key ) ) {
			wp_send_json_error( [ 'message' => __( 'No API key configured.', 'ai-content-suite' ) ] );
		}

		$target_post_id = self::get_translated_post_id( $post_id, $language_code );
		if ( ! $target_post_id ) {
			wp_send_json_error( [ 'message' => sprintf(
				/* translators: language code */
				__( 'No translated product found for language: %s', 'ai-content-suite' ),
				$language_code
			) ] );
		}

		$languages    = self::get_active_languages();
		$language_name = $language_code;
		foreach ( $languages as $lang ) {
			if ( $lang['code'] === $language_code ) {
				$language_name = $lang['native_name'];
				break;
			}
		}

		$model  = AICS_Settings::get_model_for_task( str_replace( 'dest_', '', $slot ) );
		$system = sprintf(
			'You are a professional translator. Translate the following product content to %s. Preserve any HTML tags exactly. Return only the translated text, nothing else.',
			$language_name
		);

		try {
			$client = new AICS_Api_Client( $api_key, $model );
			$result = $client->generate(
				$source_text,
				$system,
				null,
				[ 'prompt_slug' => 'translate_' . $language_code, 'product_id' => $target_post_id ]
			);

			$translated_text = $result['text'];

			$ok = AICS_Field_Mapper::instance()->write( $slot, $translated_text, $target_post_id );

			if ( $ok ) {
				wp_send_json_success( [
					'message'    => __( 'Translation applied.', 'ai-content-suite' ),
					'text'       => $translated_text,
					'model'      => $result['model'],
					'usage'      => $result['usage'],
					'target_id'  => $target_post_id,
				] );
			} else {
				wp_send_json_error( [ 'message' => __( 'Translation generated but could not write to translated product field.', 'ai-content-suite' ) ] );
			}
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}
}
