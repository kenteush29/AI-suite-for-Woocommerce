<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap aics-wrap">
<h1><?php esc_html_e( 'AI Content Suite — Bulk Generate', 'ai-content-suite' ); ?></h1>

<div class="aics-bulk-controls" style="margin-bottom:16px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">

	<div>
		<label for="aics-bulk-category" style="display:block; font-weight:600; margin-bottom:4px;">
			<?php esc_html_e( 'Filter by category', 'ai-content-suite' ); ?>
		</label>
		<select id="aics-bulk-category">
			<option value=""><?php esc_html_e( '— All products —', 'ai-content-suite' ); ?></option>
			<?php foreach ( $categories as $cat ) : ?>
				<option value="<?php echo esc_attr( $cat->slug ); ?>"><?php echo esc_html( $cat->name ); ?> (<?php echo (int) $cat->count; ?>)</option>
			<?php endforeach; ?>
		</select>
		<button type="button" id="aics-bulk-load" class="button" style="margin-left:8px;">
			<?php esc_html_e( 'Load products', 'ai-content-suite' ); ?>
		</button>
	</div>

	<div>
		<label style="display:block; font-weight:600; margin-bottom:4px;">
			<?php esc_html_e( 'Fields to generate', 'ai-content-suite' ); ?>
		</label>
		<div id="aics-bulk-slots" style="display:flex; gap:10px; flex-wrap:wrap;">
			<?php
			$slot_labels = [
				'dest_seo_title'         => __( 'SEO meta description', 'ai-content-suite' ),
				'dest_short_description' => __( 'Short description', 'ai-content-suite' ),
				'dest_long_description'  => __( 'Long description', 'ai-content-suite' ),
				'dest_custom_1'          => __( 'Custom field 1', 'ai-content-suite' ),
				'dest_custom_2'          => __( 'Custom field 2', 'ai-content-suite' ),
			];
			foreach ( $slot_labels as $slot => $label ) :
			?>
			<label style="white-space:nowrap;">
				<input type="checkbox" class="aics-slot-cb" value="<?php echo esc_attr( $slot ); ?>" checked />
				<?php echo esc_html( $label ); ?>
			</label>
			<?php endforeach; ?>
		</div>
	</div>

</div>

<!-- Product list -->
<div id="aics-bulk-product-wrap" style="margin-bottom:16px;">
	<p style="color:#999;"><?php esc_html_e( 'Click "Load products" to display the product list.', 'ai-content-suite' ); ?></p>
</div>

<!-- Action bar -->
<div id="aics-bulk-action-bar" style="display:none; margin-bottom:16px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
	<button type="button" id="aics-bulk-start" class="button button-primary">
		<?php esc_html_e( 'Generate & Apply selected', 'ai-content-suite' ); ?>
	</button>
	<button type="button" id="aics-bulk-select-all" class="button button-secondary">
		<?php esc_html_e( 'Select all', 'ai-content-suite' ); ?>
	</button>
	<button type="button" id="aics-bulk-deselect-all" class="button button-secondary">
		<?php esc_html_e( 'Deselect all', 'ai-content-suite' ); ?>
	</button>
	<span id="aics-bulk-selected-count" style="color:#666;"></span>
</div>

<!-- Progress -->
<div id="aics-bulk-progress-wrap" style="display:none; margin-bottom:16px;">
	<div style="background:#e0e0e0; border-radius:4px; height:20px; overflow:hidden; max-width:600px;">
		<div id="aics-bulk-progress-bar" style="background:#2271b1; height:100%; width:0%; transition:width 0.3s;"></div>
	</div>
	<p id="aics-bulk-progress-text" style="margin:4px 0 0; font-size:13px; color:#666;"></p>
</div>

<!-- Log -->
<div id="aics-bulk-log" style="display:none;">
	<h3><?php esc_html_e( 'Results', 'ai-content-suite' ); ?></h3>
	<table class="widefat striped" style="max-width:900px;">
		<thead><tr>
			<th><?php esc_html_e( 'Product', 'ai-content-suite' ); ?></th>
			<th><?php esc_html_e( 'Field', 'ai-content-suite' ); ?></th>
			<th><?php esc_html_e( 'Status', 'ai-content-suite' ); ?></th>
			<th><?php esc_html_e( 'Info', 'ai-content-suite' ); ?></th>
		</tr></thead>
		<tbody id="aics-bulk-log-body"></tbody>
	</table>
</div>

</div>
