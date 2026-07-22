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
	$(document).on('click', '.dze-x-cat', function (e) {
		e.preventDefault();
		$('.dze-x-cat').removeClass('is-active');
		$(this).addClass('is-active');
		state.cat = parseInt($(this).data('cat'), 10) || 0;
		var name = state.cat ? $.trim($(this).find('.dze-x-cat-name').text() || $(this).text()) : '';
		$('#dze-x-crumb').text(name);
		load(true);
	});

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
