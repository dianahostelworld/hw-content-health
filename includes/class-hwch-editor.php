<?php
/**
 * The check in the post editor: similar articles that already exist, links
 * to add in both directions, and an existing article's own search figures.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the editor panel and the data behind it.
 */
class HWCH_Editor {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'assets' ) );
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'classic_box' ) );
		add_action( 'wp_ajax_hwch_box', array( __CLASS__, 'ajax_box' ) );
		// Rebuilt in the background every hour, so nobody waits for them
		// when opening an article.
		add_action( 'hwch_hourly', array( __CLASS__, 'warm' ), 20 );
	}

	/**
	 * For articles edited in the Classic Editor (no block editor sidebar),
	 * the same check as a box beside the editor, based on the saved version.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function classic_box( $post ) {
		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return;
		}

		add_meta_box( 'hwch-content-health', __( 'Content health', 'hw-content-health' ), array( __CLASS__, 'render_box' ), 'post', 'side', 'high' );
	}

	/**
	 * The Classic Editor box.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render_box( $post ) {
		// The box fills in after the editor has opened, so a slow check never
		// holds up the article.
		$nonce = wp_create_nonce( 'hwch_box' );
		?>
		<div id="hwch-box-body"><p class="description"><span class="spinner is-active" style="float:none;margin:0 6px 0 0"></span><?php esc_html_e( 'Loading…', 'hw-content-health' ); ?></p></div>
		<script>
		( function () {
			var el = document.getElementById( 'hwch-box-body' );
			var body = new URLSearchParams( { action: 'hwch_box', post_id: '<?php echo (int) $post->ID; ?>', _ajax_nonce: '<?php echo esc_js( $nonce ); ?>' } );
			fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( r ) {
					el.innerHTML = r && r.success ? r.data : '<p class="description"><?php echo esc_js( __( 'Could not load. Reload the page to try again.', 'hw-content-health' ) ); ?></p>';
				} )
				.catch( function () {
					el.innerHTML = '<p class="description"><?php echo esc_js( __( 'Could not load. Reload the page to try again.', 'hw-content-health' ) ); ?></p>';
				} );
		} )();
		</script>
		<?php
	}

	/**
	 * Sends the box's contents for the article being edited.
	 *
	 * @return void
	 */
	public static function ajax_box() {
		check_ajax_referer( 'hwch_box' );

		$post = get_post( isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0 );

		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			wp_send_json_error( null, 403 );
		}

		ob_start();
		self::box_contents( $post );
		wp_send_json_success( ob_get_clean() );
	}

	/**
	 * Builds the slow, shared data the box uses, ahead of time.
	 *
	 * @return void
	 */
	public static function warm() {
		if ( ! HWCH_Store::has_data() ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		// The address map is normally saved by the weekly sync; build it now
		// if this site has not synced since the update.
		if ( ! get_option( 'hwch_url_ids' ) ) {
			HWCH_Store::url_map();
		}

		delete_transient( 'hwch_editor_pool' );
		self::pool();
		HWCH_Links::graph( true );
	}

	/**
	 * What the Classic Editor box shows.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	private static function box_contents( $post ) {
		if ( ! HWCH_Store::has_data() ) {
			echo '<p>' . esc_html__( 'No Search Console data yet. Run the first sync in Content health.', 'hw-content-health' ) . '</p>';
			return;
		}

		$request = new WP_REST_Request( 'POST', '/hwch/v1/check' );
		$request->set_param( 'post_id', $post->ID );
		$request->set_param( 'title', $post->post_title );
		$request->set_param( 'content', $post->post_content );
		$request->set_param( 'tags', wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) ) );

		$d    = self::check( $request );
		$list = function ( $items, $empty ) {
			if ( ! $items ) {
				echo '<p class="description">' . esc_html( $empty ) . '</p>';
				return;
			}
			echo '<ul style="margin:0 0 8px">';
			foreach ( $items as $item ) {
				echo '<li><a href="' . esc_url( $item['edit'] ? $item['edit'] : $item['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $item['title'] ) . '</a>';
				if ( ! empty( $item['hub'] ) ) {
					echo ' <span class="description">(' . esc_html__( 'destination hub', 'hw-content-health' ) . ')</span>';
				}
				echo '</li>';
			}
			echo '</ul>';
		};

		echo '<p class="description">' . esc_html__( 'Based on the last saved version. Save or update to check again.', 'hw-content-health' ) . '</p>';

		if ( $d['plan'] ) {
			echo '<p><strong>' . esc_html__( 'From the content plan', 'hw-content-health' ) . '</strong>';
			if ( $d['plan']['topic'] ) {
				echo '<br>' . esc_html( $d['plan']['topic'] . ( $d['plan']['due'] ? ' · ' . $d['plan']['due'] : '' ) );
			}
			if ( $d['plan']['notes'] ) {
				echo '<br>' . esc_html( $d['plan']['notes'] );
			}
			if ( $d['plan']['searches'] ) {
				echo '<br>' . esc_html__( 'Searches to cover:', 'hw-content-health' ) . ' ' . esc_html( implode( ', ', wp_list_pluck( $d['plan']['searches'], 'query' ) ) );
			}
			echo '</p>';
		}

		if ( $d['briefUrl'] ) {
			echo '<p><a class="button" href="' . esc_url( $d['briefUrl'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open the refresh brief', 'hw-content-health' ) . '</a></p>';
		}

		if ( $d['stats'] ) {
			$st = $d['stats'];
			/* translators: 1: clicks, 2: impressions, 3: position */
			echo '<p><strong>' . esc_html( sprintf( __( '%1$s clicks, %2$s impressions, position %3$s', 'hw-content-health' ), number_format_i18n( $st['clicks'] ), number_format_i18n( $st['impressions'] ), number_format_i18n( $st['position'], 1 ) ) ) . '</strong></p>';
			if ( $st['season'] ) {
				/* translators: 1: season, 2: date */
				echo '<p>' . esc_html( sprintf( __( 'Seasonal: %1$s. %2$s', 'hw-content-health' ), $st['season']['label'], $st['season']['due'] ? sprintf( /* translators: %s: date */ __( 'Refresh by %s.', 'hw-content-health' ), $st['season']['due'] ) : __( 'In season now.', 'hw-content-health' ) ) ) . '</p>';
			}
			if ( $st['queries'] ) {
				echo '<p><strong>' . esc_html__( 'Top searches', 'hw-content-health' ) . '</strong><br>' . esc_html( implode( ', ', wp_list_pluck( $st['queries'], 'query' ) ) ) . '</p>';
			}
			if ( $st['questions'] ) {
				echo '<p><strong>' . esc_html__( 'Questions not yet in a heading or FAQ', 'hw-content-health' ) . '</strong><br>' . esc_html( implode( ', ', wp_list_pluck( $st['questions'], 'query' ) ) ) . '</p>';
			}
		}

		if ( ! $d['english'] ) {
			echo '<p class="description">' . esc_html__( 'Similar articles and link suggestions are checked for English articles only.', 'hw-content-health' ) . '</p>';
			return;
		}

		echo '<p><strong>' . esc_html__( 'Similar articles already published', 'hw-content-health' ) . '</strong></p>';
		$list( $d['similar'], __( 'None found among published English articles.', 'hw-content-health' ) );

		if ( ! $d['places'] ) {
			echo '<p class="description">' . esc_html__( 'Add a destination tag to get link suggestions.', 'hw-content-health' ) . '</p>';
			return;
		}

		echo '<p><strong>' . esc_html__( 'Link to these from this article', 'hw-content-health' ) . '</strong></p>';
		$list( $d['linkTo'], __( 'Already links to the main ones.', 'hw-content-health' ) );
		echo '<p><strong>' . esc_html__( 'Link to this article from these', 'hw-content-health' ) . '</strong></p>';
		$list( $d['linkFrom'], __( 'Every other article about this destination links here already.', 'hw-content-health' ) );
	}

	/**
	 * Loads the panel in the block editor, for articles only.
	 *
	 * @return void
	 */
	public static function assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'post' !== $screen->post_type || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_enqueue_script( 'hwch-editor', HWCH_URL . 'assets/editor.js', array( 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n' ), HWCH_VERSION, true );
		wp_enqueue_style( 'hwch-editor', HWCH_URL . 'assets/editor.css', array(), HWCH_VERSION );
		wp_localize_script(
			'hwch-editor',
			'hwchEditor',
			array(
				'hasData'   => HWCH_Store::has_data(),
				'reportUrl' => admin_url( 'admin.php?page=hwch&tab=overlap' ),
			)
		);
	}

	/**
	 * The REST route the panel calls.
	 *
	 * @return void
	 */
	public static function routes() {
		register_rest_route(
			'hwch/v1',
			'/check',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'check' ),
				'permission_callback' => function ( $request ) {
					$id = (int) $request->get_param( 'post_id' );
					return $id ? current_user_can( 'edit_post', $id ) : current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'post_id' => array( 'type' => 'integer', 'default' => 0 ),
					'title'   => array( 'type' => 'string', 'default' => '' ),
					'content' => array( 'type' => 'string', 'default' => '' ),
					'tags'    => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'integer' ) ),
				),
			)
		);
	}

	/**
	 * Whether an article is, or will be, in English. A new draft that the
	 * language plugin has not assigned yet counts as the site's main language.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_english( $post_id ) {
		if ( ! $post_id || HWCH_Reports::english_ids( array( $post_id ) ) ) {
			return true;
		}

		$code = '';

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post_id );
			$code    = is_array( $details ) && ! empty( $details['language_code'] ) ? $details['language_code'] : '';
		} elseif ( function_exists( 'pll_get_post_language' ) ) {
			$code = (string) pll_get_post_language( $post_id );
		}

		if ( '' === $code && ! defined( 'ICL_SITEPRESS_VERSION' ) && ! function_exists( 'pll_get_post_language' ) ) {
			return '' === HWCH_Lang::folder_in( get_permalink( $post_id ) );
		}

		return '' === $code ? 0 === strpos( HWCH_Lang::default_code(), 'en' ) : 0 === strpos( $code, 'en' );
	}

	/**
	 * Everything the panel shows.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function check( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$title   = trim( wp_strip_all_tags( (string) $request->get_param( 'title' ) ) );
		$content = (string) $request->get_param( 'content' );
		$slugs   = array();

		foreach ( (array) $request->get_param( 'tags' ) as $term_id ) {
			$term = get_term( (int) $term_id, 'post_tag' );

			if ( $term && ! is_wp_error( $term ) ) {
				$slugs[] = $term->slug;
			}
		}

		$english = self::is_english( $post_id );
		$places  = $english ? HWCH_Overlap::places_for( $title, $slugs ) : array();
		$names   = array();

		foreach ( $places as $slug ) {
			$term    = get_term_by( 'slug', $slug, 'post_tag' );
			$names[] = $term && ! is_wp_error( $term ) ? $term->name : $slug;
		}

		return array(
			'english'   => $english,
			'hasData'   => HWCH_Store::has_data(),
			'stats'     => self::stats( $post_id, $content, $english ),
			'similar'   => $english ? self::similar( $post_id, $title, $places ) : array(),
			'linkFrom'  => $english ? self::link_from( $post_id, $places ) : array(),
			'linkTo'    => $english ? self::link_to( $post_id, $places, $content ) : array(),
			'places'    => $names,
			'wordCount' => count( HWCH_Overlap::tokens( $title ) ),
			'plan'      => HWCH_Planner::for_post( $post_id ),
			'briefUrl'  => $post_id && 'publish' === get_post_status( $post_id ) ? HWCH_Brief::url( $post_id ) : '',
		);
	}

	/**
	 * A post as the panel lists it.
	 *
	 * @param int   $id      Post ID.
	 * @param array $metrics Metrics map.
	 * @return array
	 */
	private static function item( $id, $metrics ) {
		return array(
			'id'     => $id,
			'title'  => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'edit'   => get_edit_post_link( $id, 'raw' ),
			'url'    => get_permalink( $id ),
			'clicks' => isset( $metrics[ $id ] ) ? (int) $metrics[ $id ]['clicks'] : 0,
		);
	}

	/**
	 * An existing article's own search figures.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Content as it is in the editor now.
	 * @param bool   $english Whether the article is in English.
	 * @return array|null
	 */
	private static function stats( $post_id, $content, $english ) {
		global $wpdb;

		if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . HWCH_Store::table( 'metrics' ) . ' WHERE post_id = %d', $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		if ( ! $row ) {
			return null;
		}

		$season   = null;
		$profiles = HWCH_Seasonal::profiles();

		if ( isset( $profiles[ $post_id ] ) && HWCH_Seasonal::is_seasonal( $profiles[ $post_id ] ) ) {
			$p      = $profiles[ $post_id ];
			$timing = HWCH_Seasonal::timing( $p );
			$season = array(
				'label'    => HWCH_Seasonal::season_label( $p['season'] ),
				'peak'     => HWCH_Seasonal::month_name( $p['peak'] ),
				'strength' => round( $p['strength'], 1 ),
				'due'      => $timing['in_season'] ? '' : date_i18n( get_option( 'date_format' ), $timing['due'] ),
			);
		}

		$queries = array();

		foreach ( HWCH_Overlap::queries_for( $post_id, 8 ) as $q ) {
			$queries[] = array(
				'query'       => $q['query'],
				'clicks'      => (int) $q['clicks'],
				'impressions' => (int) $q['impressions'],
				'position'    => (float) $q['position'],
			);
		}

		$range = get_option( 'hwch_last_sync_range' );

		return array(
			'clicks'      => (int) $row['clicks'],
			'impressions' => (int) $row['impressions'],
			'position'    => (float) $row['position'],
			'trend'       => HWCH_Reports::trend( $row ),
			'months'      => (int) HWCH_Settings::get( 'window_months' ),
			'queries'     => $queries,
			'season'      => $season,
			'questions'   => $english ? self::open_questions( $post_id, $content ) : array(),
			'range'       => is_array( $range ) ? array( HWCH_Dashboard::date( $range['start'] ), HWCH_Dashboard::date( $range['end'] ) ) : null,
		);
	}

	/**
	 * The article's question searches that no heading or FAQ question
	 * matches, checked against the content as it is in the editor now, so a
	 * question drops off the list as soon as it is added.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Current content.
	 * @return array
	 */
	private static function open_questions( $post_id, $content ) {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT query, impressions FROM ' . HWCH_Store::table( 'queries' ) . ' WHERE post_id = %d AND impressions >= %d ORDER BY impressions DESC LIMIT 200', // phpcs:ignore WordPress.DB.PreparedSQL
				$post_id,
				(int) HWCH_Settings::get( 'q_min_impr' )
			),
			ARRAY_A
		);

		$headings = HWCH_Questions::headings( $content );
		$ignore   = HWCH_Questions::place_words( $post_id );
		$out      = array();

		foreach ( (array) $rows as $row ) {
			if ( HWCH_Questions::is_question( $row['query'] ) && null === HWCH_Questions::covered_by( $row['query'], $headings, $ignore ) ) {
				$out[] = array( 'query' => $row['query'], 'impressions' => (int) $row['impressions'] );
			}

			if ( count( $out ) >= 6 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Every published English article's meaningful title words, for quick
	 * comparison. Cached for an hour.
	 *
	 * @return array Map of post ID => words.
	 */
	private static function pool() {
		global $wpdb;

		$cached = get_transient( 'hwch_editor_pool' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$keep = array_flip( HWCH_Reports::english_ids( wp_list_pluck( (array) $rows, 'ID' ) ) );
		$pool = array();

		foreach ( (array) $rows as $row ) {
			if ( isset( $keep[ (int) $row['ID'] ] ) ) {
				$pool[ (int) $row['ID'] ] = HWCH_Overlap::tokens( $row['post_title'] );
			}
		}

		set_transient( 'hwch_editor_pool', $pool, 2 * HOUR_IN_SECONDS );

		return $pool;
	}

	/**
	 * Published articles that already target the same topic: titles alike,
	 * articles that already appear for searches matching this title, and
	 * pairs already flagged in Overlapping articles. Articles about a
	 * different destination never count.
	 *
	 * @param int      $post_id This article (0 for none yet).
	 * @param string   $title   Title as typed.
	 * @param string[] $places  Destinations of this article.
	 * @return array
	 */
	private static function similar( $post_id, $title, $places ) {
		global $wpdb;

		$want    = HWCH_Overlap::tokens( $title );
		$found   = array();
		$metrics = HWCH_Overlap::metrics();

		$differ = function ( $other ) use ( $places ) {
			return HWCH_Overlap::places_differ( $places, HWCH_Overlap::places( $other ) );
		};

		// Titles alike.
		if ( count( $want ) >= 2 ) {
			foreach ( self::pool() as $id => $tokens ) {
				if ( $id === $post_id || ! $tokens ) {
					continue;
				}

				$score = HWCH_Overlap::similarity( $want, $tokens );

				if ( $score >= 0.5 && ! $differ( $id ) ) {
					$found[ $id ]['title'] = (int) round( $score * 100 );
				}
			}
		}

		// Searches matching the title, in either direction: most of the
		// search's words are in the title ("best bars krakow" for "The Best
		// Bars in Kraków for Backpackers"), or most of the title's words are
		// in the search. At least two meaningful words must match.
		if ( count( $want ) >= 2 ) {
			$likes = array();
			$words = $want;
			usort(
				$words,
				function ( $a, $b ) {
					return strlen( $b ) <=> strlen( $a );
				}
			);

			foreach ( array_slice( $words, 0, 4 ) as $word ) {
				$likes[] = $wpdb->prepare( 'query LIKE %s', '%' . $wpdb->esc_like( $word ) . '%' );
			}

			$rows = $wpdb->get_results( 'SELECT post_id, query, impressions, position FROM ' . HWCH_Store::table( 'queries' ) . ' WHERE ' . implode( ' OR ', $likes ) . ' ORDER BY impressions DESC LIMIT 5000', ARRAY_A ); // phpcs:ignore WordPress.DB

			$pool = self::pool();

			foreach ( (array) $rows as $row ) {
				$id = (int) $row['post_id'];

				if ( $id === $post_id || ! isset( $pool[ $id ] ) ) {
					continue;
				}

				$words  = HWCH_Overlap::tokens( $row['query'] );
				$common = count( array_intersect( $want, $words ) );

				if ( $common < 2 || $common / min( count( $want ), max( 1, count( $words ) ) ) < 0.75 || $differ( $id ) ) {
					continue;
				}

				if ( ! isset( $found[ $id ]['impressions'] ) ) {
					$found[ $id ]['impressions'] = 0;
					$found[ $id ]['queries']     = array();
				}

				$found[ $id ]['impressions'] += (int) $row['impressions'];

				if ( count( $found[ $id ]['queries'] ) < 3 ) {
					$found[ $id ]['queries'][] = array( 'query' => $row['query'], 'position' => (float) $row['position'] );
				}
			}
		}

		// Already flagged together.
		if ( $post_id ) {
			$pairs = $wpdb->get_results( $wpdb->prepare( 'SELECT post_a, post_b, overlap, title FROM ' . HWCH_Store::table( 'pairs' ) . ' WHERE post_a = %d OR post_b = %d', $post_id, $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB

			foreach ( (array) $pairs as $pair ) {
				$other = (int) $pair['post_a'] === $post_id ? (int) $pair['post_b'] : (int) $pair['post_a'];

				if ( 'publish' === get_post_status( $other ) ) {
					$found[ $other ]['pair'] = (int) $pair['overlap'];
				}
			}
		}

		$out = array();

		foreach ( $found as $id => $why ) {
			$title_score = isset( $why['title'] ) ? $why['title'] : 0;
			$impressions = isset( $why['impressions'] ) ? $why['impressions'] : 0;
			$strong      = $title_score >= (int) HWCH_Settings::get( 'title_min' ) || $impressions >= 1000 || isset( $why['pair'] );

			$out[] = self::item( $id, $metrics ) + array(
				'titleScore'  => $title_score,
				'impressions' => $impressions,
				'queries'     => isset( $why['queries'] ) ? $why['queries'] : array(),
				'pair'        => isset( $why['pair'] ) ? $why['pair'] : null,
				'strong'      => $strong,
				'rank'        => ( $strong ? 1000000 : 0 ) + $impressions + $title_score * 100,
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $b['rank'] <=> $a['rank'];
			}
		);

		return array_slice( $out, 0, 6 );
	}

	/**
	 * Articles about the most specific destination this article shares with
	 * them: its city if any article has it, otherwise its country. A shared
	 * continent alone never counts. Most clicks first.
	 *
	 * @param int      $post_id This article.
	 * @param string[] $places  Its destinations.
	 * @return int[]
	 */
	private static function same_place( $post_id, $places ) {
		$by      = HWCH_Links::by_place();
		$metrics = HWCH_Overlap::metrics();
		$places  = HWCH_Overlap::with_parents( $places );

		for ( $level = 3; $level >= 2; $level-- ) {
			$found = array();

			foreach ( HWCH_Overlap::at_level( $places, $level ) as $place ) {
				foreach ( isset( $by[ $place ] ) ? $by[ $place ] : array() as $other ) {
					if ( $other !== $post_id ) {
						$found[ $other ] = isset( $metrics[ $other ] ) ? (int) $metrics[ $other ]['clicks'] : 0;
					}
				}
			}

			if ( $found ) {
				arsort( $found );
				return array_keys( $found );
			}
		}

		return array();
	}

	/**
	 * Articles about the same destination that could link to this one and do
	 * not yet.
	 *
	 * @param int      $post_id This article.
	 * @param string[] $places  Its destinations.
	 * @return array
	 */
	private static function link_from( $post_id, $places ) {
		if ( ! $places ) {
			return array();
		}

		$graph   = $post_id ? HWCH_Links::graph() : array();
		$already = array_flip( isset( $graph[ $post_id ]['sources'] ) ? $graph[ $post_id ]['sources'] : array() );
		$metrics = HWCH_Overlap::metrics();
		$out     = array();

		foreach ( self::same_place( $post_id, $places ) as $other ) {
			if ( ! isset( $already[ $other ] ) ) {
				$out[ $other ] = self::item( $other, $metrics );
			}

			if ( count( $out ) >= 5 ) {
				break;
			}
		}

		return array_values( $out );
	}

	/**
	 * What this article could link to: the destination hub, then its
	 * strongest articles, leaving out anything already linked in the content.
	 *
	 * @param int      $post_id This article.
	 * @param string[] $places  Its destinations.
	 * @param string   $content Current content.
	 * @return array
	 */
	public static function link_to( $post_id, $places, $content ) {
		if ( ! $places ) {
			return array();
		}

		$linked = array();

		if ( preg_match_all( '/<a\s[^>]*href\s*=\s*["\']([^"\']+)["\']/i', $content, $m ) ) {
			foreach ( $m[1] as $href ) {
				$href                                = html_entity_decode( $href );
				$href                                = 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ? HWCH_Broken::origin( $href ) : $href;
				$linked[ HWCH_GSC::normalise( $href ) ] = true;
			}
		}

		$metrics = HWCH_Overlap::metrics();
		$out     = array();

		// The hub of the article's most specific destination(s): the city hub
		// for a city article, the country hub for a country article.
		foreach ( HWCH_Overlap::specific( $places ) as $place ) {
			$hub = HWCH_Broken::hub_for( $place );

			if ( $hub && ! isset( $linked[ HWCH_GSC::normalise( $hub ) ] ) ) {
				$hub_id = HWCH_Store::post_id_for_url( $hub );
				$out[]  = array(
					'id'     => $hub_id,
					'title'  => $hub_id ? html_entity_decode( get_the_title( $hub_id ), ENT_QUOTES, 'UTF-8' ) : $hub,
					'edit'   => $hub_id ? get_edit_post_link( $hub_id, 'raw' ) : '',
					'url'    => $hub,
					'clicks' => 0,
					'hub'    => true,
				);
			}

		}

		$added = 0;

		foreach ( self::same_place( $post_id, $places ) as $other ) {
			if ( $other === $post_id || isset( $linked[ HWCH_GSC::normalise( get_permalink( $other ) ) ] ) ) {
				continue;
			}

			$out[] = self::item( $other, $metrics ) + array( 'hub' => false );

			if ( ++$added >= 3 ) {
				break;
			}
		}

		// Each page once.
		$seen = array();

		return array_values(
			array_filter(
				$out,
				function ( $item ) use ( &$seen ) {
					if ( isset( $seen[ $item['url'] ] ) ) {
						return false;
					}
					$seen[ $item['url'] ] = true;
					return true;
				}
			)
		);
	}
}
