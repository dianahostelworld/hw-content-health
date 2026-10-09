<?php
/**
 * Refresh impact: how each refreshed article performed before and after its
 * refresh.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Compares Search Console figures before and after each refresh.
 *
 * An article counts as refreshed when it was queued for a refresh in Worth
 * refreshing and then updated (detected automatically), or when someone marks
 * it as refreshed. The comparison uses daily averages over the same number of
 * days on each side, so a refresh measured after 20 days is still compared
 * fairly with the 28 days before it.
 */
class HWCH_Impact {

	const MIN_DAYS = 14;

	/**
	 * Refreshed articles with their before and after figures.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array|WP_Error
	 */
	public static function data( $refresh = false ) {
		$cached = $refresh ? false : get_transient( 'hwch_impact' );

		if ( $cached ) {
			return $cached;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_hwch_refreshed', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		$window = (int) HWCH_Settings::get( 'impact_days' );
		$end    = strtotime( gmdate( 'Y-m-d', strtotime( '-3 days' ) ) . ' 00:00:00 UTC' );
		$items  = array();

		foreach ( $ids as $id ) {
			$refreshed = (int) get_post_meta( $id, '_hwch_refreshed', true );
			$day       = strtotime( gmdate( 'Y-m-d', $refreshed ) . ' 00:00:00 UTC' );
			$after     = (int) floor( ( $end - $day ) / DAY_IN_SECONDS );
			$days      = max( 0, min( $window, $after ) );

			$items[ $id ] = array(
				'id'         => $id,
				'url'        => get_permalink( $id ),
				'refreshed'  => $refreshed,
				'days'       => $days,
				'before'     => array( gmdate( 'Y-m-d', $day - $window * DAY_IN_SECONDS ), gmdate( 'Y-m-d', $day - DAY_IN_SECONDS ) ),
				'after'      => array( gmdate( 'Y-m-d', $day + DAY_IN_SECONDS ), gmdate( 'Y-m-d', $day + $days * DAY_IN_SECONDS ) ),
			);
		}

		// Search Console keeps 16 months; older refreshes are compared only
		// if both windows are still available.
		$oldest = strtotime( '-16 months' );
		$items  = array_filter(
			$items,
			function ( $item ) use ( $oldest ) {
				return strtotime( $item['before'][0] ) > $oldest;
			}
		);

		$rows = array();

		foreach ( array_chunk( $items, 15, true ) as $chunk ) {
			if ( ! $chunk ) {
				continue;
			}

			$start  = min( wp_list_pluck( wp_list_pluck( $chunk, 'before' ), 0 ) );
			$result = HWCH_GSC::query(
				array( 'page', 'date' ),
				$start,
				gmdate( 'Y-m-d', $end ),
				array( 'regex' => HWCH_Watchlists::regex( wp_list_pluck( $chunk, 'url' ) ) )
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$rows = array_merge( $rows, $result );
		}

		$by_url = array();

		foreach ( $rows as $row ) {
			$by_url[ HWCH_GSC::normalise( $row['keys'][0] ) ][ $row['keys'][1] ][] = $row;
		}

		$out = array();

		foreach ( $items as $item ) {
			$daily = isset( $by_url[ HWCH_GSC::normalise( $item['url'] ) ] ) ? $by_url[ HWCH_GSC::normalise( $item['url'] ) ] : array();

			$item['pre']  = self::window( $daily, $item['before'][0], $item['before'][1], $window );
			$item['post'] = $item['days'] ? self::window( $daily, $item['after'][0], $item['after'][1], $item['days'] ) : null;
			$out[]        = $item;
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $b['refreshed'] <=> $a['refreshed'];
			}
		);

		$data = array(
			'fetched' => time(),
			'window'  => $window,
			'items'   => $out,
		);

		set_transient( 'hwch_impact', $data, HWCH_Dashboard::CACHE );

		return $data;
	}

	/**
	 * Daily averages over a date range.
	 *
	 * @param array  $daily Map of date => rows.
	 * @param string $from  Y-m-d.
	 * @param string $to    Y-m-d.
	 * @param int    $days  Days in the range.
	 * @return array clicks and impressions per day, position.
	 */
	private static function window( $daily, $from, $to, $days ) {
		$rows = array();

		foreach ( $daily as $date => $date_rows ) {
			if ( $date >= $from && $date <= $to ) {
				$rows = array_merge( $rows, $date_rows );
			}
		}

		$sum = HWCH_Dashboard::sum( HWCH_Dashboard::totals_by( $rows ) );

		return array(
			'clicks'      => $days ? $sum['clicks'] / $days : 0,
			'impressions' => $days ? $sum['impressions'] / $days : 0,
			'position'    => $sum['position'],
		);
	}

	/**
	 * Renders the tab.
	 *
	 * @return void
	 */
	public static function render() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$refresh = isset( $_GET['refresh'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['refresh'] ) ), 'hwch_refresh' );
		$data    = self::data( $refresh );

