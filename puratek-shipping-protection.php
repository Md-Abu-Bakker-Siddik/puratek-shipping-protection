<?php
/**
 * Plugin Name:       Puratek Shipping Protection
 * Plugin URI:        https://puratekpeptides.com
 * Description:       In-house shipping protection fee, added automatically at checkout with a customer opt-out checkbox. Replaces the Route plugin.
 * Version:           1.2.2
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

define( 'PURATEK_SP_VERSION', '1.2.2' );
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
	const OPTION_KEY   = 'puratek_sp_settings';

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
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'sync_choice_from_checkout' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'sync_choice_from_checkout_post' ) );

		// AJAX toggle (WC endpoint: /?wc-ajax=puratek_sp_toggle).
		add_action( 'wc_ajax_puratek_sp_toggle', array( $this, 'ajax_toggle' ) );

		// Frontend assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Persist the choice on the order + reset session for the next order.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_meta' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'reset_session_choice' ) );

		// Small indicator for support staff on the admin order screen.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'admin_order_indicator' ) );

		// WooCommerce admin settings.
		add_action( 'admin_menu', array( $this, 'register_admin_page' ), 60 );
		add_action( 'admin_post_puratek_sp_save_settings', array( $this, 'save_admin_settings' ) );
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

	private function get_default_settings() {
		return array(
			'percentage'     => PURATEK_SP_DEFAULT_PERCENT,
			'default_opt_in' => 'yes',
			'template'       => 'compact',
			'accent_color'   => '#f7941d',
			'brand_name'     => 'Puratek',
			'widget_title'    => __( 'Shipping Protection', 'puratek-shipping-protection' ),
			'widget_text'     => __( 'Protect your order from loss, theft, or damage in transit.', 'puratek-shipping-protection' ),
			'modal_title'     => __( 'Shipping Protection', 'puratek-shipping-protection' ),
			'modal_subtitle'  => __( 'Optional coverage from the time your order leaves our facility until delivery.', 'puratek-shipping-protection' ),
			'claim_url'       => '',
			'privacy_url'     => '',
			'terms_url'       => '',
		);
	}

	public function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $settings ) ? $settings : array(), $this->get_default_settings() );
	}

	public function get_percentage() {
		$settings   = $this->get_settings();
		$percentage = (float) apply_filters( 'puratek_sp_percentage', $settings['percentage'] );
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
		$settings = $this->get_settings();
		$brand    = apply_filters( 'puratek_sp_brand_name', $settings['brand_name'] );
		$brand = is_string( $brand ) ? sanitize_text_field( $brand ) : 'Puratek';

		return '' !== $brand ? $brand : 'Puratek';
	}

	/**
	 * Whether the customer is currently opted in. Defaults to yes (checked).
	 */
	public function is_opted_in() {
		$settings = $this->get_settings();
		$default  = 'no' === $settings['default_opt_in'] ? 'no' : 'yes';
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return 'yes' === $default;
		}
		return 'yes' === WC()->session->get( self::SESSION_KEY, $default );
	}

	private function get_template() {
		$settings = $this->get_settings();
		return in_array( $settings['template'], array( 'compact', 'shield_card', 'toggle_banner' ), true ) ? $settings['template'] : 'compact';
	}

	/* ---------------------------------------------------------------------
	 * Checkout UI
	 * ------------------------------------------------------------------- */

	public function render_checkbox_row() {
		$settings     = $this->get_settings();
		$brand_name  = $this->get_brand_name();
		$fee_amount  = $this->calculate_fee( function_exists( 'WC' ) ? WC()->cart : null );
		$template    = $this->get_template();
		$accent      = sanitize_hex_color( $settings['accent_color'] );
		$accent      = $accent ? $accent : '#f7941d';
		?>
		<tr class="puratek-sp-row puratek-sp-row--<?php echo esc_attr( $template ); ?>" style="--puratek-sp-accent: <?php echo esc_attr( $accent ); ?>;">
			<td colspan="2">
				<div class="puratek-sp-widget puratek-sp-widget--<?php echo esc_attr( $template ); ?>" style="--puratek-sp-accent: <?php echo esc_attr( $accent ); ?>;">
					<div class="puratek-sp-widget__main">
						<label class="puratek-sp-widget__checkbox-wrap" for="puratek-shipping-protection-checkbox">
							<input type="hidden" name="puratek_shipping_protection" value="no" />
							<input
								type="checkbox"
								name="puratek_shipping_protection"
								value="yes"
								class="puratek-sp-widget__checkbox"
								id="puratek-shipping-protection-checkbox"
								<?php checked( $this->is_opted_in() ); ?>
							/>
							<span class="puratek-sp-sr-only"><?php esc_html_e( 'Enable Shipping Protection', 'puratek-shipping-protection' ); ?></span>
						</label>

						<div class="puratek-sp-widget__content">
							<div class="puratek-sp-widget__heading">
								<?php if ( 'shield_card' === $template ) : ?>
									<span class="puratek-sp-widget__shield" aria-hidden="true">&#10003;</span>
								<?php endif; ?>
								<button
									type="button"
									class="puratek-sp-widget__title puratek-sp-modal-trigger"
									aria-haspopup="dialog"
									aria-controls="puratek-sp-information-modal"
								>
									<?php echo esc_html( $settings['widget_title'] ); ?>
								</button>
								<?php if ( 'compact' === $template ) : ?>
								<span class="puratek-sp-widget__brand">
									<?php
									printf(
										/* translators: %s: brand name */
										esc_html__( 'by %s', 'puratek-shipping-protection' ),
										esc_html( $brand_name )
									);
									?>
								</span>
								<?php endif; ?>
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
							<?php if ( 'compact' !== $template ) : ?>
								<p class="puratek-sp-widget__description"><?php echo esc_html( $settings['widget_text'] ); ?></p>
							<?php endif; ?>
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
		$settings    = $this->get_settings();
		$brand_name  = $this->get_brand_name();
		$logo_url    = apply_filters( 'puratek_sp_brand_logo_url', '' );
		$logo_url    = is_string( $logo_url ) ? esc_url( $logo_url ) : '';
		$claim_url   = $settings['claim_url'];
		$claim_url   = apply_filters( 'puratek_sp_claim_url', $claim_url );
		$privacy_url = $settings['privacy_url'];
		$privacy_url = apply_filters( 'puratek_sp_privacy_url', $privacy_url );
		$terms_url   = $settings['terms_url'];
		$terms_url   = apply_filters( 'puratek_sp_terms_url', $terms_url );
		?>
		<div class="puratek-sp-modal" id="puratek-sp-information-modal" style="--puratek-sp-accent: <?php echo esc_attr( sanitize_hex_color( $settings['accent_color'] ) ?: '#f7941d' ); ?>;" hidden>
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
					<p class="puratek-sp-modal__eyebrow"><?php esc_html_e( 'PURATEK PACKAGE PROTECTION', 'puratek-shipping-protection' ); ?></p>
					<h2 class="puratek-sp-modal__title" id="puratek-sp-modal-title"><?php echo esc_html( $settings['modal_title'] ); ?></h2>
					<p class="puratek-sp-modal__subtitle" id="puratek-sp-modal-subtitle">
						<?php echo esc_html( $settings['modal_subtitle'] ); ?>
					</p>

					<ul class="puratek-sp-modal__benefits">
						<li class="puratek-sp-modal__benefit">
							<span class="puratek-sp-modal__benefit-icon" aria-hidden="true">&#10003;</span>
							<div><strong><?php esc_html_e( 'What is covered', 'puratek-shipping-protection' ); ?></strong><span><?php esc_html_e( 'Packages lost, missing, stolen, or physically damaged in transit, plus verified fulfillment errors.', 'puratek-shipping-protection' ); ?></span></div>
						</li>
						<li class="puratek-sp-modal__benefit">
							<span class="puratek-sp-modal__benefit-icon" aria-hidden="true">!</span>
							<div><strong><?php esc_html_e( 'Important exclusions', 'puratek-shipping-protection' ); ?></strong><span><?php esc_html_e( 'Incorrect addresses, refused or unclaimed packages, carrier delays, customs or regulatory action, and late claims are not covered.', 'puratek-shipping-protection' ); ?></span></div>
						</li>
						<li class="puratek-sp-modal__benefit">
							<span class="puratek-sp-modal__benefit-icon" aria-hidden="true">10</span>
							<div><strong><?php esc_html_e( 'Report within 10 days', 'puratek-shipping-protection' ); ?></strong><span><?php esc_html_e( 'Claims should be reported within 10 calendar days of delivery, attempted delivery, or the last tracking update.', 'puratek-shipping-protection' ); ?></span></div>
						</li>
					</ul>
				</div>

				<footer class="puratek-sp-modal__footer">
					<p class="puratek-sp-modal__disclaimer">
						<?php esc_html_e( 'Shipping Protection is optional and subject to coverage eligibility, exclusions, and the applicable Terms of Service. Claims may require supporting information during review.', 'puratek-shipping-protection' ); ?>
					</p>
					<?php if ( $claim_url || $privacy_url || $terms_url ) : ?>
						<nav class="puratek-sp-modal__links" aria-label="<?php esc_attr_e( 'Shipping Protection resources', 'puratek-shipping-protection' ); ?>">
							<?php if ( $claim_url ) : ?><a href="<?php echo esc_url( $claim_url ); ?>"><?php esc_html_e( 'File A Claim', 'puratek-shipping-protection' ); ?></a><?php endif; ?>
							<?php if ( $privacy_url ) : ?><a href="<?php echo esc_url( $privacy_url ); ?>"><?php esc_html_e( 'Privacy Policy', 'puratek-shipping-protection' ); ?></a><?php endif; ?>
							<?php if ( $terms_url ) : ?><a href="<?php echo esc_url( $terms_url ); ?>"><?php esc_html_e( 'Full Terms & Coverage', 'puratek-shipping-protection' ); ?></a><?php endif; ?>
						</nav>
					<?php endif; ?>
				</footer>
			</div>
		</div>
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

	/**
	 * Keep the session authoritative when WooCommerce serializes checkout fields.
	 * The paired hidden field means both checked and unchecked states are present.
	 */
	public function sync_choice_from_checkout( $post_data ) {
		parse_str( (string) $post_data, $data );
		if ( isset( $data['puratek_shipping_protection'] ) ) {
			$this->set_session_choice( $data['puratek_shipping_protection'] );
		}
	}

	public function sync_choice_from_checkout_post() {
		if ( isset( $_POST['puratek_shipping_protection'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce validates checkout requests.
			$this->set_session_choice( wp_unslash( $_POST['puratek_shipping_protection'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
	}

	private function set_session_choice( $value ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, 'yes' === wc_clean( $value ) ? 'yes' : 'no' );
		}
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
		$protected = false;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_fees() as $fee ) {
				if ( $fee->name === $this->get_label() ) {
					$protected = true;
					$order->update_meta_data( self::META_FEE, wc_format_decimal( $fee->total ) );
					break;
				}
			}
		}
		$order->update_meta_data( self::META_OPTED, $protected ? 'yes' : 'no' );
	}

	/**
	 * Every new order starts opted-in again, even if the customer removed
	 * protection on a previous order.
	 */
	public function reset_session_choice() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			$settings = $this->get_settings();
			WC()->session->set( self::SESSION_KEY, 'no' === $settings['default_opt_in'] ? 'no' : 'yes' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Admin settings
	 * ------------------------------------------------------------------- */

	public function register_admin_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Shipping Protection', 'puratek-shipping-protection' ),
			__( 'Shipping Protection', 'puratek-shipping-protection' ),
			'manage_woocommerce',
			'puratek-shipping-protection',
			array( $this, 'render_admin_page' )
		);
	}

	public function save_admin_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'puratek-shipping-protection' ) );
		}
		check_admin_referer( 'puratek_sp_save_settings' );

		$input     = isset( $_POST['puratek_sp'] ) && is_array( $_POST['puratek_sp'] ) ? wp_unslash( $_POST['puratek_sp'] ) : array();
		$defaults  = $this->get_default_settings();
		$template  = isset( $input['template'] ) ? sanitize_key( $input['template'] ) : $defaults['template'];
		$percentage = isset( $input['percentage'] ) ? (float) $input['percentage'] : $defaults['percentage'];
		$accent     = isset( $input['accent_color'] ) ? sanitize_hex_color( $input['accent_color'] ) : '';

		$settings = array(
			'percentage'     => max( 0, min( 100, $percentage ) ),
			'default_opt_in' => isset( $input['default_opt_in'] ) && 'no' === $input['default_opt_in'] ? 'no' : 'yes',
			'template'       => in_array( $template, array( 'compact', 'shield_card', 'toggle_banner' ), true ) ? $template : $defaults['template'],
			'accent_color'   => $accent ? $accent : $defaults['accent_color'],
			'brand_name'     => isset( $input['brand_name'] ) ? sanitize_text_field( $input['brand_name'] ) : $defaults['brand_name'],
			'widget_title'    => isset( $input['widget_title'] ) ? sanitize_text_field( $input['widget_title'] ) : $defaults['widget_title'],
			'widget_text'     => isset( $input['widget_text'] ) ? sanitize_textarea_field( $input['widget_text'] ) : $defaults['widget_text'],
			'modal_title'     => isset( $input['modal_title'] ) ? sanitize_text_field( $input['modal_title'] ) : $defaults['modal_title'],
			'modal_subtitle'  => isset( $input['modal_subtitle'] ) ? sanitize_textarea_field( $input['modal_subtitle'] ) : $defaults['modal_subtitle'],
			'claim_url'       => isset( $input['claim_url'] ) ? esc_url_raw( $input['claim_url'], array( 'http', 'https' ) ) : '',
			'privacy_url'     => isset( $input['privacy_url'] ) ? esc_url_raw( $input['privacy_url'], array( 'http', 'https' ) ) : '',
			'terms_url'       => isset( $input['terms_url'] ) ? esc_url_raw( $input['terms_url'], array( 'http', 'https' ) ) : '',
		);

		update_option( self::OPTION_KEY, $settings, false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'puratek-shipping-protection', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$settings  = $this->get_settings();
		$templates = array(
			'compact'       => array( __( 'Compact', 'puratek-shipping-protection' ), __( 'The original minimal checkout row.', 'puratek-shipping-protection' ) ),
			'shield_card'   => array( __( 'Shield Card', 'puratek-shipping-protection' ), __( 'A reassuring bordered card with a shield.', 'puratek-shipping-protection' ) ),
			'toggle_banner' => array( __( 'Toggle Banner', 'puratek-shipping-protection' ), __( 'A modern highlighted panel with a switch.', 'puratek-shipping-protection' ) ),
		);
		?>
		<div class="wrap puratek-sp-admin">
			<h1><?php esc_html_e( 'Shipping Protection', 'puratek-shipping-protection' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Control the fee, checkout presentation, popup copy, and policy links.', 'puratek-shipping-protection' ); ?></p>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Shipping Protection settings saved.', 'puratek-shipping-protection' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="puratek_sp_save_settings" />
				<?php wp_nonce_field( 'puratek_sp_save_settings' ); ?>
				<div class="puratek-sp-admin__grid">
					<section class="puratek-sp-admin__card">
						<h2><?php esc_html_e( 'Fee settings', 'puratek-shipping-protection' ); ?></h2>
						<label><?php esc_html_e( 'Protection percentage', 'puratek-shipping-protection' ); ?><input type="number" min="0" max="100" step="0.01" name="puratek_sp[percentage]" value="<?php echo esc_attr( $settings['percentage'] ); ?>" /></label>
						<label><?php esc_html_e( 'Default checkout state', 'puratek-shipping-protection' ); ?><select name="puratek_sp[default_opt_in]"><option value="yes" <?php selected( $settings['default_opt_in'], 'yes' ); ?>><?php esc_html_e( 'Enabled', 'puratek-shipping-protection' ); ?></option><option value="no" <?php selected( $settings['default_opt_in'], 'no' ); ?>><?php esc_html_e( 'Disabled', 'puratek-shipping-protection' ); ?></option></select></label>
						<label><?php esc_html_e( 'Accent color', 'puratek-shipping-protection' ); ?><input type="color" name="puratek_sp[accent_color]" value="<?php echo esc_attr( $settings['accent_color'] ); ?>" /></label>
						<label><?php esc_html_e( 'Brand name', 'puratek-shipping-protection' ); ?><input type="text" name="puratek_sp[brand_name]" value="<?php echo esc_attr( $settings['brand_name'] ); ?>" /></label>
					</section>

					<section class="puratek-sp-admin__card">
						<h2><?php esc_html_e( 'Customer-facing text', 'puratek-shipping-protection' ); ?></h2>
						<label><?php esc_html_e( 'Widget title', 'puratek-shipping-protection' ); ?><input type="text" name="puratek_sp[widget_title]" value="<?php echo esc_attr( $settings['widget_title'] ); ?>" /></label>
						<label><?php esc_html_e( 'Widget description', 'puratek-shipping-protection' ); ?><textarea name="puratek_sp[widget_text]" rows="3"><?php echo esc_textarea( $settings['widget_text'] ); ?></textarea></label>
						<label><?php esc_html_e( 'Popup title', 'puratek-shipping-protection' ); ?><input type="text" name="puratek_sp[modal_title]" value="<?php echo esc_attr( $settings['modal_title'] ); ?>" /></label>
						<label><?php esc_html_e( 'Popup introduction', 'puratek-shipping-protection' ); ?><textarea name="puratek_sp[modal_subtitle]" rows="3"><?php echo esc_textarea( $settings['modal_subtitle'] ); ?></textarea></label>
					</section>

					<section class="puratek-sp-admin__card puratek-sp-admin__card--wide">
						<h2><?php esc_html_e( 'Checkout design', 'puratek-shipping-protection' ); ?></h2>
						<div class="puratek-sp-admin__templates">
							<?php foreach ( $templates as $key => $template ) : ?>
								<label class="puratek-sp-admin__template"><input type="radio" name="puratek_sp[template]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $settings['template'], $key ); ?> /><span class="puratek-sp-admin__template-preview puratek-sp-admin__template-preview--<?php echo esc_attr( $key ); ?>"><i></i><b><?php echo esc_html( $settings['widget_title'] ); ?></b><em><?php echo esc_html( wc_format_localized_decimal( $settings['percentage'] ) ); ?>%</em><small><?php echo esc_html( $template[1] ); ?></small></span><strong><?php echo esc_html( $template[0] ); ?></strong></label>
							<?php endforeach; ?>
						</div>
					</section>

					<section class="puratek-sp-admin__card puratek-sp-admin__card--wide">
						<h2><?php esc_html_e( 'Popup links', 'puratek-shipping-protection' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Links are shown in the popup only when a URL is entered here.', 'puratek-shipping-protection' ); ?></p>
						<div class="puratek-sp-admin__links">
							<label><?php esc_html_e( 'Claim page URL', 'puratek-shipping-protection' ); ?><input type="url" name="puratek_sp[claim_url]" value="<?php echo esc_attr( $settings['claim_url'] ); ?>" placeholder="https://" /></label>
							<label><?php esc_html_e( 'Privacy page URL', 'puratek-shipping-protection' ); ?><input type="url" name="puratek_sp[privacy_url]" value="<?php echo esc_attr( $settings['privacy_url'] ); ?>" placeholder="https://" /></label>
							<label><?php esc_html_e( 'Terms page URL', 'puratek-shipping-protection' ); ?><input type="url" name="puratek_sp[terms_url]" value="<?php echo esc_attr( $settings['terms_url'] ); ?>" placeholder="https://" /></label>
						</div>
					</section>
				</div>
				<?php submit_button( __( 'Save Shipping Protection', 'puratek-shipping-protection' ) ); ?>
			</form>
		</div>
		<style>
			.puratek-sp-admin{max-width:1120px}.puratek-sp-admin__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-top:22px}.puratek-sp-admin__card{padding:22px;border:1px solid #dcdcde;border-radius:12px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04)}.puratek-sp-admin__card--wide{grid-column:1/-1}.puratek-sp-admin__card h2{margin:0 0 18px}.puratek-sp-admin__card>label,.puratek-sp-admin__links label{display:grid;gap:7px;margin:0 0 16px;font-weight:600}.puratek-sp-admin input[type=text],.puratek-sp-admin input[type=url],.puratek-sp-admin input[type=number],.puratek-sp-admin textarea,.puratek-sp-admin select{width:100%;max-width:none}.puratek-sp-admin input[type=color]{width:64px;height:38px;padding:3px}.puratek-sp-admin__templates{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.puratek-sp-admin__template{display:grid;gap:9px}.puratek-sp-admin__template>input{position:absolute;opacity:0}.puratek-sp-admin__template-preview{display:grid;grid-template-columns:34px 1fr auto;align-items:center;gap:9px;min-height:88px;padding:15px;border:2px solid #dcdcde;border-radius:12px;background:#fff}.puratek-sp-admin__template input:checked+.puratek-sp-admin__template-preview{border-color:#2271b1;box-shadow:0 0 0 2px rgba(34,113,177,.12)}.puratek-sp-admin__template input:focus-visible+.puratek-sp-admin__template-preview{outline:2px solid #2271b1;outline-offset:2px}.puratek-sp-admin__template-preview i{width:24px;height:24px;border:3px solid #2271b1;border-radius:6px}.puratek-sp-admin__template-preview em{font-style:normal;font-weight:700}.puratek-sp-admin__template-preview small{grid-column:2/-1;color:#646970}.puratek-sp-admin__template-preview--shield_card{background:#fafafa}.puratek-sp-admin__template-preview--shield_card i{border-radius:50%}.puratek-sp-admin__template-preview--toggle_banner{background:#edf3ff;border-color:#a9bfe9}.puratek-sp-admin__template-preview--toggle_banner i{width:34px;height:20px;border:0;border-radius:99px;background:#135e96}.puratek-sp-admin__links{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:18px}@media(max-width:782px){.puratek-sp-admin__grid,.puratek-sp-admin__templates,.puratek-sp-admin__links{grid-template-columns:1fr}.puratek-sp-admin__card--wide{grid-column:auto}}
		</style>
		<?php
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
