<?php
/**
 * Health score: one number for the blog's content health, from four areas.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Each area scores the share of published English articles without that
 * problem (an article counts once per area, however many problems it has).
 * The health score is the weighted average. It is saved once a week so the
 * change and trend can be shown.
 */
class HWCH_Score {

	const HISTORY = 'hwch_score_history';

	/**
	 * Areas: weight, label, what counts, report to open.
	 *
	 * @return array
	 */
	public static function areas() {
		return array(
			'fresh'   => array( 35, __( 'Freshness', 'hw-content-health' ), __( 'In Worth refreshing or Out of date', 'hw-content-health' ), 'refresh' ),
			'onpage'  => array( 25, __( 'On-page basics', 'hw-content-health' ), __( 'No meta description, duplicate title, no featured image or noindex', 'hw-content-health' ), 'onpage' ),
			'links'   => array( 20, __( 'Links', 'hw-content-health' ), __( 'At least one broken internal or outbound link', 'hw-content-health' ), 'broken' ),
			'cleanup' => array( 20, __( 'Clean-up', 'hw-content-health' ), __( 'Candidate to prune or overlapping', 'hw-content-health' ), 'prune' ),
		);
	}

	/**
	 * The score, from the cache or worked out now.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array|null score, areas (key => score|null, affected), total; null without data.
	 */
	public static function get( $refresh = false ) {
		$stamp = (int) get_option( 'hwch_last_sync' );
		$saved = get_transient( 'hwch_score' );

		if ( ! $refresh && is_array( $saved ) && $saved['stamp'] === $stamp ) {
			return $saved['data'];
		}

		if ( ! HWCH_Store::has_data() ) {
			return null;
		}

		$all = HWCH_Reports::english_ids( get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) );
		$all = array_fill_keys( array_map( 'intval', $all ), true );
		$n   = count( $all );

		if ( ! $n ) {
			return null;
		}

		$sets = array(
			'fresh'   => function () {
				$ids = wp_list_pluck( HWCH_Reports::refresh(), 'ID' );
				$ids = array_merge( $ids, wp_list_pluck( HWCH_Dated::past_years(), 'id' ), wp_list_pluck( HWCH_Dated::stale_events(), 'id' ) );
				return $ids;
			},
			'onpage'  => function () {
				$d   = HWCH_Onpage::data();
				$ids = array_merge( (array) $d['desc'], (array) $d['image'], (array) $d['noindex'] );
				foreach ( (array) $d['titles'] as $group ) {
					$ids = array_merge( $ids, (array) $group );
				}
				return $ids;
			},
			'links'   => function () {
				$ids = array();
				foreach ( array( 'internal', 'outbound' ) as $view ) {
					foreach ( HWCH_Broken::removable( $view ) as $row ) {
						$ids = array_merge( $ids, $row['sources'] );
					}
				}
				return $ids;
			},
			'cleanup' => function () {
				$ids = wp_list_pluck( HWCH_Reports::prune(), 'ID' );
				if ( get_option( 'hwch_last_overlap' ) ) {
					foreach ( HWCH_Overlap::groups() as $g ) {
						$ids = array_merge( $ids, (array) $g['posts'] );
					}
				}
				return $ids;
			},
		);

		$areas  = array();
		$sum    = 0;
		$weight = 0;

		foreach ( self::areas() as $key => $def ) {
			try {
				$hit      = array_intersect_key( array_fill_keys( array_map( 'intval', (array) $sets[ $key ]() ), true ), $all );
				$score    = (int) round( 100 * ( $n - count( $hit ) ) / $n );
				$areas[ $key ] = array( 'score' => $score, 'affected' => count( $hit ) );
				$sum     += $score * $def[0];
				$weight  += $def[0];
			} catch ( Throwable $e ) {
				// An area that can't be worked out is left out, and the others
				// are weighted up to fill its place.
				$areas[ $key ] = array( 'score' => null, 'affected' => null );
			}
		}

		$data = array(
			'score' => $weight ? (int) round( $sum / $weight ) : null,
			'areas' => $areas,
			'total' => $n,
		);

		set_transient( 'hwch_score', array( 'stamp' => $stamp, 'data' => $data ), HOUR_IN_SECONDS );
		self::save_week( $data );

