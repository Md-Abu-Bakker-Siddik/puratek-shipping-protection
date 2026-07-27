/**
 * Puratek Shipping Protection — checkout toggle.
 *
 * The checkbox row lives inside the order review table, which WooCommerce
 * re-renders on every update_checkout, so the change handler is delegated
 * to document.body and survives fragment refreshes.
 */
(function ($) {
	'use strict';

	$(function () {
		$(document.body).on('change', '#puratek-shipping-protection-checkbox', function () {
			var optIn = $(this).is(':checked') ? 'yes' : 'no';

			$.post(puratekSP.ajaxUrl, {
				nonce: puratekSP.nonce,
				opt_in: optIn
			}).always(function () {
				// Recalculate totals; WooCommerce blocks the UI while it runs.
				$(document.body).trigger('update_checkout');
			});
		});
	});
})(jQuery);
