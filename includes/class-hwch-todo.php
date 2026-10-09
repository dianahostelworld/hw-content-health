<?php
/**
 * The to-do: the landing screen. One card per thing the latest sync found,
 * most valuable first, each opening the report that fixes it. The same
 * counts appear beside each report in the side menu.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Counts and the to-do screen.
 */
class HWCH_Todo {

	/**
	 * How many items each report has, worked out at most once an hour and
	 * again after every sync. Reports that need a live Search Console call
	 * (Quick wins) are only counted from their own saved results, so this
	 * screen never waits on Google.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array report key => count, or null when unknown.
	 */
	public static function counts( $refresh = false ) {
		$stamp = (int) get_option( 'hwch_last_sync' ) . '-' . (int) HWCH_Ahrefs::meta()['synced'];
		$saved = get_transient( 'hwch_todo_counts' );

		if ( ! $refresh && is_array( $saved ) && isset( $saved['stamp'] ) && $saved['stamp'] === $stamp ) {
			return $saved['counts'];
		}

		$c = array();

		$c['refresh'] = self::safe(
			function () {
				return HWCH_Store::has_data() ? count( HWCH_Reports::refresh() ) : null;
			}
		);

		$c['prune'] = self::safe(
			function () {
				return HWCH_Store::has_data() ? count( HWCH_Reports::prune() ) : null;
			}
		);

		$c['prune_links'] = self::safe(
			function () {
				if ( ! HWCH_Store::has_data() || ! HWCH_Ahrefs::has_data() ) {
					return null;
				}
				$n = 0;
				foreach ( HWCH_Reports::prune() as $row ) {
					$data = HWCH_Ahrefs::for_post( (int) $row['ID'] );
					$n   += ( $data && $data['rd'] ) ? 1 : 0;
				}
				return $n;
			}
		);

		try {
			$overlap = get_option( 'hwch_last_overlap' ) ? array_count_values( wp_list_pluck( HWCH_Overlap::groups(), 'label' ) ) : null;
		} catch ( Throwable $e ) {
			$overlap = null;
		}

		$c['overlap']     = is_array( $overlap ) ? array_sum( $overlap ) : null;
		$c['overlap_dup'] = is_array( $overlap ) && isset( $overlap['duplicate'] ) ? $overlap['duplicate'] : 0;

		$c['dated'] = self::safe(
			function () {
				return count( HWCH_Dated::past_years() ) + count( HWCH_Dated::stale_events() );
			}
		);

		$c['onpage'] = self::safe(
			function () {
				$data = HWCH_Onpage::data();
				return is_array( $data ) && isset( $data['desc'] ) ? count( (array) $data['desc'] ) : null;
			}
		);

		$c['quickwins'] = self::safe(
			function () {
				$lang = HWCH_Lang::current();
				$data = get_transient( 'hwch_quickwins_' . ( $lang ? $lang : 'all' ) );
				return is_array( $data ) && isset( $data['counts'] ) ? (int) $data['counts']['striking'] + (int) $data['counts']['low_ctr'] : null;
			}
		);

		$c['ahrefs_links'] = self::safe(
			function () {
				return HWCH_Ahrefs::has_data() ? count( HWCH_Ahrefs::rows( 'links' ) ) : null;
			}
		);

		$c['ahrefs_gap'] = self::safe(
			function () {
				return HWCH_Ahrefs::has_data() ? count( HWCH_Ahrefs::rows( 'gap' ) ) : null;
			}
		);

		$c['ahrefs'] = self::safe(
			function () {
				return HWCH_Ahrefs::has_data() ? count( HWCH_Ahrefs::rows( 'all' ) ) : null;
			}
		);

		set_transient( 'hwch_todo_counts', array( 'stamp' => $stamp, 'counts' => $c ), HOUR_IN_SECONDS );

		return $c;
	}

