<?php
/**
 * Question coverage: question-style searches an article appears for, and
 * whether the article answers each one in a heading or its FAQ.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Matches an article's question searches against its headings and FAQ
 * questions.
 *
 * A search is answered when one heading or FAQ question contains at least two
 * thirds of its meaningful words. The article's own destination is set aside
 * first, so "how many days in porto" is answered in a Porto article by a
 * heading like "How many days do you need?". A missing match means the
 * question is not in a heading or FAQ question; the answer may still be in a
 * paragraph, which is why the report says "not found" rather than "unanswered".
 */
class HWCH_Questions {

	const MATCH = 0.66;

	/**
	 * Words a question search can start with.
	 *
	 * @var string[]
	 */
	private static $starts = array( 'how', 'what', 'whats', 'when', 'where', 'which', 'who', 'whom', 'whose', 'why', 'is', 'are', 'was', 'were', 'am', 'can', 'could', 'do', 'does', 'did', 'should', 'would', 'will', 'has', 'have' );

	/**
	 * Phrases that make a search a question wherever they appear.
	 *
	 * @var string[]
	 */
	private static $phrases = array( 'best time to', 'worth it', 'worth visiting' );

	/**
	 * Extra words that carry no meaning when matching a question to a heading.
	 *
	 * @var string[]
	 */
	private static $filler = array( 'do', 'does', 'did', 'can', 'could', 'should', 'would', 'will', 'am', 'was', 'were', 'has', 'have', 'there', 'i', 'we', 'me', 'my', 'much', 'need', 'get', 'go', 'going', 'any', 'some', 'about', 'into', 'than', 'then', 'if', 'so', 'much', 'really', 'actually', 'good', 'whats', 'spend', 'you', 'your' );

	/**
	 * Close variants treated as the same word.
	 *
	 * @var array
	 */
	private static $variants = array(
		'safety'    => 'safe',
		'dangerous' => 'safe',
		'expensive' => 'cost',
		'price'     => 'cost',
		'prices'    => 'cost',
		'cheap'     => 'cost',
		'budget'    => 'cost',
		'costs'     => 'cost',
		'when'      => 'time',
		'month'     => 'time',
		'season'    => 'time',
		'long'      => 'day',
		'days'      => 'day',
		'nights'    => 'day',
	);

