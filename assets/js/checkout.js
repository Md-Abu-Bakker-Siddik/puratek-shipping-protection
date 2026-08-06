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
			var $checkbox = $(this);
			var optIn = $checkbox.is(':checked') ? 'yes' : 'no';
			var previousState = optIn !== 'yes';

			$checkbox.prop('disabled', true);

			$.post(puratekSP.ajaxUrl, {
				nonce: puratekSP.nonce,
				opt_in: optIn
			}).done(function (response) {
				if (!response || !response.success) {
					$checkbox.prop('checked', previousState);
					return;
				}

				// Recalculate totals; WooCommerce blocks the UI while it runs.
				$(document.body).trigger('update_checkout');
			}).fail(function () {
				// Keep the UI consistent with the unchanged server-side session.
				$checkbox.prop('checked', previousState);
			}).always(function () {
				$checkbox.prop('disabled', false);
			});
		});
	});
})(jQuery);
