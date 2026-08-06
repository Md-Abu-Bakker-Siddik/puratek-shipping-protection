/**
 * Puratek Shipping Protection checkout widget and accessible information modal.
 *
 * WooCommerce replaces the order-review markup during checkout updates, so all
 * widget handlers are delegated from document. The toggle request itself uses
 * native fetch; the existing WooCommerce checkout event is used only to ask
 * Classic Checkout to recalculate its fragments and totals.
 */
(function () {
	'use strict';

	var checkboxSelector = '#puratek-shipping-protection-checkbox';
	var modalSelector = '#puratek-sp-information-modal';
	var triggerSelector = '.puratek-sp-modal-trigger';
	var closeSelector = '.puratek-sp-modal__close';
	var lastFocusedElement = null;
	var closeTimer = null;

	function getModal() {
		return document.querySelector(modalSelector);
	}

	function getFocusableElements(modal) {
		return Array.prototype.slice.call(
			modal.querySelectorAll(
				'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
			)
		).filter(function (element) {
			return !element.hidden && element.getAttribute('aria-hidden') !== 'true';
		});
	}

	function openModal(trigger) {
		var modal = getModal();
		var closeButton;

		if (!modal || !modal.hidden) {
			return;
		}

		lastFocusedElement = trigger || document.activeElement;
		if (closeTimer) {
			window.clearTimeout(closeTimer);
			closeTimer = null;
		}
		modal.hidden = false;
		modal.classList.remove('puratek-sp-modal--closing');
		document.body.classList.add('puratek-sp-modal-open');
		closeButton = modal.querySelector(closeSelector);

		window.requestAnimationFrame(function () {
			modal.classList.add('puratek-sp-modal--open');
			window.setTimeout(function () {
				if (modal.hidden || !modal.classList.contains('puratek-sp-modal--open')) {
					return;
				}
				if (closeButton) {
					closeButton.focus();
				} else {
					modal.querySelector('.puratek-sp-modal__dialog').focus();
				}
			}, 80);
		});
	}

	function closeModal() {
		var modal = getModal();
		var prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		var finishClose;

		if (!modal || modal.hidden || modal.classList.contains('puratek-sp-modal--closing')) {
			return;
		}

		finishClose = function () {
			if (closeTimer) {
				window.clearTimeout(closeTimer);
				closeTimer = null;
			}
			modal.hidden = true;
			modal.classList.remove('puratek-sp-modal--closing');
			document.body.classList.remove('puratek-sp-modal-open');

			if (lastFocusedElement && document.documentElement.contains(lastFocusedElement)) {
				lastFocusedElement.focus();
			}
			lastFocusedElement = null;
		};

		modal.classList.add('puratek-sp-modal--closing');
		modal.classList.remove('puratek-sp-modal--open');

		if (prefersReducedMotion) {
			finishClose();
			return;
		}

		closeTimer = window.setTimeout(finishClose, 260);
	}

	function trapModalFocus(event) {
		var modal = getModal();
		var focusable;
		var first;
		var last;

		if (!modal || modal.hidden || event.key !== 'Tab') {
			return;
		}

		focusable = getFocusableElements(modal);
		if (!focusable.length) {
			event.preventDefault();
			modal.querySelector('.puratek-sp-modal__dialog').focus();
			return;
		}

		first = focusable[0];
		last = focusable[focusable.length - 1];

		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	}

	function announce(widget, message) {
		var status = widget ? widget.querySelector('.puratek-sp-widget__status') : null;

		if (status) {
			status.textContent = '';
			window.setTimeout(function () {
				status.textContent = message;
			}, 20);
		}
	}

	function requestCheckoutUpdate() {
		if (window.jQuery && typeof window.jQuery === 'function') {
			window.jQuery(document.body).trigger('update_checkout');
			return;
		}

		// Defensive fallback for checkout implementations listening natively.
		document.body.dispatchEvent(new CustomEvent('update_checkout', { bubbles: true }));
	}

	function toggleProtection(checkbox) {
		var widget = checkbox.closest('.puratek-sp-widget');
		var optedIn = checkbox.checked;
		var previousState = !optedIn;
		var body = new URLSearchParams();

		if (!window.puratekSP || !window.puratekSP.ajaxUrl || checkbox.disabled) {
			return;
		}

		body.set('nonce', window.puratekSP.nonce || '');
		body.set('opt_in', optedIn ? 'yes' : 'no');
		checkbox.disabled = true;
		checkbox.setAttribute('aria-busy', 'true');
		if (widget) {
			widget.classList.add('puratek-sp-widget--updating');
		}

		window.fetch(window.puratekSP.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: body.toString()
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('Shipping Protection request failed.');
			}
			return response.json();
		}).then(function (response) {
			if (!response || response.success !== true) {
				throw new Error('Shipping Protection was not updated.');
			}

			announce(
				widget,
				optedIn ? window.puratekSP.enabledText : window.puratekSP.disabledText
			);
			requestCheckoutUpdate();
		}).catch(function () {
			checkbox.checked = previousState;
			announce(widget, window.puratekSP.errorText || 'Shipping Protection could not be updated.');
		}).finally(function () {
			checkbox.disabled = false;
			checkbox.removeAttribute('aria-busy');
			if (widget) {
				widget.classList.remove('puratek-sp-widget--updating');
			}
		});
	}

	document.addEventListener('change', function (event) {
		if (event.target && event.target.matches(checkboxSelector)) {
			toggleProtection(event.target);
		}
	});

	document.addEventListener('click', function (event) {
		var trigger = event.target.closest(triggerSelector);
		var closeButton = event.target.closest(closeSelector);
		var modal = getModal();

		if (trigger) {
			event.preventDefault();
			openModal(trigger);
			return;
		}

		if (closeButton) {
			event.preventDefault();
			closeModal();
			return;
		}

		if (modal && !modal.hidden && event.target === modal) {
			closeModal();
		}
	});

	document.addEventListener('keydown', function (event) {
		var modal = getModal();

		if (!modal || modal.hidden) {
			return;
		}

		if (event.key === 'Escape') {
			event.preventDefault();
			closeModal();
			return;
		}

		trapModalFocus(event);
	});
}());
