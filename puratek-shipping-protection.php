<?php
/**
 * Plugin Name:       Puratek Shipping Protection
 * Plugin URI:        https://puratekpeptides.com
 * Description:       In-house shipping protection fee, added automatically at checkout with a customer opt-out checkbox. Replaces the Route plugin.
 * Version:           1.1.0
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

define( 'PURATEK_SP_VERSION', '1.1.0' );
define( 'PURATEK_SP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Default fee percentage. Filterable via 'puratek_sp_percentage'.
 */
define( 'PURATEK_SP_DEFAULT_PERCENT', 3 );

/**
 * Fee base:
 *   'subtotal'          => 3% of product subtotal after discounts (shipping excluded)
 *   'subtotal_shipping' => 3% of (product subtotal after discounts + shipping cost)
 *
 * Puratek's configured policy is 'subtotal': discounted merchandise only,
 * excluding shipping and tax.
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
		add_action( 'woocommerce_review_order_after_order_total', array( $this, 'render_checkbox_row' ) );
		add_action( 'woocommerce_after_checkout_form', array( $this, 'render_modal' ), 20 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_fee' ), 20 );

		// AJAX toggle (WC endpoint: /?wc-ajax=puratek_sp_toggle).
		add_action( 'wc_ajax_puratek_sp_toggle', array( $this, 'ajax_toggle' ) );

		// Frontend assets.
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
		$percentage = (float) apply_filters( 'puratek_sp_percentage', PURATEK_SP_DEFAULT_PERCENT );
		return max( 0, min( 100, $percentage ) );
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
	 * Brand name displayed in the widget and information modal.
	 *
	 * @return string
	 */
	public function get_brand_name() {
		$brand = apply_filters( 'puratek_sp_brand_name', 'Puratek' );
		$brand = is_string( $brand ) ? sanitize_text_field( $brand ) : 'Puratek';

		return '' !== $brand ? $brand : 'Puratek';
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
		$brand_name = $this->get_brand_name();
		$fee_amount = $this->calculate_fee( function_exists( 'WC' ) ? WC()->cart : null );
		?>
		<tr class="puratek-sp-row">
			<td colspan="2">
				<div class="puratek-sp-widget">
					<div class="puratek-sp-widget__main">
						<label class="puratek-sp-widget__checkbox-wrap" for="puratek-shipping-protection-checkbox">
							<input
								type="checkbox"
								class="puratek-sp-widget__checkbox"
								id="puratek-shipping-protection-checkbox"
								<?php checked( $this->is_opted_in() ); ?>
							/>
							<span class="puratek-sp-sr-only"><?php esc_html_e( 'Enable Shipping Protection', 'puratek-shipping-protection' ); ?></span>
						</label>

						<div class="puratek-sp-widget__content">
							<div class="puratek-sp-widget__heading">
								<button
									type="button"
									class="puratek-sp-widget__title puratek-sp-modal-trigger"
									aria-haspopup="dialog"
									aria-controls="puratek-sp-information-modal"
								>
									<?php esc_html_e( 'Shipping Protection', 'puratek-shipping-protection' ); ?>
								</button>
								<span class="puratek-sp-widget__brand">
									<?php
									printf(
										/* translators: %s: brand name */
										esc_html__( 'by %s', 'puratek-shipping-protection' ),
										esc_html( $brand_name )
									);
									?>
								</span>
								<button
									type="button"
									class="puratek-sp-widget__info puratek-sp-modal-trigger"
									aria-label="<?php esc_attr_e( 'Learn more about Shipping Protection', 'puratek-shipping-protection' ); ?>"
									aria-haspopup="dialog"
									aria-controls="puratek-sp-information-modal"
								>
									<svg class="puratek-sp-widget__info-icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
										<circle cx="8" cy="8" r="6.35" fill="none" stroke="currentColor" stroke-width="1.3" />
										<path d="M8 7.1v4.15" fill="none" stroke="currentColor" stroke-width="1.45" stroke-linecap="round" />
										<circle cx="8" cy="4.75" r="0.85" fill="currentColor" />
									</svg>
								</button>
							</div>
						</div>

						<span class="puratek-sp-widget__amount" aria-label="<?php esc_attr_e( 'Shipping Protection fee', 'puratek-shipping-protection' ); ?>">
							<?php echo wp_kses_post( wc_price( $fee_amount ) ); ?>
						</span>
					</div>
					<p class="puratek-sp-widget__status puratek-sp-sr-only" aria-live="polite" aria-atomic="true"></p>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the accessible information modal outside the order-review table.
	 */
	public function render_modal() {
		$brand_name  = $this->get_brand_name();
		$logo_url    = apply_filters( 'puratek_sp_brand_logo_url', '' );
		$logo_url    = is_string( $logo_url ) ? esc_url( $logo_url ) : '';
		$claim_url   = $this->find_published_page_url( array( 'file-a-claim', 'claims', 'contact-us', 'contact' ) );
		$claim_url   = $claim_url ? $claim_url : home_url( '/' );
		$claim_url   = apply_filters( 'puratek_sp_claim_url', $claim_url );
		$privacy_url = $this->find_published_page_url( array( 'privacy-policy', 'privacy' ) );
		if ( ! $privacy_url && function_exists( 'get_privacy_policy_url' ) ) {
			$privacy_url = get_privacy_policy_url();
		}
		$privacy_url = $privacy_url ? $privacy_url : home_url( '/' );
		$privacy_url = apply_filters( 'puratek_sp_privacy_url', $privacy_url );
		$terms_url   = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'terms' ) : '';
		$terms_url   = $terms_url ? $terms_url : home_url( '/terms-and-conditions/' );
		$terms_url   = apply_filters( 'puratek_sp_terms_url', $terms_url );
		?>
		<div class="puratek-sp-modal" id="puratek-sp-information-modal" hidden>
			<div
				class="puratek-sp-modal__dialog"
				role="dialog"
				aria-modal="true"
				aria-labelledby="puratek-sp-modal-title"
				aria-describedby="puratek-sp-modal-subtitle"
				tabindex="-1"
			>
				<header class="puratek-sp-modal__header">
					<div class="puratek-sp-modal__powered-by">
						<?php if ( $logo_url ) : ?>
							<img class="puratek-sp-modal__logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $brand_name ); ?>" />
						<?php else : ?>
							<span class="puratek-sp-modal__brand-mark" aria-hidden="true"><?php echo esc_html( strtoupper( substr( $brand_name, 0, 1 ) ) ); ?></span>
						<?php endif; ?>
						<span>
							<?php
							printf(
								/* translators: %s: brand name */
								esc_html__( 'Powered by %s', 'puratek-shipping-protection' ),
								esc_html( $brand_name )
							);
							?>
						</span>
					</div>
					<button type="button" class="puratek-sp-modal__close" aria-label="<?php esc_attr_e( 'Close Shipping Protection information', 'puratek-shipping-protection' ); ?>">
						<span aria-hidden="true">&#10005;</span>
					</button>
				</header>

				<div class="puratek-sp-modal__body">
					<h2 class="puratek-sp-modal__title" id="puratek-sp-modal-title"><?php esc_html_e( 'We\'ve got you covered.', 'puratek-shipping-protection' ); ?></h2>
					<p class="puratek-sp-modal__subtitle" id="puratek-sp-modal-subtitle">
						<?php esc_html_e( 'Shipping Protection gives you peace of mind while saving you time and money.', 'puratek-shipping-protection' ); ?>
					</p>

					<ul class="puratek-sp-modal__benefits">
						<li class="puratek-sp-modal__benefit">
							<span class="puratek-sp-modal__benefit-icon" aria-hidden="true">&#10003;</span>
							<div><strong><?php esc_html_e( 'Shipping Protection', 'puratek-shipping-protection' ); ?></strong><span><?php esc_html_e( 'Coverage in case your package is damaged in transit, stolen, or doesn\'t arrive.', 'puratek-shipping-protection' ); ?></span></div>
						</li>
						<li class="puratek-sp-modal__benefit">
							<span class="puratek-sp-modal__benefit-icon" aria-hidden="true">&#9889;</span>
							<div><strong><?php esc_html_e( 'Instant Issue Resolution', 'puratek-shipping-protection' ); ?></strong><span><?php esc_html_e( 'Get a refund or replacement in just a few clicks.', 'puratek-shipping-protection' ); ?></span></div>
						</li>
						<li class="puratek-sp-modal__benefit">
							<span class="puratek-sp-modal__benefit-icon" aria-hidden="true">24/7</span>
							<div><strong><?php esc_html_e( '24/7 Claim Support', 'puratek-shipping-protection' ); ?></strong><span><?php esc_html_e( 'We are here for you whenever an issue arises.', 'puratek-shipping-protection' ); ?></span></div>
						</li>
					</ul>
				</div>

				<footer class="puratek-sp-modal__footer">
					<p class="puratek-sp-modal__disclaimer">
						<?php esc_html_e( 'Shipping Protection is optional and subject to coverage eligibility, exclusions, and the applicable Terms of Service. Claims may require supporting information during review.', 'puratek-shipping-protection' ); ?>
					</p>
					<nav class="puratek-sp-modal__links" aria-label="<?php esc_attr_e( 'Shipping Protection resources', 'puratek-shipping-protection' ); ?>">
						<a href="<?php echo esc_url( $claim_url ); ?>"><?php esc_html_e( 'File A Claim', 'puratek-shipping-protection' ); ?></a>
						<a href="<?php echo esc_url( $privacy_url ); ?>"><?php esc_html_e( 'Privacy Policy', 'puratek-shipping-protection' ); ?></a>
						<a href="<?php echo esc_url( $terms_url ); ?>"><?php esc_html_e( 'Terms of Service', 'puratek-shipping-protection' ); ?></a>
					</nav>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Find the first published page matching one of the preferred slugs.
	 *
	 * @param string[] $slugs Preferred page slugs in priority order.
	 * @return string Published permalink, or an empty string.
	 */
	private function find_published_page_url( $slugs ) {
		foreach ( $slugs as $slug ) {
			$page = get_page_by_path( sanitize_title( $slug ), OBJECT, 'page' );
			if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
				$url = get_permalink( $page );
				if ( is_string( $url ) && '' !== $url ) {
					return $url;
				}
			}
		}

		return '';
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

		$fee = $this->calculate_fee( $cart );
		if ( $fee <= 0 ) {
			return;
		}

		$taxable = (bool) apply_filters( 'puratek_sp_fee_taxable', false );
		$cart->add_fee( $this->get_label(), $fee, $taxable );
	}

	/**
	 * Calculate the fee once for both the WooCommerce fee and widget amount.
	 *
	 * @param WC_Cart|null $cart WooCommerce cart.
	 * @return float
	 */
	private function calculate_fee( $cart ) {
		if ( ! $cart || ! is_object( $cart ) || ! method_exists( $cart, 'get_cart_contents_total' ) ) {
			return 0.0;
		}

		$base = (float) $cart->get_cart_contents_total();
		if ( 'subtotal_shipping' === PURATEK_SP_FEE_BASE && method_exists( $cart, 'get_shipping_total' ) ) {
			$base += (float) $cart->get_shipping_total();
		}
		$base = max( 0, (float) apply_filters( 'puratek_sp_fee_base', $base, $cart ) );

		return round( $base * ( $this->get_percentage() / 100 ), wc_get_price_decimals() );
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	public function ajax_toggle() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$opt_in = ( isset( $_POST['opt_in'] ) && 'yes' === wc_clean( wp_unslash( $_POST['opt_in'] ) ) ) ? 'yes' : 'no';

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			wp_send_json_error( array( 'message' => __( 'Your checkout session expired. Please refresh the page and try again.', 'puratek-shipping-protection' ) ), 409 );
		}

		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		WC()->session->set( self::SESSION_KEY, $opt_in );

		wp_send_json_success( array( 'opt_in' => $opt_in ) );
	}

	public function enqueue_scripts() {
		if ( ! is_checkout() || is_order_received_page() ) {
			return;
		}

		wp_enqueue_style(
			'puratek-sp-checkout',
			PURATEK_SP_URL . 'assets/css/checkout.css',
			array(),
			PURATEK_SP_VERSION
		);

		wp_enqueue_script(
			'puratek-sp-checkout',
			PURATEK_SP_URL . 'assets/js/checkout.js',
			array( 'wc-checkout' ),
			PURATEK_SP_VERSION,
			true
		);

		wp_localize_script(
			'puratek-sp-checkout',
			'puratekSP',
			array(
				'ajaxUrl'      => WC_AJAX::get_endpoint( 'puratek_sp_toggle' ),
				'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
				'enabledText'  => __( 'Shipping Protection added. Updating your total.', 'puratek-shipping-protection' ),
				'disabledText' => __( 'Shipping Protection removed. Updating your total.', 'puratek-shipping-protection' ),
				'errorText'    => __( 'Shipping Protection could not be updated. Please try again.', 'puratek-shipping-protection' ),
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
