<?php
/**
 * 301 redirects for articles that were redirected from the prune report.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the plugin's own redirects, so it works without another redirect
 * plugin. Each redirect is tied to the log entry that created it and is
 * removed when that entry is undone.
 */
class HWCH_Redirects {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
	}

	/**
	 * The path part of a URL, without a trailing slash, for matching.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	public static function path( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return strtolower( untrailingslashit( $path ? $path : '/' ) );
	}

	/**
	 * Adds a redirect.
	 *
	 * @param string $source  URL to redirect from.
	 * @param string $target  URL to redirect to.
	 * @param int    $post_id Article the redirect replaces.
	 * @return int Redirect ID.
	 */
	public static function add( $source, $target, $post_id ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			HWCH_Store::table( 'redirects' ),
			array(
				'source'     => self::path( $source ),
				'target'     => $target,
				'post_id'    => $post_id,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s' )
		);

		update_option( 'hwch_has_redirects', 1 );
		delete_transient( 'hwch_known_urls' );
		delete_transient( 'hwch_broken_internal' );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Removes a redirect.
	 *
	 * @param int $id Redirect ID.
	 * @return void
	 */
	public static function remove( $id ) {
		global $wpdb;
		$table = HWCH_Store::table( 'redirects' );

		$wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		delete_transient( 'hwch_broken_internal' );
		update_option( 'hwch_has_redirects', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ? 1 : 0 ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Sends a 301 if the requested path has a redirect.
	 *
	 * @return void
	 */
	public static function maybe_redirect() {
		global $wpdb;

		// Skip the lookup entirely until at least one redirect exists.
		if ( is_admin() || ! get_option( 'hwch_has_redirects' ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$path   = self::path( wp_unslash( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$target = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT target FROM ' . HWCH_Store::table( 'redirects' ) . ' WHERE source = %s ORDER BY id DESC LIMIT 1', $path ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		if ( $target ) {
			wp_redirect( $target, 301, 'Hostelworld Content Health' ); // phpcs:ignore WordPress.Security.SafeRedirect
			exit;
		}
	}
}
