<?php
defined( 'ABSPATH' ) || exit;
/**
 * Product Gallery admin page.
 *
 * @var WP_Query      $query
 * @var array|WP_Error $categories
 * @var string        $search
 * @var string        $cat
 * @var int           $paged
 * @var string        $base_url
 */
?>
<div class="wrap dze-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Products gallery', 'dazont-ecom' ); ?></h1>
	<hr class="wp-header-end" />

	<form method="get" action="<?php echo esc_url( $base_url ); ?>" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
		<input type="hidden" name="page" value="<?php echo esc_attr( DZE_Gallery::MENU_SLUG ); ?>" />
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search products…', 'dazont-ecom' ); ?>" />
		<?php if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) : ?>
			<select name="product_cat">
				<option value=""><?php esc_html_e( 'All categories', 'dazont-ecom' ); ?></option>
				<?php foreach ( $categories as $c ) : ?>
					<option value="<?php echo esc_attr( $c->slug ); ?>" <?php selected( $cat, $c->slug ); ?>><?php echo esc_html( $c->name ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'dazont-ecom' ); ?></button>
		<span class="description"><?php
			/* translators: %s: number of products found */
			echo esc_html( sprintf( _n( '%s product', '%s products', (int) $query->found_posts, 'dazont-ecom' ), number_format_i18n( (int) $query->found_posts ) ) );
		?></span>
	</form>

	<?php if ( ! $query->have_posts() ) : ?>
		<p><?php esc_html_e( 'No products found.', 'dazont-ecom' ); ?></p>
	<?php else : ?>
		<div class="dze-gal">
			<?php while ( $query->have_posts() ) : $query->the_post();
				$product = wc_get_product( get_the_ID() );
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
				$img_id    = (int) $product->get_image_id();
				$thumb     = $img_id ? wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src( 'woocommerce_thumbnail' );
				$full      = $img_id ? wp_get_attachment_image_url( $img_id, 'full' ) : $thumb;
				$is_var    = $product->is_type( 'variable' );
				$var_count = $is_var ? count( $product->get_children() ) : 0;
				$edit_link = get_edit_post_link( $product->get_id() );
			?>
				<div class="dze-gal__card">
					<div class="dze-thumb-wrap">
						<img class="dze-thumb dze-gal__img" src="<?php echo esc_url( $thumb ); ?>" data-full="<?php echo esc_url( $full ); ?>" alt="" loading="lazy" />
					</div>
					<div class="dze-gal__name"><?php echo esc_html( $product->get_name() ); ?></div>
					<div class="dze-gal__meta">
						<span class="dze-gal__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
						<span class="dze-gal__id">#<?php echo (int) $product->get_id(); ?></span>
					</div>
					<div class="dze-gal__actions">
						<?php if ( $edit_link ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $edit_link ); ?>"><?php esc_html_e( 'Edit', 'dazont-ecom' ); ?></a>
						<?php endif; ?>
						<?php if ( $is_var && $var_count > 0 ) : ?>
							<button type="button" class="button button-small dze-gal__vars" data-product="<?php echo (int) $product->get_id(); ?>">
								<?php
								/* translators: %d: number of variations */
								echo esc_html( sprintf( __( 'Variations (%d)', 'dazont-ecom' ), $var_count ) );
								?>
							</button>
						<?php endif; ?>
					</div>
				</div>
			<?php endwhile; wp_reset_postdata(); ?>
		</div>

		<?php
		$total_pages = (int) $query->max_num_pages;
		if ( $total_pages > 1 ) :
			$links = paginate_links( [
				'base'      => add_query_arg( 'paged', '%#%', add_query_arg( array_filter( [ 'page' => DZE_Gallery::MENU_SLUG, 's' => $search, 'product_cat' => $cat ] ), $base_url ) ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $total_pages,
				'prev_text' => '‹',
				'next_text' => '›',
			] );
			if ( $links ) : ?>
				<div class="tablenav"><div class="tablenav-pages" style="margin:12px 0;"><?php echo wp_kses_post( $links ); ?></div></div>
			<?php endif;
		endif; ?>
	<?php endif; ?>
</div>

<!-- Lightbox + variations popup targets -->
<div class="dze-gal-modal" id="dze-gal-modal" style="display:none;"><div class="dze-gal-modal__inner"></div></div>

<style>
	.dze-gal{display:grid;gap:18px;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));margin-top:16px;}
	.dze-gal__card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:10px;display:flex;flex-direction:column;gap:6px;}
	.dze-thumb-wrap{aspect-ratio:1/1;overflow:hidden;border-radius:8px;background:#f6f7f7;display:flex;align-items:center;justify-content:center;}
	.dze-gal__img{width:100%;height:100%;object-fit:cover;cursor:zoom-in;}
	.dze-gal__name{font-weight:600;font-size:13px;line-height:1.3;}
	.dze-gal__meta{display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#555;}
	.dze-gal__actions{display:flex;gap:6px;margin-top:auto;flex-wrap:wrap;}
	.dze-lightbox,.dze-gal-modal{position:fixed;inset:0;background:rgba(0,0,0,.8);display:flex;align-items:center;justify-content:center;z-index:100000;padding:24px;}
	.dze-lightbox img{max-width:92vw;max-height:92vh;border-radius:6px;}
	.dze-gal-modal__inner{background:#fff;border-radius:10px;max-width:900px;max-height:88vh;overflow:auto;padding:18px 20px;}
	.dze-gal-vargrid{display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));margin-top:10px;}
	.dze-gal-vargrid figure{margin:0;text-align:center;}
	.dze-gal-vargrid img{width:100%;height:auto;border-radius:6px;cursor:zoom-in;}
	.dze-gal-vargrid figcaption{font-size:12px;color:#555;margin-top:4px;}
</style>
