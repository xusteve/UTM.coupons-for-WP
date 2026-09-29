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
		wp_add_inline_script(
			'wp-admin',
			'jQuery(document).on("click",".utm-copy",function(){var t=jQuery(this).data("clip");if(navigator.clipboard){navigator.clipboard.writeText(t);jQuery(this).text("Copied").prop("disabled",true);setTimeout(()=>jQuery(this).text("Copy").prop("disabled",false),1500);}});'
		);
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
		$api_mask = Settings::mask_secret( Settings::get_api_key() );
		$hmac_set = '' !== Settings::get_hmac_secret();
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

		// Status card.
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

		// Settings form.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="utm_coupons_save_settings">';
		wp_nonce_field( 'utm_save_settings' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="utm_api">' . esc_html__( 'API key', 'utm-coupons' ) . '</label></th><td>';
		echo '<input id="utm_api" name="utm_api_key" type="password" class="regular-text" autocomplete="off" placeholder="sk_live_...">';
		if ( $api_mask ) {
			echo ' <span class="utm-mask description">' . esc_html__( 'Current:', 'utm-coupons' ) . ' ' . esc_html( $api_mask ) . '</span>';
		}
		echo '<p class="description">' . esc_html__( 'Your UTM.coupons API key (sk_live_…). Leave blank to keep the existing key.', 'utm-coupons' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="utm_hmac">' . esc_html__( 'Conversion HMAC secret', 'utm-coupons' ) . '</label></th><td>';
		echo '<input id="utm_hmac" name="utm_hmac" type="password" class="regular-text" autocomplete="off">';
		if ( $hmac_set ) {
			echo ' <span class="description">' . esc_html__( 'Set & encrypted.', 'utm-coupons' ) . '</span>';
		}
		echo '<p class="description">' . esc_html__( 'Normally auto-provisioned from the API key. Paste manually only if you were given a secret directly.', 'utm-coupons' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Sync direction', 'utm-coupons' ) . '</th><td>';
		echo self::toggle( 'sync_a', $sync_a, __( 'Store → UTM.coupons (real-time)', 'utm-coupons' ) );
		echo '<br>' . self::toggle( 'sync_b', $sync_b, __( 'UTM.coupons → Store (every 6h)', 'utm-coupons' ) );
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'New coupon landing page', 'utm-coupons' ) . '</th><td>';
		echo self::toggle( 'landing', $landing, __( 'Auto-create a public /c/ landing page for new coupons', 'utm-coupons' ) );
		echo '<p class="description">' . esc_html__( 'Off by default — you opt in per coupon to keep internal/bulk codes private.', 'utm-coupons' ) . '</p></td></tr>';

		echo '</tbody></table>';
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save changes', 'utm-coupons' ) . '</button></p>';
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
		if ( ! empty( $_POST['utm_api_key'] ) ) {
			Settings::set_api_key( sanitize_text_field( wp_unslash( $_POST['utm_api_key'] ) ) );
			self::fetch_workspace();
		}
		if ( ! empty( $_POST['utm_hmac'] ) ) {
			Settings::set_hmac_secret( sanitize_text_field( wp_unslash( $_POST['utm_hmac'] ) ) );
		}
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

	private static function fetch_workspace() {
		$key = Settings::get_api_key();
		if ( ! $key ) {
			return;
		}
		$resp = wp_remote_get(
			UTM_COUPONS_API_BASE . '/api/me',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $key ),
				'timeout' => 5,
			)
		);
		if ( is_wp_error( $resp ) ) {
			return;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( isset( $body['workspace']['name'] ) ) {
			Settings::set( Settings::OPT_WORKSPACE, $body['workspace']['name'] );
		}
	}
}
