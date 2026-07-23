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

	/**
	 * Keyword statuses. '' = unset. 'variation' = covered only by a variation
	 * value of a grouped product (weak for long-tail SEO — no dedicated page).
	 */
	public const STATUSES = [ 'covered', 'variation', 'gap', 'to_source', 'uncertain', 'ignored' ];

	private const BATCH        = 150; // keywords judged per AI call.
	private const MAX_PRODUCTS = 400; // product titles sent per call.
	private const MATCH_MODEL  = 'claude-haiku-4-5-20251001';

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
		add_action( 'wp_ajax_dze_kw_estimate',    [ $this, 'ajax_estimate' ] );
		add_action( 'wp_ajax_dze_kw_analyze',     [ $this, 'ajax_analyze' ] );
		add_action( 'wp_ajax_dze_kw_autotitles',  [ $this, 'ajax_autotitles' ] );
		add_action( 'wp_ajax_dze_kw_for_product', [ $this, 'ajax_for_product' ] );
		add_action( 'wp_ajax_dze_kw_opps',        [ $this, 'ajax_opportunities' ] );
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
			"SELECT term_id, COUNT(*) AS kw, SUM(status IN ('gap','to_source')) AS gaps, SUM(kw_type = '') AS pending
			 FROM {$table} GROUP BY term_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			ARRAY_A
		);
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['term_id'] ] = [ 'kw' => (int) $r['kw'], 'gaps' => (int) $r['gaps'], 'pending' => (int) $r['pending'] ];
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
		$parsed = self::parse_csv( $raw );
		if ( null === $parsed ) {
			wp_send_json_error( [ 'message' => __( 'Could not read any data rows in this file.', 'dazont-ecom' ) ] );
		}

		$token = wp_generate_uuid4();
		set_transient( 'dze_kw_up_' . $token, [ 'headers' => $parsed['headers'], 'rows' => $parsed['rows'] ], HOUR_IN_SECONDS );

		wp_send_json_success( [
			'token'   => $token,
			'headers' => $parsed['headers'],
			'guess'   => self::guess_map( $parsed['headers'] ),
			'sample'  => array_slice( $parsed['rows'], 0, 5 ),
			'total'   => count( $parsed['rows'] ),
		] );
	}

	/**
	 * Parses a raw CSV export: auto-detects encoding (BOM/UTF-16/Windows-1252)
	 * and delimiter (, ; tab). Returns [ 'headers' => [...], 'rows' => [...] ]
	 * or null when nothing readable. Pure function — no WordPress dependency —
	 * so the import pipeline is testable outside WP.
	 */
	public static function parse_csv( string $raw ): ?array {
		if ( strncmp( $raw, "\xFF\xFE", 2 ) === 0 || strncmp( $raw, "\xFE\xFF", 2 ) === 0 ) {
			$raw = (string) mb_convert_encoding( $raw, 'UTF-8', 'UTF-16' );
		}
		$raw = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
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
			return null;
		}
		$headers = array_shift( $lines );
		return [ 'headers' => $headers, 'rows' => $lines ];
	}

	/**
	 * Guesses which column holds each field. Exact header match first, then a
	 * contains-based pass so variants like "CPC (USD)" or "Search Volume" map
	 * themselves ("Keyword Difficulty" is excluded from the keyword search).
	 *
	 * @return array{keyword:int,volume:int,kd:int,cpc:int,intent:int} -1 = not found.
	 */
	public static function guess_map( array $headers ): array {
		$find = static function ( array $exact, array $contains, array $not = [] ) use ( $headers ): int {
			foreach ( $headers as $i => $h ) {
				if ( in_array( strtolower( trim( (string) $h ) ), $exact, true ) ) {
					return $i;
				}
			}
			foreach ( $headers as $i => $h ) {
				$h = strtolower( (string) $h );
				foreach ( $not as $n ) {
					if ( strpos( $h, $n ) !== false ) {
						continue 2;
					}
				}
				foreach ( $contains as $c ) {
					if ( strpos( $h, $c ) !== false ) {
						return $i;
					}
				}
			}
			return -1;
		};
		return [
			'keyword' => $find( [ 'keyword' ], [ 'keyword' ], [ 'difficulty', 'intent' ] ),
			'volume'  => $find( [ 'volume', 'search volume' ], [ 'volume' ] ),
			'kd'      => $find( [ 'keyword difficulty', 'kd', 'difficulty' ], [ 'difficulty' ] ),
			'cpc'     => $find( [ 'cpc' ], [ 'cpc' ] ),
			'intent'  => $find( [ 'intent' ], [ 'intent' ] ),
		];
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

		// MERGE import: keywords already in the set keep their status/matching
		// and only refresh volume/KD/CPC/intent; new keywords are added
		// unanalysed. The list stays alive across fresh SEMrush exports.
		$existing = [];
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, keyword FROM {$table} WHERE term_id = %d", $term_id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			$existing[ mb_strtolower( (string) $r['keyword'] ) ] = (int) $r['id'];
		}
		$seen  = [];
		$added = 0;
		$upd   = 0;
		foreach ( (array) $parsed['rows'] as $row ) {
			$kw  = trim( (string) ( $row[ $map['keyword'] ] ?? '' ) );
			$low = mb_strtolower( $kw );
			if ( $kw === '' || mb_strlen( $kw ) > 191 || isset( $seen[ $low ] ) ) {
				continue;
			}
			$seen[ $low ] = true;
			$fields = [
				'volume'  => (int) round( self::num( $row[ $map['volume'] ?? -1 ] ?? '' ) ),
				'kd'      => self::num_or_null( $row[ $map['kd'] ?? -1 ] ?? '' ),
				'cpc'     => self::num_or_null( $row[ $map['cpc'] ?? -1 ] ?? '' ),
				'intent'  => sanitize_text_field( (string) ( $row[ $map['intent'] ?? -1 ] ?? '' ) ),
				'updated' => $now,
			];
			if ( isset( $existing[ $low ] ) ) {
				$wpdb->update( $table, $fields, [ 'id' => $existing[ $low ] ] );
				$upd++;
			} else {
				$wpdb->insert( $table, $fields + [ 'term_id' => $term_id, 'keyword' => $kw, 'status' => '' ] );
				$added++;
			}
			if ( $added + $upd >= self::MAX_ROWS ) {
				break;
			}
		}
		delete_transient( 'dze_kw_up_' . $token );
		wp_send_json_success( [ 'imported' => $added, 'updated' => $upd ] );
	}

	/** Tolerant number parse: "1 300", "39,5", "0.56", "1.300,5". */
	public static function num( $v ): float {
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

	public static function num_or_null( $v ): ?float {
		$v = trim( (string) $v );
		return ( $v === '' || ! preg_match( '/\d/', $v ) ) ? null : self::num( $v );
	}

	/**
	 * Paged, SQL-filtered keyword listing (200 rows per page) + whole-set
	 * metrics. Everything heavy stays on the server: the browser only ever
	 * holds one page, which keeps 5000-keyword sets instant.
	 */
	public function ajax_list(): void {
		$this->guard();
		$term_id = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0;
		if ( ! $term_id ) {
			wp_send_json_error( [ 'message' => __( 'Unknown category.', 'dazont-ecom' ) ] );
		}
		$paged  = max( 1, (int) ( $_POST['paged'] ?? 1 ) );
		$q      = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
		$vmin   = ( $_POST['vmin'] ?? '' ) !== '' ? (int) $_POST['vmin'] : null;
		$kdmax  = ( $_POST['kdmax'] ?? '' ) !== '' ? (float) $_POST['kdmax'] : null;
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		$intent = sanitize_text_field( wp_unslash( $_POST['intent'] ?? '' ) );
		$sortk  = sanitize_key( wp_unslash( $_POST['sortk'] ?? 'volume' ) );
		$sortd  = 'asc' === ( $_POST['sortd'] ?? '' ) ? 'ASC' : 'DESC';
		$cols   = [ 'keyword' => 'keyword', 'volume' => 'volume', 'kd' => 'kd', 'cpc' => 'cpc', 'intent' => 'intent', 'status' => 'status' ];
		$orderby = ( $cols[ $sortk ] ?? 'volume' ) . ' ' . $sortd;

		global $wpdb;
		$table = self::table();
		$where = $wpdb->prepare( 'term_id = %d', $term_id );
		if ( $q !== '' ) {
			$where .= $wpdb->prepare( ' AND keyword LIKE %s', '%' . $wpdb->esc_like( $q ) . '%' );
		}
		if ( null !== $vmin ) {
			$where .= $wpdb->prepare( ' AND volume >= %d', $vmin );
		}
		if ( null !== $kdmax ) {
			$where .= $wpdb->prepare( ' AND (kd IS NULL OR kd <= %f)', $kdmax );
		}
		if ( 'none' === $status ) {
			$where .= " AND status = ''";
		} elseif ( $status !== '' && in_array( $status, self::STATUSES, true ) ) {
			$where .= $wpdb->prepare( ' AND status = %s', $status );
		}
		if ( $intent !== '' ) {
			$where .= $wpdb->prepare( ' AND intent LIKE %s', '%' . $wpdb->esc_like( $intent ) . '%' );
		}

		$per   = 200;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- where built with prepare above.
		$rows  = $wpdb->get_results(
			"SELECT id, keyword, volume, kd, cpc, intent, status, kw_type FROM {$table} WHERE {$where} ORDER BY {$orderby}, id ASC LIMIT " . ( ( $paged - 1 ) * $per ) . ", {$per}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above; orderby whitelisted.
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
				't'      => (string) $r['kw_type'],
			];
		}

		// Whole-set metrics (independent of the filters), computed in SQL.
		$m = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
					SUM(status = 'ignored') AS ignored,
					SUM(CASE WHEN status <> 'ignored' THEN volume ELSE 0 END) AS vol,
					SUM(CASE WHEN status <> 'ignored' AND cpc IS NOT NULL THEN cpc * volume ELSE 0 END) AS cpcw,
					SUM(CASE WHEN status <> 'ignored' AND cpc IS NOT NULL THEN volume ELSE 0 END) AS cpcv,
					AVG(CASE WHEN status <> 'ignored' THEN kd ELSE NULL END) AS kd,
					SUM(status IN ('gap','to_source')) AS gaps,
					SUM(CASE WHEN kw_type NOT IN ('category','info') AND status <> 'ignored' THEN 1 ELSE 0 END) AS prod_total,
					SUM(CASE WHEN kw_type NOT IN ('category','info') AND status IN ('covered','variation') THEN 1 ELSE 0 END) AS covered
				 FROM {$table} WHERE term_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
				$term_id
			),
			ARRAY_A
		);
		$intents = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT intent FROM {$table} WHERE term_id = %d AND intent <> '' LIMIT 40", $term_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.

		wp_send_json_success( [
			'rows'    => $out,
			'total'   => $total,
			'per'     => $per,
			'paged'   => $paged,
			'metrics' => array_map( 'floatval', (array) $m ),
			'intents' => array_values( array_unique( (array) $intents ) ),
		] );
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

	// =========================================================================
	// AI matching engine (phase 2): products ↔ keywords
	// =========================================================================

	private static function match_model(): string {
		$m = trim( (string) ( DZE_Marketing_Ai::get_settings()['match_model'] ?? '' ) );
		return $m !== '' ? $m : self::MATCH_MODEL;
	}

	/** Products of a category (children included): [ id, title, attributes ]. */
	private function product_lines( int $term_id ): array {
		$q = new WP_Query( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => self::MAX_PRODUCTS,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $term_id, 'include_children' => true ] ],
		] );
		$lines = [];
		foreach ( $q->posts as $pid ) {
			$product = wc_get_product( (int) $pid );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$attrs = [];
			foreach ( $product->get_attributes() as $attr ) {
				if ( ! is_object( $attr ) ) {
					continue;
				}
				$names = [];
				if ( $attr->is_taxonomy() ) {
					foreach ( (array) $attr->get_terms() as $t ) {
						$names[] = $t->name;
					}
				} else {
					$names = $attr->get_options();
				}
				if ( $names ) {
					$attrs[] = wc_attribute_label( $attr->get_name() ) . ': ' . implode( ', ', array_slice( $names, 0, 12 ) );
				}
			}
			$lines[] = [ 'id' => (int) $pid, 'title' => $product->get_name(), 'attrs' => implode( ' | ', $attrs ) ];
		}
		return $lines;
	}

	/** Cost preview shown before launching the analysis. cat=0 → ALL categories. */
	public function ajax_estimate(): void {
		$this->guard();
		$term_id = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0;
		global $wpdb;
		$table = self::table();
		if ( $term_id ) {
			$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE term_id = %d AND kw_type = ''", $term_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		} else {
			$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE kw_type = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		}
		if ( ! $pending ) {
			wp_send_json_error( [ 'message' => __( 'Nothing left to analyse — every imported keyword already has a verdict.', 'dazont-ecom' ) ] );
		}
		$products = $term_id ? count( $this->product_lines( $term_id ) ) : (int) wp_count_posts( 'product' )->publish;
		$batches  = (int) ceil( $pending / self::BATCH );
		// ~2.5k input + ~1.5k output tokens per batch on Haiku pricing.
		$cost = $batches * ( 2500 * 1.0 + 1500 * 5.0 ) / 1000000;
		wp_send_json_success( [
			'message' => sprintf(
				/* translators: 1: keywords, 2: products, 3: batches, 4: estimated cost, 5: model */
				__( "AI keyword analysis\n\n%1\$d keywords × %2\$d products, in %3\$d batches.\nEstimated cost: ~$%4\$s (%5\$s).\n\nLaunch?", 'dazont-ecom' ),
				$pending,
				$products,
				$batches,
				number_format_i18n( max( 0.01, $cost ), 2 ),
				self::match_model()
			),
		] );
	}

	/**
	 * Analyses ONE batch of not-yet-analysed keywords and stores the verdicts.
	 * The browser calls this in a loop until `remaining` hits 0, so progress is
	 * visible and nothing runs unattended.
	 */
	public function ajax_analyze(): void {
		$this->guard();
		$term_id = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0;
		$bulk    = ( 0 === $term_id );
		global $wpdb;
		$table = self::table();
		if ( $bulk ) {
			// Bulk mode: pick the next category that still has unanalysed keywords.
			$term_id = (int) $wpdb->get_var( "SELECT term_id FROM {$table} WHERE kw_type = '' ORDER BY term_id LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			if ( ! $term_id ) {
				wp_send_json_success( [ 'processed' => 0, 'remaining' => 0 ] );
			}
		}
		$term = get_term( $term_id, 'product_cat' );
		if ( ! $term instanceof WP_Term ) {
			wp_send_json_error( [ 'message' => __( 'Unknown category.', 'dazont-ecom' ) ] );
		}
		if ( ! class_exists( 'DZE_Marketing_Ai' ) || DZE_Marketing_Ai::api_key() === '' ) {
			wp_send_json_error( [ 'message' => __( 'Add your Anthropic API key under AI Settings first.', 'dazont-ecom' ) ] );
		}
		if ( DZE_Ai_Usage::over_budget() ) {
			wp_send_json_error( [ 'message' => DZE_Ai_Usage::budget_message() ] );
		}

		$batch = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, keyword, volume FROM {$table} WHERE term_id = %d AND kw_type = '' ORDER BY volume DESC LIMIT %d", $term_id, self::BATCH ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			ARRAY_A
		);
		if ( empty( $batch ) ) {
			wp_send_json_success( [ 'processed' => 0, 'remaining' => 0 ] );
		}

		$products = $this->product_lines( $term_id );
		$plist    = '';
		foreach ( $products as $p ) {
			$plist .= $p['id'] . ' | ' . $p['title'] . ( $p['attrs'] ? ' | ' . $p['attrs'] : '' ) . "\n";
		}
		$klist = '';
		foreach ( $batch as $k ) {
			$klist .= $k['id'] . '. ' . $k['keyword'] . ' (vol ' . $k['volume'] . ")\n";
		}

		$system = 'You are a meticulous e-commerce SEO analyst. You judge whether search queries are answered by a product catalogue. Reply with a JSON array ONLY — no prose, no code fences.';
		$user = "Product category: {$term->name}\n\n"
			. "PRODUCTS (id | title | attributes):\n{$plist}\n"
			. "SEARCH QUERIES (id. query (monthly volume)):\n{$klist}\n"
			. "For EACH query, decide:\n"
			. "t (type): \"product\" = shopper looks for a specific product; \"category\" = generic query answered by the category page itself (e.g. \"military t shirts\"); \"info\" = informational/navigational/off-topic (how-to, brand marketplaces like amazon, unrelated).\n"
			. "s (status): \"covered\" = a shopper typing this query would find EXACTLY what they asked for in one of the products. The product must satisfy EVERY qualifier of the query: material (cotton), colour (gray), style (parody, novelty, vintage), fit/size, theme, model. A specific themed/graphic product NEVER covers a broader or different query: a \"Size Matters Bullet Tshirt\" does NOT cover \"army cotton t shirt\", \"military parody t shirts\" or \"gray army t shirt\" — those are gaps unless a product matches all their qualifiers. Only near-identical wording or true synonyms of the SAME product count (\"kalashnikov tshirt\" = \"AK 47 T-shirt\"; hyphen/plural/spelling variants of one need share the verdict). \"variation\" = every qualifier is satisfied ONLY via an attribute value (e.g. the queried colour exists as a variation of a matching product), no dedicated product. \"gap\" = no product satisfies all qualifiers, even if products share words with the query. \"ignored\" = every query with t=info. For t=category use \"covered\" (the category page answers it). \"uncertain\" only when genuinely undecidable.\n"
			. "p: array of the product ids that cover it ([] when none).\n"
			. "When in doubt between covered and gap, choose gap: false positives poison our sourcing list.\n"
			. 'Output: JSON array of {"id":<query id>,"t":"...","s":"...","p":[ids]} for every query id listed.';

		try {
			$raw = $this->call_claude_kw( $system, $user );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		$raw     = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', trim( $raw ) );
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) && preg_match( '/\[.*\]/s', $raw, $m ) ) {
			$decoded = json_decode( $m[0], true );
		}
		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( [ 'message' => __( 'The AI returned an unreadable result. Try again.', 'dazont-ecom' ) ] );
		}

		$valid_ids = array_column( $batch, 'id' );
		$valid_ids = array_combine( $valid_ids, $valid_ids );
		$now       = current_time( 'mysql' );
		$done      = [];
		foreach ( $decoded as $v ) {
			$id = (int) ( $v['id'] ?? 0 );
			if ( ! isset( $valid_ids[ $id ] ) ) {
				continue;
			}
			$type   = in_array( $v['t'] ?? '', [ 'product', 'category', 'info' ], true ) ? $v['t'] : 'product';
			$status = in_array( $v['s'] ?? '', self::STATUSES, true ) ? $v['s'] : 'uncertain';
			$pids   = implode( ',', array_filter( array_map( 'intval', (array) ( $v['p'] ?? [] ) ) ) );
			$wpdb->update( $table, [ 'kw_type' => $type, 'status' => $status, 'products' => $pids, 'updated' => $now ], [ 'id' => $id ] );
			$done[ $id ] = true;
		}
		// Anything the model skipped: mark analysed as uncertain so the loop ends.
		foreach ( $valid_ids as $id ) {
			if ( ! isset( $done[ $id ] ) ) {
				$wpdb->update( $table, [ 'kw_type' => 'product', 'status' => 'uncertain', 'updated' => $now ], [ 'id' => $id ] );
			}
		}
		$remaining = $bulk
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE kw_type = ''" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			: (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE term_id = %d AND kw_type = ''", $term_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		wp_send_json_success( [ 'processed' => count( $valid_ids ), 'remaining' => $remaining, 'termName' => $term->name ] );
	}

	/** Anthropic call for the matcher: budget-guarded, recorded, Haiku by default. */
	private function call_claude_kw( string $system, string $user ): string {
		$resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
			'timeout' => 120,
			'headers' => [
				'x-api-key'         => DZE_Marketing_Ai::api_key(),
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			],
			'body'    => wp_json_encode( [
				'model'      => self::match_model(),
				'max_tokens' => 8000,
				'system'     => $system,
				'messages'   => [ [ 'role' => 'user', 'content' => $user ] ],
			] ),
		] );
		if ( is_wp_error( $resp ) ) {
			throw new RuntimeException( $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			throw new RuntimeException( (string) ( $data['error']['message'] ?? ( 'HTTP ' . $code ) ) );
		}
		DZE_Ai_Usage::record( 'anthropic', (int) ( $data['usage']['input_tokens'] ?? 0 ), (int) ( $data['usage']['output_tokens'] ?? 0 ), self::match_model() );
		$text = '';
		foreach ( (array) ( $data['content'] ?? [] ) as $block ) {
			if ( ( $block['type'] ?? '' ) === 'text' ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}
		return $text;
	}

	/**
	 * After an analysis: every product of the category that covers NO keyword
	 * gets its own title added as a covered long-tail keyword (volume 0). This
	 * captures supplier-driven products SEMrush can't surface ("F22 Raptor
	 * Tshirt") without any manual input.
	 */
	public function ajax_autotitles(): void {
		$this->guard();
		$term_id = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0;
		if ( ! $term_id ) {
			wp_send_json_error( [ 'message' => __( 'Unknown category.', 'dazont-ecom' ) ] );
		}
		$covered = self::coverage_counts( $term_id );
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );
		$added = 0;
		foreach ( $this->product_lines( $term_id ) as $p ) {
			if ( ! empty( $covered[ $p['id'] ] ) ) {
				continue;
			}
			$kw = strtolower( trim( (string) preg_replace( '/\s+/', ' ', $p['title'] ) ) );
			if ( $kw === '' || mb_strlen( $kw ) > 191 ) {
				continue;
			}
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE term_id = %d AND LOWER(keyword) = %s", $term_id, $kw ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			if ( $exists ) {
				continue;
			}
			$wpdb->insert( $table, [
				'term_id' => $term_id,
				'keyword' => $kw,
				'volume'  => 0,
				'intent'  => '',
				'kw_type' => 'product',
				'status'  => 'covered',
				'products'=> (string) $p['id'],
				'updated' => $now,
			] );
			$added++;
		}
		wp_send_json_success( [ 'added' => $added ] );
	}

	/** covered/variation keyword count per product id, for one category set. */
	public static function coverage_counts( int $term_id ): array {
		global $wpdb;
		$table = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}
		$rows = $wpdb->get_col(
			$wpdb->prepare( "SELECT products FROM {$table} WHERE term_id = %d AND status IN ('covered','variation') AND products <> ''", $term_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		);
		$out = [];
		foreach ( (array) $rows as $list ) {
			foreach ( explode( ',', (string) $list ) as $pid ) {
				$pid = (int) $pid;
				if ( $pid ) {
					$out[ $pid ] = ( $out[ $pid ] ?? 0 ) + 1;
				}
			}
		}
		return $out;
	}

	/**
	 * All sourcing opportunities across every category: keywords with status
	 * gap or to_source, sorted by volume — the shop-wide shopping list.
	 */
	public function ajax_opportunities(): void {
		$this->guard();
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			"SELECT term_id, keyword, volume, kd, cpc, status FROM {$table}
			 WHERE status IN ('gap','to_source') ORDER BY volume DESC LIMIT 1000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			ARRAY_A
		);
		$names = [];
		$out   = [];
		foreach ( (array) $rows as $r ) {
			$tid = (int) $r['term_id'];
			if ( ! isset( $names[ $tid ] ) ) {
				$t             = get_term( $tid, 'product_cat' );
				$names[ $tid ] = $t instanceof WP_Term ? $t->name : ( '#' . $tid );
			}
			$out[] = [
				'cat'    => $tid,
				'catName'=> $names[ $tid ],
				'kw'     => (string) $r['keyword'],
				'vol'    => (int) $r['volume'],
				'kd'     => null === $r['kd'] ? null : (float) $r['kd'],
				'cpc'    => null === $r['cpc'] ? null : (float) $r['cpc'],
				'status' => (string) $r['status'],
			];
		}
		wp_send_json_success( [ 'rows' => $out ] );
	}

	/** The keywords a given product covers (for the product-card popup). */
	public function ajax_for_product(): void {
		$this->guard();
		$term_id = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0;
		$pid     = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		if ( ! $term_id || ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Unknown product.', 'dazont-ecom' ) ] );
		}
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT keyword, volume, status FROM {$table}
				 WHERE term_id = %d AND status IN ('covered','variation') AND FIND_IN_SET(%s, products)
				 ORDER BY volume DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
				$term_id,
				(string) $pid
			),
			ARRAY_A
		);
		wp_send_json_success( [
			'title' => get_the_title( $pid ),
			'rows'  => array_map( static fn( $r ) => [ 'kw' => $r['keyword'], 'vol' => (int) $r['volume'], 's' => $r['status'] ], (array) $rows ),
		] );
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
