<?php
/**
 * Clicks likely lost to AI Overviews: articles seen as often as a year ago,
 * ranking as well, but clicked far less.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Compares each article with the same period a year earlier.
 *
 * Search Console does not report when an AI Overview appears, so this is an
 * inference: unchanged impressions and position with a much lower click
 * rate is the pattern an AI Overview leaves. A new People also ask box,
 * video results, more ads or a title change can leave the same pattern,
 * which is why the report says "likely". Comparing with the same weeks last
 * year keeps seasonality out of it.
 */
class HWCH_AIO {

	/**
	 * Days compared.
	 */
	const DAYS = 90;

	/**
	 * The two periods compared: the last 90 days, and the same 90 days a
	 * year earlier (52 weeks, so weekdays line up).
	 *
	 * @return array now and then, each array( start, end ).
	 */
	public static function periods() {
		$end  = strtotime( '-3 days' );
		$then = $end - 364 * DAY_IN_SECONDS;

		return array(
			'now'  => array( gmdate( 'Y-m-d', $end - ( self::DAYS - 1 ) * DAY_IN_SECONDS ), gmdate( 'Y-m-d', $end ) ),
			'then' => array( gmdate( 'Y-m-d', $then - ( self::DAYS - 1 ) * DAY_IN_SECONDS ), gmdate( 'Y-m-d', $then ) ),
		);
	}

