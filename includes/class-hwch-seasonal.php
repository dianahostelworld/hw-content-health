<?php
/**
 * Seasonal planner: when each article and destination peaks, and which
 * articles to refresh ahead of their season.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores clicks per article per calendar month and works out each article's
 * season from them.
 *
 * Search Console keeps 16 months, so most calendar months are seen once and
 * a few twice. That is enough to see a clear peak, like Christmas markets in
 * December, but not to separate a one-off spike from a pattern. Articles
 * therefore need a clear peak and enough clicks before they count as seasonal.
 */
class HWCH_Seasonal {

	/**
	 * A month is part of an article's season when its clicks are at least this
	 * much above the article's average month.
	 */
	const SEASON_RATIO = 1.25;

	/**
	 * Fewest months of data (since publication) before an article is judged.
	 */
	const MIN_MONTHS = 10;

	/**
	 * Seconds the sync may spend fetching months before it stops and leaves
	 * the rest for next time, so a first sync does not time out.
	 */
	const TIME_BUDGET = 100;

	/**
	 * The calendar months Search Console still has complete data for, oldest
	 * first, as Y-m. The newest is the last month that ended three days ago;
	 * the oldest is the first full month inside the 16 months Google keeps.
	 *
	 * @return string[]
	 */
	public static function months() {
		// Data lags three days. The month containing that day is complete only
		// if that day is its last; otherwise the month before is the newest.
		$end    = strtotime( '-3 days' );
		$newest = strtotime( gmdate( 'Y-m-01', $end ) );

		if ( gmdate( 'j', $end ) !== gmdate( 't', $end ) ) {
			$newest = strtotime( '-1 month', $newest );
		}

		$oldest = strtotime( gmdate( 'Y-m-01', strtotime( '-16 months' ) ) . ' +1 month' );
		$months = array();

		for ( $t = $oldest; $t <= $newest; $t = strtotime( '+1 month', $t ) ) {
			$months[] = gmdate( 'Y-m', $t );
		}

		return $months;
	}

	/**
	 * Fetches the months not stored yet and removes months Google no longer
	 * has. Called at the end of each sync, and from the planner's button.
	 *
	 * @param array $url_to_post Map of normalised URL => post ID.
	 * @param int   $budget      Seconds to spend before stopping.
	 * @return true|WP_Error
	 */
	public static function build( $url_to_post, $budget = self::TIME_BUDGET ) {
		global $wpdb;

		$table  = HWCH_Store::table( 'monthly' );
		$months = self::months();
		$done   = array_values( array_intersect( (array) get_option( 'hwch_months_done', array() ), $months ) );
		$start  = microtime( true );

		if ( $months ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE month < %s", $months[0] ) ); // phpcs:ignore WordPress.DB
		}

		// Newest first, so a sync that stops early still has the recent months.
		foreach ( array_reverse( array_diff( $months, $done ) ) as $month ) {
			if ( microtime( true ) - $start > $budget ) {
				break;
			}

			$first = $month . '-01';
			$last  = gmdate( 'Y-m-t', strtotime( $first ) );
			$pages = HWCH_GSC::pages( $first, $last );

			if ( is_wp_error( $pages ) ) {
				update_option( 'hwch_months_done', $done, false );
				return $pages;
			}

			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE month = %s", $month ) ); // phpcs:ignore WordPress.DB

			$values = array();

			foreach ( $pages as $url => $figures ) {
				if ( empty( $url_to_post[ $url ] ) || ( ! $figures['clicks'] && ! $figures['impressions'] ) ) {
					continue;
				}

				$values[ $url_to_post[ $url ] ] = array(
					'clicks'      => ( isset( $values[ $url_to_post[ $url ] ] ) ? $values[ $url_to_post[ $url ] ]['clicks'] : 0 ) + $figures['clicks'],
					'impressions' => ( isset( $values[ $url_to_post[ $url ] ] ) ? $values[ $url_to_post[ $url ] ]['impressions'] : 0 ) + $figures['impressions'],
				);
			}

			foreach ( array_chunk( $values, 400, true ) as $chunk ) {
				$sql = array();

				foreach ( $chunk as $post_id => $figures ) {
					$sql[] = $wpdb->prepare( '(%d, %s, %d, %d)', $post_id, $month, $figures['clicks'], $figures['impressions'] );
				}

				$wpdb->query( "INSERT INTO {$table} (post_id, month, clicks, impressions) VALUES " . implode( ',', $sql ) ); // phpcs:ignore WordPress.DB
			}

			$done[] = $month;
			update_option( 'hwch_months_done', $done, false );
		}

		delete_transient( 'hwch_seasonal' );

		return true;
	}

