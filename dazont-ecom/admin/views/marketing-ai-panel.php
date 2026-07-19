<?php
defined( 'ABSPATH' ) || exit;
/**
 * AI calendar generator + suggestions review — embedded at the top of the
 * Marketing Events page.
 *
 * @var bool   $has_key
 * @var array  $suggestions
 * @var array  $languages   Active site languages ([code, native_name, flag]).
 * @var string $primary     Primary language code (pre-selected).
 */
$ai_settings_url = add_query_arg( [ 'page' => DZE_Marketing_Ai::MENU_SLUG ], admin_url( 'admin.php' ) );
?>
<div class="dze-mai-block" style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:16px 18px;margin-bottom:20px;">
	<h2 class="title" style="margin-top:0;"><?php esc_html_e( 'AI Marketing Assistant — generate a calendar', 'dazont-ecom' ); ?></h2>

	<?php if ( ! $has_key ) : ?>
		<p class="description">
			<?php esc_html_e( 'Add your Anthropic API key to enable this.', 'dazont-ecom' ); ?>
			<a href="<?php echo esc_url( $ai_settings_url ); ?>"><?php esc_html_e( 'Configure it in Settings →', 'dazont-ecom' ); ?></a>
		</p>
	<?php else : ?>
		<p>
			<label><?php esc_html_e( 'Language', 'dazont-ecom' ); ?>
				<select id="dze-mai-lang">
					<?php foreach ( $languages as $lng ) :
						$code = (string) ( $lng['code'] ?? '' );
						if ( $code === '' ) { continue; } ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $primary ); ?>>
							<?php echo esc_html( ( $lng['native_name'] ?? strtoupper( $code ) ) . ' (' . strtoupper( $code ) . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			&nbsp;
			<label><?php esc_html_e( 'Countries', 'dazont-ecom' ); ?>
				<input type="text" id="dze-mai-countries" placeholder="<?php esc_attr_e( 'optional — e.g. FR, BE, CH', 'dazont-ecom' ); ?>" style="width:180px;" />
			</label>
		</p>
		<p>
			<label><?php esc_html_e( 'From', 'dazont-ecom' ); ?> <input type="date" id="dze-mai-start" /></label>
			&nbsp;
			<label><?php esc_html_e( 'To', 'dazont-ecom' ); ?> <input type="date" id="dze-mai-end" /></label>
			&nbsp;
			<button type="button" id="dze-mai-generate" class="button button-primary"><?php esc_html_e( 'Generate suggestions', 'dazont-ecom' ); ?></button>
			<span id="dze-mai-gen-status" style="margin-left:8px;font-size:13px;"></span>
		</p>
		<p class="description"><?php esc_html_e( 'Suggestions are generated for the chosen language only. Leave Countries blank to use that language\'s default markets.', 'dazont-ecom' ); ?> <a href="<?php echo esc_url( $ai_settings_url ); ?>"><?php esc_html_e( 'Adjust country pools / see what the AI knows about your shop →', 'dazont-ecom' ); ?></a></p>
	<?php endif; ?>

	<?php if ( ! empty( $suggestions ) ) : ?>
		<h3 style="margin-bottom:4px;"><?php esc_html_e( 'Suggested events — review before adding', 'dazont-ecom' ); ?></h3>
		<p class="description" style="margin-top:0;"><?php esc_html_e( 'Tick rows and use the bulk buttons, or Accept/Discard one at a time. Accepted events are added to the calendar below as disabled — enable the ones you want.', 'dazont-ecom' ); ?></p>
		<p>
			<button type="button" class="button dze-mai-bulk-accept"><?php esc_html_e( 'Accept selected', 'dazont-ecom' ); ?></button>
			<button type="button" class="button dze-mai-bulk-refuse"><?php esc_html_e( 'Discard selected', 'dazont-ecom' ); ?></button>
			<span id="dze-mai-bulk-status" style="margin-left:8px;font-size:13px;color:#666;"></span>
		</p>
		<table class="widefat striped" id="dze-mai-suggestions">
			<thead>
				<tr>
					<th style="width:28px;text-align:center;"><input type="checkbox" id="dze-mai-check-all" /></th>
					<th><?php esc_html_e( 'Event', 'dazont-ecom' ); ?></th>
					<th style="width:72px;"><?php esc_html_e( 'Discount', 'dazont-ecom' ); ?></th>
					<th style="width:300px;"><?php esc_html_e( 'Dates', 'dazont-ecom' ); ?></th>
					<th style="width:84px;"><?php esc_html_e( 'Languages', 'dazont-ecom' ); ?></th>
					<th style="width:150px;"><?php esc_html_e( 'Actions', 'dazont-ecom' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $suggestions as $sug ) :
					require DZE_DIR . 'admin/views/marketing-ai-row.php';
				endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
