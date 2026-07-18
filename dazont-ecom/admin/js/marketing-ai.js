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
		$.post(cfg.ajaxUrl, { action: 'dze_mai_generate', nonce: cfg.nonce, start_date: start, end_date: end })
		.done(function (res) {
			if (res.success) {
				$status.css('color', '#0a7040').text('✓ ' + res.data.message);
				// Reload to render the new suggestion rows server-side.
				window.location.reload();
			} else {
				$status.css('color', '#b32d2e').text('✕ ' + ((res.data && res.data.message) || i18n.error));
				$btn.prop('disabled', false);
			}
		})
		.fail(function () {
			$status.css('color', '#b32d2e').text(i18n.error);
			$btn.prop('disabled', false);
		});
	});

	// ---- Accept a suggestion (with inline edits) ----
	$(document).on('click', '.dze-mai-accept', function () {
		var $row    = $(this).closest('.dze-mai-row');
		var $status = $row.find('.dze-mai-row-status');
		var $btn    = $(this);
		$btn.prop('disabled', true);
		$status.css('color', '#666').text(i18n.accepting);
		$.post(cfg.ajaxUrl, {
			action: 'dze_mai_accept',
			nonce: cfg.nonce,
			id: $row.data('id'),
			title: $row.find('.dze-f-title').val(),
			percent: $row.find('.dze-f-percent').val(),
			start_date: $row.find('.dze-f-start').val(),
			end_date: $row.find('.dze-f-end').val(),
			languages: $row.find('.dze-f-langs').val(),
			email_subject: $row.find('.dze-f-subject').val(),
			klaviyo_email: $row.find('.dze-f-klaviyo').is(':checked') ? 1 : 0
		})
		.done(function (res) {
			if (res.success) {
				$row.fadeOut(200, function () { $(this).remove(); });
			} else {
				$status.css('color', '#b32d2e').text((res.data && res.data.message) || i18n.error);
				$btn.prop('disabled', false);
			}
		})
		.fail(function () {
			$status.css('color', '#b32d2e').text(i18n.error);
			$btn.prop('disabled', false);
		});
	});

	// ---- Discard a suggestion ----
	$(document).on('click', '.dze-mai-refuse', function () {
		if (!window.confirm(i18n.confirmRef)) { return; }
		var $row = $(this).closest('.dze-mai-row');
		$.post(cfg.ajaxUrl, { action: 'dze_mai_refuse', nonce: cfg.nonce, id: $row.data('id') })
		.always(function () { $row.fadeOut(200, function () { $(this).remove(); }); });
	});

}(jQuery));
