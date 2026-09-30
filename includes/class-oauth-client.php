<?php
/**
 * One-click store connection (OAuth authorization-code flow, M5.4).
 *
 * Replaces the old copy/paste of an API key + webhook secret across two
 * browsers. The merchant clicks "Connect with UTM.coupons" and is taken to
 * the dashboard's `/oauth-authorize` page (they are already signed in there).
 * After approval the dashboard redirects back to this plugin's callback with
 * `?code=…&state=…`; this class exchanges the code for a fresh API key and
 * webhook signing secret at `POST /api/oauth/token` and stores them.
 *
 * @package UTM_Coupons
 */

namespace UTM_Coupons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OAuth_Client {

	const STATE_TRANSIENT = 'utm_oauth_state';
	const OPT_WEBHOOK_ID  = 'utm_coupons_webhook_id';

	/**
	 * Wire up the admin-post callback.
	 */
	public static function init() {
		add_action( 'admin_post_utm_oauth_callback', array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_utm_oauth_disconnect', array( __CLASS__, 'handle_disconnect' ) );
	}

	/**
	 * The URL the platform redirects back to after approval.
	 * The plugin's own admin-post handler (no visible page).
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=utm_oauth_callback' );
	}

	/**
	 * The dashboard's authorize page URL.
	 *
	 * Production is app.utm.coupons. A filter lets local development point at
	 * a running dashboard (e.g. http://127.0.0.1:5173) without code changes.
	 */
	public static function authorize_base() {
		$base = 'https://app.utm.coupons/oauth-authorize';
		return apply_filters( 'utm_coupons_oauth_authorize_base', $base );
	}

	/**
	 * Render the connection card on the settings page.
	 */
	public static function render_connect_card() {
		$api_key   = Settings::get_api_key();
		$workspace = Settings::get( Settings::OPT_WORKSPACE, '' );
		$connected = '' !== $api_key;

		echo '<div class="utm-card" style="max-width:640px;margin-bottom:20px">';
		echo '<h3>' . esc_html__( 'WordPress store connection', 'utm-coupons' ) . '</h3>';

		if ( $connected ) {
			echo '<p class="utm-state"><span class="utm-status-dot utm-dot-ok"></span>' .
				esc_html__( 'Connected', 'utm-coupons' );
			if ( $workspace ) {
				echo ' &middot; ' . esc_html( $workspace );
			}
			echo '</p>';
			echo '<p class="description">' . esc_html__( 'Your store reports conversions to UTM.coupons automatically. Use "Run connection test" below to verify end to end.', 'utm-coupons' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=utm_oauth_disconnect' ), 'utm_oauth_disconnect' ) ) . '" style="color:#cf222e">' . esc_html__( 'Disconnect', 'utm-coupons' ) . '</a></p>';
		} else {
			echo '<p class="utm-state"><span class="utm-status-dot utm-dot-warn"></span>' .
				esc_html__( 'Not connected', 'utm-coupons' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Connect your WordPress store to UTM.coupons in one click. You will be asked to approve the connection in your dashboard — an API key and a webhook endpoint are created automatically.', 'utm-coupons' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( self::authorize_url() ) . '">' . esc_html__( 'Connect with UTM.coupons', 'utm-coupons' ) . '</a></p>';
		}

		echo '</div>';
	}

	/**
	 * Build the full authorize URL with state + redirect_uri.
	 */
	private static function authorize_url() {
		$state        = wp_generate_password( 32, false );
		set_transient( self::STATE_TRANSIENT, $state, 15 * MINUTE_IN_SECONDS );

		$params = array(
			'redirect_uri' => self::redirect_uri(),
			'state'        => $state,
			'workspace'    => Settings::get( Settings::OPT_WORKSPACE, '' ),
		);
		return add_query_arg( $params, self::authorize_base() );
	}

	/**
	 * Handle the OAuth callback: verify state, exchange code for credentials.
	 */
	public static function handle_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied' );
		}

		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		if ( ! $code || ! $state ) {
			self::redirect_with_notice( 'missing' );
		}

		$expected = get_transient( self::STATE_TRANSIENT );
		delete_transient( self::STATE_TRANSIENT );
		if ( ! $expected || ! hash_equals( $expected, $state ) ) {
			self::redirect_with_notice( 'state_mismatch' );
		}

		$response = wp_remote_post(
			UTM_COUPONS_API_BASE . '/api/oauth/token',
			array(
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode(
					array(
						'code'         => $code,
						'redirect_uri' => self::redirect_uri(),
					)
				),
				'timeout'     => 10,
				'blocking'    => true,
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::add( 'OAuth token exchange failed: ' . $response->get_error_message(), 'error' );
			self::redirect_with_notice( 'exchange_failed' );
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $http < 200 || $http >= 300 || empty( $body['data']['apiKey'] ) ) {
			Logger::add( 'OAuth token exchange rejected: HTTP ' . $http . ' ' . wp_remote_retrieve_body( $response ), 'error' );
			self::redirect_with_notice( 'exchange_failed' );
		}

		$data = $body['data'];

		// Store the provisioned credentials.
		Settings::set_api_key( sanitize_text_field( $data['apiKey'] ) );
		// Report-side signing secret (merchant -> platform): used to sign
		// conversion reports sent to hooks.utm.coupons.
		if ( ! empty( $data['conversionSecret'] ) ) {
			Settings::set_hmac_secret( sanitize_text_field( $data['conversionSecret'] ) );
		}
		// Webhook signing secret (platform -> merchant): used to verify
		// inbound webhook deliveries from the platform.
		if ( ! empty( $data['webhookSecret'] ) ) {
			Settings::set_webhook_secret( sanitize_text_field( $data['webhookSecret'] ) );
		}
		if ( ! empty( $data['workspaceName'] ) ) {
			Settings::set( Settings::OPT_WORKSPACE, sanitize_text_field( $data['workspaceName'] ) );
		}
		if ( ! empty( $data['workspaceId'] ) ) {
			Settings::set( Settings::OPT_WORKSPACE_ID, sanitize_text_field( $data['workspaceId'] ) );
		}
		Settings::set( self::OPT_WEBHOOK_ID, sanitize_text_field( $data['webhookId'] ?? '' ) );
		Settings::set( Settings::OPT_STATUS, 'connected' );
		Settings::set( Settings::OPT_LAST_TEST, gmdate( 'Y-m-d H:i:s' ) );

		Logger::add(
			sprintf(
				'OAuth connected to %s (webhook %s).',
				$data['workspaceName'] ?? 'workspace',
				$data['webhookId'] ?? '?'
			),
			'success'
		);

		self::redirect_with_notice( 'connected' );
	}

	/**
	 * Backfill the workspace id for installs that connected before it was
	 * stored (or lost it).
	 *
	 * P2 verifies conversion reports against a per-workspace signing secret, so
	 * a report without a workspace id is only accepted when the coupon happens
	 * to exist on the platform. A store that connected with an older version
	 * has the right secret but no id, and every report would be rejected with
	 * 401 — asking every merchant to reconnect to fix that is not acceptable.
	 * The id is recoverable from the API key we already hold, so fetch it once.
	 *
	 * @return string The workspace id, or '' when it cannot be resolved.
	 */
	public static function ensure_workspace_id() {
		$workspace_id = Settings::get( Settings::OPT_WORKSPACE_ID, '' );
		if ( '' !== $workspace_id ) {
			return $workspace_id;
		}

		$api_key = Settings::get_api_key();
		if ( '' === $api_key ) {
			return ''; // Not connected — nothing to backfill from.
		}

		$response = wp_remote_get(
			UTM_COUPONS_API_BASE . '/api/me',
			array(
				'headers'  => array( 'Authorization' => 'Bearer ' . $api_key ),
				'timeout'  => 8,
				'blocking' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		if ( $http < 200 || $http >= 300 ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$id   = $body['data']['workspace']['id'] ?? '';
		if ( '' === $id ) {
			return '';
		}

		Settings::set( Settings::OPT_WORKSPACE_ID, sanitize_text_field( $id ) );
		return $id;
	}

	/**
	 * Disconnect: clear credentials and status.
	 */
	public static function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied' );
		}
		check_admin_referer( 'utm_oauth_disconnect' );

		Settings::set_api_key( '' );
		Settings::set_hmac_secret( '' );
		Settings::set_webhook_secret( '' );
		Settings::set( Settings::OPT_WORKSPACE, '' );
		Settings::set( Settings::OPT_WORKSPACE_ID, '' );
		Settings::set( self::OPT_WEBHOOK_ID, '' );
		Settings::set( Settings::OPT_STATUS, 'unconfigured' );

		Logger::add( 'OAuth connection disconnected.', 'warning' );
		self::redirect_with_notice( 'disconnected' );
	}

	private static function redirect_with_notice( $code ) {
		wp_safe_redirect( admin_url( 'admin.php?page=utm-coupons&oauth=' . $code ) );
		exit;
	}
}