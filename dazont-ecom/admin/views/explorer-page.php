<?php
defined( 'ABSPATH' ) || exit;
/**
 * Product Explorer shell.
 *
 * @var array $categories  Nested category tree (id/name/count/sales_qty/sales_rev/image/children).
 * @var array $attributes  wc_get_attribute_taxonomies()
 */

/** Recursive renderer for the category rail. */
if ( ! function_exists( 'dze_explorer_cat_list' ) ) {
	function dze_explorer_cat_list( array $nodes, int &$idx ): void {
		if ( empty( $nodes ) ) {
			return;
		}
		echo '<ul class="dze-x-cats">';
		foreach ( $nodes as $n ) {
			$idx++;
			$rev      = (float) ( $n['sales_rev'] ?? 0 );
			$rev_dir  = (float) ( $n['sales_rev_direct'] ?? 0 );
			$fmt      = static function ( float $v ): string {
				return function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $v ) ) : number_format_i18n( $v, 2 );
			};
			$res_ts   = (int) ( $n['researched'] ?? 0 );
			$res_h    = $res_ts ? sprintf( /* translators: %s: human time difference, e.g. "3 months" */ __( '%s ago', 'dazont-ecom' ), human_time_diff( $res_ts ) ) : '';
			echo '<li>';
			printf(
				'<a href="#" class="dze-x-cat" data-cat="%1$d" data-idx="%2$d" data-count="%3$d" data-count-direct="%4$d" data-qty="%5$d" data-qty-direct="%6$d" data-rev="%7$s" data-rev-direct="%8$s" data-revfmt="%9$s" data-revfmt-direct="%10$s" data-res="%11$d" data-res-h="%12$s">%13$s<span class="dze-x-cat-name">%14$s</span><span class="dze-x-cat-count">%3$d</span></a>',
				(int) $n['id'],
				$idx,
				(int) $n['count'],
				(int) ( $n['count_direct'] ?? 0 ),
				(int) round( (float) ( $n['sales_qty'] ?? 0 ) ),
				(int) round( (float) ( $n['sales_qty_direct'] ?? 0 ) ),
				esc_attr( (string) $rev ),
				esc_attr( (string) $rev_dir ),
				esc_attr( $fmt( $rev ) ),
				esc_attr( $fmt( $rev_dir ) ),
				$res_ts,
				esc_attr( $res_h ),
				$n['image'] ? '<img src="' . esc_url( $n['image'] ) . '" alt="" />' : '<span class="dze-x-cat-noimg"></span>',
				esc_html( $n['name'] )
			);
			if ( ! empty( $n['children'] ) ) {
				dze_explorer_cat_list( $n['children'], $idx );
			}
			echo '</li>';
		}
		echo '</ul>';
	}
}
$dze_cat_idx = 0;
?>
<div class="wrap dze-x-wrap">

	<div class="dze-x-topbar">
		<strong class="dze-x-title"><?php esc_html_e( 'Product Explorer', 'dazont-ecom' ); ?></strong>
		<button type="button" id="dze-x-focus" class="button button-small"><?php esc_html_e( 'Focus mode', 'dazont-ecom' ); ?></button>
	</div>

	<div id="dze-explorer" class="dze-x-app">
		<aside class="dze-x-side">
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

			<div class="dze-x-cat-head">
				<label class="dze-x-catsort-lbl" for="dze-x-catsort"><?php esc_html_e( 'Categories', 'dazont-ecom' ); ?></label>
				<select id="dze-x-catsort">
					<option value="az"><?php esc_html_e( 'A → Z', 'dazont-ecom' ); ?></option>
					<option value="qty"><?php esc_html_e( 'By units sold', 'dazont-ecom' ); ?></option>
					<option value="rev"><?php esc_html_e( 'By revenue', 'dazont-ecom' ); ?></option>
				</select>
				<select id="dze-x-catscope">
					<option value="roll"><?php esc_html_e( 'Incl. sub-categories', 'dazont-ecom' ); ?></option>
					<option value="direct"><?php esc_html_e( 'This category only', 'dazont-ecom' ); ?></option>
				</select>
				<a href="#" class="dze-x-cat dze-x-cat-all is-active" data-cat="0"><?php esc_html_e( 'All products', 'dazont-ecom' ); ?></a>
			</div>
			<?php dze_explorer_cat_list( $categories, $dze_cat_idx ); ?>
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
