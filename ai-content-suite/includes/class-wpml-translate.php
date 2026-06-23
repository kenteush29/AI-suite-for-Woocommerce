<?php
defined( 'ABSPATH' ) || exit;

/**
 * WPML helper (static only). Provides language discovery and post-ID resolution.
 * The actual translation actions live in AICS_Product_Actions.
 */
final class AICS_Wpml_Translate {

	public static function is_wpml_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'icl_object_id' );
	}

	/**
	 * Returns active languages as [ [ 'code' => 'en', 'native_name' => 'English' ], ... ].
	 */
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
			];
		}
		return $result;
	}

	/**
	 * Resolves the product ID in a given language.
	 *
	 * @param int    $source_id       Any known ID of the product (in any language).
	 * @param string $language_code   Target language code.
	 * @param bool   $return_original When true, returns the original ID if no translation exists.
	 * @return int   The resolved post ID, or 0 when none and $return_original is false.
	 */
	public static function get_translated_post_id( int $source_id, string $language_code, bool $return_original = false ): int {
		if ( ! self::is_wpml_active() ) {
			return $return_original ? $source_id : 0;
		}
		$translated_id = apply_filters( 'wpml_object_id', $source_id, 'product', $return_original, $language_code );
		return (int) ( $translated_id ?? 0 );
	}
}
