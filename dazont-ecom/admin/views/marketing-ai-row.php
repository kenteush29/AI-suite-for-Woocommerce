<?php
defined( 'ABSPATH' ) || exit;
/**
 * One suggestion row for the AI Marketing review table.
 *
 * @var array $sug
 */
$sug = wp_parse_args( $sug, [
	'id' => '', 'title' => '', 'percent' => 0, 'start_date' => '', 'end_date' => '',
	'languages' => [], 'email_subject' => '', 'rationale' => '', 'countries' => [],
	'klaviyo_email' => false,
] );
$langs     = implode( ', ', (array) $sug['languages'] );
$countries = implode( ', ', (array) $sug['countries'] );
?>
<tr class="dze-mai-row" data-id="<?php echo esc_attr( $sug['id'] ); ?>">
	<td>
		<input type="text" class="large-text dze-f-title" value="<?php echo esc_attr( $sug['title'] ); ?>" />
		<?php if ( ! empty( $sug['rationale'] ) ) : ?>
			<p class="description" style="margin:4px 0 0;"><?php echo esc_html( $sug['rationale'] ); ?></p>
		<?php endif; ?>
		<?php if ( $countries ) : ?>
			<p class="description" style="margin:2px 0 0;"><?php printf( esc_html__( 'Countries: %s', 'dazont-ecom' ), '<code>' . esc_html( $countries ) . '</code>' ); ?></p>
		<?php endif; ?>
		<label class="description" style="display:block;margin-top:4px;">
			<?php esc_html_e( 'Email subject:', 'dazont-ecom' ); ?>
			<input type="text" class="regular-text dze-f-subject" style="width:100%;" value="<?php echo esc_attr( $sug['email_subject'] ); ?>" />
		</label>
	</td>
	<td><input type="number" min="1" max="90" class="dze-f-percent" style="width:60px;" value="<?php echo esc_attr( (int) $sug['percent'] ); ?>" />%</td>
	<td><input type="date" class="dze-f-start" value="<?php echo esc_attr( $sug['start_date'] ); ?>" /></td>
	<td><input type="date" class="dze-f-end" value="<?php echo esc_attr( $sug['end_date'] ); ?>" /></td>
	<td><input type="text" class="dze-f-langs" style="width:90px;" value="<?php echo esc_attr( $langs ); ?>" /></td>
	<td style="text-align:center;"><input type="checkbox" class="dze-f-klaviyo" <?php checked( ! empty( $sug['klaviyo_email'] ) ); ?> /></td>
	<td>
		<button type="button" class="button button-primary dze-mai-accept"><?php esc_html_e( 'Accept', 'dazont-ecom' ); ?></button>
		<button type="button" class="button-link dze-mai-refuse" style="color:#b32d2e;"><?php esc_html_e( 'Discard', 'dazont-ecom' ); ?></button>
		<div class="dze-mai-row-status" style="font-size:12px;margin-top:4px;"></div>
	</td>
</tr>
