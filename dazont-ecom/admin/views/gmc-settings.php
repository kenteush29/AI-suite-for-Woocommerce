<?php
defined( 'ABSPATH' ) || exit;
/**
 * @var array  $accounts
 * @var array  $keys
 * @var array  $languages
 * @var bool   $has_creds
 * @var bool   $creds_locked
 */
$lang_names = [];
foreach ( $languages as $l ) {
	$lang_names[ $l['code'] ] = $l['native_name'];
}
?>
<div class="wrap dze-wrap">
	<h1><?php esc_html_e( 'Google Merchant Center', 'dazont-ecom' ); ?></h1>

	<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'dazont-ecom' ); ?></p></div>
	<?php endif; ?>

	<p class="description" style="max-width:820px;">
		<?php esc_html_e( 'Push your scheduled sales to Google Merchant Center as merchant promotions (for Google Ads / free listings). One Merchant Center account is used per language.', 'dazont-ecom' ); ?>
	</p>

	<form method="post" action="options.php">
		<?php settings_fields( 'dze_gmc_options' ); ?>

		<h2 class="title"><?php esc_html_e( 'Service account credentials', 'dazont-ecom' ); ?></h2>
		<?php if ( $creds_locked ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Credentials are provided by the DZE_GMC_SERVICE_ACCOUNT constant (wp-config.php). This is the recommended, most secure option.', 'dazont-ecom' ); ?></p></div>
		<?php else : ?>
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'Paste the JSON key of a Google Cloud service account that has access to your Merchant Center (Content API for Shopping enabled). For maximum security you can instead define DZE_GMC_SERVICE_ACCOUNT in wp-config.php (a file path or the raw JSON) and leave this blank.', 'dazont-ecom' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dze-gmc-creds"><?php esc_html_e( 'Service account JSON', 'dazont-ecom' ); ?></label></th>
					<td>
						<textarea id="dze-gmc-creds" name="<?php echo esc_attr( DZE_Gmc::OPT_CREDENTIALS ); ?>" rows="6" class="large-text code" placeholder='{ "type": "service_account", "client_email": "...", "private_key": "...", "token_uri": "https://oauth2.googleapis.com/token" }'></textarea>
						<p class="description">
							<?php echo $has_creds
								? '<span style="color:#0a7040;">&#10003; ' . esc_html__( 'Credentials are set. Leave blank to keep them.', 'dazont-ecom' ) . '</span>'
								: esc_html__( 'No credentials set yet.', 'dazont-ecom' ); ?>
						</p>
					</td>
				</tr>
			</table>
		<?php endif; ?>

		<h2 class="title"><?php esc_html_e( 'Merchant accounts per language', 'dazont-ecom' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php foreach ( $keys as $key ) :
				$acc      = $accounts[ $key ] ?? [];
				$is_lang  = $key !== 'default';
				$label    = $is_lang ? ( $lang_names[ $key ] ?? strtoupper( $key ) ) : __( 'Default (no WPML)', 'dazont-ecom' );
			?>
			<tr>
				<th scope="row"><?php echo esc_html( $label ); ?><?php echo $is_lang ? ' <code>' . esc_html( $key ) . '</code>' : ''; ?></th>
				<td>
					<label><?php esc_html_e( 'Merchant ID', 'dazont-ecom' ); ?>
						<input type="text" name="<?php echo esc_attr( DZE_Gmc::OPT_ACCOUNTS . '[' . $key . '][merchant_id]' ); ?>" value="<?php echo esc_attr( $acc['merchant_id'] ?? '' ); ?>" class="regular-text" placeholder="e.g. 123456789" />
					</label>
					&nbsp;
					<label><?php esc_html_e( 'Target country', 'dazont-ecom' ); ?>
						<input type="text" name="<?php echo esc_attr( DZE_Gmc::OPT_ACCOUNTS . '[' . $key . '][country]' ); ?>" value="<?php echo esc_attr( $acc['country'] ?? '' ); ?>" size="4" maxlength="2" placeholder="US" />
					</label>
					<?php if ( ! $is_lang ) : ?>
					&nbsp;
					<label><?php esc_html_e( 'Content language', 'dazont-ecom' ); ?>
						<input type="text" name="<?php echo esc_attr( DZE_Gmc::OPT_ACCOUNTS . '[' . $key . '][language]' ); ?>" value="<?php echo esc_attr( $acc['language'] ?? '' ); ?>" size="4" maxlength="5" placeholder="en" />
					</label>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
		</table>
		<p class="description"><?php esc_html_e( 'Target country = 2-letter ISO code (US, FR, DE…). It must match the Merchant Center account\'s target country.', 'dazont-ecom' ); ?></p>

		<?php submit_button(); ?>
	</form>

	<hr />
	<h2><?php esc_html_e( 'Connection test', 'dazont-ecom' ); ?></h2>
	<p><?php esc_html_e( 'Verify the credentials by requesting a Google access token.', 'dazont-ecom' ); ?></p>
	<button type="button" id="dze-gmc-test" class="button button-secondary"><?php esc_html_e( 'Test connection', 'dazont-ecom' ); ?></button>
	<span id="dze-gmc-test-status" style="margin-left:8px;font-size:13px;"></span>

	<hr />
	<p class="description" style="max-width:820px;">
		<?php esc_html_e( 'Sync happens automatically every hour (WP-Cron) for active sales, and can be forced from the Marketing & Discounts list (per promotion or in bulk). Note: GMC promotions apply store-wide (product/category scope is not yet mapped to GMC).', 'dazont-ecom' ); ?>
	</p>
</div>
