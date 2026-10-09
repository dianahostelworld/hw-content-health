<?php
/**
 * Database tables, the weekly schedule, and the Search Console sync.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores Search Console figures per article so the reports load instantly.
 */
class HWCH_Store {

	const DB_VERSION = '6';

	/**
	 * Table names.
	 *
	 * @param string $name metrics, log or redirects.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'hwch_' . $name;
	}

	/**
	 * Creates or updates the tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table( 'metrics' ) . " (
				post_id bigint(20) unsigned NOT NULL,
				clicks int(10) unsigned NOT NULL DEFAULT 0,
				impressions int(10) unsigned NOT NULL DEFAULT 0,
				position decimal(6,1) NOT NULL DEFAULT 0,
				clicks_recent int(10) unsigned NOT NULL DEFAULT 0,
				clicks_prior int(10) unsigned NOT NULL DEFAULT 0,
				synced_at datetime NOT NULL,
				PRIMARY KEY  (post_id)
			) $charset;"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'log' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL,
				action varchar(40) NOT NULL,
				details longtext NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				undone_at datetime NULL,
				PRIMARY KEY  (id),
				KEY post_id (post_id)
			) $charset;"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'redirects' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source varchar(500) NOT NULL,
				target varchar(500) NOT NULL,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY source (source(191))
			) $charset;"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'pairs' ) . " (
				post_a bigint(20) unsigned NOT NULL,
				post_b bigint(20) unsigned NOT NULL,
				overlap tinyint(3) unsigned NOT NULL DEFAULT 0,
				shared int(10) unsigned NOT NULL DEFAULT 0,
				title tinyint(3) unsigned NOT NULL DEFAULT 0,
				queries longtext NULL,
				PRIMARY KEY  (post_a,post_b)
			) $charset;"
		);

		// Each article's searches, kept from the sync so the editor check and
		// Question coverage can read them without asking Google again.
		dbDelta(
			'CREATE TABLE ' . self::table( 'queries' ) . " (
				post_id bigint(20) unsigned NOT NULL,
				query varchar(255) NOT NULL,
				clicks int(10) unsigned NOT NULL DEFAULT 0,
				impressions int(10) unsigned NOT NULL DEFAULT 0,
				position decimal(6,1) NOT NULL DEFAULT 0,
				KEY post_id (post_id)
			) $charset;"
		);

		// Clicks per article per calendar month, for the Seasonal planner.
		dbDelta(
			'CREATE TABLE ' . self::table( 'monthly' ) . " (
				post_id bigint(20) unsigned NOT NULL,
				month char(7) NOT NULL,
				clicks int(10) unsigned NOT NULL DEFAULT 0,
				impressions int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (post_id,month)
			) $charset;"
		);

		// Blog addresses that returned a 404, counted.
		dbDelta(
			'CREATE TABLE ' . self::table( 'notfound' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				path_hash char(32) NOT NULL,
				path varchar(500) NOT NULL,
				hits int(10) unsigned NOT NULL DEFAULT 0,
				referrer varchar(500) NOT NULL DEFAULT '',
				first_seen datetime NOT NULL,
				last_seen datetime NOT NULL,
				ignored tinyint(1) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY path_hash (path_hash)
			) $charset;"
		);

		// Outbound links and whether they still work.
		dbDelta(
			'CREATE TABLE ' . self::table( 'linkcheck' ) . " (
				url_hash char(32) NOT NULL,
				url varchar(1000) NOT NULL,
				status smallint(5) unsigned NOT NULL DEFAULT 0,
				error varchar(191) NOT NULL DEFAULT '',
				checked_at datetime NULL,
				PRIMARY KEY  (url_hash)
			) $charset;"
		);

		// Searches the English blog appears for, for the Content planner.
		dbDelta(
			'CREATE TABLE ' . self::table( 'demand' ) . " (
				query varchar(255) NOT NULL,
				clicks int(10) unsigned NOT NULL DEFAULT 0,
				impressions int(10) unsigned NOT NULL DEFAULT 0,
				position decimal(6,1) NOT NULL DEFAULT 0,
				KEY impressions (impressions)
			) $charset;"
		);

		// Each article this year and the same period last year, for spotting
		// clicks lost to AI Overviews.
		dbDelta(
			'CREATE TABLE ' . self::table( 'yoy' ) . " (
				post_id bigint(20) unsigned NOT NULL,
				clicks_now int(10) unsigned NOT NULL DEFAULT 0,
				impr_now int(10) unsigned NOT NULL DEFAULT 0,
				pos_now decimal(6,1) NOT NULL DEFAULT 0,
				clicks_then int(10) unsigned NOT NULL DEFAULT 0,
				impr_then int(10) unsigned NOT NULL DEFAULT 0,
				pos_then decimal(6,1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (post_id)
			) $charset;"
		);

		// Blog vs main site was removed in 1.9.4: clear what it stored.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( 'rivals' ) ); // phpcs:ignore WordPress.DB
		delete_option( 'hwch_rivals_booking' );
		delete_option( 'hwch_rivals_other' );

		// The content plan.
		dbDelta(
			'CREATE TABLE ' . self::table( 'plan' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title varchar(255) NOT NULL,
				destination varchar(200) NOT NULL DEFAULT '',
				topic varchar(100) NOT NULL DEFAULT '',
				notes text NULL,
				due date NULL,
				status varchar(20) NOT NULL DEFAULT 'idea',
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				evidence longtext NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id)
			) $charset;"
		);

		update_option( 'hwch_db_version', self::DB_VERSION );
	}

	/**
	 * Runs the installer after an update that changed the tables.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'hwch_db_version' ) !== self::DB_VERSION ) {
			self::install();
			// Updating the plugin does not run the activation hook, so the
			// new hourly schedule is added here too.
			self::schedule();
		}
	}

	/**
	 * Schedules the weekly sync, and the hourly job that checks outbound
	 * links a batch at a time.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'hwch_weekly_sync' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'hwch_weekly_sync' );
		}

		if ( ! wp_next_scheduled( 'hwch_hourly' ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'hwch_hourly' );
		}
	}

	/**
	 * Removes the weekly sync.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( 'hwch_weekly_sync' );
		wp_clear_scheduled_hook( 'hwch_hourly' );
	}

	/**
	 * Pulls three date ranges from Search Console and stores them per article:
	 * - the main window (12 months by default), for both reports;
	 * - the last 90 days and the 90 days before, to show whether traffic is
	 *   already slipping.
	 * Search Console data lags by about three days, so ranges end three days ago.
	 *
	 * @return true|WP_Error
	 */
	public static function sync() {
		global $wpdb;

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$end    = strtotime( '-3 days' );
		$months = (int) HWCH_Settings::get( 'window_months' );

		$ranges = array(
			'main'   => array( gmdate( 'Y-m-d', strtotime( '-' . $months . ' months', $end ) ), gmdate( 'Y-m-d', $end ) ),
			'recent' => array( gmdate( 'Y-m-d', strtotime( '-89 days', $end ) ), gmdate( 'Y-m-d', $end ) ),
			'prior'  => array( gmdate( 'Y-m-d', strtotime( '-179 days', $end ) ), gmdate( 'Y-m-d', strtotime( '-90 days', $end ) ) ),
		);

		$data = array();

		foreach ( $ranges as $name => $range ) {
			$pages = HWCH_GSC::pages( $range[0], $range[1] );

			if ( is_wp_error( $pages ) ) {
				update_option( 'hwch_last_error', $pages->get_error_message(), false );
				return $pages;
			}

			$data[ $name ] = $pages;
		}

		if ( ! $data['main'] ) {
			$error = new WP_Error( 'hwch_empty', __( 'Search Console returned no pages under the URL prefix. Check the property and the URL prefix in the settings. The previous data was kept.', 'hw-content-health' ) );
			update_option( 'hwch_last_error', $error->get_error_message(), false );
			return $error;
		}

		$ids = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		$now         = current_time( 'mysql', true );
		$table       = self::table( 'metrics' );
		$url_to_post = array();

		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL

		foreach ( $ids as $id ) {
			$url  = HWCH_GSC::normalise( get_permalink( $id ) );
			$url_to_post[ $url ] = $id;
			$main = isset( $data['main'][ $url ] ) ? $data['main'][ $url ] : array( 'clicks' => 0, 'impressions' => 0, 'position' => 0 );

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'post_id'       => $id,
					'clicks'        => $main['clicks'],
					'impressions'   => $main['impressions'],
					'position'      => $main['position'],
					'clicks_recent' => isset( $data['recent'][ $url ] ) ? $data['recent'][ $url ]['clicks'] : 0,
					'clicks_prior'  => isset( $data['prior'][ $url ] ) ? $data['prior'][ $url ]['clicks'] : 0,
					'synced_at'     => $now,
				),
				array( '%d', '%d', '%d', '%f', '%d', '%d', '%s' )
			);
		}

		// Kept so the reports and later steps can find an article from its
		// address without working out 40,000 permalinks again.
		update_option( 'hwch_url_ids', $url_to_post, false );
		delete_transient( 'hwch_url_extra' );

		update_option( 'hwch_last_sync', $now, false );
		update_option(
			'hwch_last_sync_range',
			array(
				'start' => $ranges['main'][0],
				'end'   => $ranges['main'][1],
			),
			false
		);
		delete_option( 'hwch_last_error' );

		// Everything else runs afterwards, one short step per request, so the
		// sync never hits the server's time limit.
		self::queue_steps();

		return true;
	}

	/**
	 * The steps that follow the page figures, in order, with their names.
	 *
	 * @return array Map of step => label.
	 */
	public static function steps() {
		return array(
			'overlap'        => __( 'overlapping articles and searches', 'hw-content-health' ),
			'planner'        => __( 'content planner searches', 'hw-content-health' ),
			'aio'            => __( 'last year\'s figures (AI Overviews)', 'hw-content-health' ),
			'seasonal'       => __( 'monthly figures (Seasonal planner)', 'hw-content-health' ),
		);
	}

	/**
	 * Queues the steps and starts the first in the background.
	 *
	 * @return void
	 */
	public static function queue_steps() {
		update_option( 'hwch_steps', array_keys( self::steps() ), false );
		update_option( 'hwch_step_errors', array(), false );
		update_option( 'hwch_step_tries', array(), false );
		delete_transient( 'hwch_step_lock' );
		self::next_in_background();
	}

	/**
	 * Asks WordPress to run the next step in a separate background request.
	 *
	 * @return void
	 */
	private static function next_in_background() {
		if ( ! wp_next_scheduled( 'hwch_sync_step' ) ) {
			wp_schedule_single_event( time(), 'hwch_sync_step' );
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Steps still to run.
	 *
	 * @return string[]
	 */
	public static function pending() {
		return array_values( array_intersect( (array) get_option( 'hwch_steps', array() ), array_keys( self::steps() ) ) );
	}

	/**
	 * Runs the next step. Called by the background job, and by the Content
	 * health screen while it is open. Only one step runs at a time; a step
	 * that is cut off is tried again, three times at most.
	 *
	 * @return array pending (labels), busy, errors.
	 */
	public static function run_step() {
		$pending = self::pending();

		if ( ! $pending ) {
			return self::status();
		}

		if ( get_transient( 'hwch_step_lock' ) ) {
			return self::status( true );
		}

		set_transient( 'hwch_step_lock', 1, 5 * MINUTE_IN_SECONDS );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$step   = $pending[0];
		$tries  = (array) get_option( 'hwch_step_tries', array() );
		$errors = (array) get_option( 'hwch_step_errors', array() );

		$tries[ $step ] = ( isset( $tries[ $step ] ) ? $tries[ $step ] : 0 ) + 1;
		update_option( 'hwch_step_tries', $tries, false );

		if ( $tries[ $step ] > 3 ) {
			$result = new WP_Error( 'hwch_slow', __( 'it was cut off by the server three times', 'hw-content-health' ) );
		} else {
			$result = self::do_step( $step );
		}

		if ( is_wp_error( $result ) ) {
			$errors[ $step ] = $result->get_error_message();
		} else {
			unset( $errors[ $step ] );
		}

		update_option( 'hwch_step_errors', $errors, false );
		update_option( 'hwch_steps', array_slice( $pending, 1 ), false );
		delete_transient( 'hwch_step_lock' );

		if ( count( $pending ) > 1 ) {
			self::next_in_background();
		} elseif ( $errors ) {
			$labels = self::steps();
			$parts  = array();

			foreach ( $errors as $key => $message ) {
				$parts[] = ( isset( $labels[ $key ] ) ? $labels[ $key ] : $key ) . ' (' . $message . ')';
			}

			/* translators: %s: list of failed parts */
			update_option( 'hwch_last_error', sprintf( __( 'Page data updated, but these could not be refreshed: %s', 'hw-content-health' ), implode( '; ', $parts ) ), false );
		}

		return self::status();
	}

	/**
	 * Runs one step.
	 *
	 * @param string $step Step.
	 * @return true|WP_Error
	 */
	private static function do_step( $step ) {
		$result = true;

		switch ( $step ) {
			case 'overlap':
				$result = HWCH_Overlap::build( self::url_map() );
				if ( ! is_wp_error( $result ) ) {
					update_option( 'hwch_last_overlap', current_time( 'mysql', true ), false );
				}
				delete_transient( 'hwch_questions' );
				break;
			case 'planner':
				$result = HWCH_Planner::build();
				break;
			case 'aio':
				$result = HWCH_AIO::build( self::url_map() );
				break;
			case 'seasonal':
				$result = HWCH_Seasonal::build( self::url_map(), 60 );
				break;
		}

		delete_transient( 'hwch_seasonal' );
		delete_transient( 'hwch_planner' );
		delete_transient( 'hwch_aio' );

		return $result;
	}

	/**
	 * Where the steps are, for the progress line.
	 *
	 * @param bool $busy Whether a step is running right now.
	 * @return array
	 */
	public static function status( $busy = false ) {
		$labels = self::steps();

		return array(
			'pending' => array_map(
				function ( $step ) use ( $labels ) {
					return isset( $labels[ $step ] ) ? $labels[ $step ] : $step;
				},
				self::pending()
			),
			'busy'    => $busy,
			'errors'  => array_values( (array) get_option( 'hwch_step_errors', array() ) ),
		);
	}

	/**
	 * Every published article's normalised URL, mapped to its ID, as the sync
	 * builds it.
	 *
	 * @return array Map of normalised URL => post ID.
	 */
	public static function url_map() {
		$stored = get_option( 'hwch_url_ids' );

		if ( is_array( $stored ) && $stored ) {
			return $stored;
		}

		$map = array();

		foreach ( get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) as $id ) {
			$map[ HWCH_GSC::normalise( get_permalink( $id ) ) ] = $id;
		}

		if ( $map ) {
			update_option( 'hwch_url_ids', $map, false );
		}

		return $map;
	}

	/**
	 * The post at an address. Uses the map saved by the last sync, then
	 * WordPress's own lookup for anything else (pages, articles published
	 * since the sync), remembering those answers for a day so each address
	 * is only looked up once.
	 *
	 * @param string $url URL.
	 * @return int Post ID, or 0.
	 */
	public static function post_id_for_url( $url ) {
		static $map = null;
		static $extra = null;
		static $memo = array();
		static $dirty = false;

		$key = HWCH_GSC::normalise( $url );

		if ( '' === $key ) {
			return 0;
		}

		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		if ( null === $map ) {
			$map   = get_option( 'hwch_url_ids' );
			$map   = is_array( $map ) ? $map : array();
			$extra = get_transient( 'hwch_url_extra' );
			$extra = is_array( $extra ) ? $extra : array();
		}

		if ( isset( $map[ $key ] ) ) {
			$memo[ $key ] = (int) $map[ $key ];
			return $memo[ $key ];
		}

		if ( isset( $extra[ $key ] ) ) {
			$memo[ $key ] = (int) $extra[ $key ];
			return $memo[ $key ];
		}

		$id             = (int) url_to_postid( $url );
		$memo[ $key ]   = $id;
		$extra[ $key ]  = $id;

		if ( ! $dirty ) {
			$dirty = true;
			add_action(
				'shutdown',
				function () use ( &$extra ) {
					if ( count( $extra ) > 5000 ) {
						$extra = array_slice( $extra, -5000, null, true );
					}
					set_transient( 'hwch_url_extra', $extra, DAY_IN_SECONDS );
				}
			);
		}

		return $id;
	}

	/**
	 * Whether a sync has ever completed. Until it has, every article would
	 * look like it had no traffic, so the prune report must not be shown.
	 *
	 * @return bool
	 */
	public static function has_data() {
		return (bool) get_option( 'hwch_last_sync' );
	}
}