	/**
	 * Fetches both periods and stores each article's figures. Called by the
	 * sync.
	 *
	 * @param array $url_to_post Map of normalised URL => post ID.
	 * @return true|WP_Error
	 */
	public static function build( $url_to_post ) {
		global $wpdb;

		$periods = self::periods();
		$data    = array();

		foreach ( $periods as $name => $range ) {
			$pages = HWCH_GSC::pages( $range[0], $range[1] );

			if ( is_wp_error( $pages ) ) {
				return $pages;
			}

			foreach ( $pages as $url => $figures ) {
				if ( empty( $url_to_post[ $url ] ) ) {
					continue;
				}

				$id = $url_to_post[ $url ];

				if ( ! isset( $data[ $id ][ $name ] ) ) {
					$data[ $id ][ $name ] = array( 'clicks' => 0, 'impressions' => 0, 'posw' => 0 );
				}

				$data[ $id ][ $name ]['clicks']      += $figures['clicks'];
				$data[ $id ][ $name ]['impressions'] += $figures['impressions'];
				$data[ $id ][ $name ]['posw']        += $figures['position'] * $figures['impressions'];
			}
		}

		$table = HWCH_Store::table( 'yoy' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB

		$values = array();

		foreach ( $data as $id => $both ) {
			$now  = isset( $both['now'] ) ? $both['now'] : array( 'clicks' => 0, 'impressions' => 0, 'posw' => 0 );
			$then = isset( $both['then'] ) ? $both['then'] : array( 'clicks' => 0, 'impressions' => 0, 'posw' => 0 );

			$values[] = $wpdb->prepare(
				'(%d, %d, %d, %f, %d, %d, %f)',
				$id,
				$now['clicks'],
				$now['impressions'],
				$now['impressions'] ? $now['posw'] / $now['impressions'] : 0,
				$then['clicks'],
				$then['impressions'],
				$then['impressions'] ? $then['posw'] / $then['impressions'] : 0
			);
		}

		foreach ( array_chunk( $values, 400 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$table} (post_id, clicks_now, impr_now, pos_now, clicks_then, impr_then, pos_then) VALUES " . implode( ',', $chunk ) ); // phpcs:ignore WordPress.DB
		}

		update_option( 'hwch_aio_periods', $periods, false );
		delete_transient( 'hwch_aio' );

		return true;
	}

	/**
	 * One article's comparison, and whether it fits the pattern.
	 *
	 * @param array $row Row from the yoy table.
	 * @return array|null The comparison with ctr_now, ctr_then and lost, or
	 *                    null when it does not fit the pattern.
	 */
	public static function check( $row ) {
		$impr_now    = (int) $row['impr_now'];
		$impr_then   = (int) $row['impr_then'];
		$clicks_then = (int) $row['clicks_then'];

		if ( $impr_now < (int) HWCH_Settings::get( 'aio_min_impr' ) || $impr_then <= 0 || $clicks_then < 20 ) {
			return null;
		}

		$ctr_now  = (int) $row['clicks_now'] / $impr_now;
		$ctr_then = $clicks_then / $impr_then;
		$drop     = (int) HWCH_Settings::get( 'aio_ctr_drop' ) / 100;

		// Seen as often, ranking as well, clicked much less.
		if ( $impr_now < 0.8 * $impr_then || (float) $row['pos_now'] > (float) $row['pos_then'] + 1.5 || $ctr_now > $ctr_then * ( 1 - $drop ) ) {
			return null;
		}

		return $row + array(
			'ctr_now'  => $ctr_now,
			'ctr_then' => $ctr_then,
			'lost'     => (int) round( $impr_now * $ctr_then - (int) $row['clicks_now'] ),
		);
	}

	/**
	 * Every published article that fits the pattern, most clicks lost first.
	 *
	 * @param string $lang Language code, or '' for all.
	 * @return array
	 */
	public static function data( $lang = '' ) {
		global $wpdb;

		$cached = get_transient( 'hwch_aio' );

		if ( ! is_array( $cached ) ) {
			$rows   = $wpdb->get_results( 'SELECT y.* FROM ' . HWCH_Store::table( 'yoy' ) . " y INNER JOIN {$wpdb->posts} p ON p.ID = y.post_id AND p.post_status = 'publish' AND p.post_type = 'post'", ARRAY_A ); // phpcs:ignore WordPress.DB
			$cached = array();

			foreach ( (array) $rows as $row ) {
				$hit = self::check( $row );

				if ( $hit ) {
					$cached[ (int) $row['post_id'] ] = $hit;
				}
			}

			uasort(
				$cached,
				function ( $a, $b ) {
					return $b['lost'] <=> $a['lost'];
				}
			);

			set_transient( 'hwch_aio', $cached, 12 * HOUR_IN_SECONDS );
		}

		if ( $lang ) {
			$keep   = array_flip( HWCH_Lang::ids_in( array_keys( $cached ), $lang ) );
			$cached = array_intersect_key( $cached, $keep );
		}

		$now = time();

		return array_filter(
			$cached,
			function ( $row ) use ( $now ) {
				return (int) get_post_meta( (int) $row['post_id'], '_hwch_dismissed_aio', true ) < $now;
			}
		);
	}

	/**
	 * Words that make a search a booking search, which the main site's
	 * accommodation pages should win, in every language the blog is in.
	 * "Where to stay" is not one: that is pre-booking advice, the blog's job.
	 * Words in Latin script count only as whole words; words in other
	 * scripts (which do not separate words with spaces) count anywhere.
	 *
	 * @return array latin, other.
	 */
	public static function booking_words() {
		return array(
			'latin' => array(
				// English.
				'hostel', 'hostels', 'hotel', 'hotels', 'motel', 'motels', 'accommodation', 'accommodations', 'accomodation', 'dorm', 'dorms', 'booking', 'book', 'guesthouse', 'guesthouses', 'guest house', 'guest houses', 'bnb', 'capsule', 'lodging', 'lodge', 'cheap stay', 'cheap stays',
				// Spanish and Portuguese.
				'albergue', 'albergues', 'hostal', 'hostales', 'alojamiento', 'alojamientos', 'hospedaje', 'reservar', 'hospedagem', 'alojamento', 'alojamentos', 'pousada', 'pousadas', 'hoteles', 'hotéis', 'hoteis',
				// French.
				'auberge', 'auberges', 'auberge de jeunesse', 'hébergement', 'hebergement', 'hôtel', 'hôtels', 'réserver',
				// German and Dutch.
				'jugendherberge', 'jugendherbergen', 'herberge', 'unterkunft', 'unterkünfte', 'unterkunfte', 'übernachtung', 'ubernachtung', 'buchen', 'jeugdherberg', 'jeugdherbergen', 'overnachting', 'accommodatie',
				// Italian.
				'ostello', 'ostelli', 'albergo', 'alberghi', 'alloggio', 'alloggi', 'prenotare',
				// Polish and Czech.
				'schronisko', 'schroniska', 'hostele', 'noclegi', 'nocleg', 'hotele', 'ubytování', 'ubytovani', 'ubytovna', 'hostely', 'hotely',
				// Danish, Norwegian, Swedish, Finnish.
				'vandrerhjem', 'vandrarheim', 'vandrarhem', 'overnatning', 'overnatting', 'hoteller', 'hotell', 'boende', 'hostelli', 'hostellit', 'majoitus', 'hotelli', 'hotellit',
				// Turkish.
				'otel', 'oteller', 'pansiyon', 'konaklama',
			),
			'other' => array(
				'хостел', 'хостелы', 'отель', 'отели', 'гостиница', 'гостиницы', 'жилье', 'жильё', 'проживание',
				'호스텔', '호텔', '숙소', '게스트하우스',
				'ホステル', 'ホテル', 'ゲストハウス', '宿泊', '宿',
				'青年旅舍', '青旅', '旅舍', '酒店', '住宿', '民宿', '旅馆',
			),
		);
	}

	/**
	 * Whether a search is a booking search.
	 *
	 * @param string $query Search.
	 * @return bool
	 */
	public static function is_booking( $query ) {
		static $latin = null, $other = null;

		if ( null === $latin ) {
			$words = self::booking_words();
			$latin = '/(?<![\p{L}\p{N}])(?:' . implode( '|', array_map( function ( $w ) { return str_replace( ' ', '\s+', preg_quote( $w, '/' ) ); }, $words['latin'] ) ) . ')(?![\p{L}\p{N}])/u';
			$other = '/(?:' . implode( '|', array_map( function ( $w ) { return preg_quote( $w, '/' ); }, $words['other'] ) ) . ')/u';
		}

		$query = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $query, 'UTF-8' ) : strtolower( (string) $query );

		return preg_match( $latin, $query ) || preg_match( $latin, remove_accents( $query ) ) || preg_match( $other, $query );
	}

