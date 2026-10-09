<?php
/**
 * Chart annotations: Google's ranking updates, and the team's own changes
 * from the Log, marked on the Dashboard charts.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Collects what happened on each day of a chart.
 */
class HWCH_Annotations {

	const FEED = 'https://status.search.google.com/incidents.json';

	/**
	 * Log actions shown on the charts, with their labels.
	 *
	 * @return array Map of action => array( singular, plural ).
	 */
	private static function kinds() {
		return array(
			/* translators: %s: number */
			'refreshed' => array( __( '%s refresh', 'hw-content-health' ), __( '%s refreshes', 'hw-content-health' ) ),
			/* translators: %s: number */
			'redirect'  => array( __( '%s redirect (merged or pruned)', 'hw-content-health' ), __( '%s redirects (merged or pruned)', 'hw-content-health' ) ),
			/* translators: %s: number */
			'unpublish' => array( __( '%s unpublished', 'hw-content-health' ), __( '%s unpublished', 'hw-content-health' ) ),
		);
	}

	/**
	 * Google Search incidents: ranking updates (core, spam, Discover…) and
	 * other issues such as indexing or Search Console data delays. The feed
	 * only lists the most recent ten, so each fetch is merged into a history
	 * kept by the plugin. Fetched at most once a day.
	 *
	 * @return array List of array( id, begin, end, title, url, ranking ), newest first.
	 */
	public static function google() {
		$history = (array) get_option( 'hwch_google_updates', array() );

		if ( ! get_transient( 'hwch_google_feed' ) ) {
			// Mark the attempt first, so a failing feed is not retried on every page load.
			set_transient( 'hwch_google_feed', 1, DAY_IN_SECONDS );

			$response = wp_remote_get( self::FEED, array( 'timeout' => 10 ) );
			$items    = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );

			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					if ( empty( $item['id'] ) || empty( $item['begin'] ) ) {
						continue;
					}

					$products = isset( $item['affected_products'] ) ? wp_list_pluck( (array) $item['affected_products'], 'title' ) : array();

					$history[ $item['id'] ] = array(
						'id'      => sanitize_text_field( $item['id'] ),
						'begin'   => gmdate( 'Y-m-d', strtotime( $item['begin'] ) ),
						'end'     => ! empty( $item['end'] ) ? gmdate( 'Y-m-d', strtotime( $item['end'] ) ) : '',
						'title'   => sanitize_text_field( isset( $item['external_desc'] ) ? $item['external_desc'] : '' ),
						'url'     => ! empty( $item['uri'] ) ? esc_url_raw( 'https://status.search.google.com/' . ltrim( $item['uri'], '/' ) ) : 'https://status.search.google.com/',
						'ranking' => in_array( 'Ranking', $products, true ),
					);
				}

				// Keep two years, which covers every period the charts show.
				$cutoff  = gmdate( 'Y-m-d', strtotime( '-2 years' ) );
				$history = array_filter(
					$history,
					function ( $item ) use ( $cutoff ) {
						return $item['begin'] >= $cutoff;
					}
				);