	/**
	 * Whether a search is phrased as a question.
	 *
	 * @param string $query Search.
	 * @return bool
	 */
	public static function is_question( $query ) {
		$query = ' ' . strtolower( trim( (string) $query ) ) . ' ';
		$first = strtok( ltrim( $query ), ' ' );

		if ( in_array( $first, self::$starts, true ) || '?' === substr( trim( $query ), -1 ) ) {
			return true;
		}

		foreach ( self::$phrases as $phrase ) {
			if ( false !== strpos( $query, ' ' . $phrase . ' ' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The meaningful words of a question or heading, with close variants
	 * merged and simple plurals removed.
	 *
	 * @param string   $text   Text.
	 * @param string[] $ignore Words to leave out (the article's destination).
	 * @return string[]
	 */
	public static function words( $text, $ignore = array() ) {
		// "When" is a question word, but in a heading it answers "best time".
		$text  = strtolower( remove_accents( wp_strip_all_tags( (string) $text ) ) );
		$raw   = preg_split( '/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$words = array();

		foreach ( $raw as $word ) {
			if ( isset( self::$variants[ $word ] ) ) {
				$word = self::$variants[ $word ];
			} elseif ( strlen( $word ) > 4 && 'ies' === substr( $word, -3 ) ) {
				$word = substr( $word, 0, -3 ) . 'y';
			} elseif ( strlen( $word ) > 3 && 's' === substr( $word, -1 ) && 'ss' !== substr( $word, -2 ) ) {
				$word = substr( $word, 0, -1 );
			}

			if ( 'time' !== $word && ( strlen( $word ) < 2 || in_array( $word, self::$filler, true ) || ! HWCH_Overlap::tokens( $word ) ) ) {
				continue;
			}

			if ( in_array( $word, $ignore, true ) ) {
				continue;
			}

			$words[ $word ] = true;
		}

		return array_keys( $words );
	}

	/**
	 * The headings and FAQ questions in an article's content. Recognises
	 * h2 to h6, the native Details block (summary), the Yoast and Rank Math FAQ
	 * blocks, elements whose class names an FAQ question or accordion title,
	 * the class set in Settings, bold lines ending in a question mark, and
	 * questions stored in block settings (custom FAQ blocks often keep them
	 * there rather than in the HTML).
	 *
	 * @param string $content Post content.
	 * @return string[]
	 */
	public static function headings( $content ) {
		$content = (string) $content;
		$found   = array();

		// Questions stored as block attributes, e.g. <!-- wp:x/faq {"question":"…"} -->.
		if ( preg_match_all( '/<!--\s+wp:[a-z0-9\/-]+\s+(\{.*?\})\s+\/?-->/s', $content, $blocks ) ) {
			foreach ( $blocks[1] as $json ) {
				$attrs = json_decode( $json, true );

				if ( is_array( $attrs ) ) {
					array_walk_recursive(
						$attrs,
						function ( $value, $key ) use ( &$found ) {
							if ( is_string( $value ) && is_string( $key ) && preg_match( '/question|title|summary|heading/i', $key ) ) {
								$found[] = $value;
							}
						}
					);
				}
			}
		}

		if ( ! class_exists( 'DOMDocument' ) || '' === trim( $content ) ) {
			return self::clean( $found );
		}

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $content . '</div>' );
		libxml_clear_errors();

		$xpath  = new DOMXPath( $doc );
		$custom = trim( (string) HWCH_Settings::get( 'faq_class' ) );
		$class  = 'contains(concat(" ", normalize-space(@class), " "), " %s ")';
		$paths  = array(
			'//h2', '//h3', '//h4', '//h5', '//h6', '//summary', '//dt',
			'//*[' . sprintf( $class, 'schema-faq-question' ) . ']',
			'//*[' . sprintf( $class, 'rank-math-question' ) . ']',
			'//*[contains(@class, "faq") and (contains(@class, "question") or contains(@class, "title") or contains(@class, "header"))]',
			'//*[contains(@class, "accordion") and (contains(@class, "title") or contains(@class, "header") or contains(@class, "question") or contains(@class, "toggle"))]',
		);

		if ( $custom ) {
			$paths[] = '//*[' . sprintf( $class, esc_attr( $custom ) ) . ']';
		}

		foreach ( $paths as $path ) {
			foreach ( $xpath->query( $path ) as $node ) {
				$found[] = $node->textContent;
			}
		}

		// FAQs written as bold questions without an accordion.
		foreach ( $xpath->query( '//strong | //b' ) as $node ) {
			if ( '?' === substr( trim( $node->textContent ), -1 ) ) {
				$found[] = $node->textContent;
			}
		}

		return self::clean( $found );
	}

	/**
	 * Trims and de-duplicates texts.
	 *
	 * @param string[] $texts Texts.
	 * @return string[]
	 */
	private static function clean( $texts ) {
		$out = array();

		foreach ( $texts as $text ) {
			$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) ) );

			if ( '' !== $text && mb_strlen( $text ) < 300 ) {
				$out[ strtolower( $text ) ] = $text;
			}
		}

		return array_values( $out );
	}

	/**
	 * Whether a question is covered by any of the headings, and by which.
	 *
	 * @param string   $query    Question search.
	 * @param string[] $headings Headings and FAQ questions.
	 * @param string[] $ignore   Words to set aside (the article's destination).
	 * @return string|null The heading that covers it, or null.
	 */
	public static function covered_by( $query, $headings, $ignore = array() ) {
		$want = self::words( $query, $ignore );

		if ( ! $want ) {
			// Nothing left once the destination is set aside, e.g. "what to do in porto"
			// becomes nothing specific: treat it as covered by the article itself.
			return '';
		}

		$best       = null;
		$best_score = 0;

		foreach ( $headings as $heading ) {
			$have  = self::words( $heading );
			$score = count( array_intersect( $want, $have ) ) / count( $want );

			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $heading;
			}
		}

		return $best_score >= self::MATCH ? $best : null;
	}

	/**
	 * The words for an article's destinations, to set aside when matching.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public static function place_words( $post_id ) {
		$names = HWCH_Overlap::destinations();
		$words = array();

		foreach ( HWCH_Overlap::places( $post_id ) as $slug ) {
			foreach ( isset( $names[ $slug ] ) ? $names[ $slug ] : array() as $name ) {
				$words = array_merge( $words, preg_split( '/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY ) );
			}
		}

		return array_values( array_unique( $words ) );
	}

	/**
	 * Every English article with question searches, and which ones its
	 * headings and FAQ cover.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array List of array( id, clicks, missing, covered, missing_impr ).
	 */
	public static function data( $refresh = false ) {
		global $wpdb;

		$cached = $refresh ? false : get_transient( 'hwch_questions' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT q.post_id, q.query, q.clicks, q.impressions, q.position FROM ' . HWCH_Store::table( 'queries' ) . " q
				INNER JOIN {$wpdb->posts} p ON p.ID = q.post_id AND p.post_type = 'post' AND p.post_status = 'publish'
				WHERE q.impressions >= %d", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) HWCH_Settings::get( 'q_min_impr' )
			),
			ARRAY_A
		);

		$by_post = array();

		foreach ( (array) $rows as $row ) {
			if ( self::is_question( $row['query'] ) ) {
				$by_post[ (int) $row['post_id'] ][] = $row;
			}
		}

		$english = array_flip( HWCH_Reports::english_ids( array_keys( $by_post ) ) );
		$metrics = HWCH_Overlap::metrics();
		$out     = array();

		foreach ( $by_post as $post_id => $questions ) {
			if ( ! isset( $english[ $post_id ] ) ) {
				continue;
			}

			$headings = self::headings( get_post_field( 'post_content', $post_id, 'raw' ) );
			$ignore   = self::place_words( $post_id );
			$missing  = array();
			$covered  = array();

			foreach ( $questions as $q ) {
				$by   = self::covered_by( $q['query'], $headings, $ignore );
				$item = array(
					'query'       => $q['query'],
					'impressions' => (int) $q['impressions'],
					'clicks'      => (int) $q['clicks'],
					'position'    => (float) $q['position'],
					'by'          => (string) $by,
				);

				if ( null === $by ) {
					$missing[] = $item;
				} else {
					$covered[] = $item;
				}
			}

			$sort = function ( $a, $b ) {
				return $b['impressions'] <=> $a['impressions'];
			};
			usort( $missing, $sort );
			usort( $covered, $sort );

			$out[] = array(
				'id'           => $post_id,
				'clicks'       => isset( $metrics[ $post_id ] ) ? (int) $metrics[ $post_id ]['clicks'] : 0,
				'missing'      => $missing,
				'covered'      => $covered,
				'missing_impr' => array_sum( wp_list_pluck( $missing, 'impressions' ) ),
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $b['missing_impr'] <=> $a['missing_impr'];
			}
		);

		if ( $out ) {
			set_transient( 'hwch_questions', $out, 12 * HOUR_IN_SECONDS );
		}

		return $out;
	}

