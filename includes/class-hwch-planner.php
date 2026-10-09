<?php
/**
 * Content planner: where the blog has search demand but no article, which
 * destinations need articles, and a plan to work through them.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds content gaps in the searches the English blog already appears for,
 * and keeps the content plan.
 *
 * Search Console only reports searches the blog already appears for, so a
 * gap here always has real impressions behind it, but demand for a
 * destination or topic the blog has never appeared for is invisible. That
 * part needs keyword data from outside Search Console (such as Ahrefs).
 */
class HWCH_Planner {

	/**
	 * Place names that are also ordinary English words, so they only count
	 * when the country or region above them is in the search too ("nice
	 * france", not "nice hostels").
	 *
	 * @var string[]
	 */
	private static $ambiguous = array( 'nice', 'split', 'bath', 'reading', 'male', 'bar', 'hue', 'ios', 'orange', 'mobile', 'sale', 'deal', 'como', 'bergen op zoom', 'florida keys', 'victoria', 'jersey', 'cork', 'georgia', 'turkey', 'chile', 'jordan', 'china', 'wales', 'york' );

	/**
	 * The travel topics, in the order they are checked (the first match wins,
	 * so "christmas markets" is an event, not food), with the words that
	 * identify them and a working title.
	 *
	 * @return array Map of key => array( label, words, title ).
	 */
	public static function topics() {
		return array(
			'events'         => array( __( 'Festivals & events', 'hw-content-health' ), array( 'festival', 'festivals', 'carnival', 'christmas market', 'christmas markets', 'christmas', 'new year', 'new years', 'nye', 'events', 'oktoberfest', 'pride', 'concerts' ), 'Festivals and Events in %s' ),
			'where-to-stay'  => array( __( 'Where to stay', 'hw-content-health' ), array( 'where to stay', 'best area', 'best areas', 'area to stay', 'areas to stay', 'neighbourhood', 'neighbourhoods', 'neighborhood', 'neighborhoods', 'which area', 'place to stay', 'places to stay' ), 'Where to Stay in %s' ),
			'hostels'        => array( __( 'Hostels', 'hw-content-health' ), array( 'hostel', 'hostels' ), 'Best Hostels in %s' ),
			'itinerary'      => array( __( 'Itinerary & how long', 'hw-content-health' ), array( 'itinerary', 'how many days', 'how long', 'days in', 'day in', 'weekend in', 'weekend', '24 hours', '48 hours', 'one day', 'two days', 'three days', '1 day', '2 days', '3 days', '4 days', '5 days', '7 days', 'week in', 'one week' ), 'How Many Days in %s: Itinerary' ),
			'best-time'      => array( __( 'Best time & weather', 'hw-content-health' ), array( 'best time', 'when to visit', 'when to go', 'weather', 'climate', 'rainy season', 'in winter', 'in summer', 'in spring', 'in autumn', 'in fall', 'in january', 'in february', 'in march', 'in april', 'in may', 'in june', 'in july', 'in august', 'in september', 'in october', 'in november', 'in december' ), 'Best Time to Visit %s' ),
			'safety'         => array( __( 'Safety', 'hw-content-health' ), array( 'safe', 'safety', 'dangerous', 'danger', 'scams', 'scam', 'crime' ), 'Is %s Safe?' ),
			'budget'         => array( __( 'Budget & costs', 'hw-content-health' ), array( 'budget', 'cheap', 'cost', 'costs', 'expensive', 'prices', 'price', 'free things', 'for free', 'shoestring' ), '%s on a Budget' ),
			'getting-around' => array( __( 'Getting there & around', 'hw-content-health' ), array( 'getting around', 'get around', 'transport', 'transportation', 'metro', 'subway', 'bus', 'buses', 'train', 'trains', 'tram', 'airport', 'ferry', 'how to get', 'get to', 'get from', 'taxi', 'uber', 'car rental', 'rent a car', 'travel card', 'transit' ), 'Getting Around %s' ),
			'day-trips'      => array( __( 'Day trips', 'hw-content-health' ), array( 'day trip', 'day trips', 'excursion', 'excursions', 'near', 'nearby' ), 'Best Day Trips from %s' ),
			'nightlife'      => array( __( 'Nightlife', 'hw-content-health' ), array( 'nightlife', 'night life', 'bars', 'clubs', 'club', 'clubbing', 'party', 'pub', 'pubs', 'pub crawl', 'rooftop', 'cocktail' ), '%s Nightlife Guide' ),
			'food'           => array( __( 'Food & drink', 'hw-content-health' ), array( 'food', 'eat', 'eating', 'restaurants', 'restaurant', 'street food', 'dishes', 'dish', 'cuisine', 'where to eat', 'brunch', 'cafes', 'cafe', 'coffee', 'food market', 'vegan', 'vegetarian', 'wine', 'beer' ), 'What to Eat in %s' ),
			'things-to-do'   => array( __( 'Things to do', 'hw-content-health' ), array( 'things to do', 'what to do', 'attractions', 'activities', 'must see', 'sightseeing', 'places to visit', 'what to see', 'to see in', 'bucket list', 'hidden gems', 'tourist' ), 'Best Things to Do in %s' ),
			'nature'         => array( __( 'Beaches & nature', 'hw-content-health' ), array( 'beach', 'beaches', 'island', 'islands', 'hike', 'hikes', 'hiking', 'trek', 'trekking', 'trail', 'trails', 'national park', 'lake', 'lakes', 'mountains', 'waterfall', 'waterfalls', 'surf', 'surfing', 'diving', 'snorkelling', 'snorkeling', 'ski', 'skiing' ), 'Best Beaches and Nature in %s' ),
			'culture'        => array( __( 'Culture & history', 'hw-content-health' ), array( 'history', 'museum', 'museums', 'culture', 'architecture', 'art', 'churches', 'church', 'temples', 'temple', 'castle', 'castles', 'palace', 'ruins' ), 'Culture and History in %s' ),
			'solo'           => array( __( 'Solo travel', 'hw-content-health' ), array( 'solo', 'alone', 'single' ), 'Solo Travel in %s' ),
			'practical'      => array( __( 'Practical tips', 'hw-content-health' ), array( 'visa', 'currency', 'tipping', 'sim card', 'esim', 'plug', 'adapter', 'tap water', 'what to pack', 'packing', 'travel tips', 'tips', 'etiquette', 'dress code', 'language', 'phrases', 'atm', 'cash' ), '%s Travel Tips' ),
			'backpacking'    => array( __( 'Backpacking & routes', 'hw-content-health' ), array( 'backpacking', 'backpacker', 'backpackers', 'route', 'routes', 'road trip', 'travel guide', 'guide', 'interrail', 'interrailing', 'gap year' ), 'Backpacking %s' ),
		);
	}

