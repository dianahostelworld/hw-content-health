<?php
/**
 * On-page checks for English articles: meta descriptions, duplicate SEO
 * titles, featured images, length and noindex.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs the checks and renders the On-page tab.
 */
class HWCH_Onpage {

	/**
	 * The meta description field in use: Yoast, Rank Math, or none.
	 *
	 * @return string
	 */
	public static function desc_key() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return '_yoast_wpseo_metadesc';
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rank_math_description';
		}

		return '';
	}

	/**
	 * Whether an article is set to noindex in Yoast or Rank Math.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	private static function is_noindex( $id ) {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return '1' === (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true );
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return in_array( 'noindex', (array) get_post_meta( $id, 'rank_math_robots', true ), true );
		}

		return false;
	}

	/**
	 * All checks, cached for an hour.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array
	 */
	public static function data( $refresh = false ) {
		$cached = $refresh ? false : get_transient( 'hwch_onpage' );

		if ( $cached ) {
			return $cached;
		}

		$ids = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		$ids = HWCH_Reports::english_ids( $ids );
		update_meta_cache( 'post', $ids );

		$seo_key  = HWCH_Overlap::seo_title_key();
		$desc_key = self::desc_key();
		$min      = (int) HWCH_Settings::get( 'short_words' );
		$out      = array( 'desc' => array(), 'titles' => array(), 'image' => array(), 'short' => array(), 'noindex' => array() );
		$titles   = array();

		foreach ( $ids as $id ) {
			if ( $desc_key && '' === trim( (string) get_post_meta( $id, $desc_key, true ) ) ) {
				$out['desc'][] = $id;
			}

			// Yoast and Rank Math titles can contain variables such as
			// %%sitename%%; the comparison uses the written part.
			$title = $seo_key ? (string) get_post_meta( $id, $seo_key, true ) : '';
			$title = '' === trim( $title ) ? get_post_field( 'post_title', $id, 'raw' ) : $title;
			$key   = trim( preg_replace( '/\s+/', ' ', strtolower( preg_replace( '/%%[^%]+%%|%[a-z_]+%/i', '', $title ) ) ) );

			if ( '' !== $key ) {
				$titles[ $key ][] = $id;
			}

			if ( ! has_post_thumbnail( $id ) ) {
				$out['image'][] = $id;
			}

			$words = str_word_count( wp_strip_all_tags( strip_shortcodes( (string) get_post_field( 'post_content', $id, 'raw' ) ) ) );

			if ( $words < $min ) {
				$out['short'][ $id ] = $words;
			}

			if ( self::is_noindex( $id ) ) {
				$out['noindex'][] = $id;
			}
		}

		$out['titles'] = array_values(
			array_filter(
				$titles,
				function ( $group ) {
					return count( $group ) > 1;
				}
			)
		);

		asort( $out['short'] );

		set_transient( 'hwch_onpage', $out, HOUR_IN_SECONDS );

		return $out;
	}

	/**
	 * Renders the tab.
	 *
	 * @return void
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification
		$view    = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'desc';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$refresh = isset( $_GET['refresh'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['refresh'] ) ), 'hwch_refresh' );
		// phpcs:enable

		$data  = self::data( $refresh );
		$views = array(
			'desc'    => __( 'No meta description', 'hw-content-health' ),
			'titles'  => __( 'Duplicate SEO titles', 'hw-content-health' ),
			'image'   => __( 'No featured image', 'hw-content-health' ),
			'short'   => __( 'Short articles', 'hw-content-health' ),
			'noindex' => __( 'Set to noindex', 'hw-content-health' ),
		);

		if ( ! self::desc_key() ) {
			unset( $views['desc'], $views['noindex'] );
		}

		$view = isset( $views[ $view ] ) ? $view : (string) key( $views );
		?>
		<ul class="subsubsub hwch-filter">
			<?php $i = 0; ?>
			<?php foreach ( $views as $key => $label ) : ?>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=hwch&tab=onpage&view=' . $key ) ); ?>" class="<?php echo $view === $key ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( count( $data[ $key ] ) ) ); ?>)</span></a><?php echo ++$i < count( $views ) ? ' |' : ''; ?></li>
			<?php endforeach; ?>
		</ul>
		<div class="clear"></div>
		<?php
		$intros = array(
			'desc'    => __( 'English articles with no meta description, so Google writes its own snippet from the page. Write one here; it saves to the SEO plugin and can be undone from the Log.', 'hw-content-health' ),
			'titles'  => __( 'English articles sharing the same SEO title (or post title, where no SEO title is set). Identical titles make them compete in search and look like duplicates. Edit them here.', 'hw-content-health' ),
			'image'   => __( 'English articles with no featured image, which shows on social shares, in Google Discover and in the blog\'s own listings.', 'hw-content-health' ),
			/* translators: %s: words */
			'short'   => sprintf( __( 'English articles under %s words. Short is not always a problem, but these are the likeliest thin pages. Shortest first.', 'hw-content-health' ), number_format_i18n( HWCH_Settings::get( 'short_words' ) ) ),
			'noindex' => __( 'Published English articles set to noindex, so they cannot appear in Google. Usually a leftover from a draft or test; check each is meant to be hidden.', 'hw-content-health' ),
		);
		?>
		<p class="hwch-intro">
			<?php echo esc_html( $intros[ $view ] ); ?>
			<?php esc_html_e( 'Checked every hour;', 'hw-content-health' ); ?>
			<a href="<?php echo esc_url( add_query_arg( 'refresh', wp_create_nonce( 'hwch_refresh' ) ) ); ?>"><?php esc_html_e( 'check now', 'hw-content-health' ); ?></a>.
		</p>
		<?php
		if ( ! $data[ $view ] ) {
			echo '<p class="hwch-empty">' . esc_html__( 'None found.', 'hw-content-health' ) . '</p>';
			return;
		}

		if ( 'titles' === $view ) {
			self::view_titles( $data['titles'] );
			return;
		}

		$ids   = 'short' === $view ? array_keys( $data['short'] ) : $data[ $view ];
		$total = count( $ids );
		$ids   = array_slice( $ids, ( $paged - 1 ) * HWCH_Export::per( 50 ), HWCH_Export::per( 50 ) );
		?>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Article', 'hw-content-health' ); ?></th>
					<?php if ( 'short' === $view ) : ?>
						<th><?php esc_html_e( 'Words', 'hw-content-health' ); ?></th>
					<?php endif; ?>
					<?php if ( 'desc' === $view ) : ?>
						<th><?php esc_html_e( 'Meta description', 'hw-content-health' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $ids as $id ) : ?>
					<tr>
						<td><?php self::article( $id ); ?></td>
						<?php if ( 'short' === $view ) : ?>
							<td><?php echo esc_html( number_format_i18n( $data['short'][ $id ] ) ); ?></td>
						<?php endif; ?>
						<?php if ( 'desc' === $view ) : ?>
							<td class="hwch-seo">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'hwch_action' ); ?>
									<input type="hidden" name="action" value="hwch_meta_desc">
									<input type="hidden" name="post_id" value="<?php echo esc_attr( $id ); ?>">
									<textarea name="meta_desc" rows="2" maxlength="320" placeholder="<?php esc_attr_e( 'About 150 characters describing the article', 'hw-content-health' ); ?>"></textarea>
									<button class="button button-small"><?php esc_html_e( 'Save', 'hw-content-health' ); ?></button>
								</form>
							</td>
						<?php endif; ?>
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

	/**
	 * Groups of articles sharing a title.
	 *
	 * @param array $groups Groups of post IDs.
	 * @return void
	 */
	private static function view_titles( $groups ) {
		$seo_key = HWCH_Overlap::seo_title_key();

		foreach ( array_slice( $groups, 0, HWCH_Export::per( 100 ) ) as $g => $group ) {
			echo '<div class="hwch-group"><table class="widefat hwch-table"><tbody>';

			foreach ( $group as $id ) {
				$value = $seo_key ? (string) get_post_meta( $id, $seo_key, true ) : get_post_field( 'post_title', $id, 'raw' );
				?>
				<tr>
					<td><?php self::article( $id ); ?></td>
					<td class="hwch-seo">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'hwch_action' ); ?>
							<input type="hidden" name="action" value="hwch_seo_title">
							<input type="hidden" name="post_id" value="<?php echo esc_attr( $id ); ?>">
							<input type="hidden" name="return_tab" value="onpage">
							<input type="text" name="seo_title" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( get_post_field( 'post_title', $id, 'raw' ) ); ?>">
							<button class="button button-small"><?php esc_html_e( 'Save', 'hw-content-health' ); ?></button>
						</form>
					</td>
				</tr>
				<?php
			}

			echo '</tbody></table></div>';
		}
	}

	/**
	 * An article's title and address.
	 *
	 * @param int $id Post ID.
	 * @return void
	 */
	private static function article( $id ) {
		printf(
			'<strong><a href="%1$s">%2$s</a></strong><div class="hwch-url"><a href="%3$s" target="_blank" rel="noopener">%4$s</a></div>',
			esc_url( get_edit_post_link( $id ) ),
			esc_html( get_the_title( $id ) ),
			esc_url( get_permalink( $id ) ),
			esc_html( rawurldecode( (string) wp_parse_url( get_permalink( $id ), PHP_URL_PATH ) ) )
		);
	}
}
