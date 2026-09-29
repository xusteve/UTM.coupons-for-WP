<?php
/**
 * First-party click capture. Reads click_id / coupon from the URL and stores
 * them as 30-day cookies so they can be attached to the next conversion.
 *
 * @package UTM_Coupons
 */

namespace UTM_Coupons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Click_Capture {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'capture' ) );
	}

	public static function capture() {
		if ( isset( $_GET['click_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			setcookie(
				'utm_click_id',
				sanitize_text_field( wp_unslash( $_GET['click_id'] ) ),
				time() + MONTH_IN_SECONDS,
				COOKIEPATH ?: '/',
				COOKIE_DOMAIN ?: '',
				is_ssl(),
				true
			);
		}
		if ( isset( $_GET['coupon'] ) && empty( $_COOKIE['utm_coupon'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			setcookie(
				'utm_coupon',
				sanitize_text_field( wp_unslash( $_GET['coupon'] ) ),
				time() + MONTH_IN_SECONDS,
				COOKIEPATH ?: '/',
				COOKIE_DOMAIN ?: '',
				is_ssl(),
				true
			);
		}
	}
}