	/**
	 * The hourly job: fetches a few missing months, so a first sync never has
	 * to fetch all of them at once.
	 *
	 * @return void
	 */
	public static function hourly() {
		$progress = self::progress();

		if ( HWCH_Store::has_data() && $progress['done'] < $progress['total'] ) {
			self::build( HWCH_Store::url_map(), 40 );
		}
	}

	/**
	 * How many of the available months are stored.
	 *
	 * @return array done, total.
	 */
	public static function progress() {
		$months = self::months();

		return array(
			'done'  => count( array_intersect( (array) get_option( 'hwch_months_done', array() ), $months ) ),
			'total' => count( $months ),
		);
	}

	/**
	 * Every published article's seasonal profile.
	 *
	 * @return array Map of post ID => profile (see profile()).
	 */
	public static function profiles() {
		global $wpdb;

		$cached = get_transient( 'hwch_seasonal' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$done = array_intersect( (array) get_option( 'hwch_months_done', array() ), self::months() );
		sort( $done );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT m.post_id, m.month, m.clicks, p.post_date_gmt FROM ' . HWCH_Store::table( 'monthly' ) . " m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'post' AND p.post_status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		$by_post = array();

		foreach ( (array) $rows as $row ) {
			$by_post[ (int) $row['post_id'] ]['published']              = substr( (string) $row['post_date_gmt'], 0, 7 );
			$by_post[ (int) $row['post_id'] ]['clicks'][ $row['month'] ] = (int) $row['clicks'];
		}

		$out = array();

		foreach ( $by_post as $post_id => $data ) {
			$profile = self::profile( $data['clicks'], $data['published'], $done );

			if ( $profile ) {
				$out[ $post_id ] = $profile;
			}
		}

		set_transient( 'hwch_seasonal', $out, 12 * HOUR_IN_SECONDS );

		return $out;
	}

	/**
	 * One article's season, from its clicks per month.
	 *
	 * @param array    $clicks    Map of Y-m => clicks.
	 * @param string   $published Y-m of publication.
	 * @param string[] $months    Months that were fetched, oldest first.
	 * @return array|null average (clicks per calendar month 1-12), mean, peak,
	 *                    strength, season (calendar months, in order), total,
	 *                    months. Null when there are too few months to judge.
	 */
	public static function profile( $clicks, $published, $months ) {
		// Months before publication are not zeros, just missing.
		$months = array_values(
			array_filter(
				$months,
				function ( $month ) use ( $published ) {
					return $month >= $published;
				}
			)
		);

		if ( count( $months ) < self::MIN_MONTHS ) {
			return null;
		}

		$sums  = array_fill( 1, 12, 0 );
		$seen  = array_fill( 1, 12, 0 );
		$total = 0;

		foreach ( $months as $month ) {
			$cal           = (int) substr( $month, 5, 2 );
			$value         = isset( $clicks[ $month ] ) ? (int) $clicks[ $month ] : 0;
			$sums[ $cal ] += $value;
			++$seen[ $cal ];
			$total        += $value;
		}

		$average = array();

		foreach ( $sums as $cal => $sum ) {
			$average[ $cal ] = $seen[ $cal ] ? $sum / $seen[ $cal ] : null;
		}

		$known = array_filter(
			$average,
			function ( $value ) {
				return null !== $value;
			}
		);
		// The average month weights each calendar month once, so months seen
		// twice in the 16 months Google keeps do not count double.
		$mean = $known ? array_sum( $known ) / count( $known ) : 0;

		if ( $mean <= 0 ) {
			return null;
		}

		arsort( $known );
		$peak = (int) key( $known );

		// The season: the run of months around the peak that stay well above
		// average, wrapping from December into January.
		$threshold = $mean * self::SEASON_RATIO;
		$season    = array( $peak );

		for ( $i = 1; $i < 12; $i++ ) {
			$prev = ( ( $peak - $i - 1 ) % 12 + 12 ) % 12 + 1;
			if ( null === $average[ $prev ] || $average[ $prev ] < $threshold || in_array( $prev, $season, true ) ) {
				break;
			}
			array_unshift( $season, $prev );
		}

		for ( $i = 1; $i < 12; $i++ ) {
			$next = ( $peak + $i - 1 ) % 12 + 1;
			if ( null === $average[ $next ] || $average[ $next ] < $threshold || in_array( $next, $season, true ) ) {
				break;
			}
			$season[] = $next;
		}

		return array(
			'average'  => $average,
			'mean'     => $mean,
			'peak'     => $peak,
			'strength' => $known[ $peak ] / $mean,
			'season'   => $season,
			'total'    => $total,
			'months'   => count( $months ),
		);
	}

	/**
	 * Whether a profile is seasonal enough to plan around.
	 *
	 * @param array $profile Profile.
	 * @return bool
	 */
	public static function is_seasonal( $profile ) {
		return $profile
			&& $profile['total'] >= (int) HWCH_Settings::get( 'season_min_clicks' )
			&& $profile['strength'] >= (int) HWCH_Settings::get( 'season_strength' ) / 100
			&& count( $profile['season'] ) <= 6;
	}

	/**
	 * When the season next starts, whether it is on now, and the refresh window
	 * before it.
	 *
	 * @param array $profile Profile.
	 * @param int   $now     Timestamp.
	 * @return array start, opens, due (timestamps), in_season (bool).
	 */
	public static function timing( $profile, $now = 0 ) {
		$now       = $now ? $now : time();
		$this_cal  = (int) gmdate( 'n', $now );
		$first     = (int) $profile['season'][0];
		$in_season = in_array( $this_cal, $profile['season'], true );

		if ( $in_season ) {
			// The start of the season that is on now, which may be last year.
			$offset = ( $this_cal - $first + 12 ) % 12;
			$start  = strtotime( gmdate( 'Y-m-01', $now ) . ' -' . $offset . ' months' );
		} else {
			$year  = (int) gmdate( 'Y', $now );
			$start = gmmktime( 0, 0, 0, $first, 1, $first > $this_cal ? $year : $year + 1 );
		}

		return array(
			'start'     => $start,
			'opens'     => $start - (int) HWCH_Settings::get( 'season_lead_max' ) * WEEK_IN_SECONDS,
			'due'       => $start - (int) HWCH_Settings::get( 'season_lead_min' ) * WEEK_IN_SECONDS,
			'in_season' => $in_season,
		);
	}

	/**
	 * Seasonal articles with their timing and status, for the planner.
	 *
	 * @param string $lang Language code, or '' for all.
	 * @return array
	 */
	public static function rows( $lang = '' ) {
		$profiles = self::profiles();
		$ids      = array_keys( $profiles );

		if ( $lang ) {
			$ids = HWCH_Lang::ids_in( $ids, $lang );
		}

		$metrics = HWCH_Overlap::metrics();
		$now     = time();
		$out     = array();

		foreach ( $ids as $id ) {
			$profile = $profiles[ $id ];

			if ( ! self::is_seasonal( $profile ) ) {
				continue;
			}

			$dismissed = (int) get_post_meta( $id, '_hwch_dismissed_seasonal', true );

			if ( $dismissed > $now ) {
				continue;
			}

			$timing   = self::timing( $profile, $now );
			$modified = strtotime( get_post_field( 'post_modified_gmt', $id ) . ' UTC' );

			// Updated since shortly before the window opened: already done.
			$updated = $modified >= $timing['opens'] - 30 * DAY_IN_SECONDS;

			if ( $timing['in_season'] ) {
				$status = 'in_season';
			} elseif ( $updated ) {
				$status = 'done';
			} elseif ( $now >= $timing['due'] ) {
				$status = 'late';
			} elseif ( $now >= $timing['opens'] ) {
				$status = 'now';
			} else {
				$status = 'soon';
			}

			$out[] = array(
				'id'       => $id,
				'profile'  => $profile,
				'timing'   => $timing,
				'status'   => $status,
				'modified' => $modified,
				'clicks'   => isset( $metrics[ $id ] ) ? (int) $metrics[ $id ]['clicks'] : 0,
			);
		}

		return $out;
	}

	/**
	 * Seasonality per destination: the clicks of its English articles added
	 * up per calendar month.
	 *
	 * @return array List of array( slug, name, average, mean, peak, season, total, articles ), most clicks first.
	 */
	public static function destinations() {
		$profiles = self::profiles();
		$english  = array_flip( HWCH_Reports::english_ids( array_keys( $profiles ) ) );
		$names    = HWCH_Overlap::destinations();
		$sums     = array();

		foreach ( $profiles as $id => $profile ) {
			if ( ! isset( $english[ $id ] ) ) {
				continue;
			}

			foreach ( HWCH_Overlap::places( $id ) as $slug ) {
				if ( ! isset( $sums[ $slug ] ) ) {
					$sums[ $slug ] = array( 'average' => array_fill( 1, 12, 0 ), 'articles' => 0 );
				}

				foreach ( $profile['average'] as $cal => $value ) {
					$sums[ $slug ]['average'][ $cal ] += (float) $value;
				}

				++$sums[ $slug ]['articles'];
			}
		}

		$out = array();

		foreach ( $sums as $slug => $data ) {
			$total = array_sum( $data['average'] );

			if ( $total < 1 ) {
				continue;
			}

			$mean     = $total / 12;
			$average  = $data['average'];
			arsort( $average );
			$peak     = (int) key( $average );
			$term     = get_term_by( 'slug', $slug, 'post_tag' );

			$out[] = array(
				'slug'     => $slug,
				'name'     => $term && ! is_wp_error( $term ) ? $term->name : ucwords( str_replace( '-', ' ', $slug ) ),
				'average'  => $data['average'],
				'mean'     => $mean,
				'peak'     => $peak,
				'strength' => $data['average'][ $peak ] / $mean,
				'total'    => $total,
				'articles' => $data['articles'],
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $b['total'] <=> $a['total'];
			}
		);

		return $out;
	}

	/**
	 * A season as words: "July", or "June to August".
	 *
	 * @param int[] $season Calendar months.
	 * @return string
	 */
	public static function season_label( $season ) {
		$first = self::month_name( reset( $season ) );

		if ( count( $season ) < 2 ) {
			return $first;
		}

		/* translators: 1: first month, 2: last month */
		return sprintf( __( '%1$s to %2$s', 'hw-content-health' ), $first, self::month_name( end( $season ) ) );
	}

	/**
	 * A month's name.
	 *
	 * @param int  $cal   1-12.
	 * @param bool $short Three-letter form.
	 * @return string
	 */
	public static function month_name( $cal, $short = false ) {
		return date_i18n( $short ? 'M' : 'F', gmmktime( 12, 0, 0, (int) $cal, 1, 2026 ) );
	}

	/**
	 * Renders the tab.
	 *
	 * @return void
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification
		$view  = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'soon';
		$view  = in_array( $view, array( 'soon', 'all', 'places' ), true ) ? $view : 'soon';
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		if ( ! HWCH_Store::has_data() ) {
			echo '<p class="hwch-empty">' . esc_html__( 'This report appears after the first successful sync.', 'hw-content-health' ) . '</p>';
			return;
		}

		$progress = self::progress();

		if ( 'places' !== $view ) {
			HWCH_Lang::picker();
		}

		$base = admin_url( 'admin.php?page=hwch&tab=seasonal' );
		?>
		<ul class="subsubsub hwch-filter">
			<li><a href="<?php echo esc_url( add_query_arg( 'view', 'soon', $base ) ); ?>" class="<?php echo 'soon' === $view ? 'current' : ''; ?>"><?php esc_html_e( 'Refresh soon', 'hw-content-health' ); ?></a> |</li>
			<li><a href="<?php echo esc_url( add_query_arg( 'view', 'all', $base ) ); ?>" class="<?php echo 'all' === $view ? 'current' : ''; ?>"><?php esc_html_e( 'All seasonal articles', 'hw-content-health' ); ?></a> |</li>
			<li><a href="<?php echo esc_url( add_query_arg( 'view', 'places', $base ) ); ?>" class="<?php echo 'places' === $view ? 'current' : ''; ?>"><?php esc_html_e( 'Destinations by month', 'hw-content-health' ); ?></a></li>
		</ul>
		<div class="clear"></div>

		<?php if ( $progress['done'] < $progress['total'] ) : ?>
			<div class="notice notice-info inline hwch-inline-notice">
				<p>
					<?php
					/* translators: 1: months fetched, 2: months available */
					echo esc_html( sprintf( __( 'Monthly figures are still being collected: %1$s of %2$s months so far. A few more are added every hour in the background; this button fetches the rest now (it can take a couple of minutes).', 'hw-content-health' ), number_format_i18n( $progress['done'] ), number_format_i18n( $progress['total'] ) ) );
					?>
				</p>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'hwch_action' ); ?>
						<input type="hidden" name="action" value="hwch_season_fetch">
						<p><button class="button"><?php esc_html_e( 'Fetch the remaining months', 'hw-content-health' ); ?></button></p>
					</form>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php

