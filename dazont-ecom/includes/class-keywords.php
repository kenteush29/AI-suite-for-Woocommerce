<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sourcing Assistant — SEO keywords per category (SEMrush imports).
 *
 * Stores one keyword set per product category in a dedicated table (the CSV is
 * only an exchange format: import replaces the category's set, export gives it
 * back). The Keyword Workbench UI lives in the category overlay of the
 * Sourcing Assistant: sortable/filterable table, manual statuses, bulk actions
 * and per-category metrics (volume, weighted CPC, average KD, completion).
 *
 * Import is deliberately tolerant — SEMrush exports vary by account/locale —
 * so the delimiter, encoding and decimal style are auto-detected and the
 * column mapping is confirmed by the user before anything is written.
 *
 * Phase 1: no AI involved anywhere here. The `kw_type` and `products` columns
 * are reserved for the phase-2 matching engine.
 */
final class DZE_Keywords {

	private const NONCE          = 'dze_kw';
	private const SCHEMA_OPT     = 'dze_kw_schema';
	private const SCHEMA_VERSION = 1;
	private const MAX_ROWS       = 5000;   // per category — safety cap.
	private const MAX_UPLOAD     = 5242880; // 5 MB.

	/** Manual statuses (phase 1). '' = unset. 'variation' arrives with phase 2. */
	public const STATUSES = [ 'covered', 'gap', 'uncertain', 'ignored' ];

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_init', [ $this, 'maybe_install' ] );
		add_action( 'wp_ajax_dze_kw_upload', [ $this, 'ajax_upload' ] );
		add_action( 'wp_ajax_dze_kw_import', [ $this, 'ajax_import' ] );
		add_action( 'wp_ajax_dze_kw_list',   [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_dze_kw_status', [ $this, 'ajax_status' ] );
		add_action( 'wp_ajax_dze_kw_clear',  [ $this, 'ajax_clear' ] );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dze_keywords';
	}

	public static function nonce(): string {
		return wp_create_nonce( self::NONCE );
	}

	/** Creates/updates the keywords table when the schema version changes. */
	public function maybe_install(): void {
		if ( (int) get_option( self::SCHEMA_OPT, 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			term_id BIGINT UNSIGNED NOT NULL,
			keyword VARCHAR(191) NOT NULL,
			volume INT UNSIGNED NOT NULL DEFAULT 0,
			kd DECIMAL(5,2) NULL,
			cpc DECIMAL(8,2) NULL,
			intent VARCHAR(60) NOT NULL DEFAULT '',
			kw_type VARCHAR(20) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT '',
			products TEXT NULL,
			updated DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY term_id (term_id),
			KEY term_status (term_id,status)
		) {$charset};" );
		update_option( self::SCHEMA_OPT, self::SCHEMA_VERSION, false );
	}

	/**
	 * Keyword + gap counts per category, for the performance list badges.
	 *
	 * @return array<int,array{kw:int,gaps:int}>
	 */
	public static function counts_by_term(): array {
		global $wpdb;
		$table = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}
		$rows = $wpdb->get_results(
			"SELECT term_id, COUNT(*) AS kw, SUM(status = 'gap') AS gaps
			 FROM {$table} GROUP BY term_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			ARRAY_A
		);
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['term_id'] ] = [ 'kw' => (int) $r['kw'], 'gaps' => (int) $r['gaps'] ];
		}
		return $out;
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	private function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
	}

	/**
	 * Step 1 of the import: receives the CSV, parses it fully, stores the parsed
	 * rows in a short-lived transient and returns headers + guessed column
	 * mapping + a sample, so the user can confirm before anything is written.
	 */
	public function ajax_upload(): void {
		$this->guard();
		if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'No file received.', 'dazont-ecom' ) ] );
		}
		if ( (int) ( $_FILES['file']['size'] ?? 0 ) > self::MAX_UPLOAD ) {
			wp_send_json_error( [ 'message' => __( 'File too large (5 MB max).', 'dazont-ecom' ) ] );
		}
		$raw = (string) file_get_contents( $_FILES['file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		if ( $raw === '' ) {
			wp_send_json_error( [ 'message' => __( 'Empty file.', 'dazont-ecom' ) ] );
		}

		// Encoding: strip BOMs, fall back from UTF-16/Windows-1252 to UTF-8.
		if ( strncmp( $raw, "\xFF\xFE", 2 ) === 0 || strncmp( $raw, "\xFE\xFF", 2 ) === 0 ) {
			$raw = (string) mb_convert_encoding( $raw, 'UTF-8', 'UTF-16' );
		}
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = (string) mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
		}

		// Delimiter: whichever of ; , tab dominates the header line.
		$first = strtok( $raw, "\r\n" ) ?: '';
		$delim = ',';
		$best  = substr_count( $first, ',' );
		foreach ( [ ';', "\t" ] as $d ) {
			if ( substr_count( $first, $d ) > $best ) {
				$best  = substr_count( $first, $d );
				$delim = $d;
			}
		}

		// Full parse via a temp stream (handles quoted fields properly).
		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory stream.
		fwrite( $fh, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $fh );
		$lines = [];
		while ( ( $row = fgetcsv( $fh, 0, $delim, '"', '\\' ) ) !== false ) {
			if ( count( $row ) === 1 && trim( (string) $row[0] ) === '' ) {
				continue;
			}
			$lines[] = array_map( static fn( $v ) => trim( (string) $v ), $row );
			if ( count( $lines ) > self::MAX_ROWS + 1 ) {
				break;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( count( $lines ) < 2 ) {
			wp_send_json_error( [ 'message' => __( 'Could not read any data rows in this file.', 'dazont-ecom' ) ] );
		}

		$headers = array_shift( $lines );

		// Guess the mapping from common SEMrush header names.
		$guess  = [ 'keyword' => -1, 'volume' => -1, 'kd' => -1, 'cpc' => -1, 'intent' => -1 ];
		$needle = [
			'keyword' => [ 'keyword' ],
			'volume'  => [ 'volume', 'search volume' ],
			'kd'      => [ 'keyword difficulty', 'kd', 'difficulty' ],
			'cpc'     => [ 'cpc' ],
			'intent'  => [ 'intent' ],
		];
		foreach ( $headers as $i => $h ) {
			$h = strtolower( $h );
			foreach ( $needle as $field => $names ) {
				if ( $guess[ $field ] === -1 && in_array( $h, $names, true ) ) {
					$guess[ $field ] = $i;
				}
			}
		}

		$token = wp_generate_uuid4();
		set_transient( 'dze_kw_up_' . $token, [ 'headers' => $headers, 'rows' => $lines ], HOUR_IN_SECONDS );

		wp_send_json_success( [
			'token'   => $token,
			'headers' => $headers,
			'guess'   => $guess,
			'sample'  => array_slice( $lines, 0, 5 ),
			'total'   => count( $lines ),
		] );
	}

	/** Step 2: user confirmed the mapping — replace the category's keyword set. */
	public function ajax_import(): void {
		$this->guard();
		$term_id = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0;
		$token   = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$map     = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? array_map( 'intval', wp_unslash( $_POST['map'] ) ) : [];
		if ( ! $term_id || ! term_exists( $term_id, 'product_cat' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown category.', 'dazont-ecom' ) ] );
		}
		$parsed = get_transient( 'dze_kw_up_' . $token );
		if ( ! is_array( $parsed ) || empty( $parsed['rows'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Upload expired — please upload the file again.', 'dazont-ecom' ) ] );
		}
		if ( ! isset( $map['keyword'] ) || $map['keyword'] < 0 ) {
			wp_send_json_error( [ 'message' => __( 'Pick which column holds the keyword.', 'dazont-ecom' ) ] );
		}

		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );
		$seen  = [];
		$count = 0;

		$wpdb->delete( $table, [ 'term_id' => $term_id ], [ '%d' ] ); // one CSV per category: import replaces.

		foreach ( (array) $parsed['rows'] as $row ) {
			$kw = trim( (string) ( $row[ $map['keyword'] ] ?? '' ) );
			if ( $kw === '' || mb_strlen( $kw ) > 191 || isset( $seen[ mb_strtolower( $kw ) ] ) ) {
				continue;
			}
			$seen[ mb_strtolower( $kw ) ] = true;
			$wpdb->insert( $table, [
				'term_id' => $term_id,
				'keyword' => $kw,
				'volume'  => (int) round( $this->num( $row[ $map['volume'] ?? -1 ] ?? '' ) ),
				'kd'      => $this->num_or_null( $row[ $map['kd'] ?? -1 ] ?? '' ),
				'cpc'     => $this->num_or_null( $row[ $map['cpc'] ?? -1 ] ?? '' ),
				'intent'  => sanitize_text_field( (string) ( $row[ $map['intent'] ?? -1 ] ?? '' ) ),
				'status'  => '',
				'updated' => $now,
			] );
			$count++;
			if ( $count >= self::MAX_ROWS ) {
				break;
			}
		}
		delete_transient( 'dze_kw_up_' . $token );
		wp_send_json_success( [ 'imported' => $count ] );
	}

	/** Tolerant number parse: "1 300", "39,5", "0.56", "1.300,5". */
	private function num( $v ): float {
		$v = str_replace( [ ' ', "\xC2\xA0", '%', '$', '€' ], '', (string) $v );
		if ( $v === '' || ! preg_match( '/\d/', $v ) ) {
			return 0.0;
		}
		if ( strpos( $v, ',' ) !== false && strpos( $v, '.' ) !== false ) {
			// Both present: the last one is the decimal separator.
			$v = strrpos( $v, ',' ) > strrpos( $v, '.' )
				? str_replace( [ '.', ',' ], [ '', '.' ], $v )
				: str_replace( ',', '', $v );
		} else {
			$v = str_replace( ',', '.', $v );
		}
		return (float) $v;
	}

	private function num_or_null( $v ): ?float {
		$v = trim( (string) $v );
		return ( $v === '' || ! preg_match( '/\d/', $v ) ) ? null : $this->num( $v );
	}

	/** Full keyword set of a category (the Workbench renders/filters client-side). */
	public function ajax_list(): void {
		$this->guard();
		$term_id = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0;
		if ( ! $term_id ) {
			wp_send_json_error( [ 'message' => __( 'Unknown category.', 'dazont-ecom' ) ] );
		}
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, keyword, volume, kd, cpc, intent, status
				 FROM {$table} WHERE term_id = %d ORDER BY volume DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
				$term_id
			),
			ARRAY_A
		);
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [
				'id'     => (int) $r['id'],
				'kw'     => (string) $r['keyword'],
				'vol'    => (int) $r['volume'],
				'kd'     => null === $r['kd'] ? null : (float) $r['kd'],
				'cpc'    => null === $r['cpc'] ? null : (float) $r['cpc'],
				'intent' => (string) $r['intent'],
				'status' => (string) $r['status'],
			];
		}
		wp_send_json_success( [ 'rows' => $out ] );
	}

	/** Set the status of one or many keywords ('' clears it). */
	public function ajax_status(): void {
		$this->guard();
		$ids    = array_values( array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) ) ) );
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( $status !== '' && ! in_array( $status, self::STATUSES, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown status.', 'dazont-ecom' ) ] );
		}
		if ( empty( $ids ) ) {
			wp_send_json_error( [ 'message' => __( 'Nothing selected.', 'dazont-ecom' ) ] );
		}
		global $wpdb;
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, updated = %s WHERE id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table + int placeholders.
				array_merge( [ $status, current_time( 'mysql' ) ], $ids )
			)
		);
		wp_send_json_success();
	}

	/** Remove the whole keyword set of a category. */
	public function ajax_clear(): void {
		$this->guard();
		$term_id = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0;
		if ( ! $term_id ) {
			wp_send_json_error( [ 'message' => __( 'Unknown category.', 'dazont-ecom' ) ] );
		}
		global $wpdb;
		$wpdb->delete( self::table(), [ 'term_id' => $term_id ], [ '%d' ] );
		wp_send_json_success();
	}
}
