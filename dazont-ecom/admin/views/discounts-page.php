<?php
defined( 'ABSPATH' ) || exit;
/**
 * List screen shared by "Marketing Events" and "Discounts" (filtered by $mode).
 *
 * @var array       $rules
 * @var array       $type_labels
 * @var array       $languages
 * @var string|null $notice
 * @var string      $mode        'events' or 'discounts'
 * @var string      $menu_slug
 * @var string      $page_title
 */
$admin_post = admin_url( 'admin-post.php' );
$new_url    = add_query_arg( [ 'page' => $menu_slug, 'new' => 1 ], admin_url( 'admin.php' ) );
$controller = DZE_Discounts::instance();
$is_events  = ( 'events' === $mode );
$gmc        = ( $is_events && class_exists( 'DZE_Gmc' ) ) ? DZE_Gmc::instance() : null;
$gmc_on     = $gmc && $gmc->is_configured();
?>
<div class="wrap dze-wrap">
	<h1 class="wp-heading-inline"><?php echo esc_html( $page_title ); ?></h1>
	<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php echo esc_html( $is_events ? __( 'Add event', 'dazont-ecom' ) : __( 'Add discount', 'dazont-ecom' ) ); ?></a>
	<hr class="wp-header-end" />

	<?php if ( $is_events && class_exists( 'DZE_Marketing_Ai' ) ) : ?>
		<?php DZE_Marketing_Ai::instance()->render_calendar_panel(); ?>
		<hr style="margin:24px 0;" />
	<?php endif; ?>

	<?php if ( $gmc_on ) : ?>
		<p>
			<button type="button" class="button" id="dze-gmc-sync-selected"><?php esc_html_e( 'Sync selected with GMC', 'dazont-ecom' ); ?></button>
			<span id="dze-gmc-bulk-status" style="margin-left:8px;font-size:13px;color:#666;"></span>
		</p>
	<?php endif; ?>

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
			<th><?php esc_html_e( 'Dates', 'dazont-ecom' ); ?></th>
			<th><?php esc_html_e( 'Status', 'dazont-ecom' ); ?></th>
			<?php if ( $gmc_on ) : ?><th><?php esc_html_e( 'GMC sync', 'dazont-ecom' ); ?></th><?php endif; ?>
			<th></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $rules ) ) :
			$colspan = 7 + ( ! empty( $languages ) ? 1 : 0 ) + ( $gmc_on ? 1 : 0 ); ?>
			<tr><td colspan="<?php echo (int) $colspan; ?>"><?php esc_html_e( 'No promotions yet. Click “Add promotion”.', 'dazont-ecom' ); ?></td></tr>
		<?php else : foreach ( $rules as $id => $r ) :
			$toggle_url = wp_nonce_url( add_query_arg( [ 'action' => 'dze_discount_toggle', 'rule' => $id ], $admin_post ), 'dze_discount_toggle' );
			$delete_url = wp_nonce_url( add_query_arg( [ 'action' => 'dze_discount_delete', 'rule' => $id ], $admin_post ), 'dze_discount_delete' );
			$edit_url   = add_query_arg( [ 'page' => $menu_slug, 'edit' => $id ], admin_url( 'admin.php' ) );
			$enabled    = ! empty( $r['enabled'] );
			$scope_txt  = ( $r['scope'] ?? 'all' ) === 'all' ? __( 'Whole store', 'dazont-ecom' ) : ( $r['scope'] === 'categories' ? __( 'Categories', 'dazont-ecom' ) : __( 'Products', 'dazont-ecom' ) );
			$start_txt  = ! empty( $r['start'] ) ? esc_html( $r['start'] ) : '<span style="color:#999;">' . esc_html__( 'no start', 'dazont-ecom' ) . '</span>';
			$end_txt    = ! empty( $r['end'] )   ? esc_html( $r['end'] )   : '<span style="color:#999;">' . esc_html__( 'no end', 'dazont-ecom' ) . '</span>';

			$status = $controller->rule_status( $r );
			$status_map = [
				'active'    => [ '#0a7040', '● ' . __( 'Active', 'dazont-ecom' ) ],
				'scheduled' => [ '#b26a00', '◔ ' . __( 'Scheduled', 'dazont-ecom' ) ],
				'passed'    => [ '#787c82', '◌ ' . __( 'Passed', 'dazont-ecom' ) ],
				'inactive'  => [ '#999999', '○ ' . __( 'Inactive', 'dazont-ecom' ) ],
			];
			$st = $status_map[ $status ] ?? $status_map['inactive'];
		?>
			<tr>
				<td><strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $r['title'] !== '' ? $r['title'] : '(untitled)' ); ?></a></strong></td>
				<td><?php echo esc_html( $type_labels[ $r['type'] ] ?? $r['type'] ); ?></td>
				<td><?php
				if ( ( $r['type'] ?? '' ) === 'bulk_order' ) {
					$pcts = array_map( static fn( $t ) => (float) ( $t['percent'] ?? 0 ), (array) ( $r['tiers'] ?? [] ) );
					echo $pcts
						? esc_html( sprintf( __( 'up to %s%%', 'dazont-ecom' ), rtrim( rtrim( (string) max( $pcts ), '0' ), '.' ) ) )
						: '<span style="color:#999;">—</span>';
				} else {
					echo esc_html( rtrim( rtrim( (string) ( $r['percent'] ?? 0 ), '0' ), '.' ) ) . '%';
				}
				?></td>
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
				<td>
					<?php if ( ( $r['type'] ?? '' ) === 'sale' ) : ?>
						<?php echo wp_kses_post( $start_txt ); ?> → <?php echo wp_kses_post( $end_txt ); ?>
					<?php else : ?>
						<span style="color:#999;">—</span>
					<?php endif; ?>
				</td>
				<td><span style="color:<?php echo esc_attr( $st[0] ); ?>;font-weight:600;"><?php echo esc_html( $st[1] ); ?></span></td>
				<?php if ( $gmc_on ) : ?>
				<td>
					<?php if ( ( $r['type'] ?? '' ) === 'sale' ) : ?>
						<label style="display:block;margin-bottom:3px;"><input type="checkbox" class="dze-gmc-cb" value="<?php echo esc_attr( $id ); ?>" /> <?php echo wp_kses_post( $gmc->sync_badges_html( $r ) ); ?></label>
						<a href="#" class="dze-gmc-sync-one" data-rule="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Sync now', 'dazont-ecom' ); ?></a>
						<span class="dze-gmc-feedback" style="font-size:12px;margin-left:6px;"></span>
					<?php else : ?>
						<span style="color:#999;">—</span>
					<?php endif; ?>
				</td>
				<?php endif; ?>
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
		<?php if ( $is_events ) : ?>
			<?php esc_html_e( 'Only one marketing event can be active at a time — overlapping ones are kept disabled.', 'dazont-ecom' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Discounts are evergreen: enable a rule once and it keeps applying (no schedule). They appear in the cart as a promo-code line — “Bundle” for a Bulk offer per item, “Wholesale” for a Bulk order.', 'dazont-ecom' ); ?>
		<?php endif; ?>
	</p>
</div>
