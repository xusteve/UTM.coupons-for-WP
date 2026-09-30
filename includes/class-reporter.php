<?php
/**
 * Signed conversion reporter + retry queue.
 *
 * @package UTM_Coupons
 */

namespace UTM_Coupons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reporter {

	const RETRY_OPTION = 'utm_coupons_retry_queue';

	/**
	 * Send a signed conversion event to hooks.utm.coupons.
	 *
	 * @param string $order_id  Order / payment identifier.
	 * @param float  $amount    Order total.
	 * @param string $currency  ISO currency code.
	 * @param string $code      Coupon / discount code (uppercased on send).
	 * @param string $status    paid | pending | refunded | cancelled.
	 * @param array  $extra     Optional extra fields (sourceId, shareId, customerRef).
	 * @return bool|int         True on 2xx, false on failure, WP_Error on transport error.
	 */
	public static function report( $order_id, $amount, $currency, $code, $status, $extra = array() ) {
		$secret = Settings::get_hmac_secret();
		if ( ! $secret ) {
			Logger::add( 'Skipped report — no HMAC secret configured.', 'warning' );
			return false;
		}

		$click_id = isset( $_COOKIE['utm_click_id'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['utm_click_id'] ) ) : null;

		$payload = array_merge(
			array(
				'orderId'     => (string) $order_id,
				'amount'      => (float) $amount,
				'currency'    => (string) $currency,
				'couponCode'  => strtoupper( (string) $code ),
				'clickId'     => $click_id,
				'status'      => (string) $status,
				'occurredAt'  => gmdate( 'Y-m-d' ) . 'T' . gmdate( 'H:i:s' ) . 'Z',
				// P2 (M5.4): the platform verifies conversion reports against a
				// per-workspace signing secret. Sending our workspace id lets
				// the hooks worker pick the right secret without depending on
				// the coupon existing in the platform ledger.
				'workspaceId' => Settings::get( Settings::OPT_WORKSPACE_ID, '' ),
			),
			$extra
		);

		// Omit null optional fields rather than sending them (per contract).
		foreach ( $payload as $k => $v ) {
			if ( null === $v ) {
				unset( $payload[ $k ] );
			}
		}

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			Logger::add( 'Failed to encode report payload.', 'error' );
			return false;
		}

		$sig      = hash_hmac( 'sha256', $body, $secret );
		$blocking = true; // Always block: hooks.utm.coupons returns 202 quickly and we need the real status for logging + local mirror.
		$response = wp_remote_post(
			UTM_COUPONS_HOOKS_URL,
			array(
				'headers'     => array(
					'Content-Type'    => 'application/json',
					'x-utm-signature' => $sig,
				),
				'body'        => $body,
				'timeout'     => 5,
				'blocking'    => $blocking,
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::add( 'Report transport error: ' . $response->get_error_message(), 'error', $payload );
			self::queue_retry( $body, $sig );
			return $response;
		}

		$code_http = (int) wp_remote_retrieve_response_code( $response );
		$resp_body = wp_remote_retrieve_body( $response );

		if ( $code_http >= 200 && $code_http < 300 ) {
			Logger::add(
				sprintf( 'Reported %s order %s (code %s) → HTTP %d', $status, $order_id, strtoupper( (string) $code ), $code_http ),
				'success',
				$resp_body
			);
			// Record in the local coupon mirror so the list page can show it.
			self::record_coupon( (string) $code, $status );
			return true;
		}

		Logger::add(
			sprintf( 'Report rejected: HTTP %d — %s', $code_http, $resp_body ),
			'error',
			$payload
		);
		self::queue_retry( $body, $sig );
		return false;
	}

	/**
	 * Keep a lightweight local mirror of synced coupons for the list page.
	 */
	private static function record_coupon( $code, $status ) {
		$code = strtoupper( (string) $code );
		if ( '' === $code ) {
			return;
		}
		$mirror = get_option( 'utm_coupons_mirror', array() );
		$mirror[ $code ] = array(
			'code'   => $code,
			'status' => ( 'refunded' === $status || 'cancelled' === $status ) ? 'inactive' : 'active',
			'seen'   => gmdate( 'Y-m-d H:i:s' ),
		);
		update_option( 'utm_coupons_mirror', $mirror );
	}

	public static function get_mirror() {
		return get_option( 'utm_coupons_mirror', array() );
	}

	// ---------------------------------------------------------------------
	// Retry queue.
	// ---------------------------------------------------------------------

	private static function queue_retry( $body, $sig ) {
		$queue   = get_option( self::RETRY_OPTION, array() );
		$queue[] = array(
			'body' => $body,
			'sig'  => $sig,
			'time' => time(),
		);
		if ( count( $queue ) > 50 ) {
			$queue = array_slice( $queue, -50 );
		}
		update_option( self::RETRY_OPTION, $queue );
	}

	public static function process_retry_queue() {
		$queue = get_option( self::RETRY_OPTION, array() );
		if ( empty( $queue ) ) {
			return;
		}
		$remaining = array();
		foreach ( $queue as $item ) {
			$response = wp_remote_post(
				UTM_COUPONS_HOOKS_URL,
				array(
					'headers'     => array(
						'Content-Type'    => 'application/json',
						'x-utm-signature' => $item['sig'],
					),
					'body'        => $item['body'],
					'timeout'     => 5,
					'blocking'    => true,
					'data_format' => 'body',
				)
			);
			if ( is_wp_error( $response ) ) {
				$remaining[] = $item;
				continue;
			}
			$code_http = (int) wp_remote_retrieve_response_code( $response );
			if ( $code_http >= 200 && $code_http < 300 ) {
				Logger::add( 'Retry delivered a queued report.', 'success' );
			} else {
				$remaining[] = $item;
			}
		}
		update_option( self::RETRY_OPTION, $remaining );
	}
}
