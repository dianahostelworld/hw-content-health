<?php
/**
 * Broken links and 404s: internal links to redirected, unpublished or deleted
 * pages, outbound links that no longer work, a log of 404s on the blog, and
 * redirect chains.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds links that no longer lead where they should.
 */
class HWCH_Broken {

	/**
	 * Parts of a path that are never an article, so their 404s are not logged.
	 */
	const JUNK = '#(^|/)(wp-admin|wp-login|wp-content|wp-includes|xmlrpc|cgi-bin|\.well-known|\.git|\.env)|\.(php|js|css|map|png|jpe?g|gif|svg|webp|ico|xml|txt|json|bak|zip|sql|woff2?|ttf|mp4)$#i';

	/**
	 * Statuses that mean a site refused an automated check rather than that
	 * the page is gone.
	 *
	 * @var int[]
	 */
	private static $blocked = array( 401, 403, 405, 406, 429, 999 );

	/**
	 * Sites that answer automated checks with an error even when the page
	 * exists, so their errors are listed as "could not check".
	 *
	 * @var string[]
	 */
	private static $bot_hostile = array( 'linkedin.com', 'instagram.com', 'facebook.com', 'x.com', 'twitter.com', 'tiktok.com', 'threads.net', 'pinterest.com' );

	/**
	 * Whether a URL is on a site that refuses automated checks.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function hostile( $url ) {
		$host = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );

		foreach ( self::$bot_hostile as $domain ) {
			if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'log_404' ), 99 );
		add_action( 'hwch_hourly', array( __CLASS__, 'hourly' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'forget' ), 10, 3 );
	}

	/**
	 * Forgets the list of live pages when an article or page is published or
	 * unpublished, so a merge or prune shows up in the report straight away.
	 *
	 * @param string  $new  New status.
	 * @param string  $old  Old status.
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function forget( $new, $old, $post ) {
		if ( $new !== $old && ( 'publish' === $new || 'publish' === $old ) && in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			delete_transient( 'hwch_known_urls' );
			delete_transient( 'hwch_broken_internal' );
			delete_transient( 'hwch_lang_folders' );
		}
	}

	/**
	 * The site's scheme and host (and port), with no path: REQUEST_URI paths
	 * already include the blog folder, so home_url() would add it twice.
	 *
	 * @param string $path Path to add.
	 * @return string
	 */
	public static function origin( $path = '' ) {
		$home = wp_parse_url( home_url( '/' ) );

		return ( isset( $home['scheme'] ) ? $home['scheme'] : 'https' ) . '://' . ( isset( $home['host'] ) ? $home['host'] : '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $path;
	}

	/**
	 * Counts a 404 on the blog. Runs after the plugin's own redirects, so an
	 * address that has been redirected is never logged.
	 *
	 * @return void
	 */
	public static function log_404() {
		global $wpdb;

		if ( ! is_404() || ! HWCH_Settings::get( 'log_404' ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$path   = HWCH_Redirects::path( wp_unslash( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$prefix = HWCH_Redirects::path( (string) HWCH_Settings::get( 'url_prefix' ) );

		if ( strlen( $path ) > 400 || preg_match( self::JUNK, $path ) || ( '/' !== $prefix && 0 !== strpos( $path . '/', $prefix . '/' ) ) ) {
			return;
		}

		$table    = HWCH_Store::table( 'notfound' );
		$hash     = md5( $path );
		$now      = current_time( 'mysql', true );
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 500 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$id       = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE path_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB

		if ( $id ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$referrer
					? $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_seen = %s, referrer = %s WHERE id = %d", $now, $referrer, $id ) // phpcs:ignore WordPress.DB.PreparedSQL
					: $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_seen = %s WHERE id = %d", $now, $id ) // phpcs:ignore WordPress.DB.PreparedSQL
			);
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'path_hash'  => $hash,
				'path'       => $path,
				'hits'       => 1,
				'referrer'   => $referrer,
				'first_seen' => $now,
				'last_seen'  => $now,
				'ignored'    => 0,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * The hourly job: checks a batch of outbound links and keeps the 404 log
	 * to a sensible size.
	 *
	 * @return void
	 */
	public static function hourly() {
		global $wpdb;

		self::check_batch();

		$table = HWCH_Store::table( 'notfound' );

		// Addresses hit once or twice and not since are noise from bots.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE hits < 3 AND last_seen < %s AND ignored = 0", gmdate( 'Y-m-d H:i:s', strtotime( '-60 days' ) ) ) ); // phpcs:ignore WordPress.DB

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB

		if ( $count > 5000 ) {
			$keep = $wpdb->get_var( "SELECT hits FROM {$table} ORDER BY hits DESC LIMIT 4999, 1" ); // phpcs:ignore WordPress.DB
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE hits < %d AND ignored = 0", (int) $keep ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Every link written in the content of English articles, split into
	 * internal (on the blog) and outbound (anywhere else, including the rest
	 * of hostelworld.com). Recounted hourly.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array internal and outbound: map of URL => source post IDs.
	 */
	public static function scan( $refresh = false ) {
		global $wpdb;

		$cached = $refresh ? false : get_transient( 'hwch_broken_scan' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$ids    = HWCH_Reports::english_ids( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'" ) ); // phpcs:ignore WordPress.DB
		$home   = wp_parse_url( home_url( '/' ) );
		$host   = isset( $home['host'] ) ? preg_replace( '/^www\./', '', strtolower( $home['host'] ) ) : '';
		$base   = self::origin();
		$prefix = HWCH_GSC::normalise( HWCH_Settings::get( 'url_prefix' ) );
		$out    = array( 'internal' => array(), 'outbound' => array() );

		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$rows = $wpdb->get_results( 'SELECT ID, post_content FROM ' . $wpdb->posts . ' WHERE ID IN (' . implode( ',', array_map( 'intval', $chunk ) ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB

			foreach ( (array) $rows as $row ) {
				if ( ! preg_match_all( '/<a\s[^>]*href\s*=\s*["\']([^"\']+)["\']/i', (string) $row['post_content'], $m ) ) {
					continue;
				}

				foreach ( array_unique( $m[1] ) as $href ) {
					$href = trim( html_entity_decode( $href ) );

					if ( '' === $href || preg_match( '/^(#|mailto:|tel:|javascript:|sms:|data:)/i', $href ) ) {
						continue;
					}

					if ( 0 === strpos( $href, '//' ) ) {
						$href = 'https:' . $href;
					} elseif ( 0 === strpos( $href, '/' ) ) {
						$href = $base . $href;
					} elseif ( ! preg_match( '#^https?://#i', $href ) ) {
						continue; // A relative link without a leading slash: too rare to guess.
					}

					$href = strtok( $href, '#' );
					$link = wp_parse_url( $href );

					if ( empty( $link['host'] ) ) {
						continue;
					}

					$same = preg_replace( '/^www\./', '', strtolower( $link['host'] ) ) === $host;
					$norm = HWCH_GSC::normalise( $href );
					$kind = $same && $prefix && 0 === strpos( $norm . '/', $prefix . '/' ) ? 'internal' : 'outbound';

					if ( 'internal' === $kind ) {
						// Keyed by the simplified address, but the address as written
						// is kept: the simplified form drops www and cannot be looked up.
						if ( ! isset( $out['internal'][ $norm ] ) ) {
							$out['internal'][ $norm ] = array( 'url' => $href, 'sources' => array() );
						}
						$out['internal'][ $norm ]['sources'][] = (int) $row['ID'];
					} else {
						$out['outbound'][ $href ][] = (int) $row['ID'];
					}
				}
			}

			unset( $rows );
		}

		set_transient( 'hwch_broken_scan', $out, HOUR_IN_SECONDS );

		return $out;
	}

	/**
	 * Every published post and page, as normalised URLs.
	 *
	 * @return array Map of normalised URL => post ID.
	 */
	private static function known_urls() {
		global $wpdb;

		$cached = get_transient( 'hwch_known_urls' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$map = array();

		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status = 'publish'" ) as $id ) { // phpcs:ignore WordPress.DB
			$map[ HWCH_GSC::normalise( get_permalink( (int) $id ) ) ] = (int) $id;
		}

		set_transient( 'hwch_known_urls', $map, HOUR_IN_SECONDS );

		return $map;
	}

	/**
	 * Where a redirected address finally lands, following the plugin's
	 * redirects up to five steps.
	 *
	 * @param string $path Path.
	 * @return array|null final (URL), steps, or null if not redirected.
	 */
	public static function follow( $path ) {
		$map   = self::redirect_map();
		$path  = HWCH_Redirects::path( $path );
		$steps = 0;
		$url   = null;
		$seen  = array();

		while ( isset( $map[ $path ] ) && $steps < 5 && ! isset( $seen[ $path ] ) ) {
			$seen[ $path ] = true;
			$url           = $map[ $path ]['target'];
			$path          = HWCH_Redirects::path( $url );
			++$steps;
		}

		return null === $url ? null : array( 'final' => $url, 'steps' => $steps );
	}

	/**
	 * The plugin's redirects, newest winning, by source path.
	 *
	 * @return array Map of path => row.
	 */
	private static function redirect_map() {
		global $wpdb;
		static $map = null;

		if ( null === $map ) {
			$map = array();

			foreach ( (array) $wpdb->get_results( 'SELECT id, source, target, post_id FROM ' . HWCH_Store::table( 'redirects' ) . ' ORDER BY id', ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
				$map[ $row['source'] ] = $row;
			}
		}

		return $map;
	}

	/**
	 * If a path is a tag or category archive, the term's taxonomy and slug.
	 *
	 * @param string $path Path.
	 * @return array|null taxonomy, slug.
	 */
	private static function archive( $path ) {
		// Matched at the end of the path, so it works whether /blog is the
		// WordPress folder or part of the permalink pattern, and with a
		// language folder or /page/2 around it.
		foreach ( array( 'post_tag' => get_option( 'tag_base' ) ? get_option( 'tag_base' ) : 'tag', 'category' => get_option( 'category_base' ) ? get_option( 'category_base' ) : 'category' ) as $taxonomy => $slug_base ) {
			if ( preg_match( '#/' . preg_quote( trim( $slug_base, '/' ), '#' ) . '/([^/]+)(?:/page/\d+)?/?$#', $path, $m ) ) {
				return array( 'taxonomy' => $taxonomy, 'slug' => sanitize_title( rawurldecode( $m[1] ) ) );
			}
		}

		return null;
	}

	/**
	 * Internal links that lead nowhere useful, grouped by the address.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array List of array( url, problem, final, sources, status ).
	 */
	public static function internal( $refresh = false ) {
		global $wpdb;

		$cached = $refresh ? false : get_transient( 'hwch_broken_internal' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$scan  = self::scan( $refresh );
		$known = self::known_urls();
		$out   = array();
		$check = array();

		// Every article's address ending and status in one query, instead of
		// asking WordPress to resolve each link separately (far too slow at
		// tens of thousands of links).
		$slugs = array();

		foreach ( (array) $wpdb->get_results( "SELECT ID, post_name, post_status FROM {$wpdb->posts} WHERE post_type = 'post' AND post_name <> '' AND post_status NOT IN ('auto-draft','inherit')", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
			$slugs[ $row['post_name'] ] = array( (int) $row['ID'], $row['post_status'] );
		}

		foreach ( $scan['internal'] as $norm => $link ) {
			if ( isset( $known[ $norm ] ) ) {
				continue;
			}

			$url     = $link['url'];
			$sources = $link['sources'];
			$path = HWCH_Redirects::path( $url );
			$row  = array( 'url' => $url, 'final' => '', 'status' => '', 'sources' => array_values( array_unique( $sources ) ) );

			$redirect = self::follow( $path );

			if ( $redirect ) {
				$final_id     = url_to_postid( $redirect['final'] );
				$row['final'] = $redirect['final'];
				$row['problem'] = $final_id && 'publish' !== get_post_status( $final_id ) ? 'dead_end' : 'redirected';
				$row['steps']   = $redirect['steps'];
				$out[]          = $row;
				continue;
			}

			$slug = sanitize_title( rawurldecode( basename( $path ) ) );

			if ( isset( $slugs[ $slug ] ) && 'publish' !== $slugs[ $slug ][1] ) {
				$row['problem'] = 'unpublished';
				$row['status']  = $slugs[ $slug ][1];
				$row['post_id'] = $slugs[ $slug ][0];
				$out[]          = $row;
				continue;
			}

			$archive = self::archive( $path );

			if ( $archive ) {
				if ( ! get_term_by( 'slug', $archive['slug'], $archive['taxonomy'] ) ) {
					$row['problem'] = 'term';
					$row['final']   = self::hub_for( $archive['slug'] );
					$out[]          = $row;
				}
				continue;
			}

			// Anything else on the blog (hub pages, the home page, search…) is
			// checked over HTTP with the outbound links.
			$check[] = $url;
		}

		self::queue( $check );

		foreach ( self::results( $check ) as $url => $result ) {
			if ( self::is_broken( $result ) ) {
				$out[] = array(
					'url'     => $url,
					'problem' => 'missing',
					'final'   => '',
					'status'  => $result['status'] ? (string) $result['status'] : $result['error'],
					'sources' => array_values( array_unique( $scan['internal'][ HWCH_GSC::normalise( $url ) ]['sources'] ) ),
				);
			}
		}

		usort(
			$out,
			function ( $a, $b ) {
				return count( $b['sources'] ) <=> count( $a['sources'] );
			}
		);

		set_transient( 'hwch_broken_internal', $out, HOUR_IN_SECONDS );

		return $out;
	}

	/**
	 * The destination hub page for a tag, when the hubs use it.
	 *
	 * @param string $slug Tag slug.
	 * @return string URL, or ''.
	 */
	public static function hub_for( $slug ) {
		global $wpdb;

		$keys = array();

		foreach ( array( 'hw_hub_', '_hw_hub_' ) as $base ) {
			foreach ( array( '', 'country_', 'state_', 'city_' ) as $template ) {
				$keys[] = $base . $template . 'hub_term';
			}
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish'
				WHERE pm.meta_key IN ('" . implode( "','", array_map( 'esc_sql', $keys ) ) . "') AND pm.meta_value LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL
				'%' . $wpdb->esc_like( $slug ) . '%'
			),
			ARRAY_A
		);

		// The English hub: translated hubs carry the same destination.
		$fallback = '';

		foreach ( (array) $rows as $row ) {
			$slugs = array_map( 'sanitize_title', preg_split( '/[+,|\s]+/', strtolower( (string) $row['meta_value'] ), -1, PREG_SPLIT_NO_EMPTY ) );

			if ( ! in_array( $slug, $slugs, true ) ) {
				continue;
			}

			if ( HWCH_Lang::is_english_post( (int) $row['post_id'] ) ) {
				return (string) get_permalink( (int) $row['post_id'] );
			}

			$fallback = $fallback ? $fallback : (string) get_permalink( (int) $row['post_id'] );
		}

		return $fallback;
	}

	/**
	 * Adds URLs to the outbound check queue if they are not there yet.
	 *
	 * @param string[] $urls URLs.
	 * @return void
	 */
	private static function queue( $urls ) {
		global $wpdb;

		$table = HWCH_Store::table( 'linkcheck' );
		$have  = array();

		foreach ( array_chunk( array_values( array_unique( $urls ) ), 500 ) as $chunk ) {
			$hashes = array_map( 'md5', $chunk );
			$have   = array_merge( $have, $wpdb->get_col( "SELECT url_hash FROM {$table} WHERE url_hash IN ('" . implode( "','", $hashes ) . "')" ) ); // phpcs:ignore WordPress.DB
		}

		$have = array_flip( $have );

		$new = array();

		foreach ( array_unique( $urls ) as $url ) {
			if ( ! isset( $have[ md5( $url ) ] ) && strlen( $url ) <= 1000 ) {
				$new[] = $wpdb->prepare( '(%s, %s, 0, %s)', md5( $url ), $url, '' );
			}
		}

		foreach ( array_chunk( $new, 400 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$table} (url_hash, url, status, error) VALUES " . implode( ',', $chunk ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Stored check results for URLs that have been checked.
	 *
	 * @param string[] $urls URLs.
	 * @return array Map of URL => array( status, error, checked_at ).
	 */
	private static function results( $urls ) {
		global $wpdb;

		$table = HWCH_Store::table( 'linkcheck' );
		$out   = array();

		foreach ( array_chunk( array_values( array_unique( $urls ) ), 500 ) as $chunk ) {
			$rows = $wpdb->get_results( "SELECT url, status, error, checked_at FROM {$table} WHERE checked_at IS NOT NULL AND url_hash IN ('" . implode( "','", array_map( 'md5', $chunk ) ) . "')", ARRAY_A ); // phpcs:ignore WordPress.DB

			foreach ( (array) $rows as $row ) {
				$out[ $row['url'] ] = array( 'status' => (int) $row['status'], 'error' => $row['error'], 'checked_at' => $row['checked_at'] );
			}
		}

		return $out;
	}

	/**
	 * Whether a check result means the page is gone or failing.
	 *
	 * @param array $result Result.
	 * @return bool
	 */
	private static function is_broken( $result ) {
		$status = (int) $result['status'];

		if ( ! $status ) {
			return '' !== $result['error'];
		}

		return ( $status >= 400 && ! in_array( $status, self::$blocked, true ) ) || $status >= 500;
	}

	/**
	 * Checks the next batch of queued links: never checked first, then those
	 * not checked for the recheck period. A few at a time, in parallel.
	 *
	 * @param int $limit How many.
	 * @return int How many were checked.
	 */
	public static function check_batch( $limit = 60 ) {
		global $wpdb;

		// Queue whatever the latest scan found.
		$scan = self::scan();
		self::queue( array_keys( $scan['outbound'] ) );

		$table = HWCH_Store::table( 'linkcheck' );
		$stale = gmdate( 'Y-m-d H:i:s', strtotime( '-' . (int) HWCH_Settings::get( 'link_recheck' ) . ' days' ) );
		$urls  = $wpdb->get_col( $wpdb->prepare( "SELECT url FROM {$table} WHERE checked_at IS NULL OR checked_at < %s ORDER BY checked_at IS NOT NULL, checked_at LIMIT %d", $stale, $limit ) ); // phpcs:ignore WordPress.DB

		if ( ! $urls ) {
			return 0;
		}

		foreach ( array_chunk( $urls, 10 ) as $chunk ) {
			foreach ( self::request( $chunk ) as $url => $result ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array( 'status' => $result['status'], 'error' => substr( $result['error'], 0, 190 ), 'checked_at' => current_time( 'mysql', true ) ),
					array( 'url_hash' => md5( $url ) ),
					array( '%d', '%s', '%s' ),
					array( '%s' )
				);
			}
		}

		update_option( 'hwch_last_linkcheck', time(), false );

		return count( $urls );
	}

	/**
	 * Requests a few URLs in parallel: a HEAD request first, then a GET for
	 * sites that do not answer HEAD properly.
	 *
	 * @param string[] $urls URLs.
	 * @return array Map of URL => array( status, error ).
	 */
	private static function request( $urls ) {
		$options = array(
			'timeout'          => 8,
			'connect_timeout'  => 5,
			'follow_redirects' => true,
			'redirects'        => 5,
			'useragent'        => 'Mozilla/5.0 (compatible; HostelworldContentHealth/' . HWCH_VERSION . '; +' . home_url( '/' ) . ')',
			'verify'           => true,
		);

		$class = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : ( class_exists( 'Requests' ) ? 'Requests' : '' );
		$out   = array();

		foreach ( array( 'HEAD', 'GET' ) as $method ) {
			$todo = array();

			foreach ( $urls as $url ) {
				if ( ! isset( $out[ $url ] ) || ( 'GET' === $method && ( ! $out[ $url ]['status'] || in_array( $out[ $url ]['status'], array( 400, 403, 404, 405, 501 ), true ) ) ) ) {
					$todo[] = $url;
				}
			}

			if ( ! $todo ) {
				break;
			}

			if ( $class ) {
				$requests = array();

				foreach ( $todo as $i => $url ) {
					$requests[ $i ] = array( 'url' => $url, 'type' => $method, 'headers' => array( 'Accept' => 'text/html,*/*' ) );
				}

				try {
					$responses = call_user_func( array( $class, 'request_multiple' ), $requests, $options );
				} catch ( Throwable $e ) {
					$responses = array();
				}

				foreach ( $todo as $i => $url ) {
					$response = isset( $responses[ $i ] ) ? $responses[ $i ] : null;

					if ( is_object( $response ) && isset( $response->status_code ) && ! ( $response instanceof Exception ) ) {
						$out[ $url ] = array( 'status' => (int) $response->status_code, 'error' => '' );
					} else {
						$out[ $url ] = array( 'status' => 0, 'error' => $response instanceof Exception ? $response->getMessage() : __( 'No response', 'hw-content-health' ) );
					}
				}
			} else {
				foreach ( $todo as $url ) {
					$response    = wp_remote_request( $url, array( 'method' => $method, 'timeout' => 8, 'redirection' => 5, 'user-agent' => $options['useragent'] ) );
					$out[ $url ] = is_wp_error( $response ) ? array( 'status' => 0, 'error' => $response->get_error_message() ) : array( 'status' => (int) wp_remote_retrieve_response_code( $response ), 'error' => '' );
				}
			}
		}

		return $out;
	}

	/**
	 * Outbound links that failed their last check, and those the site blocked.
	 *
	 * @return array broken, blocked (lists of array( url, status, error, checked_at, sources )), checked, total.
	 */
	public static function outbound() {
		$scan    = self::scan();
		$results = self::results( array_keys( $scan['outbound'] ) );
		$broken  = array();
		$blocked = array();

		foreach ( $results as $url => $result ) {
			$item = $result + array( 'url' => $url, 'sources' => array_values( array_unique( $scan['outbound'][ $url ] ) ) );

			if ( self::is_broken( $result ) && ! ( self::hostile( $url ) && $result['status'] >= 400 && $result['status'] < 500 ) ) {
				$broken[] = $item;
			} elseif ( in_array( $result['status'], self::$blocked, true ) || ( self::hostile( $url ) && $result['status'] >= 400 ) ) {
				$blocked[] = $item;
			}
		}

		$sort = function ( $a, $b ) {
			return count( $b['sources'] ) <=> count( $a['sources'] );
		};
		usort( $broken, $sort );
		usort( $blocked, $sort );

		return array(
			'broken'  => $broken,
			'blocked' => $blocked,
			'checked' => count( $results ),
			'total'   => count( $scan['outbound'] ),
		);
	}

	/**
	 * Logged 404s still worth acting on, most hits first.
	 *
	 * @return array
	 */
	public static function not_found() {
		global $wpdb;

		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . HWCH_Store::table( 'notfound' ) . ' WHERE ignored = 0 ORDER BY hits DESC, last_seen DESC LIMIT 500', ARRAY_A ); // phpcs:ignore WordPress.DB

		// Drop any that now work: redirected since, or published again.
		$map = self::redirect_map();

		return array_values(
			array_filter(
				$rows,
				function ( $row ) use ( $map ) {
					return ! isset( $map[ $row['path'] ] ) && ! url_to_postid( HWCH_Broken::origin( $row['path'] ) );
				}
			)
		);
	}

	/**
	 * The best guess at where a 404 address should go.
	 *
	 * @param string $path Path.
	 * @return array url, why. Empty url when there is no good guess.
	 */
	public static function suggest( $path ) {
		global $wpdb;

		$archive = self::archive( $path );

		if ( $archive ) {
			$hub = self::hub_for( $archive['slug'] );

			if ( $hub ) {
				return array( 'url' => $hub, 'why' => __( 'The destination hub for this tag', 'hw-content-health' ) );
			}

			return array( 'url' => '', 'why' => __( 'A removed tag or category page with no matching hub', 'hw-content-health' ) );
		}

		$slug = sanitize_title( rawurldecode( basename( $path ) ) );

		if ( '' === $slug ) {
			return array( 'url' => '', 'why' => '' );
		}

		$same = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN ('post','page') AND post_status = 'publish' LIMIT 1", $slug ) ); // phpcs:ignore WordPress.DB

		if ( $same ) {
			return array( 'url' => get_permalink( (int) $same ), 'why' => __( 'An article with the same address ending', 'hw-content-health' ) );
		}

		// The closest article by the words in its address.
		$want = HWCH_Overlap::tokens( str_replace( '-', ' ', $slug ) );

		if ( count( $want ) < 2 ) {
			return array( 'url' => '', 'why' => '' );
		}

		$best       = 0;
		$best_score = 0;

		foreach ( self::slugs() as $id => $tokens ) {
			$score = HWCH_Overlap::similarity( $want, $tokens );

			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $id;
			}
		}

		if ( $best && $best_score >= 0.5 ) {
			/* translators: %s: percentage */
			return array( 'url' => get_permalink( $best ), 'why' => sprintf( __( 'The closest article: %s%% of the words in its address match', 'hw-content-health' ), number_format_i18n( round( $best_score * 100 ) ) ) );
		}

		return array( 'url' => '', 'why' => '' );
	}

	/**
	 * The words in each published English article's address.
	 *
	 * @return array Map of post ID => words.
	 */
	private static function slugs() {
		global $wpdb;
		static $map = null;

		if ( null === $map ) {
			$map  = array();
			$rows = $wpdb->get_results( "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'", ARRAY_A ); // phpcs:ignore WordPress.DB
			$keep = array_flip( HWCH_Reports::english_ids( wp_list_pluck( (array) $rows, 'ID' ) ) );

			foreach ( (array) $rows as $row ) {
				if ( isset( $keep[ (int) $row['ID'] ] ) ) {
					$map[ (int) $row['ID'] ] = HWCH_Overlap::tokens( str_replace( '-', ' ', $row['post_name'] ) );
				}
			}
		}

		return $map;
	}

	/**
	 * The plugin's redirects that go through another redirect before landing,
	 * or land on an article that is no longer published.
	 *
	 * @return array List of array( id, source, target, final, steps, problem ).
	 */
	public static function chains() {
		$out = array();

		foreach ( self::redirect_map() as $source => $row ) {
			$next = HWCH_Redirects::path( $row['target'] );
			$map  = self::redirect_map();

			if ( isset( $map[ $next ] ) ) {
				$follow = self::follow( $source );
				$out[]  = array(
					'id'      => (int) $row['id'],
					'source'  => $source,
					'target'  => $row['target'],
					'final'   => $follow ? $follow['final'] : $row['target'],
					'steps'   => $follow ? $follow['steps'] : 1,
					'problem' => 'chain',
				);
				continue;
			}

			$target_id = url_to_postid( $row['target'] );

			if ( $target_id && 'publish' !== get_post_status( $target_id ) ) {
				$out[] = array(
					'id'      => (int) $row['id'],
					'source'  => $source,
					'target'  => $row['target'],
					'final'   => '',
					'steps'   => 1,
					'problem' => 'dead_end',
				);
			}
		}

		return $out;
	}

	/**
	 * Replaces every link to one address in an article's content with
	 * another, whatever form the old link takes (with or without www, a
	 * trailing slash or the domain).
	 *
	 * @param string $content Content.
	 * @param string $old     Address linked now.
	 * @param string $new     Address to link instead.
	 * @return array content, count.
	 */
	public static function replace_link( $content, $old, $new ) {
		$base  = self::origin();
		$want  = HWCH_GSC::normalise( $old );
		$count = 0;

		$content = preg_replace_callback(
			'/(<a\s[^>]*href\s*=\s*)(["\'])([^"\']+)\2/i',
			function ( $m ) use ( $want, $new, $base, &$count ) {
				$href = html_entity_decode( $m[3] );
				$hash = '';

				if ( false !== strpos( $href, '#' ) ) {
					$hash = substr( $href, strpos( $href, '#' ) );
					$href = substr( $href, 0, strpos( $href, '#' ) );
				}

				$full = 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ? $base . $href : $href;

				if ( HWCH_GSC::normalise( $full ) !== $want ) {
					return $m[0];
				}

				++$count;

				return $m[1] . $m[2] . esc_url( $new ) . $hash . $m[2];
			},
			(string) $content
		);

		return array( 'content' => $content, 'count' => $count );
	}

	/**
	 * Remove every link to an address from content, keeping the linked words.
	 *
	 * @param string $content Content.
	 * @param string $old     Address to unlink.
	 * @return array content, count.
	 */
	public static function unlink( $content, $old ) {
		$base  = self::origin();
		$want  = HWCH_GSC::normalise( $old );
		$count = 0;

		$content = preg_replace_callback(
			'/<a\s[^>]*?href\s*=\s*(["\'])([^"\']+)\1[^>]*>(.*?)<\/a>/is',
			function ( $m ) use ( $want, $base, &$count ) {
				$href = html_entity_decode( $m[2] );
				$href = false !== strpos( $href, '#' ) ? substr( $href, 0, strpos( $href, '#' ) ) : $href;
				$full = 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ? $base . $href : $href;

				if ( '' === $href || HWCH_GSC::normalise( $full ) !== $want ) {
					return $m[0];
				}

				++$count;

				return $m[3];
			},
			(string) $content
		);

		return array( 'content' => $content, 'count' => $count );
	}

	/**
	 * Broken links that can be removed: internal links that lead nowhere (not
	 * ones that only redirect) and outbound links that failed their check.
	 * Sites that block checks are never included.
	 *
	 * @param string $view internal or outbound.
	 * @return array[] url, sources.
	 */
	public static function removable( $view ) {
		if ( 'outbound' === $view ) {
			$rows = self::outbound()['broken'];
		} else {
			$rows = array_filter(
				self::internal(),
				function ( $r ) {
					return 'redirected' !== $r['problem'];
				}
			);
		}

		return array_values(
			array_map(
				function ( $r ) {
					return array( 'url' => $r['url'], 'sources' => array_map( 'intval', (array) $r['sources'] ) );
				},
				$rows
			)
		);
	}

	/**
	 * The "Remove all broken links" preview.
	 *
	 * @param string $view internal or outbound.
	 * @return void
	 */
	private static function remove_all_preview( $view ) {
		$rows     = self::removable( $view );
		$articles = array();

		foreach ( $rows as $r ) {
			foreach ( $r['sources'] as $id ) {
				$articles[ $id ] = true;
			}
		}

		$back = admin_url( 'admin.php?page=hwch&tab=broken&view=' . $view );
		?>
		<div class="hwch-confirm">
			<h3><?php esc_html_e( 'Remove all broken links?', 'hw-content-health' ); ?></h3>
			<p>
				<?php
				/* translators: 1: links, 2: articles */
				echo esc_html( sprintf( __( 'Broken addresses to unlink: %1$s. Articles that will change: %2$s. The linked words stay in each article; only the link goes. Every article\'s previous text is kept in the Log, so each one can be undone.', 'hw-content-health' ), number_format_i18n( count( $rows ) ), number_format_i18n( count( $articles ) ) ) );
				?>
			</p>
			<?php if ( 'internal' === $view ) : ?>
				<p class="hwch-muted"><?php esc_html_e( 'Links that only redirect are left alone: they still work, and "Update link" points them at the final page.', 'hw-content-health' ); ?></p>
			<?php else : ?>
				<p class="hwch-muted"><?php esc_html_e( 'Links to sites that block automated checks are left alone. A server error can be temporary, so check the list below first.', 'hw-content-health' ); ?></p>
			<?php endif; ?>
			<?php if ( $rows ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hwch-confirm-row">
					<?php wp_nonce_field( 'hwch_action' ); ?>
					<input type="hidden" name="action" value="hwch_remove_links">
					<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
					<button class="button button-primary"><?php esc_html_e( 'Remove them all', 'hw-content-health' ); ?></button>
					<a class="button" href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Cancel', 'hw-content-health' ); ?></a>
				</form>
			<?php else : ?>
				<a class="button" href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Back', 'hw-content-health' ); ?></a>
			<?php endif; ?>
			<ul class="hwch-list">
				<?php foreach ( array_slice( $rows, 0, 200 ) as $r ) : ?>
					<?php /* translators: %s: number */ ?>
					<li class="hwch-url"><?php echo esc_html( rawurldecode( $r['url'] ) ); ?> <span class="hwch-muted"><?php echo esc_html( sprintf( _n( 'in %s article', 'in %s articles', count( $r['sources'] ), 'hw-content-health' ), number_format_i18n( count( $r['sources'] ) ) ) ); ?></span></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * The Remove link and Remove all buttons.
	 *
	 * @param string $url  Link, or '' for the Remove all button.
	 * @param string $view internal or outbound.
	 * @return void
	 */
	private static function remove_button( $url, $view ) {
		if ( '' === $url ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=hwch&tab=broken&view=' . $view . '&remove_all=1' ) ) . '">' . esc_html__( 'Remove all broken links', 'hw-content-health' ) . '</a>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this link from every article it is in? The words stay; only the link goes.', 'hw-content-health' ) ); ?>');">
			<?php wp_nonce_field( 'hwch_action' ); ?>
			<input type="hidden" name="action" value="hwch_remove_links">
			<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
			<input type="hidden" name="url" value="<?php echo esc_attr( $url ); ?>">
			<button class="button button-small"><?php esc_html_e( 'Remove link', 'hw-content-health' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Renders the tab.
	 *
	 * @return void
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification
		$view    = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'internal';
		$view    = in_array( $view, array( 'internal', 'outbound', 'notfound', 'chains' ), true ) ? $view : 'internal';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$refresh = isset( $_GET['refresh'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['refresh'] ) ), 'hwch_refresh' );
		// phpcs:enable

		$base   = admin_url( 'admin.php?page=hwch&tab=broken' );
		$labels = array(
			'internal' => __( 'Internal links', 'hw-content-health' ),
			'outbound' => __( 'Outbound links', 'hw-content-health' ),
			'notfound' => __( '404s', 'hw-content-health' ),
			'chains'   => __( 'Redirect chains', 'hw-content-health' ),
		);
		?>
		<ul class="subsubsub hwch-filter">
			<?php $i = 0; ?>
			<?php foreach ( $labels as $key => $label ) : ?>
				<li><a href="<?php echo esc_url( add_query_arg( 'view', $key, $base ) ); ?>" class="<?php echo $view === $key ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a><?php echo ++$i < count( $labels ) ? ' |' : ''; ?></li>
			<?php endforeach; ?>
		</ul>
		<div class="clear"></div>
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( ! empty( $_GET['remove_all'] ) && in_array( $view, array( 'internal', 'outbound' ), true ) ) {
			self::remove_all_preview( $view );
		} elseif ( 'internal' === $view ) {
			self::view_internal( self::internal( $refresh ), $paged );
		} elseif ( 'outbound' === $view ) {
			self::view_outbound( $paged );
		} elseif ( 'notfound' === $view ) {
			self::view_notfound( $paged );
		} else {
			self::view_chains();
		}
	}

	/**
	 * The articles a link appears in.
	 *
	 * @param int[]  $sources Post IDs.
	 * @param string $old     The link, when it can be updated in place.
	 * @param string $new     Where it should point instead.
	 * @return void
	 */
	private static function sources( $sources, $old = '', $new = '' ) {
		echo '<ul class="hwch-list">';

		foreach ( array_slice( $sources, 0, 10 ) as $source ) {
			echo '<li><a href="' . esc_url( get_edit_post_link( $source ) ) . '">' . esc_html( get_the_title( $source ) ) . '</a>';

			if ( $old && $new && HWCH_GSC::normalise( $new ) === HWCH_GSC::normalise( get_permalink( $source ) ) ) {
				echo ' <span class="hwch-muted">' . esc_html__( 'now leads back to this article: remove the link', 'hw-content-health' ) . '</span>';
			} elseif ( $old && $new ) {
				HWCH_Admin::button( 'fix_link', $source, __( 'Update link', 'hw-content-health' ), array( 'old' => $old, 'new' => $new ) );
			}

			echo '</li>';
		}

		if ( count( $sources ) > 10 ) {
			/* translators: %s: number */
			echo '<li class="hwch-muted">' . esc_html( sprintf( __( 'and %s more', 'hw-content-health' ), number_format_i18n( count( $sources ) - 10 ) ) ) . '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Internal links view.
	 *
	 * @param array $rows  Rows from internal().
	 * @param int   $paged Page.
	 * @return void
	 */
	private static function view_internal( $rows, $paged ) {
		$total    = count( $rows );
		$rows     = array_slice( $rows, ( $paged - 1 ) * HWCH_Export::per( 50 ), HWCH_Export::per( 50 ) );
		$problems = array(
			'redirected'  => __( 'Redirected', 'hw-content-health' ),
			'dead_end'    => __( 'Redirects to an unpublished article', 'hw-content-health' ),
			'unpublished' => __( 'Article not published', 'hw-content-health' ),
			'term'        => __( 'Tag or category page removed', 'hw-content-health' ),
			'missing'     => __( 'Page not found', 'hw-content-health' ),
		);
		?>
		<p class="hwch-intro">
			<?php esc_html_e( 'Links written into English articles that point to a page on the blog that no longer works as linked: articles merged or pruned (redirected), unpublished articles, removed tag or category pages, and other blog pages that return an error. Redirected links still work but cost a hop; "Update link" points them straight at the final article, and can be undone from the Log. Recounted hourly;', 'hw-content-health' ); ?>
			<a href="<?php echo esc_url( add_query_arg( 'refresh', wp_create_nonce( 'hwch_refresh' ) ) ); ?>"><?php esc_html_e( 'recount now', 'hw-content-health' ); ?></a>.
		</p>
		<p class="hwch-count">
			<?php
			/* translators: %s: number */
			echo esc_html( sprintf( _n( '%s address', '%s addresses', $total, 'hw-content-health' ), number_format_i18n( $total ) ) );
			?>
		</p>
		<?php if ( ! $total ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'Every internal link checked points to a live page. Blog pages that are not articles are checked over time with the outbound links, so a few may still appear later.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<p><?php self::remove_button( '', 'internal' ); ?></p>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Linked address', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Problem', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Should point to', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Linked from', 'hw-content-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td class="hwch-url"><a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( rawurldecode( (string) wp_parse_url( $row['url'], PHP_URL_PATH ) ) ); ?></a></td>
						<td>
							<span class="hwch-status hwch-status--<?php echo esc_attr( 'redirected' === $row['problem'] ? 'now' : 'late' ); ?>"><?php echo esc_html( $problems[ $row['problem'] ] ); ?></span>
							<?php if ( 'unpublished' === $row['problem'] ) : ?>
								<?php /* translators: %s: post status */ ?>
								<div class="hwch-muted"><?php echo esc_html( sprintf( __( 'Now: %s', 'hw-content-health' ), $row['status'] ) ); ?></div>
							<?php elseif ( 'missing' === $row['problem'] && $row['status'] ) : ?>
								<div class="hwch-muted"><?php echo esc_html( $row['status'] ); ?></div>
							<?php elseif ( ! empty( $row['steps'] ) && $row['steps'] > 1 ) : ?>
								<?php /* translators: %s: number */ ?>
								<div class="hwch-muted"><?php echo esc_html( sprintf( __( 'Through %s redirects', 'hw-content-health' ), number_format_i18n( $row['steps'] ) ) ); ?></div>
							<?php endif; ?>
						</td>
						<td class="hwch-url">
							<?php if ( $row['final'] && 'dead_end' !== $row['problem'] ) : ?>
								<a href="<?php echo esc_url( $row['final'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( rawurldecode( (string) wp_parse_url( $row['final'], PHP_URL_PATH ) ) ); ?></a>
								<?php if ( 'term' === $row['problem'] ) : ?>
									<div class="hwch-muted"><?php esc_html_e( 'The destination hub for this tag', 'hw-content-health' ); ?></div>
								<?php endif; ?>
							<?php else : ?>
								<span class="hwch-muted"><?php esc_html_e( 'Decide in the article', 'hw-content-health' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="hwch-actions">
							<?php
							$fixable = $row['final'] && in_array( $row['problem'], array( 'redirected', 'term' ), true );
							self::sources( $row['sources'], $fixable ? $row['url'] : '', $fixable ? $row['final'] : '' );
							if ( 'redirected' !== $row['problem'] ) {
								self::remove_button( $row['url'], 'internal' );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		HWCH_Admin::paginate( $total, $paged, 50 );
	}

	/**
	 * Outbound links view.
	 *
	 * @param int $paged Page.
	 * @return void
	 */
	private static function view_outbound( $paged ) {
		$data  = self::outbound();
		$rows  = $data['broken'];
		$total = count( $rows );
		$rows  = array_slice( $rows, ( $paged - 1 ) * HWCH_Export::per( 50 ), HWCH_Export::per( 50 ) );
		$last  = (int) get_option( 'hwch_last_linkcheck' );
		?>
		<p class="hwch-intro">
			<?php
			printf(
				/* translators: 1: checked, 2: total, 3: days */
				esc_html__( 'Links from English articles to other sites, including the rest of hostelworld.com, that returned an error or no response when last checked. %1$s of %2$s links checked so far: about 60 are checked every hour, and each is checked again after %3$s days. Sites that refuse automated checks are listed separately rather than counted as broken. A server error can be temporary, so recheck before removing a link.', 'hw-content-health' ),
				esc_html( number_format_i18n( $data['checked'] ) ),
				esc_html( number_format_i18n( $data['total'] ) ),
				esc_html( number_format_i18n( (int) HWCH_Settings::get( 'link_recheck' ) ) )
			);
			if ( $last ) {
				/* translators: %s: time since */
				echo ' ' . esc_html( sprintf( __( 'Last batch %s ago.', 'hw-content-health' ), human_time_diff( $last ) ) );
			}
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hwch-inline-form">
			<?php wp_nonce_field( 'hwch_action' ); ?>
			<input type="hidden" name="action" value="hwch_check_links">
			<button class="button"><?php esc_html_e( 'Check the next batch now', 'hw-content-health' ); ?></button>
		</form>
		<p class="hwch-count">
			<?php
			/* translators: %s: number */
			echo esc_html( sprintf( _n( '%s broken link', '%s broken links', $total, 'hw-content-health' ), number_format_i18n( $total ) ) );
			?>
		</p>
		<?php if ( $total ) : ?>
			<p><?php self::remove_button( '', 'outbound' ); ?></p>
			<table class="widefat striped hwch-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Link', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Result', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Checked', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Linked from', 'hw-content-health' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td class="hwch-url"><a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html( rawurldecode( $row['url'] ) ); ?></a></td>
							<td><span class="hwch-status hwch-status--late"><?php echo esc_html( self::describe( $row ) ); ?></span></td>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row['checked_at'] . ' UTC' ) ) ); ?></td>
							<td class="hwch-actions"><?php self::sources( $row['sources'] ); ?><?php self::remove_button( $row['url'], 'outbound' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php HWCH_Admin::paginate( $total, $paged, 50 ); ?>
		<?php else : ?>
			<p class="hwch-empty"><?php esc_html_e( 'No broken outbound links among those checked so far.', 'hw-content-health' ); ?></p>
		<?php endif; ?>

		<?php if ( $data['blocked'] ) : ?>
			<details class="hwch-details hwch-blocked">
				<?php /* translators: %s: number */ ?>
				<summary><?php echo esc_html( sprintf( _n( '%s link could not be checked: the site blocks automated checks', '%s links could not be checked: the sites block automated checks', count( $data['blocked'] ), 'hw-content-health' ), number_format_i18n( count( $data['blocked'] ) ) ) ); ?></summary>
				<ul class="hwch-list">
					<?php foreach ( array_slice( $data['blocked'], 0, HWCH_Export::per( 100 ) ) as $row ) : ?>
						<?php /* translators: 1: link, 2: status */ ?>
						<li class="hwch-url"><a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html( rawurldecode( $row['url'] ) ); ?></a> <span class="hwch-muted"><?php echo esc_html( self::describe( $row ) ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>
		<?php
	}

	/**
	 * A check result in words.
	 *
	 * @param array $row Result.
	 * @return string
	 */
	private static function describe( $row ) {
		$status = (int) $row['status'];

		if ( ! $status ) {
			/* translators: %s: error */
			return sprintf( __( 'No response: %s', 'hw-content-health' ), $row['error'] );
		}

		$words = array(
			404 => __( 'Not found', 'hw-content-health' ),
			410 => __( 'Gone', 'hw-content-health' ),
			401 => __( 'Needs a login', 'hw-content-health' ),
			403 => __( 'Refused', 'hw-content-health' ),
			429 => __( 'Too many requests', 'hw-content-health' ),
			500 => __( 'Server error', 'hw-content-health' ),
			502 => __( 'Server error', 'hw-content-health' ),
			503 => __( 'Temporarily unavailable', 'hw-content-health' ),
		);

		return $status . ( isset( $words[ $status ] ) ? ' ' . $words[ $status ] : '' );
	}

	/**
	 * 404s view.
	 *
	 * @param int $paged Page.
	 * @return void
	 */
	private static function view_notfound( $paged ) {
		$rows  = self::not_found();
		$total = count( $rows );
		$rows  = array_slice( $rows, ( $paged - 1 ) * HWCH_Export::per( 50 ), HWCH_Export::per( 50 ) );
		?>
		<p class="hwch-intro">
			<?php
			if ( HWCH_Settings::get( 'log_404' ) ) {
				esc_html_e( 'Blog addresses visitors reached that returned a 404, most visits first. Each has a suggested redirect where there is a good match: an article with the same address ending, the destination hub for a removed tag page, or the closest article by the words in its address. Check the suggestion, change it if needed, and redirect with a 301; it can be undone from the Log. Addresses visited only once or twice and not since are cleared after 60 days, as they are usually bots.', 'hw-content-health' );
			} else {
				esc_html_e( 'Logging 404s is switched off in Settings, so no new ones are being recorded.', 'hw-content-health' );
			}
			?>
		</p>
		<p class="hwch-count">
			<?php
			/* translators: %s: number */
			echo esc_html( sprintf( _n( '%s address', '%s addresses', $total, 'hw-content-health' ), number_format_i18n( $total ) ) );
			?>
		</p>
		<?php if ( ! $total ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'No 404s logged yet. They appear here as visitors reach addresses that do not exist.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Address', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Visits', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Last visit', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Redirect to', 'hw-content-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $suggestion = self::suggest( $row['path'] ); ?>
					<tr>
						<td class="hwch-url">
							<a href="<?php echo esc_url( self::origin( $row['path'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( rawurldecode( $row['path'] ) ); ?></a>
							<?php if ( $row['referrer'] ) : ?>
								<?php /* translators: %s: referring URL */ ?>
								<div class="hwch-muted"><?php echo esc_html( sprintf( __( 'Last came from %s', 'hw-content-health' ), rawurldecode( $row['referrer'] ) ) ); ?></div>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['hits'] ) ); ?></td>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row['last_seen'] . ' UTC' ) ) ); ?></td>
						<td class="hwch-actions">
							<form class="hwch-redirect" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'hwch_action' ); ?>
								<input type="hidden" name="action" value="hwch_redirect_404">
								<input type="hidden" name="notfound_id" value="<?php echo esc_attr( $row['id'] ); ?>">
								<input type="text" name="target" value="<?php echo esc_attr( $suggestion['url'] ); ?>" placeholder="<?php esc_attr_e( 'URL or post ID', 'hw-content-health' ); ?>" required>
								<button class="button button-small button-primary"><?php esc_html_e( 'Redirect', 'hw-content-health' ); ?></button>
							</form>
							<?php if ( $suggestion['why'] ) : ?>
								<div class="hwch-muted"><?php echo esc_html( $suggestion['why'] ); ?></div>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'hwch_action' ); ?>
								<input type="hidden" name="action" value="hwch_ignore_404">
								<input type="hidden" name="notfound_id" value="<?php echo esc_attr( $row['id'] ); ?>">
								<button class="button button-small"><?php esc_html_e( 'Ignore', 'hw-content-health' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		HWCH_Admin::paginate( $total, $paged, 50 );
	}

	/**
	 * Redirect chains view.
	 *
	 * @return void
	 */
	private static function view_chains() {
		$rows = self::chains();
		?>
		<p class="hwch-intro"><?php esc_html_e( 'Redirects made by this plugin that pass through another redirect before landing, which happens when an article that other articles were merged into is itself merged or pruned later; and redirects that land on an article that has since been unpublished. Each hop slows the page and loses a little of the link\'s value. "Point straight to the final page" fixes a chain in one step and can be undone from the Log.', 'hw-content-health' ); ?></p>
		<?php if ( ! $rows ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'No chains or dead ends among this plugin\'s redirects.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'From', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Goes to now', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Lands on', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'hw-content-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td class="hwch-url"><?php echo esc_html( rawurldecode( $row['source'] ) ); ?></td>
						<td class="hwch-url"><?php echo esc_html( rawurldecode( (string) wp_parse_url( $row['target'], PHP_URL_PATH ) ) ); ?></td>
						<td class="hwch-url">
							<?php if ( 'chain' === $row['problem'] ) : ?>
								<?php echo esc_html( rawurldecode( (string) wp_parse_url( $row['final'], PHP_URL_PATH ) ) ); ?>
								<?php /* translators: %s: number */ ?>
								<div class="hwch-muted"><?php echo esc_html( sprintf( __( 'After %s redirects', 'hw-content-health' ), number_format_i18n( $row['steps'] ) ) ); ?></div>
							<?php else : ?>
								<span class="hwch-status hwch-status--late"><?php esc_html_e( 'An unpublished article', 'hw-content-health' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="hwch-actions">
							<?php if ( 'chain' === $row['problem'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'hwch_action' ); ?>
									<input type="hidden" name="action" value="hwch_fix_chain">
									<input type="hidden" name="redirect_id" value="<?php echo esc_attr( $row['id'] ); ?>">
									<button class="button button-small button-primary"><?php esc_html_e( 'Point straight to the final page', 'hw-content-health' ); ?></button>
								</form>
							<?php else : ?>
								<span class="hwch-muted"><?php esc_html_e( 'Republish the article, or undo the redirect from the Log and redirect again elsewhere', 'hw-content-health' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
