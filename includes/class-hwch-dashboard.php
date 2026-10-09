<?php
/**
 * Dashboard: the blog's Search Console performance at a glance.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetches, caches and renders the dashboard.
 */
class HWCH_Dashboard {

	const PERIODS = array( 7, 28, 90 );
	const CACHE   = 12 * HOUR_IN_SECONDS;

	/**
	 * The date ranges for a period and the one before it. Search Console data
	 * lags about three days, so the current period ends three days ago.
	 *
	 * @param int $days Period length.
	 * @return array cur_start, cur_end, prev_start, prev_end (Y-m-d).
	 */
	public static function ranges( $days ) {
		$end        = strtotime( '-3 days' );
		$cur_start  = strtotime( '-' . ( $days - 1 ) . ' days', $end );
		$prev_end   = strtotime( '-1 day', $cur_start );
		$prev_start = strtotime( '-' . ( $days - 1 ) . ' days', $prev_end );

		return array(
			'cur_start'  => gmdate( 'Y-m-d', $cur_start ),
			'cur_end'    => gmdate( 'Y-m-d', $end ),
			'prev_start' => gmdate( 'Y-m-d', $prev_start ),
			'prev_end'   => gmdate( 'Y-m-d', $prev_end ),
		);
	}

	/**
	 * Adds rows into a map of key => clicks, impressions, weighted position.
	 *
	 * @param array $rows      Raw API rows.
	 * @param int   $key_index Which key to group by.
	 * @return array Map of key => array( clicks, impressions, position ).
	 */
	public static function totals_by( $rows, $key_index = 0 ) {
		$map = array();

		foreach ( (array) $rows as $row ) {
			$key = $row['keys'][ $key_index ];

			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = array( 'clicks' => 0, 'impressions' => 0, 'pos_sum' => 0 );
			}

			$map[ $key ]['clicks']      += (int) $row['clicks'];
			$map[ $key ]['impressions'] += (int) $row['impressions'];
			$map[ $key ]['pos_sum']     += (float) $row['position'] * (int) $row['impressions'];
		}

		foreach ( $map as $key => $data ) {
			$map[ $key ]['position'] = $data['impressions'] ? $data['pos_sum'] / $data['impressions'] : 0;
			$map[ $key ]['ctr']      = $data['impressions'] ? $data['clicks'] / $data['impressions'] : 0;
			unset( $map[ $key ]['pos_sum'] );
		}

