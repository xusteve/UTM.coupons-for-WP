<?php
/**
 * Attribution readout for the Coupons screen.
 *
 * The Coupons screen used to be built from the local coupon mirror — codes the
 * plugin happened to record while reporting an order. That mirror says nothing
 * about whether a coupon exists on the platform, so the screen happily printed
 * landing pages and short links for codes that were never there, and every one
 * of them 404'd.
 *
 * This class is the honest version: coupons come from the platform's coupon
 * ledger, links come from the platform's own slug (never rebuilt from the code
 * — a slug can be suffixed, e.g. SUMMER20-2), and attribution comes from the
 * report endpoint. Codes we reported but that have no coupon on the platform
 * are still shown, as grey rows, because they earned real money and hiding
 * them would look like lost revenue.
 *
 * Every call is cached: this screen is read far more often than the numbers
 * change, and a merchant opening it twice should not cost two API round trips.
 *
 * @package UTM_Coupons
 */

namespace UTM_Coupons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reports {

	/** How long a fetched readout stays fresh. */
	const CACHE_TTL = 300;

	/** Windows offered on the screen; the platform caps a report at 90 days. */
	const WINDOWS = array( 7, 30, 90 );
	const DEFAULT_WINDOW = 30;

	/** Short links live on their own host; `/r/<code>` on the main site is not a route. */
	const SHORT_LINK_BASE = 'https://r.utm.coupons';
	const PUBLIC_BASE     = 'https://utm.coupons';

	const COUPON_LIMIT = 100;

	/**
	 * Sanitise a requested window against the whitelist.
	 *
	 * @param int|string $days
	 * @return int
	 */
	public static function window( $days ) {
		$days = (int) $days;
		return in_array( $days, self::WINDOWS, true ) ? $days : self::DEFAULT_WINDOW;
	}

