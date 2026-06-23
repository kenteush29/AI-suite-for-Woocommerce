/* global aicsPA, jQuery */
(function ($) {
	'use strict';

	var cfg  = aicsPA;
	var i18n = cfg.i18n;

	// Holds the products targeted by the currently open modal:
	// either a single id (row action) or all checked ids (toolbar button).
	var targetProducts = [];
	var productTitles  = {}; // id -> title cache

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	function getCheckedProducts() {
		var ids = [];
		$('#the-list input[name="post[]"]:checked').each(function () {
			ids.push(parseInt($(this).val(), 10));
		});
		return ids;
	}

	function getProductTitle(id) {
		if (productTitles[id]) {
			return productTitles[id];
		}
		// Read the title from the list row.
		var $link = $('#post-' + id + ' .row-title').first();
		var title = $link.length ? $.trim($link.text()) : ('#' + id);
		productTitles[id] = title;
		return title;
	}

	function openModal($overlay) {
		$overlay.css('display', 'flex');
		$('body').addClass('aics-modal-open');
	}

	function closeModal($overlay) {
		$overlay.hide();
		$('body').removeClass('aics-modal-open');
	}

	function resetModal($overlay) {
		$overlay.find('.aics-modal-progress').hide();
		$overlay.find('.aics-progress-bar').css('width', '0%');
		$overlay.find('.aics-progress-text').text('');
		$overlay.find('.aics-modal-log').hide().find('tbody').empty();
		$overlay.find('.aics-modal-start').prop('disabled', false).text(i18n.start);
		$overlay.find('.aics-modal-fieldset, .aics-lang-switch').css('opacity', '1');
	}

	function describeTarget() {
		if (targetProducts.length === 1) {
			return getProductTitle(targetProducts[0]);
		}
		return targetProducts.length + ' ' + i18n.productsTargeted;
	}

	function escHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;')
			.replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function statusBadge(ok, label) {
		return ok
			? '<span class="aics-status-ok">&#10003; ' + (label || i18n.done) + '</span>'
			: '<span class="aics-status-err">&#10007; ' + (label || i18n.error) + '</span>';
	}

	// ---------------------------------------------------------------------
	// GENERATE
	// ---------------------------------------------------------------------

	var $genModal = $('#aics-modal-generate');

	function launchGenerate(products) {
		if (!products.length) {
			alert(i18n.noProducts);
			return;
		}
		targetProducts = products;
		resetModal($genModal);
		$('#aics-gen-target').text(describeTarget());
		openModal($genModal);
	}

	$('#aics-pa-open-generate').on('click', function () {
		launchGenerate(getCheckedProducts());
	});

	$(document).on('click', '.aics-pa-row-generate', function (e) {
		e.preventDefault();
		launchGenerate([parseInt($(this).data('id'), 10)]);
	});

	$genModal.find('.aics-modal-start').on('click', function () {
		var slots = [];
		$genModal.find('.aics-gen-slot:checked').each(function () { slots.push($(this).val()); });

		if (!slots.length) { alert(i18n.noSlots); return; }

		var queue = [];
		targetProducts.forEach(function (pid) {
			slots.forEach(function (slot) {
				queue.push({ pid: pid, slot: slot });
			});
		});

		var $start = $(this);
		$start.prop('disabled', true).text(i18n.working);
		$genModal.find('.aics-modal-fieldset').css('opacity', '0.5');
		$genModal.find('.aics-modal-progress').show();
		$genModal.find('.aics-modal-log').show();

		runQueue($genModal, queue, 0, function (item) {
			return {
				action  : 'aics_pa_generate',
				nonce   : cfg.nonce,
				post_id : item.pid,
				slot    : item.slot
			};
		}, function (item, ok, info) {
			$genModal.find('.aics-modal-log tbody').append(
				'<tr><td>' + escHtml(getProductTitle(item.pid)) + '</td>' +
				'<td>' + escHtml(cfg.slots[item.slot] || item.slot) + '</td>' +
				'<td>' + statusBadge(ok) + '</td>' +
				'<td>' + escHtml(info) + '</td></tr>'
			);
		}, $start);
	});

	// ---------------------------------------------------------------------
	// TRANSLATE
	// ---------------------------------------------------------------------

	var $trModal = $('#aics-modal-translate');

	function launchTranslate(products) {
		if (!products.length) {
			alert(i18n.noProducts);
			return;
		}
		targetProducts = products;
		resetModal($trModal);
		$('#aics-tr-target').text(describeTarget());
		openModal($trModal);
	}

	$('#aics-pa-open-translate').on('click', function () {
		launchTranslate(getCheckedProducts());
	});

	$(document).on('click', '.aics-pa-row-translate', function (e) {
		e.preventDefault();
		launchTranslate([parseInt($(this).data('id'), 10)]);
	});

	if ($trModal.length) {
		$trModal.find('.aics-modal-start').on('click', function () {
			var sourceLang = $('#aics-tr-source').val();
			var targetLangs = [];
			$trModal.find('.aics-tr-target-lang:checked').each(function () { targetLangs.push($(this).val()); });

			var slots = [];
			$trModal.find('.aics-tr-slot:checked').each(function () { slots.push($(this).val()); });

			if (!targetLangs.length) { alert(i18n.noLangs); return; }
			if (!slots.length) { alert(i18n.noSlots); return; }
			if (targetLangs.length === 1 && targetLangs[0] === sourceLang) { alert(i18n.sameLang); return; }

			var queue = [];
			targetProducts.forEach(function (pid) {
				targetLangs.forEach(function (lang) {
					if (lang === sourceLang) { return; }
					slots.forEach(function (slot) {
						queue.push({ pid: pid, slot: slot, lang: lang });
					});
				});
			});

			var $start = $(this);
			$start.prop('disabled', true).text(i18n.working);
			$trModal.find('.aics-modal-fieldset, .aics-lang-switch').css('opacity', '0.5');
			$trModal.find('.aics-modal-progress').show();
			$trModal.find('.aics-modal-log').show();

			runQueue($trModal, queue, 0, function (item) {
				return {
					action      : 'aics_pa_translate',
					nonce       : cfg.nonce,
					post_id     : item.pid,
					slot        : item.slot,
					source_lang : sourceLang,
					target_lang : item.lang
				};
			}, function (item, ok, info) {
				$trModal.find('.aics-modal-log tbody').append(
					'<tr><td>' + escHtml(getProductTitle(item.pid)) + '</td>' +
					'<td>' + escHtml(item.lang.toUpperCase()) + '</td>' +
					'<td>' + escHtml(cfg.slots[item.slot] || item.slot) + '</td>' +
					'<td>' + statusBadge(ok) + '</td>' +
					'<td>' + escHtml(info) + '</td></tr>'
				);
			}, $start);
		});
	}

	// ---------------------------------------------------------------------
	// Shared sequential queue runner
	// ---------------------------------------------------------------------

	function runQueue($modal, queue, index, buildData, onResult, $start) {
		if (index >= queue.length) {
			$modal.find('.aics-progress-bar').css('width', '100%');
			$modal.find('.aics-progress-text').text(i18n.allDone + ' (' + queue.length + ')');
			$start.prop('disabled', false).text(i18n.start);
			return;
		}

		var item    = queue[index];
		var percent = Math.round((index / queue.length) * 100);
		$modal.find('.aics-progress-bar').css('width', percent + '%');
		$modal.find('.aics-progress-text').text((index + 1) + ' / ' + queue.length + ' — ' + getProductTitle(item.pid));

		$.post(cfg.ajaxUrl, buildData(item))
		.done(function (res) {
			if (res.success) {
				var info = res.data && res.data.model
					? (res.data.model + ' · ' + (res.data.usage ? res.data.usage.output_tokens : 0) + ' tok')
					: '';
				onResult(item, true, info);
			} else {
				onResult(item, false, (res.data && res.data.message) || i18n.error);
			}
		})
		.fail(function () {
			onResult(item, false, 'Request failed');
		})
		.always(function () {
			runQueue($modal, queue, index + 1, buildData, onResult, $start);
		});
	}

	// ---------------------------------------------------------------------
	// Close handlers
	// ---------------------------------------------------------------------

	$('.aics-modal-close').on('click', function () {
		closeModal($(this).closest('.aics-modal-overlay'));
	});

	$('.aics-modal-overlay').on('click', function (e) {
		if (e.target === this) {
			closeModal($(this));
		}
	});

}(jQuery));