		return $map;
	}

	/**
	 * Clicks, impressions, CTR and position summed across a set of rows.
	 *
	 * @param array $map Output of totals_by().
	 * @return array
	 */
	public static function sum( $map ) {
		$clicks = 0;
		$impr   = 0;
		$pos    = 0;

		foreach ( $map as $data ) {
			$clicks += $data['clicks'];
			$impr   += $data['impressions'];
			$pos    += $data['position'] * $data['impressions'];
		}

		return array(
			'clicks'      => $clicks,
			'impressions' => $impr,
			'ctr'         => $impr ? $clicks / $impr : 0,
			'position'    => $impr ? $pos / $impr : 0,
		);
	}

	/**
	 * The dashboard data for a period, from the cache or fresh.
	 *
	 * @param int  $days    7, 28 or 90.
	 * @param bool $refresh Ignore the cache.
	 * @return array|WP_Error
	 */
	public static function data( $days, $refresh = false, $type = 'web' ) {
		$lang      = HWCH_Lang::current();
		$cache_key = 'hwch_dash2_' . $type . '_' . ( $lang ? $lang : 'all' ) . '_' . $days;
		$cached    = $refresh ? false : get_transient( $cache_key );

		if ( $cached ) {
			return $cached;
		}

		$r = self::ranges( $days );

		$opts  = array( 'type' => $type, 'lang' => $lang );
		// All languages: the property's own total, exactly as Search Console
		// counts it. Any page filter makes Google count page by page, which
		// gives different totals even when every page matches.
		$dates = HWCH_GSC::query( array( 'date' ), $r['prev_start'], $r['cur_end'], $opts + ( $lang ? array() : array( 'nofilter' => true ) ) );
		if ( is_wp_error( $dates ) ) {
			return $dates;
		}

		$fetch = array();
		// Discover has no search queries, so only pages are fetched for it.
		foreach ( 'discover' === $type ? array( 'page' ) : array( 'page', 'query' ) as $dimension ) {
			foreach ( array( 'cur', 'prev' ) as $period ) {
				$rows = HWCH_GSC::query( array( $dimension ), $r[ $period . '_start' ], $r[ $period . '_end' ], $opts + array( 'max' => 5000 ) );

				if ( is_wp_error( $rows ) ) {
					return $rows;
				}

				$fetch[ $dimension ][ $period ] = self::totals_by( $rows );
			}
		}

		// Daily series for both periods, aligned day by day.
		$by_date = self::totals_by( $dates );
		$cur     = array();
		$prev    = array();

		for ( $i = 0; $i < $days; $i++ ) {
			$c = gmdate( 'Y-m-d', strtotime( $r['cur_start'] . ' +' . $i . ' days' ) );
			$p = gmdate( 'Y-m-d', strtotime( $r['prev_start'] . ' +' . $i . ' days' ) );

			$cur[ $c ]  = isset( $by_date[ $c ] ) ? $by_date[ $c ] : array( 'clicks' => 0, 'impressions' => 0, 'position' => 0, 'ctr' => 0 );
			$prev[ $p ] = isset( $by_date[ $p ] ) ? $by_date[ $p ] : array( 'clicks' => 0, 'impressions' => 0, 'position' => 0, 'ctr' => 0 );
		}

		// Pages: the top 20 by clicks now, and the biggest movers either way.
		$pages_cur  = $fetch['page']['cur'];
		$pages_prev = $fetch['page']['prev'];
		$movers     = array();

		foreach ( array_unique( array_merge( array_keys( $pages_cur ), array_keys( $pages_prev ) ) ) as $url ) {
			$now    = isset( $pages_cur[ $url ] ) ? $pages_cur[ $url ]['clicks'] : 0;
			$before = isset( $pages_prev[ $url ] ) ? $pages_prev[ $url ]['clicks'] : 0;

			$movers[ $url ] = $now - $before;
		}

		arsort( $movers );
		$gains = array_slice( array_filter( $movers, function ( $d ) { return $d > 0; } ), 0, 10, true );
		asort( $movers );
		$drops = array_slice( array_filter( $movers, function ( $d ) { return $d < 0; } ), 0, 10, true );

		$top_pages = self::top( $pages_cur, 20 );
		$top_query = isset( $fetch['query'] ) ? self::top( $fetch['query']['cur'], 20 ) : array();

		$data = array(
			'days'      => $days,
			'ranges'    => $r,
			'fetched'   => time(),
			'total_cur' => self::sum( $cur ),
			'total_pre' => self::sum( $prev ),
			'daily'     => array( 'cur' => $cur, 'prev' => $prev ),
			'pages'     => self::pick( $top_pages, $pages_cur, $pages_prev ),
			'gains'     => self::pick( array_keys( $gains ), $pages_cur, $pages_prev ),
			'drops'     => self::pick( array_keys( $drops ), $pages_cur, $pages_prev ),
			'queries'   => isset( $fetch['query'] ) ? self::pick( $top_query, $fetch['query']['cur'], $fetch['query']['prev'] ) : array(),
		);

		set_transient( $cache_key, $data, self::CACHE );

		return $data;
	}

	/**
	 * The keys with the most clicks.
	 *
	 * @param array $map   Output of totals_by().
	 * @param int   $count How many.
	 * @return string[]
	 */
	private static function top( $map, $count ) {
		uasort(
			$map,
			function ( $a, $b ) {
				return array( $b['clicks'], $b['impressions'] ) <=> array( $a['clicks'], $a['impressions'] );
			}
		);

		return array_slice( array_keys( $map ), 0, $count );
	}

	/**
	 * Current and previous figures for a list of keys.
	 *
	 * @param string[] $keys Keys.
	 * @param array    $cur  Current map.
	 * @param array    $prev Previous map.
	 * @return array
	 */
	private static function pick( $keys, $cur, $prev ) {
		$empty = array( 'clicks' => 0, 'impressions' => 0, 'position' => 0, 'ctr' => 0 );
		$out   = array();

		foreach ( $keys as $key ) {
			$out[] = array(
				'key'  => $key,
				'cur'  => isset( $cur[ $key ] ) ? $cur[ $key ] : $empty,
				'prev' => isset( $prev[ $key ] ) ? $prev[ $key ] : $empty,
			);
		}

		return $out;
	}

	/**
	 * Renders the Dashboard tab.
	 *
	 * @return void
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification
		$days    = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 28;
		$days    = in_array( $days, self::PERIODS, true ) ? $days : 28;
		$refresh = isset( $_GET['refresh'] ) && current_user_can( HWCH_Actions::CAP ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['refresh'] ) ), 'hwch_refresh' );
		// phpcs:enable

		HWCH_Lang::picker();

		$data = self::data( $days, $refresh );

		self::period_switch( $days, self::PERIODS );

		if ( is_wp_error( $data ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $data->get_error_message() ) . '</p></div>';
			return;
		}

		$r   = $data['ranges'];
		$now = $data['total_cur'];
		$was = $data['total_pre'];
		?>
		<p class="hwch-intro">
			<?php
			printf(
				/* translators: 1-4: dates, 5: time since */
				esc_html__( '%1$s to %2$s, compared with %3$s to %4$s. Fetched %5$s ago.', 'hw-content-health' ),
				esc_html( self::date( $r['cur_start'] ) ),
				esc_html( self::date( $r['cur_end'] ) ),
				esc_html( self::date( $r['prev_start'] ) ),
				esc_html( self::date( $r['prev_end'] ) ),
				esc_html( human_time_diff( $data['fetched'] ) )
			);
			?>
			<a href="<?php echo esc_url( add_query_arg( 'refresh', wp_create_nonce( 'hwch_refresh' ) ) ); ?>"><?php esc_html_e( 'Refresh', 'hw-content-health' ); ?></a>
		</p>

		<div class="hwch-kpis">
			<?php
			self::kpi( __( 'Clicks', 'hw-content-health' ), number_format_i18n( $now['clicks'] ), HWCH_Chart::change( $now['clicks'], $was['clicks'] ) );
			self::kpi( __( 'Impressions', 'hw-content-health' ), number_format_i18n( $now['impressions'] ), HWCH_Chart::change( $now['impressions'], $was['impressions'] ) );
			self::kpi( __( 'CTR', 'hw-content-health' ), number_format_i18n( $now['ctr'] * 100, 2 ) . '%', HWCH_Chart::change( $now['ctr'], $was['ctr'] ) );
			self::kpi( __( 'Avg. position', 'hw-content-health' ), number_format_i18n( $now['position'], 1 ), HWCH_Chart::change( $now['position'], $was['position'], 'position' ) );
			?>
		</div>

		<?php $annotations = HWCH_Annotations::for_chart( array_keys( $data['daily']['cur'] ), HWCH_Lang::current() ); ?>
		<div class="hwch-charts">
			<?php foreach ( array( 'clicks' => __( 'Clicks per day', 'hw-content-health' ), 'impressions' => __( 'Impressions per day', 'hw-content-health' ) ) as $metric => $label ) : ?>
				<div class="hwch-chart-box">
					<h3><?php echo esc_html( $label ); ?> <span class="hwch-legend"><i class="hwch-key"></i><?php esc_html_e( 'this period', 'hw-content-health' ); ?> <i class="hwch-key hwch-key--prev"></i><?php esc_html_e( 'previous', 'hw-content-health' ); ?><?php if ( $annotations['marks'] ) : ?> <i class="hwch-key hwch-key--band"></i><?php esc_html_e( 'Google update', 'hw-content-health' ); ?> <i class="hwch-key hwch-key--change"></i><?php esc_html_e( 'your changes', 'hw-content-health' ); ?><?php endif; ?></span></h3>
					<?php
					echo HWCH_Chart::lines( // phpcs:ignore WordPress.Security.EscapeOutput
						array(
							array( 'values' => wp_list_pluck( $data['daily']['prev'], $metric ), 'colour' => '#8c8f94', 'dashed' => true ),
							array( 'values' => wp_list_pluck( $data['daily']['cur'], $metric ), 'colour' => 'clicks' === $metric ? '#2271b1' : '#6d28d9' ),
						),
						array_map( array( __CLASS__, 'date' ), array_keys( $data['daily']['cur'] ) ),
						$label,
						$annotations['marks']
					);
					?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php HWCH_Annotations::render_list( $annotations['items'] ); ?>

		<h2><?php esc_html_e( 'Top 20 pages', 'hw-content-health' ); ?></h2>
		<?php self::table( $data['pages'], 'page' ); ?>

		<div class="hwch-two">
			<div>
				<h2><?php esc_html_e( 'Biggest gains', 'hw-content-health' ); ?></h2>
				<?php self::table( $data['gains'], 'page', true ); ?>
			</div>
			<div>
				<h2><?php esc_html_e( 'Biggest drops', 'hw-content-health' ); ?></h2>
				<?php self::table( $data['drops'], 'page', true ); ?>
			</div>
		</div>

		<h2><?php esc_html_e( 'Top 20 search queries', 'hw-content-health' ); ?></h2>
		<?php self::table( $data['queries'], 'query' ); ?>
		<?php
	}

	/**
	 * A table of pages or queries with their change.
	 *
	 * @param array  $rows    Rows from pick().
	 * @param string $type    page or query.
	 * @param bool   $compact Fewer columns, for the gains and drops.
	 * @return void
	 */
	public static function table( $rows, $type, $compact = false, $no_position = false ) {
		if ( ! $rows ) {
			echo '<p class="hwch-muted">' . esc_html__( 'None in this period.', 'hw-content-health' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php echo esc_html( 'page' === $type ? __( 'Page', 'hw-content-health' ) : __( 'Query', 'hw-content-health' ) ); ?></th>
					<th><?php esc_html_e( 'Clicks', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Change', 'hw-content-health' ); ?></th>
					<?php if ( ! $compact ) : ?>
						<th><?php esc_html_e( 'Impressions', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Change', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'CTR', 'hw-content-health' ); ?></th>
					<?php endif; ?>
					<?php if ( ! $no_position ) : ?>
						<th><?php esc_html_e( 'Avg. position', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Change', 'hw-content-health' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo 'page' === $type ? self::page_link( $row['key'] ) : esc_html( $row['key'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['cur']['clicks'] ) ); ?></td>
						<td>
							<?php
							if ( $compact ) {
								$diff = $row['cur']['clicks'] - $row['prev']['clicks'];
								printf( '<span class="%1$s">%2$s%3$s</span>', $diff < 0 ? 'hwch-down' : 'hwch-up', $diff > 0 ? '+' : '', esc_html( number_format_i18n( $diff ) ) );
							} else {
								echo HWCH_Chart::change( $row['cur']['clicks'], $row['prev']['clicks'] ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
							?>
						</td>
						<?php if ( ! $compact ) : ?>
							<td><?php echo esc_html( number_format_i18n( $row['cur']['impressions'] ) ); ?></td>
							<td><?php echo HWCH_Chart::change( $row['cur']['impressions'], $row['prev']['impressions'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['cur']['ctr'] * 100, 1 ) ); ?>%</td>
						<?php endif; ?>
						<?php if ( ! $no_position ) : ?>
							<td><?php echo esc_html( $row['cur']['position'] ? number_format_i18n( $row['cur']['position'], 1 ) : '–' ); ?></td>
							<td><?php echo HWCH_Chart::change( $row['cur']['position'], $row['prev']['position'], 'position' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * A page URL as a short path, linked to the page, with an edit link when
	 * it is a post on this site.
	 *
	 * @param string $url URL.
	 * @return string HTML.
	 */
	public static function page_link( $url ) {
		$path    = wp_parse_url( $url, PHP_URL_PATH );
		$post_id = HWCH_Store::post_id_for_url( $url );
		$title   = $post_id ? get_the_title( $post_id ) : '';
		$html    = '';

		if ( $title ) {
			$html .= '<strong><a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . esc_html( $title ) . '</a></strong>';
		}

		// Decoded, so non-Latin addresses read as text rather than %E4%B8%96…
		$html .= '<div class="hwch-url"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( rawurldecode( $path ? $path : $url ) ) . '</a></div>';

		return $html;
	}

	/**
	 * A KPI card.
	 *
	 * @param string $label  Label.
	 * @param string $value  Formatted value.
	 * @param string $change Change HTML.
	 * @return void
	 */
	public static function kpi( $label, $value, $change ) {
		printf(
			'<div class="hwch-kpi"><div class="hwch-kpi-label">%1$s</div><div class="hwch-kpi-value">%2$s</div>%3$s</div>',
			esc_html( $label ),
			esc_html( $value ),
			'' === $change ? '' : '<div class="hwch-kpi-change">' . $change . ' ' . esc_html__( 'vs previous', 'hw-content-health' ) . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}

	/**
	 * Period links.
	 *
	 * @param int   $days    Current period.
	 * @param int[] $periods Choices.
	 * @return void
	 */
	public static function period_switch( $days, $periods ) {
		echo '<div class="hwch-periods">';
		foreach ( $periods as $p ) {
			printf(
				'<a class="button%1$s" href="%2$s">%3$s</a> ',
				$p === $days ? ' button-primary' : '',
				esc_url( remove_query_arg( 'refresh', add_query_arg( 'days', $p ) ) ),
				/* translators: %d: number of days */
				esc_html( sprintf( __( 'Last %d days', 'hw-content-health' ), $p ) )
			);
		}
		echo '</div>';
	}

	/**
	 * A Y-m-d date, formatted for the site.
	 *
	 * @param string $ymd Date.
	 * @return string
	 */
	public static function date( $ymd ) {
		return date_i18n( get_option( 'date_format' ), strtotime( $ymd . ' 12:00:00 UTC' ) );
	}
}
