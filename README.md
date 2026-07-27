# Puratek Shipping Protection

**In-house shipping protection for WooCommerce — replaces Route.**

Instead of paying a third-party service, Puratek now offers its own shipping
protection. A **3% fee** is added automatically at checkout, and the customer
can remove it with one click if they don't want it. All protection revenue
stays with the store.

---

## What this plugin does

| | |
|---|---|
| **Adds** | A "Shipping Protection (3%)" checkbox on the checkout page, **checked by default** |
| **Charges** | 3% of the cart subtotal (after discounts) as a normal WooCommerce fee |
| **Customer control** | Untick the box → fee is removed instantly, no page reload |
| **Records** | The choice (Yes/No) and fee amount are saved on every order |
| **Replaces** | The Route plugin and its third-party subscription |

---

## What the customer sees

1. Customer adds products to the cart and goes to **Checkout**.
2. In the order summary, just above the total, they see:

   ```
   ☑  Shipping Protection (3%)
      Protects your order against loss, theft, or damage in transit.

   Shipping Protection (3%)                          $4.79
   ─────────────────────────────────────────────────────────
   Total                                           $164.44
   ```

3. The box is **already ticked** — protection is included automatically.
4. If they untick it, the totals refresh in place and the fee disappears.
5. The fee (when kept) appears on the thank-you page, the order confirmation
   email, and the customer's invoice — exactly like any other order line.

Every **new** order starts with the box ticked again, even if the same
customer removed protection on a previous order.

---

## What the store team sees

**WooCommerce → Orders → (any order):**

- The fee appears as a line item: `Shipping Protection (3%)  $4.79`
- In the order details box there is a clear indicator:
  - 🟢 **Shipping Protection: Yes** — this order is protected
  - 🟠 **Shipping Protection: No (customer removed)** — customer opted out

**When a customer reports a lost / stolen / damaged shipment:**

1. Open the order and check the indicator.
2. **Yes** → the order is protected; reship or refund per Puratek's
   protection policy. (Claims are now handled by Puratek support, not Route.)
3. **No** → the customer declined protection; handle per standard policy.

**Refunds:** the protection fee is a normal order line — when refunding, you
choose whether to include it, just like shipping.

---

## Installation & go-live

1. Upload the plugin: **Plugins → Add New → Upload Plugin** → select the zip
   → **Activate**. (No settings screen — it works immediately.)
2. Place a small test order and confirm the fee appears at checkout, on the
   order, and in the confirmation email.
3. **Only after the test order is verified:** deactivate the **Route** plugin.
4. Cancel the Route subscription.

> ⚠️ Never run this plugin and Route at the same time in production — the
> customer would be charged for two protections.

---

## Configuration

There is intentionally no settings page — fewer things to break. Behaviour is
adjusted with small code filters (ask the developer):

| Filter | Purpose | Default |
|---|---|---|
| `puratek_sp_percentage` | Fee percentage | `3` |
| `puratek_sp_label` | Customer-facing label | `Shipping Protection (3%)` |
| `puratek_sp_fee_base` | Amount the % is calculated on | Cart subtotal after discounts, excl. shipping & tax |
| `puratek_sp_fee_taxable` | Charge tax on the fee | No |

**Example — change the fee to 2.5%:**

```php
add_filter( 'puratek_sp_percentage', function () { return 2.5; } );
```

**Example — include shipping in the 3% base:**

```php
add_filter( 'puratek_sp_fee_base', function ( $base, $cart ) {
    return $base + (float) $cart->get_shipping_total();
}, 10, 2 );
```

---

## FAQ

**Does it work with coupons?**
Yes. The 3% is calculated *after* discounts, so customers are never charged
protection on money they didn't spend.

**Is the fee taxed?**
Not by default. It can be switched to taxable with the
`puratek_sp_fee_taxable` filter if required.

**Where is the customer's choice stored?**
On the order itself: meta `_puratek_shipping_protection` (`yes` / `no`) and
`_puratek_shipping_protection_fee` (amount). Reporting tools can query it.

**Does it work with the block-based checkout?**
No — it is built for the classic `[woocommerce_checkout]` shortcode checkout,
which is what this site uses.

**Multisite?**
Yes. Activate it per-site; no network-wide configuration is needed.

**HPOS (High-Performance Order Storage)?**
Yes, fully compatible.

---

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Checkbox missing at checkout | Theme overrides the order review template without the `woocommerce_review_order_before_order_total` hook — check the child theme's WooCommerce templates |
| Fee doesn't update when toggling | JavaScript error from another plugin blocking the AJAX call — check the browser console |
| Two protection fees shown | Route is still active — deactivate it |
| Fee shows $0.00 | Cart subtotal is 0 (e.g. 100% coupon) — the fee hides itself automatically at 0 |

---

## Technical summary (for developers)

- Fee via `woocommerce_cart_calculate_fees` (checkout contexts only:
  checkout page, `update_order_review`, `wc-ajax=checkout`).
- Opt-in state in `WC()->session` (`puratek_shipping_protection`), default `yes`.
- Toggle endpoint: `?wc-ajax=puratek_sp_toggle` (nonce-protected).
- Checkbox rendered inside the review-order table so it survives fragment
  refreshes; JS handler is delegated to `document.body`.
- Order meta written through the `WC_Order` object (HPOS-safe) on
  `woocommerce_checkout_create_order`.

---

## Changelog

### 1.0.0 — July 2026
- Initial release: automatic 3% protection fee, opt-out checkbox with AJAX
  totals refresh, order meta record, admin order indicator.
