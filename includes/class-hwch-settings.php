<?php
/**
 * Settings: the Search Console connection and the thresholds for each report.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and saves the plugin settings.
 */
class HWCH_Settings {

	const OPTION = 'hwch_settings';

	/**
	 * Default values for every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'property'          => '',
			'auth'              => 'oauth',
			'client_id'         => '',
			'client_secret'     => '',
			'key_json'          => '',
			'url_prefix'        => home_url( '/' ),
			'window_months'     => 12,
			'refresh_age'       => 12,
			'refresh_min_click' => 100,
			'prune_min_age'     => 6,
			'prune_max_impr'    => 10,
			'overlap_days'      => 90,
			'overlap_min'       => 30,
			'overlap_min_impr'  => 50,
			'title_min'         => 70,
			'qw_strike_impr'    => 200,
			'qw_ctr_impr'       => 500,
			'qw_ctr_ratio'      => 50,
			'impact_days'       => 28,
			'hub_min'           => 5,
			'event_tags'        => 'events, festivals',
			'event_age'         => 6,
			'short_words'       => 500,
			'season_min_clicks' => 200,
			'season_strength'   => 150,
			'season_lead_max'   => 8,
			'season_lead_min'   => 6,
			'q_min_impr'        => 20,
			'faq_class'         => '',
			'log_404'           => 1,
			'link_recheck'      => 30,
			'planner_min_impr'  => 100,
			'aio_min_impr'      => 1000,
			'aio_ctr_drop'      => 30,
			'ahrefs_key'        => '',
			'ahrefs_links_rows' => 5000,
			'ahrefs_top_rows'   => 2000,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array
	 */
	public static function all() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * The service account key. A key file path defined in wp-config.php
	 * (HWCH_GSC_KEY_FILE) takes priority over a key pasted into the settings,
	 * so the key can be kept out of the database.
	 *
	 * @return array|null Decoded key, or null if none is configured.
	 */
	public static function key() {
		$json = '';

		if ( defined( 'HWCH_GSC_KEY_FILE' ) && is_readable( HWCH_GSC_KEY_FILE ) ) {
			$json = (string) file_get_contents( HWCH_GSC_KEY_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			$json = (string) self::get( 'key_json' );
		}

		$key = json_decode( $json, true );

		if ( empty( $key['client_email'] ) || empty( $key['private_key'] ) ) {
			return null;
		}

		return $key;
	}

	/**
	 * Cleans settings before they are saved.
	 *
	 * @param array $input Raw form input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = self::all();
		$input   = (array) $input;
		$out     = $current;

		$out['property']   = isset( $input['property'] ) ? sanitize_text_field( $input['property'] ) : $current['property'];
		$out['auth']       = isset( $input['auth'] ) && 'service' === $input['auth'] ? 'service' : 'oauth';
		$out['client_id']  = isset( $input['client_id'] ) ? sanitize_text_field( $input['client_id'] ) : $current['client_id'];

		// Like the key, the client secret is never printed back into the form,
		// so an empty box keeps the saved one.
		if ( ! empty( $input['client_secret'] ) ) {
			$out['client_secret'] = sanitize_text_field( $input['client_secret'] );
		}

		// A different client or sign-in method invalidates the cached token.
		if ( $out['auth'] !== $current['auth'] || $out['client_id'] !== $current['client_id'] ) {
			delete_transient( 'hwch_gsc_token' );
		}
		$out['url_prefix'] = isset( $input['url_prefix'] ) ? esc_url_raw( trim( $input['url_prefix'] ) ) : $current['url_prefix'];

		// The key is never printed back into the form, so an empty box means
		// "keep the saved key", not "delete it".
		if ( ! empty( $input['key_json'] ) ) {
			// options.php has already unslashed the input; unslashing again would
			// strip the backslashes from the \n sequences in the private key.
			$raw  = trim( $input['key_json'] );
			$test = json_decode( $raw, true );

			if ( empty( $test['client_email'] ) || empty( $test['private_key'] ) ) {
				add_settings_error( self::OPTION, 'hwch_key', __( 'That does not look like a service account key: it needs client_email and private_key. The previous key was kept.', 'hw-content-health' ) );
			} else {
				$out['key_json'] = $raw;
			}
		}

		// The Ahrefs key, like the others, is never printed back: an empty
		// box keeps the saved one.
		if ( ! empty( $input['ahrefs_key'] ) ) {
			$out['ahrefs_key'] = preg_replace( '/\s+/', '', sanitize_text_field( $input['ahrefs_key'] ) );
			delete_transient( 'hwch_ahrefs_usage' );
		}

		if ( ! empty( $input['clear_ahrefs_key'] ) ) {
			$out['ahrefs_key'] = '';
			delete_transient( 'hwch_ahrefs_usage' );
		}

		if ( ! empty( $input['clear_key'] ) ) {
			$out['key_json'] = '';
		}

		if ( isset( $input['event_tags'] ) ) {
			$out['event_tags'] = implode( ', ', array_filter( array_map( 'sanitize_title', preg_split( '/[\s,]+/', (string) $input['event_tags'] ) ) ) );
		}

		if ( isset( $input['faq_class'] ) ) {
			$out['faq_class'] = sanitize_html_class( trim( (string) $input['faq_class'] ) );
		}

		// A hidden field sends 0 when the box is unticked.
		if ( isset( $input['log_404'] ) ) {
			$out['log_404'] = $input['log_404'] ? 1 : 0;
		}

		$ints = array(
			'window_months'     => array( 1, 16 ),
			'refresh_age'       => array( 1, 60 ),
			'refresh_min_click' => array( 0, 1000000 ),
			'prune_min_age'     => array( 0, 60 ),
			'prune_max_impr'    => array( 0, 100000 ),
			'overlap_days'      => array( 28, 480 ),
			'overlap_min'       => array( 5, 100 ),
			'overlap_min_impr'  => array( 0, 1000000 ),
			'title_min'         => array( 30, 100 ),
			'qw_strike_impr'    => array( 0, 1000000 ),
			'qw_ctr_impr'       => array( 0, 1000000 ),
			'qw_ctr_ratio'      => array( 5, 100 ),
			'impact_days'       => array( 14, 90 ),
			'hub_min'           => array( 1, 1000 ),
			'event_age'         => array( 1, 60 ),
			'short_words'       => array( 50, 5000 ),
			'season_min_clicks' => array( 0, 1000000 ),
			'season_strength'   => array( 110, 1000 ),
			'season_lead_max'   => array( 1, 26 ),
			'season_lead_min'   => array( 0, 25 ),
			'q_min_impr'        => array( 1, 100000 ),
			'link_recheck'      => array( 7, 365 ),
			'planner_min_impr'  => array( 1, 1000000 ),
			'aio_min_impr'      => array( 1, 10000000 ),
			'aio_ctr_drop'      => array( 10, 90 ),
			'ahrefs_links_rows' => array( 100, 50000 ),
			'ahrefs_top_rows'   => array( 100, 50000 ),
		);

		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = min( $range[1], max( $range[0], (int) $input[ $key ] ) );
			}
		}

		// The refresh window must open before it is due.
		if ( $out['season_lead_min'] >= $out['season_lead_max'] ) {
			$out['season_lead_min'] = max( 0, $out['season_lead_max'] - 1 );
		}

		// New thresholds change what the reports show.
		delete_transient( 'hwch_seasonal' );
		delete_transient( 'hwch_questions' );
		delete_transient( 'hwch_planner' );
		delete_transient( 'hwch_aio' );

		return $out;
	}
}