	/**
	 * Fetches the searches the English blog appears for and stores them.
	 * Called by the sync.
	 *
	 * @return true|WP_Error
	 */
	public static function build() {
		global $wpdb;

		$end    = gmdate( 'Y-m-d', strtotime( '-3 days' ) );
		$start  = gmdate( 'Y-m-d', strtotime( $end . ' -' . ( (int) HWCH_Settings::get( 'overlap_days' ) - 1 ) . ' days' ) );
		$prefix = untrailingslashit( (string) HWCH_Settings::get( 'url_prefix' ) );

		// English blog pages only: inside the blog address, and outside every
		// other language's folder.
		$filters = array( array( 'dimension' => 'page', 'operator' => 'contains', 'expression' => $prefix ) );
		$codes   = HWCH_Lang::other_codes();

		if ( ! $codes ) {
			$codes = array_values( array_unique( HWCH_Lang::folders() ) );
		}

		if ( $codes ) {
			$path      = untrailingslashit( (string) wp_parse_url( $prefix, PHP_URL_PATH ) );
			$filters[] = array( 'dimension' => 'page', 'operator' => 'excludingRegex', 'expression' => preg_quote( $path, '' ) . '/(' . implode( '|', array_map( 'preg_quote', $codes ) ) . ')/' );
		}

		$rows = HWCH_GSC::query( array( 'query' ), $start, $end, array( 'filters' => $filters, 'max' => 50000 ) );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$table = HWCH_Store::table( 'demand' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB

		$values = array();

		foreach ( $rows as $row ) {
			if ( empty( $row['keys'][0] ) ) {
				continue;
			}

			$values[] = $wpdb->prepare( '(%s, %d, %d, %f)', mb_substr( (string) $row['keys'][0], 0, 255 ), (int) $row['clicks'], (int) $row['impressions'], round( (float) $row['position'], 1 ) );
		}

		foreach ( array_chunk( $values, 400 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$table} (query, clicks, impressions, position) VALUES " . implode( ',', $chunk ) ); // phpcs:ignore WordPress.DB
		}

		update_option( 'hwch_demand_range', array( 'start' => $start, 'end' => $end ), false );
		delete_transient( 'hwch_planner' );

		return true;
	}

	/**
	 * Lowercase words of a text, accents removed.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function words( $text ) {
		return preg_split( '/[^a-z0-9]+/', strtolower( remove_accents( (string) $text ) ), -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * Destination names indexed by their first word, longest first.
	 *
	 * @return array Map of first word => list of array( words, slug, name ).
	 */
	private static function name_index() {
		static $index = null;

		if ( null !== $index ) {
			return $index;
		}

		$index = array();

		foreach ( HWCH_Overlap::destinations() as $slug => $names ) {
			foreach ( $names as $name ) {
				$words = self::words( $name );

				if ( $words ) {
					$index[ $words[0] ][] = array( $words, $slug, implode( ' ', $words ) );
				}
			}
		}

		foreach ( $index as $first => $list ) {
			usort(
				$list,
				function ( $a, $b ) {
					return count( $b[0] ) <=> count( $a[0] );
				}
			);
			$index[ $first ] = $list;
		}

		return $index;
	}

	/**
	 * The destinations named in a search, and the words they took up.
	 *
	 * @param string[] $words Search words.
	 * @return array slugs, used (word positions).
	 */
	public static function places_in( $words ) {
		$index = self::name_index();
		$found = array();
		$used  = array();
		$names = array();
		$count = count( $words );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! isset( $index[ $words[ $i ] ] ) ) {
				continue;
			}

			$matched = 0;

			foreach ( $index[ $words[ $i ] ] as $candidate ) {
				$len = count( $candidate[0] );

				// Longest name first; every destination sharing that name counts
				// (two tags can carry the same name).
				if ( $matched && $len < $matched ) {
					break;
				}

				if ( array_slice( $words, $i, $len ) === $candidate[0] ) {
					$found[ $candidate[1] ] = true;
					$names[ $candidate[1] ] = $candidate[2];
					$matched                = $len;
				}
			}

			if ( $matched ) {
				for ( $j = $i; $j < $i + $matched; $j++ ) {
					$used[ $j ] = true;
				}

				$i += $matched - 1;
			}
		}

		// An ambiguous name only counts with the place above it in the search.
		$info = HWCH_Overlap::hierarchy();

		foreach ( array_keys( $found ) as $slug ) {
			if ( in_array( $names[ $slug ], self::$ambiguous, true ) ) {
				$parent = isset( $info[ $slug ]['parent'] ) ? $info[ $slug ]['parent'] : '';

				if ( ! $parent || ! isset( $found[ $parent ] ) ) {
					unset( $found[ $slug ] );
				}
			}
		}

