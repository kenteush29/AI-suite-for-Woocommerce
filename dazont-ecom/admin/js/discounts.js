/* global jQuery */
(function ($) {
	'use strict';

	var thresholdLabels = {
		bulk : 'Minimum quantity of the same product'
	};
	var thresholdHelp = {
		bulk : 'The discount applies to a product line once its own quantity reaches this number (repeats for each qualifying product). Shown in the cart as "Bundle".'
	};

	function refreshType() {
		var type = $('#dze-type').val();
		var isSale = (type === 'sale');
		var isBulk = (type === 'bulk');
		var isBulkOrder = (type === 'bulk_order');

		// Marketing Events only.
		$('.dze-field-schedule').toggle(isSale);
		$('.dze-field-banner').toggle(isSale);

		// Percent is used by sale + bulk; bulk_order uses per-tier percents.
		$('.dze-field-percent').toggle(!isBulkOrder);

		// Bulk offer per item.
		$('.dze-field-threshold').toggle(isBulk);
		if (isBulk) {
			$('.dze-threshold-label').text(thresholdLabels.bulk);
			$('.dze-threshold-help').text(thresholdHelp.bulk);
		}

		// Bulk order (tiered).
		$('.dze-field-min-subtotal, .dze-field-min-qty, .dze-field-tiers').toggle(isBulkOrder);
	}

	function refreshScope() {
		var scope = $('.dze-scope:checked').val();
		$('.dze-field-categories').toggle(scope === 'categories');
		$('.dze-field-products').toggle(scope === 'products');
	}

	function refreshBannerLocation() {
		var loc = $('.dze-banner-loc:checked').val();
		$('.dze-field-product-position').toggle(loc === 'product');
	}

	// ---- Media Library picker for hero images ----
	var frame = null;
	$(document).on('click', '.dze-hero-select', function (e) {
		e.preventDefault();
		var $cell = $(this).closest('.dze-hero-picker');

		frame = wp.media({
			title: 'Select image',
			button: { text: 'Use this image' },
			library: { type: 'image' },
			multiple: false
		});

		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
			$cell.find('input[type=hidden]').val(att.id);
			$cell.find('.dze-hero-preview').attr('src', url).show();
			$cell.find('.dze-hero-clear').show();
		});

		frame.open();
	});

	$(document).on('click', '.dze-hero-clear', function (e) {
		e.preventDefault();
		var $cell = $(this).closest('.dze-hero-picker');
		$cell.find('input[type=hidden]').val('');
		$cell.find('.dze-hero-preview').attr('src', '').hide();
		$(this).hide();
	});

	$(function () {
		refreshType();
		refreshScope();
		refreshBannerLocation();
		$('#dze-type').on('change', refreshType);
		$(document).on('change', '.dze-scope', refreshScope);
		$(document).on('change', '.dze-banner-loc', refreshBannerLocation);
	});

}(jQuery));
