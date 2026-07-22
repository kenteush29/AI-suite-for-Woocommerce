/* global dzeExplorer, jQuery */
(function ($) {
	'use strict';

	var cfg  = dzeExplorer;
	var i18n = cfg.i18n;

	var state = { cat: 0, paged: 1, loading: false, hasMore: false };
	var searchTimer = null;

	function filters() {
		var attr = {};
		$('.dze-x-attr').each(function () {
			var v = $(this).val();
			if (v) { attr[$(this).data('tax')] = v; }
		});
		return {
			s: $('#dze-x-search').val() || '',
			cat: state.cat,
			sort: $('#dze-x-sort').val(),
			stock: $('#dze-x-stock').val(),
			attr: attr
		};
	}

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

	// ---- Category rail ----
	function showResearch($cat) {
		var cat = parseInt($cat.attr('data-cat'), 10) || 0;
		if (!cat) { $('#dze-x-research').hide(); return; }
		var h = $cat.attr('data-res-h') || '';
		$('#dze-x-research-when').text(h || i18n.never);
		$('#dze-x-research').data('cat', cat).show();
	}
	$(document).on('click', '.dze-x-cat', function (e) {
		e.preventDefault();
		$('.dze-x-cat').removeClass('is-active');
		$(this).addClass('is-active');
		state.cat = parseInt($(this).data('cat'), 10) || 0;
		var name = state.cat ? $.trim($(this).find('.dze-x-cat-name').text() || $(this).text()) : '';
		$('#dze-x-crumb').text(name);
		showResearch($(this));
		load(true);
	});

	// ---- Mark a category as novelty-searched today ----
	$('#dze-x-research-mark').on('click', function () {
		var cat = parseInt($('#dze-x-research').data('cat'), 10) || 0;
		if (!cat) { return; }
		var $btn = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_explorer_mark_researched', nonce: cfg.nonce, cat: cat })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { return; }
				$('#dze-x-research-when').text(i18n.justNow);
				// Keep the rail in sync so re-selecting the category shows it too.
				var $a = $('.dze-x-cat.is-active');
				$a.attr('data-res', res.data.ts).attr('data-res-h', i18n.justNow);
			})
			.fail(function () { $btn.prop('disabled', false); });
	});

	// ---- Category rail ordering + scope (rolled-up vs this-category-only) ----
	function catVal($a, mode, scope) {
		var d = scope === 'direct' ? '-direct' : '';
		if (mode === 'qty') { return parseFloat($a.attr('data-qty' + d)) || 0; }
		if (mode === 'rev') { return parseFloat($a.attr('data-rev' + d)) || 0; }
		return parseInt($a.attr('data-idx'), 10) || 0;
	}
	function applyCatView() {
		var mode  = $('#dze-x-catsort').val();
		var scope = $('#dze-x-catscope').val();
		$('#dze-explorer .dze-x-cats').each(function () {
			var $ul = $(this);
			var lis = $ul.children('li').get();
			lis.sort(function (la, lb) {
				var a = $(la).children('.dze-x-cat').first();
				var b = $(lb).children('.dze-x-cat').first();
				if (mode === 'az') { return catVal(a, 'az', scope) - catVal(b, 'az', scope); }
				return catVal(b, mode, scope) - catVal(a, mode, scope); // descending
			});
			lis.forEach(function (li) { $ul.append(li); });
		});
		var d = scope === 'direct' ? '-direct' : '';
		$('.dze-x-cat').not('.dze-x-cat-all').each(function () {
			var $c = $(this), $badge = $c.find('.dze-x-cat-count');
			if (mode === 'qty') {
				$badge.text(($c.attr('data-qty' + d) || '0') + ' ' + i18n.units);
			} else if (mode === 'rev') {
				$badge.text($c.attr('data-revfmt' + d) || '');
			} else {
				$badge.text($c.attr('data-count' + d) || '0');
			}
		});
	}
	$('#dze-x-catsort, #dze-x-catscope').on('change', applyCatView);

	// ---- Filters ----
	$('#dze-x-sort, #dze-x-stock').on('change', function () { load(true); });
	$(document).on('change', '.dze-x-attr', function () { load(true); });
	$('#dze-x-search').on('input', function () {
		clearTimeout(searchTimer);
		searchTimer = setTimeout(function () { load(true); }, 350);
	});

	// ---- Load more (button + infinite scroll) ----
	$('#dze-x-load').on('click', function () { state.paged++; load(false); });
	$('.dze-x-grid').on('scroll', function () {
		if (state.hasMore && !state.loading) {
			var el = this;
			if (el.scrollTop + el.clientHeight >= el.scrollHeight - 300) {
				state.paged++; load(false);
			}
		}
	});

	// ---- Focus mode ----
	$('#dze-x-focus').on('click', function () {
		$('body').toggleClass('dze-x-focus');
		$(this).text($('body').hasClass('dze-x-focus') ? i18n.exit : i18n.focus);
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
	$(document).on('keydown', function (e) { if (e.key === 'Escape') { $('.dze-lightbox').remove(); $('#dze-x-modal').hide(); } });

	$(function () { load(true); });

}(jQuery));
