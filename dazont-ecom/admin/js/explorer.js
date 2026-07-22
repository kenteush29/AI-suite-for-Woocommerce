/* global dzeExplorer, jQuery */
(function ($) {
	'use strict';

	var cfg  = dzeExplorer;
	var i18n = cfg.i18n;

	var state = { cat: 0, paged: 1, loading: false, hasMore: false };

	function escHtml(s) { return $('<span>').text(s == null ? '' : s).html(); }
	function escAttr(s) { return escHtml(s).replace(/"/g, '&quot;'); }

	// =====================================================================
	// Category list (main screen)
	// =====================================================================
	var perf = { view: 'grouped', sort: 'qty', search: '' };
	var allRows = $('#dze-x-list').children('.dze-x-row').get();

	function val(r, key, view) {
		if (key === 'name') { return r.getAttribute('data-name') || ''; }
		if (key === 'seq')  { return parseInt(r.getAttribute('data-seq'), 10) || 0; }
		if (key === 'res')  { return parseInt(r.getAttribute('data-res'), 10) || 0; }
		var d = view === 'detailed' ? '-direct' : '';
		return parseFloat(r.getAttribute('data-qty' + d)) || 0;
	}
	function cmp(a, b, key, view) {
		if (key === 'name') { var an = val(a, 'name'), bn = val(b, 'name'); return an < bn ? -1 : (an > bn ? 1 : 0); }
		if (key === 'seq') { return val(a, 'seq') - val(b, 'seq'); }
		if (key === 'res') { var dr = val(a, 'res') - val(b, 'res'); if (dr) { return dr; } return val(b, 'qty', view) - val(a, 'qty', view); }
		return val(b, 'qty', view) - val(a, 'qty', view); // units, descending
	}
	function buildGrouped(key) {
		var byParent = {};
		allRows.forEach(function (r) { var p = r.getAttribute('data-parent') || '0'; (byParent[p] = byParent[p] || []).push(r); });
		Object.keys(byParent).forEach(function (p) { byParent[p].sort(function (a, b) { return cmp(a, b, key, 'grouped'); }); });
		var out = [];
		(function dfs(pid) {
			(byParent[pid] || []).forEach(function (r) { out.push(r); dfs(r.getAttribute('data-cat')); });
		})('0');
		return out;
	}
	function applyPerf() {
		var view = perf.view, key = perf.sort;
		var $list = $('#dze-x-list');
		$list.toggleClass('is-grouped', view === 'grouped').toggleClass('is-detailed', view === 'detailed');

		var ordered;
		if (view === 'grouped') {
			ordered = buildGrouped(key);
		} else {
			ordered = allRows.filter(function (r) { return (parseInt(r.getAttribute('data-count-direct'), 10) || 0) > 0; });
			ordered.sort(function (a, b) { return cmp(a, b, key, 'detailed'); });
		}

		var d = view === 'detailed' ? '-direct' : '';
		var inView = {}, shown = 0;
		ordered.forEach(function (r) {
			inView[r.getAttribute('data-cat')] = true;
			var ok = !perf.search || (r.getAttribute('data-name') || '').indexOf(perf.search) >= 0;
			r.style.display = ok ? '' : 'none';
			if (ok) { shown++; }
			var depth = view === 'grouped' ? (parseInt(r.getAttribute('data-depth'), 10) || 0) : 0;
			var $r = $(r);
			$r.find('.dze-x-row-indent').css('width', (depth * 18) + 'px');
			$r.find('.dze-x-row-count').text((r.getAttribute('data-count' + d) || '0') + ' ' + i18n.products);
			$r.find('.dze-x-row-qty').text((r.getAttribute('data-qty' + d) || '0') + ' ' + i18n.sold);
			$list.append(r);
		});
		allRows.forEach(function (r) { if (!inView[r.getAttribute('data-cat')]) { r.style.display = 'none'; } });
		$('#dze-x-perf-empty').toggle(shown === 0);
	}

	$('.dze-x-view-btn').on('click', function () {
		$('.dze-x-view-btn').removeClass('is-active');
		$(this).addClass('is-active');
		perf.view = $(this).data('view');
		applyPerf();
	});
	$('#dze-x-perf-sort').on('change', function () { perf.sort = $(this).val(); applyPerf(); });
	$('#dze-x-perf-search').on('input', function () { perf.search = ($(this).val() || '').toLowerCase(); applyPerf(); });

	// Mark researched without opening the overlay.
	$(document).on('click', '.dze-x-mark', function (e) {
		e.stopPropagation();
		var $btn = $(this), cat = parseInt($btn.data('cat'), 10) || 0;
		if (!cat) { return; }
		$btn.prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_explorer_mark_researched', nonce: cfg.nonce, cat: cat })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { return; }
				var $r = $btn.closest('.dze-x-row');
				$r.attr('data-res', res.data.ts);
				$r.find('.dze-x-row-res').text(i18n.justNow);
			})
			.fail(function () { $btn.prop('disabled', false); });
	});

	// =====================================================================
	// Products overlay
	// =====================================================================
	function openOverlay($r) {
		state.cat = parseInt($r.attr('data-cat'), 10) || 0;
		$('#dze-x-ov-title').text($r.attr('data-path') || '');
		var thumb = $r.attr('data-thumb') || '';
		$('#dze-x-ov-thumb').html(thumb ? ('<img src="' + thumb + '" alt="" />') : '');
		$('#dze-x-ai-panel').hide().empty();
		$('#dze-x-ai').prop('disabled', false);
		$('#dze-x-overlay').css('display', 'flex');
		$('body').addClass('dze-x-ov-open');
		load(true);
	}
	function closeOverlay() { $('#dze-x-overlay').hide(); $('body').removeClass('dze-x-ov-open'); }

	$(document).on('click', '.dze-x-row', function () { openOverlay($(this)); });
	$(document).on('keydown', '.dze-x-row', function (e) {
		if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openOverlay($(this)); }
	});
	$('#dze-x-ov-close').on('click', closeOverlay);

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
	function load(reset) {
		if (state.loading) { return; }
		state.loading = true;
		if (reset) { state.paged = 1; $('#dze-x-grid').empty(); }
		$('#dze-x-status').text(i18n.loading);
		$('#dze-x-load').hide();

		var data = { action: 'dze_explorer_products', nonce: cfg.nonce, paged: state.paged, cat: state.cat };
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

	// ---- Variations popup (sortable: by ID or by an attribute) ----
	var vars = { images: [], attrs: [], sort: 'id' };
	function showModal(html) { $('#dze-x-modal').find('.dze-gal-modal__inner').html(html); $('#dze-x-modal').css('display', 'flex'); }
	function renderVars() {
		var s = vars.sort;
		var imgs = vars.images.slice().sort(function (a, b) {
			if (s === 'id') { return (a.id || 0) - (b.id || 0); }
			var av = (a.attrs && a.attrs[s]) || '', bv = (b.attrs && b.attrs[s]) || '';
			return av < bv ? -1 : (av > bv ? 1 : 0);
		});
		var opts = '<option value="id"' + (s === 'id' ? ' selected' : '') + '>' + escHtml(i18n.byId) + '</option>';
		vars.attrs.forEach(function (a) {
			opts += '<option value="' + escAttr(a) + '"' + (a === s ? ' selected' : '') + '>' + escHtml(a) + '</option>';
		});
		var html = '<div class="dze-var-head"><h2 style="margin:0;">' + escHtml(i18n.variations) + '</h2>' +
			'<label class="dze-var-sort">' + escHtml(i18n.sortBy) + ' <select id="dze-var-sort">' + opts + '</select></label></div>' +
			'<div class="dze-gal-vargrid">';
		imgs.forEach(function (v) {
			if (!v.thumb) { return; }
			html += '<figure><img src="' + v.thumb + '" data-full="' + (v.full || v.thumb) + '" alt="" loading="lazy" />' +
				'<figcaption>' + escHtml(v.title || '') + '</figcaption></figure>';
		});
		html += '</div>';
		showModal(html);
	}
	$(document).on('click', '.dze-x-vars', function () {
		var id = $(this).data('product');
		showModal('<p>' + escHtml(i18n.loading) + '</p>');
		$.post(cfg.ajaxUrl, { action: 'dze_explorer_variations', nonce: cfg.nonce, product: id }).done(function (res) {
			if (!res.success || !res.data.images || !res.data.images.length) { showModal('<p>' + escHtml(i18n.none) + '</p>'); return; }
			vars.images = res.data.images;
			vars.attrs  = res.data.attributes || [];
			vars.sort   = 'id';
			renderVars();
		}).fail(function () { showModal('<p>' + escHtml(i18n.error) + '</p>'); });
	});
	$(document).on('change', '#dze-var-sort', function () { vars.sort = $(this).val(); renderVars(); });

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
