<?php
/**
 * Out of date: titles naming a past year, and event articles that have not
 * been updated for a while.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds dated content and renders the Out of date tab.
 */
class HWCH_Dated {

	/**
	 * Published English articles whose title, SEO title or URL names a year
	 * before this one.
	 *
	 * @return array
	 */
	public static function past_years() {
		global $wpdb;

		$year    = (int) gmdate( 'Y' );
		$seo_key = HWCH_Overlap::seo_title_key();
		$rows    = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT ID, post_title, post_name, post_modified_gmt FROM {$wpdb->posts}
			WHERE post_type = 'post' AND post_status = 'publish'
			AND ( post_title REGEXP '20[0-9]{2}' OR post_name REGEXP '20[0-9]{2}' )",
			ARRAY_A
		);

		// SEO titles can hold a year the post title does not.
		if ( $seo_key ) {
			$extra = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value REGEXP '20[0-9]{2}'", $seo_key )
			);
			$have  = array_flip( wp_list_pluck( (array) $rows, 'ID' ) );

			foreach ( (array) $extra as $id ) {
				$post = get_post( (int) $id );

				if ( $post && 'post' === $post->post_type && 'publish' === $post->post_status && ! isset( $have[ $post->ID ] ) ) {
					$rows[] = array(
						'ID'                => $post->ID,
						'post_title'        => $post->post_title,
						'post_name'         => $post->post_name,
						'post_modified_gmt' => $post->post_modified_gmt,
					);
				}
			}
		}

		$english = array_flip( HWCH_Reports::english_ids( wp_list_pluck( (array) $rows, 'ID' ) ) );
		$metrics = HWCH_Overlap::metrics();
		$out     = array();

		foreach ( (array) $rows as $row ) {
			$id = (int) $row['ID'];

			if ( ! isset( $english[ $id ] ) || self::dismissed( $id ) ) {
				continue;
			}

			$seo    = $seo_key ? (string) get_post_meta( $id, $seo_key, true ) : '';
			$fields = array();

			foreach ( array( 'title' => $row['post_title'], 'seo' => $seo, 'url' => rawurldecode( $row['post_name'] ) ) as $field => $text ) {
				$old = self::past_years_in( $text, $year );

				if ( $old ) {
					$fields[ $field ] = $old;
				}
			}

			if ( ! $fields ) {
				continue;
			}

			$out[] = array(
				'id'     => $id,
				'fields' => $fields,
				'seo'    => $seo,
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
	 * Years before this one found in a piece of text.
	 *
	 * @param string $text Text.
	 * @param int    $year This year.
	 * @return int[]
	 */
	public static function past_years_in( $text, $year ) {
		preg_match_all( '/(?<!\d)(20\d{2})(?!\d)/', (string) $text, $m );

		return array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $m[1] ),
					function ( $y ) use ( $year ) {
						return $y >= 2000 && $y < $year;
					}
				)
			)
		);
	}

	/**
	 * Published English articles in the event tags that have not been
	 * updated for the set number of months.
	 *
	 * @return array
	 */
	public static function stale_events() {
		$slugs = array_filter( array_map( 'trim', explode( ',', (string) HWCH_Settings::get( 'event_tags' ) ) ) );

		if ( ! $slugs ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy' => 'post_tag',
						'field'    => 'slug',
						'terms'    => $slugs,
					),
				),
				'date_query'       => array(
					array(
						'column' => 'post_modified_gmt',
						'before' => (int) HWCH_Settings::get( 'event_age' ) . ' months ago',
					),
				),
			)
		);

		$metrics = HWCH_Overlap::metrics();
		$out     = array();

		foreach ( HWCH_Reports::english_ids( $ids ) as $id ) {
			if ( self::dismissed( $id ) ) {
				continue;
			}

			$out[] = array(
				'id'     => $id,
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
	 * Whether someone hid this article from the tab.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	private static function dismissed( $id ) {
		return (int) get_post_meta( $id, '_hwch_dismissed_dated', true ) > time();
	}

	/**
	 * Renders the tab.
	 *
	 * @return void
	 */
	public static function render() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$view  = isset( $_GET['view'] ) && 'events' === $_GET['view'] ? 'events' : 'years';
		$years = 'years' === $view ? self::past_years() : array();
		$events = 'events' === $view ? self::stale_events() : array();
		?>
		<ul class="subsubsub hwch-filter">
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=hwch&tab=dated&view=years' ) ); ?>" class="<?php echo 'years' === $view ? 'current' : ''; ?>"><?php esc_html_e( 'Past years in titles', 'hw-content-health' ); ?></a> |</li>
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=hwch&tab=dated&view=events' ) ); ?>" class="<?php echo 'events' === $view ? 'current' : ''; ?>"><?php esc_html_e( 'Event articles not updated', 'hw-content-health' ); ?></a></li>
		</ul>
		<div class="clear"></div>
		<?php
		if ( 'years' === $view ) {
			self::view_years( $years );
		} else {
			self::view_events( $events );
		}
	}

	/**
	 * Past years in titles.
	 *
	 * @param array $rows Rows.
	 * @return void
	 */
	private static function view_years( $rows ) {
		$seo_key = HWCH_Overlap::seo_title_key();
		?>
		<p class="hwch-intro">
			<?php
			printf(
				/* translators: %s: this year */
				esc_html__( 'English articles whose title, SEO title or URL names a year before %s. An old year in the title costs clicks even when the content is still right. Edit the title here if it is only the year that is out of date, or open the article if it needs more. If the year is correct (a history piece, say), hide it for six months. Most clicks first. Changing a URL needs a redirect, so the URL is only flagged, not edited here.', 'hw-content-health' ),
				esc_html( gmdate( 'Y' ) )
			);
			?>
		</p>
		<?php self::count( count( $rows ) ); ?>
		<?php if ( ! $rows ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'No titles with a past year.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Article', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Clicks', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Year found in', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Title', 'hw-content-health' ); ?></th>
					<?php if ( $seo_key ) : ?>
						<th><?php esc_html_e( 'SEO title', 'hw-content-health' ); ?></th>
					<?php endif; ?>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $rows, 0, HWCH_Export::per( 200 ) ) as $row ) : ?>
					<?php
					$id     = $row['id'];
					$labels = array(
						'title' => __( 'title', 'hw-content-health' ),
						'seo'   => __( 'SEO title', 'hw-content-health' ),
						'url'   => __( 'URL', 'hw-content-health' ),
					);
					$found  = array();
					foreach ( $row['fields'] as $field => $years ) {
						$found[] = $labels[ $field ] . ' (' . implode( ', ', $years ) . ')';
					}
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit article', 'hw-content-health' ); ?></a></strong>
							<div class="hwch-url"><a href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( rawurldecode( (string) wp_parse_url( get_permalink( $id ), PHP_URL_PATH ) ) ); ?></a></div>
						</td>
						<td><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td>
						<td><?php echo esc_html( implode( '; ', $found ) ); ?></td>
						<td class="hwch-seo"><?php self::title_form( 'post_title', $id, get_post_field( 'post_title', $id, 'raw' ), '' ); ?></td>
						<?php if ( $seo_key ) : ?>
							<td class="hwch-seo"><?php self::title_form( 'seo_title', $id, $row['seo'], get_post_field( 'post_title', $id, 'raw' ) ); ?></td>
						<?php endif; ?>
						<td class="hwch-actions"><?php self::hide_button( $id ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Event articles not updated.
	 *
	 * @param array $rows Rows.
	 * @return void
	 */
	private static function view_events( $rows ) {
		$s = HWCH_Settings::all();
		?>
		<p class="hwch-intro">
			<?php
			printf(
				/* translators: 1: tags, 2: months */
				esc_html__( 'English articles tagged %1$s that have not been updated for over %2$s months. Events change dates, move or end, so these are the articles most likely to be wrong. Update them, or hide them for six months if they are still accurate. The tags and the number of months are in Settings.', 'hw-content-health' ),
				esc_html( $s['event_tags'] ),
				esc_html( $s['event_age'] )
			);
			?>
		</p>
		<?php self::count( count( $rows ) ); ?>
		<?php if ( ! $rows ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'No event articles need checking.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Article', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Clicks', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Last updated', 'hw-content-health' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $rows, 0, HWCH_Export::per( 200 ) ) as $row ) : ?>
					<?php $modified = strtotime( get_post_field( 'post_modified_gmt', $row['id'] ) . ' UTC' ); ?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>"><?php echo esc_html( get_the_title( $row['id'] ) ); ?></a></strong>
							<div class="hwch-url"><a href="<?php echo esc_url( get_permalink( $row['id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( rawurldecode( (string) wp_parse_url( get_permalink( $row['id'] ), PHP_URL_PATH ) ) ); ?></a></div>
						</td>
						<td><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td>
						<td>
							<?php echo esc_html( date_i18n( get_option( 'date_format' ), $modified ) ); ?>
							<?php /* translators: %s: time since */ ?>
							<div class="hwch-muted"><?php echo esc_html( sprintf( __( '%s ago', 'hw-content-health' ), human_time_diff( $modified ) ) ); ?></div>
						</td>
						<td class="hwch-actions">
							<a class="button button-small button-primary" href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'hw-content-health' ); ?></a>
							<?php self::hide_button( $row['id'] ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * An inline title form.
	 *
	 * @param string $action      post_title or seo_title.
	 * @param int    $id          Post ID.
	 * @param string $value       Current value.
	 * @param string $placeholder Placeholder.
	 * @return void
	 */
	private static function title_form( $action, $id, $value, $placeholder ) {
		$field = 'post_title' === $action ? 'post_title' : 'seo_title';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'hwch_action' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( 'hwch_' . $action ); ?>">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( $id ); ?>">
			<input type="hidden" name="return_tab" value="dated">
			<input type="text" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>">
			<button class="button button-small"><?php esc_html_e( 'Save', 'hw-content-health' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Hide for six months.
	 *
	 * @param int $id Post ID.
	 * @return void
	 */
	private static function hide_button( $id ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'hwch_action' ); ?>
			<input type="hidden" name="action" value="hwch_dismiss">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( $id ); ?>">
			<input type="hidden" name="report" value="dated">
			<button class="button button-small"><?php esc_html_e( 'Still correct', 'hw-content-health' ); ?></button>
		</form>
		<?php
	}

	/**
	 * A count line.
	 *
	 * @param int $n Count.
	 * @return void
	 */
	private static function count( $n ) {
		/* translators: %s: number */
		echo '<p class="hwch-count">' . esc_html( sprintf( _n( '%s article', '%s articles', $n, 'hw-content-health' ), number_format_i18n( $n ) ) ) . ( $n > 200 ? ' <span class="hwch-muted">' . esc_html__( '(showing the 200 with most clicks)', 'hw-content-health' ) . '</span>' : '' ) . '</p>';
	}
}
