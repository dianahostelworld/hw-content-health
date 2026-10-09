<?php
/**
 * Google Search Console: authentication with a service account, and page-level
 * Search Analytics queries.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * A minimal Search Console client. No Google SDK is needed: the service
 * account signs a JWT with OpenSSL and exchanges it for an access token.
 */
class HWCH_GSC {

	const SCOPE     = 'https://www.googleapis.com/auth/webmasters.readonly';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const API_URL   = 'https://searchconsole.googleapis.com/webmasters/v3/sites/%s/searchAnalytics/query';
	const ROW_LIMIT = 25000;

	/**
	 * An access token, cached until shortly before it expires.
	 *
	 * @return string|WP_Error
	 */
	public static function token() {
		$cached = get_transient( 'hwch_gsc_token' );

		if ( $cached ) {
			return $cached;
		}

		if ( 'oauth' === HWCH_Settings::get( 'auth' ) ) {
			return HWCH_OAuth::access_token();
		}

		$key = HWCH_Settings::key();

		if ( ! $key ) {
			return new WP_Error( 'hwch_no_key', __( 'No service account key is configured.', 'hw-content-health' ) );
		}

		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'hwch_no_openssl', __( 'The PHP OpenSSL extension is required to sign in to Search Console.', 'hw-content-health' ) );
		}

		$now    = time();
		$header = self::b64( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = self::b64(
			wp_json_encode(
				array(
					'iss'   => $key['client_email'],
					'scope' => self::SCOPE,
					'aud'   => self::TOKEN_URL,
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);

		$signature = '';

		if ( ! openssl_sign( $header . '.' . $claims, $signature, $key['private_key'], 'sha256' ) ) {
			return new WP_Error( 'hwch_sign', __( 'The service account private key could not be used to sign the request.', 'hw-content-health' ) );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $header . '.' . $claims . '.' . self::b64( $signature ),
				),
			)
		);

		$body = self::body( $response );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'hwch_token', __( 'Google did not return an access token.', 'hw-content-health' ) );
		}

		$lifetime = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
		set_transient( 'hwch_gsc_token', $body['access_token'], max( 60, $lifetime - 120 ) );

		return $body['access_token'];
	}

	/**
	 * Clicks, impressions and position per page for a date range, summed
	 * across URL variants (trailing slash, query strings) of the same page.
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return array|WP_Error Map of normalised URL => array( clicks, impressions, position ).
	 */
	public static function pages( $start, $end ) {
		$token = self::token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$property = HWCH_Settings::get( 'property' );

		if ( ! $property ) {
			return new WP_Error( 'hwch_no_property', __( 'No Search Console property is configured.', 'hw-content-health' ) );
		}

		$prefix = self::normalise( HWCH_Settings::get( 'url_prefix' ) );
		$pages  = array();
		$offset = 0;

		do {
			$response = wp_remote_post(
				sprintf( self::API_URL, rawurlencode( $property ) ),
				array(
					'timeout' => 60,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'startDate'  => $start,
							'endDate'    => $end,
							'dimensions' => array( 'page' ),
							'type'       => 'web',
							'rowLimit'   => self::ROW_LIMIT,
							'startRow'   => $offset,
						)
					),
				)
			);

			$body = self::body( $response );

			if ( is_wp_error( $body ) ) {
				return $body;
			}

			$rows = isset( $body['rows'] ) ? $body['rows'] : array();

			foreach ( $rows as $row ) {
				$url = self::normalise( $row['keys'][0] );

				if ( $prefix && 0 !== strpos( $url, $prefix ) ) {
					continue;
				}

				if ( ! isset( $pages[ $url ] ) ) {
					$pages[ $url ] = array( 'clicks' => 0, 'impressions' => 0, 'pos_sum' => 0 );
				}

				$pages[ $url ]['clicks']      += (int) $row['clicks'];
				$pages[ $url ]['impressions'] += (int) $row['impressions'];
				// Position is averaged, weighted by impressions, when variants merge.
				$pages[ $url ]['pos_sum']     += (float) $row['position'] * (int) $row['impressions'];
			}

			$offset += self::ROW_LIMIT;
		} while ( count( $rows ) === self::ROW_LIMIT );

		foreach ( $pages as $url => $data ) {
			$pages[ $url ]['position'] = $data['impressions'] ? round( $data['pos_sum'] / $data['impressions'], 1 ) : 0;
			unset( $pages[ $url ]['pos_sum'] );
		}

		return $pages;
	}

	/**
	 * Clicks, impressions and position per page and search query, for finding
	 * articles that compete for the same searches. The API returns the rows
	 * with the most clicks first; this stops at 50,000 rows, which covers the
	 * queries that matter and keeps the weekly sync quick.
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return array|WP_Error List of array( url, query, clicks, impressions, position ).
	 */
	public static function page_queries( $start, $end ) {
		$token = self::token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$prefix = self::normalise( HWCH_Settings::get( 'url_prefix' ) );
		$rows   = array();
		$offset = 0;

		do {
			$response = wp_remote_post(
				sprintf( self::API_URL, rawurlencode( HWCH_Settings::get( 'property' ) ) ),
				array(
					'timeout' => 90,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'startDate'  => $start,
							'endDate'    => $end,
							'dimensions' => array( 'page', 'query' ),
							'type'       => 'web',
							'rowLimit'   => self::ROW_LIMIT,
							'startRow'   => $offset,
						)
					),
				)
			);

			$body = self::body( $response );

			if ( is_wp_error( $body ) ) {
				return $body;
			}

			$batch = isset( $body['rows'] ) ? $body['rows'] : array();

			foreach ( $batch as $row ) {
				$url = self::normalise( $row['keys'][0] );

				if ( $prefix && 0 !== strpos( $url, $prefix ) ) {
					continue;
				}

				$rows[] = array(
					'url'         => $url,
					'query'       => (string) $row['keys'][1],
					'clicks'      => (int) $row['clicks'],
					'impressions' => (int) $row['impressions'],
					'position'    => round( (float) $row['position'], 1 ),
				);
			}

			$offset += self::ROW_LIMIT;
		} while ( count( $batch ) === self::ROW_LIMIT && $offset < 50000 );

		return $rows;
	}

	/**
	 * A general Search Analytics query, limited to the blog.
	 *
	 * @param string[] $dimensions Dimensions, e.g. array( 'date' ) or array( 'page', 'date' ).
	 * @param string   $start      Y-m-d.
	 * @param string   $end        Y-m-d.
	 * @param array    $args       'regex' to limit pages to a regular expression
	 *                             (instead of the blog URL), 'max' rows (default 25,000).
	 * @return array|WP_Error Raw rows: array( keys, clicks, impressions, ctr, position ).
	 */
	public static function query( $dimensions, $start, $end, $args = array() ) {
		$token = self::token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = wp_parse_args( $args, array( 'regex' => '', 'max' => self::ROW_LIMIT, 'type' => 'web', 'lang' => '', 'filters' => array(), 'property' => '', 'nofilter' => false ) );

		if ( $args['nofilter'] ) {
			$filters = array();
		} elseif ( $args['filters'] ) {
			$filters = $args['filters'];
		} elseif ( $args['regex'] ) {
			$filters = array( array( 'dimension' => 'page', 'operator' => 'includingRegex', 'expression' => $args['regex'] ) );
		} else {
			$filters = array( array( 'dimension' => 'page', 'operator' => 'contains', 'expression' => untrailingslashit( (string) HWCH_Settings::get( 'url_prefix' ) ) ) );
			$filters = array_merge( $filters, HWCH_Lang::gsc_filters( $args['lang'] ) );
		}

		$rows   = array();
		$offset = 0;

		do {
			$limit = min( self::ROW_LIMIT, $args['max'] - $offset );

			$response = wp_remote_post(
				sprintf( self::API_URL, rawurlencode( $args['property'] ? $args['property'] : HWCH_Settings::get( 'property' ) ) ),
				array(
					'timeout' => 90,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						array_filter(
							array(
								'startDate'             => $start,
								'endDate'               => $end,
								'dimensions'            => array_values( $dimensions ),
								'type'                  => $args['type'],
								'dimensionFilterGroups' => $filters ? array( array( 'filters' => $filters ) ) : null,
								'rowLimit'              => $limit,
								'startRow'              => $offset,
							),
							function ( $v ) { return null !== $v; }
						)
					),
				)
			);

			$body = self::body( $response );

			if ( is_wp_error( $body ) ) {
				return $body;
			}

			$batch = isset( $body['rows'] ) ? $body['rows'] : array();
			$rows  = array_merge( $rows, $batch );

			$offset += $limit;
		} while ( count( $batch ) === $limit && $offset < $args['max'] );

		return $rows;
	}

	/**
	 * A URL reduced to host and path, so the permalink and the URL Google
	 * reports match however they differ in scheme, www, case, query string
	 * or trailing slash.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalise( $url ) {
		$parts = wp_parse_url( (string) $url );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$host = preg_replace( '/^www\./', '', strtolower( $parts['host'] ) );
		$path = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';

		return $host . $path;
	}

	/**
	 * Decodes an API response, turning HTTP and API errors into WP_Error.
	 *
	 * @param array|WP_Error $response Response from wp_remote_post().
	 * @return array|WP_Error
	 */
	public static function body( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : ( isset( $body['error_description'] ) ? $body['error_description'] : 'HTTP ' . $code );

			return new WP_Error( 'hwch_api', sprintf( /* translators: %s: error from Google */ __( 'Google returned an error: %s', 'hw-content-health' ), $message ), array( 'status' => $code ) );
		}

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Who the plugin signs in to Search Console as, in words, for telling
	 * someone which account to give access to a property.
	 *
	 * @return string
	 */
	public static function account() {
		if ( 'service' === HWCH_Settings::get( 'auth' ) ) {
			$key = HWCH_Settings::key();

			if ( $key ) {
				/* translators: %s: service account email */
				return sprintf( __( 'the service account %s', 'hw-content-health' ), $key['client_email'] );
			}
		}

		return __( 'the Google account connected in Settings', 'hw-content-health' );
	}

	/**
	 * URL-safe base64, as JWT requires.
	 *
	 * @param string $data Raw data.
	 * @return string
	 */
	private static function b64( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}
}
