<?php
/**
 * Plugin Name: UTM.coupons for WP
 * Plugin URI: https://utm.coupons/wp-plugin/
 * Description: Connect WooCommerce, Easy Digital Downloads, SureCart or FluentCart to UTM.coupons. Coupons sync automatically and every order is attributed with zero setup.
 * Version: 0.1.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: UTM.coupons
 * Author URI: https://utm.coupons/
 * Text Domain: utm-coupons
 * Domain Path: /languages
 *
 * @package UTM_Coupons
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UTM_COUPONS_VERSION', '0.1.1' );
define( 'UTM_COUPONS_FILE', __FILE__ );
define( 'UTM_COUPONS_PATH', plugin_dir_path( __FILE__ ) );
define( 'UTM_COUPONS_URL', plugin_dir_url( __FILE__ ) );

define( 'UTM_COUPONS_HOOKS_URL', 'https://hooks.utm.coupons/v1/conversions' );
define( 'UTM_COUPONS_API_BASE', 'https://api.utm.coupons' );

require_once UTM_COUPONS_PATH . 'includes/class-logger.php';
require_once UTM_COUPONS_PATH . 'includes/class-settings.php';
require_once UTM_COUPONS_PATH . 'includes/class-reporter.php';
require_once UTM_COUPONS_PATH . 'includes/class-click-capture.php';
require_once UTM_COUPONS_PATH . 'includes/class-platforms.php';
require_once UTM_COUPONS_PATH . 'includes/class-admin.php';
require_once UTM_COUPONS_PATH . 'includes/class-embed.php';

/**
 * Boot the plugin.
 */
function utm_coupons_boot() {
	UTM_Coupons\Click_Capture::init();
	UTM_Coupons\Platforms::init();
	UTM_Coupons\Embed::init();
	if ( is_admin() ) {
		UTM_Coupons\Admin::init();
	}
	// Dashboard "At a glance" widget.
	add_action( 'wp_dashboard_setup', array( 'UTM_Coupons\Admin', 'register_dashboard_widget' ) );
}
add_action( 'plugins_loaded', 'utm_coupons_boot' );

register_activation_hook( __FILE__, array( 'UTM_Coupons\Settings', 'activate' ) );

// Daily retry of failed conversion reports.
add_action( 'utm_coupons_retry_queue', array( 'UTM_Coupons\Reporter', 'process_retry_queue' ) );
if ( ! wp_next_scheduled( 'utm_coupons_retry_queue' ) ) {
	wp_schedule_event( time(), 'daily', 'utm_coupons_retry_queue' );
}

// Expose a tiny helper so other code/tests can read the plugin version.
if ( ! function_exists( 'utm_coupons_version' ) ) {
	function utm_coupons_version() {
		return UTM_COUPONS_VERSION;
	}
}
