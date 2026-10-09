<?php
/**
 * Quick wins: searches just off page one, and pages whose clicks are low for
 * the position they hold.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetches, caches and renders the Quick wins tab.
 */
class HWCH_QuickWins {

	/**
	 * Typical organic CTR by position on page one. A broad industry
	 * benchmark, used only to spot pages far below it; actual CTR varies with
	 * the search and what else appears on the results page.
	 *
	 * @var float[]
	 */
	const BENCHMARK = array( 1 => 0.28, 2 => 0.16, 3 => 0.11, 4 => 0.08, 5 => 0.07, 6 => 0.05 );

	/**
	 * Expected CTR for a position, between the benchmark points.
	 *
	 * @param float $position Average position.
	 * @return float
	 */
	public static function expected_ctr( $position ) {
		$position = max( 1, min( 6, (float) $position ) );
		$low      = (int) floor( $position );
		$high     = (int) ceil( $position );

		if ( $low === $high ) {
			return self::BENCHMARK[ $low ];
		}

		return self::BENCHMARK[ $low ] + ( self::BENCHMARK[ $high ] - self::BENCHMARK[ $low ] ) * ( $position - $low );
	}

	/**
	 * The two lists, from the cache or fresh. Both use the last 28 days.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array|WP_Error
	 */
	public static function data( $refresh = false ) {
		$lang   = HWCH_Lang::current();
		$key    = 'hwch_quickwins_' . ( $lang ? $lang : 'all' );
		$cached = $refresh ? false : get_transient( $key );

		if ( $cached ) {
			return $cached;
		}

		$r     = HWCH_Dashboard::ranges( 28 );
		$pairs = HWCH_GSC::query( array( 'page', 'query' ), $r['cur_start'], $r['cur_end'], array( 'lang' => $lang ) );

		if ( is_wp_error( $pairs ) ) {
			return $pairs;
		}

		$pages = HWCH_GSC::query( array( 'page' ), $r['cur_start'], $r['cur_end'], array( 'max' => 5000, 'lang' => $lang ) );

		if ( is_wp_error( $pages ) ) {
			return $pages;
		}

		$s = HWCH_Settings::all();

		// Striking distance: positions 8 to 20 with real demand.
		$striking = array();

		foreach ( $pairs as $row ) {
			if ( $row['position'] >= 8 && $row['position'] <= 20 && $row['impressions'] >= $s['qw_strike_impr'] ) {
				$striking[] = array(
					'query'       => $row['keys'][1],
					'page'        => $row['keys'][0],
					'position'    => (float) $row['position'],
					'impressions' => (int) $row['impressions'],
					'clicks'      => (int) $row['clicks'],
				);
			}
		}

		usort(
			$striking,
			function ( $a, $b ) {
				return $b['impressions'] <=> $a['impressions'];
			}
		);

		// Low CTR: top-five pages getting well under the typical share of clicks.
		$low_ctr = array();

		foreach ( $pages as $row ) {
			if ( $row['position'] > 5.5 || $row['impressions'] < $s['qw_ctr_impr'] ) {
				continue;
			}

			$expected = self::expected_ctr( $row['position'] );
			$ctr      = $row['impressions'] ? $row['clicks'] / $row['impressions'] : 0;

			if ( $ctr >= $expected * $s['qw_ctr_ratio'] / 100 ) {
				continue;
			}

			$low_ctr[] = array(
				'page'        => $row['keys'][0],
				'position'    => (float) $row['position'],
				'impressions' => (int) $row['impressions'],
				'clicks'      => (int) $row['clicks'],
				'ctr'         => $ctr,
				'expected'    => $expected,
				'missed'      => (int) round( $row['impressions'] * $expected - $row['clicks'] ),
			);
		}

		usort(
			$low_ctr,
			function ( $a, $b ) {
				return $b['missed'] <=> $a['missed'];
			}
		);

		$data = array(
			'ranges'   => $r,
			'fetched'  => time(),
			'striking' => array_slice( $striking, 0, 100 ),
			'low_ctr'  => array_slice( $low_ctr, 0, 100 ),
			'counts'   => array( 'striking' => count( $striking ), 'low_ctr' => count( $low_ctr ) ),
		);

		set_transient( $key, $data, HWCH_Dashboard::CACHE );

		return $data;
	}

