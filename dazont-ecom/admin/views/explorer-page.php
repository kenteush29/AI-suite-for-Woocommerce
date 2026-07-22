<?php
defined( 'ABSPATH' ) || exit;
/**
 * Product Explorer shell — two modes:
 *   - Category performance : a flat, sortable table of every category with its
 *     sales (units / revenue), product count and last "novelty search" date.
 *     Built around the two priority axes: best sellers, and categories not
 *     researched in a long time. This is the "director" screen.
 *   - Browse products      : the visual product grid with a category rail and
 *     collapsible filters. Reached from the table via "View products".
 *
 * @var array $categories  Nested category tree (id/name/count/count_direct/
 *                         sales_*(direct)/researched/image/children).
 * @var array $attributes  wc_get_attribute_taxonomies()
 */

/** Recursive renderer for the (navigational) category rail in Browse mode. */
if ( ! function_exists( 'dze_explorer_cat_list' ) ) {
	function dze_explorer_cat_list( array $nodes ): void {
		if ( empty( $nodes ) ) {
			return;
		}
		echo '<ul class="dze-x-cats">';
		foreach ( $nodes as $n ) {
			$res_ts = (int) ( $n['researched'] ?? 0 );
			$res_h  = $res_ts ? sprintf( /* translators: %s: human time difference */ __( '%s ago', 'dazont-ecom' ), human_time_diff( $res_ts ) ) : '';
			echo '<li>';
			printf(
				'<a href="#" class="dze-x-cat" data-cat="%1$d" data-count="%2$d" data-res="%3$d" data-res-h="%4$s">%5$s<span class="dze-x-cat-name">%6$s</span><span class="dze-x-cat-count">%2$d</span></a>',
				(int) $n['id'],
				(int) $n['count'],
				$res_ts,
				esc_attr( $res_h ),
				$n['image'] ? '<img src="' . esc_url( $n['image'] ) . '" alt="" />' : '<span class="dze-x-cat-noimg"></span>',
				esc_html( $n['name'] )
			);
			if ( ! empty( $n['children'] ) ) {
				dze_explorer_cat_list( $n['children'] );
			}
			echo '</li>';
		}
		echo '</ul>';
	}
}

/** Flatten the category tree into rows carrying their full path. */
if ( ! function_exists( 'dze_explorer_flat_rows' ) ) {
	function dze_explorer_flat_rows( array $nodes, array $trail, array &$rows ): void {
		foreach ( $nodes as $n ) {
			$path   = array_merge( $trail, [ (string) $n['name'] ] );
			$rows[] = [
				'node'  => $n,
				'path'  => $path,
				'depth' => count( $trail ),
				'leaf'  => empty( $n['children'] ),
			];
			if ( ! empty( $n['children'] ) ) {
				dze_explorer_flat_rows( $n['children'], $path, $rows );
			}
		}
	}
}

