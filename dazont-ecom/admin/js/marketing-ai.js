/* global dzeMai, jQuery */
(function ($) {
	'use strict';

	var cfg  = dzeMai;
	var i18n = cfg.i18n;

	// ---- Generate suggestions ----
	$('#dze-mai-generate').on('click', function () {
		var $btn = $(this), $status = $('#dze-mai-gen-status');
		var start = $('#dze-mai-start').val();
		var end   = $('#dze-mai-end').val();
		if (!start || !end) {
			$status.css('color', '#b32d2e').text(i18n.needDates);
			return;
		}
		$btn.prop('disabled', true);
		$status.css('color', '#666').text(i18n.generating);
		$.post(cfg.ajaxUrl, {
			action: 'dze_mai_generate',
			nonce: cfg.nonce,
			start_date: start,
			end_date: end,
			lang: $('#dze-mai-lang').val() || '',
			countries: $('#dze-mai-countries').val() || ''
		})
		.done(function (res) {
			if (res.success) {
				$status.css('color', '#0a7040').text('✓ ' + res.data.message);
				if (res.data.count > 0) {
					// Reload to render the new suggestion rows server-side.
					window.location.reload();
				} else {
					$btn.prop('disabled', false);
				}
			} else {
				$status.css('color', '#b32d2e').text('✕ ' + ((res.data && res.data.message) || i18n.error));
				$btn.prop('disabled', false);
			}
		})
		.fail(function (xhr) {
			var extra = xhr && xhr.status ? ' (HTTP ' + xhr.status + ')' : '';
			$status.css('color', '#b32d2e').text(i18n.error + extra + '. ' + (xhr && xhr.status === 0 ? 'Timed out or blocked.' : ''));
			$btn.prop('disabled', false);
		});
	});

	// ---- Accept one suggestion (with its inline edits). Returns a promise. ----
	function acceptRow($row) {
		var $status = $row.find('.dze-mai-row-status');
		$row.find('.dze-mai-accept').prop('disabled', true);
		$status.css('color', '#666').text(i18n.accepting);
		return $.post(cfg.ajaxUrl, {
			action: 'dze_mai_accept',
			nonce: cfg.nonce,
			id: $row.data('id'),
			title: $row.find('.dze-f-title').val(),
			percent: $row.find('.dze-f-percent').val(),
			start_date: $row.find('.dze-f-start').val(),
			end_date: $row.find('.dze-f-end').val(),
			languages: $row.find('.dze-f-langs').val(),
			email_subject: $row.find('.dze-f-subject').val()
		})
		.done(function (res) {
			if (res.success) {
				$row.fadeOut(200, function () { $(this).remove(); });
			} else {
				$status.css('color', '#b32d2e').text((res.data && res.data.message) || i18n.error);
				$row.find('.dze-mai-accept').prop('disabled', false);
			}
		})
		.fail(function () {
			$status.css('color', '#b32d2e').text(i18n.error);
			$row.find('.dze-mai-accept').prop('disabled', false);
		});
	}

	// ---- Discard one suggestion. Returns a promise. ----
	function refuseRow($row) {
		return $.post(cfg.ajaxUrl, { action: 'dze_mai_refuse', nonce: cfg.nonce, id: $row.data('id') })
			.always(function () { $row.fadeOut(200, function () { $(this).remove(); }); });
	}

	$(document).on('click', '.dze-mai-accept', function () { acceptRow($(this).closest('.dze-mai-row')); });

	$(document).on('click', '.dze-mai-refuse', function () {
		if (!window.confirm(i18n.confirmRef)) { return; }
		refuseRow($(this).closest('.dze-mai-row'));
	});

	// ---- Select all ----
	$(document).on('change', '#dze-mai-check-all', function () {
		$('#dze-mai-suggestions .dze-mai-cb').prop('checked', $(this).is(':checked'));
	});

	function selectedRows() {
		return $('#dze-mai-suggestions .dze-mai-cb:checked').closest('.dze-mai-row');
	}

	// ---- Bulk accept ----
	$(document).on('click', '.dze-mai-bulk-accept', function () {
		var $rows = selectedRows();
		if (!$rows.length) { return; }
		var $status = $('#dze-mai-bulk-status');
		$status.css('color', '#666').text(i18n.accepting + ' (' + $rows.length + ')');
		var jobs = $rows.map(function () { return acceptRow($(this)); }).get();
		$.when.apply($, jobs).always(function () {
			$status.css('color', '#0a7040').text('✓');
		});
	});

	// ---- Bulk discard ----
	$(document).on('click', '.dze-mai-bulk-refuse', function () {
		var $rows = selectedRows();
		if (!$rows.length) { return; }
		if (!window.confirm(i18n.confirmRefBulk)) { return; }
		$rows.each(function () { refuseRow($(this)); });
	});

}(jQuery));
