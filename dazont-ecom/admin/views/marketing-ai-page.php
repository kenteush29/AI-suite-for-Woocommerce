<?php
defined( 'ABSPATH' ) || exit;
/**
 * @var array  $settings
 * @var array  $suggestions
 * @var bool   $key_locked
 * @var bool   $has_key
 * @var string $def_country
 * @var string $def_lang
 */
?>
<div class="wrap dze-wrap">
	<h1><?php esc_html_e( 'AI Marketing Assistant', 'dazont-ecom' ); ?></h1>

	<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'dazont-ecom' ); ?></p></div>
	<?php endif; ?>

	<p class="description" style="max-width:820px;">
		<?php esc_html_e( 'Generate a marketing calendar tailored to your shop, target countries and languages. Review each suggestion — accept it (it becomes a scheduled sale you can enable), edit it, or discard it. Add [dze_marketing_calendar] to your home page to show the calendar to visitors.', 'dazont-ecom' ); ?>
	</p>

	<form method="post" action="options.php">
		<?php settings_fields( 'dze_mai_options' ); ?>

		<h2 class="title"><?php esc_html_e( 'Configuration', 'dazont-ecom' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dze-mai-key"><?php esc_html_e( 'Anthropic API key', 'dazont-ecom' ); ?></label></th>
				<td>
					<?php if ( $key_locked ) : ?>
						<div class="notice notice-info inline"><p><?php esc_html_e( 'Provided by the DZE_ANTHROPIC_API_KEY constant (wp-config.php).', 'dazont-ecom' ); ?></p></div>
					<?php else : ?>
						<input type="password" id="dze-mai-key" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[api_key]' ); ?>" value="" class="regular-text" autocomplete="new-password" placeholder="sk-ant-…" />
						<p class="description">
							<?php echo $has_key
								? '<span style="color:#0a7040;">&#10003; ' . esc_html__( 'A key is saved. Leave blank to keep it.', 'dazont-ecom' ) . '</span>'
								: esc_html__( 'Get a key at console.anthropic.com → API Keys. Stored in your database; used only to call the Claude API.', 'dazont-ecom' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-mai-shop"><?php esc_html_e( 'Shop type / description', 'dazont-ecom' ); ?></label></th>
				<td>
					<textarea id="dze-mai-shop" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[shop_type]' ); ?>" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Handmade jewellery and accessories, mid-range, mostly women 25-45.', 'dazont-ecom' ); ?>"><?php echo esc_textarea( $settings['shop_type'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'The more specific, the better the suggestions (products, audience, positioning).', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-mai-countries"><?php esc_html_e( 'Target countries', 'dazont-ecom' ); ?></label></th>
				<td>
					<input type="text" id="dze-mai-countries" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[countries]' ); ?>" value="<?php echo esc_attr( $settings['countries'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $def_country ?: 'US, FR, DE' ); ?>" />
					<p class="description">
						<?php esc_html_e( '2-letter ISO codes, comma-separated.', 'dazont-ecom' ); ?>
						<?php if ( $def_country ) : ?><?php printf( esc_html__( 'Leave blank to use your GMC countries: %s', 'dazont-ecom' ), '<code>' . esc_html( $def_country ) . '</code>' ); ?><?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-mai-languages"><?php esc_html_e( 'Languages', 'dazont-ecom' ); ?></label></th>
				<td>
					<input type="text" id="dze-mai-languages" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[languages]' ); ?>" value="<?php echo esc_attr( $settings['languages'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $def_lang ?: 'en, fr' ); ?>" />
					<p class="description">
						<?php esc_html_e( '2-letter language codes, comma-separated.', 'dazont-ecom' ); ?>
						<?php if ( $def_lang ) : ?><?php printf( esc_html__( 'Leave blank to use: %s', 'dazont-ecom' ), '<code>' . esc_html( $def_lang ) . '</code>' ); ?><?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Planning', 'dazont-ecom' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Horizon (months)', 'dazont-ecom' ); ?>
						<input type="number" min="1" max="24" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[horizon_months]' ); ?>" value="<?php echo esc_attr( (int) $settings['horizon_months'] ); ?>" style="width:70px;" />
					</label>
					&nbsp;&nbsp;
					<label><?php esc_html_e( 'Max events', 'dazont-ecom' ); ?>
						<input type="number" min="1" max="24" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[max_events]' ); ?>" value="<?php echo esc_attr( (int) $settings['max_events'] ); ?>" style="width:70px;" />
					</label>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save configuration', 'dazont-ecom' ) ); ?>
	</form>

	<hr />

	<h2 class="title"><?php esc_html_e( 'Generate calendar', 'dazont-ecom' ); ?></h2>
	<p><?php esc_html_e( 'Ask the AI to propose a set of promotional events for your shop.', 'dazont-ecom' ); ?></p>
	<p>
		<button type="button" id="dze-mai-generate" class="button button-primary"<?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Generate suggestions', 'dazont-ecom' ); ?></button>
		<span id="dze-mai-gen-status" style="margin-left:8px;font-size:13px;"></span>
	</p>
	<?php if ( ! $has_key ) : ?>
		<p class="description"><?php esc_html_e( 'Add and save your Anthropic API key above to enable generation.', 'dazont-ecom' ); ?></p>
	<?php endif; ?>

	<hr />

	<h2 class="title"><?php esc_html_e( 'Suggested events', 'dazont-ecom' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Edit any field inline, then Accept to add it to your calendar (as a disabled scheduled sale), or Discard it.', 'dazont-ecom' ); ?></p>

	<table class="widefat striped" id="dze-mai-suggestions">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Event', 'dazont-ecom' ); ?></th>
				<th style="width:90px;"><?php esc_html_e( 'Discount', 'dazont-ecom' ); ?></th>
				<th style="width:150px;"><?php esc_html_e( 'Start', 'dazont-ecom' ); ?></th>
				<th style="width:150px;"><?php esc_html_e( 'End', 'dazont-ecom' ); ?></th>
				<th style="width:110px;"><?php esc_html_e( 'Languages', 'dazont-ecom' ); ?></th>
				<th style="width:80px;"><?php esc_html_e( 'Klaviyo email', 'dazont-ecom' ); ?></th>
				<th style="width:150px;"><?php esc_html_e( 'Actions', 'dazont-ecom' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $suggestions ) ) : ?>
				<tr class="dze-mai-empty"><td colspan="7"><span style="color:#777;"><?php esc_html_e( 'No suggestions yet — generate some above.', 'dazont-ecom' ); ?></span></td></tr>
			<?php else : ?>
				<?php foreach ( $suggestions as $sug ) :
					require DZE_DIR . 'admin/views/marketing-ai-row.php';
				endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<hr />
	<p class="description" style="max-width:820px;">
		<?php esc_html_e( 'Home-page widget: paste the shortcode', 'dazont-ecom' ); ?>
		<code>[dze_marketing_calendar]</code>
		<?php esc_html_e( 'into any page or block. It lists upcoming sales with their dates and discount, highlights the one running now, and shows an ✉ badge for events flagged for a Klaviyo email.', 'dazont-ecom' ); ?>
	</p>
</div>
