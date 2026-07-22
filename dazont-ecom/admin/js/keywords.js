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

	var kw = {
		cat: 0,
		open: false,
		loaded: false,
		rows: [],
		sort: { key: 'vol', dir: 'desc' },
		sel: {},
		upload: null // pending {token, headers, guess, sample, total}
	};

	function esc(s) { return $('<span>').text(s == null ? '' : s).html(); }
	function cat() { return parseInt($('#dze-x-overlay').attr('data-cat'), 10) || 0; }

	var STATUSES = [
		{ v: '',          l: i18n.stNone },
		{ v: 'covered',   l: i18n.stCovered },
		{ v: 'gap',       l: i18n.stGap },
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
		$('#dze-x-kw-toggle').html(open ? '🖼 ' + esc(i18n.products) : '🔑 ' + esc(i18n.keywords));
		if (open && (!kw.loaded || kw.cat !== cat())) { loadRows(); }
	}
	$('#dze-x-kw-toggle').on('click', function () { setOpen(!kw.open); });

	// A new category was opened (row click) or the overlay closed: reset.
	$(document).on('click', '.dze-x-row, #dze-x-ov-close', function () {
		kw.open = false; kw.loaded = false; kw.rows = []; kw.sel = {};
		$('#dze-x-kw').hide();
		$('#dze-x-grid, .dze-x-more').show();
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
		var vol = 0, cpcW = 0, cpcV = 0, kdS = 0, kdN = 0, covered = 0, gaps = 0;
		active.forEach(function (r) {
			vol += r.vol;
			if (r.cpc != null) { cpcW += r.cpc * r.vol; cpcV += r.vol; }
			if (r.kd != null) { kdS += r.kd; kdN++; }
			if (r.status === 'covered') { covered++; }
			if (r.status === 'gap') { gaps++; }
		});
		var chips = [];
		chips.push('<strong>' + kw.rows.length + '</strong> kw' + (ignored ? ' <span class="dze-x-kw-dim">(' + ignored + ' ' + esc(i18n.mIgnored) + ')</span>' : ''));
		chips.push(esc(i18n.mVolume) + ' <strong>' + vol.toLocaleString() + '</strong>');
		if (cpcV > 0) { chips.push(esc(i18n.mWcpc) + ' <strong>' + (cpcW / cpcV).toFixed(2) + '</strong>'); }
		if (kdN > 0) { chips.push(esc(i18n.mAvgKd) + ' <strong>' + (kdS / kdN).toFixed(0) + '</strong>'); }
		if (active.length > 0) {
			chips.push(esc(i18n.mCompletion) + ' <strong>' + Math.round(100 * covered / active.length) + '%</strong> (' + covered + '/' + active.length + ')');
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
		var rows = sortRows(filtered());
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
		$('#dze-x-kw-table').html(html);
	}

	$('#dze-x-kw-q, #dze-x-kw-vmin, #dze-x-kw-kdmax').on('input', render);
	$('#dze-x-kw-status, #dze-x-kw-intent').on('change', render);
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
	// Import (upload → mapping confirmation → import)
	// =====================================================================
	$('#dze-x-kw-import').on('click', function () { $('#dze-x-kw-file').trigger('click'); });
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
		if (kw.rows.length && !window.confirm(i18n.confirmImp)) { return; }
		var $btn = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_kw_import', nonce: cfg.nonce, cat: cat(), token: u.token, map: map })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { window.alert((res.data && res.data.message) || i18n.error); return; }
				$('#dze-x-modal').hide();
				kw.upload = null;
				window.alert(res.data.imported + ' ' + i18n.imported);
				loadRows();
				setTimeout(refreshBadge, 800);
			})
			.fail(function () { $btn.prop('disabled', false); window.alert(i18n.error); });
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
