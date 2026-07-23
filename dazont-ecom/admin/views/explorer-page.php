<?php
defined( 'ABSPATH' ) || exit;
/**
 * Sourcing Assistant (Product Explorer).
 *
 * One screen — a hierarchical, sortable LIST of categories (thumbnail, product
 * count, units sold, last "novelty search"), in two views:
 *   - Grouped  : the full site hierarchy (indented, collapsible); parents
 *                include their sub-categories' products and sales.
 *   - Detailed : flat list of only the categories that have their own products
 *                (empty container parents hidden), ranked on their own figures.
 * Sorting is done by clicking the column headers. Clicking a row opens a
 * full-screen overlay with that category's products, a "Mark searched" button
 * and a "Get AI insights" button.
 *
 * @var array $categories  Nested category tree (id/name/count/count_direct/
 *                         sales_*(direct)/researched/image/children).
 */

/** Flatten the tree into rows carrying path, depth, parent id and a DFS index. */
if ( ! function_exists( 'dze_explorer_flat_rows' ) ) {
	function dze_explorer_flat_rows( array $nodes, array $trail, int $parent_id, array &$rows, int &$seq ): void {
		foreach ( $nodes as $n ) {
			$path   = array_merge( $trail, [ (string) $n['name'] ] );
			$rows[] = [
				'node'   => $n,
				'path'   => $path,
				'depth'  => count( $trail ),
				'parent' => $parent_id,
				'seq'    => $seq++,
			];
			if ( ! empty( $n['children'] ) ) {
				dze_explorer_flat_rows( $n['children'], $path, (int) $n['id'], $rows, $seq );
			}
		}
	}
}
$dze_rows = [];
$dze_seq  = 0;
dze_explorer_flat_rows( $categories, [], 0, $dze_rows, $dze_seq );
?>
<div class="wrap dze-x-wrap">

	<div class="dze-x-topbar">
		<strong class="dze-x-title"><?php esc_html_e( 'Sourcing Assistant — category performance', 'dazont-ecom' ); ?></strong>
	</div>

	<section id="dze-x-perf" class="dze-x-perf">
		<div class="dze-x-perf-toolbar">
			<input type="search" id="dze-x-perf-search" class="dze-x-perf-search" placeholder="<?php esc_attr_e( 'Search categories…', 'dazont-ecom' ); ?>" />

			<div class="dze-x-views">
				<button type="button" class="dze-x-view-btn is-active" data-view="grouped"><?php esc_html_e( 'Grouped (site hierarchy)', 'dazont-ecom' ); ?></button>
				<button type="button" class="dze-x-view-btn" data-view="detailed"><?php esc_html_e( 'Detailed (own products only)', 'dazont-ecom' ); ?></button>
			</div>

			<button type="button" id="dze-x-expand" class="button button-small"><?php esc_html_e( 'Expand all', 'dazont-ecom' ); ?></button>
			<button type="button" id="dze-x-opps-toggle" class="button button-small"><?php esc_html_e( '🎯 All opportunities', 'dazont-ecom' ); ?></button>
			<button type="button" id="dze-x-kw-bulk-ai" class="button button-small"><?php esc_html_e( '✨ Analyse all pending', 'dazont-ecom' ); ?></button>
			<span id="dze-x-global-prog" class="dze-x-kw-prog"></span>
		</div>

		<div class="dze-x-list-head">
			<button type="button" class="dze-x-col" data-key="name"><?php esc_html_e( 'Category', 'dazont-ecom' ); ?><span class="dze-x-arrow"></span></button>
			<button type="button" class="dze-x-col dze-x-num" data-key="count"><?php esc_html_e( 'Products', 'dazont-ecom' ); ?><span class="dze-x-arrow"></span></button>
			<button type="button" class="dze-x-col dze-x-num is-desc" data-key="qty"><?php esc_html_e( 'Units sold', 'dazont-ecom' ); ?><span class="dze-x-arrow"></span></button>
			<button type="button" class="dze-x-col" data-key="res"><?php esc_html_e( 'Last search', 'dazont-ecom' ); ?><span class="dze-x-arrow"></span></button>
			<span></span>
		</div>

		<div id="dze-x-list" class="dze-x-list is-grouped">
			<?php foreach ( $dze_rows as $r ) :
				$n       = $r['node'];
				$res_ts  = (int) ( $n['researched'] ?? 0 );
				$res_h   = $res_ts ? sprintf( /* translators: %s: human time difference */ __( '%s ago', 'dazont-ecom' ), human_time_diff( $res_ts ) ) : '';
				$leaf    = end( $r['path'] );
				$parents = array_slice( $r['path'], 0, -1 );
				$thumb   = (string) ( $n['image'] ?? '' );
				$haskids = ! empty( $n['children'] );
				?>
				<div class="dze-x-row" role="button" tabindex="0"
					data-cat="<?php echo (int) $n['id']; ?>"
					data-parent="<?php echo (int) $r['parent']; ?>"
					data-depth="<?php echo (int) $r['depth']; ?>"
					data-seq="<?php echo (int) $r['seq']; ?>"
					data-haschild="<?php echo $haskids ? 1 : 0; ?>"
					data-name="<?php echo esc_attr( strtolower( implode( ' ', $r['path'] ) ) ); ?>"
					data-leaf="<?php echo esc_attr( strtolower( (string) $leaf ) ); ?>"
					data-path="<?php echo esc_attr( implode( ' › ', $r['path'] ) ); ?>"
					data-thumb="<?php echo esc_url( $thumb ); ?>"
					data-count="<?php echo (int) ( $n['count'] ?? 0 ); ?>"
					data-count-direct="<?php echo (int) ( $n['count_direct'] ?? 0 ); ?>"
					data-qty="<?php echo (int) round( (float) ( $n['sales_qty'] ?? 0 ) ); ?>"
					data-qty-direct="<?php echo (int) round( (float) ( $n['sales_qty_direct'] ?? 0 ) ); ?>"
					data-res="<?php echo $res_ts; ?>"
					data-res-h="<?php echo esc_attr( $res_h ); ?>">
					<span class="dze-x-row-cat">
						<span class="dze-x-row-indent"></span>
						<?php if ( $haskids ) : ?>
							<button type="button" class="dze-x-tog" aria-label="<?php esc_attr_e( 'Expand / collapse', 'dazont-ecom' ); ?>">▸</button>
						<?php else : ?>
							<span class="dze-x-tog dze-x-tog-sp" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="dze-x-row-thumb">
							<?php if ( $thumb ) : ?>
								<img src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy" />
							<?php else : ?>
								<span class="dze-x-row-noimg" aria-hidden="true">🗂️</span>
							<?php endif; ?>
						</span>
						<span class="dze-x-row-names">
							<span class="dze-x-row-name"><?php echo esc_html( $leaf ); ?></span>
							<?php if ( $parents ) : ?>
								<span class="dze-x-row-path"><?php echo esc_html( implode( ' › ', $parents ) ); ?></span>
							<?php endif; ?>
							<span class="dze-x-row-kwbadge"<?php echo empty( $n['kw'] ) ? ' style="display:none;"' : ''; ?>><?php
								if ( ! empty( $n['kw'] ) ) {
									/* translators: 1: number of keywords, 2: number of gaps */
									echo esc_html( sprintf( __( '%1$s kw · %2$s opportunities', 'dazont-ecom' ), number_format_i18n( (int) $n['kw'] ), number_format_i18n( (int) $n['gaps'] ) ) );
								}
							?></span>
						</span>
					</span>
					<span class="dze-x-num dze-x-row-count"></span>
					<span class="dze-x-num dze-x-row-qty"></span>
					<span class="dze-x-row-res"><?php echo $res_h ? esc_html( $res_h ) : '<span class="dze-x-never">—</span>'; ?></span>
					<span class="dze-x-row-act">
						<span class="dze-x-ico" title="<?php echo (int) ( $n['count'] ?? 0 ) > 0 ? esc_attr__( 'Category live (has products)', 'dazont-ecom' ) : esc_attr__( 'Category empty — not visible on the storefront yet', 'dazont-ecom' ); ?>"><?php echo (int) ( $n['count'] ?? 0 ) > 0 ? '🟢' : '⚪'; ?></span>
						<span class="dze-x-ico dze-x-ico-kw <?php echo empty( $n['kw'] ) ? 'is-off' : ''; ?>" title="<?php echo empty( $n['kw'] ) ? esc_attr__( 'No keyword set imported', 'dazont-ecom' ) : ( (int) ( $n['pending'] ?? 0 ) > 0 ? esc_attr__( 'Keyword set imported — analysis pending', 'dazont-ecom' ) : esc_attr__( 'Keyword set imported & analysed', 'dazont-ecom' ) ); ?>"><?php echo empty( $n['kw'] ) ? '🔍' : ( (int) ( $n['pending'] ?? 0 ) > 0 ? '⏳' : '🔑' ); ?></span>
						<button type="button" class="button button-small dze-x-imp" data-cat="<?php echo (int) $n['id']; ?>" title="<?php esc_attr_e( 'Import a SEMrush CSV for this category', 'dazont-ecom' ); ?>">📥</button>
						<button type="button" class="button button-small dze-x-an" data-cat="<?php echo (int) $n['id']; ?>" title="<?php esc_attr_e( 'Analyse this category\'s keywords with AI', 'dazont-ecom' ); ?>" <?php echo empty( $n['kw'] ) ? 'style="display:none;"' : ''; ?>>✨</button>
						<a class="button button-small" href="<?php echo esc_url( get_edit_term_link( (int) $n['id'], 'product_cat' ) ?: '#' ); ?>" title="<?php esc_attr_e( 'Edit the WooCommerce category', 'dazont-ecom' ); ?>" target="_blank" rel="noopener" onclick="event.stopPropagation();">✏️</a>
						<button type="button" class="button button-small dze-x-mark" data-cat="<?php echo (int) $n['id']; ?>"><?php esc_html_e( 'Mark searched', 'dazont-ecom' ); ?></button>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
		<p id="dze-x-perf-empty" class="dze-x-perf-empty" style="display:none;"><?php esc_html_e( 'No categories match.', 'dazont-ecom' ); ?></p>

		<!-- Shop-wide opportunities (gaps + to-source across every category) -->
		<div id="dze-x-opps" class="dze-x-kw-table" style="display:none;"></div>
	</section>

	<!-- ===================== Products overlay (per category) ===================== -->
	<div id="dze-x-overlay" class="dze-x-overlay" style="display:none;">
		<div class="dze-x-ov-head">
			<button type="button" id="dze-x-ov-close" class="button">&larr; <?php esc_html_e( 'Back', 'dazont-ecom' ); ?></button>
			<span class="dze-x-ov-thumb" id="dze-x-ov-thumb"></span>
			<span class="dze-x-ov-title" id="dze-x-ov-title"></span>
			<span id="dze-x-count" class="dze-x-count"></span>
			<span class="dze-x-ov-actions">
				<button type="button" id="dze-x-kw-toggle" class="button">🔑 <?php esc_html_e( 'Keywords', 'dazont-ecom' ); ?></button>
				<button type="button" id="dze-x-ov-mark" class="button"><?php esc_html_e( 'Mark searched today', 'dazont-ecom' ); ?></button>
				<button type="button" id="dze-x-ai" class="button button-primary"><?php esc_html_e( '🎯 See opportunities', 'dazont-ecom' ); ?></button>
			</span>
		</div>
		<div id="dze-x-ai-panel" class="dze-x-ai-panel" style="display:none;"></div>

		<!-- Keyword Workbench (SEMrush set of the open category) -->
		<div id="dze-x-kw" class="dze-x-kwpanel" style="display:none;">
			<div class="dze-x-kw-bar">
				<span id="dze-x-kw-metrics" class="dze-x-kw-metrics"></span>
				<span class="dze-x-kw-tools">
					<input type="search" id="dze-x-kw-q" placeholder="<?php esc_attr_e( 'Filter keywords…', 'dazont-ecom' ); ?>" />
					<input type="number" id="dze-x-kw-vmin" min="0" placeholder="<?php esc_attr_e( 'Vol ≥', 'dazont-ecom' ); ?>" />
					<input type="number" id="dze-x-kw-kdmax" min="0" max="100" placeholder="<?php esc_attr_e( 'KD ≤', 'dazont-ecom' ); ?>" />
					<select id="dze-x-kw-status"></select>
					<select id="dze-x-kw-intent"></select>
				</span>
				<span class="dze-x-kw-actions">
					<select id="dze-x-kw-bulk"></select>
					<button type="button" id="dze-x-kw-apply" class="button"><?php esc_html_e( 'Apply', 'dazont-ecom' ); ?></button>
					<button type="button" id="dze-x-kw-ai" class="button button-primary"><?php esc_html_e( '✨ Analyse with AI', 'dazont-ecom' ); ?></button>
					<span id="dze-x-kw-prog" class="dze-x-kw-prog"></span>
					<button type="button" id="dze-x-kw-import" class="button"><?php esc_html_e( 'Import CSV', 'dazont-ecom' ); ?></button>
					<button type="button" id="dze-x-kw-export" class="button"><?php esc_html_e( 'Export', 'dazont-ecom' ); ?></button>
					<button type="button" id="dze-x-kw-delete" class="button-link" style="color:#b32d2e;"><?php esc_html_e( 'Delete set', 'dazont-ecom' ); ?></button>
					<input type="file" id="dze-x-kw-file" accept=".csv,text/csv,text/plain" style="display:none;" />
				</span>
			</div>
			<div id="dze-x-kw-table" class="dze-x-kw-table"></div>
		</div>

		<div id="dze-x-subcats" class="dze-x-subcats" style="display:none;"></div>
		<div id="dze-x-grid" class="dze-x-grid"></div>
		<div class="dze-x-more">
			<button type="button" id="dze-x-load" class="button" style="display:none;"><?php esc_html_e( 'Load more', 'dazont-ecom' ); ?></button>
			<span id="dze-x-status" class="dze-x-status"></span>
		</div>
	</div>

	<div class="dze-gal-modal" id="dze-x-modal" style="display:none;"><div class="dze-gal-modal__inner"></div></div>
</div>