		return array( 'slugs' => array_keys( $found ), 'used' => $used );
	}

	/**
	 * The topic a search is about, if it matches one.
	 *
	 * @param string $padded Search words, joined and padded with spaces.
	 * @return string Topic key, or ''.
	 */
	public static function topic_of( $padded ) {
		foreach ( self::topics() as $key => $topic ) {
			foreach ( $topic[1] as $phrase ) {
				if ( false !== strpos( $padded, ' ' . $phrase . ' ' ) ) {
					return $key;
				}
			}
		}

		return '';
	}

	/**
	 * Every English article's destinations and title, for checking coverage.
	 *
	 * @return array articles (id => array( padded title, title words )), by_place (slug => ids).
	 */
	private static function articles() {
		global $wpdb;

		$rows     = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$english  = array_flip( HWCH_Reports::english_ids( wp_list_pluck( (array) $rows, 'ID' ) ) );
		$articles = array();
		$by_place = array();

		foreach ( (array) $rows as $row ) {
			$id = (int) $row['ID'];

			if ( ! isset( $english[ $id ] ) ) {
				continue;
			}

			$words           = self::words( $row['post_title'] );
			$articles[ $id ] = array( ' ' . implode( ' ', $words ) . ' ', HWCH_Overlap::tokens( $row['post_title'] ) );

			foreach ( HWCH_Overlap::places( $id ) as $slug ) {
				$by_place[ $slug ][] = $id;
			}
		}

		return array( 'articles' => $articles, 'by_place' => $by_place );
	}

	/**
	 * Whether an article title covers a topic or a group of words.
	 *
	 * @param array  $article array( padded title, title words ).
	 * @param string $key     'topic:x' or 'other:words'.
	 * @return bool
	 */
	private static function covers( $article, $key ) {
		list( $kind, $what ) = explode( ':', $key, 2 );

		if ( 'topic' === $kind ) {
			$topics = self::topics();

			foreach ( $topics[ $what ][1] as $phrase ) {
				if ( false !== strpos( $article[0], ' ' . $phrase . ' ' ) ) {
					return true;
				}
			}

			return false;
		}

		$want = explode( ' ', $what );

		return count( array_intersect( $want, $article[1] ) ) >= ceil( count( $want ) * 2 / 3 );
	}

	/**
	 * The analysis behind the planner: demand per destination, gaps per
	 * destination and topic, and searches with no destination and no
	 * article. Cached for 12 hours, and refreshed by each sync.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array dests, gaps, general, searches.
	 */
	public static function analyse( $refresh = false ) {
		global $wpdb;

		$cached = $refresh ? false : get_transient( 'hwch_planner' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$rows    = $wpdb->get_results( 'SELECT query, clicks, impressions, position FROM ' . HWCH_Store::table( 'demand' ) . ' WHERE impressions >= 5', ARRAY_A ); // phpcs:ignore WordPress.DB
		$dests   = array();
		$cells   = array();
		$general = array();

		foreach ( (array) $rows as $row ) {
			$query = (string) $row['query'];

			if ( false !== strpos( $query, 'hostelworld' ) ) {
				continue; // Searches for the brand are not content gaps.
			}

			$words  = self::words( $query );
			$padded = ' ' . implode( ' ', $words ) . ' ';
			$found  = self::places_in( $words );
			$impr   = (int) $row['impressions'];
			$pos    = (float) $row['position'];
			$topic  = self::topic_of( $padded );
			$dest   = '';
			$best   = 0;

			foreach ( $found['slugs'] as $slug ) {
				$level = min( 3, HWCH_Overlap::level( $slug ) );

				if ( $level > $best ) {
					$best = $level;
					$dest = $slug;
				}
			}

			// Words left once the destination is set aside.
			$rest = array();
			foreach ( $words as $i => $word ) {
				if ( ! isset( $found['used'][ $i ] ) ) {
					$rest[] = $word;
				}
			}
			$rest = HWCH_Overlap::tokens( implode( ' ', $rest ) );

			if ( $topic ) {
				$key = 'topic:' . $topic;
			} elseif ( $rest ) {
				$rest = array_slice( array_values( array_unique( $rest ) ), 0, 4 );
				sort( $rest );
				$key = 'other:' . implode( ' ', $rest );
			} else {
				$key = '';
			}

			if ( $dest ) {
				if ( ! isset( $dests[ $dest ] ) ) {
					$dests[ $dest ] = array( 'impressions' => 0, 'clicks' => 0, 'posw' => 0, 'low' => 0 );
				}

				$dests[ $dest ]['impressions'] += $impr;
				$dests[ $dest ]['clicks']      += (int) $row['clicks'];
				$dests[ $dest ]['posw']        += $pos * $impr;
				$dests[ $dest ]['low']         += $pos > 10 ? $impr : 0;

				// A search for just the destination is demand, not a topic.
				if ( ! $key ) {
					continue;
				}

				$bucket = &$cells[ $dest ][ $key ];
			} else {
				if ( ! $key ) {
					continue;
				}

				// No destination: group by all its meaningful words.
				$all = array_slice( array_values( array_unique( HWCH_Overlap::tokens( $query ) ) ), 0, 4 );

				if ( count( $all ) < 2 ) {
					continue;
				}

				sort( $all );
				$bucket = &$general[ 'other:' . implode( ' ', $all ) ];
			}

			if ( ! $bucket ) {
				$bucket = array( 'impressions' => 0, 'clicks' => 0, 'posw' => 0, 'examples' => array() );
			}

			$bucket['impressions'] += $impr;
			$bucket['clicks']      += (int) $row['clicks'];
			$bucket['posw']        += $pos * $impr;
			$bucket['examples'][]   = array( $query, $impr, $pos );

			if ( count( $bucket['examples'] ) > 3 ) {
				usort(
					$bucket['examples'],
					function ( $a, $b ) {
						return $b[1] <=> $a[1];
					}
				);
				array_pop( $bucket['examples'] );
			}

			unset( $bucket );
		}

		$by_impr = function ( &$bucket ) {
			usort(
				$bucket['examples'],
				function ( $a, $b ) {
					return $b[1] <=> $a[1];
				}
			);
		};

		foreach ( $cells as $slug => $keys ) {
			foreach ( array_keys( $keys ) as $key ) {
				$by_impr( $cells[ $slug ][ $key ] );
			}
		}

		foreach ( array_keys( $general ) as $key ) {
			$by_impr( $general[ $key ] );
		}

		$index = self::articles();
		$gaps  = array();

		foreach ( $cells as $slug => $keys ) {
			$here = isset( $index['by_place'][ $slug ] ) ? $index['by_place'][ $slug ] : array();

			foreach ( $keys as $key => $cell ) {
				if ( $cell['impressions'] < 10 ) {
					continue;
				}

				$covering = array();

				foreach ( $here as $id ) {
					if ( self::covers( $index['articles'][ $id ], $key ) ) {
						$covering[] = $id;
					}
				}

				$position = $cell['impressions'] ? $cell['posw'] / $cell['impressions'] : 0;

				// Covered and ranking: not a gap. Covered but on page 3 or lower: weak.
				if ( $covering && $position <= 20 ) {
					continue;
				}

				$gaps[] = array(
					'dest'        => $slug,
					'key'         => $key,
					'impressions' => $cell['impressions'],
					'clicks'      => $cell['clicks'],
					'position'    => round( $position, 1 ),
					'examples'    => $cell['examples'],
					'status'      => $covering ? 'weak' : 'gap',
					'covering'    => array_slice( $covering, 0, 3 ),
				);
			}
		}

		$others = array();

		foreach ( $general as $key => $cell ) {
			$position = $cell['impressions'] ? $cell['posw'] / $cell['impressions'] : 0;

			if ( $cell['impressions'] < 10 || $position <= 10 ) {
				continue;
			}

			$covered = false;

			foreach ( $index['articles'] as $article ) {
				if ( self::covers( $article, $key ) ) {
					$covered = true;
					break;
				}
			}

			if ( ! $covered ) {
				$others[] = array(
					'key'         => $key,
					'impressions' => $cell['impressions'],
					'clicks'      => $cell['clicks'],
					'position'    => round( $position, 1 ),
					'examples'    => $cell['examples'],
				);
			}
		}

		$sort = function ( $a, $b ) {
			return $b['impressions'] <=> $a['impressions'];
		};
		usort( $gaps, $sort );
		usort( $others, $sort );

		foreach ( $dests as $slug => $data ) {
			$dests[ $slug ]['articles'] = isset( $index['by_place'][ $slug ] ) ? count( $index['by_place'][ $slug ] ) : 0;
		}

		// Destinations with a hub but no searches yet still belong in the list.
		foreach ( array_keys( HWCH_Overlap::destinations() ) as $slug ) {
			if ( ! isset( $dests[ $slug ] ) ) {
				$dests[ $slug ] = array( 'impressions' => 0, 'clicks' => 0, 'posw' => 0, 'low' => 0, 'articles' => isset( $index['by_place'][ $slug ] ) ? count( $index['by_place'][ $slug ] ) : 0 );
			}
		}

		$out = array(
			'dests'    => $dests,
			'gaps'     => $gaps,
			'general'  => array_slice( $others, 0, 500 ),
			'searches' => count( (array) $rows ),
		);

		if ( $rows ) {
			set_transient( 'hwch_planner', $out, 12 * HOUR_IN_SECONDS );
		}

		return $out;
	}

	/**
	 * A gap's topic in words.
	 *
	 * @param string $key 'topic:x' or 'other:words'.
	 * @return string
	 */
	public static function label( $key, $examples = array() ) {
		if ( false === strpos( $key, ':' ) ) {
			return 'refresh' === $key ? __( 'Refresh', 'hw-content-health' ) : ucfirst( $key );
		}

		list( $kind, $what ) = explode( ':', $key, 2 );
		$topics              = self::topics();

		if ( 'topic' === $kind && isset( $topics[ $what ] ) ) {
			return $topics[ $what ][0];
		}

		// A group of words reads best as its biggest search.
		return $examples ? '“' . $examples[0][0] . '”' : ucfirst( $what );
	}

	/**
	 * A plain working title for a gap, to be rewritten by the writer.
	 *
	 * @param string $dest Destination slug.
	 * @param string $key  Topic key.
	 * @return string
	 */
	public static function working_title( $dest, $key ) {
		$name                = self::dest_name( $dest );
		list( $kind, $what ) = explode( ':', $key, 2 );
		$topics              = self::topics();

		if ( 'topic' === $kind && isset( $topics[ $what ] ) ) {
			return sprintf( $topics[ $what ][2], $name );
		}

		$gap = self::find_gap( $dest, $key );

		return $gap ? ucfirst( $gap['examples'][0][0] ) : ( $name ? $name . ': ' . ucwords( $what ) : ucfirst( $what ) );
	}

	/**
	 * A destination's name.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function dest_name( $slug ) {
		if ( '' === $slug ) {
			return '';
		}

		$term = get_term_by( 'slug', $slug, 'post_tag' );

		return $term && ! is_wp_error( $term ) ? $term->name : ucwords( str_replace( '-', ' ', $slug ) );
	}

	/**
	 * A destination's level in words.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private static function level_name( $slug ) {
		$names = array( 1 => __( 'Continent', 'hw-content-health' ), 2 => __( 'Country', 'hw-content-health' ), 3 => __( 'City', 'hw-content-health' ) );

		return $names[ min( 3, HWCH_Overlap::level( $slug ) ) ];
	}

	/**
	 * A destination's hub page and its status.
	 *
	 * @param string $slug Slug.
	 * @return array status ('publish', 'draft', 'none'), edit link.
	 */
	private static function hub( $slug ) {
		$info = HWCH_Overlap::hierarchy();
		$page = isset( $info[ $slug ]['page'] ) ? (int) $info[ $slug ]['page'] : 0;

		if ( ! $page ) {
			return array( 'status' => 'none', 'edit' => '' );
		}

		$status = get_post_status( $page );

		return array( 'status' => 'publish' === $status ? 'publish' : 'draft', 'edit' => (string) get_edit_post_link( $page ) );
	}

	/**
	 * When a destination peaks, and when to publish to be ready for it: the
	 * refresh window's lead time before the season starts. Only for
	 * destinations with a clear season.
	 *
	 * @return array Map of slug => array( peak, start, publish_by ).
	 */
	public static function seasons() {
		static $out = null;

		if ( null !== $out ) {
			return $out;
		}

		$out  = array();
		$lead = (int) HWCH_Settings::get( 'season_lead_max' ) * WEEK_IN_SECONDS;

		foreach ( HWCH_Seasonal::destinations() as $row ) {
			if ( $row['strength'] < (int) HWCH_Settings::get( 'season_strength' ) / 100 ) {
				continue;
			}

			// Walk back from the peak while months stay well above average.
			$start = $row['peak'];

			for ( $i = 1; $i < 12; $i++ ) {
				$prev = ( ( $start - 2 ) % 12 + 12 ) % 12 + 1;

				if ( $row['average'][ $prev ] < $row['mean'] * HWCH_Seasonal::SEASON_RATIO ) {
					break;
				}

				$start = $prev;
			}

			$year  = (int) gmdate( 'Y' );
			$first = gmmktime( 0, 0, 0, $start, 1, $year );

			// The next season that can still be prepared for.
			while ( $first - $lead < time() ) {
				$first = gmmktime( 0, 0, 0, $start, 1, ++$year );
			}

			$out[ $row['slug'] ] = array(
				'peak'       => $row['peak'],
				'start'      => $first,
				'publish_by' => $first - $lead,
			);
		}

		return $out;
	}

	/**
	 * The plan, newest first, with statuses kept in step with their drafts.
	 *
	 * @return array
	 */
	public static function plan() {
		global $wpdb;

		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . HWCH_Store::table( 'plan' ) . ' ORDER BY (due IS NULL), due, id DESC', ARRAY_A ); // phpcs:ignore WordPress.DB

		foreach ( $rows as $i => $row ) {
			if ( 'refresh' === $row['topic'] ) {
				// A refresh is done once the article is updated after it was planned.
				if ( strtotime( get_post_field( 'post_modified_gmt', (int) $row['post_id'] ) . ' UTC' ) > strtotime( $row['created_at'] . ' UTC' ) + HOUR_IN_SECONDS ) {
					$rows[ $i ]['status'] = 'published';
				}
				continue;
			}

			if ( $row['post_id'] ) {
				$status = get_post_status( (int) $row['post_id'] );

				if ( 'publish' === $status ) {
					$rows[ $i ]['status'] = 'published';
				} elseif ( $status && in_array( $row['status'], array( 'idea', 'briefed' ), true ) ) {
					$rows[ $i ]['status'] = 'writing';
				}
			}
		}

		return $rows;
	}

	/**
	 * Plan statuses.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'idea'      => __( 'Idea', 'hw-content-health' ),
			'briefed'   => __( 'Briefed', 'hw-content-health' ),
			'writing'   => __( 'Writing', 'hw-content-health' ),
			'published' => __( 'Published', 'hw-content-health' ),
		);
	}

	/**
	 * Gaps already in the plan, by destination and topic.
	 *
	 * @return array Set of "dest|key".
	 */
	private static function planned() {
		$set = array();

		foreach ( self::plan() as $row ) {
			if ( $row['topic'] ) {
				$set[ $row['destination'] . '|' . $row['topic'] ] = (int) $row['id'];
			}
		}

		return $set;
	}

	/**
	 * Whether a gap was marked "Not a gap" and is still hidden.
	 *
	 * @param string $dest Destination.
	 * @param string $key  Topic key.
	 * @return bool
	 */
	private static function dismissed( $dest, $key ) {
		$map = (array) get_option( 'hwch_gap_dismissed', array() );
		$id  = md5( $dest . '|' . $key );

		return isset( $map[ $id ] ) && $map[ $id ] > time();
	}

	/**
	 * One gap, found again by destination and topic (for adding to the plan).
	 *
	 * @param string $dest Destination.
	 * @param string $key  Topic key.
	 * @return array|null
	 */
	public static function find_gap( $dest, $key ) {
		$data = self::analyse();
		$list = '' === $dest ? $data['general'] : $data['gaps'];

		foreach ( $list as $gap ) {
			if ( $gap['key'] === $key && ( '' === $dest || $gap['dest'] === $dest ) ) {
				return $gap;
			}
		}

		return null;
	}

	/**
	 * Renders the tab.
	 *
	 * @return void
	 */
	public static function render() {
		global $wpdb;

		// phpcs:disable WordPress.Security.NonceVerification
		$view    = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'gaps';
		$view    = in_array( $view, array( 'gaps', 'dests', 'other', 'plan' ), true ) ? $view : 'gaps';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$refresh = isset( $_GET['refresh'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['refresh'] ) ), 'hwch_refresh' );
		// phpcs:enable

		$base   = admin_url( 'admin.php?page=hwch&tab=planner' );
		$labels = array(
			'gaps'  => __( 'Gaps', 'hw-content-health' ),
			'dests' => __( 'Destinations', 'hw-content-health' ),
			'other' => __( 'Searches with no destination', 'hw-content-health' ),
			/* translators: %s: number of items */
			'plan'  => sprintf( __( 'Plan (%s)', 'hw-content-health' ), number_format_i18n( count( array_filter( self::plan(), function ( $row ) { return 'published' !== $row['status']; } ) ) ) ),
		);
		?>
		<ul class="subsubsub hwch-filter">
			<?php $i = 0; ?>
			<?php foreach ( $labels as $key => $label ) : ?>
				<li><a href="<?php echo esc_url( add_query_arg( 'view', $key, $base ) ); ?>" class="<?php echo $view === $key ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a><?php echo ++$i < count( $labels ) ? ' |' : ''; ?></li>
			<?php endforeach; ?>
		</ul>
		<div class="clear"></div>
		<?php
		if ( 'plan' === $view ) {
			self::view_plan();
			return;
		}

		$stored = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . HWCH_Store::table( 'demand' ) ); // phpcs:ignore WordPress.DB

		if ( ! $stored ) {
			echo '<p class="hwch-empty">' . esc_html__( 'No searches are stored for the planner yet. They are saved by the sync: click Sync now at the top. If the sync fails, its error appears at the top of this page.', 'hw-content-health' ) . '</p>';
			return;
		}

		$data  = self::analyse( $refresh );
		$range = get_option( 'hwch_demand_range' );
		?>
		<p class="hwch-intro">
			<?php
			printf(
				/* translators: 1: number of searches, 2: start date, 3: end date */
				esc_html__( 'Built from %1$s searches the English blog appeared for between %2$s and %3$s. Each search is matched to the most specific destination it names and to a travel topic. A gap is a destination and topic with impressions but no English article about that destination whose title covers the topic. Search Console only reports searches the blog already appears for, so destinations and topics the blog has never appeared for are not here: that needs keyword data from outside Search Console, such as Ahrefs.', 'hw-content-health' ),
				esc_html( number_format_i18n( $data['searches'] ) ),
				esc_html( is_array( $range ) ? HWCH_Dashboard::date( $range['start'] ) : '' ),
				esc_html( is_array( $range ) ? HWCH_Dashboard::date( $range['end'] ) : '' )
			);
			?>
			<a href="<?php echo esc_url( add_query_arg( 'refresh', wp_create_nonce( 'hwch_refresh' ) ) ); ?>"><?php esc_html_e( 'Recalculate now', 'hw-content-health' ); ?></a>
		</p>
		<?php
		if ( 'dests' === $view ) {
			self::view_dests( $data, $paged );
		} elseif ( 'other' === $view ) {
			self::view_other( $data, $paged );
		} else {
			self::view_gaps( $data, $paged );
		}
	}

	/**
	 * A few example searches.
	 *
	 * @param array $examples List of array( query, impressions, position ).
	 * @return void
	 */
	private static function examples( $examples ) {
		echo '<ul class="hwch-list">';

		foreach ( $examples as $example ) {
			/* translators: 1: search, 2: impressions, 3: position */
			echo '<li>' . esc_html( sprintf( __( '%1$s · %2$s impr. · pos. %3$s', 'hw-content-health' ), $example[0], number_format_i18n( $example[1] ), number_format_i18n( $example[2], 1 ) ) ) . '</li>';
		}

		echo '</ul>';
	}

	/**
	 * The "Add to plan" and "Not a gap" buttons for a gap.
	 *
	 * @param string $dest    Destination.
	 * @param string $key     Topic key.
	 * @param array  $planned Gaps in the plan.
	 * @return void
	 */
	private static function gap_actions( $dest, $key, $planned ) {
		if ( isset( $planned[ $dest . '|' . $key ] ) ) {
			echo '<span class="hwch-badge">' . esc_html__( 'In the plan', 'hw-content-health' ) . '</span>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'hwch_action' ); ?>
			<input type="hidden" name="action" value="hwch_plan_add">
			<input type="hidden" name="destination" value="<?php echo esc_attr( $dest ); ?>">
			<input type="hidden" name="topic" value="<?php echo esc_attr( $key ); ?>">
			<button class="button button-small button-primary"><?php esc_html_e( 'Add to plan', 'hw-content-health' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'hwch_action' ); ?>
			<input type="hidden" name="action" value="hwch_gap_dismiss">
			<input type="hidden" name="destination" value="<?php echo esc_attr( $dest ); ?>">
			<input type="hidden" name="topic" value="<?php echo esc_attr( $key ); ?>">
			<button class="button button-small"><?php esc_html_e( 'Not a gap', 'hw-content-health' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Gaps view.
	 *
	 * @param array $data  Analysis.
	 * @param int   $paged Page.
	 * @return void
	 */
	private static function view_gaps( $data, $paged ) {
		// phpcs:disable WordPress.Security.NonceVerification
		$level = isset( $_GET['level'] ) ? absint( $_GET['level'] ) : 0;
		$only  = isset( $_GET['dest'] ) ? sanitize_title( wp_unslash( $_GET['dest'] ) ) : '';
		$kind  = isset( $_GET['kind'] ) && 'weak' === $_GET['kind'] ? 'weak' : 'gap';
		// phpcs:enable

		$min     = (int) HWCH_Settings::get( 'planner_min_impr' );
		$planned = self::planned();
		$seasons = self::seasons();
		$rows    = array_values(
			array_filter(
				$data['gaps'],
				function ( $gap ) use ( $min, $level, $only, $kind ) {
					return $gap['status'] === $kind
						&& $gap['impressions'] >= $min
						&& ( ! $level || min( 3, HWCH_Overlap::level( $gap['dest'] ) ) === $level )
						&& ( ! $only || $gap['dest'] === $only )
						&& ! self::dismissed( $gap['dest'], $gap['key'] );
				}
			)
		);
		$total   = count( $rows );
		$rows    = array_slice( $rows, ( $paged - 1 ) * HWCH_Export::per( 50 ), HWCH_Export::per( 50 ) );
		$base    = admin_url( 'admin.php?page=hwch&tab=planner&view=gaps' );
		?>
		<form method="get" class="hwch-inline-form">
			<input type="hidden" name="page" value="hwch">
			<input type="hidden" name="tab" value="planner">
			<input type="hidden" name="view" value="gaps">
			<?php if ( $only ) : ?>
				<input type="hidden" name="dest" value="<?php echo esc_attr( $only ); ?>">
			<?php endif; ?>
			<select name="kind">
				<option value="gap" <?php selected( 'gap', $kind ); ?>><?php esc_html_e( 'No article yet', 'hw-content-health' ); ?></option>
				<option value="weak" <?php selected( 'weak', $kind ); ?>><?php esc_html_e( 'Article exists but ranks on page 3 or lower', 'hw-content-health' ); ?></option>
			</select>
			<select name="level">
				<option value="0"><?php esc_html_e( 'Every destination', 'hw-content-health' ); ?></option>
				<option value="3" <?php selected( 3, $level ); ?>><?php esc_html_e( 'Cities', 'hw-content-health' ); ?></option>
				<option value="2" <?php selected( 2, $level ); ?>><?php esc_html_e( 'Countries', 'hw-content-health' ); ?></option>
				<option value="1" <?php selected( 1, $level ); ?>><?php esc_html_e( 'Continents', 'hw-content-health' ); ?></option>
			</select>
			<button class="button"><?php esc_html_e( 'Show', 'hw-content-health' ); ?></button>
			<?php if ( $only ) : ?>
				<?php /* translators: %s: destination */ ?>
				<a href="<?php echo esc_url( $base ); ?>"><?php echo esc_html( sprintf( __( 'Showing %s only: show every destination', 'hw-content-health' ), self::dest_name( $only ) ) ); ?></a>
			<?php endif; ?>
		</form>
		<p class="hwch-count">
			<?php
			/* translators: 1: number, 2: impressions */
			echo esc_html( sprintf( _n( '%1$s gap with at least %2$s impressions', '%1$s gaps with at least %2$s impressions', $total, 'hw-content-health' ), number_format_i18n( $total ), number_format_i18n( $min ) ) );
			?>
		</p>
		<?php if ( ! $total ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'No gaps with enough impressions for this selection. Lower "Gaps need at least … impressions" in Settings to see smaller ones.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Destination', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Topic', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Impressions', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Avg. position', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Top searches', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Timing', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'hw-content-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $gap ) : ?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( add_query_arg( 'dest', $gap['dest'], $base ) ); ?>"><?php echo esc_html( self::dest_name( $gap['dest'] ) ); ?></a></strong>
							<div class="hwch-muted"><?php echo esc_html( self::level_name( $gap['dest'] ) ); ?></div>
						</td>
						<td>
							<?php echo esc_html( self::label( $gap['key'], $gap['examples'] ) ); ?>
							<?php if ( 'weak' === $gap['status'] && $gap['covering'] ) : ?>
								<div class="hwch-muted">
									<?php esc_html_e( 'Covered by:', 'hw-content-health' ); ?>
									<?php foreach ( $gap['covering'] as $id ) : ?>
										<br><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( $gap['impressions'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $gap['position'], 1 ) ); ?></td>
						<td><?php self::examples( $gap['examples'] ); ?></td>
						<td>
							<?php if ( isset( $seasons[ $gap['dest'] ] ) ) : ?>
								<?php $season = $seasons[ $gap['dest'] ]; ?>
								<?php /* translators: %s: month */ ?>
								<?php echo esc_html( sprintf( __( 'Peaks in %s', 'hw-content-health' ), HWCH_Seasonal::month_name( $season['peak'] ) ) ); ?>
								<?php /* translators: %s: date */ ?>
								<div class="hwch-muted"><?php echo esc_html( sprintf( __( 'Publish by %s', 'hw-content-health' ), date_i18n( get_option( 'date_format' ), $season['publish_by'] ) ) ); ?></div>
							<?php else : ?>
								<span class="hwch-muted"><?php esc_html_e( 'No clear season', 'hw-content-health' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="hwch-actions"><?php self::gap_actions( $gap['dest'], $gap['key'], $planned ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		HWCH_Admin::paginate( $total, $paged, 50 );
	}

	/**
	 * Destinations view.
	 *
	 * @param array $data  Analysis.
	 * @param int   $paged Page.
	 * @return void
	 */
	private static function view_dests( $data, $paged ) {
		$min     = (int) HWCH_Settings::get( 'planner_min_impr' );
		$seasons = self::seasons();
		$gapsum  = array();

		foreach ( $data['gaps'] as $gap ) {
			if ( 'gap' === $gap['status'] && $gap['impressions'] >= $min && ! self::dismissed( $gap['dest'], $gap['key'] ) ) {
				$gapsum[ $gap['dest'] ]['n']    = ( isset( $gapsum[ $gap['dest'] ]['n'] ) ? $gapsum[ $gap['dest'] ]['n'] : 0 ) + 1;
				$gapsum[ $gap['dest'] ]['impr'] = ( isset( $gapsum[ $gap['dest'] ]['impr'] ) ? $gapsum[ $gap['dest'] ]['impr'] : 0 ) + $gap['impressions'];
			}
		}

		$rows = array();

		foreach ( $data['dests'] as $slug => $dest ) {
			$rows[] = $dest + array(
				'slug'     => $slug,
				'gaps'     => isset( $gapsum[ $slug ] ) ? $gapsum[ $slug ]['n'] : 0,
				'gap_impr' => isset( $gapsum[ $slug ] ) ? $gapsum[ $slug ]['impr'] : 0,
			);
		}

		// Most impressions in gaps first, then most impressions overall.
		usort(
			$rows,
			function ( $a, $b ) {
				return array( $b['gap_impr'], $b['impressions'] ) <=> array( $a['gap_impr'], $a['impressions'] );
			}
		);

		$total = count( $rows );
		$rows  = array_slice( $rows, ( $paged - 1 ) * HWCH_Export::per( 50 ), HWCH_Export::per( 50 ) );
		$base  = admin_url( 'admin.php?page=hwch&tab=planner&view=gaps' );
		?>
		<p class="hwch-muted"><?php esc_html_e( 'Every destination with a hub page or searches, those with the most impressions in gaps first. "Thin" marks destinations with two English articles or fewer. "On page 2 or lower" is the share of the destination\'s impressions where the blog ranks below the first page: demand the blog is not yet capturing.', 'hw-content-health' ); ?></p>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Destination', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Hub', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'English articles', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Impressions', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'On page 2 or lower', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Gaps', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Timing', 'hw-content-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $hub = self::hub( $row['slug'] ); ?>
					<tr>
						<td>
							<strong><?php echo esc_html( self::dest_name( $row['slug'] ) ); ?></strong>
							<?php if ( $row['articles'] <= 2 ) : ?>
								<span class="hwch-badge"><?php esc_html_e( 'Thin', 'hw-content-health' ); ?></span>
							<?php endif; ?>
							<div class="hwch-muted"><?php echo esc_html( self::level_name( $row['slug'] ) ); ?></div>
						</td>
						<td>
							<?php if ( 'none' === $hub['status'] ) : ?>
								<span class="hwch-muted"><?php esc_html_e( 'None', 'hw-content-health' ); ?></span>
							<?php else : ?>
								<a href="<?php echo esc_url( $hub['edit'] ); ?>"><?php echo esc_html( 'publish' === $hub['status'] ? __( 'Published', 'hw-content-health' ) : __( 'Draft', 'hw-content-health' ) ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( $row['articles'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['impressions'] ) ); ?></td>
						<td><?php echo esc_html( $row['impressions'] ? number_format_i18n( 100 * $row['low'] / $row['impressions'] ) . '%' : '–' ); ?></td>
						<td>
							<?php if ( $row['gaps'] ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'dest', $row['slug'], $base ) ); ?>">
									<?php
									/* translators: 1: number of gaps, 2: impressions */
									echo esc_html( sprintf( _n( '%1$s gap, %2$s impr.', '%1$s gaps, %2$s impr.', $row['gaps'], 'hw-content-health' ), number_format_i18n( $row['gaps'] ), number_format_i18n( $row['gap_impr'] ) ) );
									?>
								</a>
							<?php else : ?>
								<span class="hwch-muted">–</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( isset( $seasons[ $row['slug'] ] ) ) : ?>
								<?php /* translators: %s: month */ ?>
								<?php echo esc_html( sprintf( __( 'Peaks in %s', 'hw-content-health' ), HWCH_Seasonal::month_name( $seasons[ $row['slug'] ]['peak'] ) ) ); ?>
							<?php else : ?>
								<span class="hwch-muted">–</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		HWCH_Admin::paginate( $total, $paged, 50 );
	}

	/**
	 * Searches with no destination view.
	 *
	 * @param array $data  Analysis.
	 * @param int   $paged Page.
	 * @return void
	 */
	private static function view_other( $data, $paged ) {
		$min     = (int) HWCH_Settings::get( 'planner_min_impr' );
		$planned = self::planned();
		$rows    = array_values(
			array_filter(
				$data['general'],
				function ( $gap ) use ( $min ) {
					return $gap['impressions'] >= $min && ! self::dismissed( '', $gap['key'] );
				}
			)
		);
		$total   = count( $rows );
		$rows    = array_slice( $rows, ( $paged - 1 ) * HWCH_Export::per( 50 ), HWCH_Export::per( 50 ) );
		?>
		<p class="hwch-muted"><?php esc_html_e( 'Searches that name no destination, rank below the first page, and match no English article title (two thirds of their words), grouped by their words. Often broad guides or travel-style topics, like "solo female travel tips".', 'hw-content-health' ); ?></p>
		<?php if ( ! $total ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'None with enough impressions.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Words', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Impressions', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Avg. position', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Top searches', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'hw-content-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $gap ) : ?>
					<tr>
						<td><strong><?php echo esc_html( self::label( $gap['key'], $gap['examples'] ) ); ?></strong></td>
						<td><?php echo esc_html( number_format_i18n( $gap['impressions'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $gap['position'], 1 ) ); ?></td>
						<td><?php self::examples( $gap['examples'] ); ?></td>
						<td class="hwch-actions"><?php self::gap_actions( '', $gap['key'], $planned ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		HWCH_Admin::paginate( $total, $paged, 50 );
	}

	/**
	 * Plan view.
	 *
	 * @return void
	 */
	private static function view_plan() {
		$rows     = self::plan();
		$statuses = self::statuses();
		$dests    = array_keys( HWCH_Overlap::destinations() );
		?>
		<p class="hwch-intro"><?php esc_html_e( 'Articles to write, from the gaps or your own ideas. "Create draft" makes the WordPress draft with the destination tag, and the brief appears in the Content health panel while it is being written. The status follows the draft: Writing once it exists, Published once it is live.', 'hw-content-health' ); ?></p>

		<details class="hwch-details hwch-plan-new">
			<summary><?php esc_html_e( 'Add your own idea', 'hw-content-health' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'hwch_action' ); ?>
				<input type="hidden" name="action" value="hwch_plan_add">
				<p><label><?php esc_html_e( 'Working title', 'hw-content-health' ); ?><br><input type="text" name="title" class="regular-text" required></label></p>
				<p>
					<label><?php esc_html_e( 'Destination (optional)', 'hw-content-health' ); ?><br>
						<input type="text" name="destination" list="hwch-dests" class="regular-text">
						<datalist id="hwch-dests">
							<?php foreach ( $dests as $slug ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( self::dest_name( $slug ) ); ?></option>
							<?php endforeach; ?>
						</datalist>
					</label>
				</p>
				<p><label><?php esc_html_e( 'Publish by (optional)', 'hw-content-health' ); ?><br><input type="month" name="due"></label></p>
				<p><label><?php esc_html_e( 'Notes', 'hw-content-health' ); ?><br><textarea name="notes" rows="3" class="large-text"></textarea></label></p>
				<p><button class="button button-primary"><?php esc_html_e( 'Add to plan', 'hw-content-health' ); ?></button></p>
			</form>
		</details>

		<?php if ( ! $rows ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'The plan is empty. Add gaps from the Gaps view, or your own ideas above.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Article', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Why', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Status and date', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'hw-content-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $evidence = $row['evidence'] ? json_decode( $row['evidence'], true ) : null; ?>
					<tr class="<?php echo 'published' === $row['status'] ? 'hwch-row-done' : ''; ?>">
						<td>
							<?php if ( $row['post_id'] && get_post( (int) $row['post_id'] ) ) : ?>
								<strong><a href="<?php echo esc_url( get_edit_post_link( (int) $row['post_id'] ) ); ?>"><?php echo esc_html( ( 'refresh' === $row['topic'] ? __( 'Refresh: ', 'hw-content-health' ) : '' ) . get_the_title( (int) $row['post_id'] ) ); ?></a></strong>
							<?php else : ?>
								<strong><?php echo esc_html( $row['title'] ); ?></strong>
								<div class="hwch-muted"><?php esc_html_e( 'Working title', 'hw-content-health' ); ?></div>
							<?php endif; ?>
							<?php if ( $row['destination'] ) : ?>
								<div class="hwch-muted"><?php echo esc_html( self::dest_name( $row['destination'] ) . ( $row['topic'] ? ' · ' . self::label( $row['topic'] ) : '' ) ); ?></div>
							<?php endif; ?>
							<?php if ( $row['notes'] ) : ?>
								<div class="hwch-plan-notes"><?php echo esc_html( $row['notes'] ); ?></div>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $evidence ) : ?>
								<?php /* translators: 1: impressions, 2: position */ ?>
								<?php echo esc_html( sprintf( __( '%1$s impressions, avg. position %2$s', 'hw-content-health' ), number_format_i18n( $evidence['impressions'] ), number_format_i18n( $evidence['position'], 1 ) ) ); ?>
								<?php self::examples( $evidence['examples'] ); ?>
							<?php elseif ( 'refresh' === $row['topic'] ) : ?>
								<a href="<?php echo esc_url( HWCH_Brief::url( (int) $row['post_id'] ) ); ?>"><?php esc_html_e( 'Refresh brief', 'hw-content-health' ); ?></a>
							<?php else : ?>
								<span class="hwch-muted"><?php esc_html_e( 'Own idea', 'hw-content-health' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hwch-plan-edit">
								<?php wp_nonce_field( 'hwch_action' ); ?>
								<input type="hidden" name="action" value="hwch_plan_update">
								<input type="hidden" name="plan_id" value="<?php echo esc_attr( $row['id'] ); ?>">
								<select name="status">
									<?php foreach ( $statuses as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $row['status'] ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<input type="month" name="due" value="<?php echo esc_attr( $row['due'] ? substr( $row['due'], 0, 7 ) : '' ); ?>">
								<button class="button button-small"><?php esc_html_e( 'Save', 'hw-content-health' ); ?></button>
							</form>
							<?php if ( $row['due'] && 'published' !== $row['status'] && strtotime( $row['due'] ) < strtotime( gmdate( 'Y-m-01' ) ) ) : ?>
								<span class="hwch-status hwch-status--late"><?php esc_html_e( 'Overdue', 'hw-content-health' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="hwch-actions">
							<?php if ( ( ! $row['post_id'] || ! get_post( (int) $row['post_id'] ) ) && class_exists( 'TH_Bridge' ) ) : ?>
								<a class="button button-small button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=trailhead&view=start&plan=' . (int) $row['id'] ) ); ?>"><?php esc_html_e( 'Start in Trailhead', 'hw-content-health' ); ?></a>
							<?php elseif ( ! $row['post_id'] || ! get_post( (int) $row['post_id'] ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'hwch_action' ); ?>
									<input type="hidden" name="action" value="hwch_plan_draft">
									<input type="hidden" name="plan_id" value="<?php echo esc_attr( $row['id'] ); ?>">
									<button class="button button-small button-primary"><?php esc_html_e( 'Create draft', 'hw-content-health' ); ?></button>
								</form>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this from the plan? Any draft already created is kept.', 'hw-content-health' ) ); ?>');">
								<?php wp_nonce_field( 'hwch_action' ); ?>
								<input type="hidden" name="action" value="hwch_plan_delete">
								<input type="hidden" name="plan_id" value="<?php echo esc_attr( $row['id'] ); ?>">
								<button class="button button-small"><?php esc_html_e( 'Remove', 'hw-content-health' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The plan item a post was drafted from, for the editor panel.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function for_post( $post_id ) {
		global $wpdb;

		if ( ! $post_id ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . HWCH_Store::table( 'plan' ) . ' WHERE post_id = %d LIMIT 1', $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		if ( ! $row ) {
			return null;
		}

		$evidence = $row['evidence'] ? json_decode( $row['evidence'], true ) : null;

		return array(
			'topic'    => $row['topic'] ? self::label( $row['topic'] ) : '',
			'notes'    => (string) $row['notes'],
			'due'      => $row['due'] ? date_i18n( 'F Y', strtotime( $row['due'] ) ) : '',
			'searches' => $evidence ? array_map(
				function ( $e ) {
					return array( 'query' => $e[0], 'impressions' => (int) $e[1] );
				},
				$evidence['examples']
			) : array(),
		);
	}
}