	/**
	 * The share of an article's impressions from informational searches
	 * (questions, or no booking words), which are the searches AI Overviews
	 * answer most.
	 *
	 * @param int $post_id Post ID.
	 * @return array share (0-1), questions (top question searches).
	 */
	public static function informational( $post_id ) {
		$all       = 0;
		$info      = 0;
		$questions = array();

		foreach ( HWCH_Overlap::queries_for( $post_id, 100 ) as $q ) {
			$all += (int) $q['impressions'];

			if ( ! self::is_booking( $q['query'] ) ) {
				$info += (int) $q['impressions'];
			}

			if ( HWCH_Questions::is_question( $q['query'] ) && count( $questions ) < 3 ) {
				$questions[] = $q['query'];
			}
		}

		return array( 'share' => $all ? $info / $all : 0, 'questions' => $questions );
	}

	/**
	 * Renders the tab.
	 *
	 * @return void
	 */
	public static function render() {
		global $wpdb;

		if ( ! (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . HWCH_Store::table( 'yoy' ) ) ) { // phpcs:ignore WordPress.DB
			echo '<p class="hwch-empty">' . esc_html__( 'No year-on-year figures yet. They are saved by the sync: click Sync now at the top.', 'hw-content-health' ) . '</p>';
			return;
		}

		HWCH_Lang::picker();

		// phpcs:ignore WordPress.Security.NonceVerification
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$rows    = array_values( self::data( HWCH_Lang::current() ) );
		$total   = count( $rows );
		$lost    = array_sum( wp_list_pluck( $rows, 'lost' ) );
		$rows    = array_slice( $rows, ( $paged - 1 ) * HWCH_Export::per( 50 ), HWCH_Export::per( 50 ) );
		$periods = get_option( 'hwch_aio_periods' );
		$s       = HWCH_Settings::all();
		?>
		<p class="hwch-intro">
			<?php
			printf(
				/* translators: 1-4: dates, 5: percentage, 6: impressions */
				esc_html__( 'Articles seen about as often as a year ago and ranking about as well, but clicked much less: the pattern an AI Overview leaves when it answers the search on Google. This period (%1$s to %2$s) is compared with the same weeks last year (%3$s to %4$s), so seasons do not distort it. An article is listed when its impressions are at least 80%% of last year\'s, its position is no more than 1.5 places worse, and its click rate fell by at least %5$s%%; at least %6$s impressions this period. Search Console does not report AI Overviews, so this is an inference: a new People also ask box, video results, more ads or a changed title leave the same pattern. Check the main searches on Google before acting.', 'hw-content-health' ),
				esc_html( is_array( $periods ) ? HWCH_Dashboard::date( $periods['now'][0] ) : '' ),
				esc_html( is_array( $periods ) ? HWCH_Dashboard::date( $periods['now'][1] ) : '' ),
				esc_html( is_array( $periods ) ? HWCH_Dashboard::date( $periods['then'][0] ) : '' ),
				esc_html( is_array( $periods ) ? HWCH_Dashboard::date( $periods['then'][1] ) : '' ),
				esc_html( number_format_i18n( $s['aio_ctr_drop'] ) ),
				esc_html( number_format_i18n( $s['aio_min_impr'] ) )
			);
			?>
		</p>
		<p class="hwch-intro"><?php esc_html_e( 'What usually helps: answer the search clearly near the top, with something an AI summary cannot give (first-hand tips, prices you checked, local detail), so the article is the one the Overview cites; and judge these articles on impressions as much as clicks.', 'hw-content-health' ); ?></p>
		<p class="hwch-count">
			<?php
			/* translators: 1: number of articles, 2: clicks */
			echo esc_html( sprintf( _n( '%1$s article, about %2$s clicks lost this period compared with last year\'s click rate', '%1$s articles, about %2$s clicks lost this period compared with last year\'s click rate', $total, 'hw-content-health' ), number_format_i18n( $total ), number_format_i18n( $lost ) ) );
			?>
		</p>
		<?php if ( ! $total ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'No article fits the pattern.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Article', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Impressions', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Position', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Click rate', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Clicks lost', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Its searches', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'hw-content-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$id   = (int) $row['post_id'];
					$info = self::informational( $id );
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a></strong>
							<div class="hwch-url"><a href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( rawurldecode( (string) wp_parse_url( get_permalink( $id ), PHP_URL_PATH ) ) ); ?></a></div>
						</td>
						<td>
							<?php echo esc_html( number_format_i18n( $row['impr_now'] ) ); ?>
							<?php /* translators: %s: impressions */ ?>
							<div class="hwch-muted"><?php echo esc_html( sprintf( __( 'was %s', 'hw-content-health' ), number_format_i18n( $row['impr_then'] ) ) ); ?></div>
						</td>
						<td>
							<?php echo esc_html( number_format_i18n( $row['pos_now'], 1 ) ); ?>
							<?php /* translators: %s: position */ ?>
							<div class="hwch-muted"><?php echo esc_html( sprintf( __( 'was %s', 'hw-content-health' ), number_format_i18n( $row['pos_then'], 1 ) ) ); ?></div>
						</td>
						<td>
							<?php echo esc_html( number_format_i18n( 100 * $row['ctr_now'], 1 ) . '%' ); ?>
							<?php /* translators: %s: percentage */ ?>
							<div class="hwch-muted"><?php echo esc_html( sprintf( __( 'was %s', 'hw-content-health' ), number_format_i18n( 100 * $row['ctr_then'], 1 ) . '%' ) ); ?></div>
						</td>
						<td>
							<strong><?php echo esc_html( number_format_i18n( $row['lost'] ) ); ?></strong>
							<?php /* translators: 1: clicks now, 2: clicks then */ ?>
							<div class="hwch-muted"><?php echo esc_html( sprintf( __( '%1$s clicks, was %2$s', 'hw-content-health' ), number_format_i18n( $row['clicks_now'] ), number_format_i18n( $row['clicks_then'] ) ) ); ?></div>
						</td>
						<td>
							<?php /* translators: %s: percentage */ ?>
							<?php echo esc_html( sprintf( __( '%s informational', 'hw-content-health' ), number_format_i18n( 100 * $info['share'] ) . '%' ) ); ?>
							<?php if ( $info['questions'] ) : ?>
								<ul class="hwch-list">
									<?php foreach ( $info['questions'] as $q ) : ?>
										<li><?php echo esc_html( $q ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
						<td class="hwch-actions">
							<a class="button button-small button-primary" href="<?php echo esc_url( HWCH_Brief::url( $id ) ); ?>"><?php esc_html_e( 'Refresh brief', 'hw-content-health' ); ?></a>
							<?php HWCH_Admin::button( 'dismiss', $id, __( 'Checked', 'hw-content-health' ), array( 'report' => 'aio' ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		HWCH_Admin::paginate( $total, $paged, 50 );
	}
}