	/**
	 * Runs one count; a report that fails counts as unknown rather than
	 * taking the screen down.
	 *
	 * @param callable $fn Counter.
	 * @return int|null
	 */
	private static function safe( $fn ) {
		try {
			$n = $fn();
			return null === $n ? null : (int) $n;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * The cards, most valuable first.
	 *
	 * @return array
	 */
	public static function cards() {
		$c = self::counts();

		$cards = array(
			array(
				'tab'    => 'refresh',
				'count'  => $c['refresh'],
				'colour' => 'orange',
				'title'  => __( 'Articles worth refreshing', 'hw-content-health' ),
				'why'    => __( 'Articles with real traffic that haven\'t been updated in a while. Most clicks first.', 'hw-content-health' ),
			),
			array(
				'tab'    => 'quickwins',
				'count'  => $c['quickwins'],
				'colour' => 'green',
				'title'  => __( 'Quick wins', 'hw-content-health' ),
				'why'    => __( 'Searches sitting just off page one, and pages with a low click rate for their position.', 'hw-content-health' ),
			),
			array(
				'tab'    => 'overlap',
				'count'  => $c['overlap'],
				'colour' => 'purple',
				'title'  => __( 'Overlapping article groups', 'hw-content-health' ),
				/* translators: %s: number of groups */
				'why'    => $c['overlap_dup'] ? sprintf( _n( '%s is a likely duplicate. Merge them, or mark them as fine.', '%s are likely duplicates. Merge them, or mark them as fine.', $c['overlap_dup'], 'hw-content-health' ), number_format_i18n( $c['overlap_dup'] ) ) : __( 'Articles competing for the same searches. Merge them, or mark them as fine.', 'hw-content-health' ),
			),
			array(
				'tab'    => 'ahrefs',
				'view'   => 'gap',
				'count'  => $c['ahrefs_gap'],
				'colour' => 'blue',
				'title'  => __( 'Ahrefs and Search Console disagree', 'hw-content-health' ),
				'why'    => __( 'Ranking in Ahrefs, barely any clicks in Search Console. Check redirects and canonicals.', 'hw-content-health' ),
			),
			array(
				'tab'    => 'onpage',
				'count'  => $c['onpage'],
				'colour' => 'pink',
				'title'  => __( 'Missing meta descriptions', 'hw-content-health' ),
				'why'    => __( 'Write them straight from the list, or export it and draft them in bulk.', 'hw-content-health' ),
			),
			array(
				'tab'    => 'dated',
				'count'  => $c['dated'],
				'colour' => 'sunset',
				'title'  => __( 'Out of date', 'hw-content-health' ),
				'why'    => __( 'Titles that name a past year, and event articles not updated for months.', 'hw-content-health' ),
			),
			array(
				'tab'    => 'prune',
				'count'  => $c['prune'],
				'colour' => 'ink',
				'title'  => __( 'Candidates to prune', 'hw-content-health' ),
				/* translators: %s: number with backlinks */
				'why'    => $c['prune_links'] ? sprintf( _n( 'No clicks in the data window. %s has backlinks: redirect it, don\'t just unpublish.', 'No clicks in the data window. %s have backlinks: redirect them, don\'t just unpublish.', $c['prune_links'], 'hw-content-health' ), number_format_i18n( $c['prune_links'] ) ) : __( 'No clicks in the data window. Redirect or unpublish, all undoable from the Log.', 'hw-content-health' ),
			),
			array(
				'tab'    => 'ahrefs',
				'view'   => 'links',
				'count'  => $c['ahrefs_links'],
				'colour' => 'teal',
				'title'  => __( 'Links but no search traffic', 'hw-content-health' ),
				'why'    => __( 'Other sites link here, nobody finds them in search. Refresh or redirect.', 'hw-content-health' ),
			),
		);

		return $cards;
	}

	/**
	 * The link to a card's report.
	 *
	 * @param array $card Card.
	 * @return string
	 */
	public static function url( $card ) {
		$args = array( 'page' => 'hwch', 'tab' => $card['tab'] );

		if ( ! empty( $card['view'] ) ) {
			$args['view'] = $card['view'];
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * The screen.
	 *
	 * @return void
	 */
	public static function render() {
		$cards = self::cards();
		$todo  = array_filter(
			$cards,
			function ( $card ) {
				return $card['count'];
			}
		);
		$clear = array_filter(
			$cards,
			function ( $card ) {
				return 0 === $card['count'];
			}
		);
		$first = true;
		$last  = (int) get_option( 'hwch_last_sync' );
		?>
		<div class="hwch-page-head">
			<div>
				<?php if ( $last ) : ?>
					<p class="hwch-eyebrow">
						<?php
						/* translators: %s: date */
						echo esc_html( sprintf( __( 'From the sync on %s', 'hw-content-health' ), date_i18n( 'j M', $last ) ) );
						?>
					</p>
				<?php endif; ?>
				<h2 class="hwch-h1"><?php esc_html_e( 'This week\'s to-do', 'hw-content-health' ); ?></h2>
				<p class="hwch-lede"><?php esc_html_e( 'Everything the latest sync found, most valuable first. Open one to work through it.', 'hw-content-health' ); ?></p>
			</div>
		</div>

		<?php HWCH_Score::render_block(); ?>

		<div class="hwch-todo">
			<section class="hwch-todo-list" aria-label="<?php esc_attr_e( 'To-do', 'hw-content-health' ); ?>">
				<?php if ( ! $todo ) : ?>
					<p class="hwch-empty"><?php esc_html_e( 'Nothing to do right now. New items appear here after the next sync.', 'hw-content-health' ); ?></p>
				<?php endif; ?>

				<?php foreach ( $todo as $card ) : ?>
					<article class="hwch-card hwch-todo-card">
						<div class="hwch-todo-num hwch-tone--<?php echo esc_attr( $card['colour'] ); ?>"><?php echo null === $card['count'] ? '?' : esc_html( number_format_i18n( $card['count'] ) ); ?></div>
						<div class="hwch-todo-text">
							<h3><?php echo esc_html( $card['title'] ); ?></h3>
							<p><?php echo esc_html( null === $card['count'] ? __( 'Open it to load the latest figures.', 'hw-content-health' ) : $card['why'] ); ?></p>
						</div>
						<a class="hwch-btn <?php echo $first ? 'hwch-btn--primary' : 'hwch-btn--secondary'; ?>" href="<?php echo esc_url( self::url( $card ) ); ?>"><?php echo $first ? esc_html__( 'Start', 'hw-content-health' ) : esc_html__( 'Open', 'hw-content-health' ); ?></a>
					</article>
					<?php $first = false; ?>
				<?php endforeach; ?>

				<?php if ( $clear ) : ?>
					<p class="hwch-clear">
						<span class="hwch-tick" aria-hidden="true">✓</span>
						<?php
						/* translators: %s: list of reports */
						echo esc_html( sprintf( __( 'All clear: %s.', 'hw-content-health' ), implode( ', ', wp_list_pluck( $clear, 'title' ) ) ) );
						?>
					</p>
				<?php endif; ?>
			</section>

			<aside class="hwch-todo-side">
				<?php self::side_traffic(); ?>
				<?php self::side_done(); ?>
			</aside>
		</div>
		<?php
	}

	/**
	 * Last 28 days, from the Dashboard's saved figures (never a live call).
	 *
	 * @return void
	 */
	private static function side_traffic() {
		$lang = HWCH_Lang::current();
		$data = get_transient( 'hwch_dash2_web_' . ( $lang ? $lang : 'all' ) . '_28' );
		?>
		<section class="hwch-card hwch-side-card">
			<h3><?php esc_html_e( 'Blog, last 28 days', 'hw-content-health' ); ?></h3>
			<?php if ( is_array( $data ) && isset( $data['total_cur'] ) ) : ?>
				<div class="hwch-mini-stats">
					<?php
					foreach ( array( 'clicks' => __( 'Clicks', 'hw-content-health' ), 'impressions' => __( 'Impressions', 'hw-content-health' ) ) as $key => $label ) :
						$cur    = (int) $data['total_cur'][ $key ];
						$pre    = (int) $data['total_pre'][ $key ];
						$change = $pre ? round( ( $cur - $pre ) / $pre * 100 ) : null;
						?>
						<div>
							<div class="hwch-muted"><?php echo esc_html( $label ); ?></div>
							<div class="hwch-stat"><?php echo esc_html( number_format_i18n( $cur ) ); ?></div>
							<?php if ( null !== $change ) : ?>
								<div class="<?php echo $change < 0 ? 'hwch-down' : 'hwch-up'; ?>"><?php echo esc_html( ( $change > 0 ? '+' : '' ) . number_format_i18n( $change ) . '%' ); ?></div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="hwch-muted"><?php esc_html_e( 'Open the Dashboard once to load these figures.', 'hw-content-health' ); ?></p>
			<?php endif; ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=hwch&tab=dashboard' ) ); ?>"><?php esc_html_e( 'Open the Dashboard', 'hw-content-health' ); ?></a>
		</section>
		<?php
	}

	/**
	 * The last few actions from the Log.
	 *
	 * @return void
	 */
	private static function side_done() {
		global $wpdb;

		$rows   = $wpdb->get_results( 'SELECT post_id, action, created_at, undone_at FROM ' . HWCH_Store::table( 'log' ) . ' ORDER BY id DESC LIMIT 5', ARRAY_A ); // phpcs:ignore WordPress.DB
		$labels = HWCH_Admin::log_labels();
		?>
		<section class="hwch-card hwch-side-card">
			<h3><?php esc_html_e( 'Done recently', 'hw-content-health' ); ?></h3>
			<?php if ( ! $rows ) : ?>
				<p class="hwch-muted"><?php esc_html_e( 'Nothing yet. Every change made from these reports shows here and can be undone.', 'hw-content-health' ); ?></p>
			<?php else : ?>
				<ul class="hwch-done">
					<?php foreach ( $rows as $row ) : ?>
						<li>
							<span class="hwch-tick" aria-hidden="true">✓</span>
							<span>
								<?php echo esc_html( isset( $labels[ $row['action'] ] ) ? $labels[ $row['action'] ] : $row['action'] ); ?>
								<?php if ( (int) $row['post_id'] ) : ?>
									<strong><?php echo esc_html( get_the_title( (int) $row['post_id'] ) ); ?></strong>
								<?php endif; ?>
								<span class="hwch-muted">
									<?php
									echo esc_html( date_i18n( 'j M', strtotime( $row['created_at'] . ' UTC' ) ) );
									echo $row['undone_at'] ? ' · ' . esc_html__( 'undone', 'hw-content-health' ) : '';
									?>
								</span>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=hwch&tab=log' ) ); ?>"><?php esc_html_e( 'See the full log', 'hw-content-health' ); ?></a>
		</section>
		<?php
	}
}