	/**
	 * Renders the tab.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! get_option( 'hwch_last_overlap' ) ) {
			echo '<p class="hwch-empty">' . esc_html__( 'This report appears after the next successful sync, which now also keeps each article\'s searches.', 'hw-content-health' ) . '</p>';
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$refresh = isset( $_GET['refresh'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['refresh'] ) ), 'hwch_refresh' );
		// phpcs:enable

		$now  = time();
		$rows = array_values(
			array_filter(
				self::data( $refresh ),
				function ( $row ) use ( $now ) {
					return $row['missing'] && (int) get_post_meta( $row['id'], '_hwch_dismissed_questions', true ) < $now;
				}
			)
		);

		global $wpdb;

		$all      = self::data();
		$stored   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . HWCH_Store::table( 'queries' ) ); // phpcs:ignore WordPress.DB
		$asked    = 0;
		$matched  = 0;
		foreach ( $all as $item ) {
			$asked   += count( $item['missing'] ) + count( $item['covered'] );
			$matched += count( $item['covered'] );
		}

		$total = count( $rows );
		$rows  = array_slice( $rows, ( $paged - 1 ) * HWCH_Export::per( 25 ), HWCH_Export::per( 25 ) );
		?>
		<p class="hwch-intro">
			<?php
			printf(
				/* translators: %s: impressions */
				esc_html__( 'Question-style searches each English article already appears for (at least %s impressions), which none of its headings or FAQ questions match. Adding them as a heading or an FAQ is the most direct way to win featured snippets and AI Overview citations. A match needs two thirds of the question\'s words in one heading; the article\'s own destination is set aside. The answer may still be in a paragraph, so check before adding one. Articles with the most unmatched impressions first.', 'hw-content-health' ),
				esc_html( number_format_i18n( (int) HWCH_Settings::get( 'q_min_impr' ) ) )
			);
			?>
			<a href="<?php echo esc_url( add_query_arg( 'refresh', wp_create_nonce( 'hwch_refresh' ) ) ); ?>"><?php esc_html_e( 'Recheck now', 'hw-content-health' ); ?></a>
		</p>
		<p class="hwch-count">
			<?php
			/* translators: %s: number */
			echo esc_html( sprintf( _n( '%s article', '%s articles', $total, 'hw-content-health' ), number_format_i18n( $total ) ) );
			?>
		</p>
		<p class="hwch-muted">
			<?php
			/* translators: 1: searches stored, 2: question searches, 3: articles, 4: matched */
			echo esc_html( sprintf( __( 'Checked: %1$s searches stored from the last sync, of which %2$s are questions on %3$s English articles; %4$s are matched by a heading or FAQ question.', 'hw-content-health' ), number_format_i18n( $stored ), number_format_i18n( $asked ), number_format_i18n( count( $all ) ), number_format_i18n( $matched ) ) );
			?>
		</p>
		<?php if ( ! $stored ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'No searches are stored yet, so there is nothing to check. They are saved by the sync: click Sync now at the top. If the sync fails, its error appears at the top of this page.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php elseif ( ! $asked ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'None of the stored searches with enough impressions are phrased as questions. Lower "Questions with at least … impressions" in Settings to include smaller ones.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php elseif ( ! $total ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'Every question search found is matched by a heading or FAQ question, or the article was marked "Covered already".', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Article', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Not found in a heading or FAQ', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Already matched', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'hw-content-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>"><?php echo esc_html( get_the_title( $row['id'] ) ); ?></a></strong>
							<div class="hwch-url"><a href="<?php echo esc_url( get_permalink( $row['id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( rawurldecode( (string) wp_parse_url( get_permalink( $row['id'] ), PHP_URL_PATH ) ) ); ?></a></div>
							<?php /* translators: %s: clicks */ ?>
							<div class="hwch-muted"><?php echo esc_html( sprintf( __( '%s clicks in the data window', 'hw-content-health' ), number_format_i18n( $row['clicks'] ) ) ); ?></div>
						</td>
						<td>
							<table class="hwch-qtable">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Question', 'hw-content-health' ); ?></th>
										<th><?php esc_html_e( 'Impressions', 'hw-content-health' ); ?></th>
										<th><?php esc_html_e( 'Position', 'hw-content-health' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( array_slice( $row['missing'], 0, 8 ) as $q ) : ?>
										<tr>
											<td><?php echo esc_html( $q['query'] ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $q['impressions'] ) ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $q['position'], 1 ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<?php if ( count( $row['missing'] ) > 8 ) : ?>
								<details class="hwch-details">
									<?php /* translators: %s: number */ ?>
									<summary><?php echo esc_html( sprintf( __( '%s more', 'hw-content-health' ), number_format_i18n( count( $row['missing'] ) - 8 ) ) ); ?></summary>
									<ul class="hwch-list">
										<?php foreach ( array_slice( $row['missing'], 8 ) as $q ) : ?>
											<?php /* translators: 1: question, 2: impressions */ ?>
											<li><?php echo esc_html( sprintf( __( '%1$s (%2$s impressions)', 'hw-content-health' ), $q['query'], number_format_i18n( $q['impressions'] ) ) ); ?></li>
										<?php endforeach; ?>
									</ul>
								</details>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! $row['covered'] ) : ?>
								<span class="hwch-muted"><?php esc_html_e( 'None', 'hw-content-health' ); ?></span>
							<?php else : ?>
								<details class="hwch-details">
									<?php /* translators: %s: number */ ?>
									<summary><?php echo esc_html( sprintf( _n( '%s question', '%s questions', count( $row['covered'] ), 'hw-content-health' ), number_format_i18n( count( $row['covered'] ) ) ) ); ?></summary>
									<ul class="hwch-list">
										<?php foreach ( $row['covered'] as $q ) : ?>
											<li>
												<?php echo esc_html( $q['query'] ); ?>
												<?php if ( '' !== $q['by'] ) : ?>
													<div class="hwch-muted">&ldquo;<?php echo esc_html( $q['by'] ); ?>&rdquo;</div>
												<?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>
								</details>
							<?php endif; ?>
						</td>
						<td class="hwch-actions">
							<a class="button button-small button-primary" href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'hw-content-health' ); ?></a>
							<a class="button button-small" href="<?php echo esc_url( HWCH_Brief::url( $row['id'] ) ); ?>"><?php esc_html_e( 'Refresh brief', 'hw-content-health' ); ?></a>
							<?php HWCH_Admin::button( 'dismiss', $row['id'], __( 'Covered already', 'hw-content-health' ), array( 'report' => 'questions' ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		HWCH_Admin::paginate( $total, $paged, 25 );
	}
}
