<?php
/**
 * Internal links: English articles that few or no other pages link to.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the internal link graph from post and page content.
 */
class HWCH_Links {

	/**
	 * Incoming and outgoing internal links per article, from the content of
	 * every published post and page. Links in menus, sidebars and the hubs'
	 * automatic article lists are not in the content, so they are not counted:
	 * this measures links placed in the writing itself.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array Map of post ID => array( in, out, sources ).
	 */
	public static function graph( $refresh = false ) {
		global $wpdb;

		$cached = $refresh ? false : get_transient( 'hwch_links' );

		if ( $cached ) {
			return $cached;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		// English articles only: they are what the tab reports on, and links
		// between them are what count. Reading every language at once is too
		// much for a blog with this many translations.
		$post_ids = HWCH_Reports::english_ids(
			$wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'" ) // phpcs:ignore WordPress.DB
		);
		$page_ids = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish'" ) ); // phpcs:ignore WordPress.DB

		$by_url = array();
		$graph  = array();

		foreach ( $post_ids as $id ) {
			$graph[ $id ] = array( 'in' => 0, 'out' => 0, 'sources' => array() );
		}

		// Addresses from the map the sync saved; only articles published
		// since then need their permalink worked out here.
		foreach ( (array) get_option( 'hwch_url_ids', array() ) as $url => $id ) {
			if ( isset( $graph[ (int) $id ] ) ) {
				$by_url[ $url ] = (int) $id;
			}
		}

		$missing = array_diff( $post_ids, array_values( $by_url ) );

		foreach ( $missing as $id ) {
			$by_url[ HWCH_GSC::normalise( get_permalink( $id ) ) ] = $id;
		}

		$home = wp_parse_url( home_url( '/' ) );
		$base = ( isset( $home['scheme'] ) ? $home['scheme'] : 'https' ) . '://' . ( isset( $home['host'] ) ? $home['host'] : '' );

		// Read the content in small batches to keep memory low.
		foreach ( array_chunk( array_merge( $post_ids, $page_ids ), 200 ) as $chunk ) {
			$rows = $wpdb->get_results( 'SELECT ID, post_content FROM ' . $wpdb->posts . ' WHERE ID IN (' . implode( ',', array_map( 'intval', $chunk ) ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB

			foreach ( (array) $rows as $row ) {
				$source = (int) $row['ID'];

				if ( ! preg_match_all( '/<a\s[^>]*href\s*=\s*["\']([^"\']+)["\']/i', (string) $row['post_content'], $m ) ) {
					continue;
				}

				$targets = array();

				foreach ( $m[1] as $href ) {
					$href = html_entity_decode( $href );

					if ( 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ) {
						$href = $base . $href;
					}

					$key    = HWCH_GSC::normalise( $href );
					$target = isset( $by_url[ $key ] ) ? $by_url[ $key ] : 0;

					if ( $target && $target !== $source ) {
						$targets[ $target ] = true;
					}
				}

				if ( isset( $graph[ $source ] ) ) {
					$graph[ $source ]['out'] = count( $targets );
				}

				foreach ( array_keys( $targets ) as $target ) {
					++$graph[ $target ]['in'];
					$graph[ $target ]['sources'][] = $source;
				}
			}

			unset( $rows );
		}

		set_transient( 'hwch_links', $graph, 2 * HOUR_IN_SECONDS );

		return $graph;
	}

	/**
	 * English articles with no more than a number of incoming links.
	 *
	 * @param int  $max     Most incoming links to include.
	 * @param int  $min     Fewest incoming links to include.
	 * @param bool $refresh Ignore the cache.
	 * @return array
	 */
	public static function weak( $max, $min, $refresh = false ) {
		$graph   = self::graph( $refresh );
		$metrics = HWCH_Overlap::metrics();
		$out     = array();

		foreach ( $graph as $id => $node ) {
			if ( $node['in'] > $max || $node['in'] < $min ) {
				continue;
			}

			$out[] = array(
				'id'     => $id,
				'in'     => $node['in'],
				'out'    => $node['out'],
				'clicks' => isset( $metrics[ $id ] ) ? (int) $metrics[ $id ]['clicks'] : 0,
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $b['clicks'] <=> $a['clicks'];
			}
		);

		return $out;
	}

	/**
	 * Articles that could link to this one: English articles about the same
	 * city (or, when there are none, the same country) that do not link to it
	 * yet, most clicks first. A shared continent is never enough: "Where to
	 * stay in Mallorca" and "Where to stay in Berlin" are both Europe but have
	 * nothing to say about each other.
	 *
	 * @param int   $id    Post ID.
	 * @param array $graph Link graph.
	 * @return int[]
	 */
	public static function suggestions( $id, $graph ) {
		$by_place = self::by_place();
		$metrics  = HWCH_Overlap::metrics();
		$already  = array_flip( isset( $graph[ $id ]['sources'] ) ? $graph[ $id ]['sources'] : array() );
		$places   = HWCH_Overlap::with_parents( HWCH_Overlap::places( $id ) );

		// Words in the title that are not the destination, so "Where to stay
		// in Porto" ranks "Best hostels in Porto" above "Porto nightlife".
		$place_words = array();
		foreach ( HWCH_Overlap::destinations() as $slug => $names ) {
			if ( in_array( $slug, $places, true ) ) {
				foreach ( $names as $name ) {
					$place_words = array_merge( $place_words, explode( ' ', $name ) );
				}
			}
		}
		$topic = array_diff( HWCH_Overlap::tokens( get_post_field( 'post_title', $id, 'raw' ) ), $place_words );

		// Most specific first: cities, then countries. Continents are skipped.
		for ( $level = 3; $level >= 2; $level-- ) {
			$found = array();

			foreach ( HWCH_Overlap::at_level( $places, $level ) as $place ) {
				foreach ( isset( $by_place[ $place ] ) ? $by_place[ $place ] : array() as $other ) {
					if ( $other !== $id && ! isset( $already[ $other ] ) && ! isset( $found[ $other ] ) ) {
						$shared          = $topic ? count( array_intersect( $topic, HWCH_Overlap::tokens( get_post_field( 'post_title', $other, 'raw' ) ) ) ) : 0;
						$found[ $other ] = array( $shared, isset( $metrics[ $other ] ) ? (int) $metrics[ $other ]['clicks'] : 0 );
					}
				}
			}

			if ( $found ) {
				uasort(
					$found,
					function ( $a, $b ) {
						return $b <=> $a;
					}
				);
				return array_slice( array_keys( $found ), 0, 3 );
			}
		}

		return array();
	}

	/**
	 * English articles per destination, each counted under its own tags and
	 * the country and continent above them.
	 *
	 * @return array Map of destination slug => post IDs.
	 */
	public static function by_place() {
		static $by_place = null;

		if ( null === $by_place ) {
			$by_place     = array();
			$destinations = HWCH_Overlap::destinations();

			foreach ( HWCH_Tags::article_tags() as $post_id => $slugs ) {
				foreach ( HWCH_Overlap::with_parents( array_values( array_intersect( $slugs, array_keys( $destinations ) ) ) ) as $place ) {
					$by_place[ $place ][] = $post_id;
				}
			}
		}

		return $by_place;
	}

	/**
	 * Renders the tab.
	 *
	 * @return void
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification
		$view    = isset( $_GET['view'] ) && 'few' === $_GET['view'] ? 'few' : 'none';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$refresh = isset( $_GET['refresh'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['refresh'] ) ), 'hwch_refresh' );
		// phpcs:enable

		$rows  = 'few' === $view ? self::weak( 2, 1, $refresh ) : self::weak( 0, 0, $refresh );
		$graph = self::graph();
		$total = count( $rows );
		$rows  = array_slice( $rows, ( $paged - 1 ) * HWCH_Export::per( 50 ), HWCH_Export::per( 50 ) );
		?>
		<ul class="subsubsub hwch-filter">
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=hwch&tab=links&view=none' ) ); ?>" class="<?php echo 'none' === $view ? 'current' : ''; ?>"><?php esc_html_e( 'No links in', 'hw-content-health' ); ?></a> |</li>
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=hwch&tab=links&view=few' ) ); ?>" class="<?php echo 'few' === $view ? 'current' : ''; ?>"><?php esc_html_e( 'Only 1–2 links in', 'hw-content-health' ); ?></a></li>
		</ul>
		<div class="clear"></div>
		<p class="hwch-intro">
			<?php esc_html_e( 'English articles that no other article or page links to (or only one or two do), counted from links written into the content: menus, sidebars and the hubs\' automatic article lists are not counted. Articles with few links in are harder for Google to find and rank. The last column suggests articles to edit so they link to this one: articles about the same city (or country, if there are none) that do not link to it yet. Continents are too broad to count. Most clicks first. Recounted every hour;', 'hw-content-health' ); ?>
			<a href="<?php echo esc_url( add_query_arg( 'refresh', wp_create_nonce( 'hwch_refresh' ) ) ); ?>"><?php esc_html_e( 'recount now', 'hw-content-health' ); ?></a>.
		</p>
		<p class="hwch-count">
			<?php
			/* translators: %s: number */
			echo esc_html( sprintf( _n( '%s article', '%s articles', $total, 'hw-content-health' ), number_format_i18n( $total ) ) );
			?>
		</p>
		<?php if ( ! $total ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'None found.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Article', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Clicks', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Links in', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Links out', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Add a link to it from', 'hw-content-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $suggested = self::suggestions( $row['id'], $graph ); ?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>"><?php echo esc_html( get_the_title( $row['id'] ) ); ?></a></strong>
							<div class="hwch-url"><a href="<?php echo esc_url( get_permalink( $row['id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( rawurldecode( (string) wp_parse_url( get_permalink( $row['id'] ), PHP_URL_PATH ) ) ); ?></a></div>
						</td>
						<td><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['in'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['out'] ) ); ?></td>
						<td>
							<?php if ( ! $suggested ) : ?>
								<?php
								$own = HWCH_Overlap::with_parents( HWCH_Overlap::places( $row['id'] ) );
								if ( ! $own ) {
									$why = __( 'No destination tag, so no suggestions. Add a city or country tag.', 'hw-content-health' );
								} elseif ( ! HWCH_Overlap::at_level( $own, 2 ) && ! HWCH_Overlap::at_level( $own, 3 ) ) {
									$tags   = wp_get_post_terms( $row['id'], 'post_tag', array( 'fields' => 'all' ) );
									$known  = HWCH_Overlap::destinations();
									$others = array();
									foreach ( is_array( $tags ) ? $tags : array() as $term ) {
										if ( ! isset( $known[ $term->slug ] ) ) {
											$others[] = $term->name;
										}
									}
									/* translators: 1: destination tags recognised, 2: other tags */
									$why = sprintf( __( 'Only a continent is recognised (%1$s), which is too broad to suggest from.%2$s', 'hw-content-health' ), implode( ', ', array_map( function ( $slug ) { $t = get_term_by( 'slug', $slug, 'post_tag' ); return $t ? $t->name : $slug; }, $own ) ), $others ? ' ' . sprintf( /* translators: %s: tag names */ __( 'These tags have no hub page, so they do not count as destinations yet: %s.', 'hw-content-health' ), implode( ', ', $others ) ) : '' );
								} else {
									$why = __( 'No other article about the same city or country found', 'hw-content-health' );
								}
								?>
								<span class="hwch-muted"><?php echo esc_html( $why ); ?></span>
							<?php else : ?>
								<ul class="hwch-list">
									<?php foreach ( $suggested as $source ) : ?>
										<li><a href="<?php echo esc_url( get_edit_post_link( $source ) ); ?>"><?php echo esc_html( get_the_title( $source ) ); ?></a></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $total / 50 );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div></div>';
		}
	}
}
