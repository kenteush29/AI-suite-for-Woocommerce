<?php
defined( 'ABSPATH' ) || exit;
/**
 * Product Explorer.
 *
 * One screen — a visual, sortable grid of category cards (with thumbnails)
 * built around sales volume, in two views:
 *   - Grouped  : every category, parents include their sub-categories' sales.
 *   - Detailed : only categories that have their own products (empty container
 *                parents hidden), ranked on their own direct sales.
 * Clicking a card opens a full-screen overlay with that category's products and
 * a "Get AI insights" button that recaps the category and suggests what to
 * source next.
 *
 * @var array $categories  Nested category tree (id/name/count/count_direct/
 *                         sales_*(direct)/researched/image/children).
 */

/** Flatten the tree into rows carrying their full path. */
if ( ! function_exists( 'dze_explorer_flat_rows' ) ) {
	function dze_explorer_flat_rows( array $nodes, array $trail, array &$rows ): void {
		foreach ( $nodes as $n ) {
			$path   = array_merge( $trail, [ (string) $n['name'] ] );
			$rows[] = [ 'node' => $n, 'path' => $path ];
			if ( ! empty( $n['children'] ) ) {
				dze_explorer_flat_rows( $n['children'], $path, $rows );
			}
		}
	}
}
$dze_rows = [];
dze_explorer_flat_rows( $categories, [], $dze_rows );
?>
<div class="wrap dze-x-wrap">

	<div class="dze-x-topbar">
		<strong class="dze-x-title"><?php esc_html_e( 'Category performance', 'dazont-ecom' ); ?></strong>
	</div>

	<section id="dze-x-perf" class="dze-x-perf">
		<div class="dze-x-perf-toolbar">
			<input type="search" id="dze-x-perf-search" class="dze-x-perf-search" placeholder="<?php esc_attr_e( 'Search categories…', 'dazont-ecom' ); ?>" />

			<div class="dze-x-views">
				<button type="button" class="dze-x-view-btn is-active" data-view="grouped"><?php esc_html_e( 'Grouped (incl. sub-categories)', 'dazont-ecom' ); ?></button>
				<button type="button" class="dze-x-view-btn" data-view="detailed"><?php esc_html_e( 'Detailed (own products only)', 'dazont-ecom' ); ?></button>
			</div>

			<label class="dze-x-perf-ctl"><?php esc_html_e( 'Sort', 'dazont-ecom' ); ?>
				<select id="dze-x-perf-sort">
					<option value="qty"><?php esc_html_e( 'Units sold', 'dazont-ecom' ); ?></option>
					<option value="res"><?php esc_html_e( 'Last search', 'dazont-ecom' ); ?></option>
					<option value="name"><?php esc_html_e( 'Name', 'dazont-ecom' ); ?></option>
				</select>
			</label>
		</div>

		<div id="dze-x-ccards" class="dze-x-ccards">
			<?php foreach ( $dze_rows as $r ) :
				$n       = $r['node'];
				$res_ts  = (int) ( $n['researched'] ?? 0 );
				$res_h   = $res_ts ? sprintf( /* translators: %s: human time difference */ __( '%s ago', 'dazont-ecom' ), human_time_diff( $res_ts ) ) : '';
				$leaf    = end( $r['path'] );
				$parents = array_slice( $r['path'], 0, -1 );
				$thumb   = (string) ( $n['image'] ?? '' );
				?>
				<div class="dze-x-ccard" role="button" tabindex="0"
					data-cat="<?php echo (int) $n['id']; ?>"
					data-name="<?php echo esc_attr( strtolower( implode( ' ', $r['path'] ) ) ); ?>"
					data-path="<?php echo esc_attr( implode( ' › ', $r['path'] ) ); ?>"
					data-thumb="<?php echo esc_url( $thumb ); ?>"
					data-count="<?php echo (int) ( $n['count'] ?? 0 ); ?>"
					data-count-direct="<?php echo (int) ( $n['count_direct'] ?? 0 ); ?>"
					data-qty="<?php echo (int) round( (float) ( $n['sales_qty'] ?? 0 ) ); ?>"
					data-qty-direct="<?php echo (int) round( (float) ( $n['sales_qty_direct'] ?? 0 ) ); ?>"
					data-res="<?php echo $res_ts; ?>"
					data-res-h="<?php echo esc_attr( $res_h ); ?>">
					<div class="dze-x-ccard-thumb">
						<?php if ( $thumb ) : ?>
							<img src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy" />
						<?php else : ?>
							<span class="dze-x-ccard-noimg" aria-hidden="true">🗂️</span>
						<?php endif; ?>
					</div>
					<div class="dze-x-ccard-body">
						<?php if ( $parents ) : ?>
							<div class="dze-x-ccard-path"><?php echo esc_html( implode( ' › ', $parents ) ); ?> ›</div>
						<?php endif; ?>
						<div class="dze-x-ccard-name"><?php echo esc_html( $leaf ); ?></div>
						<div class="dze-x-ccard-metrics">
							<span class="dze-x-ccard-qty"></span>
							<span class="dze-x-ccard-res"><?php echo $res_h ? esc_html( $res_h ) : '<span class="dze-x-never">—</span>'; ?></span>
						</div>
					</div>
					<button type="button" class="button button-small dze-x-mark" data-cat="<?php echo (int) $n['id']; ?>"><?php esc_html_e( 'Mark searched', 'dazont-ecom' ); ?></button>
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
