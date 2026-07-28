<?php
/**
 * Plugin Name:       Puratek Shipping Protection
 * Plugin URI:        https://puratekpeptides.com
 * Description:       In-house shipping protection fee, added automatically at checkout with a customer opt-out checkbox. Replaces the Route plugin.
 * Version:           1.0.0
 * Author:            Puratek
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * License:           GPL-2.0-or-later
 * Text Domain:       puratek-shipping-protection
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PURATEK_SP_VERSION', '1.0.0' );
define( 'PURATEK_SP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Default fee percentage. Filterable via 'puratek_sp_percentage'.
 */
define( 'PURATEK_SP_DEFAULT_PERCENT', 3 );

/**
 * Fee base — THE one-line switch (pending client answer, Q1):
 *   'subtotal'          => 3% of product subtotal after discounts (shipping excluded)
 *   'subtotal_shipping' => 3% of (product subtotal after discounts + shipping cost)
 */
define( 'PURATEK_SP_FEE_BASE', 'subtotal' );

class Puratek_Shipping_Protection {

	const SESSION_KEY  = 'puratek_shipping_protection';
	const NONCE_ACTION = 'puratek_sp_toggle';
	const META_OPTED   = '_puratek_shipping_protection';
	const META_FEE     = '_puratek_shipping_protection_fee';