$dze_fmt = static function ( float $v ): string {
	return function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $v ) ) : number_format_i18n( $v, 2 );
};
$dze_rows = [];
dze_explorer_flat_rows( $categories, [], $dze_rows );
?>
<div class="wrap dze-x-wrap">

	<div class="dze-x-topbar">
		<div class="dze-x-tabs">
			<button type="button" class="dze-x-tab is-active" data-mode="perf"><?php esc_html_e( 'Category performance', 'dazont-ecom' ); ?></button>
			<button type="button" class="dze-x-tab" data-mode="browse"><?php esc_html_e( 'Browse products', 'dazont-ecom' ); ?></button>
		</div>
		<button type="button" id="dze-x-focus" class="button button-small"><?php esc_html_e( 'Focus mode', 'dazont-ecom' ); ?></button>
	</div>

	<!-- ============================ PERFORMANCE ============================ -->
	<section id="dze-x-perf" class="dze-x-perf">
		<div class="dze-x-perf-toolbar">
			<input type="search" id="dze-x-perf-search" class="dze-x-perf-search" placeholder="<?php esc_attr_e( 'Search categories…', 'dazont-ecom' ); ?>" />

			<div class="dze-x-axes">
				<button type="button" class="button dze-x-axis is-active" data-axis="best"><?php esc_html_e( '🏆 Best sellers', 'dazont-ecom' ); ?></button>
				<button type="button" class="button dze-x-axis" data-axis="stale"><?php esc_html_e( '🕒 Needs restocking', 'dazont-ecom' ); ?></button>
			</div>

			<label class="dze-x-perf-ctl"><?php esc_html_e( 'Sales', 'dazont-ecom' ); ?>
				<select id="dze-x-perf-scope">
					<option value="direct"><?php esc_html_e( 'This category only', 'dazont-ecom' ); ?></option>
					<option value="roll"><?php esc_html_e( 'Incl. sub-categories', 'dazont-ecom' ); ?></option>
				</select>
			</label>
			<label class="dze-x-perf-ctl"><?php esc_html_e( 'Level', 'dazont-ecom' ); ?>
				<select id="dze-x-perf-level">
					<option value="all"><?php esc_html_e( 'All levels', 'dazont-ecom' ); ?></option>
					<option value="top"><?php esc_html_e( 'Top-level only', 'dazont-ecom' ); ?></option>
					<option value="leaf"><?php esc_html_e( 'Leaf categories only', 'dazont-ecom' ); ?></option>
				</select>
			</label>
		</div>

		<div class="dze-x-perf-tablewrap">
			<table class="dze-x-perf-table">
				<thead>
					<tr>
						<th class="dze-x-sortable" data-key="name"><?php esc_html_e( 'Category', 'dazont-ecom' ); ?></th>
						<th class="dze-x-sortable dze-x-num" data-key="count"><?php esc_html_e( 'Products', 'dazont-ecom' ); ?></th>
						<th class="dze-x-sortable dze-x-num" data-key="qty"><?php esc_html_e( 'Units sold', 'dazont-ecom' ); ?></th>
						<th class="dze-x-sortable dze-x-num" data-key="rev"><?php esc_html_e( 'Revenue', 'dazont-ecom' ); ?></th>
						<th class="dze-x-sortable" data-key="res"><?php esc_html_e( 'Last search', 'dazont-ecom' ); ?></th>
						<th class="dze-x-actions-h"><?php esc_html_e( 'Actions', 'dazont-ecom' ); ?></th>
					</tr>
				</thead>
				<tbody id="dze-x-perf-body">
					<?php foreach ( $dze_rows as $r ) :
						$n       = $r['node'];
						$res_ts  = (int) ( $n['researched'] ?? 0 );
						$res_h   = $res_ts ? sprintf( /* translators: %s: human time difference */ __( '%s ago', 'dazont-ecom' ), human_time_diff( $res_ts ) ) : '';
						$rev     = (float) ( $n['sales_rev'] ?? 0 );
						$rev_dir = (float) ( $n['sales_rev_direct'] ?? 0 );
						$leaf    = end( $r['path'] );
						$parents = array_slice( $r['path'], 0, -1 );
						?>
						<tr class="dze-x-prow"
							data-cat="<?php echo (int) $n['id']; ?>"
							data-name="<?php echo esc_attr( strtolower( implode( ' ', $r['path'] ) ) ); ?>"
							data-depth="<?php echo (int) $r['depth']; ?>"
							data-leaf="<?php echo $r['leaf'] ? 1 : 0; ?>"
							data-count="<?php echo (int) ( $n['count'] ?? 0 ); ?>"
							data-count-direct="<?php echo (int) ( $n['count_direct'] ?? 0 ); ?>"
							data-qty="<?php echo (int) round( (float) ( $n['sales_qty'] ?? 0 ) ); ?>"
							data-qty-direct="<?php echo (int) round( (float) ( $n['sales_qty_direct'] ?? 0 ) ); ?>"
							data-rev="<?php echo esc_attr( (string) $rev ); ?>"
							data-rev-direct="<?php echo esc_attr( (string) $rev_dir ); ?>"
							data-revfmt="<?php echo esc_attr( $dze_fmt( $rev ) ); ?>"
							data-revfmt-direct="<?php echo esc_attr( $dze_fmt( $rev_dir ) ); ?>"
							data-res="<?php echo $res_ts; ?>">
							<td class="dze-x-c-name">
								<?php if ( $parents ) : ?>
									<span class="dze-x-path"><?php echo esc_html( implode( ' › ', $parents ) ); ?> › </span>
								<?php endif; ?>
								<span class="dze-x-leaf"><?php echo esc_html( $leaf ); ?></span>
							</td>
							<td class="dze-x-num dze-x-c-count"></td>
							<td class="dze-x-num dze-x-c-qty"></td>
							<td class="dze-x-num dze-x-c-rev"></td>
							<td class="dze-x-c-res"><?php echo $res_h ? esc_html( $res_h ) : '<span class="dze-x-never">—</span>'; ?></td>
							<td class="dze-x-c-act">
								<button type="button" class="button button-small dze-x-view" data-cat="<?php echo (int) $n['id']; ?>"><?php esc_html_e( 'View products', 'dazont-ecom' ); ?></button>
								<button type="button" class="button button-small dze-x-mark" data-cat="<?php echo (int) $n['id']; ?>"><?php esc_html_e( 'Mark searched', 'dazont-ecom' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p id="dze-x-perf-empty" class="dze-x-perf-empty" style="display:none;"><?php esc_html_e( 'No categories match.', 'dazont-ecom' ); ?></p>
		</div>
	</section>

	<!-- ============================== BROWSE ============================== -->
	<div id="dze-explorer" class="dze-x-app" style="display:none;">
		<aside class="dze-x-side">
			<details class="dze-x-filters">
				<summary><?php esc_html_e( 'Search &amp; filters', 'dazont-ecom' ); ?></summary>
				<div class="dze-x-filters-body">
					<input type="search" id="dze-x-search" class="dze-x-search" placeholder="<?php esc_attr_e( 'Search products…', 'dazont-ecom' ); ?>" />
					<div class="dze-x-controls">
						<select id="dze-x-sort">
							<option value="date_desc"><?php esc_html_e( 'Newest added', 'dazont-ecom' ); ?></option>
							<option value="date_asc"><?php esc_html_e( 'Oldest added', 'dazont-ecom' ); ?></option>
							<option value="title_asc"><?php esc_html_e( 'Title A → Z', 'dazont-ecom' ); ?></option>
							<option value="title_desc"><?php esc_html_e( 'Title Z → A', 'dazont-ecom' ); ?></option>
						</select>
						<select id="dze-x-stock">
							<option value=""><?php esc_html_e( 'Any stock', 'dazont-ecom' ); ?></option>
							<option value="in"><?php esc_html_e( 'In stock', 'dazont-ecom' ); ?></option>
							<option value="out"><?php esc_html_e( 'Out of stock', 'dazont-ecom' ); ?></option>
						</select>
					</div>
					<?php if ( ! empty( $attributes ) ) : ?>
						<div class="dze-x-attrs">
							<?php foreach ( $attributes as $attr ) :
								$tax = wc_attribute_taxonomy_name( $attr->attribute_name );
								if ( ! taxonomy_exists( $tax ) ) {
									continue;
								}
								$terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => true ] );
								if ( is_wp_error( $terms ) || empty( $terms ) ) {
									continue;
								} ?>
								<select class="dze-x-attr" data-tax="<?php echo esc_attr( $tax ); ?>">
									<option value=""><?php echo esc_html( sprintf( /* translators: %s: attribute name */ __( '%s: any', 'dazont-ecom' ), $attr->attribute_label ) ); ?></option>
									<?php foreach ( $terms as $t ) : ?>
										<option value="<?php echo esc_attr( $t->slug ); ?>"><?php echo esc_html( $t->name ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</details>

			<div class="dze-x-cat-head">
				<a href="#" class="dze-x-cat dze-x-cat-all is-active" data-cat="0"><?php esc_html_e( 'All products', 'dazont-ecom' ); ?></a>
			</div>
			<?php dze_explorer_cat_list( $categories ); ?>
		</aside>

		<main class="dze-x-main">
			<div class="dze-x-bar">
				<span id="dze-x-count" class="dze-x-count"></span>
				<span id="dze-x-crumb" class="dze-x-crumb"></span>
				<span id="dze-x-research" class="dze-x-research" style="display:none;">
					<span class="dze-x-research-label"><?php esc_html_e( 'Last novelty search:', 'dazont-ecom' ); ?></span>
					<strong id="dze-x-research-when"></strong>
					<button type="button" id="dze-x-research-mark" class="button button-small"><?php esc_html_e( 'Mark searched today', 'dazont-ecom' ); ?></button>
				</span>
			</div>
			<div id="dze-x-grid" class="dze-x-grid"></div>
			<div class="dze-x-more">
				<button type="button" id="dze-x-load" class="button" style="display:none;"><?php esc_html_e( 'Load more', 'dazont-ecom' ); ?></button>
				<span id="dze-x-status" class="dze-x-status"></span>
			</div>
		</main>
	</div>

	<div class="dze-gal-modal" id="dze-x-modal" style="display:none;"><div class="dze-gal-modal__inner"></div></div>
</div>
