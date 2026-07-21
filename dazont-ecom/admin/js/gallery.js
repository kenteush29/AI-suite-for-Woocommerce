/* global dzeGallery, jQuery */
(function ($) {
	'use strict';

	var cfg  = dzeGallery;
	var i18n = cfg.i18n;
	var STORE_KEY = 'dze_gal_view';
	var COLS_KEY  = 'dze_gal_cols';

	function applyCols(n) {
		var $grid = $('#dze-gal-view .dze-gal');
		if (!n || n === 'auto') { $grid.css('grid-template-columns', ''); }
		else { $grid.css('grid-template-columns', 'repeat(' + n + ',minmax(0,1fr))'); }
	}

	// ---- List / Gallery view toggle on the standard products list ----
	function applyView(mode) {
		var gallery = (mode === 'gallery');
		$('body').toggleClass('dze-gallery-on', gallery);
		$('#dze-gal-view').toggle(gallery);
		$('.dze-view-toggle .button').removeClass('active');
		$('.dze-view-toggle .dze-view-' + (gallery ? 'gallery' : 'list')).addClass('active');
	}

	function initToggle() {
		var $view = $('#dze-gal-view');
		if (!$view.length) { return; }

		// The grid is printed in the footer (outside .wrap), which would let it
		// slide under the admin menu. Move it into the content area, right after
		// the products table, so it respects the page width.
		var $table = $('.wrap .wp-list-table').first();
		if ($table.length) { $view.insertAfter($table); }
		else { $('.wrap').first().append($view); }

		var $bar = $('<span class="dze-view-toggle"></span>')
			.append('<button type="button" class="button dze-view-list">' + i18n.list + '</button>')
			.append('<button type="button" class="button dze-view-gallery">' + i18n.gallery + '</button>');

		// Place the toggle right after the page title / "Add New" button.
		var $anchor = $('.wrap .page-title-action').last();
		if ($anchor.length) { $anchor.after($bar); }
		else { $('.wrap hr.wp-header-end').first().before($bar); }

		$bar.on('click', '.dze-view-list', function () {
			try { localStorage.setItem(STORE_KEY, 'list'); } catch (e) {}
			applyView('list');
		});
		$bar.on('click', '.dze-view-gallery', function () {
			try { localStorage.setItem(STORE_KEY, 'gallery'); } catch (e) {}
			applyView('gallery');
		});

		// Columns-per-row selector.
		var savedCols = 'auto';
		try { savedCols = localStorage.getItem(COLS_KEY) || 'auto'; } catch (e) {}
		var $cols = $('<span class="dze-gal-cols"><label>' + i18n.columns + ' </label></span>');
		var $sel = $('<select></select>').append('<option value="auto">' + i18n.auto + '</option>');
		[2, 3, 4, 5, 6, 7, 8].forEach(function (n) {
			$sel.append('<option value="' + n + '"' + (String(n) === savedCols ? ' selected' : '') + '>' + n + '</option>');
		});
		$cols.append($sel);
		$bar.after($cols);
		$sel.on('change', function () {
			var v = $(this).val();
			try { localStorage.setItem(COLS_KEY, v); } catch (e) {}
			applyCols(v);
		});
		applyCols(savedCols);

		var saved = 'list';
		try { saved = localStorage.getItem(STORE_KEY) || 'list'; } catch (e) {}
		applyView(saved);
	}

	// ---- Zoom a product / variation thumbnail ----
	function openLightbox(full) {
		if (!full) { return; }
		$('body').append('<div class="dze-lightbox"><img src="' + full + '" alt="" /></div>');
	}
	$(document).on('click', '.dze-thumb, .dze-gal-vargrid img', function () {
		openLightbox($(this).data('full') || $(this).attr('src'));
	});
	$(document).on('click', '.dze-lightbox', function () { $(this).remove(); });

	// ---- Variations popup (loaded on demand) ----
	var $modal = null;
	function openModal(html) {
		$modal = $('#dze-gal-modal');
		$modal.find('.dze-gal-modal__inner').html(html);
		$modal.css('display', 'flex');
	}
	function closeModal() {
		if ($modal) { $modal.hide(); $modal.find('.dze-gal-modal__inner').empty(); }
	}

	$(document).on('click', '.dze-gal__vars', function () {
		var id = $(this).data('product');
		openModal('<p>' + i18n.loading + '</p>');
		$.post(cfg.ajaxUrl, { action: 'dze_gallery_variations', nonce: cfg.nonce, product: id })
			.done(function (res) {
				if (!res.success || !res.data.images || !res.data.images.length) {
					openModal('<p>' + i18n.none + '</p>');
					return;
				}
				var html = '<h2 style="margin-top:0;">' + i18n.variations + '</h2><div class="dze-gal-vargrid">';
				res.data.images.forEach(function (v) {
					if (!v.thumb) { return; }
					html += '<figure><img src="' + v.thumb + '" data-full="' + (v.full || v.thumb) + '" alt="" loading="lazy" />' +
						'<figcaption>' + (v.title || '') + '</figcaption></figure>';
				});
				html += '</div>';
				openModal(html);
			})
			.fail(function () { openModal('<p>' + i18n.error + '</p>'); });
	});

	$(document).on('click', '.dze-gal-modal', function (e) {
		if (e.target === this) { closeModal(); }
	});
	$(document).on('keydown', function (e) {
		if (e.key === 'Escape') { $('.dze-lightbox').remove(); closeModal(); }
	});

	$(initToggle);

}(jQuery));