		return $data;
	}

	/**
	 * Keep one score per week (the Monday it falls in).
	 *
	 * @param array $data Score.
	 * @return void
	 */
	private static function save_week( $data ) {
		if ( null === $data['score'] ) {
			return;
		}

		$week = gmdate( 'Y-m-d', strtotime( 'monday this week', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$hist = (array) get_option( self::HISTORY, array() );

		if ( isset( $hist[ $week ] ) ) {
			return;
		}

		$hist[ $week ] = array( 'score' => $data['score'], 'areas' => wp_list_pluck( $data['areas'], 'score' ) );
		ksort( $hist );
		update_option( self::HISTORY, array_slice( $hist, -52, null, true ), false );
	}

	/**
	 * Change since last week's saved score, or null.
	 *
	 * @param int|null $score Now.
	 * @param string   $area  Area key, or '' for the health score.
	 * @return int|null
	 */
	public static function change( $score, $area = '' ) {
		$hist = (array) get_option( self::HISTORY, array() );
		$week = gmdate( 'Y-m-d', strtotime( 'monday this week', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$prev = null;

		foreach ( $hist as $w => $h ) {
			if ( $w < $week ) {
				$prev = $h;
			}
		}

		if ( null === $score || ! $prev ) {
			return null;
		}

		$was = '' === $area ? $prev['score'] : ( $prev['areas'][ $area ] ?? null );

		return null === $was ? null : $score - (int) $was;
	}

	/**
	 * Green, amber or red.
	 *
	 * @param int|null $s Score.
	 * @return string
	 */
	public static function band( $s ) {
		if ( null === $s ) {
			return 'none';
		}
		return $s >= 80 ? 'good' : ( $s >= 60 ? 'mid' : 'bad' );
	}

	/**
	 * "+3 vs last week".
	 *
	 * @param int|null $d Change.
	 * @return string HTML.
	 */
	public static function change_html( $d ) {
		if ( null === $d ) {
			return '';
		}
		$cls = $d > 0 ? 'hwch-up' : ( $d < 0 ? 'hwch-down' : 'hwch-muted' );
		/* translators: %s: change */
		return '<span class="' . $cls . '">' . esc_html( sprintf( __( '%s vs last week', 'hw-content-health' ), ( $d > 0 ? '+' : '' ) . number_format_i18n( $d ) ) ) . '</span>';
	}

	/**
	 * 12-week trend line.
	 *
	 * @return string SVG, or ''.
	 */
	private static function trend() {
		$hist = array_slice( (array) get_option( self::HISTORY, array() ), -12, null, true );

		if ( count( $hist ) < 2 ) {
			return '';
		}

		$w   = 220;
		$h   = 48;
		$pts = array();
		$i   = 0;
		$max = count( $hist ) - 1;

		foreach ( $hist as $week => $row ) {
			$x     = round( $i / $max * ( $w - 8 ) + 4, 1 );
			$y     = round( $h - 4 - ( (int) $row['score'] / 100 ) * ( $h - 8 ), 1 );
			$pts[] = $x . ',' . $y;
			$i++;
		}

		$weeks = array_keys( $hist );
		/* translators: 1: first week, 2: last week */
		$label = sprintf( __( 'Health score by week, %1$s to %2$s', 'hw-content-health' ), date_i18n( 'j M', strtotime( $weeks[0] ) ), date_i18n( 'j M', strtotime( end( $weeks ) ) ) );

		return '<svg class="hwch-score-trend" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" role="img" aria-label="' . esc_attr( $label ) . '"><polyline fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="' . esc_attr( implode( ' ', $pts ) ) . '"/></svg><div class="hwch-muted">' . esc_html( $label ) . '</div>';
	}

	/**
	 * The full block, for the To-do page.
	 *
	 * @return void
	 */
	public static function render_block() {
		try {
			$s = self::get();
		} catch ( Throwable $e ) {
			$s = null;
		}

		if ( ! $s || null === $s['score'] ) {
			return;
		}
		?>
		<section class="hwch-card hwch-score" aria-label="<?php esc_attr_e( 'Health score', 'hw-content-health' ); ?>">
			<div class="hwch-score-main">
				<div class="hwch-score-label"><?php esc_html_e( 'Health score', 'hw-content-health' ); ?></div>
				<div class="hwch-score-num hwch-band--<?php echo esc_attr( self::band( $s['score'] ) ); ?>"><?php echo esc_html( $s['score'] ); ?><span>/100</span></div>
				<div><?php echo self::change_html( self::change( $s['score'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				<?php echo self::trend(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
			<div class="hwch-score-areas">
				<?php foreach ( self::areas() as $key => $def ) : ?>
					<?php $a = $s['areas'][ $key ]; ?>
					<a class="hwch-score-area" href="<?php echo esc_url( admin_url( 'admin.php?page=hwch&tab=' . $def[3] ) ); ?>">
						<span class="hwch-score-area-head"><b><?php echo esc_html( $def[1] ); ?></b> <span class="hwch-muted"><?php echo esc_html( $def[0] . '%' ); ?></span></span>
						<span class="hwch-score-area-num hwch-band--<?php echo esc_attr( self::band( $a['score'] ) ); ?>"><?php echo null === $a['score'] ? '–' : esc_html( $a['score'] ); ?></span>
						<span class="hwch-score-bar"><span class="hwch-band-bg--<?php echo esc_attr( self::band( $a['score'] ) ); ?>" style="width:<?php echo (int) $a['score']; ?>%"></span></span>
						<span class="hwch-muted">
							<?php
							echo null === $a['affected']
								? esc_html__( 'Not available yet', 'hw-content-health' )
								/* translators: 1: affected, 2: total, 3: rule */
								: esc_html( sprintf( __( '%1$s of %2$s articles need work: %3$s.', 'hw-content-health' ), number_format_i18n( $a['affected'] ), number_format_i18n( $s['total'] ), lcfirst( $def[2] ) ) );
							?>
						</span>
						<?php echo self::change_html( self::change( $a['score'], $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</a>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}
}
