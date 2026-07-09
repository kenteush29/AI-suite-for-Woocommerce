<?php
defined( 'ABSPATH' ) || exit;
/** @var RSTK_Restock_List_Table $table */
/** @var int $last_recalc */
?>
<div class="wrap rstk-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Restock', 'restock-for-woocommerce' ); ?></h1>

	<p class="description" style="margin:8px 0 16px; max-width:820px;">
		<?php esc_html_e( 'Product-lines with at least one out-of-stock item, ranked by total sales. Click a variable product to reveal its out-of-stock variations.', 'restock-for-woocommerce' ); ?>
	</p>

	<div class="rstk-toolbar" style="margin-bottom:12px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
		<button type="button" id="rstk-recalc" class="button button-secondary">
			<span class="dashicons dashicons-update" style="vertical-align:text-top;"></span>
			<?php esc_html_e( 'Recalculate sales now', 'restock-for-woocommerce' ); ?>
		</button>
		<span id="rstk-recalc-status" style="font-size:13px; color:#666;">
			<?php if ( $last_recalc ) : ?>
				<?php
				printf(
					/* translators: %s: formatted date/time */
					esc_html__( 'Sales cache last updated: %s', 'restock-for-woocommerce' ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_recalc ) )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'Sales cache never built — run a recalculation to populate the sales figures.', 'restock-for-woocommerce' ); ?>
			<?php endif; ?>
		</span>
	</div>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( RSTK_Restock::MENU_SLUG ); ?>" />
		<?php
		$table->search_box( __( 'Search products', 'restock-for-woocommerce' ), 'rstk-search' );
		$table->display();
		?>
	</form>
</div>
