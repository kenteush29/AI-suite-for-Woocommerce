/* global dzeGallery, jQuery */
(function ($) {
	'use strict';

	var cfg  = dzeGallery;
	var i18n = cfg.i18n;

	function openLightbox(full) {
		if (!full) { return; }
		$('body').append('<div class="dze-lightbox"><img src="' + full + '" alt="" /></div>');
	}

	// ---- Zoom a product / variation thumbnail ----
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

	// Close the modal when clicking the backdrop (but not its inner content).
	$(document).on('click', '.dze-gal-modal', function (e) {
		if (e.target === this) { closeModal(); }
	});
	$(document).on('keydown', function (e) {
		if (e.key === 'Escape') { $('.dze-lightbox').remove(); closeModal(); }
	});

}(jQuery));
