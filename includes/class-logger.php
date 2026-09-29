<?php
/**
 * Simple ring-buffer logger stored in an option.
 *
 * @package UTM_Coupons
 */

namespace UTM_Coupons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {

	const OPTION = 'utm_coupons_log';
	const LIMIT  = 100;

	/**
	 * Append an entry to the log.
	 *
	 * @param string $message Human readable message.
	 * @param string $level   info | success | warning | error.
	 * @param mixed  $data    Optional structured payload.
	 */
	public static function add( $message, $level = 'info', $data = null ) {
		$log = get_option( self::OPTION, array() );
		array_unshift(
			$log,
			array(
				'time'    => gmdate( 'Y-m-d H:i:s' ),
				'level'   => $level,
				'message' => $message,
				'data'    => $data,
			)
		);
		if ( count( $log ) > self::LIMIT ) {
			$log = array_slice( $log, 0, self::LIMIT );
		}
		update_option( self::OPTION, $log );
	}

	/**
	 * Return all entries (newest first).
	 */
	public static function get_all() {
		return get_option( self::OPTION, array() );
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
