=== Puratek Shipping Protection ===
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPL-2.0-or-later

In-house shipping protection fee for WooCommerce, replacing the Route plugin.

== Description ==

Adds a "Shipping Protection (3%)" checkbox to the classic WooCommerce checkout:

* Checked by default — the fee is added automatically.
* The customer can untick it; totals refresh via AJAX without a page reload.
* The fee is a standard WooCommerce fee line, so it appears on the admin
  order screen, customer invoices, and all WooCommerce emails automatically.
* The customer's choice is stored on the order
  (meta `_puratek_shipping_protection` = yes/no, fee amount in
  `_puratek_shipping_protection_fee`).
* Works on single-site and multisite installs. HPOS compatible.
  Classic (shortcode) checkout only.

== Configuration ==

No settings screen — defaults are intentionally simple.

Fee base: the PURATEK_SP_FEE_BASE constant at the top of the main plugin
file switches between 'subtotal' (default) and 'subtotal_shipping'
(subtotal + shipping cost) as the 3% base — a one-line change.

Filters:

* `puratek_sp_percentage`  — fee percentage (default 3).
* `puratek_sp_label`       — customer-facing label.
* `puratek_sp_fee_base`    — fee base amount (default: cart contents total
                             after discounts, excluding shipping and tax).
* `puratek_sp_fee_taxable` — whether the fee is taxable (default: no).

== Changelog ==

= 1.0.1 =
* Finalized the fee base as discounted merchandise subtotal, excluding shipping and tax.
* Hardened percentage filtering and checkout-session error handling.
* Restores the checkbox if the AJAX update fails.

= 1.0.0 =
* Initial release: checkout checkbox, 3% fee, AJAX toggle, order meta,
  admin order indicator.
