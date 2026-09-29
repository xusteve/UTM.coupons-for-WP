<?php
/**
 * Embed code: the `<utm-coupon>` widget, aligned with the live widget.
 *
 * The coupon catalogue syncs from the store automatically, but showing a
 * coupon *on* the store is a separate job — that is what the widget is for.
 * This class is the single place that knows how to write it, so the snippet a
 * merchant copies here and the one the dashboard shows are the same string.
 *
 * Two rules govern every attribute here:
 *
 *  1. THE VOCABULARY IS FROZEN. The widget only renders the variants, button
 *     styles and themes in the whitelists below, and silently ignores anything
 *     else. Accepting a merchant's guess and emitting it would produce a
 *     widget that renders but ignores the choice — worse than a hard no.
 *
 *  2. AN ATTRIBUTE THAT SAYS NOTHING IS WORSE THAN A MISSING ONE. Defaults are
 *     omitted: `variant="ticket"` is noise when ticket is what the widget does
 *     without it. This mirrors `couponEmbedSnippet()` in the dashboard.
 *
 * The element renders nothing without the script, so the snippet is always the
 * script tag *plus* the element — never the element alone.
 *
 * @package UTM_Coupons
 */

namespace UTM_Coupons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Embed {

	/** Public widget script — served by the render worker, cache-busted there. */
	const SCRIPT_URL = 'https://utm.coupons/embed/v1.js';

	/** Frozen vocabularies (constants.ts WIDGET_VARIANTS / _BUTTON_STYLES / _THEMES). */
	const VARIANTS      = array( 'ticket', 'button', 'mini', 'bar', 'badge' );
	const BUTTON_STYLES = array( 'solid', 'outline', 'pill', 'reveal', 'stacked' );
	const THEMES        = array( 'light', 'dark', 'brand', 'minimal' );

	/** Defaults the widget applies on its own — never worth an attribute. */
	const DEFAULT_VARIANT = 'ticket';
	const DEFAULT_STYLE   = 'solid';
	const DEFAULT_THEME   = 'light';

	/**
	 * Wire up the shortcode.
	 */
	public static function init() {
		add_shortcode( 'utm_coupon', array( __CLASS__, 'render' ) );
		add_shortcode( 'utm-coupon', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_script' ) );
	}

	/**
	 * Register the widget script so a theme or the shortcode can load it once.
	 */
	public static function register_script() {
		wp_register_script( 'utm-coupons-embed', self::SCRIPT_URL, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party widget, versioned at the URL.
	}

	/**
	 * Normalise a shortcode value against a whitelist.
	 *
	 * @param string $value      Raw attribute.
	 * @param array  $whitelist  Allowed values.
	 * @param string $default    Fallback when the value is not allowed.
	 * @return string
	 */
	private static function whitelist( $value, array $whitelist, $default ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return in_array( $value, $whitelist, true ) ? $value : $default;
	}

	/**
	 * Build the `<utm-coupon>` element for a set of arguments.
	 *
	 * @param array $args {
	 *     code (required), variant, button_style, button_text, theme,
	 *     description, discount, unit, expires, logo, brand, domain, style.
	 * }
	 * @return string Element markup, or '' when there is no code.
	 */
	public static function element( array $args ): string {
		$code = isset( $args['code'] ) ? sanitize_text_field( wp_unslash( $args['code'] ) ) : '';
		$code = strtoupper( trim( $code ) );
		if ( '' === $code ) {
			return '';
		}

		$variant = self::whitelist( $args['variant'] ?? '', self::VARIANTS, self::DEFAULT_VARIANT );
		$theme   = self::whitelist( $args['theme'] ?? '', self::THEMES, self::DEFAULT_THEME );

		$attrs = array( 'code="' . esc_attr( $code ) . '"' );

		if ( self::DEFAULT_VARIANT !== $variant ) {
			$attrs[] = 'variant="' . esc_attr( $variant ) . '"';
		}

		if ( 'button' === $variant ) {
			$style = self::whitelist( $args['button_style'] ?? '', self::BUTTON_STYLES, self::DEFAULT_STYLE );
			if ( self::DEFAULT_STYLE !== $style ) {
				$attrs[] = 'button-style="' . esc_attr( $style ) . '"';
			}
			// Only solid and pill carry a label; the others have no text slot.
			$text = isset( $args['button_text'] ) ? sanitize_text_field( $args['button_text'] ) : '';
			if ( '' !== $text && in_array( $style, array( 'solid', 'pill' ), true ) ) {
				$attrs[] = 'button-text="' . esc_attr( $text ) . '"';
			}
		}

		if ( self::DEFAULT_THEME !== $theme ) {
			$attrs[] = 'theme="' . esc_attr( $theme ) . '"';
		}

		// Optional, order matters only for readability.
		$optional = array( 'description', 'discount', 'unit', 'expires', 'logo', 'brand', 'domain', 'style' );
		foreach ( $optional as $key ) {
			if ( empty( $args[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( $args[ $key ] );
			$name  = str_replace( '_', '-', $key );
			if ( 'logo' === $key ) {
				$value = esc_url_raw( $value );
			}
			$attrs[] = $name . '="' . esc_attr( $value ) . '"';
		}

		return '<utm-coupon ' . implode( ' ', $attrs ) . '></utm-coupon>';
	}

	/**
	 * The runnable snippet: script tag first, then the element.
	 *
	 * @param array $args Same shape as element().
	 * @return string
	 */
	public static function snippet( array $args ): string {
		$element = self::element( $args );
		if ( '' === $element ) {
			return '';
		}
		return '<script async src="' . esc_url( self::SCRIPT_URL ) . '"></script>' . "\n" . $element;
	}

	/**
	 * Shortcode handler. Loads the script and returns the element.
	 *
	 * @param array|string $atts Shortcode attributes; `code` may also be the
	 *                           bare first positional argument.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts = is_array( $atts ) ? $atts : array();

		// [utm_coupon "SUMMER20"] — positional code.
		if ( isset( $atts[0] ) ) {
			$atts['code'] = $atts[0];
			unset( $atts[0] );
		}

		$element = self::element( $atts );
		if ( '' === $element ) {
			return ''; // A code-less widget renders nothing; say nothing.
		}

		// The element upgrades itself only once the script has run.
		if ( ! wp_script_is( 'utm-coupons-embed', 'enqueued' ) ) {
			wp_enqueue_script( 'utm-coupons-embed' );
		}

		return $element;
	}
}
