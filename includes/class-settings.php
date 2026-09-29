<?php
/**
 * Plugin settings (Options API) with lightweight encryption for secrets.
 *
 * @package UTM_Coupons
 */

namespace UTM_Coupons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPT_API_KEY    = 'utm_coupons_api_key';
	const OPT_HMAC       = 'utm_coupons_hmac_secret';
	const OPT_WORKSPACE  = 'utm_coupons_workspace';
	const OPT_SITE_TOKEN = 'utm_coupons_site_token';
	const OPT_SYNC_A     = 'utm_coupons_sync_store_to_platform';
	const OPT_SYNC_B     = 'utm_coupons_sync_platform_to_store';
	const OPT_LANDING    = 'utm_coupons_landing_default';
	const OPT_STATUS     = 'utm_coupons_connection_status';
	const OPT_LAST_TEST  = 'utm_coupons_last_test';

	/**
	 * Create sane defaults on activation.
	 */
	public static function activate() {
		if ( ! get_option( self::OPT_SITE_TOKEN ) ) {
			update_option( self::OPT_SITE_TOKEN, wp_generate_password( 32, false ) );
		}
		if ( false === get_option( self::OPT_SYNC_A ) ) {
			update_option( self::OPT_SYNC_A, '1' );
		}
		if ( false === get_option( self::OPT_SYNC_B ) ) {
			update_option( self::OPT_SYNC_B, '0' );
		}
		if ( false === get_option( self::OPT_LANDING ) ) {
			update_option( self::OPT_LANDING, '0' );
		}
	}

	// ---------------------------------------------------------------------
	// Secret storage (encrypted when libsodium is available).
	// ---------------------------------------------------------------------

	private static function crypto_key() {
		$seed = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'utm' ) . '|' .
				( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'coupons' );
		return substr( hash( 'sha256', $seed, true ), 0, 32 );
	}

	public static function set_hmac_secret( $plain ) {
		update_option( self::OPT_HMAC, self::encrypt( $plain ) );
	}

	public static function get_hmac_secret() {
		$v = get_option( self::OPT_HMAC, '' );
		if ( '' === $v ) {
			return '';
		}
		return self::decrypt( $v );
	}

	public static function set_api_key( $plain ) {
		update_option( self::OPT_API_KEY, self::encrypt( $plain ) );
	}

	public static function get_api_key() {
		$v = get_option( self::OPT_API_KEY, '' );
		if ( '' === $v ) {
			return '';
		}
		return self::decrypt( $v );
	}

	private static function encrypt( $plain ) {
		if ( function_exists( 'sodium_crypto_secretbox' ) && is_string( $plain ) && '' !== $plain ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$c     = sodium_crypto_secretbox( $plain, $nonce, self::crypto_key() );
			return 'sm:' . base64_encode( $nonce . $c );
		}
		return 'pl:' . base64_encode( (string) $plain );
	}

	private static function decrypt( $val ) {
		if ( strpos( $val, 'sm:' ) === 0 ) {
			$b     = base64_decode( substr( $val, 3 ) );
			$nonce = substr( $b, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$c     = substr( $b, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$out   = sodium_crypto_secretbox_open( $c, $nonce, self::crypto_key() );
			return false === $out ? '' : $out;
		}
		if ( strpos( $val, 'pl:' ) === 0 ) {
			return base64_decode( substr( $val, 3 ) );
		}
		return $val;
	}

	// ---------------------------------------------------------------------
	// Generic helpers.
	// ---------------------------------------------------------------------

	public static function get( $key, $default = '' ) {
		$v = get_option( $key, $default );
		return false === $v ? $default : $v;
	}

	public static function set( $key, $value ) {
		update_option( $key, $value );
	}

	public static function site_token() {
		$t = get_option( self::OPT_SITE_TOKEN, '' );
		if ( '' === $t ) {
			$t = wp_generate_password( 32, false );
			update_option( self::OPT_SITE_TOKEN, $t );
		}
		return $t;
	}

	public static function mask_secret( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( strlen( $value ) <= 8 ) {
			return '••••••';
		}
		return substr( $value, 0, 6 ) . str_repeat( '•', max( 4, strlen( $value ) - 10 ) ) . substr( $value, -4 );
	}
}
