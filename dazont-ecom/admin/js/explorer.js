/* global dzeExplorer, jQuery */
(function ($) {
	'use strict';

	var cfg  = dzeExplorer;
	var i18n = cfg.i18n;

	var state = { cat: 0, paged: 1, loading: false, hasMore: false };

	// =====================================================================
	// Category cards (the main screen)
	// =====================================================================
	var perf = { view: 'grouped', sort: 'qty', search: '' };

	function cardQty($c) {
		return parseInt($c.attr(perf.view === 'detailed' ? 'data-qty-direct' : 'data-qty'), 10) || 0;
	}

	function applyPerf() {
		var $wrap = $('#dze-x-ccards');
		var cards = $wrap.children('.dze-x-ccard').get();
		var shown = 0;

		cards.forEach(function (c) {
			var $c = $(c), ok = true;
			if (perf.search && ($c.attr('data-name') || '').indexOf(perf.search) < 0) { ok = false; }
			if (ok && perf.view === 'detailed' && (parseInt($c.attr('data-count-direct'), 10) || 0) === 0) { ok = false; }
			$c.toggle(ok);
			if (ok) { shown++; }
			$c.find('.dze-x-ccard-qty').text(cardQty($c) + ' ' + i18n.sold);
		});
		$('#dze-x-perf-empty').toggle(shown === 0);

		cards.sort(function (a, b) {
			var $a = $(a), $b = $(b);
			if (perf.sort === 'name') {
				var an = $a.attr('data-name') || '', bn = $b.attr('data-name') || '';
				return an < bn ? -1 : (an > bn ? 1 : 0);
			}
			if (perf.sort === 'res') {
				var ar = parseInt($a.attr('data-res'), 10) || 0, br = parseInt($b.attr('data-res'), 10) || 0;
				if (ar !== br) { return ar - br; } // oldest / never searched first
				return cardQty($b) - cardQty($a);
			}
			return cardQty($b) - cardQty($a); // units sold, descending
		});
		cards.forEach(function (c) { $wrap.append(c); });
	}

	$('.dze-x-view-btn').on('click', function () {
		$('.dze-x-view-btn').removeClass('is-active');
		$(this).addClass('is-active');
		perf.view = $(this).data('view');
		applyPerf();
	});
	$('#dze-x-perf-sort').on('change', function () { perf.sort = $(this).val(); applyPerf(); });
	$('#dze-x-perf-search').on('input', function () { perf.search = ($(this).val() || '').toLowerCase(); applyPerf(); });

	// Mark researched (without opening the overlay).
	$(document).on('click', '.dze-x-mark', function (e) {
		e.stopPropagation();
		var $btn = $(this), cat = parseInt($btn.data('cat'), 10) || 0;
		if (!cat) { return; }
		$btn.prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_explorer_mark_researched', nonce: cfg.nonce, cat: cat })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { return; }
				var $c = $btn.closest('.dze-x-ccard');
				$c.attr('data-res', res.data.ts).attr('data-res-h', i18n.justNow);
				$c.find('.dze-x-ccard-res').text(i18n.justNow);
			})
			.fail(function () { $btn.prop('disabled', false); });
	});

	// =====================================================================
	// Products overlay
	// =====================================================================
	function openOverlay($c) {
		state.cat = parseInt($c.attr('data-cat'), 10) || 0;
		$('#dze-x-ov-title').text($c.attr('data-path') || '');
		var thumb = $c.attr('data-thumb') || '';
		$('#dze-x-ov-thumb').html(thumb ? ('<img src="' + thumb + '" alt="" />') : '');
		$('#dze-x-ai-panel').hide().empty();
		$('#dze-x-ai').prop('disabled', false);
		$('#dze-x-overlay').css('display', 'flex');
		$('body').addClass('dze-x-ov-open');
		load(true);
	}
	function closeOverlay() { $('#dze-x-overlay').hide(); $('body').removeClass('dze-x-ov-open'); }

	$(document).on('click', '.dze-x-ccard', function () { openOverlay($(this)); });
	$(document).on('keydown', '.dze-x-ccard', function (e) {
		if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openOverlay($(this)); }
	});
	$('#dze-x-ov-close').on('click', closeOverlay);

	// AI insights for the open category.
	$('#dze-x-ai').on('click', function () {
		if (!state.cat) { return; }
		var $btn = $(this).prop('disabled', true);
		var $panel = $('#dze-x-ai-panel').show().html('<span class="dze-x-ai-spin"></span>' + i18n.aiThinking);
		$.post(cfg.ajaxUrl, { action: 'dze_explorer_ai_insights', nonce: cfg.nonce, cat: state.cat })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { $panel.text((res.data && res.data.message) || i18n.error); return; }
				$panel.text(res.data.text);
			})
			.fail(function () { $btn.prop('disabled', false); $panel.text(i18n.error); });
	});

	// =====================================================================
	// Product grid (inside the overlay)
	// =====================================================================
	function filters() { return { cat: state.cat }; }

	function load(reset) {
		if (state.loading) { return; }
		state.loading = true;
		if (reset) { state.paged = 1; $('#dze-x-grid').empty(); }
		$('#dze-x-status').text(i18n.loading);
		$('#dze-x-load').hide();

		var data = $.extend({ action: 'dze_explorer_products', nonce: cfg.nonce, paged: state.paged }, filters());
		$.post(cfg.ajaxUrl, data).done(function (res) {
			if (!res.success) { $('#dze-x-status').text(i18n.error); state.loading = false; return; }
			$('#dze-x-grid').append(res.data.html);
			state.hasMore = res.data.hasMore;
			$('#dze-x-count').text(res.data.found);
			if (!$('#dze-x-grid').children().length) { $('#dze-x-status').text(i18n.noResults); }
			else { $('#dze-x-status').text(''); }
			$('#dze-x-load').toggle(!!state.hasMore);
			state.loading = false;
		}).fail(function () { $('#dze-x-status').text(i18n.error); state.loading = false; });
	}

	$('#dze-x-load').on('click', function () { state.paged++; load(false); });
	$('#dze-x-grid').on('scroll', function () {
		if (state.hasMore && !state.loading) {
			var el = this;
			if (el.scrollTop + el.clientHeight >= el.scrollHeight - 300) { state.paged++; load(false); }
		}
	});

	// ---- Image zoom ----
	$(document).on('click', '.dze-thumb, .dze-gal-vargrid img', function () {
		var full = $(this).data('full') || $(this).attr('src');
		if (full) { $('body').append('<div class="dze-lightbox"><img src="' + full + '" alt="" /></div>'); }
	});
	$(document).on('click', '.dze-lightbox', function () { $(this).remove(); });

	// ---- Variations popup ----
	function openModal(html) { $('#dze-x-modal').find('.dze-gal-modal__inner').html(html); $('#dze-x-modal').css('display', 'flex'); }
	$(document).on('click', '.dze-x-vars', function () {
		var id = $(this).data('product');
		openModal('<p>' + i18n.loading + '</p>');
		$.post(cfg.ajaxUrl, { action: 'dze_explorer_variations', nonce: cfg.nonce, product: id }).done(function (res) {
			if (!res.success || !res.data.images || !res.data.images.length) { openModal('<p>' + i18n.none + '</p>'); return; }
			var html = '<h2 style="margin-top:0;">' + i18n.variations + '</h2><div class="dze-gal-vargrid">';
			res.data.images.forEach(function (v) {
				if (!v.thumb) { return; }
				html += '<figure><img src="' + v.thumb + '" data-full="' + (v.full || v.thumb) + '" alt="" loading="lazy" />' +
					'<figcaption>' + $('<span>').text(v.title || '').html() + '</figcaption></figure>';
			});
			html += '</div>';
			openModal(html);
		}).fail(function () { openModal('<p>' + i18n.error + '</p>'); });
	});
	$(document).on('click', '#dze-x-modal', function (e) { if (e.target === this) { $(this).hide(); } });

	// ---- Escape: close the most specific thing first ----
	$(document).on('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		if ($('.dze-lightbox').length) { $('.dze-lightbox').remove(); return; }
		if ($('#dze-x-modal').is(':visible')) { $('#dze-x-modal').hide(); return; }
		if ($('#dze-x-overlay').is(':visible')) { closeOverlay(); }
	});

	// ---- Init ----
	$(function () { applyPerf(); });

}(jQuery));
