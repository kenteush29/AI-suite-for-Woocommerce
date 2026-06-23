/* global aicsMetabox, jQuery */
(function ($) {
	'use strict';

	var cfg  = aicsMetabox;
	var i18n = cfg.i18n;

	// ---- Generate button ----
	$(document).on('click', '.aics-btn-generate', function () {
		var $row     = $(this).closest('.aics-gen-row');
		var $btn     = $(this);
		var $apply   = $row.find('.aics-btn-apply');
		var $preview = $row.find('.aics-preview-area');
		var $status  = $row.find('.aics-gen-status');
		var slot     = $row.data('slot');

		$btn.prop('disabled', true).text(i18n.generating);
		$apply.hide();
		$status.text('').css('color', '#999');

		$.post(cfg.ajaxUrl, {
			action  : 'aics_generate_field',
			nonce   : cfg.nonce,
			post_id : cfg.postId,
			slot    : slot,
		})
		.done(function (res) {
			if (res.success) {
				$preview.val(res.data.text).show();
				$apply.show();
				$status.text(
					res.data.model + ' — ' +
					res.data.usage.input_tokens + ' in / ' +
					res.data.usage.output_tokens + ' out'
				);
			} else {
				$preview.hide();
				$status.text(i18n.error + ' ' + res.data.message).css('color', '#c0392b');
			}
		})
		.fail(function () {
			$status.text(i18n.error + ' Request failed.').css('color', '#c0392b');
		})
		.always(function () {
			$btn.prop('disabled', false).text(i18n.generate);
		});
	});

	// ---- Apply button ----
	$(document).on('click', '.aics-btn-apply', function () {
		var $row       = $(this).closest('.aics-gen-row');
		var $btn       = $(this);
		var $status    = $row.find('.aics-gen-status');
		var slot       = $row.data('slot');
		var targetId   = $row.data('target-id');
		var targetType = $row.data('target-type');
		var value      = $row.find('.aics-preview-area').val();

		$btn.prop('disabled', true).text(i18n.applying);

		$.post(cfg.ajaxUrl, {
			action  : 'aics_apply_field',
			nonce   : cfg.nonce,
			post_id : cfg.postId,
			slot    : slot,
			value   : value,
		})
		.done(function (res) {
			if (res.success) {
				$status.text(i18n.applied).css('color', '#0a7040');
				$btn.hide();
				if (targetId) {
					liveUpdateField(targetId, targetType, value);
				}
			} else {
				$status.text(i18n.error + ' ' + res.data.message).css('color', '#c0392b');
				$btn.prop('disabled', false).text(i18n.apply);
			}
		})
		.fail(function () {
			$status.text(i18n.error + ' Request failed.').css('color', '#c0392b');
			$btn.prop('disabled', false).text(i18n.apply);
		});
	});

	// ---- WPML Translation ----
	var wpmlLanguages = [];

	if ( cfg.wpmlActive ) {
		$.post(cfg.ajaxUrl, {
			action : 'aics_get_wpml_languages',
			nonce  : cfg.nonce,
		})
		.done(function (res) {
			if (res.success) {
				wpmlLanguages = res.data.languages;
				renderWpmlLanguages();
			} else {
				$('#aics-wpml-languages').text(res.data.message || 'WPML unavailable.');
			}
		})
		.fail(function () {
			$('#aics-wpml-languages').text('Could not load WPML languages.');
		});
	}

	function renderWpmlLanguages() {
		if (!wpmlLanguages.length) {
			$('#aics-wpml-languages').text('No additional languages found.');
			return;
		}
		var html = '';
		wpmlLanguages.forEach(function (lang) {
			html += '<label style="margin-right:10px;">' +
				'<input type="checkbox" class="aics-lang-cb" value="' + lang.code + '" checked /> ' +
				lang.native_name + '</label>';
		});
		$('#aics-wpml-languages').html(html);
		$('#aics-btn-translate-all').show();
	}

	$(document).on('click', '#aics-btn-translate-all', function () {
		var $btn     = $(this);
		var $status  = $('#aics-wpml-status');
		var langs    = [];
		$('.aics-lang-cb:checked').each(function () { langs.push($(this).val()); });

		if (!langs.length) {
			$status.text('Select at least one language.').css('color', '#c0392b');
			return;
		}

		// Collect all applied (slot → text) pairs from the metabox
		var pairs = [];
		$('.aics-gen-row').each(function () {
			var $row    = $(this);
			var slot    = $row.data('slot');
			var $apply  = $row.find('.aics-btn-apply');
			var $preview = $row.find('.aics-preview-area');
			// Only include slots that have generated content (preview visible)
			if ($preview.is(':visible') && $preview.val()) {
				pairs.push({ slot: slot, text: $preview.val() });
			}
		});

		if (!pairs.length) {
			$status.text(i18n.noContent).css('color', '#c0392b');
			return;
		}

		$btn.prop('disabled', true).text(i18n.translating);
		$status.text('').css('color', '#999');

		// Build full queue: each (lang × slot) pair
		var queue = [];
		langs.forEach(function (lang) {
			pairs.forEach(function (pair) {
				queue.push({ lang: lang, slot: pair.slot, text: pair.text });
			});
		});

		processTranslateQueue(queue, 0, $btn, $status);
	});

	function processTranslateQueue(queue, index, $btn, $status) {
		if (index >= queue.length) {
			$btn.prop('disabled', false).text('Translate all fields');
			$status.text(i18n.translateDone).css('color', '#0a7040');
			return;
		}

		var item = queue[index];
		$status.text(i18n.translating + ' ' + item.lang + ' — ' + item.slot + ' (' + (index + 1) + '/' + queue.length + ')').css('color', '#999');

		$.post(cfg.ajaxUrl, {
			action        : 'aics_translate_content',
			nonce         : cfg.nonce,
			post_id       : cfg.postId,
			slot          : item.slot,
			source_text   : item.text,
			language_code : item.lang,
		})
		.always(function () {
			processTranslateQueue(queue, index + 1, $btn, $status);
		});
	}

	// ---- Live-update the page field after apply ----
	function liveUpdateField(id, type, value) {
		switch (type) {
			case 'input':
				$('#' + id).val(value).trigger('change');
				break;

			case 'tinymce':
				// Try TinyMCE visual editor first, fall back to plain textarea.
				if (typeof tinyMCE !== 'undefined' && tinyMCE.get(id)) {
					tinyMCE.get(id).setContent(value);
				}
				$('#' + id).val(value);
				break;

			case 'acf':
				// ACF text/textarea: input id is "acf-{field_key}".
				var $acfInput = $('#acf-' + id);
				if ($acfInput.length) {
					$acfInput.val(value).trigger('change');
				} else if (typeof tinyMCE !== 'undefined' && tinyMCE.get(id)) {
					// wysiwyg ACF field uses the field_key as editor id.
					tinyMCE.get(id).setContent(value);
				}
				break;

			case 'rankmath':
				$('#' + id).val(value).trigger('input').trigger('change');
				break;
		}
	}

}(jQuery));
