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

	$(function () {
		refreshType();
		refreshScope();
		$('#dze-type').on('change', refreshType);
		$(document).on('change', '.dze-scope', refreshScope);
	});

}(jQuery));
