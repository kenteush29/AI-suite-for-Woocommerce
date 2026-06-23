/* global aicsBulk, jQuery */
(function ($) {
	'use strict';

	var cfg  = aicsBulk;
	var i18n = cfg.i18n;

	var $productWrap  = $('#aics-bulk-product-wrap');
	var $actionBar    = $('#aics-bulk-action-bar');
	var $progressWrap = $('#aics-bulk-progress-wrap');
	var $progressBar  = $('#aics-bulk-progress-bar');
	var $progressText = $('#aics-bulk-progress-text');
	var $logWrap      = $('#aics-bulk-log');
	var $logBody      = $('#aics-bulk-log-body');
	var $selectedCount = $('#aics-bulk-selected-count');

	var products = [];

	// ---- Load products ----
	$('#aics-bulk-load').on('click', function () {
		var $btn      = $(this);
		var category  = $('#aics-bulk-category').val();

		$btn.prop('disabled', true).text(i18n.loading);
		$productWrap.html('<p style="color:#999;">' + i18n.loading + '</p>');

		$.post(cfg.ajaxUrl, {
			action   : 'aics_bulk_get_products',
			nonce    : cfg.nonce,
			category : category,
		})
		.done(function (res) {
			if (res.success) {
				products = res.data.products;
				renderProductTable(products);
				$actionBar.css('display', 'flex');
			} else {
				$productWrap.html('<p style="color:#c0392b;">' + (res.data.message || i18n.error) + '</p>');
			}
		})
		.fail(function () {
			$productWrap.html('<p style="color:#c0392b;">' + i18n.error + '</p>');
		})
		.always(function () {
			$btn.prop('disabled', false).text('Load products');
		});
	});

	function renderProductTable(prods) {
		if (!prods.length) {
			$productWrap.html('<p style="color:#999;">No products found.</p>');
			return;
		}
		var html = '<table class="widefat striped" style="max-width:900px;"><thead><tr>' +
			'<th style="width:32px;"><input type="checkbox" id="aics-cb-all" checked /></th>' +
			'<th>Product</th>' +
			'</tr></thead><tbody>';

		prods.forEach(function (p) {
			html += '<tr>' +
				'<td><input type="checkbox" class="aics-product-cb" value="' + p.id + '" checked /></td>' +
				'<td>' + escHtml(p.title) + ' <small style="color:#999;">#' + p.id + '</small></td>' +
				'</tr>';
		});
		html += '</tbody></table>';
		$productWrap.html(html);
		updateCount();

		$('#aics-cb-all').on('change', function () {
			$('.aics-product-cb').prop('checked', $(this).prop('checked'));
			updateCount();
		});
		$productWrap.on('change', '.aics-product-cb', updateCount);
	}

	function updateCount() {
		var n = $('.aics-product-cb:checked').length;
		$selectedCount.text(n + ' product(s) selected');
	}

	// ---- Select / deselect all ----
	$('#aics-bulk-select-all').on('click', function () {
		$('.aics-product-cb, #aics-cb-all').prop('checked', true);
		updateCount();
	});
	$('#aics-bulk-deselect-all').on('click', function () {
		$('.aics-product-cb, #aics-cb-all').prop('checked', false);
		updateCount();
	});

	// ---- Start bulk generation ----
	$('#aics-bulk-start').on('click', function () {
		var selectedProducts = [];
		$('.aics-product-cb:checked').each(function () {
			selectedProducts.push(parseInt($(this).val(), 10));
		});

		var selectedSlots = [];
		$('.aics-slot-cb:checked').each(function () {
			selectedSlots.push($(this).val());
		});

		if (!selectedProducts.length) {
			alert(i18n.noProducts);
			return;
		}
		if (!selectedSlots.length) {
			alert(i18n.noSlots);
			return;
		}

		if (!confirm(i18n.confirm)) {
			return;
		}

		// Build queue: all (product × slot) pairs
		var queue = [];
		selectedProducts.forEach(function (productId) {
			selectedSlots.forEach(function (slot) {
				queue.push({ productId: productId, slot: slot });
			});
		});

		$logBody.empty();
		$logWrap.show();
		$progressWrap.show();
		$progressBar.css('width', '0%');
		$('#aics-bulk-start').prop('disabled', true);

		processQueue(queue, 0);
	});

	function processQueue(queue, index) {
		if (index >= queue.length) {
			$progressBar.css('width', '100%');
			$progressText.text('Done! ' + queue.length + ' operations completed.');
			$('#aics-bulk-start').prop('disabled', false);
			return;
		}

		var item     = queue[index];
		var percent  = Math.round((index / queue.length) * 100);
		$progressBar.css('width', percent + '%');

		var productTitle = '';
		for (var i = 0; i < products.length; i++) {
			if (products[i].id === item.productId) { productTitle = products[i].title; break; }
		}
		var slotLabel = cfg.slots[item.slot] || item.slot;

		$progressText.text((index + 1) + ' / ' + queue.length + ' — ' + productTitle + ' — ' + slotLabel);

		$.post(cfg.ajaxUrl, {
			action  : 'aics_bulk_generate',
			nonce   : cfg.nonce,
			post_id : item.productId,
			slot    : item.slot,
		})
		.done(function (res) {
			appendLog(productTitle, slotLabel, res.success, res.success ? (res.data.model + ' · ' + res.data.usage.output_tokens + ' tok') : res.data.message);
		})
		.fail(function () {
			appendLog(productTitle, slotLabel, false, 'Request failed');
		})
		.always(function () {
			processQueue(queue, index + 1);
		});
	}

	function appendLog(product, field, success, info) {
		var status = success
			? '<span class="aics-status-ok">&#10003; ' + i18n.done + '</span>'
			: '<span class="aics-status-err">&#10007; ' + i18n.error + '</span>';
		$logBody.append(
			'<tr><td>' + escHtml(product) + '</td><td>' + escHtml(field) + '</td><td>' + status + '</td><td>' + escHtml(info) + '</td></tr>'
		);
	}

	function escHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

}(jQuery));
