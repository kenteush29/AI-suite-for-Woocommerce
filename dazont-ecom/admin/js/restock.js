/* global dzeRestock, jQuery */
(function ($) {
	'use strict';

	var cfg  = dzeRestock;
	var i18n = cfg.i18n;

	// ---- Expand / collapse variations (lazy load) ----
	$(document).on('click', '.dze-toggle', function (e) {
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
			'<tr id="restock-child-' + parent + '" class="dze-child">' +
			'<td colspan="' + cols + '"><em>' + escHtml(i18n.loading) + '</em></td></tr>'
		);
		var $childRow = $('#restock-child-' + parent);

		$.post(cfg.ajaxUrl, {
			action    : 'dze_variations',
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
		return '<p class="dze-subnote">' + escHtml(i18n.subNote || '') + '</p>' +
			'<table class="widefat striped dze-subtable">' +
			'<thead><tr><th>Variation</th><th>SKU</th><th>Price</th><th>Sales</th></tr></thead>' +
			'<tbody>' + rowsHtml + '</tbody></table>';
	}

	// ---- Recalculate ----
	$('#dze-recalc').on('click', function () {
		var $btn    = $(this);
		var $status = $('#dze-recalc-status');

		$btn.prop('disabled', true);
		$status.css('color', '#666').text(i18n.recalc);

		$.post(cfg.ajaxUrl, { action: 'dze_recalc', nonce: cfg.nonce })
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

	// ---- Select-all sync (header checkbox) ----
	$(document).on('change', 'thead .check-column input[type=checkbox], tfoot .check-column input[type=checkbox]', function () {
		$('.dze-cb').prop('checked', $(this).prop('checked'));
	});

	// ---- Per-row restock ----
	$(document).on('click', '.dze-restock-btn', function () {
		var $btn = $(this);
		var id   = $btn.data('id');
		$btn.prop('disabled', true).text(i18n.restocking);
		restockOne(id, function (ok) {
			if (ok) {
				removeRow(id);
			} else {
				$btn.prop('disabled', false).text(i18n.restock);
			}
		});
	});

	// ---- Bulk restock ----
	$(document).on('click', '.dze-bulk-restock', function () {
		var ids = [];
		$('.dze-cb:checked').each(function () { ids.push(parseInt($(this).val(), 10)); });

		if (!ids.length) { alert(i18n.noSelection); return; }
		if (!confirm(i18n.confirmBulk)) { return; }

		var $status = $('.dze-bulk-status');
		$('.dze-bulk-restock').prop('disabled', true);
		$('.dze-cb, thead .check-column input, tfoot .check-column input').prop('disabled', true);

		var total = ids.length;
		var done  = 0;

		function next() {
			if (!ids.length) {
				$status.css('color', '#0a7040').text(i18n.bulkDone + ': ' + done + '/' + total);
				$('.dze-bulk-restock').prop('disabled', false);
				return;
			}
			var id = ids.shift();
			done++;
			$status.css('color', '#666').text(i18n.restocking + ' ' + done + '/' + total);
			restockOne(id, function (ok) {
				if (ok) { removeRow(id); }
				next();
			});
		}
		next();
	});

	function restockOne(id, cb) {
		$.post(cfg.ajaxUrl, { action: 'dze_restock', nonce: cfg.nonce, id: id })
		.done(function (res) { cb(!!(res && res.success)); })
		.fail(function () { cb(false); });
	}

	function removeRow(id) {
		$('#restock-child-' + id).remove();
		$('#restock-line-' + id).fadeOut(300, function () { $(this).remove(); });
	}

	function escHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;')
			.replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

}(jQuery));
