<?php
/**
 * Tracked URLs: named groups of URLs whose Search Console performance you
 * follow together.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores, fetches and renders the URL groups.
 */
class HWCH_Watchlists {

	const OPTION  = 'hwch_watchlists';
	const PERIODS = array( 7, 28, 90, 180 );
	const MAX     = 200;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_hwch_list_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_hwch_list_delete', array( __CLASS__, 'delete' ) );
	}

	/**
	 * All groups.
	 *
	 * @return array Map of ID => array( name, urls, created ).
	 */
	public static function all() {
		return (array) get_option( self::OPTION, array() );
	}

	/**
	 * Cleans a pasted list of URLs: one per line (or separated by spaces or
	 * commas), full URLs or paths from the blog, duplicates removed.
	 *
	 * @param string $raw Pasted text.
	 * @return string[]
	 */
	public static function parse_urls( $raw ) {
		$prefix = wp_parse_url( (string) HWCH_Settings::get( 'url_prefix' ) );
		$base   = ( isset( $prefix['scheme'] ) ? $prefix['scheme'] : 'https' ) . '://' . ( isset( $prefix['host'] ) ? $prefix['host'] : '' );
		$urls   = array();

		foreach ( preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) as $url ) {
			if ( 0 === strpos( $url, '/' ) ) {
				$url = $base . $url;
			}

			$url = esc_url_raw( $url, array( 'http', 'https' ) );

			if ( $url ) {
				$urls[ HWCH_GSC::normalise( $url ) ] = $url;
			}
		}

		return array_slice( array_values( $urls ), 0, self::MAX );
	}

	/**
	 * Creates or updates a group.
	 *
	 * @return void
	 */
	public static function save() {
		check_admin_referer( 'hwch_action' );

		if ( ! current_user_can( HWCH_Actions::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'hw-content-health' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$id   = isset( $_POST['list'] ) ? sanitize_key( $_POST['list'] ) : '';
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$urls = self::parse_urls( isset( $_POST['urls'] ) ? wp_unslash( $_POST['urls'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable

		if ( '' === $name || ! $urls ) {
			wp_safe_redirect( admin_url( 'admin.php?page=hwch&tab=urls&hwch_msg=list_missing' ) );
			exit;
		}

		$lists = self::all();
		$id    = $id && isset( $lists[ $id ] ) ? $id : 'l' . substr( md5( uniqid( '', true ) ), 0, 8 );

		$lists[ $id ] = array(
			'name'    => $name,
			'urls'    => $urls,
			'created' => isset( $lists[ $id ]['created'] ) ? $lists[ $id ]['created'] : time(),
		);

		update_option( self::OPTION, $lists, false );
		self::clear_cache( $id );

		wp_safe_redirect( admin_url( 'admin.php?page=hwch&tab=urls&list=' . $id . '&hwch_msg=list_saved' ) );
		exit;
	}

	/**
	 * Deletes a group. Only the group goes; the articles are not touched.
	 *
	 * @return void
	 */
	public static function delete() {
		check_admin_referer( 'hwch_action' );

		if ( ! current_user_can( HWCH_Actions::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'hw-content-health' ), 403 );
		}

		$id    = isset( $_POST['list'] ) ? sanitize_key( $_POST['list'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$lists = self::all();

		unset( $lists[ $id ] );
		update_option( self::OPTION, $lists, false );
		self::clear_cache( $id );

		wp_safe_redirect( admin_url( 'admin.php?page=hwch&tab=urls&hwch_msg=list_deleted' ) );
		exit;
	}

	/**
	 * Drops the cached figures for a group.
	 *
	 * @param string $id Group ID.
	 * @return void
	 */
	private static function clear_cache( $id ) {
		foreach ( self::PERIODS as $days ) {
			delete_transient( 'hwch_list_' . $id . '_' . $days );
		}
	}

	/**
	 * Figures for every URL in a group: this period and the one before, with
	 * a day-by-day (or, over 28 days, week-by-week) clicks series.
	 *
	 * @param string $id      Group ID.
	 * @param int    $days    Period.
	 * @param bool   $refresh Ignore the cache.
	 * @return array|WP_Error
	 */
	public static function data( $id, $days, $refresh = false ) {
		$lists = self::all();

		if ( empty( $lists[ $id ] ) ) {
			return new WP_Error( 'hwch_no_list', __( 'That group no longer exists.', 'hw-content-health' ) );
		}

		$key    = 'hwch_list_' . $id . '_' . $days;
		$cached = $refresh ? false : get_transient( $key );

		if ( $cached ) {
			return $cached;
		}

		$r    = HWCH_Dashboard::ranges( $days );
		$urls = $lists[ $id ]['urls'];
		$rows = array();

		// Up to 15 URLs per request, matched on host and path so http/https,
		// www and a trailing slash do not matter.
		foreach ( array_chunk( $urls, 15 ) as $chunk ) {
			$result = HWCH_GSC::query(
				array( 'page', 'date' ),
				$r['prev_start'],
				$r['cur_end'],
				array( 'regex' => self::regex( $chunk ) )
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$rows = array_merge( $rows, $result );
		}

		// Group rows by URL (normalised) and date.
		$by_url = array();

		foreach ( $rows as $row ) {
			$norm = HWCH_GSC::normalise( $row['keys'][0] );
			$date = $row['keys'][1];

			$by_url[ $norm ][ $date ][] = $row;
		}

		$buckets = $days > 28 ? 'week' : 'day';
		$out     = array();
		$all_cur = array();
		$all_pre = array();
		$series  = array();

		foreach ( $urls as $url ) {
			$norm = HWCH_GSC::normalise( $url );
			$cur  = array();
			$pre  = array();
			$line = array();

			foreach ( isset( $by_url[ $norm ] ) ? $by_url[ $norm ] : array() as $date => $date_rows ) {
				$in_cur = $date >= $r['cur_start'];

				foreach ( $date_rows as $row ) {
					if ( $in_cur ) {
						$cur[] = $row;
						$slot  = 'week' === $buckets ? (int) floor( ( strtotime( $date ) - strtotime( $r['cur_start'] ) ) / WEEK_IN_SECONDS ) : $date;
						$line[ $slot ] = ( isset( $line[ $slot ] ) ? $line[ $slot ] : 0 ) + (int) $row['clicks'];
					} else {
						$pre[] = $row;
					}
				}
			}

			// Fill the series so every day (or week) has a value.
			$filled = array();
			$steps  = 'week' === $buckets ? (int) ceil( $days / 7 ) : $days;

			for ( $i = 0; $i < $steps; $i++ ) {
				$slot       = 'week' === $buckets ? $i : gmdate( 'Y-m-d', strtotime( $r['cur_start'] . ' +' . $i . ' days' ) );
				$filled[]   = isset( $line[ $slot ] ) ? $line[ $slot ] : 0;
				$series[ $i ] = ( isset( $series[ $i ] ) ? $series[ $i ] : 0 ) + ( isset( $line[ $slot ] ) ? $line[ $slot ] : 0 );
			}

			$now = HWCH_Dashboard::sum( HWCH_Dashboard::totals_by( $cur ) );
			$was = HWCH_Dashboard::sum( HWCH_Dashboard::totals_by( $pre ) );

			$all_cur = array_merge( $all_cur, $cur );
			$all_pre = array_merge( $all_pre, $pre );

			$out[] = array(
				'url'    => $url,
				'cur'    => $now,
				'prev'   => $was,
				'series' => $filled,
			);
		}

		$data = array(
			'ranges'    => $r,
			'fetched'   => time(),
			'buckets'   => $buckets,
			'rows'      => $out,
			'total_cur' => HWCH_Dashboard::sum( HWCH_Dashboard::totals_by( $all_cur ) ),
			'total_pre' => HWCH_Dashboard::sum( HWCH_Dashboard::totals_by( $all_pre ) ),
			'series'    => array_values( $series ),
		);

		set_transient( $key, $data, HWCH_Dashboard::CACHE );

		return $data;
	}

	/**
	 * A regular expression matching these URLs in Search Console, whatever
	 * their scheme, www or trailing slash. Keep it to about 15 URLs.
	 *
	 * @param string[] $urls URLs.
	 * @return string
	 */
	public static function regex( $urls ) {
		$patterns = array();

		foreach ( $urls as $url ) {
			$parts      = wp_parse_url( $url );
			$host       = isset( $parts['host'] ) ? preg_replace( '/^www\./', '', strtolower( $parts['host'] ) ) : '';
			$path       = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
			$patterns[] = preg_quote( $host . $path, '' ) . '/?';
		}

		return '^https?://(www\\.)?(' . implode( '|', $patterns ) . ')(\\?.*)?$';
	}

	/**
	 * Keeps only the group's URLs in one language, and recalculates the
	 * totals and the chart from them.
	 *
	 * @param array  $data Group data.
	 * @param string $lang Language code, or '' for all.
	 * @return array
	 */
	private static function for_language( $data, $lang ) {
		if ( '' === $lang ) {
			return $data;
		}

		$data['rows'] = array_values(
			array_filter(
				$data['rows'],
				function ( $row ) use ( $lang ) {
					return HWCH_Lang::of_url( $row['url'] ) === $lang;
				}
			)
		);

		foreach ( array( 'cur' => 'total_cur', 'prev' => 'total_pre' ) as $side => $key ) {
			$map = array();

			foreach ( $data['rows'] as $i => $row ) {
				$map[ $i ] = $row[ $side ];
			}

			$data[ $key ] = HWCH_Dashboard::sum( $map );
		}

		$series = array_fill( 0, count( $data['series'] ), 0 );

		foreach ( $data['rows'] as $row ) {
			foreach ( $row['series'] as $i => $value ) {
				$series[ $i ] += $value;
			}
		}

		$data['series'] = $series;

		return $data;
	}

	/**
	 * Renders the Tracked URLs tab.
	 *
	 * @return void
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification
		$lists   = self::all();
		$id      = isset( $_GET['list'] ) ? sanitize_key( $_GET['list'] ) : '';
		$editing = isset( $_GET['edit'] );
		$days    = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 28;
		$days    = in_array( $days, self::PERIODS, true ) ? $days : 28;
		$refresh = isset( $_GET['refresh'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['refresh'] ) ), 'hwch_refresh' );
		// phpcs:enable

		if ( ! $id && $lists && ! isset( $_GET['new'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$id = (string) key( $lists );
		}
		?>
		<div class="hwch-lists">
			<div class="hwch-lists-nav">
				<?php foreach ( $lists as $list_id => $list ) : ?>
					<a class="<?php echo $list_id === $id ? 'current' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=hwch&tab=urls&list=' . $list_id ) ); ?>">
						<?php echo esc_html( $list['name'] ); ?>
						<span class="hwch-muted"><?php echo esc_html( count( $list['urls'] ) ); ?></span>
					</a>
				<?php endforeach; ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=hwch&tab=urls&new=1' ) ); ?>"><?php esc_html_e( '+ New group', 'hw-content-health' ); ?></a>
			</div>

			<div class="hwch-lists-main">
				<?php
				if ( ! $id || empty( $lists[ $id ] ) || $editing ) {
					self::form( $id && isset( $lists[ $id ] ) ? $id : '', $id && isset( $lists[ $id ] ) ? $lists[ $id ] : array() );
				} else {
					self::view( $id, $lists[ $id ], $days, $refresh );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * The create/edit form.
	 *
	 * @param string $id   Group ID, or '' for a new one.
	 * @param array  $list Group.
	 * @return void
	 */
	private static function form( $id, $list ) {
		?>
		<h2><?php echo esc_html( $id ? __( 'Edit group', 'hw-content-health' ) : __( 'New group of URLs', 'hw-content-health' ) ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hwch-list-form">
			<?php wp_nonce_field( 'hwch_action' ); ?>
			<input type="hidden" name="action" value="hwch_list_save">
			<input type="hidden" name="list" value="<?php echo esc_attr( $id ); ?>">
			<p>
				<label for="hwch-list-name"><strong><?php esc_html_e( 'Name', 'hw-content-health' ); ?></strong></label><br>
				<input id="hwch-list-name" class="regular-text" name="name" required value="<?php echo esc_attr( isset( $list['name'] ) ? $list['name'] : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Portugal refresh, October', 'hw-content-health' ); ?>">
			</p>
			<p>
				<label for="hwch-list-urls"><strong><?php esc_html_e( 'URLs', 'hw-content-health' ); ?></strong></label><br>
				<textarea id="hwch-list-urls" class="large-text code" rows="10" name="urls" required placeholder="https://www.hostelworld.com/blog/things-to-do-in-porto/&#10;/blog/porto-nightlife/"><?php echo esc_textarea( isset( $list['urls'] ) ? implode( "\n", $list['urls'] ) : '' ); ?></textarea>
				<span class="description">
					<?php
					/* translators: %d: maximum number of URLs */
					echo esc_html( sprintf( __( 'One per line, full URLs or paths starting with /. Up to %d.', 'hw-content-health' ), self::MAX ) );
					?>
				</span>
			</p>
			<?php submit_button( $id ? __( 'Save group', 'hw-content-health' ) : __( 'Create group', 'hw-content-health' ), 'primary', 'submit', false ); ?>
			<?php if ( $id ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=hwch&tab=urls&list=' . $id ) ); ?>"><?php esc_html_e( 'Cancel', 'hw-content-health' ); ?></a>
			<?php endif; ?>
		</form>

		<?php if ( $id ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hwch-delete">
				<?php wp_nonce_field( 'hwch_action' ); ?>
				<input type="hidden" name="action" value="hwch_list_delete">
				<input type="hidden" name="list" value="<?php echo esc_attr( $id ); ?>">
				<button class="button-link button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this group? The articles themselves are not affected.', 'hw-content-health' ) ); ?>');"><?php esc_html_e( 'Delete group', 'hw-content-health' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * A group's performance.
	 *
	 * @param string $id      Group ID.
	 * @param array  $list    Group.
	 * @param int    $days    Period.
	 * @param bool   $refresh Ignore the cache.
	 * @return void
	 */
	private static function view( $id, $list, $days, $refresh ) {
		$data = self::data( $id, $days, $refresh );
		?>
		<div class="hwch-list-head">
			<h2><?php echo esc_html( $list['name'] ); ?></h2>
			<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=hwch&tab=urls&list=' . $id . '&edit=1' ) ); ?>"><?php esc_html_e( 'Edit URLs', 'hw-content-health' ); ?></a>
		</div>
		<?php
		HWCH_Lang::picker();
		HWCH_Dashboard::period_switch( $days, self::PERIODS );

		if ( is_wp_error( $data ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $data->get_error_message() ) . '</p></div>';
			return;
		}

		$data = self::for_language( $data, HWCH_Lang::current() );
		$r    = $data['ranges'];
		$now  = $data['total_cur'];
		$was  = $data['total_pre'];
		?>
		<p class="hwch-intro">
			<?php
			printf(
				/* translators: 1: count, 2-5: dates, 6: time since */
				esc_html__( '%1$s URLs. %2$s to %3$s, compared with %4$s to %5$s. Fetched %6$s ago.', 'hw-content-health' ),
				esc_html( number_format_i18n( count( $data['rows'] ) ) ),
				esc_html( HWCH_Dashboard::date( $r['cur_start'] ) ),
				esc_html( HWCH_Dashboard::date( $r['cur_end'] ) ),
				esc_html( HWCH_Dashboard::date( $r['prev_start'] ) ),
				esc_html( HWCH_Dashboard::date( $r['prev_end'] ) ),
				esc_html( human_time_diff( $data['fetched'] ) )
			);
			?>
			<a href="<?php echo esc_url( add_query_arg( 'refresh', wp_create_nonce( 'hwch_refresh' ) ) ); ?>"><?php esc_html_e( 'Refresh', 'hw-content-health' ); ?></a>
		</p>

		<div class="hwch-kpis">
			<?php
			HWCH_Dashboard::kpi( __( 'Clicks', 'hw-content-health' ), number_format_i18n( $now['clicks'] ), HWCH_Chart::change( $now['clicks'], $was['clicks'] ) );
			HWCH_Dashboard::kpi( __( 'Impressions', 'hw-content-health' ), number_format_i18n( $now['impressions'] ), HWCH_Chart::change( $now['impressions'], $was['impressions'] ) );
			HWCH_Dashboard::kpi( __( 'CTR', 'hw-content-health' ), number_format_i18n( $now['ctr'] * 100, 2 ) . '%', HWCH_Chart::change( $now['ctr'], $was['ctr'] ) );
			HWCH_Dashboard::kpi( __( 'Avg. position', 'hw-content-health' ), number_format_i18n( $now['position'], 1 ), HWCH_Chart::change( $now['position'], $was['position'], 'position' ) );
			?>
		</div>

		<div class="hwch-chart-box">
			<h3><?php echo esc_html( 'week' === $data['buckets'] ? __( 'Clicks per week, all URLs', 'hw-content-health' ) : __( 'Clicks per day, all URLs', 'hw-content-health' ) ); ?></h3>
			<?php echo HWCH_Chart::lines( array( array( 'values' => $data['series'], 'colour' => '#2271b1' ) ), array( HWCH_Dashboard::date( $r['cur_start'] ), HWCH_Dashboard::date( $r['cur_end'] ) ), __( 'Clicks', 'hw-content-health' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>

		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Page', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Clicks', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Change', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Click trend', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Impressions', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Change', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'CTR', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Avg. position', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Change', 'hw-content-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['rows'] as $row ) : ?>
					<tr>
						<td>
							<?php echo HWCH_Dashboard::page_link( $row['url'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php if ( ! $row['cur']['impressions'] && ! $row['prev']['impressions'] ) : ?>
								<div class="hwch-muted"><?php esc_html_e( 'No Search Console data for this URL in either period. Check the URL is exact.', 'hw-content-health' ); ?></div>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( $row['cur']['clicks'] ) ); ?></td>
						<td><?php echo HWCH_Chart::change( $row['cur']['clicks'], $row['prev']['clicks'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo HWCH_Chart::spark( $row['series'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['cur']['impressions'] ) ); ?></td>
						<td><?php echo HWCH_Chart::change( $row['cur']['impressions'], $row['prev']['impressions'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['cur']['ctr'] * 100, 1 ) ); ?>%</td>
						<td><?php echo esc_html( $row['cur']['position'] ? number_format_i18n( $row['cur']['position'], 1 ) : '–' ); ?></td>
						<td><?php echo HWCH_Chart::change( $row['cur']['position'], $row['prev']['position'], 'position' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
