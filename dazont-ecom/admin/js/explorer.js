/* global dzeExplorer, jQuery */
(function ($) {
	'use strict';

	var cfg  = dzeExplorer;
	var i18n = cfg.i18n;

	var state = { cat: 0, paged: 1, loading: false, hasMore: false, browseLoaded: false };
	var searchTimer = null;

	// =====================================================================
	// Mode switching (Performance ⇄ Browse)
	// =====================================================================
	function setMode(mode) {
		$('.dze-x-tab').removeClass('is-active').filter('[data-mode="' + mode + '"]').addClass('is-active');
		$('#dze-x-perf').toggle(mode === 'perf');
		$('#dze-explorer').toggle(mode === 'browse');
	}
	$('.dze-x-tab').on('click', function () {
		var mode = $(this).data('mode');
		setMode(mode);
		if (mode === 'browse' && !state.browseLoaded) { load(true); }
	});

	// =====================================================================
	// Category performance table
	// =====================================================================
	var perf = { key: 'qty', dir: 'desc', scope: 'direct', level: 'all', search: '' };

	function perfVal($tr, key, scope) {
		var d = scope === 'direct' ? '-direct' : '';
		if (key === 'name') { return $tr.attr('data-name') || ''; }
		if (key === 'res')  { return parseInt($tr.attr('data-res'), 10) || 0; }
		if (key === 'count') { return parseFloat($tr.attr('data-count' + d)) || 0; }
		if (key === 'qty')   { return parseFloat($tr.attr('data-qty' + d)) || 0; }
		if (key === 'rev')   { return parseFloat($tr.attr('data-rev' + d)) || 0; }
		return 0;
	}

	function applyPerf() {
		var $body = $('#dze-x-perf-body');
		var rows  = $body.children('tr').get();
		var d     = perf.scope === 'direct' ? '-direct' : '';
		var shown = 0;

		rows.forEach(function (tr) {
			var $tr = $(tr), ok = true;
			if (perf.search && ($tr.attr('data-name') || '').indexOf(perf.search) < 0) { ok = false; }
			if (ok && perf.level === 'top' && parseInt($tr.attr('data-depth'), 10) !== 0) { ok = false; }
			if (ok && perf.level === 'leaf' && $tr.attr('data-leaf') !== '1') { ok = false; }
			$tr.toggle(ok);
			if (ok) { shown++; }
			// numbers follow the chosen scope
			$tr.children('.dze-x-c-count').text($tr.attr('data-count' + d) || '0');
			$tr.children('.dze-x-c-qty').text($tr.attr('data-qty' + d) || '0');
			$tr.children('.dze-x-c-rev').text($tr.attr('data-revfmt' + d) || '');
		});
		$('#dze-x-perf-empty').toggle(shown === 0);

		rows.sort(function (a, b) {
			var $a = $(a), $b = $(b);
			var av = perfVal($a, perf.key, perf.scope), bv = perfVal($b, perf.key, perf.scope);
			var cmp = perf.key === 'name' ? (av < bv ? -1 : (av > bv ? 1 : 0)) : (av - bv);
			cmp = perf.dir === 'asc' ? cmp : -cmp;
			if (cmp !== 0) { return cmp; }
			// Tie-break by units sold, so "sells a lot AND stale" floats to the top.
			return perfVal($b, 'qty', perf.scope) - perfVal($a, 'qty', perf.scope);
		});
		rows.forEach(function (tr) { $body.append(tr); });

		$('.dze-x-sortable').removeClass('is-asc is-desc');
		$('.dze-x-sortable[data-key="' + perf.key + '"]').addClass(perf.dir === 'asc' ? 'is-asc' : 'is-desc');
	}

	$('.dze-x-sortable').on('click', function () {
		var key = $(this).data('key');
		if (perf.key === key) { perf.dir = perf.dir === 'asc' ? 'desc' : 'asc'; }
		else { perf.key = key; perf.dir = (key === 'name' || key === 'res') ? 'asc' : 'desc'; }
		$('.dze-x-axis').removeClass('is-active');
		applyPerf();
	});

	$('.dze-x-axis').on('click', function () {
		$('.dze-x-axis').removeClass('is-active');
		$(this).addClass('is-active');
		if ($(this).data('axis') === 'best') { perf.key = 'qty'; perf.dir = 'desc'; }
		else { perf.key = 'res'; perf.dir = 'asc'; } // stale: never / oldest first
		applyPerf();
	});

	$('#dze-x-perf-scope').on('change', function () { perf.scope = $(this).val(); applyPerf(); });
	$('#dze-x-perf-level').on('change', function () { perf.level = $(this).val(); applyPerf(); });
	$('#dze-x-perf-search').on('input', function () { perf.search = ($(this).val() || '').toLowerCase(); applyPerf(); });

	// Mark a category researched, straight from the table.
	$(document).on('click', '.dze-x-mark', function () {
		var $btn = $(this), cat = parseInt($btn.data('cat'), 10) || 0;
		if (!cat) { return; }
		$btn.prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_explorer_mark_researched', nonce: cfg.nonce, cat: cat })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { return; }
				syncResearched(cat, res.data.ts);
			})
			.fail(function () { $btn.prop('disabled', false); });
	});

	// Jump from a performance row into the Browse grid, filtered to that category.
	$(document).on('click', '.dze-x-view', function () {
		var cat = parseInt($(this).data('cat'), 10) || 0;
		setMode('browse');
		$('.dze-x-cat').removeClass('is-active');
		var $a = $('.dze-x-cat[data-cat="' + cat + '"]').addClass('is-active');
		state.cat = cat;
		$('#dze-x-crumb').text(cat && $a.length ? $.trim($a.find('.dze-x-cat-name').text()) : '');
		if ($a.length) { showResearch($a); }
		load(true);
	});

	// Keep both the table row and the rail link in sync after a "researched" mark.
	function syncResearched(cat, ts) {
		$('.dze-x-prow[data-cat="' + cat + '"]').attr('data-res', ts).children('.dze-x-c-res').text(i18n.justNow);
		$('.dze-x-cat[data-cat="' + cat + '"]').attr('data-res', ts).attr('data-res-h', i18n.justNow);
	}

	// =====================================================================
	// Browse: product grid
	// =====================================================================
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
		state.browseLoaded = true;
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

	$('#dze-x-research-mark').on('click', function () {
		var cat = parseInt($('#dze-x-research').data('cat'), 10) || 0;
		if (!cat) { return; }
		var $btn = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_explorer_mark_researched', nonce: cfg.nonce, cat: cat })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { return; }
				$('#dze-x-research-when').text(i18n.justNow);
				syncResearched(cat, res.data.ts);
			})
			.fail(function () { $btn.prop('disabled', false); });
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

	// ---- Init: land on the director screen ----
	$(function () {
		setMode('perf');
		applyPerf();
	});

}(jQuery));
