/* global rstkRestock, jQuery */
(function ($) {
	'use strict';

	var cfg  = rstkRestock;
	var i18n = cfg.i18n;

	// ---- Expand / collapse variations (lazy load) ----
	$(document).on('click', '.rstk-toggle', function (e) {
		e.preventDefault();
		var $btn   = $(this);
		var parent = $btn.data('parent');
		var $row   = $('#restock-line-' + parent);
		var cols   = $row.children('td, th').length || 5;
		var $child = $('#restock-child-' + parent);

		if ($child.length) {
			var visible = $child.is(':visible');
			$child.toggle(!visible);
			$btn.attr('aria-expanded', String(!visible)).text(visible ? '▸' : '▾');
			return;
		}

		$btn.attr('aria-expanded', 'true').text('▾');
		$row.after(
			'<tr id="restock-child-' + parent + '" class="rstk-child">' +
			'<td colspan="' + cols + '"><em>' + escHtml(i18n.loading) + '</em></td></tr>'
		);
		var $childRow = $('#restock-child-' + parent);

		$.post(cfg.ajaxUrl, {
			action    : 'rstk_variations',
			nonce     : cfg.nonce,
			parent_id : parent
		})
		.done(function (res) {
			if (res.success && res.data.count > 0) {
				$childRow.find('td').html(buildSubTable(res.data.rows));
			} else if (res.success) {
				$childRow.find('td').html('<em>' + escHtml(i18n.noVar) + '</em>');
			} else {
				$childRow.find('td').html('<span style="color:#c0392b;">' + escHtml(i18n.error) + '</span>');
			}
		})
		.fail(function () {
			$childRow.find('td').html('<span style="color:#c0392b;">' + escHtml(i18n.error) + '</span>');
		});
	});

	function buildSubTable(rowsHtml) {
		return '<p class="rstk-subnote">' + escHtml(i18n.subNote || '') + '</p>' +
			'<table class="widefat striped rstk-subtable">' +
			'<thead><tr><th>Variation</th><th>SKU</th><th>Price</th><th>Sales</th></tr></thead>' +
			'<tbody>' + rowsHtml + '</tbody></table>';
	}

	// ---- Recalculate ----
	$('#rstk-recalc').on('click', function () {
		var $btn    = $(this);
		var $status = $('#rstk-recalc-status');

		$btn.prop('disabled', true);
		$status.css('color', '#666').text(i18n.recalc);

		$.post(cfg.ajaxUrl, { action: 'rstk_recalc', nonce: cfg.nonce })
		.done(function (res) {
			if (res.success) {
				$status.css('color', '#0a7040').text(res.data.message + ' — ' + res.data.timestamp);
				setTimeout(function () { window.location.reload(); }, 900);
			} else {
				$status.css('color', '#c0392b').text((res.data && res.data.message) || i18n.error);
				$btn.prop('disabled', false);
			}
		})
		.fail(function () {
			$status.css('color', '#c0392b').text(i18n.error);
			$btn.prop('disabled', false);
		});
	});

	function escHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;')
			.replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

}(jQuery));
