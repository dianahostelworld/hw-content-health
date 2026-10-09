<?php
/**
 * Refresh brief: everything the plugin knows about one article, on one page,
 * with a plain-text copy to paste into a ticket.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the brief for an article.
 */
class HWCH_Brief {

	/**
	 * The brief's address.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function url( $post_id ) {
		return admin_url( 'admin.php?page=hwch&tab=brief&post=' . (int) $post_id );
	}

	/**
	 * Everything in the brief.
	 *
	 * @param int $id Post ID.
	 * @return array
	 */
	public static function data( $id ) {
		global $wpdb;

		$content = (string) get_post_field( 'post_content', $id, 'raw' );
		$title   = (string) get_post_field( 'post_title', $id, 'raw' );
		$english = HWCH_Lang::is_english_post( $id );
		$out     = array( 'id' => $id, 'english' => $english );

		// Performance.
		$out['metrics'] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . HWCH_Store::table( 'metrics' ) . ' WHERE post_id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out['trend']   = $out['metrics'] ? HWCH_Reports::trend( $out['metrics'] ) : null;
		$out['yoy']     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . HWCH_Store::table( 'yoy' ) . ' WHERE post_id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out['aio']     = $out['yoy'] ? HWCH_AIO::check( $out['yoy'] ) : null;

		// Season.
		$profiles      = HWCH_Seasonal::profiles();
		$out['season'] = null;

		if ( isset( $profiles[ $id ] ) && HWCH_Seasonal::is_seasonal( $profiles[ $id ] ) ) {
			$out['season'] = array( 'profile' => $profiles[ $id ], 'timing' => HWCH_Seasonal::timing( $profiles[ $id ] ) );
		}

		// Searches, and whether a heading reflects them.
		$headings = array_merge( array( $title ), HWCH_Questions::headings( $content ) );
		$ignore   = HWCH_Questions::place_words( $id );

		$out['searches']  = array();
		$out['questions'] = array();

		foreach ( HWCH_Overlap::queries_for( $id, 200 ) as $q ) {
			$covered = null !== HWCH_Questions::covered_by( $q['query'], $headings, $ignore );

			if ( count( $out['searches'] ) < 15 ) {
				$out['searches'][] = $q + array( 'covered' => $covered );
			}

			if ( ! $covered && HWCH_Questions::is_question( $q['query'] ) && (int) $q['impressions'] >= (int) HWCH_Settings::get( 'q_min_impr' ) && count( $out['questions'] ) < 10 ) {
				$out['questions'][] = $q;
			}
		}

		// Outdated years in the title and text.
		$year           = (int) gmdate( 'Y' );
		$out['years']   = array(
			'title'   => HWCH_Dated::past_years_in( $title, $year ),
			'content' => HWCH_Dated::past_years_in( wp_strip_all_tags( $content ), $year ),
		);

		// On-page.
		$onpage        = HWCH_Onpage::data();
		$out['onpage'] = array();

		if ( in_array( $id, $onpage['desc'], true ) ) {
			$out['onpage'][] = __( 'No meta description.', 'hw-content-health' );
		}
		if ( in_array( $id, $onpage['image'], true ) ) {
			$out['onpage'][] = __( 'No featured image.', 'hw-content-health' );
		}
		if ( isset( $onpage['short'][ $id ] ) ) {
			/* translators: %s: number of words */
			$out['onpage'][] = sprintf( __( 'Short: %s words.', 'hw-content-health' ), number_format_i18n( $onpage['short'][ $id ] ) );
		}
		if ( in_array( $id, $onpage['noindex'], true ) ) {
			$out['onpage'][] = __( 'Set to noindex: Google is told not to show it.', 'hw-content-health' );
		}
		foreach ( $onpage['titles'] as $group ) {
			if ( in_array( $id, $group, true ) ) {
				/* translators: %s: titles */
				$out['onpage'][] = sprintf( __( 'Same SEO title as: %s.', 'hw-content-health' ), implode( ', ', array_map( 'get_the_title', array_diff( $group, array( $id ) ) ) ) );
			}
		}

		// Overlapping articles.
		$out['overlap'] = array();

		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT post_a, post_b, overlap FROM ' . HWCH_Store::table( 'pairs' ) . ' WHERE post_a = %d OR post_b = %d ORDER BY overlap DESC LIMIT 5', $id, $id ), ARRAY_A ) as $pair ) { // phpcs:ignore WordPress.DB
			$other = (int) $pair['post_a'] === $id ? (int) $pair['post_b'] : (int) $pair['post_a'];

			if ( 'publish' === get_post_status( $other ) ) {
				$out['overlap'][] = array( 'id' => $other, 'overlap' => (int) $pair['overlap'] );
			}
		}