		if ( 'places' === $view ) {
			self::view_places();
			return;
		}

		$rows = self::rows( HWCH_Lang::current() );

		if ( 'soon' === $view ) {
			// Anything whose refresh window is open, overdue, or opens within four weeks.
			$horizon = time() + 4 * WEEK_IN_SECONDS;
			$rows    = array_values(
				array_filter(
					$rows,
					function ( $row ) use ( $horizon ) {
						return in_array( $row['status'], array( 'now', 'late' ), true ) || ( 'soon' === $row['status'] && $row['timing']['opens'] <= $horizon );
					}
				)
			);
		}

		$order = array( 'late' => 0, 'now' => 1, 'soon' => 2, 'in_season' => 3, 'done' => 4 );

		usort(
			$rows,
			function ( $a, $b ) use ( $order ) {
				return array( $order[ $a['status'] ], $a['timing']['due'], -$a['clicks'] ) <=> array( $order[ $b['status'] ], $b['timing']['due'], -$b['clicks'] );
			}
		);

		$total = count( $rows );
		$rows  = array_slice( $rows, ( $paged - 1 ) * HWCH_Export::per( 50 ), HWCH_Export::per( 50 ) );
		$s     = HWCH_Settings::all();
		?>
		<p class="hwch-intro">
			<?php
			if ( 'soon' === $view ) {
				printf(
					/* translators: 1: weeks, 2: weeks */
					esc_html__( 'Seasonal articles whose season is coming up. Refreshing %1$s to %2$s weeks before a season starts gives Google time to recrawl the page before searches rise. An article\'s season is the run of months where it gets at least a quarter more clicks than its average month; articles updated shortly before their refresh window opened count as done.', 'hw-content-health' ),
					esc_html( $s['season_lead_min'] ),
					esc_html( $s['season_lead_max'] )
				);
			} else {
				printf(
					/* translators: 1: clicks, 2: ratio */
					esc_html__( 'Every article with a clear season: at least %1$s clicks over the months stored, and a best month at least %2$s times its average month. Search Console keeps 16 months, so most months have been seen only once; treat a season as a strong hint, not a certainty.', 'hw-content-health' ),
					esc_html( number_format_i18n( $s['season_min_clicks'] ) ),
					esc_html( number_format_i18n( $s['season_strength'] / 100, 1 ) )
				);
			}
			?>
		</p>
		<p class="hwch-count">
			<?php
			/* translators: %s: number */
			echo esc_html( sprintf( _n( '%s article', '%s articles', $total, 'hw-content-health' ), number_format_i18n( $total ) ) );
			?>
		</p>
		<?php if ( ! $total ) : ?>
			<p class="hwch-empty"><?php echo esc_html( 'soon' === $view ? __( 'No seasons coming up in the next few weeks. "All seasonal articles" shows the whole year.', 'hw-content-health' ) : __( 'No seasonal articles yet. They appear once enough months are stored.', 'hw-content-health' ) ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Article', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Season', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Clicks by month', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Refresh by', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Last updated', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'hw-content-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php self::row( $row ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		HWCH_Admin::paginate( $total, $paged, 50 );
	}

	/**
	 * One article in the planner.
	 *
	 * @param array $row Row from rows().
	 * @return void
	 */
	private static function row( $row ) {
		$id      = $row['id'];
		$p       = $row['profile'];
		$t       = $row['timing'];
		$queued  = (int) get_post_meta( $id, '_hwch_queued', true );
		$url     = get_permalink( $id );
		$date    = get_option( 'date_format' );
		$days_to = (int) ceil( ( $t['start'] - time() ) / DAY_IN_SECONDS );
		?>
		<tr class="<?php echo 'done' === $row['status'] ? 'hwch-row-done' : ''; ?>">
			<td>
				<strong><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a></strong>
				<div class="hwch-url"><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ); ?></a></div>
				<?php if ( $queued ) : ?>
					<?php /* translators: %s: date */ ?>
					<span class="hwch-badge"><?php echo esc_html( sprintf( __( 'Queued %s', 'hw-content-health' ), date_i18n( $date, $queued ) ) ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<strong><?php echo esc_html( self::season_label( $p['season'] ) ); ?></strong>
				<div class="hwch-muted">
					<?php
					/* translators: 1: month, 2: ratio */
					echo esc_html( sprintf( __( 'Peaks in %1$s at %2$s× an average month', 'hw-content-health' ), self::month_name( $p['peak'] ), number_format_i18n( $p['strength'], 1 ) ) );
					?>
				</div>
			</td>
			<td><?php echo HWCH_Chart::months( $p['average'], $p['season'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
			<td>
				<?php
				switch ( $row['status'] ) {
					case 'now':
						echo '<span class="hwch-status hwch-status--now">' . esc_html__( 'Refresh now', 'hw-content-health' ) . '</span>';
						/* translators: %s: date */
						echo '<div class="hwch-muted">' . esc_html( sprintf( __( 'By %s', 'hw-content-health' ), date_i18n( $date, $t['due'] ) ) ) . '</div>';
						break;
					case 'late':
						echo '<span class="hwch-status hwch-status--late">' . esc_html__( 'Late', 'hw-content-health' ) . '</span>';
						/* translators: %s: number of days */
						echo '<div class="hwch-muted">' . esc_html( sprintf( _n( 'Season starts in %s day', 'Season starts in %s days', $days_to, 'hw-content-health' ), number_format_i18n( $days_to ) ) ) . '</div>';
						break;
					case 'soon':
						echo esc_html( date_i18n( $date, $t['due'] ) );
						/* translators: %s: date */
						echo '<div class="hwch-muted">' . esc_html( sprintf( __( 'Window opens %s', 'hw-content-health' ), date_i18n( $date, $t['opens'] ) ) ) . '</div>';
						break;
					case 'in_season':
						echo '<span class="hwch-muted">' . esc_html__( 'In season now', 'hw-content-health' ) . '</span>';
						break;
					default:
						echo '<span class="hwch-status hwch-status--done">' . esc_html__( 'Updated in time', 'hw-content-health' ) . '</span>';
				}
				?>
			</td>
			<td>
				<?php echo esc_html( date_i18n( $date, $row['modified'] ) ); ?>
				<?php /* translators: %s: time since */ ?>
				<div class="hwch-muted"><?php echo esc_html( sprintf( __( '%s ago', 'hw-content-health' ), human_time_diff( $row['modified'] ) ) ); ?></div>
			</td>
			<td class="hwch-actions">
				<a class="button button-small button-primary" href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit', 'hw-content-health' ); ?></a>
				<?php if ( ! $queued ) : ?>
					<?php HWCH_Admin::button( 'queue', $id, __( 'Queue for refresh', 'hw-content-health' ) ); ?>
				<?php endif; ?>
				<a class="button button-small" href="<?php echo esc_url( HWCH_Brief::url( $id ) ); ?>"><?php esc_html_e( 'Refresh brief', 'hw-content-health' ); ?></a>
				<?php HWCH_Admin::button( 'dismiss', $id, __( 'Not seasonal', 'hw-content-health' ), array( 'report' => 'seasonal' ) ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Destinations by month: a calendar of when each destination's articles
	 * get their clicks.
	 *
	 * @return void
	 */
	private static function view_places() {
		$rows = array_slice( self::destinations(), 0, 60 );
		?>
		<p class="hwch-intro"><?php esc_html_e( 'When each destination gets its clicks, from its English articles added together. Darker months get more of the year\'s clicks. The 60 destinations with the most clicks are shown; use it to time commissioning as well as refreshes.', 'hw-content-health' ); ?></p>
		<?php if ( ! $rows ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'No destination data yet. It needs stored monthly figures and articles carrying destination tags.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<div class="hwch-scroll">
			<table class="widefat hwch-table hwch-heat">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Destination', 'hw-content-health' ); ?></th>
						<?php for ( $m = 1; $m <= 12; $m++ ) : ?>
							<th class="hwch-heat-month"><?php echo esc_html( self::month_name( $m, true ) ); ?></th>
						<?php endfor; ?>
						<th><?php esc_html_e( 'Peak', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Articles', 'hw-content-health' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php $top = max( $row['average'] ); ?>
						<tr>
							<td><strong><?php echo esc_html( $row['name'] ); ?></strong></td>
							<?php for ( $m = 1; $m <= 12; $m++ ) : ?>
								<?php
								$value = $row['average'][ $m ];
								$alpha = $top > 0 ? round( 0.08 + 0.82 * $value / $top, 2 ) : 0;
								/* translators: 1: month, 2: clicks */
								$label = sprintf( __( '%1$s: about %2$s clicks', 'hw-content-health' ), self::month_name( $m ), number_format_i18n( round( $value ) ) );
								?>
								<td class="hwch-heat-cell" style="background-color: rgba(34, 113, 177, <?php echo esc_attr( $alpha ); ?>);" title="<?php echo esc_attr( $label ); ?>"><span class="screen-reader-text"><?php echo esc_html( $label ); ?></span></td>
							<?php endfor; ?>
							<td>
								<?php echo esc_html( self::month_name( $row['peak'] ) ); ?>
								<?php /* translators: %s: ratio */ ?>
								<div class="hwch-muted"><?php echo esc_html( sprintf( __( '%s× average', 'hw-content-health' ), number_format_i18n( $row['strength'], 1 ) ) ); ?></div>
							</td>
							<td><?php echo esc_html( number_format_i18n( $row['articles'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
