<?php
/**
 * Overlapping articles: pairs of articles that compete for the same searches
 * or have near-identical titles, grouped for review.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds and groups overlapping articles.
 *
 * Two signals, each checked separately:
 * - Search overlap: how much of the smaller article's search impressions come
 *   from queries the other article also appears for. This is the real test of
 *   cannibalisation, because it shows Google treating them as alternatives.
 * - Title similarity: shared meaningful words in the titles, ignoring filler
 *   words, numbers and years. This catches duplicates that have too little
 *   traffic for the search signal to show.
 */
class HWCH_Overlap {

	/**
	 * Queries that more than this many articles appear for are too generic
	 * (e.g. "hostelworld blog") to say anything about two particular articles.
	 */
	const GENERIC_QUERY = 15;

	/**
	 * Words that carry no meaning for comparing titles.
	 *
	 * @var string[]
	 */
	private static $stopwords = array( 'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'how', 'in', 'is', 'it', 'its', 'of', 'on', 'or', 'our', 'that', 'the', 'this', 'to', 'vs', 'what', 'when', 'where', 'which', 'who', 'why', 'with', 'you', 'your', 'guide', 'ultimate', 'complete', 'best', 'top', 'tips' );

	/**
	 * Rebuilds the stored pairs. Called at the end of each sync.
	 *
	 * @param array $url_to_post Map of normalised URL => post ID, published articles only.
	 * @return true|WP_Error
	 */
	public static function build( $url_to_post ) {
		global $wpdb;

		$days  = (int) HWCH_Settings::get( 'overlap_days' );
		$end   = strtotime( '-3 days' );
		$rows  = HWCH_GSC::page_queries( gmdate( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days', $end ) ), gmdate( 'Y-m-d', $end ) );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		self::save_queries( $rows, $url_to_post );

		// Only English articles are compared, so translations of the same
		// article never count as duplicates of each other.
		$english     = array_flip( HWCH_Reports::english_ids( array_values( $url_to_post ) ) );
		$url_to_post = array_filter(
			$url_to_post,
			function ( $post_id ) use ( $english ) {
				return isset( $english[ $post_id ] );
			}
		);

		// Per query, the articles that appear for it; per article, its total
		// impressions across all stored queries.
		$by_query = array();
		$totals   = array();

		foreach ( $rows as $row ) {
			if ( empty( $url_to_post[ $row['url'] ] ) || $row['impressions'] < 1 ) {
				continue;
			}

			$post_id = $url_to_post[ $row['url'] ];

			if ( ! isset( $by_query[ $row['query'] ][ $post_id ] ) ) {
				$by_query[ $row['query'] ][ $post_id ] = array( 'impressions' => 0, 'position' => $row['position'] );
			}

			$by_query[ $row['query'] ][ $post_id ]['impressions'] += $row['impressions'];
			$totals[ $post_id ] = ( isset( $totals[ $post_id ] ) ? $totals[ $post_id ] : 0 ) + $row['impressions'];
		}

		// Shared impressions per pair, and the queries behind them.
		$pairs = array();

		foreach ( $by_query as $query => $posts ) {
			if ( count( $posts ) < 2 || count( $posts ) > self::GENERIC_QUERY ) {
				continue;
			}

			$ids = array_keys( $posts );
			sort( $ids );
			$n = count( $ids );

			for ( $i = 0; $i < $n; $i++ ) {
				for ( $j = $i + 1; $j < $n; $j++ ) {
					$a      = $ids[ $i ];
					$b      = $ids[ $j ];
					$key    = $a . '-' . $b;
					$shared = min( $posts[ $a ]['impressions'], $posts[ $b ]['impressions'] );

					if ( ! isset( $pairs[ $key ] ) ) {
						$pairs[ $key ] = array( 'a' => $a, 'b' => $b, 'shared' => 0, 'queries' => array() );
					}

					$pairs[ $key ]['shared']   += $shared;
					$pairs[ $key ]['queries'][] = array( $query, $posts[ $a ]['impressions'], $posts[ $a ]['position'], $posts[ $b ]['impressions'], $posts[ $b ]['position'] );
				}
			}
		}

		$min_overlap = (int) HWCH_Settings::get( 'overlap_min' ) / 100;
		$min_shared  = (int) HWCH_Settings::get( 'overlap_min_impr' );
		$min_title   = (int) HWCH_Settings::get( 'title_min' ) / 100;
		$keep        = array();

		foreach ( $pairs as $key => $pair ) {
			$smaller = min( $totals[ $pair['a'] ], $totals[ $pair['b'] ] );
			$overlap = $smaller ? $pair['shared'] / $smaller : 0;

			if ( $overlap < $min_overlap || $pair['shared'] < $min_shared || self::different_places( $pair['a'], $pair['b'] ) ) {
				continue;
			}

			// The five shared queries with the most combined impressions.
			usort(
				$pair['queries'],
				function ( $x, $y ) {
					return ( $y[1] + $y[3] ) <=> ( $x[1] + $x[3] );
				}
			);

			$keep[ $key ] = array(
				'a'       => $pair['a'],
				'b'       => $pair['b'],
				'overlap' => round( $overlap * 100 ),
				'shared'  => $pair['shared'],
				'title'   => 0,
				'queries' => array_slice( $pair['queries'], 0, 5 ),
			);
		}

		// Title similarity across all published articles, compared only when
		// they share at least one meaningful word.
		$titles = array();
		$index  = array();

		foreach ( array_unique( array_values( $url_to_post ) ) as $post_id ) {
			$tokens = self::tokens( get_post_field( 'post_title', $post_id, 'raw' ) );

			if ( count( $tokens ) < 2 ) {
				continue;
			}

			sort( $tokens );
			$titles[ $post_id ] = $tokens;

			foreach ( $tokens as $token ) {
				$index[ $token ][] = $post_id;
			}
		}

		foreach ( $index as $token => $ids ) {
			if ( count( $ids ) > 300 ) {
				continue; // A word in hundreds of titles cannot tell them apart.
			}

			$n = count( $ids );

			for ( $i = 0; $i < $n; $i++ ) {
				for ( $j = $i + 1; $j < $n; $j++ ) {
					$a      = min( $ids[ $i ], $ids[ $j ] );
					$b      = max( $ids[ $i ], $ids[ $j ] );
					$common = array_values( array_intersect( $titles[ $a ], $titles[ $b ] ) );

					// Each pair is compared once: under the first word they share.
					if ( ! $common || $common[0] !== $token ) {
						continue;
					}

					$key        = $a . '-' . $b;
					$similarity = self::similarity( $titles[ $a ], $titles[ $b ] );

					if ( isset( $keep[ $key ] ) ) {
						$keep[ $key ]['title'] = round( $similarity * 100 );
					} elseif ( $similarity >= $min_title && ! self::different_places( $a, $b ) ) {
						$keep[ $key ] = array(
							'a'       => $a,
							'b'       => $b,
							'overlap' => 0,
							'shared'  => 0,
							'title'   => round( $similarity * 100 ),
							'queries' => array(),
						);
					}
				}
			}
		}

		$table = HWCH_Store::table( 'pairs' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB

		foreach ( $keep as $pair ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'post_a'  => $pair['a'],
					'post_b'  => $pair['b'],
					'overlap' => $pair['overlap'],
					'shared'  => $pair['shared'],
					'title'   => $pair['title'],
					'queries' => wp_json_encode( $pair['queries'] ),
				),
				array( '%d', '%d', '%d', '%d', '%d', '%s' )
			);
		}

		return true;
	}

	/**
	 * Whether two articles are about different destinations, e.g. "Best bars
	 * in Porto" and "Best bars in Krakow". Those share a format, not an
	 * audience, so they are never flagged, however alike the titles are.
	 *
	 * Destinations come from the tags the destination hubs use (each hub's
	 * tag), matched against each article's tags and its title. When either
	 * article has no recognisable destination, the pair is left to the normal
	 * checks.
	 *
	 * @param int $a Post ID.
	 * @param int $b Post ID.
	 * @return bool
	 */
	public static function different_places( $a, $b ) {
		return self::places_differ( self::places( $a ), self::places( $b ) );
	}

	/**
	 * Whether two sets of destinations point to different places. They are
	 * compared at the most specific level both have: two city articles by
	 * city, a city article and a country article by country. A shared
	 * continent never makes two articles "the same place", so "Best bars in
	 * Porto" and "Best bars in Kraków" stay different.
	 *
	 * @param string[] $da Destinations of one article.
	 * @param string[] $db Destinations of the other.
	 * @return bool
	 */
	public static function places_differ( $da, $db ) {
		if ( ! $da || ! $db ) {
			return false;
		}

		$ea    = self::with_parents( $da );
		$eb    = self::with_parents( $db );
		$level = min( self::deepest( $da ), self::deepest( $db ) );
		$pa    = self::at_level( $ea, $level );
		$pb    = self::at_level( $eb, $level );

		return $pa && $pb && ! array_intersect( $pa, $pb );
	}

	/**
	 * The most specific of an article's destinations: its cities if it has
	 * any, otherwise its countries, otherwise its continents.
	 *
	 * @param string[] $places Destination slugs.
	 * @return string[]
	 */
	public static function specific( $places ) {
		return self::at_level( $places, self::deepest( $places ) );
	}

	/**
	 * Destinations plus the country and continent above each, from the hub
	 * pages' parent pages.
	 *
	 * @param string[] $places Destination slugs.
	 * @return string[]
	 */
	public static function with_parents( $places ) {
		$info = self::hierarchy();
		$out  = array();

		foreach ( $places as $slug ) {
			$guard = 0;

			while ( $slug && ! isset( $out[ $slug ] ) && $guard++ < 6 ) {
				$out[ $slug ] = true;
				$slug         = isset( $info[ $slug ] ) ? $info[ $slug ]['parent'] : '';
			}
		}

		return array_keys( $out );
	}

	/**
	 * A destination's level: 1 continent, 2 country, 3 city (or deeper).
	 *
	 * @param string $slug Destination slug.
	 * @return int
	 */
	public static function level( $slug ) {
		$info = self::hierarchy();
		return isset( $info[ $slug ] ) ? $info[ $slug ]['level'] : 1;
	}

	/**
	 * The deepest level among some destinations.
	 *
	 * @param string[] $places Destination slugs.
	 * @return int
	 */
	private static function deepest( $places ) {
		$max = 0;
		foreach ( $places as $slug ) {
			$max = max( $max, min( 3, self::level( $slug ) ) );
		}
		return $max;
	}

	/**
	 * The destinations at one level.
	 *
	 * @param string[] $places Destination slugs.
	 * @param int      $level  Level.
	 * @return string[]
	 */
	public static function at_level( $places, $level ) {
		return array_values(
			array_filter(
				$places,
				function ( $slug ) use ( $level ) {
					return min( 3, self::level( $slug ) ) === $level;
				}
			)
		);
	}

	/**
	 * Each destination's level and parent destination. The level comes from
	 * the hub pages' nesting (continent page > country page > city page); when
	 * a hub has no hub page above it, the meta field it uses decides
	 * (…city_hub_term is a city, …country_hub_term a country).
	 *
	 * @return array Map of slug => array( level, parent, hub ).
	 */
	public static function hierarchy() {
		global $wpdb;
		static $info = null;

		if ( null !== $info ) {
			return $info;
		}

		$info = array();
		$keys = array();

		foreach ( array( 'hw_hub_', '_hw_hub_' ) as $base ) {
			foreach ( array( '', 'country_', 'city_' ) as $template ) {
				$keys[] = $base . $template . 'hub_term';
			}
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_parent FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status IN ('publish','draft','pending','private','future')
			WHERE pm.meta_key IN ('" . implode( "','", array_map( 'esc_sql', $keys ) ) . "')", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		$by_page = array();
		$parents = array();

		foreach ( (array) $rows as $row ) {
			$slugs = preg_split( '/[+,|\s]+/', strtolower( (string) $row['meta_value'] ), -1, PREG_SPLIT_NO_EMPTY );
			$slug  = $slugs ? sanitize_title( $slugs[0] ) : '';

			if ( '' === $slug ) {
				continue;
			}

			$by_page[ (int) $row['post_id'] ] = $slug;
			$parents[ (int) $row['post_id'] ] = (int) $row['post_parent'];
			$key_level                        = false !== strpos( $row['meta_key'], 'city_' ) ? 3 : ( false !== strpos( $row['meta_key'], 'country_' ) ? 2 : 0 );

			if ( ! isset( $info[ $slug ] ) || $key_level > $info[ $slug ]['key_level'] ) {
				$info[ $slug ] = array( 'page' => (int) $row['post_id'], 'key_level' => $key_level, 'parent' => '', 'level' => 1 );
			}
		}

		// Every hub page carrying each destination (translations of a hub
		// carry the same destination, and are not always nested the same).
		$pages_of = array();
		foreach ( $by_page as $page_id => $slug ) {
			$pages_of[ $slug ][] = $page_id;
		}

		// 1. From the hub pages: walk up each page to the nearest hub page
		// above it, keeping the deepest result across a destination's pages.
		foreach ( $info as $slug => $data ) {
			$best_depth  = 0;
			$best_parent = '';

			foreach ( $pages_of[ $slug ] as $page_id ) {
				$page   = $parents[ $page_id ];
				$depth  = 0;
				$parent = '';
				$guard  = 0;

				while ( $page && $guard++ < 10 ) {
					if ( isset( $by_page[ $page ] ) && $by_page[ $page ] !== $slug ) {
						if ( ! $depth ) {
							$parent = $by_page[ $page ];
						}
						++$depth;
					}

					$page = isset( $parents[ $page ] ) ? $parents[ $page ] : (int) wp_get_post_parent_id( $page );
				}

				if ( $depth > $best_depth ) {
					$best_depth  = $depth;
					$best_parent = $parent;
				}
			}

			$info[ $slug ]['parent'] = $best_parent;
			$info[ $slug ]['level']  = $best_depth ? $best_depth + 1 : max( 1, $data['key_level'] );
		}

		// 2. From how articles are tagged: when at least 80% of the English
		// articles tagged B are also tagged A, and A is on more articles, A is
		// above B (Germany above Berlin, Europe above Germany). This works
		// however the hub pages are built. The deeper of the two answers wins.
		$counts = array();
		$pairs  = array();

		foreach ( HWCH_Tags::article_tags() as $slugs ) {
			$own = array_values( array_intersect( array_unique( $slugs ), array_keys( self::destinations() ) ) );

			foreach ( $own as $x ) {
				$counts[ $x ] = ( isset( $counts[ $x ] ) ? $counts[ $x ] : 0 ) + 1;

				foreach ( $own as $y ) {
					if ( $x !== $y ) {
						$pairs[ $x ][ $y ] = ( isset( $pairs[ $x ][ $y ] ) ? $pairs[ $x ][ $y ] : 0 ) + 1;
					}
				}
			}
		}

		$inferred = array();

		foreach ( $counts as $b => $n ) {
			$parent = '';

			foreach ( isset( $pairs[ $b ] ) ? $pairs[ $b ] : array() as $a => $together ) {
				// The closest qualifying tag above: the one on the fewest articles.
				if ( $counts[ $a ] > $n && $together / $n >= 0.8 && ( '' === $parent || $counts[ $a ] < $counts[ $parent ] ) ) {
					$parent = $a;
				}
			}

			$inferred[ $b ] = $parent;
		}

		foreach ( $inferred as $slug => $parent ) {
			$depth = 1;
			$seen  = array( $slug => true );
			$up    = $parent;

			while ( '' !== $up && ! isset( $seen[ $up ] ) && $depth < 6 ) {
				$seen[ $up ] = true;
				++$depth;
				$up = isset( $inferred[ $up ] ) ? $inferred[ $up ] : '';
			}

			if ( ! isset( $info[ $slug ] ) ) {
				$info[ $slug ] = array( 'page' => 0, 'key_level' => 0, 'parent' => '', 'level' => 1 );
			}

			if ( $depth > $info[ $slug ]['level'] ) {
				$info[ $slug ]['level']  = $depth;
				$info[ $slug ]['parent'] = $parent;
			}
		}

		return $info;
	}

	/**
	 * The destinations an article is about: destination tags on the article,
	 * plus destinations named in its title.
	 *
	 * @param int $post_id Post ID.
	 * @return string[] Destination tag slugs.
	 */
	public static function places( $post_id ) {
		static $cache = array();

		if ( isset( $cache[ $post_id ] ) ) {
			return $cache[ $post_id ];
		}

		$cache[ $post_id ] = self::places_for(
			get_post_field( 'post_title', $post_id, 'raw' ),
			(array) wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'slugs' ) )
		);

		return $cache[ $post_id ];
	}

	/**
	 * The destinations a title and set of tags point to. Used for saved
	 * articles, and for a draft whose title and tags are still being edited.
	 *
	 * @param string   $title Title.
	 * @param string[] $slugs Tag slugs.
	 * @return string[] Destination tag slugs.
	 */
	public static function places_for( $title, $slugs ) {
		$destinations = self::destinations();
		$found        = array();

		foreach ( (array) $slugs as $slug ) {
			if ( isset( $destinations[ $slug ] ) ) {
				$found[ $slug ] = true;
			}
		}

		$title = ' ' . implode( ' ', preg_split( '/[^a-z]+/', strtolower( remove_accents( (string) $title ) ), -1, PREG_SPLIT_NO_EMPTY ) ) . ' ';

		foreach ( $destinations as $slug => $names ) {
			foreach ( $names as $name ) {
				if ( false !== strpos( $title, ' ' . $name . ' ' ) ) {
					$found[ $slug ] = true;
					break;
				}
			}
		}

		return array_keys( $found );
	}

	/**
	 * Keeps the page-and-search rows from the sync, per article, so the
	 * editor check and Question coverage can use them. All languages are kept;
	 * the reports that use them decide which to show.
	 *
	 * @param array $rows        Rows from HWCH_GSC::page_queries().
	 * @param array $url_to_post Map of normalised URL => post ID.
	 * @return void
	 */
	private static function save_queries( $rows, $url_to_post ) {
		global $wpdb;

		$merged = array();

		// Several URL variants can belong to one article: add them together.
		foreach ( $rows as $row ) {
			if ( empty( $url_to_post[ $row['url'] ] ) || '' === $row['query'] ) {
				continue;
			}

			$key = $url_to_post[ $row['url'] ] . "\t" . $row['query'];

			if ( ! isset( $merged[ $key ] ) ) {
				$merged[ $key ] = array( 'post_id' => $url_to_post[ $row['url'] ], 'query' => $row['query'], 'clicks' => 0, 'impressions' => 0, 'pos_sum' => 0 );
			}

			$merged[ $key ]['clicks']      += $row['clicks'];
			$merged[ $key ]['impressions'] += $row['impressions'];
			$merged[ $key ]['pos_sum']     += $row['position'] * $row['impressions'];
		}

		$table = HWCH_Store::table( 'queries' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB

		foreach ( array_chunk( array_values( $merged ), 400 ) as $chunk ) {
			$values = array();

			foreach ( $chunk as $row ) {
				$values[] = $wpdb->prepare(
					'(%d, %s, %d, %d, %f)',
					$row['post_id'],
					mb_substr( $row['query'], 0, 255 ),
					$row['clicks'],
					$row['impressions'],
					$row['impressions'] ? round( $row['pos_sum'] / $row['impressions'], 1 ) : 0
				);
			}

			$wpdb->query( "INSERT INTO {$table} (post_id, query, clicks, impressions, position) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * An article's searches from the last sync, most clicks first.
	 *
	 * @param int $post_id Post ID.
	 * @param int $limit   How many.
	 * @return array List of array( query, clicks, impressions, position ).
	 */
	public static function queries_for( $post_id, $limit = 10 ) {
		global $wpdb;

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT query, clicks, impressions, position FROM ' . HWCH_Store::table( 'queries' ) . ' WHERE post_id = %d ORDER BY clicks DESC, impressions DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$post_id,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Every destination on the blog: the tag of each continent, country and
	 * city hub, with the ways its name can appear in a title (the tag name
	 * and the slug with spaces, both lower case and without accents).
	 *
	 * @return array Map of tag slug => list of names.
	 */
	public static function destinations() {
		global $wpdb;
		static $map = null;

		if ( null !== $map ) {
			return $map;
		}

		$map   = array();
		$keys  = array();
		$bases = array( 'hw_hub_', '_hw_hub_' );

		foreach ( $bases as $base ) {
			foreach ( array( '', 'country_', 'city_' ) as $template ) {
				$keys[] = $base . $template . 'hub_term';
			}
		}

		$values = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('" . implode( "','", array_map( 'esc_sql', $keys ) ) . "')" // phpcs:ignore WordPress.DB.PreparedSQL
		);

		foreach ( (array) $values as $value ) {
			foreach ( preg_split( '/[+,|\s]+/', strtolower( (string) $value ), -1, PREG_SPLIT_NO_EMPTY ) as $slug ) {
				$slug = sanitize_title( $slug );

				if ( '' === $slug ) {
					continue;
				}

				$names = array( str_replace( '-', ' ', $slug ) );
				$term  = get_term_by( 'slug', $slug, 'post_tag' );

				if ( $term && ! is_wp_error( $term ) ) {
					$names[] = trim( preg_replace( '/[^a-z]+/', ' ', strtolower( remove_accents( $term->name ) ) ) );
				}

				$map[ $slug ] = array_values( array_unique( array_filter( $names ) ) );
			}
		}

		return $map;
	}

	/**
	 * The meaningful words in a title.
	 *
	 * @param string $title Title.
	 * @return string[]
	 */
	public static function tokens( $title ) {
		$title = strtolower( remove_accents( wp_strip_all_tags( (string) $title ) ) );
		$words = preg_split( '/[^a-z]+/', $title, -1, PREG_SPLIT_NO_EMPTY );

		return array_values(
			array_unique(
				array_filter(
					$words,
					function ( $word ) {
						return strlen( $word ) > 1 && ! in_array( $word, self::$stopwords, true );
					}
				)
			)
		);
	}

	/**
	 * Share of words two titles have in common (Jaccard index).
	 *
	 * @param string[] $a Words.
	 * @param string[] $b Words.
	 * @return float 0 to 1.
	 */
	public static function similarity( $a, $b ) {
		$union = count( array_unique( array_merge( $a, $b ) ) );
		return $union ? count( array_intersect( $a, $b ) ) / $union : 0;
	}

	/**
	 * Groups of overlapping articles, for the report. Pairs that share an
	 * article join into one group (A-B and B-C make A, B, C). Articles that
	 * are no longer published, and pairs someone marked as fine, drop out.
	 *
	 * @return array List of groups: array( posts, pairs, score, label, clicks ).
	 */
	public static function groups() {
		global $wpdb;

		$pairs = $wpdb->get_results( 'SELECT * FROM ' . HWCH_Store::table( 'pairs' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$fine  = (array) get_option( 'hwch_fine_pairs', array() );

		$ids     = array_merge( wp_list_pluck( (array) $pairs, 'post_a' ), wp_list_pluck( (array) $pairs, 'post_b' ) );
		$english = array_flip( HWCH_Reports::english_ids( array_unique( $ids ) ) );

		$pairs = array_filter(
			(array) $pairs,
			function ( $pair ) use ( $fine, $english ) {
				$a = (int) $pair['post_a'];
				$b = (int) $pair['post_b'];

				return empty( $fine[ $a . '-' . $b ] )
					&& isset( $english[ $a ], $english[ $b ] )
					&& 'publish' === get_post_status( $a )
					&& 'publish' === get_post_status( $b )
					&& ! self::different_places( $a, $b );
			}
		);

		// Union-find.
		$parent = array();
		$find   = function ( $x ) use ( &$parent, &$find ) {
			if ( ! isset( $parent[ $x ] ) ) {
				$parent[ $x ] = $x;
			}
			if ( $parent[ $x ] !== $x ) {
				$parent[ $x ] = $find( $parent[ $x ] );
			}
			return $parent[ $x ];
		};

		foreach ( $pairs as $pair ) {
			$parent[ $find( (int) $pair['post_a'] ) ] = $find( (int) $pair['post_b'] );
		}

		$groups = array();

		foreach ( $pairs as $pair ) {
			$root = $find( (int) $pair['post_a'] );

			$pair['queries'] = (array) json_decode( (string) $pair['queries'], true );
			$groups[ $root ]['pairs'][] = $pair;
			$groups[ $root ]['posts'][ (int) $pair['post_a'] ] = true;
			$groups[ $root ]['posts'][ (int) $pair['post_b'] ] = true;
		}

		$metrics = self::metrics();
		$out     = array();

		foreach ( $groups as $group ) {
			$ids     = array_keys( $group['posts'] );
			$overlap = max( wp_list_pluck( $group['pairs'], 'overlap' ) );
			$title   = max( wp_list_pluck( $group['pairs'], 'title' ) );
			$score   = max( $overlap, $title );

			if ( $overlap >= 60 || $title >= 85 ) {
				$label = 'duplicate';
			} elseif ( $overlap >= 30 || $title >= 70 ) {
				$label = 'strong';
			} else {
				$label = 'related';
			}

			// Suggested keeper: most clicks, then most impressions.
			usort(
				$ids,
				function ( $x, $y ) use ( $metrics ) {
					$mx = isset( $metrics[ $x ] ) ? $metrics[ $x ] : array( 'clicks' => 0, 'impressions' => 0 );
					$my = isset( $metrics[ $y ] ) ? $metrics[ $y ] : array( 'clicks' => 0, 'impressions' => 0 );
					return array( $my['clicks'], $my['impressions'] ) <=> array( $mx['clicks'], $mx['impressions'] );
				}
			);

			$clicks = 0;
			foreach ( $ids as $id ) {
				$clicks += isset( $metrics[ $id ] ) ? (int) $metrics[ $id ]['clicks'] : 0;
			}

			$out[] = array(
				'posts'  => $ids,
				'pairs'  => $group['pairs'],
				'score'  => $score,
				'label'  => $label,
				'clicks' => $clicks,
			);
		}

		// Strongest first, then the most traffic at stake.
		usort(
			$out,
			function ( $x, $y ) {
				return array( $y['score'], $y['clicks'] ) <=> array( $x['score'], $x['clicks'] );
			}
		);

		return $out;
	}

	/**
	 * Clicks, impressions and position per article, from the last sync.
	 *
	 * @return array Map of post ID => row.
	 */
	public static function metrics() {
		global $wpdb;

		$rows = $wpdb->get_results( 'SELECT post_id, clicks, impressions, position FROM ' . HWCH_Store::table( 'metrics' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$map  = array();

		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['post_id'] ] = $row;
		}

		return $map;
	}

	/**
	 * The SEO title field in use: Yoast, Rank Math, or none.
	 *
	 * @return string Meta key, or '' when no SEO plugin is active.
	 */
	public static function seo_title_key() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return '_yoast_wpseo_title';
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rank_math_title';
		}

		return '';
	}
}
