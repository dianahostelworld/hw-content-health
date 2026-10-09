<?php
/**
 * Actions taken from the reports. Every change is logged and can be undone,
 * and nothing is deleted: pruned articles are unpublished to drafts.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the admin-post requests from the report screens.
 */
class HWCH_Actions {

	const CAP = 'edit_others_posts';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		foreach ( array( 'sync', 'queue', 'dismiss', 'unpublish', 'redirect', 'undo', 'merge', 'seo_title', 'fine', 'refreshed', 'add_tag', 'merge_tags', 'post_title', 'meta_desc', 'season_fetch', 'check_links', 'redirect_404', 'ignore_404', 'fix_chain', 'fix_link', 'plan_add', 'plan_update', 'plan_delete', 'plan_draft', 'gap_dismiss', 'remove_links', 'retire' ) as $action ) {
			add_action( 'admin_post_hwch_' . $action, array( __CLASS__, $action ) );
		}

		add_action( 'post_updated', array( __CLASS__, 'detect_refresh' ), 10, 3 );
	}

	/**
	 * Checks the nonce and capability, and returns the article ID.
	 *
	 * @param string $cap Capability needed.
	 * @return int
	 */
	private static function guard( $cap = self::CAP ) {
		check_admin_referer( 'hwch_action' );

		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'hw-content-health' ), 403 );
		}

		return isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Goes back to the report with a message.
	 *
	 * @param string $message Message key.
	 * @param string $tab     Tab to return to.
	 * @return void
	 */
	private static function back( $message, $tab = '' ) {
		$referer = wp_get_referer();
		$url     = $referer ? $referer : admin_url( 'admin.php?page=hwch' );

		if ( $tab ) {
			$url = add_query_arg( 'tab', $tab, $url );
		}

		wp_safe_redirect( add_query_arg( 'hwch_msg', $message, remove_query_arg( 'hwch_msg', $url ) ) );
		exit;
	}

	/**
	 * Adds a log entry.
	 *
	 * @param int    $post_id Article.
	 * @param string $action  Action name.
	 * @param array  $details What is needed to undo it.
	 * @return void
	 */
	public static function log( $post_id, $action, $details = array() ) {
		global $wpdb;

		// The to-do counts change with every action.
		delete_transient( 'hwch_todo_counts' );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			HWCH_Store::table( 'log' ),
			array(
				'post_id'    => $post_id,
				'action'     => $action,
				'details'    => wp_json_encode( $details ),
				'user_id'    => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Runs the Search Console sync now.
	 *
	 * @return void
	 */
	public static function sync() {
		self::guard( 'manage_options' );

		$result = HWCH_Store::sync();

		self::back( is_wp_error( $result ) ? 'sync_failed' : 'synced' );
	}

	/**
	 * Marks an article as queued for a refresh, which keeps it visible but
	 * flags it so the team does not pick it up twice.
	 *
	 * @return void
	 */
	public static function queue() {
		$post_id = self::guard();

		if ( get_post( $post_id ) ) {
			update_post_meta( $post_id, '_hwch_queued', time() );
			self::log( $post_id, 'queue' );
		}

		self::back( 'queued' );
	}

	/**
	 * Hides an article from one report for six months.
	 *
	 * @return void
	 */
	public static function dismiss() {
		$post_id = self::guard();
		$report  = isset( $_POST['report'] ) && in_array( $_POST['report'], array( 'prune', 'dated', 'seasonal', 'questions', 'aio' ), true ) ? sanitize_key( $_POST['report'] ) : 'refresh'; // phpcs:ignore WordPress.Security.NonceVerification

		if ( get_post( $post_id ) ) {
			update_post_meta( $post_id, '_hwch_dismissed_' . $report, strtotime( '+6 months' ) );
			self::log( $post_id, 'dismiss', array( 'report' => $report ) );
		}

		self::back( 'dismissed' );
	}

	/**
	 * Unpublishes an article (moves it to draft) without a redirect.
	 *
	 * @return void
	 */
	public static function unpublish() {
		$post_id = self::guard();
		$post    = get_post( $post_id );

		if ( $post && 'publish' === $post->post_status ) {
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
			self::log( $post_id, 'unpublish', array( 'status' => 'publish', 'url' => get_permalink( $post_id ) ) );
		}

		self::back( 'unpublished' );
	}

	/**
	 * Redirects an article to another page with a 301, then unpublishes it.
	 * The target can be a full URL on this site or a post ID.
	 *
	 * @return void
	 */
	public static function redirect() {
		$post_id = self::guard();
		$raw     = isset( $_POST['target'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['target'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( '' === $raw ) {
			self::back( 'redirect_missing' );
		}

		$target = ctype_digit( $raw ) ? get_permalink( (int) $raw ) : esc_url_raw( $raw );

		self::back( self::redirect_post( $post_id, (string) $target ) ? 'redirected' : 'redirect_invalid' );
	}

	/**
	 * Keeps one article from an overlapping group and redirects the chosen
	 * others to it. Each redirect is logged separately, so any one of them can
	 * be undone on its own.
	 *
	 * @return void
	 */
	public static function merge() {
		self::guard();

		// phpcs:disable WordPress.Security.NonceVerification
		$keeper = isset( $_POST['keeper'] ) ? absint( $_POST['keeper'] ) : 0;
		$others = isset( $_POST['redirect'] ) ? array_map( 'absint', (array) $_POST['redirect'] ) : array();
		// phpcs:enable

		$others = array_diff( $others, array( $keeper ) );

		if ( ! $keeper || 'publish' !== get_post_status( $keeper ) || ! $others ) {
			self::back( 'merge_missing', 'overlap' );
		}

		$target = get_permalink( $keeper );
		$done   = 0;

		foreach ( $others as $post_id ) {
			if ( self::redirect_post( $post_id, $target ) ) {
				++$done;
			}
		}

		self::back( $done ? 'merged' : 'redirect_invalid', 'overlap' );
	}

	/**
	 * The redirect itself: validates the target, adds the 301, moves the
	 * article to draft and logs it.
	 *
	 * @param int    $post_id Article to redirect.
	 * @param string $target  URL on this site.
	 * @return bool Whether it was redirected.
	 */
	private static function redirect_post( $post_id, $target ) {
		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}

		// Only redirect within this site, and never to itself.
		$target = $target ? wp_validate_redirect( $target, '' ) : '';
		$source = get_permalink( $post_id );

		if ( ! $target || HWCH_Redirects::path( $target ) === HWCH_Redirects::path( $source ) ) {
			return false;
		}

		$redirect_id = HWCH_Redirects::add( $source, $target, $post_id );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );

		self::log(
			$post_id,
			'redirect',
			array(
				'status'      => 'publish',
				'url'         => $source,
				'target'      => $target,
				'redirect_id' => $redirect_id,
			)
		);

		return true;
	}

	/**
	 * Changes an article's SEO title: the Yoast or Rank Math title if one of
	 * them is active, otherwise the post title itself.
	 *
	 * @return void
	 */
	public static function seo_title() {
		$post_id = self::guard();
		$title   = isset( $_POST['seo_title'] ) ? sanitize_text_field( wp_unslash( $_POST['seo_title'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$key     = HWCH_Overlap::seo_title_key();

		if ( ! get_post( $post_id ) || '' === $title ) {
			self::back( 'title_missing', self::return_tab() );
		}

		if ( $key ) {
			$previous = (string) get_post_meta( $post_id, $key, true );
			update_post_meta( $post_id, $key, $title );
		} else {
			$previous = get_post_field( 'post_title', $post_id, 'raw' );
			wp_update_post( array( 'ID' => $post_id, 'post_title' => $title ) );
		}

		delete_transient( 'hwch_onpage' );

		self::log(
			$post_id,
			'seo_title',
			array(
				'field'    => $key ? $key : 'post_title',
				'previous' => $previous,
				'title'    => $title,
			)
		);

		self::back( 'title_saved', self::return_tab() );
	}

	/**
	 * The tab a form asked to return to.
	 *
	 * @return string
	 */
	private static function return_tab() {
		$tab = isset( $_POST['return_tab'] ) ? sanitize_key( $_POST['return_tab'] ) : 'overlap'; // phpcs:ignore WordPress.Security.NonceVerification
		return $tab ? $tab : 'overlap';
	}

	/**
	 * Marks a queued article as refreshed now, for when the update was made
	 * without editing the post (or before it was queued).
	 *
	 * @return void
	 */
	public static function refreshed() {
		$post_id = self::guard();

		if ( get_post( $post_id ) && ! get_post_meta( $post_id, '_hwch_refreshed', true ) ) {
			update_post_meta( $post_id, '_hwch_refreshed', time() );
			delete_transient( 'hwch_impact' );
			self::log( $post_id, 'refreshed', array( 'auto' => false ) );
		}

		self::back( 'refreshed' );
	}

	/**
	 * When a published article that was queued for a refresh is updated, the
	 * first update counts as the refresh, and its date starts the impact
	 * comparison.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $after   Post after the update.
	 * @param WP_Post $before  Post before the update.
	 * @return void
	 */
	public static function detect_refresh( $post_id, $after, $before ) {
		if ( 'post' !== $after->post_type || 'publish' !== $after->post_status || 'publish' !== $before->post_status ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( get_post_meta( $post_id, '_hwch_queued', true ) && ! get_post_meta( $post_id, '_hwch_refreshed', true ) ) {
			update_post_meta( $post_id, '_hwch_refreshed', time() );
			delete_transient( 'hwch_impact' );
			self::log( $post_id, 'refreshed', array( 'auto' => true ) );
		}
	}

	/**
	 * Adds a destination tag to an article.
	 *
	 * @return void
	 */
	public static function add_tag() {
		$post_id = self::guard();
		$slug    = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$term    = $slug ? get_term_by( 'slug', $slug, 'post_tag' ) : null;

		if ( get_post( $post_id ) && $term && ! is_wp_error( $term ) ) {
			wp_set_post_terms( $post_id, array( (int) $term->term_id ), 'post_tag', true );
			self::log( $post_id, 'add_tag', array( 'term_id' => (int) $term->term_id, 'name' => $term->name ) );
		}

		self::back( 'tag_added', 'tags' );
	}

	/**
	 * Moves every article from duplicate tags onto the tag being kept. The
	 * emptied tags are not deleted, so their archive pages and settings stay
	 * until someone removes them in Posts > Tags.
	 *
	 * @return void
	 */
	public static function merge_tags() {
		self::guard();

		// phpcs:disable WordPress.Security.NonceVerification
		$keep = isset( $_POST['keep'] ) ? absint( $_POST['keep'] ) : 0;
		$from = isset( $_POST['from'] ) ? array_map( 'absint', (array) $_POST['from'] ) : array();
		// phpcs:enable

		$from = array_diff( $from, array( $keep ) );
		$kept = get_term( $keep, 'post_tag' );

		if ( ! $kept || is_wp_error( $kept ) || ! $from ) {
			self::back( 'merge_tags_missing', 'tags' );
		}

		foreach ( $from as $term_id ) {
			$posts = array_map( 'intval', (array) get_objects_in_term( $term_id, 'post_tag' ) );
			$had   = array();

			foreach ( $posts as $post_id ) {
				if ( has_term( $keep, 'post_tag', $post_id ) ) {
					$had[] = $post_id;
				} else {
					wp_set_post_terms( $post_id, array( $keep ), 'post_tag', true );
				}

				wp_remove_object_terms( $post_id, $term_id, 'post_tag' );
			}

			$old = get_term( $term_id, 'post_tag' );

			self::log(
				$keep,
				'merge_tags',
				array(
					'keep'      => $keep,
					'keep_name' => $kept->name,
					'from'      => $term_id,
					'from_name' => $old && ! is_wp_error( $old ) ? $old->name : '',
					'posts'     => $posts,
					'had'       => $had,
				)
			);
		}

		self::back( 'tags_merged', 'tags' );
	}

	/**
	 * Changes an article's post title.
	 *
	 * @return void
	 */
	public static function post_title() {
		$post_id = self::guard();
		$title   = isset( $_POST['post_title'] ) ? sanitize_text_field( wp_unslash( $_POST['post_title'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! get_post( $post_id ) || '' === $title ) {
			self::back( 'title_missing', self::return_tab() );
		}

		$previous = get_post_field( 'post_title', $post_id, 'raw' );
		wp_update_post( array( 'ID' => $post_id, 'post_title' => $title ) );
		self::log( $post_id, 'seo_title', array( 'field' => 'post_title', 'previous' => $previous, 'title' => $title ) );

		self::back( 'title_saved', self::return_tab() );
	}

	/**
	 * Saves a meta description to Yoast or Rank Math.
	 *
	 * @return void
	 */
	public static function meta_desc() {
		$post_id = self::guard();
		$desc    = isset( $_POST['meta_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['meta_desc'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$key     = HWCH_Onpage::desc_key();

		if ( ! $key || ! get_post( $post_id ) || '' === trim( $desc ) ) {
			self::back( 'desc_missing', 'onpage' );
		}

		$previous = (string) get_post_meta( $post_id, $key, true );
		update_post_meta( $post_id, $key, $desc );
		delete_transient( 'hwch_onpage' );
		self::log( $post_id, 'meta_desc', array( 'field' => $key, 'previous' => $previous, 'desc' => $desc ) );

		self::back( 'desc_saved', 'onpage' );
	}

	/**
	 * Marks every pair in a group as fine: the articles are meant to be
	 * separate, so the group stops appearing.
	 *
	 * @return void
	 */
	public static function fine() {
		self::guard();

		$ids = isset( $_POST['posts'] ) ? array_map( 'absint', (array) $_POST['posts'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		sort( $ids );

		$fine = (array) get_option( 'hwch_fine_pairs', array() );
		$keys = array();
		$n    = count( $ids );

		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$key          = $ids[ $i ] . '-' . $ids[ $j ];
				$fine[ $key ] = time();
				$keys[]       = $key;
			}
		}

		update_option( 'hwch_fine_pairs', $fine, false );

		if ( $ids ) {
			self::log( $ids[0], 'fine', array( 'pairs' => $keys, 'posts' => $ids ) );
		}

		self::back( 'fine', 'overlap' );
	}

	/**
	 * Fetches the monthly figures the Seasonal planner is still missing.
	 *
	 * @return void
	 */
	public static function season_fetch() {
		self::guard( 'manage_options' );

		$result = HWCH_Seasonal::build( HWCH_Store::url_map() );

		if ( is_wp_error( $result ) ) {
			update_option( 'hwch_last_error', $result->get_error_message(), false );
		}

		self::back( is_wp_error( $result ) ? 'months_failed' : 'months_fetched', 'seasonal' );
	}

	/**
	 * Checks the next batch of outbound links now, rather than waiting for
	 * the hourly job.
	 *
	 * @return void
	 */
	public static function check_links() {
		self::guard();

		self::back( HWCH_Broken::check_batch() ? 'links_checked' : 'links_none' );
	}

	/**
	 * A 404 address, from the id posted by the 404 list.
	 *
	 * @return array|null
	 */
	private static function notfound_row() {
		global $wpdb;

		$id = isset( $_POST['notfound_id'] ) ? absint( $_POST['notfound_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . HWCH_Store::table( 'notfound' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Redirects a 404 address to a page on this site with a 301.
	 *
	 * @return void
	 */
	public static function redirect_404() {
		global $wpdb;

		self::guard();

		$row = self::notfound_row();
		$raw = isset( $_POST['target'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['target'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $row || '' === $raw ) {
			self::back( 'redirect_missing' );
		}

		$target = ctype_digit( $raw ) ? (string) get_permalink( (int) $raw ) : esc_url_raw( 0 === strpos( $raw, '/' ) ? HWCH_Broken::origin( $raw ) : $raw );
		$target = $target ? wp_validate_redirect( $target, '' ) : '';

		if ( ! $target || HWCH_Redirects::path( $target ) === $row['path'] ) {
			self::back( 'redirect_invalid' );
		}

		$redirect_id = HWCH_Redirects::add( $row['path'], $target, 0 );
		$wpdb->update( HWCH_Store::table( 'notfound' ), array( 'ignored' => 2 ), array( 'id' => (int) $row['id'] ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		self::log( 0, 'redirect_404', array( 'path' => $row['path'], 'target' => $target, 'redirect_id' => $redirect_id, 'notfound_id' => (int) $row['id'] ) );

		self::back( 'redirected_404' );
	}

	/**
	 * Hides a 404 address from the list.
	 *
	 * @return void
	 */
	public static function ignore_404() {
		global $wpdb;

		self::guard();

		$row = self::notfound_row();

		if ( $row ) {
			$wpdb->update( HWCH_Store::table( 'notfound' ), array( 'ignored' => 1 ), array( 'id' => (int) $row['id'] ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::log( 0, 'ignore_404', array( 'path' => $row['path'], 'notfound_id' => (int) $row['id'] ) );
		}

		self::back( 'ignored_404' );
	}

	/**
	 * Points a redirect straight at the page its chain finally lands on.
	 *
	 * @return void
	 */
	public static function fix_chain() {
		global $wpdb;

		self::guard();

		$id    = isset( $_POST['redirect_id'] ) ? absint( $_POST['redirect_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$table = HWCH_Store::table( 'redirects' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$final = $row ? HWCH_Broken::follow( $row['source'] ) : null;

		if ( ! $row || ! $final || $final['final'] === $row['target'] || HWCH_Redirects::path( $final['final'] ) === $row['source'] ) {
			self::back( 'redirect_invalid' );
		}

		$wpdb->update( $table, array( 'target' => $final['final'] ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::log( (int) $row['post_id'], 'fix_chain', array( 'redirect_id' => $id, 'source' => $row['source'], 'previous' => $row['target'], 'target' => $final['final'], 'path' => $row['source'] ) );

		self::back( 'chain_fixed' );
	}

	/**
	 * Replaces a link in one article's content with the address it should
	 * point to. The previous content is kept in the Log so Undo can put it
	 * back, as long as the article has not been edited since.
	 *
	 * @return void
	 */
	public static function fix_link() {
		$post_id = self::guard();

		// phpcs:disable WordPress.Security.NonceVerification
		$old = isset( $_POST['old'] ) ? esc_url_raw( wp_unslash( $_POST['old'] ) ) : '';
		$new = isset( $_POST['new'] ) ? esc_url_raw( wp_unslash( $_POST['new'] ) ) : '';
		// phpcs:enable

		$post = get_post( $post_id );
		$new  = $new ? wp_validate_redirect( $new, '' ) : '';

		if ( ! $post || ! $old || ! $new || ! current_user_can( 'edit_post', $post_id ) ) {
			self::back( 'redirect_invalid' );
		}

		$result = HWCH_Broken::replace_link( $post->post_content, $old, $new );

		if ( ! $result['count'] ) {
			self::back( 'link_not_found' );
		}

		$previous = $post->post_content;

		wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $result['content'] ) ) );

		self::log(
			$post_id,
			'fix_link',
			array(
				'old'      => $old,
				'new'      => $new,
				'count'    => $result['count'],
				'previous' => base64_encode( gzcompress( $previous ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				'after'    => md5( (string) get_post_field( 'post_content', $post_id, 'raw' ) ),
			)
		);

		delete_transient( 'hwch_broken_scan' );
		delete_transient( 'hwch_broken_internal' );
		delete_transient( 'hwch_links' );

		self::back( 'link_fixed' );
	}

	/**
	 * Removes broken links from articles, keeping the linked words: one
	 * address (url), or every removable one on a tab. Each changed article is
	 * logged with its previous text, so it can be undone.
	 *
	 * @return void
	 */
	public static function remove_links() {
		self::guard();

		// phpcs:disable WordPress.Security.NonceVerification
		$view = isset( $_POST['view'] ) && 'outbound' === $_POST['view'] ? 'outbound' : 'internal';
		$one  = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		// phpcs:enable

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		// Group by article, so each article is saved and logged once.
		$by_post = array();

		foreach ( HWCH_Broken::removable( $view ) as $row ) {
			if ( $one && HWCH_GSC::normalise( $one ) !== HWCH_GSC::normalise( $row['url'] ) ) {
				continue;
			}
			foreach ( $row['sources'] as $id ) {
				$by_post[ $id ][] = $row['url'];
			}
		}

		$links = 0;
		$posts = 0;

		foreach ( $by_post as $post_id => $urls ) {
			$post = get_post( $post_id );

			if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			$content = $post->post_content;
			$n       = 0;

			foreach ( array_unique( $urls ) as $url ) {
				$r       = HWCH_Broken::unlink( $content, $url );
				$content = $r['content'];
				$n      += $r['count'];
			}

			if ( ! $n ) {
				continue;
			}

			wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $content ) ) );
			self::log(
				$post_id,
				'remove_link',
				array(
					'urls'     => array_values( array_unique( $urls ) ),
					'count'    => $n,
					'previous' => base64_encode( gzcompress( $post->post_content ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
					'after'    => md5( (string) get_post_field( 'post_content', $post_id, 'raw' ) ),
				)
			);
			$links += $n;
			$posts++;
		}

		delete_transient( 'hwch_broken_scan' );
		delete_transient( 'hwch_broken_internal' );
		delete_transient( 'hwch_links' );
		set_transient( 'hwch_removed_' . get_current_user_id(), array( $links, $posts ), 120 );

		wp_safe_redirect( admin_url( 'admin.php?page=hwch&tab=broken&view=' . $view . '&hwch_msg=links_removed' ) );
		exit;
	}

	/**
	 * Redirects the ticked articles from Retire articles, each to its own
	 * target, and moves them to draft. Each can be undone from the Log.
	 *
	 * @return void
	 */
	public static function retire() {
		self::guard();

		// phpcs:disable WordPress.Security.NonceVerification
		$ids     = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
		$targets = isset( $_POST['target'] ) ? (array) wp_unslash( $_POST['target'] ) : array();
		$phrase  = isset( $_POST['phrase'] ) ? sanitize_text_field( wp_unslash( $_POST['phrase'] ) ) : '';
		// phpcs:enable

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$done = 0;
		$bad  = 0;

		foreach ( $ids as $id ) {
			$target = isset( $targets[ $id ] ) ? esc_url_raw( trim( (string) $targets[ $id ] ) ) : '';
			$target = $target && 0 === strpos( $target, '/' ) ? home_url( $target ) : $target;

			if ( self::redirect_post( $id, $target ) ) {
				$done++;
			} else {
				$bad++;
			}
		}

		delete_transient( 'hwch_onpage' );
		set_transient( 'hwch_retired_' . get_current_user_id(), array( $done, $bad ), 120 );

		wp_safe_redirect( admin_url( 'admin.php?page=hwch&tab=retire&hwch_msg=retired&phrase=' . rawurlencode( $phrase ) ) );
		exit;
	}

	/**
	 * Adds an article to the content plan: a gap (destination and topic,
	 * with its evidence) or an idea typed in.
	 *
	 * @return void
	 */
	public static function plan_add() {
		global $wpdb;

		self::guard();

		// phpcs:disable WordPress.Security.NonceVerification
		$dest  = isset( $_POST['destination'] ) ? sanitize_title( wp_unslash( $_POST['destination'] ) ) : '';
		$topic = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$due   = isset( $_POST['due'] ) && preg_match( '/^\d{4}-\d{2}$/', sanitize_text_field( wp_unslash( $_POST['due'] ) ) ) ? sanitize_text_field( wp_unslash( $_POST['due'] ) ) . '-01' : null;
		// phpcs:enable

		$evidence = null;
		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		// A refresh of an existing article, from its refresh brief.
		if ( 'refresh' === $topic && $post_id && get_post( $post_id ) ) {
			$profiles = HWCH_Seasonal::profiles();
			$due      = null;

			if ( isset( $profiles[ $post_id ] ) && HWCH_Seasonal::is_seasonal( $profiles[ $post_id ] ) ) {
				$timing = HWCH_Seasonal::timing( $profiles[ $post_id ] );
				$due    = $timing['in_season'] ? null : gmdate( 'Y-m-01', $timing['due'] );
			}

			$places = HWCH_Overlap::specific( HWCH_Overlap::places( $post_id ) );

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				HWCH_Store::table( 'plan' ),
				array(
					/* translators: %s: title */
					'title'       => sprintf( __( 'Refresh: %s', 'hw-content-health' ), get_the_title( $post_id ) ),
					'destination' => $places ? $places[0] : '',
					'topic'       => 'refresh',
					'notes'       => '',
					'due'         => $due,
					'status'      => 'idea',
					'post_id'     => $post_id,
					'evidence'    => null,
					'user_id'     => get_current_user_id(),
					'created_at'  => current_time( 'mysql', true ),
				)
			);

			self::back( 'plan_added' );
		}

		if ( $topic ) {
			$gap = HWCH_Planner::find_gap( $dest, $topic );

			if ( $gap ) {
				$evidence = wp_json_encode(
					array(
						'impressions' => $gap['impressions'],
						'position'    => $gap['position'],
						'examples'    => $gap['examples'],
						'range'       => get_option( 'hwch_demand_range' ),
					)
				);
			}

			$title = $title ? $title : HWCH_Planner::working_title( $dest, $topic );

			// Seasonal destinations: default the date to the publish-by month.
			$seasons = HWCH_Planner::seasons();
			if ( ! $due && isset( $seasons[ $dest ] ) ) {
				$due = gmdate( 'Y-m-01', $seasons[ $dest ]['publish_by'] );
			}
		}

		if ( '' === $title ) {
			self::back( 'plan_missing', 'planner' );
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			HWCH_Store::table( 'plan' ),
			array(
				'title'       => $title,
				'destination' => $dest,
				'topic'       => $topic,
				'notes'       => $notes,
				'due'         => $due,
				'status'      => 'idea',
				'post_id'     => 0,
				'evidence'    => $evidence,
				'user_id'     => get_current_user_id(),
				'created_at'  => current_time( 'mysql', true ),
			)
		);

		self::back( 'plan_added' );
	}

	/**
	 * A plan item, from the id posted.
	 *
	 * @return array|null
	 */
	private static function plan_row() {
		global $wpdb;

		$id = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . HWCH_Store::table( 'plan' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Changes a plan item's status and date.
	 *
	 * @return void
	 */
	public static function plan_update() {
		global $wpdb;

		self::guard();

		$row = self::plan_row();

		if ( $row ) {
			// phpcs:disable WordPress.Security.NonceVerification
			$status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : $row['status'];
			$due    = isset( $_POST['due'] ) && preg_match( '/^\d{4}-\d{2}$/', sanitize_text_field( wp_unslash( $_POST['due'] ) ) ) ? sanitize_text_field( wp_unslash( $_POST['due'] ) ) . '-01' : null;
			// phpcs:enable

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				HWCH_Store::table( 'plan' ),
				array(
					'status' => isset( HWCH_Planner::statuses()[ $status ] ) ? $status : $row['status'],
					'due'    => $due,
				),
				array( 'id' => (int) $row['id'] )
			);
		}

		self::back( 'plan_updated' );
	}

	/**
	 * Removes a plan item. Any draft made from it is kept.
	 *
	 * @return void
	 */
	public static function plan_delete() {
		global $wpdb;

		self::guard();

		$row = self::plan_row();

		if ( $row ) {
			$wpdb->delete( HWCH_Store::table( 'plan' ), array( 'id' => (int) $row['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		self::back( 'plan_deleted' );
	}

	/**
	 * Creates the WordPress draft for a plan item, tagged with its
	 * destination, and opens it.
	 *
	 * @return void
	 */
	public static function plan_draft() {
		global $wpdb;

		self::guard();

		$row = self::plan_row();

		if ( ! $row ) {
			self::back( 'plan_missing', 'planner' );
		}

		$tags = array();

		if ( $row['destination'] && get_term_by( 'slug', $row['destination'], 'post_tag' ) ) {
			// The destination and the country and continent above it, as the
			// blog's tagging guide asks.
			foreach ( HWCH_Overlap::with_parents( array( $row['destination'] ) ) as $slug ) {
				if ( get_term_by( 'slug', $slug, 'post_tag' ) ) {
					$tags[] = $slug;
				}
			}
		}

		$post_id = wp_insert_post(
			array(
				'post_title'  => $row['title'],
				'post_status' => 'draft',
				'post_type'   => 'post',
				'tags_input'  => $tags,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			self::back( 'plan_missing', 'planner' );
		}

		$wpdb->update( HWCH_Store::table( 'plan' ), array( 'post_id' => (int) $post_id, 'status' => 'writing' ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		wp_safe_redirect( get_edit_post_link( $post_id, 'raw' ) );
		exit;
	}

	/**
	 * Hides a gap for six months.
	 *
	 * @return void
	 */
	public static function gap_dismiss() {
		self::guard();

		// phpcs:disable WordPress.Security.NonceVerification
		$dest  = isset( $_POST['destination'] ) ? sanitize_title( wp_unslash( $_POST['destination'] ) ) : '';
		$topic = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
		// phpcs:enable

		$map                              = (array) get_option( 'hwch_gap_dismissed', array() );
		$map[ md5( $dest . '|' . $topic ) ] = time() + 182 * DAY_IN_SECONDS;

		// Forget expired ones so the option stays small.
		$map = array_filter(
			$map,
			function ( $until ) {
				return $until > time();
			}
		);

		update_option( 'hwch_gap_dismissed', $map, false );

		self::back( 'gap_dismissed' );
	}

	/**
	 * Reverses a logged action.
	 *
	 * @return void
	 */
	public static function undo() {
		delete_transient( 'hwch_todo_counts' );
		global $wpdb;

		self::guard();

		$log_id = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$table  = HWCH_Store::table( 'log' );
		$entry  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND undone_at IS NULL", $log_id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		if ( ! $entry ) {
			self::back( 'undo_missing', 'log' );
		}

		$details = (array) json_decode( (string) $entry['details'], true );
		$post_id = (int) $entry['post_id'];

		switch ( $entry['action'] ) {
			case 'queue':
				delete_post_meta( $post_id, '_hwch_queued' );
				break;

			case 'dismiss':
				delete_post_meta( $post_id, '_hwch_dismissed_' . ( isset( $details['report'] ) ? $details['report'] : 'refresh' ) );
				break;

			case 'seo_title':
				if ( get_post( $post_id ) ) {
					if ( 'post_title' === $details['field'] ) {
						wp_update_post( array( 'ID' => $post_id, 'post_title' => $details['previous'] ) );
					} elseif ( '' === (string) $details['previous'] ) {
						delete_post_meta( $post_id, $details['field'] );
					} else {
						update_post_meta( $post_id, $details['field'], $details['previous'] );
					}
				}
				break;

			case 'meta_desc':
				if ( '' === (string) $details['previous'] ) {
					delete_post_meta( $post_id, $details['field'] );
				} else {
					update_post_meta( $post_id, $details['field'], $details['previous'] );
				}
				delete_transient( 'hwch_onpage' );
				break;

			case 'refreshed':
				delete_post_meta( $post_id, '_hwch_refreshed' );
				delete_transient( 'hwch_impact' );
				break;

			case 'add_tag':
				wp_remove_object_terms( $post_id, (int) $details['term_id'], 'post_tag' );
				break;

			case 'merge_tags':
				foreach ( (array) $details['posts'] as $moved ) {
					wp_set_post_terms( (int) $moved, array( (int) $details['from'] ), 'post_tag', true );

					if ( ! in_array( (int) $moved, array_map( 'intval', (array) $details['had'] ), true ) ) {
						wp_remove_object_terms( (int) $moved, (int) $details['keep'], 'post_tag' );
					}
				}
				break;

			case 'fine':
				$fine = (array) get_option( 'hwch_fine_pairs', array() );
				foreach ( (array) $details['pairs'] as $key ) {
					unset( $fine[ $key ] );
				}
				update_option( 'hwch_fine_pairs', $fine, false );
				break;

			case 'redirect_404':
				if ( ! empty( $details['redirect_id'] ) ) {
					HWCH_Redirects::remove( $details['redirect_id'] );
				}
				// Falls through to show the 404 in the list again.

			case 'ignore_404':
				if ( ! empty( $details['notfound_id'] ) ) {
					$wpdb->update( HWCH_Store::table( 'notfound' ), array( 'ignored' => 0 ), array( 'id' => (int) $details['notfound_id'] ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
				break;

			case 'fix_chain':
				$wpdb->update( HWCH_Store::table( 'redirects' ), array( 'target' => $details['previous'] ), array( 'id' => (int) $details['redirect_id'] ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				break;

			case 'fix_link':
				$current = (string) get_post_field( 'post_content', $post_id, 'raw' );

				// Only if nobody has edited the article since; otherwise their
				// later changes would be lost.
				if ( md5( $current ) !== $details['after'] ) {
					self::back( 'undo_changed', 'log' );
				}

				wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => gzuncompress( base64_decode( $details['previous'] ) ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				delete_transient( 'hwch_broken_scan' );
		delete_transient( 'hwch_broken_internal' );
				delete_transient( 'hwch_links' );
				break;

			case 'remove_link':
				$current = (string) get_post_field( 'post_content', $post_id, 'raw' );

				if ( md5( $current ) !== $details['after'] ) {
					self::back( 'undo_changed', 'log' );
				}

				wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => gzuncompress( base64_decode( $details['previous'] ) ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				delete_transient( 'hwch_broken_scan' );
				delete_transient( 'hwch_broken_internal' );
				delete_transient( 'hwch_links' );
				break;

			case 'redirect':
				if ( ! empty( $details['redirect_id'] ) ) {
					HWCH_Redirects::remove( $details['redirect_id'] );
				}
				// Falls through to restore the status.

			case 'unpublish':
				if ( get_post( $post_id ) ) {
					wp_update_post( array( 'ID' => $post_id, 'post_status' => isset( $details['status'] ) ? $details['status'] : 'publish' ) );
				}
				break;
		}

		$wpdb->update( $table, array( 'undone_at' => current_time( 'mysql', true ) ), array( 'id' => $log_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		self::back( 'undone', 'log' );
	}
}
