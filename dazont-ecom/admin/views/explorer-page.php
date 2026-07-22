<?php
defined( 'ABSPATH' ) || exit;
/**
 * Product Explorer shell.
 *
 * @var array $categories  Nested category tree (id/name/count/image/children).
 * @var array $attributes  wc_get_attribute_taxonomies()
 */

/** Recursive renderer for the category rail. */
if ( ! function_exists( 'dze_explorer_cat_list' ) ) {
	function dze_explorer_cat_list( array $nodes ): void {
		if ( empty( $nodes ) ) {
			return;
		}
		echo '<ul class="dze-x-cats">';
		foreach ( $nodes as $n ) {
			echo '<li>';
			printf(
				'<a href="#" class="dze-x-cat" data-cat="%1$d">%2$s<span class="dze-x-cat-name">%3$s</span><span class="dze-x-cat-count">%4$d</span></a>',
				(int) $n['id'],
				$n['image'] ? '<img src="' . esc_url( $n['image'] ) . '" alt="" />' : '<span class="dze-x-cat-noimg"></span>',
				esc_html( $n['name'] ),
				(int) $n['count']
			);
			if ( ! empty( $n['children'] ) ) {
				dze_explorer_cat_list( $n['children'] );
			}
			echo '</li>';
		}
		echo '</ul>';
	}
}
?>
<div class="wrap dze-x-wrap">
	<div id="dze-explorer" class="dze-x-app">
		<aside class="dze-x-side">
			<div class="dze-x-side-head">
				<strong><?php esc_html_e( 'Product Explorer', 'dazont-ecom' ); ?></strong>
				<button type="button" id="dze-x-focus" class="button button-small"><?php esc_html_e( 'Focus mode', 'dazont-ecom' ); ?></button>
			</div>

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
				<a href="#" class="dze-x-cat dze-x-cat-all is-active" data-cat="0"><?php esc_html_e( 'All products', 'dazont-ecom' ); ?></a>
			</div>
			<?php dze_explorer_cat_list( $categories ); ?>
		</aside>

		<main class="dze-x-main">
			<div class="dze-x-bar">
				<span id="dze-x-count" class="dze-x-count"></span>
				<span id="dze-x-crumb" class="dze-x-crumb"></span>
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