		if ( is_wp_error( $data ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $data->get_error_message() ) . '</p></div>';
			return;
		}
		?>
		<p class="hwch-intro">
			<?php
			printf(
				/* translators: 1: days, 2: minimum days, 3: time since */
				esc_html__( 'Articles queued in Worth refreshing and then updated (the first update after queuing counts as the refresh), or marked as refreshed. Each is compared on daily averages: the %1$s days before the refresh against the same number of days after it, or as many days as have passed. Results show once there are at least %2$s days of data after the refresh. Fetched %3$s ago.', 'hw-content-health' ),
				esc_html( $data['window'] ),
				esc_html( self::MIN_DAYS ),
				esc_html( human_time_diff( $data['fetched'] ) )
			);
			?>
			<a href="<?php echo esc_url( add_query_arg( 'refresh', wp_create_nonce( 'hwch_refresh' ) ) ); ?>"><?php esc_html_e( 'Refresh', 'hw-content-health' ); ?></a>
		</p>
		<?php

		if ( ! $data['items'] ) {
			echo '<p class="hwch-empty">' . esc_html__( 'No refreshed articles yet. Queue articles in Worth refreshing; when one is updated, it appears here.', 'hw-content-health' ) . '</p>';
			return;
		}

		$measured = array_filter(
			$data['items'],
			function ( $item ) {
				return $item['days'] >= self::MIN_DAYS && $item['pre']['clicks'] > 0;
			}
		);

		if ( $measured ) {
			$changes = array();
			$better  = 0;

			foreach ( $measured as $item ) {
				$change    = ( $item['post']['clicks'] - $item['pre']['clicks'] ) / $item['pre']['clicks'] * 100;
				$changes[] = $change;
				$better   += $change > 0 ? 1 : 0;
			}

			sort( $changes );
			$n      = count( $changes );
			$median = $n % 2 ? $changes[ (int) floor( $n / 2 ) ] : ( $changes[ $n / 2 - 1 ] + $changes[ $n / 2 ] ) / 2;
			?>
			<div class="hwch-kpis">
				<?php
				HWCH_Dashboard::kpi( __( 'Refreshes measured', 'hw-content-health' ), number_format_i18n( $n ), '' );
				HWCH_Dashboard::kpi( __( 'Gained clicks', 'hw-content-health' ), number_format_i18n( $better ) . ' / ' . number_format_i18n( $n ), '' );
				HWCH_Dashboard::kpi( __( 'Median change in clicks', 'hw-content-health' ), ( $median > 0 ? '+' : '' ) . number_format_i18n( round( $median ) ) . '%', '' );
				?>
			</div>
			<?php
		}
		?>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Article', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Refreshed', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Clicks per day', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Change', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Impressions per day', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Change', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Avg. position', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Change', 'hw-content-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['items'] as $item ) : ?>
					<?php
					$ready = $item['days'] >= self::MIN_DAYS;
					$pre   = $item['pre'];
					$post  = $item['post'];
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( get_edit_post_link( $item['id'] ) ); ?>"><?php echo esc_html( get_the_title( $item['id'] ) ); ?></a></strong>
							<div class="hwch-url"><a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( rawurldecode( (string) wp_parse_url( $item['url'], PHP_URL_PATH ) ) ); ?></a></div>
						</td>
						<td>
							<?php echo esc_html( date_i18n( get_option( 'date_format' ), $item['refreshed'] ) ); ?>
							<div class="hwch-muted">
								<?php
								/* translators: %s: number of days */
								echo esc_html( sprintf( _n( '%s day of data since', '%s days of data since', $item['days'], 'hw-content-health' ), number_format_i18n( $item['days'] ) ) );
								?>
							</div>
						</td>
						<?php if ( ! $ready ) : ?>
							<td colspan="6" class="hwch-muted">
								<?php
								/* translators: %s: days remaining */
								echo esc_html( sprintf( __( 'Too early to compare. Results in %s days.', 'hw-content-health' ), number_format_i18n( self::MIN_DAYS - $item['days'] ) ) );
								?>
							</td>
						<?php else : ?>
							<td><?php echo esc_html( number_format_i18n( $pre['clicks'], 1 ) . ' → ' . number_format_i18n( $post['clicks'], 1 ) ); ?></td>
							<td><?php echo HWCH_Chart::change( $post['clicks'], $pre['clicks'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo esc_html( number_format_i18n( $pre['impressions'], 0 ) . ' → ' . number_format_i18n( $post['impressions'], 0 ) ); ?></td>
							<td><?php echo HWCH_Chart::change( $post['impressions'], $pre['impressions'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo esc_html( ( $pre['position'] ? number_format_i18n( $pre['position'], 1 ) : '–' ) . ' → ' . ( $post['position'] ? number_format_i18n( $post['position'], 1 ) : '–' ) ); ?></td>
							<td><?php echo HWCH_Chart::change( $post['position'], $pre['position'], 'position' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
