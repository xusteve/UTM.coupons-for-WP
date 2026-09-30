<?php
/**
 * Admin UI: connection settings, coupons list, logs and dashboard widget.
 *
 * @package UTM_Coupons
 */

namespace UTM_Coupons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Coupons mirror table.
 */
class Coupons_Table extends \WP_List_Table {

	public function get_columns() {
		return array(
			'code'      => __( 'Coupon', 'utm-coupons' ),
			'status'    => __( 'Store status', 'utm-coupons' ),
			'landing'   => __( 'Landing page', 'utm-coupons' ),
			'shortlink' => __( 'Short link', 'utm-coupons' ),
			'embed'     => __( 'Embed code', 'utm-coupons' ),
			'seen'      => __( 'Last seen', 'utm-coupons' ),
		);
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$mirror                = Reporter::get_mirror();
		$items                 = array();
		foreach ( $mirror as $row ) {
			$items[] = $row;
		}
		$this->items = $items;
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'code':
				return '<code>' . esc_html( $item['code'] ) . '</code>';
			case 'status':
				$cls = 'utm-pill ' . ( 'active' === $item['status'] ? 'utm-pill-ok' : 'utm-pill-off' );
				return '<span class="' . $cls . '">' . esc_html( ucfirst( $item['status'] ) ) . '</span>';
			case 'landing':
				$url = 'https://utm.coupons/c/' . sanitize_title( $item['code'] );
				return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View /c/', 'utm-coupons' ) . '</a>';
			case 'shortlink':
				$slug = 'https://utm.coupons/r/' . sanitize_title( $item['code'] );
				return '<button class="button button-small utm-copy" data-clip="' . esc_attr( $slug ) . '">' . esc_html__( 'Copy', 'utm-coupons' ) . '</button>';
			case 'embed':
				$snippet = Embed::snippet( array( 'code' => $item['code'] ) );
				if ( '' === $snippet ) {
					return '';
				}
				return '<button class="button button-small utm-copy" data-clip="' . esc_attr( $snippet ) . '">' . esc_html__( 'Copy embed', 'utm-coupons' ) . '</button>';
			case 'seen':
				return esc_html( $item['seen'] );
			default:
				return '';
		}
	}
}

class Admin {

	/**
	 * Wire up admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_utm_coupons_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_utm_coupons_test', array( __CLASS__, 'run_test' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'UTM.coupons', 'utm-coupons' ),
			__( 'UTM.coupons', 'utm-coupons' ),
			'manage_options',
			'utm-coupons',
			array( __CLASS__, 'render_connection' ),
			'dashicons-tickets-alt',
			58
		);
		add_submenu_page(
			'utm-coupons',
			__( 'Connection', 'utm-coupons' ),
			__( 'Connection', 'utm-coupons' ),
			'manage_options',
			'utm-coupons',
			array( __CLASS__, 'render_connection' )
		);
		add_submenu_page(
			'utm-coupons',
			__( 'Coupons', 'utm-coupons' ),
			__( 'Coupons', 'utm-coupons' ),
			'manage_options',
			'utm-coupons-coupons',
			array( __CLASS__, 'render_coupons' )
		);
		add_submenu_page(
			'utm-coupons',
			__( 'Platforms & Logs', 'utm-coupons' ),
			__( 'Platforms & Logs', 'utm-coupons' ),
			'manage_options',
			'utm-coupons-logs',
			array( __CLASS__, 'render_logs' )
		);
	}

	public static function enqueue( $hook ) {
		if ( false === strpos( $hook, 'utm-coupons' ) ) {
			return;
		}
		wp_add_inline_style( 'wp-admin', self::inline_css() );
		// Print the copy handler in the footer instead of attaching it to a
		// script handle: `wp-admin` is not a registered *script* handle, so
		// wp_add_inline_script() silently dropped it and the Copy buttons did
		// nothing when clicked.
		add_action( 'admin_footer', array( __CLASS__, 'print_copy_script' ) );
	}

	/**
	 * Copy-to-clipboard handler for every .utm-copy button.
	 *
	 * Restores the button's own label (a "Copy embed" button must not come back
	 * as "Copy") and falls back to execCommand where the async clipboard API is
	 * unavailable — e.g. a store served over plain HTTP.
	 */
	public static function print_copy_script() {
		echo '<script>' . self::copy_script() . '</script>';
	}

	private static function copy_script() {
		return 'jQuery(function($){$(document).on("click",".utm-copy",function(e){'
			. 'e.preventDefault();'
			. 'var b=$(this),t=b.data("clip")||"";'
			. 'var o=b.data("utm-orig");if(!o){o=b.text();b.data("utm-orig",o);}'
			. 'function done(){b.text("Copied").prop("disabled",true);setTimeout(function(){b.text(o).prop("disabled",false);},1500);}'
			. 'function fallback(){var ta=document.createElement("textarea");ta.value=t;ta.setAttribute("readonly","");'
			. 'ta.style.position="fixed";ta.style.top="-1000px";document.body.appendChild(ta);ta.select();'
			. 'try{document.execCommand("copy");}catch(err){}document.body.removeChild(ta);done();}'
			. 'if(navigator.clipboard&&navigator.clipboard.writeText){'
			. 'navigator.clipboard.writeText(t).then(done,fallback);}else{fallback();}'
			. '});});';
	}