	/** @var Puratek_Shipping_Protection|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_wc_feature_compat' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		// Checkout UI + fee.
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'render_checkbox_row' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_fee' ), 20 );

		// AJAX toggle (WC endpoint: /?wc-ajax=puratek_sp_toggle).
		add_action( 'wc_ajax_puratek_sp_toggle', array( $this, 'ajax_toggle' ) );

		// Frontend script.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Persist the choice on the order + reset session for the next order.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_meta' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'reset_session_choice' ) );

		// Small indicator for support staff on the admin order screen.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'admin_order_indicator' ) );
	}

	/**
	 * HPOS-safe (order meta is written through the WC_Order object) and
	 * classic-checkout only — the site uses the [woocommerce_checkout] shortcode.
	 */
	public function declare_wc_feature_compat() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, false );
		}
	}

	public function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>' .
			esc_html__( 'Puratek Shipping Protection requires WooCommerce to be installed and active.', 'puratek-shipping-protection' ) .
			'</p></div>';
	}

	/* ---------------------------------------------------------------------
	 * Settings (filterable)
	 * ------------------------------------------------------------------- */

	public function get_percentage() {
		return (float) apply_filters( 'puratek_sp_percentage', PURATEK_SP_DEFAULT_PERCENT );
	}

	public function get_label() {
		$label = sprintf(
			/* translators: %s: fee percentage */
			__( 'Shipping Protection (%s%%)', 'puratek-shipping-protection' ),
			wc_format_localized_decimal( $this->get_percentage() )
		);
		return apply_filters( 'puratek_sp_label', $label );
	}

	/**
	 * Whether the customer is currently opted in. Defaults to yes (checked).
	 */
	public function is_opted_in() {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return true;
		}
		return 'yes' === WC()->session->get( self::SESSION_KEY, 'yes' );
	}

	/* ---------------------------------------------------------------------
	 * Checkout UI
	 * ------------------------------------------------------------------- */

	public function render_checkbox_row() {
		?>
		<tr class="puratek-shipping-protection-row">
			<td colspan="2" style="padding-top:12px;padding-bottom:12px;">
				<label for="puratek-shipping-protection-checkbox" style="display:flex;align-items:center;gap:8px;margin:0;cursor:pointer;font-weight:600;">
					<input type="checkbox" id="puratek-shipping-protection-checkbox" <?php checked( $this->is_opted_in() ); ?> />
					<span><?php echo esc_html( $this->get_label() ); ?></span>
				</label>
				<small style="display:block;margin-top:4px;opacity:.75;font-weight:400;">
					<?php esc_html_e( 'Protects your order against loss, theft, or damage in transit.', 'puratek-shipping-protection' ); ?>
				</small>
			</td>
		</tr>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Fee calculation
	 * ------------------------------------------------------------------- */

	/**
	 * The fee only applies in checkout contexts: the checkout page itself,
	 * the update_order_review AJAX refresh, and order placement (wc-ajax=checkout).
	 */
	private function is_checkout_context() {
		if ( is_checkout() ) {
			return true;
		}
		if ( ! empty( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$endpoint = wc_clean( wp_unslash( $_GET['wc-ajax'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return in_array( $endpoint, array( 'update_order_review', 'checkout' ), true );
		}
		return false;
	}

	public function add_fee( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! $this->is_checkout_context() || ! $this->is_opted_in() || $cart->is_empty() ) {
			return;
		}

		/*
		 * Fee base: controlled by the PURATEK_SP_FEE_BASE constant at the top of
		 * this file (one-line switch, pending client confirmation of Q1).
		 * The 'puratek_sp_fee_base' filter can still override the final base.
		 */
		$base = (float) $cart->get_cart_contents_total();
		if ( 'subtotal_shipping' === PURATEK_SP_FEE_BASE ) {
			$base += (float) $cart->get_shipping_total();
		}
		$base = (float) apply_filters( 'puratek_sp_fee_base', $base, $cart );

		$fee = round( $base * ( $this->get_percentage() / 100 ), wc_get_price_decimals() );
		if ( $fee <= 0 ) {
			return;
		}

		$taxable = (bool) apply_filters( 'puratek_sp_fee_taxable', false );
		$cart->add_fee( $this->get_label(), $fee, $taxable );
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	public function ajax_toggle() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$opt_in = ( isset( $_POST['opt_in'] ) && 'yes' === $_POST['opt_in'] ) ? 'yes' : 'no';

		if ( function_exists( 'WC' ) && WC()->session ) {
			if ( ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}
			WC()->session->set( self::SESSION_KEY, $opt_in );
		}

		wp_send_json_success( array( 'opt_in' => $opt_in ) );
	}

	public function enqueue_scripts() {
		if ( ! is_checkout() || is_order_received_page() ) {
			return;
		}

		wp_enqueue_script(
			'puratek-sp-checkout',
			PURATEK_SP_URL . 'assets/js/checkout.js',
			array( 'jquery', 'wc-checkout' ),
			PURATEK_SP_VERSION,
			true
		);

		wp_localize_script(
			'puratek-sp-checkout',
			'puratekSP',
			array(
				'ajaxUrl' => WC_AJAX::get_endpoint( 'puratek_sp_toggle' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Order persistence
	 * ------------------------------------------------------------------- */

	/**
	 * The fee itself is stored as a normal WooCommerce fee line item, so it
	 * automatically appears on the admin order screen, customer invoices and
	 * all WooCommerce emails. The meta below is the explicit yes/no record.
	 *
	 * @param WC_Order $order Order being created.
	 * @param array    $data  Posted checkout data.
	 */
	public function save_order_meta( $order, $data ) {
		$opted = $this->is_opted_in();
		$order->update_meta_data( self::META_OPTED, $opted ? 'yes' : 'no' );

		if ( $opted && function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_fees() as $fee ) {
				if ( $fee->name === $this->get_label() ) {
					$order->update_meta_data( self::META_FEE, wc_format_decimal( $fee->total ) );
					break;
				}
			}
		}
	}

	/**
	 * Every new order starts opted-in again, even if the customer removed
	 * protection on a previous order.
	 */
	public function reset_session_choice() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, 'yes' );
		}
	}

	/**
	 * Yes/No badge on the admin order screen (works with and without HPOS).
	 *
	 * @param WC_Order $order Order object.
	 */
	public function admin_order_indicator( $order ) {
		$value = $order->get_meta( self::META_OPTED );
		if ( '' === $value ) {
			return; // Order predates this plugin.
		}
		printf(
			'<p class="form-field form-field-wide"><strong>%s</strong> %s</p>',
			esc_html__( 'Shipping Protection:', 'puratek-shipping-protection' ),
			'yes' === $value
				? '<span style="color:#2E7D4F;font-weight:600;">' . esc_html__( 'Yes', 'puratek-shipping-protection' ) . '</span>'
				: '<span style="color:#A8690E;font-weight:600;">' . esc_html__( 'No (customer removed)', 'puratek-shipping-protection' ) . '</span>'
		);
	}
}

Puratek_Shipping_Protection::instance();
