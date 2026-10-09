<?php
/**
 * The two reports: articles worth refreshing, and candidates to prune.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the rows for each report from the stored Search Console figures.
 */
class HWCH_Reports {

	/**
	 * Articles with real traffic that have not been updated for a long time,
	 * ordered by clicks so the most valuable stale pages come first.
	 *
	 * @return array
	 */
	public static function refresh() {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . (int) HWCH_Settings::get( 'refresh_age' ) . ' months' ) );
		$min    = (int) HWCH_Settings::get( 'refresh_min_click' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_date_gmt, p.post_modified_gmt, m.clicks, m.impressions, m.position, m.clicks_recent, m.clicks_prior
				FROM {$wpdb->posts} p
				INNER JOIN " . HWCH_Store::table( 'metrics' ) . " m ON m.post_id = p.ID
				WHERE p.post_type = 'post' AND p.post_status = 'publish'
				AND p.post_modified_gmt < %s AND m.clicks >= %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$cutoff,
				$min
			),
			ARRAY_A
		);

		$lang = HWCH_Lang::current();

		if ( $lang ) {
			$keep = array_flip( HWCH_Lang::ids_in( wp_list_pluck( (array) $rows, 'ID' ), $lang ) );
			$rows = array_filter(
				(array) $rows,
				function ( $row ) use ( $keep ) {
					return isset( $keep[ (int) $row['ID'] ] );
				}
			);
		}

		return self::without_dismissed( $rows, 'refresh' );
	}

	/**
	 * Articles old enough to have been indexed that got no clicks, and at
	 * most a handful of impressions, over the whole window. Articles with no
	 * Search Console row at all count as zero.
	 *
	 * @return array
	 */
	public static function prune() {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . (int) HWCH_Settings::get( 'prune_min_age' ) . ' months' ) );
		$max    = (int) HWCH_Settings::get( 'prune_max_impr' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_date_gmt, p.post_modified_gmt,
					COALESCE(m.clicks, 0) AS clicks, COALESCE(m.impressions, 0) AS impressions, COALESCE(m.position, 0) AS position,
					COALESCE(m.clicks_recent, 0) AS clicks_recent, COALESCE(m.clicks_prior, 0) AS clicks_prior
				FROM {$wpdb->posts} p
				LEFT JOIN " . HWCH_Store::table( 'metrics' ) . " m ON m.post_id = p.ID
				WHERE p.post_type = 'post' AND p.post_status = 'publish'
				AND p.post_date_gmt < %s
				AND COALESCE(m.clicks, 0) = 0 AND COALESCE(m.impressions, 0) <= %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$cutoff,
				$max
			),
			ARRAY_A
		);

		return self::without_dismissed( self::english_only( $rows ), 'prune' );
	}

	/**
	 * Keeps only English articles.
	 *
	 * @param array $rows Rows with an ID.
	 * @return array
	 */
	private static function english_only( $rows ) {
		$rows    = (array) $rows;
		$english = array_flip( self::english_ids( array_map( 'intval', wp_list_pluck( $rows, 'ID' ) ) ) );

		return array_values(
			array_filter(
				$rows,
				function ( $row ) use ( $english ) {
					return isset( $english[ (int) $row['ID'] ] );
				}
			)
		);
	}

	/**
	 * Which of these articles are in English, using WPML or Polylang,
	 * whichever the site runs. Any English variant counts (en, en-gb, en-us).
	 * With neither plugin active, every article is treated as English.
	 *
	 * @param int[] $ids Post IDs.
	 * @return int[]
	 */
	public static function english_ids( $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );

		if ( ! $ids ) {
			return array();
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$english = array();

			foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
				$english = array_merge(
					$english,
					$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						"SELECT element_id FROM {$wpdb->prefix}icl_translations
						WHERE element_type = 'post_post' AND language_code LIKE 'en%'
						AND element_id IN (" . implode( ',', $chunk ) . ')' // phpcs:ignore WordPress.DB.PreparedSQL
					)
				);
			}

			return array_map( 'intval', $english );
		}

		if ( function_exists( 'pll_get_post_language' ) ) {
			return HWCH_Lang::pll_filter( $ids, 'en', true );
		}

		// Neither WPML nor Polylang: tell the language from the address, so
		// /blog/de/… articles are never treated as English.
		$folders = HWCH_Lang::folders();

		return array_values(
			array_filter(
				$ids,
				function ( $id ) use ( $folders ) {
					return ! isset( $folders[ $id ] );
				}
			)
		);
	}

	/**
	 * Clicks trend: the last 90 days against the 90 days before.
	 *
	 * @param array $row Report row.
	 * @return float|null Percentage change, or null if there is no baseline.
	 */
	public static function trend( $row ) {
		if ( (int) $row['clicks_prior'] <= 0 ) {
			return null;
		}

		return round( ( (int) $row['clicks_recent'] - (int) $row['clicks_prior'] ) / (int) $row['clicks_prior'] * 100 );
	}

	/**
	 * Sorts rows by a column.
	 *
	 * @param array  $rows    Rows.
	 * @param string $orderby Column.
	 * @param string $order   asc or desc.
	 * @return array
	 */
	public static function sort( $rows, $orderby, $order ) {
		$allowed = array( 'post_title', 'post_date_gmt', 'post_modified_gmt', 'clicks', 'impressions', 'position', 'trend' );

		if ( ! in_array( $orderby, $allowed, true ) ) {
			return $rows;
		}

		usort(
			$rows,
			function ( $a, $b ) use ( $orderby, $order ) {
				if ( 'trend' === $orderby ) {
					$x = self::trend( $a );
					$y = self::trend( $b );
					$x = null === $x ? -INF : $x;
					$y = null === $y ? -INF : $y;
				} elseif ( in_array( $orderby, array( 'post_title', 'post_date_gmt', 'post_modified_gmt' ), true ) ) {
					$x = strtolower( $a[ $orderby ] );
					$y = strtolower( $b[ $orderby ] );
				} else {
					$x = (float) $a[ $orderby ];
					$y = (float) $b[ $orderby ];
				}

				$cmp = $x <=> $y;

				return 'asc' === $order ? $cmp : -$cmp;
			}
		);

		return $rows;
	}

	/**
	 * Drops articles someone dismissed from this report, until the dismissal
	 * runs out.
	 *
	 * @param array  $rows   Rows.
	 * @param string $report refresh or prune.
	 * @return array
	 */
	private static function without_dismissed( $rows, $report ) {
		global $wpdb;

		$now = time();

		// One query for every dismissal, instead of one per article.
		$hidden = array();
		$found  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_hwch_dismissed_' . $report ),
			ARRAY_A
		);

		foreach ( (array) $found as $meta ) {
			if ( (int) $meta['meta_value'] >= $now ) {
				$hidden[ (int) $meta['post_id'] ] = true;
			}
		}

		return array_values(
			array_filter(
				(array) $rows,
				function ( $row ) use ( $hidden ) {
					return ! isset( $hidden[ (int) $row['ID'] ] );
				}
			)
		);
	}
}
