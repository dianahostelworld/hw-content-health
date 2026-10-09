<?php
/**
 * Retire articles: find every published article with a phrase in its title or
 * address (such as "best hostels in") and redirect them in bulk.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds articles by phrase and redirects the ticked ones, each to its own
 * target: its destination hub by default, else the blog homepage.
 */
class HWCH_Retire {

	const DEFAULT_PHRASE = 'best hostels in';

	/**
	 * Published articles whose title or address contains the phrase.
	 *
	 * @param string $phrase Phrase.
	 * @return int[]
	 */
	public static function find( $phrase ) {
		global $wpdb;

		$phrase = trim( $phrase );

		if ( '' === $phrase ) {
			return array();
		}

		$slug = sanitize_title( $phrase );

		return array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND ( LOWER(post_title) LIKE %s OR post_name LIKE %s ) ORDER BY post_title",
					'%' . $wpdb->esc_like( strtolower( $phrase ) ) . '%',
					'%' . $wpdb->esc_like( $slug ) . '%'
				)
			)
		);
	}

	/**
	 * Where an article should go: the hub of its most specific destination,
	 * then any of its destinations, else the blog homepage.
	 *
	 * @param int $id Post ID.
	 * @return array url, why.
	 */
	public static function target( $id ) {
		$places = HWCH_Overlap::places( $id );

		foreach ( array_unique( array_merge( HWCH_Overlap::specific( $places ), $places ) ) as $slug ) {
			$hub = HWCH_Broken::hub_for( $slug );

			if ( $hub ) {
				return array( 'url' => $hub, 'why' => 'hub' );
			}
		}

		return array( 'url' => home_url( '/' ), 'why' => 'home' );
	}

	/**
	 * Search Console figures for some articles.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array id => clicks, impressions.
	 */
	private static function metrics( $ids ) {
		global $wpdb;

		if ( ! $ids ) {
			return array();
		}

		$out  = array();
		$rows = $wpdb->get_results( 'SELECT post_id, clicks, impressions FROM ' . HWCH_Store::table( 'metrics' ) . ' WHERE post_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB

		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['post_id'] ] = $r;
		}

		return $out;
	}

	/**
	 * The page.
	 *
	 * @return void
	 */
	public static function render() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$phrase = isset( $_GET['phrase'] ) ? sanitize_text_field( wp_unslash( $_GET['phrase'] ) ) : self::DEFAULT_PHRASE;
		$ids    = self::find( $phrase );
		$m      = self::metrics( $ids );
		?>
		<p class="hwch-intro"><?php esc_html_e( 'Find every published article with a phrase in its title or address, and redirect the ones that should go. Each target is the article\'s destination hub when it has one, otherwise the blog homepage; change any of them before redirecting. Redirected articles get a 301 and move to draft, exactly as in Candidates to prune, and each one can be undone from the Log.', 'hw-content-health' ); ?></p>

		<form method="get" class="hwch-retire-search">
			<input type="hidden" name="page" value="hwch">
			<input type="hidden" name="tab" value="retire">
			<label for="hwch-phrase"><?php esc_html_e( 'Title or address contains', 'hw-content-health' ); ?></label>
			<input id="hwch-phrase" name="phrase" value="<?php echo esc_attr( $phrase ); ?>" class="regular-text">
			<button class="button"><?php esc_html_e( 'Find', 'hw-content-health' ); ?></button>
		</form>

		<p class="hwch-count">
			<?php
			/* translators: 1: number, 2: phrase */
			echo esc_html( sprintf( _n( '%1$s published article with "%2$s"', '%1$s published articles with "%2$s"', count( $ids ), 'hw-content-health' ), number_format_i18n( count( $ids ) ), $phrase ) );
			?>
		</p>

		<?php if ( ! $ids ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'No published articles match.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="hwch-retire" onsubmit="var n=this.querySelectorAll('input[name=&quot;ids[]&quot;]:checked').length; if(!n){alert('<?php echo esc_js( __( 'Tick at least one article.', 'hw-content-health' ) ); ?>');return false;} return confirm('<?php echo esc_js( __( 'Redirect the ticked articles and move them to draft? Each one can be undone from the Log.', 'hw-content-health' ) ); ?>'.replace('ticked', n + ' ticked'));">
			<?php wp_nonce_field( 'hwch_action' ); ?>
			<input type="hidden" name="action" value="hwch_retire">
			<input type="hidden" name="phrase" value="<?php echo esc_attr( $phrase ); ?>">
			<table class="widefat striped hwch-table">
				<thead>
					<tr>
						<td class="check-column"><label class="screen-reader-text" for="hwch-all"><?php esc_html_e( 'Select all', 'hw-content-health' ); ?></label><input type="checkbox" id="hwch-all" onclick="document.querySelectorAll('#hwch-retire input[name=&quot;ids[]&quot;]').forEach(function(c){c.checked=this.checked;}.bind(this));"></td>
						<th><?php esc_html_e( 'Article', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Clicks', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Impressions', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Backlinks', 'hw-content-health' ); ?></th>
						<th><?php esc_html_e( 'Redirect to', 'hw-content-health' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ids as $id ) : ?>
						<?php $t = self::target( $id ); ?>
						<tr>
							<th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( $id ); ?>" id="hwch-r-<?php echo esc_attr( $id ); ?>"></th>
							<td>
								<label for="hwch-r-<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( get_the_title( $id ) ); ?></strong></label>
								<div class="hwch-url"><a href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( rawurldecode( (string) wp_parse_url( get_permalink( $id ), PHP_URL_PATH ) ) ); ?></a> · <a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit', 'hw-content-health' ); ?></a></div>
							</td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $m[ $id ]['clicks'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $m[ $id ]['impressions'] ?? 0 ) ) ); ?></td>
							<td><?php echo HWCH_Ahrefs::links_cell( $id ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td>
								<label class="screen-reader-text" for="hwch-t-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Redirect to', 'hw-content-health' ); ?></label>
								<input type="text" class="regular-text" id="hwch-t-<?php echo esc_attr( $id ); ?>" name="target[<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_attr( $t['url'] ); ?>">
								<div class="hwch-muted"><?php echo 'hub' === $t['why'] ? esc_html__( 'Its destination hub', 'hw-content-health' ) : esc_html__( 'No hub found: blog homepage', 'hw-content-health' ); ?></div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><button class="button button-primary"><?php esc_html_e( 'Redirect selected', 'hw-content-health' ); ?></button></p>
		</form>
		<?php
	}
}
