<?php
/**
 * Platform adapters: WooCommerce, Easy Digital Downloads and the
 * SureCart / FluentCart webhook bridge.
 *
 * @package UTM_Coupons
 */

namespace UTM_Coupons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Platforms {

	/**
	 * Register every integration hook.
	 */
	public static function init() {
		// WooCommerce.
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'woo_order_paid' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'woo_order_paid' ), 10, 1 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'woo_order_refunded' ), 10, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'woo_order_refunded' ), 10, 1 );

		// Easy Digital Downloads.
		add_action( 'edd_complete_purchase', array( __CLASS__, 'edd_purchase_paid' ), 10, 1 );
		add_action( 'edd_payment_status_refunded', array( __CLASS__, 'edd_purchase_refunded' ), 10, 1 );

		// SureCart / FluentCart webhook bridge.
		add_action( 'rest_api_init', array( __CLASS__, 'register_bridge' ) );
	}

	/**
	 * Detect which supported platforms are installed & active.
	 *
	 * @return array<string,bool>
	 */
	public static function detect() {
		return array(
			'woocommerce' => class_exists( 'WooCommerce' ) || function_exists( 'WC' ),
			'edd'         => class_exists( 'Easy_Digital_Downloads' ) || function_exists( 'EDD' ),
			'surecart'    => class_exists( 'SureCart' ) || defined( 'SURECART_VERSION' ),
			'fluentcart'  => class_exists( 'FluentCart' ) || defined( 'FLUENT_CART_VERSION' ) || function_exists( 'fluentCart' ),
		);
	}

	// ---------------------------------------------------------------------
	// WooCommerce.
	// ---------------------------------------------------------------------

	public static function woo_order_paid( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$codes = $order->get_coupon_codes();
		if ( empty( $codes ) ) {
			return; // No-coupon guard: nothing to attribute.
		}
		Reporter::report(
			(string) $order->get_order_number(),
			(float) $order->get_total(),
			(string) $order->get_currency(),
			strtoupper( (string) $codes[0] ),
			'paid'
		);
	}

	public static function woo_order_refunded( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$codes = $order->get_coupon_codes();
		if ( empty( $codes ) ) {
			return;
		}
		Reporter::report(
			(string) $order->get_order_number(),
			(float) $order->get_total(),
			(string) $order->get_currency(),
			strtoupper( (string) $codes[0] ),
			'refunded'
		);
	}

	// ---------------------------------------------------------------------
	// Easy Digital Downloads.
	// ---------------------------------------------------------------------

	public static function edd_purchase_paid( $payment_id ) {
		if ( ! function_exists( 'edd_get_payment' ) ) {
			return;
		}
		$payment = edd_get_payment( $payment_id );
		if ( ! $payment ) {
			return;
		}
		$codes = (array) ( $payment->discounts ?? array() );
		$codes = array_filter( $codes );
		if ( empty( $codes ) ) {
			return;
		}
		Reporter::report(
			(string) $payment->number,
			(float) $payment->total,
			(string) $payment->currency,
			strtoupper( (string) reset( $codes ) ),
			'paid'
		);
	}

	public static function edd_purchase_refunded( $payment_id ) {
		if ( ! function_exists( 'edd_get_payment' ) ) {
			return;
		}
		$payment = edd_get_payment( $payment_id );
		if ( ! $payment ) {
			return;
		}
		$codes = (array) ( $payment->discounts ?? array() );
		$codes = array_filter( $codes );
		if ( empty( $codes ) ) {
			return;
		}
		Reporter::report(
			(string) $payment->number,
			(float) $payment->total,
			(string) $payment->currency,
			strtoupper( (string) reset( $codes ) ),
			'refunded'
		);
	}

	// ---------------------------------------------------------------------
	// SureCart / FluentCart webhook bridge.
	// ---------------------------------------------------------------------

	public static function register_bridge() {
		register_rest_route(
			'utm-coupons/v1',
			'/bridge',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'bridge_auth' ),
				'callback'            => array( __CLASS__, 'bridge_callback' ),
			)
		);
	}

	/**
	 * Protect the bridge with the install-time site token.
	 */
	public static function bridge_auth( $request ) {
		$token = $request->get_header( 'x-utm-site-token' );
		if ( empty( $token ) ) {
			$token = $request->get_param( 'site_token' );
		}
		if ( empty( $token ) || ! hash_equals( (string) Settings::site_token(), (string) $token ) ) {
			return new \WP_Error( 'utm_forbidden', 'Invalid site token', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function bridge_callback( $request ) {
		$event    = $request->get_json_params();
		if ( empty( $event ) ) {
			$event = $request->get_params();
		}
		$order_id = (string) ( $event['order_id'] ?? '' );
		$code     = (string) ( $event['discount_code'] ?? '' );
		if ( '' === $order_id || '' === $code ) {
			return new \WP_REST_Response( array( 'skipped' => true ), 200 );
		}
		Reporter::report(
			$order_id,
			(float) ( $event['total'] ?? 0 ),
			(string) ( $event['currency'] ?? 'USD' ),
			strtoupper( $code ),
			! empty( $event['refunded'] ) ? 'refunded' : 'paid'
		);
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	// ---------------------------------------------------------------------
	// Self test (connection wizard round-trip).
	// ---------------------------------------------------------------------

	/**
	 * Send a harmless test conversion and return whether the endpoint accepted it.
	 */
	public static function self_test() {
		$secret = Settings::get_hmac_secret();
		if ( ! $secret ) {
			return false;
		}
		$body = wp_json_encode(
			array(
				'orderId'    => 'T-WPSELFTEST',
				'amount'     => 0,
				'currency'   => 'USD',
				'couponCode' => 'UTMSELFTEST',
				'status'     => 'paid',
				'occurredAt' => gmdate( 'Y-m-d' ) . 'T' . gmdate( 'H:i:s' ) . 'Z',
			)
		);
		$sig  = hash_hmac( 'sha256', $body, $secret );
		$resp = wp_remote_post(
			UTM_COUPONS_HOOKS_URL,
			array(
				'headers'     => array(
					'Content-Type'    => 'application/json',
					'x-utm-signature' => $sig,
				),
				'body'        => $body,
				'timeout'     => 5,
				'blocking'    => true,
				'data_format' => 'body',
			)
		);
		if ( is_wp_error( $resp ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		return $code >= 200 && $code < 300;
	}
}
