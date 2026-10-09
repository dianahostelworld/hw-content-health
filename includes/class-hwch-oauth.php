<?php
/**
 * "Connect with Google": signs in with a person's own Google account, for
 * when a service account cannot be added to the Search Console property.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * OAuth 2.0 authorisation-code flow with offline access. The person signs in
 * once; the refresh token is stored and used to get access tokens for every
 * sync after that, including the weekly one that runs with nobody logged in.
 */
class HWCH_OAuth {

	const OPTION   = 'hwch_oauth';
	const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
	const SCOPES   = 'https://www.googleapis.com/auth/webmasters.readonly openid email';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_hwch_oauth_start', array( __CLASS__, 'start' ) );
		add_action( 'admin_post_hwch_oauth_callback', array( __CLASS__, 'callback' ) );
		add_action( 'admin_post_hwch_oauth_disconnect', array( __CLASS__, 'disconnect' ) );
	}

	/**
	 * The address Google sends people back to. It must be added, exactly, as
	 * an authorised redirect URI on the OAuth client in Google Cloud.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=hwch_oauth_callback' );
	}

	/**
	 * The connected account, if any.
	 *
	 * @return array|null array( refresh_token, email, connected_at ), or null.
	 */
	public static function connection() {
		$data = get_option( self::OPTION );
		return ! empty( $data['refresh_token'] ) ? $data : null;
	}

	/**
	 * Sends the person to Google to sign in.
	 *
	 * @return void
	 */
	public static function start() {
		check_admin_referer( 'hwch_oauth_start' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'hw-content-health' ), 403 );
		}

		$client_id = HWCH_Settings::get( 'client_id' );

		if ( ! $client_id || ! HWCH_Settings::get( 'client_secret' ) ) {
			self::back( 'oauth_no_client' );
		}

		// A one-time value tied to this person, checked on the way back so a
		// forged callback cannot connect someone else's account.
		$state = wp_generate_password( 32, false );
		set_transient( 'hwch_oauth_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );

		$url = add_query_arg(
			array(
				'client_id'              => rawurlencode( $client_id ),
				'redirect_uri'           => rawurlencode( self::redirect_uri() ),
				'response_type'          => 'code',
				'scope'                  => rawurlencode( self::SCOPES ),
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'true',
				'state'                  => $state,
			),
			self::AUTH_URL
		);

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/**
	 * Receives the code from Google and swaps it for tokens.
	 *
	 * @return void
	 */
	public static function callback() {
		// phpcs:disable WordPress.Security.NonceVerification -- the state value is the check here.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'hw-content-health' ), 403 );
		}

		$key      = 'hwch_oauth_state_' . get_current_user_id();
		$expected = get_transient( $key );
		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		delete_transient( $key );

		if ( ! $expected || ! hash_equals( $expected, $state ) ) {
			self::back( 'oauth_state' );
		}

		if ( ! empty( $_GET['error'] ) ) {
			self::back( 'oauth_denied' );
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable

		$body = HWCH_GSC::body(
			wp_remote_post(
				HWCH_GSC::TOKEN_URL,
				array(
					'timeout' => 20,
					'body'    => array(
						'code'          => $code,
						'client_id'     => HWCH_Settings::get( 'client_id' ),
						'client_secret' => HWCH_Settings::get( 'client_secret' ),
						'redirect_uri'  => self::redirect_uri(),
						'grant_type'    => 'authorization_code',
					),
				)
			)
		);

		if ( is_wp_error( $body ) || empty( $body['refresh_token'] ) ) {
			update_option( 'hwch_last_error', is_wp_error( $body ) ? $body->get_error_message() : __( 'Google did not return a refresh token. Remove the app from your Google account permissions and connect again.', 'hw-content-health' ), false );
			self::back( 'oauth_failed' );
		}

		update_option(
			self::OPTION,
			array(
				'refresh_token' => $body['refresh_token'],
				'email'         => self::email_from( isset( $body['id_token'] ) ? $body['id_token'] : '' ),
				'connected_at'  => time(),
			),
			false
		);

		if ( ! empty( $body['access_token'] ) ) {
			$lifetime = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
			set_transient( 'hwch_gsc_token', $body['access_token'], max( 60, $lifetime - 120 ) );
		}

		delete_option( 'hwch_last_error' );
		self::back( 'oauth_connected' );
	}

	/**
	 * Forgets the connected account and revokes its access at Google.
	 *
	 * @return void
	 */
	public static function disconnect() {
		check_admin_referer( 'hwch_oauth_disconnect' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'hw-content-health' ), 403 );
		}

		$connection = self::connection();

		if ( $connection ) {
			wp_remote_post( 'https://oauth2.googleapis.com/revoke', array( 'timeout' => 10, 'body' => array( 'token' => $connection['refresh_token'] ) ) );
		}

		delete_option( self::OPTION );
		delete_transient( 'hwch_gsc_token' );

		self::back( 'oauth_disconnected' );
	}

	/**
	 * A fresh access token from the stored refresh token.
	 *
	 * @return string|WP_Error
	 */
	public static function access_token() {
		$connection = self::connection();

		if ( ! $connection ) {
			return new WP_Error( 'hwch_not_connected', __( 'No Google account is connected. Go to Content health > Settings and click Connect with Google.', 'hw-content-health' ) );
		}

		$body = HWCH_GSC::body(
			wp_remote_post(
				HWCH_GSC::TOKEN_URL,
				array(
					'timeout' => 20,
					'body'    => array(
						'client_id'     => HWCH_Settings::get( 'client_id' ),
						'client_secret' => HWCH_Settings::get( 'client_secret' ),
						'refresh_token' => $connection['refresh_token'],
						'grant_type'    => 'refresh_token',
					),
				)
			)
		);

		if ( is_wp_error( $body ) ) {
			return new WP_Error(
				'hwch_refresh',
				sprintf(
					/* translators: %s: error from Google */
					__( 'The Google connection has stopped working (%s). Click Connect with Google in Settings to sign in again.', 'hw-content-health' ),
					$body->get_error_message()
				)
			);
		}

		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'hwch_token', __( 'Google did not return an access token.', 'hw-content-health' ) );
		}

		$lifetime = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
		set_transient( 'hwch_gsc_token', $body['access_token'], max( 60, $lifetime - 120 ) );

		return $body['access_token'];
	}

	/**
	 * The email address from the ID token Google returns with the tokens, to
	 * show which account is connected. It came straight from Google over TLS,
	 * so it is read without verifying the signature.
	 *
	 * @param string $id_token JWT.
	 * @return string
	 */
	private static function email_from( $id_token ) {
		$parts = explode( '.', (string) $id_token );

		if ( count( $parts ) < 2 ) {
			return '';
		}

		$claims = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		return isset( $claims['email'] ) ? sanitize_email( $claims['email'] ) : '';
	}

	/**
	 * Back to the Settings tab with a message.
	 *
	 * @param string $message Message key.
	 * @return void
	 */
	private static function back( $message ) {
		wp_safe_redirect( admin_url( 'admin.php?page=hwch&tab=settings&hwch_msg=' . $message ) );
		exit;
	}
}
