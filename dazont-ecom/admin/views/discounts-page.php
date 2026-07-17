<?php
defined( 'ABSPATH' ) || exit;
/**
 * List screen for Marketing & Discounts.
 *
 * @var array       $rules
 * @var array       $type_labels
 * @var array       $languages
 * @var string|null $notice
 */
$admin_post = admin_url( 'admin-post.php' );
$new_url    = add_query_arg( [ 'page' => DZE_Discounts::MENU_SLUG, 'new' => 1 ], admin_url( 'admin.php' ) );
$controller = DZE_Discounts::instance();
$dt_format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
?>
<div class="wrap dze-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Marketing & Discounts', 'dazont-ecom' ); ?></h1>
	<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add promotion', 'dazont-ecom' ); ?></a>
	<hr class="wp-header-end" />

	<?php if ( isset( $_GET['saved'] ) && $_GET['saved'] === '1' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Promotion saved.', 'dazont-ecom' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['deleted'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Promotion deleted.', 'dazont-ecom' ); ?></p></div>
	<?php endif; ?>
	<?php if ( ! empty( $notice ) ) : ?>
		<div class="notice notice-warning is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<table class="widefat striped">
		<thead><tr>
			<th><?php esc_html_e( 'Title', 'dazont-ecom' ); ?></th>
			<th><?php esc_html_e( 'Type', 'dazont-ecom' ); ?></th>
			<th><?php esc_html_e( 'Discount', 'dazont-ecom' ); ?></th>
			<th><?php esc_html_e( 'Scope', 'dazont-ecom' ); ?></th>
			<?php if ( ! empty( $languages ) ) : ?><th><?php esc_html_e( 'Languages', 'dazont-ecom' ); ?></th><?php endif; ?>
			<th><?php esc_html_e( 'Created', 'dazont-ecom' ); ?></th>
			<th><?php esc_html_e( 'Application dates', 'dazont-ecom' ); ?></th>
			<th><?php esc_html_e( 'Status', 'dazont-ecom' ); ?></th>
			<th></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $rules ) ) : ?>
			<tr><td colspan="9"><?php esc_html_e( 'No promotions yet. Click “Add promotion”.', 'dazont-ecom' ); ?></td></tr>
		<?php else : foreach ( $rules as $id => $r ) :
			$toggle_url = wp_nonce_url( add_query_arg( [ 'action' => 'dze_discount_toggle', 'rule' => $id ], $admin_post ), 'dze_discount_toggle' );
			$delete_url = wp_nonce_url( add_query_arg( [ 'action' => 'dze_discount_delete', 'rule' => $id ], $admin_post ), 'dze_discount_delete' );
			$edit_url   = add_query_arg( [ 'page' => DZE_Discounts::MENU_SLUG, 'edit' => $id ], admin_url( 'admin.php' ) );
			$enabled    = ! empty( $r['enabled'] );
			$scope_txt  = ( $r['scope'] ?? 'all' ) === 'all' ? __( 'Whole store', 'dazont-ecom' ) : ( $r['scope'] === 'categories' ? __( 'Categories', 'dazont-ecom' ) : __( 'Products', 'dazont-ecom' ) );
			$created    = ! empty( $r['created_at'] ) ? wp_date( $dt_format, (int) $r['created_at'] ) : '—';
			$start_txt  = ! empty( $r['start'] ) ? esc_html( str_replace( 'T', ' ', $r['start'] ) ) : '<span style="color:#999;">' . esc_html__( 'no start', 'dazont-ecom' ) . '</span>';
			$end_txt    = ! empty( $r['end'] )   ? esc_html( str_replace( 'T', ' ', $r['end'] ) )   : '<span style="color:#999;">' . esc_html__( 'no end', 'dazont-ecom' ) . '</span>';
		?>
			<tr>
				<td><strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $r['title'] !== '' ? $r['title'] : '(untitled)' ); ?></a></strong></td>
				<td><?php echo esc_html( $type_labels[ $r['type'] ] ?? $r['type'] ); ?></td>
				<td><?php echo esc_html( rtrim( rtrim( (string) ( $r['percent'] ?? 0 ), '0' ), '.' ) ); ?>%</td>
				<td><?php echo esc_html( $scope_txt ); ?></td>
				<?php if ( ! empty( $languages ) ) : ?>
				<td>
					<?php
					$flags = $controller->rule_language_flags( $r );
					if ( empty( $flags ) ) {
						echo '<span style="color:#999;">—</span>';
					} else {
						foreach ( $flags as $lang ) {
							if ( ! empty( $lang['flag'] ) ) {
								printf(
									'<img src="%1$s" alt="%2$s" title="%2$s" style="width:18px;height:12px;margin-right:3px;vertical-align:middle;border:1px solid #eee;" />',
									esc_url( $lang['flag'] ),
									esc_attr( $lang['native_name'] )
								);
							} else {
								echo '<span style="margin-right:5px;">' . esc_html( strtoupper( $lang['code'] ) ) . '</span>';
							}
						}
					}
					?>
				</td>
				<?php endif; ?>
				<td><?php echo esc_html( $created ); ?></td>
				<td>
					<?php if ( ( $r['type'] ?? '' ) === 'sale' ) : ?>
						<?php echo wp_kses_post( $start_txt ); ?> → <?php echo wp_kses_post( $end_txt ); ?>
					<?php else : ?>
						<span style="color:#999;">—</span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $enabled ) : ?>
						<span class="dze-status-ok">● <?php esc_html_e( 'Active', 'dazont-ecom' ); ?></span>
					<?php else : ?>
						<span style="color:#999;">○ <?php esc_html_e( 'Disabled', 'dazont-ecom' ); ?></span>
					<?php endif; ?>
				</td>
				<td style="text-align:right;white-space:nowrap;">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'dazont-ecom' ); ?></a> |
					<a href="<?php echo esc_url( $toggle_url ); ?>"><?php echo $enabled ? esc_html__( 'Disable', 'dazont-ecom' ) : esc_html__( 'Enable', 'dazont-ecom' ); ?></a> |
					<a href="<?php echo esc_url( $delete_url ); ?>" class="dze-danger" onclick="return confirm('<?php esc_attr_e( 'Delete this promotion?', 'dazont-ecom' ); ?>');"><?php esc_html_e( 'Delete', 'dazont-ecom' ); ?></a>
				</td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>

	<p class="description" style="margin-top:12px;">
		<?php esc_html_e( 'Only one promotion (scheduled sale) can be active at a time — overlapping ones are kept disabled.', 'dazont-ecom' ); ?>
	</p>
</div>