				update_option( 'hwch_google_updates', $history, false );
			}
		}

		$history = array_values( $history );

		usort(
			$history,
			function ( $a, $b ) {
				return strcmp( $b['begin'], $a['begin'] );
			}
		);

		return $history;
	}

	/**
	 * The team's changes per day, from the Log (not undone).
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @param string $lang  Language code, or '' for all.
	 * @return array Map of Y-m-d => array( counts => map of action => n, posts => post IDs ).
	 */
	public static function changes( $start, $end, $lang = '' ) {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT post_id, action, created_at FROM ' . HWCH_Store::table( 'log' ) . " WHERE undone_at IS NULL AND action IN ('refreshed','redirect','unpublish') AND created_at >= %s AND created_at <= %s ORDER BY created_at", // phpcs:ignore WordPress.DB.PreparedSQL
				$start . ' 00:00:00',
				$end . ' 23:59:59'
			),
			ARRAY_A
		);

		if ( $lang && $rows ) {
			$keep = array_flip( HWCH_Lang::ids_in( array_map( 'intval', wp_list_pluck( $rows, 'post_id' ) ), $lang ) );
			$rows = array_filter(
				$rows,
				function ( $row ) use ( $keep ) {
					return isset( $keep[ (int) $row['post_id'] ] );
				}
			);
		}

		$days = array();

		foreach ( (array) $rows as $row ) {
			$day = substr( $row['created_at'], 0, 10 );

			if ( ! isset( $days[ $day ] ) ) {
				$days[ $day ] = array( 'counts' => array(), 'posts' => array() );
			}

			$days[ $day ]['counts'][ $row['action'] ] = ( isset( $days[ $day ]['counts'][ $row['action'] ] ) ? $days[ $day ]['counts'][ $row['action'] ] : 0 ) + 1;
			$days[ $day ]['posts'][]                  = (int) $row['post_id'];
		}

		return $days;
	}

	/**
	 * Everything to mark on a chart of these days, and the list shown under it.
	 *
	 * @param string[] $dates The chart's days, Y-m-d, in order.
	 * @param string   $lang  Language code, or '' for all.
	 * @return array marks (for HWCH_Chart::lines), items (for the list).
	 */
	public static function for_chart( $dates, $lang = '' ) {
		$dates = array_values( $dates );

		if ( ! $dates ) {
			return array( 'marks' => array(), 'items' => array() );
		}

		$first = reset( $dates );
		$last  = end( $dates );
		$index = array_flip( $dates );
		$marks = array();
		$items = array();

		foreach ( self::google() as $update ) {
			$begin = $update['begin'];
			$end   = $update['end'] ? $update['end'] : $last;

			if ( $end < $first || $begin > $last ) {
				continue;
			}

			$from = isset( $index[ $begin ] ) ? $index[ $begin ] : 0;
			$to   = isset( $index[ $end ] ) ? $index[ $end ] : count( $dates ) - 1;

			$marks[] = array(
				'from'  => $from,
				'to'    => $to,
				'kind'  => $update['ranking'] ? 'google' : 'issue',
				'label' => $update['title'],
			);

			$items[] = array(
				'date'  => $begin,
				'end'   => $update['end'],
				'kind'  => $update['ranking'] ? 'google' : 'issue',
				'label' => $update['title'],
				'url'   => $update['url'],
			);
		}

		$kinds = self::kinds();

		foreach ( self::changes( $first, $last, $lang ) as $day => $change ) {
			$bits = array();

			foreach ( $change['counts'] as $action => $count ) {
				$bits[] = sprintf( _n( $kinds[ $action ][0], $kinds[ $action ][1], $count, 'hw-content-health' ), number_format_i18n( $count ) ); // phpcs:ignore WordPress.WP.I18n
			}

			$label = implode( ', ', $bits );

			$marks[] = array(
				'from'  => $index[ $day ],
				'to'    => $index[ $day ],
				'kind'  => 'change',
				'label' => $label,
			);

			$items[] = array(
				'date'  => $day,
				'end'   => '',
				'kind'  => 'change',
				'label' => $label,
				'posts' => array_slice( array_unique( $change['posts'] ), 0, 5 ),
				'more'  => max( 0, count( array_unique( $change['posts'] ) ) - 5 ),
			);
		}

		usort(
			$items,
			function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);

		return array( 'marks' => $marks, 'items' => $items );
	}

	/**
	 * The list of what happened, shown under the charts.
	 *
	 * @param array $items Items from for_chart().
	 * @return void
	 */
	public static function render_list( $items ) {
		if ( ! $items ) {
			return;
		}
		?>
		<div class="hwch-marks">
			<h3><?php esc_html_e( 'What happened in this period', 'hw-content-health' ); ?></h3>
			<ul>
				<?php foreach ( $items as $item ) : ?>
					<li class="hwch-marks-item hwch-marks-item--<?php echo esc_attr( $item['kind'] ); ?>">
						<span class="hwch-marks-date">
							<?php
							echo esc_html( HWCH_Dashboard::date( $item['date'] ) );
							if ( ! empty( $item['end'] ) && $item['end'] !== $item['date'] ) {
								echo esc_html( ' – ' . HWCH_Dashboard::date( $item['end'] ) );
							} elseif ( 'change' !== $item['kind'] && empty( $item['end'] ) ) {
								echo ' <span class="hwch-muted">' . esc_html__( '(still rolling out)', 'hw-content-health' ) . '</span>';
							}
							?>
						</span>
						<?php
						$text = $item['label'];

						if ( ! empty( $item['posts'] ) ) {
							$text .= ': ' . implode( ', ', array_map( 'get_the_title', $item['posts'] ) );

							if ( $item['more'] ) {
								/* translators: %s: number */
								$text .= sprintf( __( ' and %s more', 'hw-content-health' ), number_format_i18n( $item['more'] ) );
							}
						}

						if ( ! empty( $item['url'] ) ) {
							echo '<a href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $text ) . '</a>';
						} else {
							echo '<span>' . esc_html( $text ) . '</span>';
						}
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}
