/* global dzeKw, jQuery */
/**
 * Keyword Workbench — SEMrush keyword set of the category open in the
 * Sourcing Assistant overlay. Loads the whole set once, then filters/sorts
 * entirely client-side. Import goes through a column-mapping confirmation.
 */
(function ($) {
	'use strict';

	var cfg  = dzeKw;
	var i18n = cfg.i18n;

	var PAGE = 400; // rows rendered at once — keeps a 4000-row set instant.

	var kw = {
		cat: 0,
		open: false,
		loaded: false,
		rows: [],
		sort: { key: 'vol', dir: 'desc' },
		sel: {},
		limit: PAGE,
		upload: null // pending {token, headers, guess, sample, total}
	};

	function esc(s) { return $('<span>').text(s == null ? '' : s).html(); }
	function cat() { return parseInt($('#dze-x-overlay').attr('data-cat'), 10) || 0; }

	var STATUSES = [
		{ v: '',          l: i18n.stNone },
		{ v: 'covered',   l: i18n.stCovered },
		{ v: 'variation', l: i18n.stVariation },
		{ v: 'gap',       l: i18n.stGap },
		{ v: 'to_source', l: i18n.stToSource },
		{ v: 'uncertain', l: i18n.stUncertain },
		{ v: 'ignored',   l: i18n.stIgnored }
	];

	// ---- Static selects (filters + bulk) ----
	(function initSelects() {
		var f = '<option value="">' + esc(i18n.anyStatus) + '</option>';
		STATUSES.slice(1).forEach(function (s) { f += '<option value="' + s.v + '">' + esc(s.l) + '</option>'; });
		f += '<option value="none">' + esc(i18n.stNone) + '</option>';
		$('#dze-x-kw-status').html(f);

		var b = '<option value="__">' + esc(i18n.bulk) + '</option>';
		STATUSES.slice(1).forEach(function (s) { b += '<option value="' + s.v + '">' + esc(s.l) + '</option>'; });
		b += '<option value="">' + esc(i18n.stNone) + '</option>';
		$('#dze-x-kw-bulk').html(b);
	}());

	// =====================================================================
	// Panel toggle + lifecycle
	// =====================================================================
	function setOpen(open) {
		kw.open = open;
		$('#dze-x-kw').toggle(open);
		$('#dze-x-grid, .dze-x-more').toggle(!open);
		$('#dze-x-subcats').toggle(!open && $('#dze-x-subcats').children().length > 0);
		$('#dze-x-kw-toggle').html(open ? '🖼 ' + esc(i18n.products) : '🔑 ' + esc(i18n.keywords));
		if (open && (!kw.loaded || kw.cat !== cat())) { loadRows(); }
	}
	$('#dze-x-kw-toggle').on('click', function () { setOpen(!kw.open); });

	// A new category was opened (row or sub-category chip) or the overlay closed: reset.
	$(document).on('click', '.dze-x-row, .dze-x-subcat, #dze-x-ov-close', function () {
		kw.open = false; kw.loaded = false; kw.rows = []; kw.sel = {};
		$('#dze-x-kw').hide();
		$('#dze-x-grid, .dze-x-more').show();
		$('#dze-x-kw-prog').text('');
		$('#dze-x-kw-toggle').html('🔑 ' + esc(i18n.keywords));
	});

	function loadRows() {
		kw.cat = cat();
		if (!kw.cat) { return; }
		$('#dze-x-kw-table').html('<p style="padding:14px;">' + esc(i18n.loading) + '</p>');
		$.post(cfg.ajaxUrl, { action: 'dze_kw_list', nonce: cfg.nonce, cat: kw.cat })
			.done(function (res) {
				if (!res.success) { $('#dze-x-kw-table').html('<p style="padding:14px;">' + esc(i18n.error) + '</p>'); return; }
				kw.rows = res.data.rows || [];
				kw.loaded = true;
				kw.sel = {};
				kw.limit = PAGE;
				buildIntentFilter();
				render();
			})
			.fail(function () { $('#dze-x-kw-table').html('<p style="padding:14px;">' + esc(i18n.error) + '</p>'); });
	}

	function buildIntentFilter() {
		var seen = {}, html = '<option value="">' + esc(i18n.anyIntent) + '</option>';
		kw.rows.forEach(function (r) {
			(r.intent || '').split(',').forEach(function (p) {
				p = $.trim(p);
				if (p && !seen[p]) { seen[p] = true; html += '<option value="' + esc(p) + '">' + esc(p) + '</option>'; }
			});
		});
		$('#dze-x-kw-intent').html(html);
	}

	// =====================================================================
	// Filtering / sorting / rendering
	// =====================================================================
	function filtered() {
		var q      = ($('#dze-x-kw-q').val() || '').toLowerCase();
		var vmin   = parseFloat($('#dze-x-kw-vmin').val());
		var kdmax  = parseFloat($('#dze-x-kw-kdmax').val());
		var status = $('#dze-x-kw-status').val();
		var intent = $('#dze-x-kw-intent').val();
		return kw.rows.filter(function (r) {
			if (q && r.kw.toLowerCase().indexOf(q) < 0) { return false; }
			if (!isNaN(vmin) && r.vol < vmin) { return false; }
			if (!isNaN(kdmax) && r.kd != null && r.kd > kdmax) { return false; }
			if (status === 'none' && r.status !== '') { return false; }
			if (status && status !== 'none' && r.status !== status) { return false; }
			if (intent && (r.intent || '').toLowerCase().indexOf(intent.toLowerCase()) < 0) { return false; }
			return true;
		});
	}

	function sortRows(rows) {
		var k = kw.sort.key, d = kw.sort.dir === 'asc' ? 1 : -1;
		rows.sort(function (a, b) {
			var av = a[k], bv = b[k];
			if (k === 'kw' || k === 'intent' || k === 'status') {
				av = (av || '').toLowerCase(); bv = (bv || '').toLowerCase();
				return av < bv ? -d : (av > bv ? d : 0);
			}
			return d * ((av == null ? -1 : av) - (bv == null ? -1 : bv));
		});
		return rows;
	}

	function metricsHtml() {
		var active = kw.rows.filter(function (r) { return r.status !== 'ignored'; });
		var ignored = kw.rows.length - active.length;
		var vol = 0, cpcW = 0, cpcV = 0, kdS = 0, kdN = 0, covered = 0, gaps = 0, prodTotal = 0;
		active.forEach(function (r) {
			vol += r.vol;
			if (r.cpc != null) { cpcW += r.cpc * r.vol; cpcV += r.vol; }
			if (r.kd != null) { kdS += r.kd; kdN++; }
			// Completion counts PRODUCT queries only: category-page and
			// informational queries are out of the sourcing equation.
			if (r.t !== 'category' && r.t !== 'info') {
				prodTotal++;
				if (r.status === 'covered' || r.status === 'variation') { covered++; }
			}
			if (r.status === 'gap') { gaps++; }
		});
		var chips = [];
		chips.push('<strong>' + kw.rows.length + '</strong> kw' + (ignored ? ' <span class="dze-x-kw-dim">(' + ignored + ' ' + esc(i18n.mIgnored) + ')</span>' : ''));
		chips.push(esc(i18n.mVolume) + ' <strong>' + vol.toLocaleString() + '</strong>');
		if (cpcV > 0) { chips.push(esc(i18n.mWcpc) + ' <strong>' + (cpcW / cpcV).toFixed(2) + '</strong>'); }
		if (kdN > 0) { chips.push(esc(i18n.mAvgKd) + ' <strong>' + (kdS / kdN).toFixed(0) + '</strong>'); }
		if (prodTotal > 0) {
			chips.push(esc(i18n.mCompletion) + ' <strong>' + Math.round(100 * covered / prodTotal) + '%</strong> (' + covered + '/' + prodTotal + ')');
		}
		chips.push('<span class="dze-x-kw-gaps">' + gaps + ' ' + esc(i18n.mGaps) + '</span>');
		return chips.join('<span class="dze-x-kw-sep">·</span>');
	}

	function statusSelect(r) {
		var html = '<select class="dze-x-kw-st" data-id="' + r.id + '">';
		STATUSES.forEach(function (s) {
			html += '<option value="' + s.v + '"' + (r.status === s.v ? ' selected' : '') + '>' + esc(s.l) + '</option>';
		});
		return html + '</select>';
	}

	function render() {
		$('#dze-x-kw-metrics').html(metricsHtml());
		if (!kw.rows.length) {
			$('#dze-x-kw-table').html('<p style="padding:14px;" class="description">' + esc(i18n.empty) + '</p>');
			return;
		}
		var all  = sortRows(filtered());
		var rows = all.slice(0, kw.limit);
		var arrow = function (k) {
			if (kw.sort.key !== k) { return ''; }
			return kw.sort.dir === 'asc' ? ' ▲' : ' ▼';
		};
		var html = '<table class="dze-x-kw-tbl"><thead><tr>' +
			'<th class="dze-x-kw-chk"><input type="checkbox" id="dze-x-kw-all" /></th>' +
			'<th class="dze-x-kw-srt" data-k="kw">' + esc(i18n.fKeyword) + arrow('kw') + '</th>' +
			'<th class="dze-x-kw-srt dze-x-kw-r" data-k="vol">' + esc(i18n.fVolume) + arrow('vol') + '</th>' +
			'<th class="dze-x-kw-srt dze-x-kw-r" data-k="kd">' + esc(i18n.fKd) + arrow('kd') + '</th>' +
			'<th class="dze-x-kw-srt dze-x-kw-r" data-k="cpc">' + esc(i18n.fCpc) + arrow('cpc') + '</th>' +
			'<th class="dze-x-kw-srt" data-k="intent">' + esc(i18n.fIntent) + arrow('intent') + '</th>' +
			'<th class="dze-x-kw-srt" data-k="status">' + esc(i18n.fStatus) + arrow('status') + '</th>' +
			'</tr></thead><tbody>';
		if (!rows.length) {
			html += '<tr><td colspan="7" style="padding:14px;">' + esc(i18n.noMatch) + '</td></tr>';
		}
		rows.forEach(function (r) {
			html += '<tr class="dze-x-kw-row st-' + (r.status || 'none') + '">' +
				'<td class="dze-x-kw-chk"><input type="checkbox" class="dze-x-kw-cb" data-id="' + r.id + '"' + (kw.sel[r.id] ? ' checked' : '') + ' /></td>' +
				'<td>' + esc(r.kw) + '</td>' +
				'<td class="dze-x-kw-r">' + r.vol.toLocaleString() + '</td>' +
				'<td class="dze-x-kw-r">' + (r.kd == null ? '—' : Math.round(r.kd)) + '</td>' +
				'<td class="dze-x-kw-r">' + (r.cpc == null ? '—' : r.cpc.toFixed(2)) + '</td>' +
				'<td>' + esc(r.intent || '—') + '</td>' +
				'<td>' + statusSelect(r) + '</td>' +
				'</tr>';
		});
		html += '</tbody></table>';
		if (all.length > rows.length) {
			html += '<p style="text-align:center;padding:12px;"><button type="button" class="button" id="dze-x-kw-more">' +
				esc(i18n.showMore) + ' (' + rows.length + ' / ' + all.length + ')</button></p>';
		}
		$('#dze-x-kw-table').html(html);
	}

	$(document).on('click', '#dze-x-kw-more', function () { kw.limit += PAGE; render(); });

	// Debounced filters: typing must never re-render 4000 rows per keystroke.
	var filterTimer = null;
	function filterChanged() {
		clearTimeout(filterTimer);
		filterTimer = setTimeout(function () { kw.limit = PAGE; render(); }, 250);
	}
	$('#dze-x-kw-q, #dze-x-kw-vmin, #dze-x-kw-kdmax').on('input', filterChanged);
	$('#dze-x-kw-status, #dze-x-kw-intent').on('change', filterChanged);
	$(document).on('click', '.dze-x-kw-srt', function () {
		var k = $(this).data('k');
		if (kw.sort.key === k) { kw.sort.dir = kw.sort.dir === 'asc' ? 'desc' : 'asc'; }
		else { kw.sort = { key: k, dir: (k === 'kw' || k === 'intent' || k === 'status') ? 'asc' : 'desc' }; }
		render();
	});

	// ---- Selection ----
	$(document).on('change', '#dze-x-kw-all', function () {
		var on = this.checked;
		$('.dze-x-kw-cb').each(function () { this.checked = on; kw.sel[$(this).data('id')] = on; });
	});
	$(document).on('change', '.dze-x-kw-cb', function () { kw.sel[$(this).data('id')] = this.checked; });

	// ---- Status updates ----
	function postStatus(ids, status, done) {
		$.post(cfg.ajaxUrl, { action: 'dze_kw_status', nonce: cfg.nonce, ids: ids, status: status })
			.done(function (res) { if (res.success && done) { done(); } });
	}
	$(document).on('change', '.dze-x-kw-st', function () {
		var id = parseInt($(this).data('id'), 10), status = $(this).val();
		postStatus([id], status, function () {
			kw.rows.forEach(function (r) { if (r.id === id) { r.status = status; } });
			render();
			refreshBadge();
		});
	});
	$('#dze-x-kw-apply').on('click', function () {
		var status = $('#dze-x-kw-bulk').val();
		if (status === '__') { return; }
		var ids = Object.keys(kw.sel).filter(function (id) { return kw.sel[id]; }).map(Number);
		if (!ids.length) { return; }
		postStatus(ids, status, function () {
			kw.rows.forEach(function (r) { if (ids.indexOf(r.id) >= 0) { r.status = status; } });
			kw.sel = {};
			render();
			refreshBadge();
		});
	});

	// Keep the performance-list badge (kw · gaps) in sync.
	function refreshBadge() {
		var gaps = kw.rows.filter(function (r) { return r.status === 'gap'; }).length;
		var $b = $('.dze-x-row[data-cat="' + kw.cat + '"] .dze-x-row-kwbadge');
		if (kw.rows.length) { $b.text(kw.rows.length + ' kw · ' + gaps + ' gaps').show(); }
		else { $b.hide(); }
	}

	// =====================================================================
	// AI analysis: estimate → confirm → batch loop → auto title keywords
	// =====================================================================
	function analyseStep() {
		$.post(cfg.ajaxUrl, { action: 'dze_kw_analyze', nonce: cfg.nonce, cat: kw.cat })
			.done(function (res) {
				if (!res.success) {
					$('#dze-x-kw-ai').prop('disabled', false);
					$('#dze-x-kw-prog').text((res.data && res.data.message) || i18n.error);
					return;
				}
				if (res.data.remaining > 0) {
					$('#dze-x-kw-prog').text(esc(i18n.analysing) + ' ' + res.data.remaining + ' ' + esc(i18n.remaining));
					analyseStep();
					return;
				}
				// Done — add uncovered product titles as covered long-tail keywords.
				$.post(cfg.ajaxUrl, { action: 'dze_kw_autotitles', nonce: cfg.nonce, cat: kw.cat })
					.always(function (r2) {
						$('#dze-x-kw-ai').prop('disabled', false);
						var added = (r2 && r2.success && r2.data) ? r2.data.added : 0;
						$('#dze-x-kw-prog').text(i18n.analyseDone + (added ? ' +' + added + ' ' + i18n.titlesAdded : ''));
						loadRows();
						setTimeout(refreshBadge, 800);
					});
			})
			.fail(function () {
				$('#dze-x-kw-ai').prop('disabled', false);
				$('#dze-x-kw-prog').text(i18n.error);
			});
	}
	$('#dze-x-kw-ai').on('click', function () {
		var $btn = $(this);
		$.post(cfg.ajaxUrl, { action: 'dze_kw_estimate', nonce: cfg.nonce, cat: cat() })
			.done(function (res) {
				if (!res.success) { window.alert((res.data && res.data.message) || i18n.error); return; }
				if (!window.confirm(res.data.message)) { return; }
				kw.cat = cat();
				$btn.prop('disabled', true);
				$('#dze-x-kw-prog').text(i18n.analysing);
				analyseStep();
			})
			.fail(function () { window.alert(i18n.error); });
	});

	// ---- Product card "🔑 n kw covered" popup ----
	$(document).on('click', '.dze-x-kwprod', function (e) {
		e.stopPropagation();
		var pid = $(this).data('product'), pcat = $(this).data('cat');
		$.post(cfg.ajaxUrl, { action: 'dze_kw_for_product', nonce: cfg.nonce, cat: pcat, product: pid })
			.done(function (res) {
				if (!res.success) { return; }
				var html = '<h2 style="margin-top:0;">' + esc(i18n.kwCovered) + ' — ' + esc(res.data.title) + '</h2>';
				if (!res.data.rows.length) {
					html += '<p>' + esc(i18n.noKw) + '</p>';
				} else {
					html += '<table class="dze-x-kw-tbl"><thead><tr><th>' + esc(i18n.fKeyword) + '</th><th class="dze-x-kw-r">' + esc(i18n.fVolume) + '</th><th>' + esc(i18n.fStatus) + '</th></tr></thead><tbody>';
					res.data.rows.forEach(function (r) {
						html += '<tr><td>' + esc(r.kw) + '</td><td class="dze-x-kw-r">' + r.vol.toLocaleString() + '</td><td>' + esc(r.s === 'variation' ? i18n.stVariation : i18n.stCovered) + '</td></tr>';
					});
					html += '</tbody></table>';
				}
				$('#dze-x-modal').find('.dze-gal-modal__inner').html(html);
				$('#dze-x-modal').css('display', 'flex');
			});
	});

	// =====================================================================
	// Import (upload → mapping confirmation → import)
	// =====================================================================
	var importCat = 0; // category targeted by the current import (row 📥 or overlay button).
	$('#dze-x-kw-import').on('click', function () { importCat = cat(); $('#dze-x-kw-file').trigger('click'); });
	$(document).on('click', '.dze-x-imp', function (e) {
		e.stopPropagation();
		importCat = parseInt($(this).data('cat'), 10) || 0;
		$('#dze-x-kw-file').trigger('click');
	});
	$('#dze-x-kw-file').on('change', function () {
		var file = this.files && this.files[0];
		this.value = '';
		if (!file) { return; }
		var fd = new FormData();
		fd.append('action', 'dze_kw_upload');
		fd.append('nonce', cfg.nonce);
		fd.append('file', file);
		$.ajax({ url: cfg.ajaxUrl, method: 'POST', data: fd, processData: false, contentType: false })
			.done(function (res) {
				if (!res.success) { window.alert((res.data && res.data.message) || i18n.error); return; }
				kw.upload = res.data;
				showMapping();
			})
			.fail(function () { window.alert(i18n.error); });
	});

	function showMapping() {
		var u = kw.upload;
		var fields = [
			{ k: 'keyword', l: i18n.fKeyword + ' *' },
			{ k: 'volume',  l: i18n.fVolume },
			{ k: 'kd',      l: i18n.fKd },
			{ k: 'cpc',     l: i18n.fCpc },
			{ k: 'intent',  l: i18n.fIntent }
		];
		var html = '<h2 style="margin-top:0;">' + esc(i18n.mapTitle) + '</h2>' +
			'<p class="description">' + esc(i18n.mapHelp) + ' <strong>' + u.total + '</strong> ' + esc(i18n.rowsFound) + '.</p>' +
			'<table class="dze-x-kw-map">';
		fields.forEach(function (f) {
			html += '<tr><th>' + esc(f.l) + '</th><td><select data-f="' + f.k + '">';
			html += '<option value="-1">' + esc(i18n.ignore) + '</option>';
			u.headers.forEach(function (h, i) {
				html += '<option value="' + i + '"' + (u.guess[f.k] === i ? ' selected' : '') + '>' + esc(h) + '</option>';
			});
			html += '</select></td></tr>';
		});
		html += '</table>';
		// Sample preview.
		html += '<div style="overflow:auto;max-height:200px;margin-top:10px;"><table class="dze-x-kw-tbl"><thead><tr>';
		u.headers.forEach(function (h) { html += '<th>' + esc(h) + '</th>'; });
		html += '</tr></thead><tbody>';
		u.sample.forEach(function (row) {
			html += '<tr>';
			u.headers.forEach(function (h, i) { html += '<td>' + esc(row[i] == null ? '' : row[i]) + '</td>'; });
			html += '</tr>';
		});
		html += '</tbody></table></div>';
		html += '<p style="margin-bottom:0;"><button type="button" class="button button-primary" id="dze-x-kw-doimport">' + esc(i18n.import) + '</button></p>';
		$('#dze-x-modal').find('.dze-gal-modal__inner').html(html);
		$('#dze-x-modal').css('display', 'flex');
	}

	$(document).on('click', '#dze-x-kw-doimport', function () {
		var u = kw.upload;
		if (!u) { return; }
		var map = {};
		$('.dze-x-kw-map select').each(function () { map[$(this).data('f')] = parseInt($(this).val(), 10); });
		if (map.keyword < 0) { window.alert(i18n.pickKw); return; }
		var target = importCat || cat();
		var $btn = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_kw_import', nonce: cfg.nonce, cat: target, token: u.token, map: map })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { window.alert((res.data && res.data.message) || i18n.error); return; }
				$('#dze-x-modal').hide();
				kw.upload = null;
				window.alert(res.data.imported + ' ' + i18n.imported + (res.data.updated ? ' (' + res.data.updated + ' ' + i18n.updated + ')' : ''));
				// Row icons: list present, analysis pending.
				var $row = $('.dze-x-row[data-cat="' + target + '"]');
				$row.find('.dze-x-ico-kw').removeClass('is-off').text('⏳');
				$row.find('.dze-x-an').show();
				if (kw.open && kw.cat === target) { loadRows(); }
				setTimeout(refreshBadge, 800);
			})
			.fail(function () { $btn.prop('disabled', false); window.alert(i18n.error); });
	});

	// =====================================================================
	// Row / bulk analysis (background loop with global progress + estimate)
	// =====================================================================
	function bgLoop(catId) {
		$.post(cfg.ajaxUrl, { action: 'dze_kw_analyze', nonce: cfg.nonce, cat: catId })
			.done(function (res) {
				if (!res.success) { $('#dze-x-global-prog').text((res.data && res.data.message) || i18n.error); return; }
				if (res.data.remaining > 0) {
					$('#dze-x-global-prog').text((res.data.termName ? res.data.termName + ' — ' : '') + res.data.remaining + ' ' + i18n.remaining);
					bgLoop(catId);
					return;
				}
				var finish = function () {
					$('#dze-x-global-prog').text(i18n.analyseDone);
					$('.dze-x-row' + (catId ? '[data-cat="' + catId + '"]' : '')).find('.dze-x-ico-kw').text('🔑').end().find('.dze-x-an').hide();
				};
				if (catId) { $.post(cfg.ajaxUrl, { action: 'dze_kw_autotitles', nonce: cfg.nonce, cat: catId }).always(finish); }
				else { finish(); }
			})
			.fail(function () { $('#dze-x-global-prog').text(i18n.error); });
	}
	function estimateThen(catId) {
		$.post(cfg.ajaxUrl, { action: 'dze_kw_estimate', nonce: cfg.nonce, cat: catId })
			.done(function (res) {
				if (!res.success) { window.alert((res.data && res.data.message) || i18n.error); return; }
				if (!window.confirm(res.data.message)) { return; }
				$('#dze-x-global-prog').text(i18n.analysing);
				bgLoop(catId);
			})
			.fail(function () { window.alert(i18n.error); });
	}
	$(document).on('click', '.dze-x-an', function (e) {
		e.stopPropagation();
		estimateThen(parseInt($(this).data('cat'), 10) || 0);
	});
	$('#dze-x-kw-bulk-ai').on('click', function () { estimateThen(0); });

	// =====================================================================
	// Shop-wide opportunities panel
	// =====================================================================
	var opps = { open: false, loaded: false };
	$('#dze-x-opps-toggle').on('click', function () {
		opps.open = !opps.open;
		$('#dze-x-opps').toggle(opps.open);
		$('#dze-x-list, .dze-x-list-head').toggle(!opps.open);
		$(this).toggleClass('button-primary', opps.open);
		if (opps.open && !opps.loaded) {
			$('#dze-x-opps').html('<p style="padding:14px;">' + esc(i18n.loading) + '</p>');
			$.post(cfg.ajaxUrl, { action: 'dze_kw_opps', nonce: cfg.nonce }).done(function (res) {
				opps.loaded = true;
				if (!res.success || !res.data.rows.length) { $('#dze-x-opps').html('<p style="padding:14px;" class="description">' + esc(i18n.noOpps) + '</p>'); return; }
				var html = '<table class="dze-x-kw-tbl"><thead><tr><th>' + esc(i18n.fKeyword) + '</th><th class="dze-x-kw-r">' + esc(i18n.fVolume) + '</th><th class="dze-x-kw-r">' + esc(i18n.fKd) + '</th><th class="dze-x-kw-r">' + esc(i18n.fCpc) + '</th><th>' + esc(i18n.fStatus) + '</th><th></th></tr></thead><tbody>';
				res.data.rows.forEach(function (r) {
					html += '<tr class="dze-x-kw-row st-' + r.status + '"><td>' + esc(r.kw) + '</td>' +
						'<td class="dze-x-kw-r">' + r.vol.toLocaleString() + '</td>' +
						'<td class="dze-x-kw-r">' + (r.kd == null ? '—' : Math.round(r.kd)) + '</td>' +
						'<td class="dze-x-kw-r">' + (r.cpc == null ? '—' : r.cpc.toFixed(2)) + '</td>' +
						'<td>' + esc(r.status === 'to_source' ? i18n.stToSource : i18n.stGap) + '</td>' +
						'<td><button type="button" class="button button-small dze-x-opp-go" data-cat="' + r.cat + '">' + esc(r.catName) + ' →</button></td></tr>';
				});
				$('#dze-x-opps').html(html + '</tbody></table>');
			});
		}
	});
	$(document).on('click', '.dze-x-opp-go', function () {
		var $row = $('.dze-x-row[data-cat="' + $(this).data('cat') + '"]');
		if ($row.length) { $row.trigger('click'); }
	});

	// =====================================================================
	// Export + delete
	// =====================================================================
	$('#dze-x-kw-export').on('click', function () {
		if (!kw.rows.length) { return; }
		var rows = sortRows(filtered());
		var csv = 'Keyword,Volume,Keyword Difficulty,CPC,Intent,Status\n';
		rows.forEach(function (r) {
			csv += '"' + r.kw.replace(/"/g, '""') + '",' + r.vol + ',' + (r.kd == null ? '' : r.kd) + ',' + (r.cpc == null ? '' : r.cpc) + ',"' + (r.intent || '').replace(/"/g, '""') + '","' + (r.status || '') + '"\n';
		});
		var a = document.createElement('a');
		a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
		a.download = 'keywords-cat-' + kw.cat + '.csv';
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		URL.revokeObjectURL(a.href);
	});

	$('#dze-x-kw-delete').on('click', function () {
		if (!kw.rows.length || !window.confirm(i18n.confirmDel)) { return; }
		$.post(cfg.ajaxUrl, { action: 'dze_kw_clear', nonce: cfg.nonce, cat: kw.cat })
			.done(function (res) {
				if (!res.success) { return; }
				kw.rows = [];
				render();
				refreshBadge();
			});
	});

}(jQuery));