	/**
	 * GET a platform endpoint, cached.
	 *
	 * @param string $path      Path under the API base.
	 * @param string $cache_key Transient key.
	 * @return array{ok:bool,data:mixed,error:string}
	 */
	private static function fetch( $path, $cache_key ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['ok'] ) ) {
			return $cached;
		}

		$api_key = Settings::get_api_key();
		if ( '' === $api_key ) {
			return array(
				'ok'    => false,
				'data'  => null,
				'error' => 'not_connected',
			);
		}

		$response = wp_remote_get(
			UTM_COUPONS_API_BASE . $path,
			array(
				'headers'  => array( 'Authorization' => 'Bearer ' . $api_key ),
				'timeout'  => 10,
				'blocking' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'data'  => null,
				'error' => 'transport',
			);
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		if ( $http < 200 || $http >= 300 ) {
			return array(
				'ok'    => false,
				'data'  => null,
				'error' => 'http_' . $http,
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! array_key_exists( 'data', $body ) ) {
			return array(
				'ok'    => false,
				'data'  => null,
				'error' => 'malformed',
			);
		}

		$result = array(
			'ok'    => true,
			'data'  => $body['data'],
			'error' => '',
		);
		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * The platform coupon ledger.
	 *
	 * @return array{ok:bool,data:mixed,error:string}
	 */
	public static function coupons() {
		return self::fetch( '/api/coupons?limit=' . self::COUPON_LIMIT, 'utm_rep_coupons' );
	}

	/**
	 * Attribution totals for a window.
	 *
	 * @param int $days
	 * @return array{ok:bool,data:mixed,error:string}
	 */
	public static function overview( $days ) {
		$days = self::window( $days );
		$from = gmdate( 'Y-m-d\TH:i:s\Z', time() - $days * DAY_IN_SECONDS );
		$to   = gmdate( 'Y-m-d\TH:i:s\Z', time() );
		$path = '/api/reports/overview?from=' . $from . '&to=' . $to . '&topCoupons=' . self::COUPON_LIMIT;
		return self::fetch( $path, 'utm_rep_ov_' . $days );
	}

	/**
	 * Absolute short URL for a platform-allocated slug.
	 *
	 * @param string|null $slug
	 * @return string '' when the coupon has no slug.
	 */
	public static function short_url( $slug ) {
		$slug = trim( (string) $slug );
		if ( '' === $slug ) {
			return '';
		}
		$base = apply_filters( 'utm_coupons_short_link_base', self::SHORT_LINK_BASE );
		return rtrim( (string) $base, '/' ) . '/' . rawurlencode( $slug );
	}

	/**
	 * Public /c/ landing page for a coupon.
	 *
	 * @param string $code
	 * @return string
	 */
	public static function landing_url( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		if ( '' === $code ) {
			return '';
		}
		$base = apply_filters( 'utm_coupons_public_base', self::PUBLIC_BASE );
		return rtrim( (string) $base, '/' ) . '/c/' . sanitize_title( $code );
	}

	/**
	 * Collapse the per-coupon report rows into one entry per code.
	 *
	 * The endpoint returns one row per (code, currency), so a code paid in two
	 * currencies appears twice. The screen shows one line per code, with the
	 * largest currency as the headline and the rest listed after it.
	 *
	 * @param array $top_coupons
	 * @return array<string,array>
	 */
	private static function performance_by_code( $top_coupons ) {
		$by_code = array();
		if ( ! is_array( $top_coupons ) ) {
			return $by_code;
		}
		foreach ( $top_coupons as $row ) {
			$code = strtoupper( (string) ( $row['couponCode'] ?? '' ) );
			if ( '' === $code ) {
				continue;
			}
			if ( ! isset( $by_code[ $code ] ) ) {
				$by_code[ $code ] = array();
			}
			$currency            = (string) ( $row['currency'] ?? '' );
			$by_code[ $code ][ $currency ] = array(
				'revenue' => (float) ( $row['revenue'] ?? 0 ),
				'orders'  => (int) ( $row['orders'] ?? 0 ),
				'paid'    => (int) ( $row['paidOrders'] ?? 0 ),
			);
		}
		// Headline currency first: largest revenue at the top.
		foreach ( $by_code as $code => $currencies ) {
			uasort(
				$by_code[ $code ],
				function ( $a, $b ) {
					return $b['revenue'] <=> $a['revenue'];
				}
			);
		}
		return $by_code;
	}

	/**
	 * The rows the Coupons screen renders: platform coupons first, then codes
	 * this store reported that have no coupon on the platform.
	 *
	 * @param int $days
	 * @return array{ok:bool,error:string,rows:array,overview:array|null}
	 */
	public static function rows( $days ) {
		$days          = self::window( $days );
		$overview_call = self::overview( $days );
		$coupons_call  = self::coupons();

		$out = array(
			'ok'       => $overview_call['ok'] || $coupons_call['ok'],
			'error'    => $overview_call['ok'] ? $coupons_call['error'] : $overview_call['error'],
			'rows'     => array(),
			'overview' => $overview_call['ok'] ? $overview_call['data'] : null,
		);

		$overview = $overview_call['ok'] ? $overview_call['data'] : array();
		$perf     = self::performance_by_code( $overview['topCoupons'] ?? array() );

		$rows = array();

		// 1) Coupons that really exist on the platform — links are trustworthy.
		if ( $coupons_call['ok'] && is_array( $coupons_call['data'] ) ) {
			foreach ( $coupons_call['data'] as $coupon ) {
				$code = strtoupper( (string) ( $coupon['code'] ?? '' ) );
				if ( '' === $code ) {
					continue;
				}
				$rows[ $code ] = array(
					'code'        => $code,
					'status'      => (string) ( $coupon['status'] ?? 'active' ),
					'exists'      => true,
					'shortUrl'    => self::short_url( $coupon['shortSlug'] ?? null ),
					'landingUrl'  => ! empty( $coupon['autoGeneratePage'] ) ? self::landing_url( $code ) : '',
					'performance' => $perf[ $code ] ?? array(),
					'expiresAt'   => (string) ( $coupon['expiresAt'] ?? '' ),
				);
			}
		}

		// 2) Codes we reported that have no coupon there. They earned real money,
		//    so they stay — as rows with no links, not as links that 404.
		foreach ( Reporter::get_mirror() as $code => $entry ) {
			$code = strtoupper( (string) $code );
			if ( '' === $code || isset( $rows[ $code ] ) ) {
				continue;
			}
			$rows[ $code ] = array(
				'code'        => $code,
				'status'      => 'reported',
				'exists'      => false,
				'shortUrl'    => '',
				'landingUrl'  => '',
				'performance' => $perf[ $code ] ?? array(),
				'expiresAt'   => '',
			);
		}

		// 3) A code with conversion rows but no coupon and no mirror entry would
		//    otherwise be invisible; it is real revenue, so surface it too.
		foreach ( $perf as $code => $currencies ) {
			if ( isset( $rows[ $code ] ) ) {
				continue;
			}
			$rows[ $code ] = array(
				'code'        => $code,
				'status'      => 'reported',
				'exists'      => false,
				'shortUrl'    => '',
				'landingUrl'  => '',
				'performance' => $currencies,
				'expiresAt'   => '',
			);
		}

		// Highest earners first, then alphabetically for the ones with no numbers.
		uasort(
			$rows,
			function ( $a, $b ) {
				$rev_a = self::total_revenue( $a['performance'] );
				$rev_b = self::total_revenue( $b['performance'] );
				if ( $rev_a !== $rev_b ) {
					return $rev_b <=> $rev_a;
				}
				return strcmp( $a['code'], $b['code'] );
			}
		);

		$out['rows'] = array_values( $rows );
		return $out;
	}

	/**
	 * Sum revenue across currencies — only used for ordering, never displayed
	 * (mixing currencies into one figure would be a lie).
	 *
	 * @param array $performance
	 * @return float
	 */
	private static function total_revenue( $performance ) {
		$sum = 0.0;
		foreach ( (array) $performance as $row ) {
			$sum += (float) ( $row['revenue'] ?? 0 );
		}
		return $sum;
	}

	/**
	 * Format an amount in its own currency.
	 *
	 * @param float  $amount
	 * @param string $currency
	 * @return string
	 */
	public static function money( $amount, $currency ) {
		$symbols = array(
			'USD' => '$',
			'EUR' => '€',
			'GBP' => '£',
			'JPY' => '¥',
			'CNY' => '¥',
			'AUD' => 'A$',
			'CAD' => 'C$',
		);
		$currency = strtoupper( (string) $currency );
		$number   = function_exists( 'number_format_i18n' )
			? number_format_i18n( (float) $amount, 2 )
			: number_format( (float) $amount, 2 );
		$symbol   = $symbols[ $currency ] ?? '';
		return '' === $symbol ? $number . ' ' . $currency : $symbol . $number;
	}

	/**
	 * Render the performance cell: headline currency, others after it.
	 *
	 * @param array $performance
	 * @return string HTML (already escaped).
	 */
	public static function performance_cell( $performance ) {
		if ( empty( $performance ) ) {
			return '<span aria-hidden="true">—</span>';
		}
		$parts = array();
		$first = true;
		foreach ( $performance as $currency => $row ) {
			$parts[] = '<span' . ( $first ? '' : ' style="color:#646970"' ) . '>'
				. esc_html( self::money( $row['revenue'], $currency ) )
				. '</span>';
			$first   = false;
		}
		return implode( ' <span style="color:#8c8f94">+</span> ', $parts );
	}

	/**
	 * Total orders across currencies for a code.
	 *
	 * @param array $performance
	 * @return int
	 */
	public static function order_count( $performance ) {
		$orders = 0;
		foreach ( (array) $performance as $row ) {
			$orders += (int) ( $row['orders'] ?? 0 );
		}
		return $orders;
	}
}
