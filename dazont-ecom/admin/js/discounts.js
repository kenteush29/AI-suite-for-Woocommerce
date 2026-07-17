/* global jQuery */
(function ($) {
	'use strict';

	var thresholdLabels = {
		cart_qty      : 'Minimum items in cart',
		cart_subtotal : 'Minimum cart subtotal',
		bulk          : 'Minimum quantity of the same product'
	};
	var thresholdHelp = {
		cart_qty      : 'Discount applies once the in-scope cart quantity reaches this number.',
		cart_subtotal : 'Discount applies once the in-scope cart subtotal reaches this amount.',
		bulk          : 'Per-product discount applies once a customer buys at least this many of the same product.'
	};

	function refreshType() {
		var type = $('#dze-type').val();
		var isSale = (type === 'sale');
		var usesThreshold = thresholdLabels.hasOwnProperty(type);

		$('.dze-field-schedule').toggle(isSale);
		$('.dze-field-banner').toggle(isSale);
		$('.dze-field-threshold').toggle(usesThreshold);

		if (usesThreshold) {
			$('.dze-threshold-label').text(thresholdLabels[type]);
			$('.dze-threshold-help').text(thresholdHelp[type]);
		}
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
