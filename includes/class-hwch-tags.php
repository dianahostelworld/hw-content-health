<?php
/**
 * Tags & hubs: the tagging the destination hubs depend on.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds tagging problems and renders the Tags & hubs tab.
 */
class HWCH_Tags {

	/**
	 * Published English articles and the tag slugs each carries.
	 *
	 * @return array Map of post ID => tag slugs.
	 */
	public static function article_tags() {
		global $wpdb;

		$ids = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		$map = array_fill_keys( HWCH_Reports::english_ids( $ids ), array() );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT tr.object_id, t.slug FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'post_tag'
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$id = (int) $row['object_id'];

			if ( isset( $map[ $id ] ) ) {
				$map[ $id ][] = $row['slug'];
			}
		}

		return $map;
	}

	/**
	 * English articles with none of the destination tags the hubs use, with
	 * suggested tags from destinations named in the title.
	 *
	 * @return array
	 */
	public static function untagged() {
		$destinations = HWCH_Overlap::destinations();

		if ( ! $destinations ) {
			return array();
		}

		$metrics = HWCH_Overlap::metrics();
		$out     = array();

		foreach ( self::article_tags() as $id => $slugs ) {
			if ( array_intersect( $slugs, array_keys( $destinations ) ) ) {
				continue;
			}

			$out[] = array(
				'id'          => $id,
				'suggestions' => HWCH_Overlap::places( $id ),
				'clicks'      => isset( $metrics[ $id ] ) ? (int) $metrics[ $id ]['clicks'] : 0,
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
	 * Groups of tags that look like the same thing: the same name once case,
	 * accents and punctuation are ignored, or the same slug with a number
	 * added (barcelona and barcelona-2).
	 *
	 * @return array List of groups of WP_Term.
	 */
	public static function duplicates() {
		$terms = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

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

		$by_key = array();

		foreach ( $terms as $term ) {
			$name = preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( $term->name ) ) );
			// WordPress adds -2, -3… when a slug is taken; longer numbers are
			// usually part of the tag (europe-2025), so they are left alone.
			$base = preg_replace( '/-[2-9]$/', '', $term->slug );

			foreach ( array( 'n:' . $name, 's:' . $base ) as $key ) {
				if ( isset( $by_key[ $key ] ) ) {
					$parent[ $find( $term->term_id ) ] = $find( $by_key[ $key ] );
				} else {
					$by_key[ $key ] = $term->term_id;
				}
			}
		}

		$groups = array();

		foreach ( $terms as $term ) {
			$groups[ $find( $term->term_id ) ][] = $term;
		}

		$groups = array_values(
			array_filter(
				$groups,
				function ( $group ) {
					return count( $group ) > 1;
				}
			)
		);

		foreach ( $groups as $i => $group ) {
			usort(
				$group,
				function ( $a, $b ) {
					return $b->count <=> $a->count;
				}
			);
			$groups[ $i ] = $group;
		}

		return $groups;
	}

	/**
	 * Tags on only one article, or none.
	 *
	 * @return WP_Term[]
	 */
	public static function thin_tags() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
				'orderby'    => 'count',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$terms,
				function ( $term ) {
					return $term->count <= 1;
				}
			)
		);
	}

	/**
	 * Destination hubs whose tag has fewer articles than the minimum.
	 *
	 * @return array
	 */
	public static function thin_hubs() {
		global $wpdb;

		$keys = array();
		foreach ( array( 'hw_hub_', '_hw_hub_' ) as $base ) {
			foreach ( array( '', 'country_', 'city_' ) as $template ) {
				$keys[] = $base . $template . 'hub_term';
			}
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'page' AND p.post_status IN ('publish', 'private', 'draft')
			WHERE pm.meta_key IN ('" . implode( "','", array_map( 'esc_sql', $keys ) ) . "') AND pm.meta_value <> ''", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		$min = (int) HWCH_Settings::get( 'hub_min' );
		$out = array();
		$tag_counts = self::english_counts();

		foreach ( (array) $rows as $row ) {
			$slug  = sanitize_title( preg_split( '/[+,|\s]+/', strtolower( $row['meta_value'] ) )[0] );
			$count = isset( $tag_counts[ $slug ] ) ? $tag_counts[ $slug ] : 0;

			if ( $count < $min ) {
				$out[ (int) $row['post_id'] ] = array(
					'hub'   => (int) $row['post_id'],
					'slug'  => $slug,
					'count' => $count,
				);
			}
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $a['count'] <=> $b['count'];
			}
		);

		return $out;
	}

	/**
	 * How many published English articles carry each tag.
	 *
	 * @return array Map of slug => count.
	 */
	private static function english_counts() {
		$counts = array();

		foreach ( self::article_tags() as $slugs ) {
			foreach ( $slugs as $slug ) {
				$counts[ $slug ] = ( isset( $counts[ $slug ] ) ? $counts[ $slug ] : 0 ) + 1;
			}
		}

		return $counts;
	}

	/**
	 * Renders the tab.
	 *
	 * @return void
	 */
	public static function render() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$view  = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'untagged';
		$views = array(
			'untagged'   => __( 'No destination tag', 'hw-content-health' ),
			'duplicates' => __( 'Duplicate tags', 'hw-content-health' ),
			'thin'       => __( 'Tags used once', 'hw-content-health' ),
			'hubs'       => __( 'Hubs with few articles', 'hw-content-health' ),
		);
		$view  = isset( $views[ $view ] ) ? $view : 'untagged';
		?>
		<ul class="subsubsub hwch-filter">
			<?php $i = 0; ?>
			<?php foreach ( $views as $key => $label ) : ?>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=hwch&tab=tags&view=' . $key ) ); ?>" class="<?php echo $view === $key ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a><?php echo ++$i < count( $views ) ? ' |' : ''; ?></li>
			<?php endforeach; ?>
		</ul>
		<div class="clear"></div>
		<?php
		if ( ! HWCH_Overlap::destinations() && 'duplicates' !== $view && 'thin' !== $view ) {
			echo '<p class="hwch-empty">' . esc_html__( 'No destination hubs were found. This view uses the tag set on each continent, country and city hub in the Blog Hub plugin.', 'hw-content-health' ) . '</p>';
			return;
		}

		call_user_func( array( __CLASS__, 'view_' . $view ) );
	}

	/**
	 * No destination tag.
	 *
	 * @return void
	 */
	private static function view_untagged() {
		$rows  = self::untagged();
		$total = count( $rows );
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$rows  = array_slice( $rows, ( $paged - 1 ) * HWCH_Export::per( 50 ), HWCH_Export::per( 50 ) );
		$dest  = HWCH_Overlap::destinations();
		?>
		<p class="hwch-intro"><?php esc_html_e( 'English articles with none of the tags the destination hubs use, so no hub can show them. Where the title names a destination, it is suggested; add it with one click. Most clicks first.', 'hw-content-health' ); ?></p>
		<p class="hwch-count">
			<?php
			/* translators: %s: number */
			echo esc_html( sprintf( _n( '%s article', '%s articles', $total, 'hw-content-health' ), number_format_i18n( $total ) ) );
			?>
		</p>
		<?php if ( ! $total ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'Every English article has a destination tag.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Article', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Clicks', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Current tags', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Suggested destination', 'hw-content-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $tags = wp_get_post_terms( $row['id'], 'post_tag', array( 'fields' => 'names' ) ); ?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>"><?php echo esc_html( get_the_title( $row['id'] ) ); ?></a></strong>
							<div class="hwch-url"><a href="<?php echo esc_url( get_permalink( $row['id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( rawurldecode( (string) wp_parse_url( get_permalink( $row['id'] ), PHP_URL_PATH ) ) ); ?></a></div>
						</td>
						<td><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td>
						<td class="hwch-muted"><?php echo esc_html( is_wp_error( $tags ) || ! $tags ? '–' : implode( ', ', $tags ) ); ?></td>
						<td class="hwch-actions">
							<?php if ( ! $row['suggestions'] ) : ?>
								<span class="hwch-muted"><?php esc_html_e( 'None found in the title', 'hw-content-health' ); ?></span>
							<?php endif; ?>
							<?php foreach ( $row['suggestions'] as $slug ) : ?>
								<?php $term = get_term_by( 'slug', $slug, 'post_tag' ); ?>
								<?php if ( $term ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( 'hwch_action' ); ?>
										<input type="hidden" name="action" value="hwch_add_tag">
										<input type="hidden" name="post_id" value="<?php echo esc_attr( $row['id'] ); ?>">
										<input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>">
										<?php /* translators: %s: tag */ ?>
										<button class="button button-small"><?php echo esc_html( sprintf( __( 'Add "%s"', 'hw-content-health' ), $term->name ) ); ?></button>
									</form>
								<?php endif; ?>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::pages( $total, $paged );
	}

	/**
	 * Duplicate tags.
	 *
	 * @return void
	 */
	private static function view_duplicates() {
		$groups = self::duplicates();
		?>
		<p class="hwch-intro"><?php esc_html_e( 'Tags that look like the same thing: the same name once case, accents and punctuation are ignored, or the same slug with a number added (barcelona and barcelona-2). Articles split between them are missing from hubs that use only one. Merging moves every article onto the tag you keep; the emptied tag is left in place, so delete it in Posts > Tags if you no longer need it. Merges can be undone from the Log.', 'hw-content-health' ); ?></p>
		<?php if ( ! $groups ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'No duplicate tags found.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<?php foreach ( $groups as $g => $group ) : ?>
			<form class="hwch-group" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'hwch_action' ); ?>
				<input type="hidden" name="action" value="hwch_merge_tags">
				<table class="widefat hwch-table">
					<thead>
						<tr>
							<th class="hwch-narrow"><?php esc_html_e( 'Keep', 'hw-content-health' ); ?></th>
							<th class="hwch-narrow"><?php esc_html_e( 'Merge', 'hw-content-health' ); ?></th>
							<th><?php esc_html_e( 'Tag', 'hw-content-health' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'hw-content-health' ); ?></th>
							<th><?php esc_html_e( 'Articles', 'hw-content-health' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $group as $n => $term ) : ?>
							<tr>
								<td class="hwch-narrow"><input type="radio" name="keep" value="<?php echo esc_attr( $term->term_id ); ?>" <?php checked( 0, $n ); ?>></td>
								<td class="hwch-narrow"><input type="checkbox" name="from[]" value="<?php echo esc_attr( $term->term_id ); ?>" <?php checked( 0 !== $n ); ?>></td>
								<td><a href="<?php echo esc_url( get_edit_term_link( $term->term_id, 'post_tag' ) ); ?>"><?php echo esc_html( $term->name ); ?></a></td>
								<td><code><?php echo esc_html( $term->slug ); ?></code></td>
								<td><?php echo esc_html( number_format_i18n( $term->count ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Move the articles from the ticked tags onto the tag you are keeping?', 'hw-content-health' ) ); ?>');"><?php esc_html_e( 'Merge ticked tags into the kept one', 'hw-content-health' ); ?></button></p>
			</form>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Tags used once or not at all.
	 *
	 * @return void
	 */
	private static function view_thin() {
		$terms = self::thin_tags();
		$total = count( $terms );
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$terms = array_slice( $terms, ( $paged - 1 ) * HWCH_Export::per( 100 ), HWCH_Export::per( 100 ) );
		?>
		<p class="hwch-intro"><?php esc_html_e( 'Tags on one article or none. They usually mean a typo, a one-off idea, or a destination nobody has written about yet. Merge them into an existing tag from the article, or delete the empty ones in Posts > Tags.', 'hw-content-health' ); ?></p>
		<p class="hwch-count">
			<?php
			/* translators: %s: number */
			echo esc_html( sprintf( _n( '%s tag', '%s tags', $total, 'hw-content-health' ), number_format_i18n( $total ) ) );
			?>
		</p>
		<?php if ( ! $total ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'Every tag is used more than once.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Tag', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Slug', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Articles', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Used on', 'hw-content-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $terms as $term ) : ?>
					<?php $objects = $term->count ? get_objects_in_term( $term->term_id, 'post_tag' ) : array(); ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_term_link( $term->term_id, 'post_tag' ) ); ?>"><?php echo esc_html( $term->name ); ?></a></td>
						<td><code><?php echo esc_html( $term->slug ); ?></code></td>
						<td><?php echo esc_html( number_format_i18n( $term->count ) ); ?></td>
						<td>
							<?php if ( $objects && ! is_wp_error( $objects ) ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( (int) $objects[0] ) ); ?>"><?php echo esc_html( get_the_title( (int) $objects[0] ) ); ?></a>
							<?php else : ?>
								<span class="hwch-muted"><?php esc_html_e( 'Not used', 'hw-content-health' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::pages( $total, $paged, 100 );
	}

	/**
	 * Hubs with few articles.
	 *
	 * @return void
	 */
	private static function view_hubs() {
		$hubs = self::thin_hubs();
		?>
		<p class="hwch-intro">
			<?php
			printf(
				/* translators: %s: number */
				esc_html__( 'Destination hubs whose tag is on fewer than %s published English articles, so their article sections look thin. Tag more existing articles (the No destination tag view helps), or plan new ones. Fewest first.', 'hw-content-health' ),
				esc_html( number_format_i18n( HWCH_Settings::get( 'hub_min' ) ) )
			);
			?>
		</p>
		<?php if ( ! $hubs ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'Every hub has enough articles.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Hub', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Tag', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'English articles', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Status', 'hw-content-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $hubs as $hub ) : ?>
					<tr>
						<td><strong><a href="<?php echo esc_url( get_edit_post_link( $hub['hub'] ) ); ?>"><?php echo esc_html( get_post_field( 'post_title', $hub['hub'], 'raw' ) ); ?></a></strong></td>
						<td><code><?php echo esc_html( $hub['slug'] ); ?></code></td>
						<td><?php echo esc_html( number_format_i18n( $hub['count'] ) ); ?></td>
						<td><?php echo esc_html( get_post_status( $hub['hub'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Page links.
	 *
	 * @param int $total Items.
	 * @param int $paged Current page.
	 * @param int $per   Per page.
	 * @return void
	 */
	private static function pages( $total, $paged, $per = 50 ) {
		$pages = (int) ceil( $total / $per );

		if ( $pages < 2 ) {
			return;
		}

		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput
			array(
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			)
		);
		echo '</div></div>';
	}
}