		// Links that no longer work, and links to add (English only, like those reports).
		$out['broken']    = array();
		$out['link_from'] = array();
		$out['link_to']   = array();

		if ( $english ) {
			foreach ( HWCH_Broken::internal() as $row ) {
				if ( in_array( $id, $row['sources'], true ) ) {
					$out['broken'][] = array( 'url' => $row['url'], 'why' => $row['problem'], 'final' => $row['final'] );
				}
			}

			$outbound = HWCH_Broken::outbound();

			foreach ( $outbound['broken'] as $row ) {
				if ( in_array( $id, $row['sources'], true ) ) {
					$out['broken'][] = array( 'url' => $row['url'], 'why' => 'outbound', 'final' => '', 'status' => $row['status'] );
				}
			}

			$out['link_from'] = HWCH_Links::suggestions( $id, HWCH_Links::graph() );
			$out['link_to']   = HWCH_Editor::link_to( $id, HWCH_Overlap::places( $id ), $content );
		}

		return $out;
	}

	/**
	 * The reasons to refresh now, in plain words, most urgent first.
	 *
	 * @param array $d Brief data.
	 * @return string[]
	 */
	public static function reasons( $d ) {
		$out  = array();
		$date = get_option( 'date_format' );

		if ( $d['season'] && ! $d['season']['timing']['in_season'] ) {
			/* translators: 1: season, 2: date */
			$out[] = sprintf( __( 'Seasonal (%1$s): refresh by %2$s so Google recrawls it before searches rise.', 'hw-content-health' ), HWCH_Seasonal::season_label( $d['season']['profile']['season'] ), date_i18n( $date, $d['season']['timing']['due'] ) );
		}
		if ( null !== $d['trend'] && $d['trend'] <= -20 ) {
			/* translators: %s: percentage */
			$out[] = sprintf( __( 'Clicks down %s%% in the last 90 days compared with the 90 days before.', 'hw-content-health' ), number_format_i18n( abs( $d['trend'] ) ) );
		}
		if ( $d['aio'] ) {
			/* translators: 1: clicks, 2: old rate, 3: new rate */
			$out[] = sprintf( __( 'Likely losing clicks to an AI Overview: about %1$s clicks fewer than last year at the same visibility (click rate %2$s, now %3$s).', 'hw-content-health' ), number_format_i18n( $d['aio']['lost'] ), number_format_i18n( 100 * $d['aio']['ctr_then'], 1 ) . '%', number_format_i18n( 100 * $d['aio']['ctr_now'], 1 ) . '%' );
		}
		if ( $d['years']['title'] ) {
			/* translators: %s: years */
			$out[] = sprintf( __( 'The title mentions %s.', 'hw-content-health' ), implode( ', ', $d['years']['title'] ) );
		}
		if ( $d['questions'] ) {
			/* translators: %s: number */
			$out[] = sprintf( _n( '%s question it appears for is not in a heading or FAQ.', '%s questions it appears for are not in a heading or FAQ.', count( $d['questions'] ), 'hw-content-health' ), number_format_i18n( count( $d['questions'] ) ) );
		}
		if ( $d['broken'] ) {
			/* translators: %s: number */
			$out[] = sprintf( _n( '%s link no longer works as linked.', '%s links no longer work as linked.', count( $d['broken'] ), 'hw-content-health' ), number_format_i18n( count( $d['broken'] ) ) );
		}
		if ( $d['overlap'] ) {
			$out[] = __( 'Overlaps with another article: consider merging rather than refreshing both.', 'hw-content-health' );
		}

		return $out;
	}

	/**
	 * The brief as plain text, for a ticket or a document.
	 *
	 * @param array $d Brief data.
	 * @return string
	 */
	public static function text( $d ) {
		$id    = $d['id'];
		$lines = array();
		$m     = $d['metrics'];
		$date  = get_option( 'date_format' );

		/* translators: %s: title */
		$lines[] = sprintf( __( 'Refresh brief: %s', 'hw-content-health' ), get_the_title( $id ) );
		$lines[] = get_permalink( $id );
		/* translators: %s: date */
		$lines[] = sprintf( __( 'Last updated %s', 'hw-content-health' ), date_i18n( $date, strtotime( get_post_field( 'post_modified_gmt', $id ) . ' UTC' ) ) );

		if ( $m ) {
			/* translators: 1: clicks, 2: impressions, 3: position, 4: trend */
			$lines[] = sprintf( __( '%1$s clicks, %2$s impressions, average position %3$s, 90-day trend %4$s', 'hw-content-health' ), number_format_i18n( $m['clicks'] ), number_format_i18n( $m['impressions'] ), number_format_i18n( $m['position'], 1 ), null === $d['trend'] ? '–' : ( $d['trend'] > 0 ? '+' : '' ) . $d['trend'] . '%' );
		}

		$section = function ( $title, $items ) use ( &$lines ) {
			if ( $items ) {
				$lines[] = '';
				$lines[] = strtoupper( $title );
				foreach ( $items as $item ) {
					$lines[] = '- ' . $item;
				}
			}
		};

		$section( __( 'Why now', 'hw-content-health' ), self::reasons( $d ) );
		$section(
			__( 'Searches to keep covering', 'hw-content-health' ),
			array_map(
				function ( $q ) {
					/* translators: 1: search, 2: clicks, 3: position, 4: note */
					return sprintf( __( '%1$s (%2$s clicks, position %3$s)%4$s', 'hw-content-health' ), $q['query'], number_format_i18n( $q['clicks'] ), number_format_i18n( $q['position'], 1 ), $q['covered'] ? '' : __( ' - not reflected in a heading', 'hw-content-health' ) );
				},
				$d['searches']
			)
		);
		$section(
			__( 'Questions to answer in a heading or the FAQ', 'hw-content-health' ),
			array_map(
				function ( $q ) {
					/* translators: 1: question, 2: impressions */
					return sprintf( __( '%1$s (%2$s impressions)', 'hw-content-health' ), $q['query'], number_format_i18n( $q['impressions'] ) );
				},
				$d['questions']
			)
		);
		$years = array();
		if ( $d['years']['title'] ) {
			/* translators: %s: years */
			$years[] = sprintf( __( 'Title: %s', 'hw-content-health' ), implode( ', ', $d['years']['title'] ) );
		}
		if ( $d['years']['content'] ) {
			/* translators: %s: years */
			$years[] = sprintf( __( 'Text: %s (check prices, opening times and events)', 'hw-content-health' ), implode( ', ', $d['years']['content'] ) );
		}
		$section( __( 'Past years mentioned', 'hw-content-health' ), $years );
		$section( __( 'On-page', 'hw-content-health' ), $d['onpage'] );
		$section(
			__( 'Links to fix', 'hw-content-health' ),
			array_map(
				function ( $b ) {
					return $b['url'] . ( $b['final'] ? ' → ' . $b['final'] : '' );
				},
				$d['broken']
			)
		);
		$section(
			__( 'Links to add from this article', 'hw-content-health' ),
			array_map(
				function ( $l ) {
					return $l['title'] . ' - ' . $l['url'];
				},
				$d['link_to']
			)
		);
		$section(
			__( 'Articles that should link here', 'hw-content-health' ),
			array_map(
				function ( $other ) {
					return get_the_title( $other ) . ' - ' . get_permalink( $other );
				},
				$d['link_from']
			)
		);
		$section(
			__( 'Overlapping articles', 'hw-content-health' ),
			array_map(
				function ( $o ) {
					/* translators: 1: title, 2: percentage */
					return sprintf( __( '%1$s (%2$s%% of searches shared)', 'hw-content-health' ), get_the_title( $o['id'] ), number_format_i18n( $o['overlap'] ) );
				},
				$d['overlap']
			)
		);

		return html_entity_decode( implode( "\n", $lines ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * A list of items, or a note when there are none.
	 *
	 * @param string   $title Heading.
	 * @param string[] $items HTML-safe items.
	 * @param string   $none  Shown when empty, or '' to hide the section.
	 * @return void
	 */
	private static function block( $title, $items, $none = '' ) {
		if ( ! $items && '' === $none ) {
			return;
		}

		echo '<div class="hwch-brief-block"><h3>' . esc_html( $title ) . '</h3>';

		if ( $items ) {
			echo '<ul class="hwch-list">';
			foreach ( $items as $item ) {
				echo '<li>' . wp_kses_post( $item ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="hwch-muted">' . esc_html( $none ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Renders the brief.
	 *
	 * @return void
	 */
	public static function render() {
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $id || 'post' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			echo '<p class="hwch-empty">' . esc_html__( 'Open a brief from an article in one of the reports.', 'hw-content-health' ) . '</p>';
			return;
		}

		$d    = self::data( $id );
		$m    = $d['metrics'];
		$date = get_option( 'date_format' );
		$link = function ( $post ) {
			return '<a href="' . esc_url( get_edit_post_link( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>';
		};
		?>
		<div class="hwch-brief">
			<h2>
				<?php
				/* translators: %s: title */
				echo esc_html( sprintf( __( 'Refresh brief: %s', 'hw-content-health' ), get_the_title( $id ) ) );
				?>
			</h2>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit the article', 'hw-content-health' ); ?></a>
				<a class="button" href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View it', 'hw-content-health' ); ?></a>
				<?php if ( ! get_post_meta( $id, '_hwch_queued', true ) ) : ?>
					<?php HWCH_Admin::button( 'queue', $id, __( 'Queue for refresh', 'hw-content-health' ) ); ?>
				<?php endif; ?>
				<?php HWCH_Admin::button( 'plan_add', $id, __( 'Add to the content plan', 'hw-content-health' ), array( 'topic' => 'refresh' ) ); ?>
			</p>

			<div class="hwch-kpis">
				<?php if ( $m ) : ?>
					<div class="hwch-kpi"><strong><?php echo esc_html( number_format_i18n( $m['clicks'] ) ); ?></strong><span><?php esc_html_e( 'clicks', 'hw-content-health' ); ?></span></div>
					<div class="hwch-kpi"><strong><?php echo esc_html( number_format_i18n( $m['impressions'] ) ); ?></strong><span><?php esc_html_e( 'impressions', 'hw-content-health' ); ?></span></div>
					<div class="hwch-kpi"><strong><?php echo esc_html( number_format_i18n( $m['position'], 1 ) ); ?></strong><span><?php esc_html_e( 'avg. position', 'hw-content-health' ); ?></span></div>
					<div class="hwch-kpi"><strong><?php echo esc_html( null === $d['trend'] ? '–' : ( $d['trend'] > 0 ? '+' : '' ) . $d['trend'] . '%' ); ?></strong><span><?php esc_html_e( 'clicks, last 90 days', 'hw-content-health' ); ?></span></div>
				<?php endif; ?>
				<div class="hwch-kpi"><strong><?php echo esc_html( date_i18n( $date, strtotime( get_post_field( 'post_modified_gmt', $id ) . ' UTC' ) ) ); ?></strong><span><?php esc_html_e( 'last updated', 'hw-content-health' ); ?></span></div>
			</div>

			<div class="hwch-brief-grid">
				<?php
				self::block( __( 'Why now', 'hw-content-health' ), array_map( 'esc_html', self::reasons( $d ) ), __( 'No urgent signal: refresh when it suits the plan.', 'hw-content-health' ) );

				self::block(
					__( 'Searches it ranks for', 'hw-content-health' ),
					array_map(
						function ( $q ) {
							/* translators: 1: search, 2: clicks, 3: position */
							return esc_html( sprintf( __( '%1$s · %2$s clicks · pos. %3$s', 'hw-content-health' ), $q['query'], number_format_i18n( $q['clicks'] ), number_format_i18n( $q['position'], 1 ) ) ) . ( $q['covered'] ? '' : ' <span class="hwch-warn">' . esc_html__( 'not reflected in a heading', 'hw-content-health' ) . '</span>' );
						},
						$d['searches']
					),
					__( 'No searches stored for this article.', 'hw-content-health' )
				);

				self::block(
					__( 'Questions to answer in a heading or the FAQ', 'hw-content-health' ),
					array_map(
						function ( $q ) {
							/* translators: 1: question, 2: impressions */
							return esc_html( sprintf( __( '%1$s · %2$s impressions', 'hw-content-health' ), $q['query'], number_format_i18n( $q['impressions'] ) ) );
						},
						$d['questions']
					),
					__( 'Every question search found is matched by a heading or FAQ question.', 'hw-content-health' )
				);

				$years = array();
				if ( $d['years']['title'] ) {
					/* translators: %s: years */
					$years[] = esc_html( sprintf( __( 'Title: %s', 'hw-content-health' ), implode( ', ', $d['years']['title'] ) ) );
				}
				if ( $d['years']['content'] ) {
					/* translators: %s: years */
					$years[] = esc_html( sprintf( __( 'Text: %s. Check prices, opening times and events.', 'hw-content-health' ), implode( ', ', $d['years']['content'] ) ) );
				}
				self::block( __( 'Past years mentioned', 'hw-content-health' ), $years );
				self::block( __( 'On-page', 'hw-content-health' ), array_map( 'esc_html', $d['onpage'] ) );

				$problems = array(
					'redirected'  => __( 'redirected', 'hw-content-health' ),
					'dead_end'    => __( 'redirects to an unpublished article', 'hw-content-health' ),
					'unpublished' => __( 'article not published', 'hw-content-health' ),
					'term'        => __( 'tag or category page removed', 'hw-content-health' ),
					'missing'     => __( 'page not found', 'hw-content-health' ),
					'outbound'    => __( 'outbound link broken', 'hw-content-health' ),
				);
				self::block(
					__( 'Links to fix', 'hw-content-health' ),
					array_map(
						function ( $b ) use ( $problems ) {
							return '<span class="hwch-url">' . esc_html( rawurldecode( $b['url'] ) ) . '</span> · ' . esc_html( $problems[ $b['why'] ] ) . ( $b['final'] ? ' → <span class="hwch-url">' . esc_html( rawurldecode( (string) wp_parse_url( $b['final'], PHP_URL_PATH ) ) ) . '</span>' : '' );
						},
						$d['broken']
					)
				);

				self::block(
					__( 'Links to add from this article', 'hw-content-health' ),
					array_map(
						function ( $l ) {
							return '<a href="' . esc_url( $l['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $l['title'] ) . '</a>' . ( ! empty( $l['hub'] ) ? ' <span class="hwch-muted">' . esc_html__( 'destination hub', 'hw-content-health' ) . '</span>' : '' );
						},
						$d['link_to']
					)
				);
				self::block( __( 'Articles that should link here', 'hw-content-health' ), array_map( $link, $d['link_from'] ) );

				self::block(
					__( 'Overlapping articles', 'hw-content-health' ),
					array_map(
						function ( $o ) use ( $link ) {
							/* translators: %s: percentage */
							return $link( $o['id'] ) . ' <span class="hwch-muted">' . esc_html( sprintf( __( '%s%% of searches shared', 'hw-content-health' ), number_format_i18n( $o['overlap'] ) ) ) . '</span>';
						},
						$d['overlap']
					)
				);
				?>
			</div>

			<?php if ( ! $d['english'] ) : ?>
				<p class="hwch-muted"><?php esc_html_e( 'Links to fix and links to add are checked for English articles only.', 'hw-content-health' ); ?></p>
			<?php endif; ?>

			<h3><?php esc_html_e( 'As plain text', 'hw-content-health' ); ?></h3>
			<p class="hwch-muted"><?php esc_html_e( 'To paste into a ticket or a document.', 'hw-content-health' ); ?></p>
			<textarea id="hwch-brief-text" class="large-text code" rows="14" readonly><?php echo esc_textarea( self::text( $d ) ); ?></textarea>
			<p><button type="button" class="button" onclick="var t=document.getElementById('hwch-brief-text');t.select();if(navigator.clipboard){navigator.clipboard.writeText(t.value);}else{document.execCommand('copy');}this.textContent='<?php echo esc_js( __( 'Copied', 'hw-content-health' ) ); ?>';"><?php esc_html_e( 'Copy', 'hw-content-health' ); ?></button></p>
		</div>
		<?php
	}
}
