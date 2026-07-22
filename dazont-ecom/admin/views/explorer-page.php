<?php
defined( 'ABSPATH' ) || exit;
/**
 * Product Explorer.
 *
 * One screen — a hierarchical, sortable LIST of categories (thumbnail, product
 * count, units sold, last "novelty search"), in two views:
 *   - Grouped  : the full site hierarchy (indented); parents include their
 *                sub-categories' products and sales; siblings ranked by the sort.
 *   - Detailed : flat list of only the categories that have their own products
 *                (empty container parents hidden), ranked on their own figures.
 * Clicking a row opens a full-screen overlay with that category's products and a
 * "Get AI insights" button.
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
		<strong class="dze-x-title"><?php esc_html_e( 'Category performance', 'dazont-ecom' ); ?></strong>
	</div>

	<section id="dze-x-perf" class="dze-x-perf">
		<div class="dze-x-perf-toolbar">
			<input type="search" id="dze-x-perf-search" class="dze-x-perf-search" placeholder="<?php esc_attr_e( 'Search categories…', 'dazont-ecom' ); ?>" />

			<div class="dze-x-views">
				<button type="button" class="dze-x-view-btn is-active" data-view="grouped"><?php esc_html_e( 'Grouped (site hierarchy)', 'dazont-ecom' ); ?></button>
				<button type="button" class="dze-x-view-btn" data-view="detailed"><?php esc_html_e( 'Detailed (own products only)', 'dazont-ecom' ); ?></button>
			</div>

			<label class="dze-x-perf-ctl"><?php esc_html_e( 'Sort', 'dazont-ecom' ); ?>
				<select id="dze-x-perf-sort">
					<option value="qty"><?php esc_html_e( 'Units sold', 'dazont-ecom' ); ?></option>
					<option value="res"><?php esc_html_e( 'Last search', 'dazont-ecom' ); ?></option>
					<option value="name"><?php esc_html_e( 'Name', 'dazont-ecom' ); ?></option>
					<option value="seq"><?php esc_html_e( 'Site order', 'dazont-ecom' ); ?></option>
				</select>
			</label>
		</div>

		<div class="dze-x-list-head">
			<span><?php esc_html_e( 'Category', 'dazont-ecom' ); ?></span>
			<span class="dze-x-num"><?php esc_html_e( 'Products', 'dazont-ecom' ); ?></span>
			<span class="dze-x-num"><?php esc_html_e( 'Units sold', 'dazont-ecom' ); ?></span>
			<span><?php esc_html_e( 'Last search', 'dazont-ecom' ); ?></span>
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
				?>
				<div class="dze-x-row" role="button" tabindex="0"
					data-cat="<?php echo (int) $n['id']; ?>"
					data-parent="<?php echo (int) $r['parent']; ?>"
					data-depth="<?php echo (int) $r['depth']; ?>"
					data-seq="<?php echo (int) $r['seq']; ?>"
					data-name="<?php echo esc_attr( strtolower( implode( ' ', $r['path'] ) ) ); ?>"
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
						</span>
					</span>
					<span class="dze-x-num dze-x-row-count"></span>
					<span class="dze-x-num dze-x-row-qty"></span>
					<span class="dze-x-row-res"><?php echo $res_h ? esc_html( $res_h ) : '<span class="dze-x-never">—</span>'; ?></span>
					<span class="dze-x-row-act">
						<button type="button" class="button button-small dze-x-mark" data-cat="<?php echo (int) $n['id']; ?>"><?php esc_html_e( 'Mark searched', 'dazont-ecom' ); ?></button>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
		<p id="dze-x-perf-empty" class="dze-x-perf-empty" style="display:none;"><?php esc_html_e( 'No categories match.', 'dazont-ecom' ); ?></p>
	</section>

	<!-- ===================== Products overlay (per category) ===================== -->
	<div id="dze-x-overlay" class="dze-x-overlay" style="display:none;">
		<div class="dze-x-ov-head">
			<button type="button" id="dze-x-ov-close" class="button">&larr; <?php esc_html_e( 'Back', 'dazont-ecom' ); ?></button>
			<span class="dze-x-ov-thumb" id="dze-x-ov-thumb"></span>
			<span class="dze-x-ov-title" id="dze-x-ov-title"></span>
			<span id="dze-x-count" class="dze-x-count"></span>
			<button type="button" id="dze-x-ai" class="button button-primary"><?php esc_html_e( '✨ Get AI insights', 'dazont-ecom' ); ?></button>
		</div>
		<div id="dze-x-ai-panel" class="dze-x-ai-panel" style="display:none;"></div>
		<div id="dze-x-grid" class="dze-x-grid"></div>
		<div class="dze-x-more">
			<button type="button" id="dze-x-load" class="button" style="display:none;"><?php esc_html_e( 'Load more', 'dazont-ecom' ); ?></button>
			<span id="dze-x-status" class="dze-x-status"></span>
		</div>
	</div>

	<div class="dze-gal-modal" id="dze-x-modal" style="display:none;"><div class="dze-gal-modal__inner"></div></div>
</div>