	/**
	 * Renders the tab.
	 *
	 * @return void
	 */
	public static function render() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$refresh = isset( $_GET['refresh'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['refresh'] ) ), 'hwch_refresh' );

		HWCH_Lang::picker();

		$data = self::data( $refresh );

		if ( is_wp_error( $data ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $data->get_error_message() ) . '</p></div>';
			return;
		}

		$r       = $data['ranges'];
		$s       = HWCH_Settings::all();
		$seo_key = HWCH_Overlap::seo_title_key();
		?>
		<p class="hwch-intro">
			<?php
			printf(
				/* translators: 1-2: dates, 3: time since */
				esc_html__( 'Search Console data from %1$s to %2$s. Fetched %3$s ago.', 'hw-content-health' ),
				esc_html( HWCH_Dashboard::date( $r['cur_start'] ) ),
				esc_html( HWCH_Dashboard::date( $r['cur_end'] ) ),
				esc_html( human_time_diff( $data['fetched'] ) )
			);
			?>
			<a href="<?php echo esc_url( add_query_arg( 'refresh', wp_create_nonce( 'hwch_refresh' ) ) ); ?>"><?php esc_html_e( 'Refresh', 'hw-content-health' ); ?></a>
		</p>

		<h2><?php esc_html_e( 'Pages with low clicks for their position', 'hw-content-health' ); ?></h2>
		<p class="hwch-intro">
			<?php
			printf(
				/* translators: 1: impressions, 2: percentage */
				esc_html__( 'Pages ranking in the top five, with at least %1$s impressions, that get under %2$s%% of the clicks usually seen at that position. A weak SEO title or meta description is the usual cause. Estimated missed clicks compares them with a typical click-through rate for the position, so treat it as a guide to what to fix first.', 'hw-content-health' ),
				esc_html( number_format_i18n( $s['qw_ctr_impr'] ) ),
				esc_html( $s['qw_ctr_ratio'] )
			);
			?>
		</p>
		<?php if ( ! $data['low_ctr'] ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'No pages below the benchmark right now.', 'hw-content-health' ); ?></p>
		<?php else : ?>
			<table class="widefat striped hwch-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Page', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Avg. position', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Impressions', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Clicks', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'CTR', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Typical CTR', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Est. missed clicks', 'hw-content-health' ); ?></th>
						<th><?php echo esc_html( $seo_key ? __( 'SEO title', 'hw-content-health' ) : __( 'Title', 'hw-content-health' ) ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data['low_ctr'] as $i => $row ) : ?>
						<?php $post_id = HWCH_Store::post_id_for_url( $row['page'] ); ?>
						<tr>
							<td><?php echo HWCH_Dashboard::page_link( $row['page'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['position'], 1 ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['impressions'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td>
							<td class="hwch-down"><?php echo esc_html( number_format_i18n( $row['ctr'] * 100, 1 ) ); ?>%</td>
							<td><?php echo esc_html( number_format_i18n( $row['expected'] * 100, 1 ) ); ?>%</td>
							<td><?php echo esc_html( number_format_i18n( $row['missed'] ) ); ?></td>
							<td class="hwch-seo">
								<?php if ( $post_id ) : ?>
									<?php
									$form_id = 'hwch-qw-title-' . $i;
									$name    = get_post_field( 'post_title', $post_id, 'raw' );
									$value   = $seo_key ? (string) get_post_meta( $post_id, $seo_key, true ) : $name;
									?>
									<form id="<?php echo esc_attr( $form_id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( 'hwch_action' ); ?>
										<input type="hidden" name="action" value="hwch_seo_title">
										<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>">
										<input type="hidden" name="return_tab" value="quickwins">
										<input type="text" name="seo_title" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $name ); ?>">
										<button class="button button-small"><?php esc_html_e( 'Save', 'hw-content-health' ); ?></button>
									</form>
								<?php else : ?>
									<span class="hwch-muted"><?php esc_html_e( 'Not a post on this site', 'hw-content-health' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php self::more( $data['counts']['low_ctr'], count( $data['low_ctr'] ) ); ?>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Striking distance', 'hw-content-health' ); ?></h2>
		<p class="hwch-intro">
			<?php
			printf(
				/* translators: %s: impressions */
				esc_html__( 'Searches where a page ranks on average between positions 8 and 20, just off or at the bottom of page one, with at least %s impressions. Improving the page for that search (covering it better, using the words searchers use, adding internal links) is often enough to move it up. Most impressions first.', 'hw-content-health' ),
				esc_html( number_format_i18n( $s['qw_strike_impr'] ) )
			);
			?>
		</p>
		<?php if ( ! $data['striking'] ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'No searches in striking distance right now.', 'hw-content-health' ); ?></p>
		<?php else : ?>
			<table class="widefat striped hwch-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Search', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Page', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Avg. position', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Impressions', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Clicks', 'hw-content-health' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data['striking'] as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $row['query'] ); ?></strong></td>
							<td><?php echo HWCH_Dashboard::page_link( $row['page'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['position'], 1 ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['impressions'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php self::more( $data['counts']['striking'], count( $data['striking'] ) ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * A note when a list is capped.
	 *
	 * @param int $total Found.
	 * @param int $shown Shown.
	 * @return void
	 */
	private static function more( $total, $shown ) {
		if ( $total > $shown ) {
			/* translators: 1: shown, 2: total */
			echo '<p class="hwch-muted">' . esc_html( sprintf( __( 'Showing the top %1$s of %2$s. Raise the minimum impressions in Settings to narrow the list.', 'hw-content-health' ), number_format_i18n( $shown ), number_format_i18n( $total ) ) ) . '</p>';
		}
	}
}