	private static function inline_css() {
		return '
		.utm-status-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;vertical-align:middle}
		.utm-dot-ok{background:#1a7f37}.utm-dot-warn{background:#bf8700}.utm-dot-err{background:#cf222e}
		.utm-pill{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600}
		.utm-pill-ok{background:#dafbe1;color:#1a7f37}.utm-pill-off{background:#eaeef2;color:#57606a}
		.utm-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-top:12px}
		.utm-card{border:1px solid #d0d7de;border-radius:8px;padding:16px;background:#fff}
		.utm-card h3{margin:0 0 8px;font-size:14px}
		.utm-card .utm-state{font-size:13px;font-weight:600}
		.utm-card code{font-size:11px;word-break:break-all}
		.utm-mask{font-family:monospace}
		';
	}

	// ---------------------------------------------------------------------
	// Connection page.
	// ---------------------------------------------------------------------

	public static function render_connection() {
		$status   = Settings::get( Settings::OPT_STATUS, 'unconfigured' );
		$last     = Settings::get( Settings::OPT_LAST_TEST, '' );
		$sync_a   = Settings::get( Settings::OPT_SYNC_A, '1' );
		$sync_b   = Settings::get( Settings::OPT_SYNC_B, '0' );
		$landing  = Settings::get( Settings::OPT_LANDING, '0' );
		$ws       = Settings::get( Settings::OPT_WORKSPACE, '' );

		$dot = 'utm-dot-warn';
		if ( 'connected' === $status ) {
			$dot = 'utm-dot-ok';
		} elseif ( 'error' === $status ) {
			$dot = 'utm-dot-err';
		}
		$label = ucfirst( $status );

		echo '<div class="wrap"><h1>' . esc_html__( 'UTM.coupons — Connection', 'utm-coupons' ) . '</h1>';

		// One-click connect card (OAuth) — the primary path.
		OAuth_Client::render_connect_card();

		// Manual status card (legacy/manual verification).
		echo '<div class="utm-card" style="max-width:640px;margin-bottom:20px">';
		echo '<p style="font-size:15px"><span class="utm-status-dot ' . $dot . '"></span><strong>' . esc_html( $label ) . '</strong>';
		if ( $ws ) {
			echo ' &middot; ' . esc_html( $ws );
		}
		echo '</p>';
		if ( $last ) {
			echo '<p class="description">' . sprintf( esc_html__( 'Last test: %s', 'utm-coupons' ), esc_html( $last ) ) . '</p>';
		}
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=utm_coupons_test' ), 'utm_test' ) ) . '">' . esc_html__( 'Run connection test', 'utm-coupons' ) . '</a></p>';
		echo '</div>';

		// Behaviour settings (kept outside the removed manual-credentials
		// block — OAuth provisions all secrets automatically now). The toggles
		// live inside the form so the checkbox values are actually submitted.
		echo '<form id="utm-behaviour-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<div class="utm-card" style="max-width:640px;margin-bottom:20px">';
		echo '<h3>' . esc_html__( 'Behaviour', 'utm-coupons' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Sync direction', 'utm-coupons' ) . '</th><td>';
		echo self::toggle( 'sync_a', $sync_a, __( 'Store → UTM.coupons (real-time)', 'utm-coupons' ) );
		echo '<br>' . self::toggle( 'sync_b', $sync_b, __( 'UTM.coupons → Store (every 6h)', 'utm-coupons' ) );
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'New coupon landing page', 'utm-coupons' ) . '</th><td>';
		echo self::toggle( 'landing', $landing, __( 'Auto-create a public /c/ landing page for new coupons', 'utm-coupons' ) );
		echo '<p class="description">' . esc_html__( 'Off by default — you opt in per coupon to keep internal/bulk codes private.', 'utm-coupons' ) . '</p></td></tr>';
		echo '</tbody></table>';
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save changes', 'utm-coupons' ) . '</button></p>';
		echo '</div>';
		echo '<input type="hidden" name="action" value="utm_coupons_save_settings">';
		wp_nonce_field( 'utm_save_settings' );
		echo '</form>';

		echo '</div>';
	}

	private static function toggle( $name, $on, $label ) {
		$id = 'utm_' . $name;
		return '<label for="' . $id . '"><input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . checked( '1', $on, false ) . '> ' . esc_html( $label ) . '</label>';
	}

	// ---------------------------------------------------------------------
	// Coupons list.
	// ---------------------------------------------------------------------

	public static function render_coupons() {
		echo '<div class="wrap"><h1>' . esc_html__( 'UTM.coupons — Coupons', 'utm-coupons' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Coupons detected and reported by the plugin. Landing pages and short links are generated automatically on UTM.coupons.', 'utm-coupons' ) . '</p>';
		$table = new Coupons_Table();
		$table->prepare_items();
		$table->display();
		echo '</div>';
	}

	// ---------------------------------------------------------------------
	// Platforms & logs.
	// ---------------------------------------------------------------------

	public static function render_logs() {
		$detect = Platforms::detect();
		echo '<div class="wrap"><h1>' . esc_html__( 'UTM.coupons — Platforms & Logs', 'utm-coupons' ) . '</h1>';

		echo '<h2>' . esc_html__( 'Detected platforms', 'utm-coupons' ) . '</h2>';
		echo '<div class="utm-cards">';
		foreach ( $detect as $name => $active ) {
			$label = ucfirst( str_replace( 'fluentcart', 'FluentCart', $name ) );
			$state = $active ? __( 'Detected', 'utm-coupons' ) : __( 'Not installed', 'utm-coupons' );
			$dot   = $active ? 'utm-dot-ok' : 'utm-dot-warn';
			echo '<div class="utm-card"><h3>' . esc_html( $label ) . '</h3><p class="utm-state"><span class="utm-status-dot ' . $dot . '"></span>' . esc_html( $state ) . '</p>';
			if ( $active ) {
				$bridge = rest_url( 'utm-coupons/v1/bridge' );
				echo '<p class="description">' . esc_html__( 'Webhook bridge:', 'utm-coupons' ) . ' <code>' . esc_html( $bridge ) . '</code></p>';
			}
			echo '</div>';
		}
		echo '</div>';

		echo '<h2 style="margin-top:24px">' . esc_html__( 'Recent activity', 'utm-coupons' ) . '</h2>';
		$log = Logger::get_all();
		if ( empty( $log ) ) {
			echo '<p class="description">' . esc_html__( 'No events yet. Place a test order that uses a coupon to see attribution here.', 'utm-coupons' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Time', 'utm-coupons' ) . '</th><th>' . esc_html__( 'Level', 'utm-coupons' ) . '</th><th>' . esc_html__( 'Message', 'utm-coupons' ) . '</th></tr></thead><tbody>';
			foreach ( $log as $entry ) {
				echo '<tr><td>' . esc_html( $entry['time'] ) . '</td><td>' . esc_html( $entry['level'] ) . '</td><td>' . esc_html( $entry['message'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	// ---------------------------------------------------------------------
	// Dashboard widget.
	// ---------------------------------------------------------------------

	public static function register_dashboard_widget() {
		wp_add_dashboard_widget(
			'utm_coupons_widget',
			__( 'UTM.coupons', 'utm-coupons' ),
			array( __CLASS__, 'dashboard_widget' )
		);
	}

	public static function dashboard_widget() {
		$status = Settings::get( Settings::OPT_STATUS, 'unconfigured' );
		$dot    = 'utm-dot-warn';
		if ( 'connected' === $status ) {
			$dot = 'utm-dot-ok';
		} elseif ( 'error' === $status ) {
			$dot = 'utm-dot-err';
		}
		echo '<p><span class="utm-status-dot ' . $dot . '"></span>' . esc_html( ucfirst( $status ) ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Coupon attribution runs automatically for WooCommerce, EDD, SureCart and FluentCart.', 'utm-coupons' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=utm-coupons' ) ) . '">' . esc_html__( 'Open UTM.coupons', 'utm-coupons' ) . '</a> <a href="https://utm.coupons/" target="_blank" rel="noopener">' . esc_html__( 'Full report', 'utm-coupons' ) . '</a></p>';
	}

	// ---------------------------------------------------------------------
	// Handlers.
	// ---------------------------------------------------------------------

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'utm_save_settings' ) ) {
			wp_die( 'Permission denied' );
		}
		// Manual credential fields were removed with the advanced block —
		// OAuth one-click connect provisions all secrets automatically.
		Settings::set( Settings::OPT_SYNC_A, isset( $_POST['sync_a'] ) ? '1' : '0' );
		Settings::set( Settings::OPT_SYNC_B, isset( $_POST['sync_b'] ) ? '1' : '0' );
		Settings::set( Settings::OPT_LANDING, isset( $_POST['landing'] ) ? '1' : '0' );
		self::evaluate_status();
		wp_safe_redirect( admin_url( 'admin.php?page=utm-coupons&saved=1' ) );
		exit;
	}

	public static function run_test() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'utm_test' ) ) {
			wp_die( 'Permission denied' );
		}
		$ok = Platforms::self_test();
		Settings::set( Settings::OPT_LAST_TEST, gmdate( 'Y-m-d H:i:s' ) );
		Settings::set( Settings::OPT_STATUS, $ok ? 'connected' : 'error' );
		wp_safe_redirect( admin_url( 'admin.php?page=utm-coupons&test=' . ( $ok ? 'ok' : 'fail' ) ) );
		exit;
	}

	private static function evaluate_status() {
		$ok = Platforms::self_test();
		Settings::set( Settings::OPT_STATUS, $ok ? 'connected' : 'unconfigured' );
		Settings::set( Settings::OPT_LAST_TEST, gmdate( 'Y-m-d H:i:s' ) );
	}
}
