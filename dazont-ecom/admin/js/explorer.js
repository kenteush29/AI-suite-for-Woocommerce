/* global dzeExplorer, jQuery */
(function ($) {
	'use strict';

	var cfg  = dzeExplorer;
	var i18n = cfg.i18n;

	var state = { cat: 0, path: '', paged: 1, loading: false, hasMore: false };

	function escHtml(s) { return $('<span>').text(s == null ? '' : s).html(); }
	function escAttr(s) { return escHtml(s).replace(/"/g, '&quot;'); }

	// =====================================================================
	// Category list (main screen)
	// =====================================================================
	var perf = { view: 'grouped', key: 'qty', dir: 'desc', search: '' };
	var allRows = $('#dze-x-list').children('.dze-x-row').get();

	// Collapse state: start with every parent collapsed (top-level overview).
	var collapsed = {};
	allRows.forEach(function (r) {
		if (r.getAttribute('data-haschild') === '1') { collapsed[r.getAttribute('data-cat')] = true; }
	});

	function val(r, key, view) {
		if (key === 'name') { return r.getAttribute('data-leaf') || ''; }
		if (key === 'res')  { return parseInt(r.getAttribute('data-res'), 10) || 0; }
		var d = view === 'detailed' ? '-direct' : '';
		if (key === 'count') { return parseFloat(r.getAttribute('data-count' + d)) || 0; }
		return parseFloat(r.getAttribute('data-qty' + d)) || 0;
	}
	function cmp(a, b, view) {
		var c;
		if (perf.key === 'name') {
			var an = val(a, 'name'), bn = val(b, 'name');
			c = an < bn ? -1 : (an > bn ? 1 : 0);
		} else {
			c = val(a, perf.key, view) - val(b, perf.key, view);
		}
		if (perf.dir === 'desc') { c = -c; }
		if (c !== 0) { return c; }
		return val(b, 'qty', view) - val(a, 'qty', view); // stable tie-break: best sellers first.
	}

	function buildGrouped() {
		var byParent = {};
		allRows.forEach(function (r) {
			var p = r.getAttribute('data-parent') || '0';
			(byParent[p] = byParent[p] || []).push(r);
		});
		Object.keys(byParent).forEach(function (p) {
			byParent[p].sort(function (a, b) { return cmp(a, b, 'grouped'); });
		});
		var out = [];
		(function dfs(pid, hidden) {
			(byParent[pid] || []).forEach(function (r) {
				out.push({ row: r, hidden: hidden });
				var cat = r.getAttribute('data-cat');
				dfs(cat, hidden || (!perf.search && collapsed[cat]));
			});
		})('0', false);
		return out;
	}

	function applyPerf() {
		var view  = perf.view;
		var $list = $('#dze-x-list');
		$list.toggleClass('is-grouped', view === 'grouped').toggleClass('is-detailed', view === 'detailed');
		$('#dze-x-expand').toggle(view === 'grouped');

		var entries;
		if (view === 'grouped') {
			entries = buildGrouped();
		} else {
			entries = allRows
				.filter(function (r) { return (parseInt(r.getAttribute('data-count-direct'), 10) || 0) > 0; })
				.sort(function (a, b) { return cmp(a, b, 'detailed'); })
				.map(function (r) { return { row: r, hidden: false }; });
		}

		var d = view === 'detailed' ? '-direct' : '';
		var seen = {}, shown = 0;
		entries.forEach(function (e) {
			var r = e.row, $r = $(r);
			seen[r.getAttribute('data-cat')] = true;
			var ok = !e.hidden && (!perf.search || (r.getAttribute('data-name') || '').indexOf(perf.search) >= 0);
			r.style.display = ok ? '' : 'none';
			if (ok) { shown++; }
			var depth = view === 'grouped' ? (parseInt(r.getAttribute('data-depth'), 10) || 0) : 0;
			$r.find('.dze-x-row-indent').css('width', (depth * 20) + 'px');
			var cat = r.getAttribute('data-cat');
			$r.find('.dze-x-tog').not('.dze-x-tog-sp').text(collapsed[cat] ? '▸' : '▾');
			$r.find('.dze-x-row-count').text((r.getAttribute('data-count' + d) || '0') + ' ' + i18n.products);
			$r.find('.dze-x-row-qty').text((r.getAttribute('data-qty' + d) || '0') + ' ' + i18n.sold);
			$list.append(r);
		});
		allRows.forEach(function (r) { if (!seen[r.getAttribute('data-cat')]) { r.style.display = 'none'; } });
		$('#dze-x-perf-empty').toggle(shown === 0);

		// Header arrows.
		$('.dze-x-col').removeClass('is-asc is-desc');
		$('.dze-x-col[data-key="' + perf.key + '"]').addClass(perf.dir === 'asc' ? 'is-asc' : 'is-desc');
		// Expand-all button label.
		var anyCollapsed = Object.keys(collapsed).some(function (k) { return collapsed[k]; });
		$('#dze-x-expand').text(anyCollapsed ? i18n.expandAll : i18n.collapseAll);
	}

	// Column-header sorting.
	$('.dze-x-col').on('click', function () {
		var key = $(this).data('key');
		if (perf.key === key) {
			perf.dir = perf.dir === 'asc' ? 'desc' : 'asc';
		} else {
			perf.key = key;
			perf.dir = (key === 'name' || key === 'res') ? 'asc' : 'desc';
		}
		applyPerf();
	});

	$('.dze-x-view-btn').on('click', function () {
		$('.dze-x-view-btn').removeClass('is-active');
		$(this).addClass('is-active');
		perf.view = $(this).data('view');
		applyPerf();
	});
	$('#dze-x-perf-search').on('input', function () { perf.search = ($(this).val() || '').toLowerCase(); applyPerf(); });

	// Collapse / expand one branch.
	$(document).on('click', '.dze-x-tog', function (e) {
		e.stopPropagation();
		if ($(this).hasClass('dze-x-tog-sp')) { return; }
		var cat = $(this).closest('.dze-x-row').attr('data-cat');
		collapsed[cat] = !collapsed[cat];
		applyPerf();
	});
	// Collapse / expand everything.
	$('#dze-x-expand').on('click', function () {
		var anyCollapsed = Object.keys(collapsed).some(function (k) { return collapsed[k]; });
		allRows.forEach(function (r) {
			if (r.getAttribute('data-haschild') === '1') { collapsed[r.getAttribute('data-cat')] = !anyCollapsed; }
		});
		applyPerf();
	});

	// Mark researched (list + overlay), always behind a confirmation.
	function markResearched(cat, path, done) {
		if (!cat) { return; }
		if (!window.confirm((path ? path + '\n\n' : '') + i18n.confirmMark)) { return; }
		$.post(cfg.ajaxUrl, { action: 'dze_explorer_mark_researched', nonce: cfg.nonce, cat: cat })
			.done(function (res) {
				if (!res.success) { return; }
				var $r = $('.dze-x-row[data-cat="' + cat + '"]');
				$r.attr('data-res', res.data.ts).attr('data-res-h', i18n.justNow);
				$r.find('.dze-x-row-res').text(i18n.justNow);
				if (done) { done(); }
			});
	}
	$(document).on('click', '.dze-x-mark', function (e) {
		e.stopPropagation();
		var $r = $(this).closest('.dze-x-row');
		markResearched(parseInt($(this).data('cat'), 10) || 0, $r.attr('data-path') || '');
	});

	// =====================================================================
	// Products overlay
	// =====================================================================
	// Sub-category band: shown just before the products when the open category
	// still has children; clicking a chip switches the overlay onto it.
	function buildSubcats(cat) {
		var html = '';
		$('.dze-x-row[data-parent="' + cat + '"]').each(function () {
			var $c = $(this);
			var thumb = $c.attr('data-thumb') || '';
			html += '<button type="button" class="dze-x-subcat" data-cat="' + $c.attr('data-cat') + '">' +
				(thumb ? '<img src="' + thumb + '" alt="" />' : '<span class="dze-x-subcat-noimg">🗂️</span>') +
				'<span>' + escHtml($c.attr('data-leafname') || $c.find('.dze-x-row-name').text()) + '</span>' +
				'<span class="dze-x-subcat-count">' + ($c.attr('data-count') || '0') + '</span></button>';
		});
		$('#dze-x-subcats').html(html).toggle(html !== '');
	}
	$(document).on('click', '.dze-x-subcat', function () {
		var $row = $('.dze-x-row[data-cat="' + $(this).data('cat') + '"]');
		if ($row.length) { openOverlay($row); }
	});

	function openOverlay($r) {
		state.cat  = parseInt($r.attr('data-cat'), 10) || 0;
		state.path = $r.attr('data-path') || '';
		$('#dze-x-overlay').attr('data-cat', state.cat); // read by keywords.js
		$('#dze-x-ov-title').text(state.path);
		var thumb = $r.attr('data-thumb') || '';
		$('#dze-x-ov-thumb').html(thumb ? ('<img src="' + thumb + '" alt="" />') : '');
		$('#dze-x-ai-panel').hide().empty();
		$('#dze-x-ai, #dze-x-ov-mark').prop('disabled', false);
		$('#dze-x-ov-add').attr('href', cfg.ajaxUrl.replace('admin-ajax.php', 'post-new.php') + '?post_type=product&dze_cat=' + state.cat);
		buildSubcats(state.cat);
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

	$('#dze-x-ov-mark').on('click', function () {
		var $btn = $(this);
		markResearched(state.cat, state.path, function () { $btn.prop('disabled', true).text(i18n.justNow + ' ✓'); });
	});

	// AI insights: saved report shown free of charge; regeneration behind a cost preview.
	function showReport($panel, d) {
		var bar = '<div class="dze-x-ai-bar"><strong>🎯</strong><span>' + escHtml(i18n.generatedOn) + ' ' +
			(d.ts ? new Date(d.ts * 1000).toLocaleString() : '') + '</span>' +
			'<span class="dze-x-ai-bar-btns">' +
			'<button type="button" class="button button-small" id="dze-x-ai-regen">' + escHtml(i18n.regen) + '</button>' +
			'<button type="button" class="button button-small" id="dze-x-ai-hide">✕</button></span></div>';
		var body = '';
		var data = d.data || null;
		if (data) {
			if (data.summary) { body += '<p class="dze-x-ai-sum">' + escHtml(data.summary) + '</p>'; }
			if (data.source_list && data.source_list.length) {
				body += '<table class="dze-x-kw-tbl"><thead><tr><th>#</th><th>Product to source</th><th>Queries covered</th><th style="text-align:right;">Volume</th></tr></thead><tbody>';
				data.source_list.forEach(function (r, i) {
					body += '<tr><td>' + (i + 1) + '</td><td><strong>' + escHtml(r.product || '') + '</strong></td>' +
						'<td class="dze-x-ai-q">' + escHtml((r.queries || []).join(' · ')) + '</td>' +
						'<td style="text-align:right;font-weight:600;">' + (parseInt(r.volume, 10) || 0).toLocaleString() + '</td></tr>';
				});
				body += '</tbody></table>';
			}
			if (data.ideas && data.ideas.length) {
				body += '<p class="dze-x-ai-ideas-t"><strong>💡 Ideas beyond the keyword data</strong></p><ul class="dze-x-ai-ideas">';
				data.ideas.forEach(function (r) {
					body += '<li><strong>' + escHtml(r.product || '') + '</strong>' + (r.why ? ' — ' + escHtml(r.why) : '') + '</li>';
				});
				body += '</ul>';
			}
		} else if (d.text) {
			body = '<div style="white-space:pre-wrap;">' + escHtml(d.text) + '</div>';
		}
		$panel.html(bar + '<div class="dze-x-ai-body">' + body + '</div>');
	}
	$(document).on('click', '#dze-x-ai-hide', function () { $('#dze-x-ai-panel').hide(); });
	function generateInsights($btn, $panel) {
		$.post(cfg.ajaxUrl, { action: 'dze_explorer_ai_insights', nonce: cfg.nonce, cat: state.cat, mode: 'estimate' })
			.done(function (res) {
				if (!res.success) { $btn.prop('disabled', false); $panel.text((res.data && res.data.message) || i18n.error); return; }
				if (!window.confirm(res.data.message)) { $btn.prop('disabled', false); $panel.hide(); return; }
				$panel.html('<span class="dze-x-ai-spin"></span>' + escHtml(i18n.aiThinking));
				$.post(cfg.ajaxUrl, { action: 'dze_explorer_ai_insights', nonce: cfg.nonce, cat: state.cat, mode: 'generate' })
					.done(function (r2) {
						$btn.prop('disabled', false);
						if (!r2.success) { $panel.text((r2.data && r2.data.message) || i18n.error); return; }
						showReport($panel, r2.data);
					})
					.fail(function () { $btn.prop('disabled', false); $panel.text(i18n.error); });
			})
			.fail(function () { $btn.prop('disabled', false); $panel.text(i18n.error); });
	}
	$('#dze-x-ai').on('click', function () {
		if (!state.cat) { return; }
		var $btn = $(this).prop('disabled', true);
		var $panel = $('#dze-x-ai-panel').show().html('<span class="dze-x-ai-spin"></span>' + escHtml(i18n.aiThinking));
		$.post(cfg.ajaxUrl, { action: 'dze_explorer_ai_insights', nonce: cfg.nonce, cat: state.cat, mode: 'get' })
			.done(function (res) {
				if (res.success && res.data.saved) { $btn.prop('disabled', false); showReport($panel, res.data); return; }
				generateInsights($btn, $panel);
			})
			.fail(function () { $btn.prop('disabled', false); $panel.text(i18n.error); });
	});
	$(document).on('click', '#dze-x-ai-regen', function () {
		generateInsights($('#dze-x-ai').prop('disabled', true), $('#dze-x-ai-panel'));
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

	// ---- Image zoom (single lightbox, closes in one click) ----
	$(document).on('click', '.dze-thumb, .dze-gal-vargrid img', function (e) {
		e.stopPropagation();
		if ($('.dze-lightbox').length) { return; } // never stack lightboxes.
		var full = $(this).data('full') || $(this).attr('src');
		if (full) { $('body').append('<div class="dze-lightbox"><img src="' + full + '" alt="" /></div>'); }
	});
	$(document).on('click', '.dze-lightbox', function () { $('.dze-lightbox').remove(); });

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
