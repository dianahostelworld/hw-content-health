<?php
/**
 * The admin screens: the tab bar, the shared report helpers, Log and Settings.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Content health menu.
 */
class HWCH_Admin {

	const PER_PAGE = 50;

	/**
	 * Every tab the screen has.
	 *
	 * @var array
	 */
	const TABS = array( 'todo', 'dashboard', 'urls', 'planner', 'quickwins', 'questions', 'refresh', 'seasonal', 'impact', 'dated', 'prune', 'overlap', 'links', 'broken', 'onpage', 'tags', 'log', 'settings', 'aio', 'brief', 'ahrefs' );

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Adds the menu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page(
			__( 'Content health', 'hw-content-health' ),
			__( 'Content health', 'hw-content-health' ),
			HWCH_Actions::CAP,
			'hwch',
			array( __CLASS__, 'render' ),
			'dashicons-chart-line',
			26
		);
	}

	/**
	 * Registers the settings.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'hwch',
			HWCH_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'HWCH_Settings', 'sanitize' ),
			)
		);
	}

	/**
	 * Loads the stylesheet on the plugin's screen only.
	 *
	 * @param string $hook Current screen.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( 'toplevel_page_hwch' === $hook ) {
			wp_enqueue_style( 'hwch-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=Poppins:wght@800&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			wp_enqueue_style( 'hwch-admin', HWCH_URL . 'assets/admin.css', array( 'hwch-fonts' ), HWCH_VERSION );
		}
	}

	/**
	 * The current tab.
	 *
	 * @return string
	 */
	private static function tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'todo'; // phpcs:ignore WordPress.Security.NonceVerification
		return self::is_tab( $tab ) ? $tab : 'todo';
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public static function render() {
		$tab    = self::tab();
		$groups = self::menu_groups();
		$counts = HWCH_Todo::counts();
		$labels = array();

		foreach ( $groups as $group ) {
			$labels += $group['items'];
		}

		$title = isset( $labels[ $tab ] ) ? $labels[ $tab ] : __( 'Content health', 'hw-content-health' );
		?>
		<div class="wrap hwch hwch-v2">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Content health', 'hw-content-health' ); ?></h1>

			<?php self::notices(); ?>

			<div class="hwch-shell">
				<aside class="hwch-side" aria-label="<?php esc_attr_e( 'Content health sections', 'hw-content-health' ); ?>">
					<div class="hwch-brand">
						<svg width="28" height="28" viewBox="0 0 164 164" aria-hidden="true" focusable="false"><path d="M164 141.173C164 153.816 153.816 164 141.173 164H22.8266C10.1842 164 0 153.816 0 141.173V22.8266C0 10.1842 10.1842 0 22.8266 0H141.173C153.816 0 164 10.1842 164 22.8266V141.173Z" fill="#F25621"/><path d="M135.731 26.1628H102.895C101.666 26.1628 100.613 27.2163 100.613 28.4454V57.7688H104.3C104.827 57.7688 105.178 58.2955 105.178 58.8223C105.178 58.9979 105.178 59.1735 105.002 59.3491L84.1074 80.5953C83.4051 81.4733 83.4051 82.5268 84.1074 83.4047L105.178 104.827C105.354 105.002 105.354 105.178 105.354 105.353C105.354 105.88 105.002 106.231 104.476 106.407H100.788V135.73C100.788 136.959 101.842 138.013 103.071 138.013H135.906C137.135 138.013 138.189 136.959 138.189 135.73V28.2698C137.838 27.0407 136.96 26.1628 135.731 26.1628ZM59.1738 59.3491C58.8226 58.9979 58.8226 58.2955 59.1738 57.9444C59.3494 57.7688 59.525 57.5932 59.8761 57.5932H63.5635V28.2698C63.5635 27.0407 62.51 25.9872 61.2809 25.9872H28.4457C27.2166 25.9872 26.1631 27.0407 26.1631 28.2698V135.555C26.1631 136.784 27.2166 137.837 28.4457 137.837H61.2809C62.51 137.837 63.5635 136.784 63.5635 135.555V106.407H59.8761C59.3494 106.407 58.8226 105.88 58.9982 105.353C58.9982 105.178 59.1738 104.827 59.3494 104.651L80.2445 83.4047C80.9468 82.5268 80.9468 81.4733 80.2445 80.5953L59.1738 59.3491Z" fill="#fff"/></svg>
						<span><?php esc_html_e( 'Content health', 'hw-content-health' ); ?></span>
					</div>

					<?php foreach ( $groups as $group ) : ?>
						<div class="hwch-side-group">
							<span class="hwch-side-label"><?php echo esc_html( $group['label'] ); ?></span>
							<?php foreach ( $group['items'] as $key => $label ) : ?>
								<?php $n = self::menu_count( $key, $counts ); ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=hwch&tab=' . $key ) ); ?>" class="hwch-side-link<?php echo $tab === $key ? ' is-current' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>>
									<span><?php echo esc_html( $label ); ?></span>
									<?php if ( $n ) : ?>
										<span class="hwch-side-count"><?php echo esc_html( number_format_i18n( $n ) ); ?></span>
									<?php endif; ?>
								</a>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
				</aside>

				<main class="hwch-main">
					<?php self::sync_bar(); ?>

					<?php if ( 'todo' !== $tab ) : ?>
						<div class="hwch-page-head">
							<h2 class="hwch-h1"><?php echo esc_html( $title ); ?></h2>
							<?php HWCH_Export::button( $tab ); ?>
						</div>
					<?php endif; ?>

					<?php
					// An error in one tab shows as a message instead of taking the
					// whole screen down, so the other tabs keep working.
					try {
						self::render_tab( $tab );
					} catch ( Throwable $e ) {
						echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'This tab could not load:', 'hw-content-health' ) . '</strong> ' . esc_html( $e->getMessage() ) . ' <span class="hwch-muted">(' . esc_html( basename( $e->getFile() ) . ':' . $e->getLine() ) . ')</span></p></div>';
					}
					?>
				</main>
			</div>
		</div>
		<?php
	}

	/**
	 * The side menu: every report, grouped by what it is for.
	 *
	 * @return array
	 */
	public static function menu_groups() {
		return array(
			array(
				'label' => __( 'Overview', 'hw-content-health' ),
				'items' => array(
					'todo'      => __( 'To-do', 'hw-content-health' ),
					'dashboard' => __( 'Dashboard', 'hw-content-health' ),
					'urls'      => __( 'Tracked URLs', 'hw-content-health' ),
				),
			),
			array(
				'label' => __( 'Grow', 'hw-content-health' ),
				'items' => array(
					'quickwins' => __( 'Quick wins', 'hw-content-health' ),
					'planner'   => __( 'Content planner', 'hw-content-health' ),
					'questions' => __( 'Question coverage', 'hw-content-health' ),
					'seasonal'  => __( 'Seasonal planner', 'hw-content-health' ),
					'aio'       => __( 'AI Overviews', 'hw-content-health' ),
				),
			),
			array(
				'label' => __( 'Fix', 'hw-content-health' ),
				'items' => array(
					'refresh' => __( 'Worth refreshing', 'hw-content-health' ),
					'dated'   => __( 'Out of date', 'hw-content-health' ),
					'onpage'  => __( 'On-page', 'hw-content-health' ),
					'links'   => __( 'Internal links', 'hw-content-health' ),
					'broken'  => __( 'Broken links', 'hw-content-health' ),
					'tags'    => __( 'Tags & hubs', 'hw-content-health' ),
				),
			),
			array(
				'label' => __( 'Clean up', 'hw-content-health' ),
				'items' => array(
					'prune'   => __( 'Candidates to prune', 'hw-content-health' ),
					'overlap' => __( 'Overlapping articles', 'hw-content-health' ),
				),
			),
			array(
				'label' => __( 'Data', 'hw-content-health' ),
				'items' => array(
					'ahrefs'   => __( 'Ahrefs', 'hw-content-health' ),
					'impact'   => __( 'Refresh impact', 'hw-content-health' ),
					'log'      => __( 'Log', 'hw-content-health' ),
					'settings' => __( 'Settings', 'hw-content-health' ),
				),
			),
		);
	}

	/**
	 * The number shown beside a report in the side menu: how many things in
	 * it need attention, or 0 for none shown.
	 *
	 * @param string $key    Tab.
	 * @param array  $counts HWCH_Todo::counts().
	 * @return int
	 */
	private static function menu_count( $key, $counts ) {
		$map = array(
			'refresh'   => array( 'refresh' ),
			'quickwins' => array( 'quickwins' ),
			'overlap'   => array( 'overlap' ),
			'dated'     => array( 'dated' ),
			'onpage'    => array( 'onpage' ),
			'prune'     => array( 'prune' ),
			'ahrefs'    => array( 'ahrefs_gap', 'ahrefs_links' ),
		);

		if ( ! isset( $map[ $key ] ) ) {
			return 0;
		}

		$n = 0;
		foreach ( $map[ $key ] as $part ) {
			$n += isset( $counts[ $part ] ) ? (int) $counts[ $part ] : 0;
		}

		return $n;
	}

	/**
	 * What each logged action is called.
	 *
	 * @return array
	 */
	public static function log_labels() {
		return array(
			'queue'        => __( 'Queued for refresh', 'hw-content-health' ),
			'dismiss'      => __( 'Dismissed', 'hw-content-health' ),
			'unpublish'    => __( 'Unpublished', 'hw-content-health' ),
			'redirect'     => __( 'Redirected and unpublished', 'hw-content-health' ),
			'seo_title'    => __( 'SEO title changed', 'hw-content-health' ),
			'fine'         => __( 'Overlap marked as fine', 'hw-content-health' ),
			'refreshed'    => __( 'Refreshed', 'hw-content-health' ),
			'add_tag'      => __( 'Tag added', 'hw-content-health' ),
			'merge_tags'   => __( 'Tags merged', 'hw-content-health' ),
			'meta_desc'    => __( 'Meta description changed', 'hw-content-health' ),
			'redirect_404' => __( '404 redirected', 'hw-content-health' ),
			'ignore_404'   => __( '404 ignored', 'hw-content-health' ),
			'fix_chain'    => __( 'Redirect chain shortened', 'hw-content-health' ),
			'fix_link'     => __( 'Link updated', 'hw-content-health' ),
		);
	}

	/**
	 * Whether a tab name is one of the screen's tabs.
	 *
	 * @param string $tab Tab.
	 * @return bool
	 */
	public static function is_tab( $tab ) {
		return in_array( $tab, self::TABS, true );
	}

	/**
	 * Prints one tab's content. Used by the screen, and by the CSV export,
	 * which renders the same thing and reads its tables.
	 *
	 * @param string $tab Tab.
	 * @return void
	 */
	public static function render_tab( $tab ) {
		if ( 'todo' === $tab ) {
			HWCH_Todo::render();
		} elseif ( 'dashboard' === $tab ) {
			HWCH_Dashboard::render();
		} elseif ( 'links' === $tab ) {
			HWCH_Links::render();
		} elseif ( 'aio' === $tab ) {
			HWCH_AIO::render();
		} elseif ( 'ahrefs' === $tab ) {
			HWCH_Ahrefs::render();
		} elseif ( 'brief' === $tab ) {
			HWCH_Brief::render();
		} elseif ( 'planner' === $tab ) {
			HWCH_Planner::render();
		} elseif ( 'broken' === $tab ) {
			HWCH_Broken::render();
		} elseif ( 'seasonal' === $tab ) {
			HWCH_Seasonal::render();
		} elseif ( 'questions' === $tab ) {
			HWCH_Questions::render();
		} elseif ( 'onpage' === $tab ) {
			HWCH_Onpage::render();
		} elseif ( 'urls' === $tab ) {
			HWCH_Watchlists::render();
		} elseif ( 'quickwins' === $tab ) {
			HWCH_QuickWins::render();
		} elseif ( 'impact' === $tab ) {
			HWCH_Impact::render();
		} elseif ( 'dated' === $tab ) {
			HWCH_Dated::render();
		} elseif ( 'tags' === $tab ) {
			HWCH_Tags::render();
		} elseif ( 'settings' === $tab ) {
			self::settings_tab();
		} elseif ( 'log' === $tab ) {
			self::log_tab();
		} elseif ( 'overlap' === $tab ) {
			self::overlap_tab();
		} else {
			self::report_tab( $tab );
		}
	}

	/**
	 * Messages after an action.
	 *
	 * @return void
	 */
	private static function notices() {
		$key      = isset( $_GET['hwch_msg'] ) ? sanitize_key( $_GET['hwch_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$messages = array(
			'synced'           => array( 'success', __( 'Search Console data updated.', 'hw-content-health' ) ),
			'sync_failed'      => array( 'error', __( 'The sync failed. See the error below.', 'hw-content-health' ) ),
			'queued'           => array( 'success', __( 'Marked as queued for a refresh.', 'hw-content-health' ) ),
			'dismissed'        => array( 'success', __( 'Hidden from this report for six months. You can undo this from the Log.', 'hw-content-health' ) ),
			'unpublished'      => array( 'success', __( 'Moved to draft. You can undo this from the Log.', 'hw-content-health' ) ),
			'redirected'       => array( 'success', __( 'Redirected with a 301 and moved to draft. You can undo this from the Log.', 'hw-content-health' ) ),
			'redirect_missing' => array( 'error', __( 'Enter the URL or post ID to redirect to.', 'hw-content-health' ) ),
			'redirect_invalid' => array( 'error', __( 'That target is not a page on this site, or it is the same article. Nothing was changed.', 'hw-content-health' ) ),
			'undone'           => array( 'success', __( 'Undone.', 'hw-content-health' ) ),
			'undo_missing'     => array( 'error', __( 'That action was already undone.', 'hw-content-health' ) ),
			'merged'             => array( 'success', __( 'Redirected to the kept article and moved to draft. Each one can be undone from the Log.', 'hw-content-health' ) ),
			'merge_missing'      => array( 'error', __( 'Choose the article to keep and tick at least one to redirect to it.', 'hw-content-health' ) ),
			'title_saved'        => array( 'success', __( 'SEO title saved. You can undo this from the Log.', 'hw-content-health' ) ),
			'title_missing'      => array( 'error', __( 'Enter an SEO title.', 'hw-content-health' ) ),
			'fine'               => array( 'success', __( 'Marked as fine. This group will not appear again unless you undo it from the Log.', 'hw-content-health' ) ),
			'list_saved'         => array( 'success', __( 'Group saved.', 'hw-content-health' ) ),
			'list_deleted'       => array( 'success', __( 'Group deleted.', 'hw-content-health' ) ),
			'list_missing'       => array( 'error', __( 'Give the group a name and at least one valid URL.', 'hw-content-health' ) ),
			'refreshed'          => array( 'success', __( 'Marked as refreshed. Its before-and-after comparison is in Refresh impact.', 'hw-content-health' ) ),
			'tag_added'          => array( 'success', __( 'Tag added. You can undo this from the Log.', 'hw-content-health' ) ),
			'tags_merged'        => array( 'success', __( 'Tags merged. The emptied tags are still in Posts > Tags. You can undo this from the Log.', 'hw-content-health' ) ),
			'merge_tags_missing' => array( 'error', __( 'Choose the tag to keep and tick at least one other to merge into it.', 'hw-content-health' ) ),
			'desc_saved'         => array( 'success', __( 'Meta description saved. You can undo this from the Log.', 'hw-content-health' ) ),
			'desc_missing'       => array( 'error', __( 'Write a meta description first.', 'hw-content-health' ) ),
			'oauth_connected'    => array( 'success', __( 'Google account connected. Click Sync now to pull the data.', 'hw-content-health' ) ),
			'oauth_disconnected' => array( 'success', __( 'Google account disconnected.', 'hw-content-health' ) ),
			'oauth_no_client'    => array( 'error', __( 'Save the OAuth client ID and secret first, then click Connect with Google.', 'hw-content-health' ) ),
			'oauth_state'        => array( 'error', __( 'The sign-in link expired or did not match. Click Connect with Google again.', 'hw-content-health' ) ),
			'oauth_denied'       => array( 'error', __( 'Google sign-in was cancelled, so nothing was connected.', 'hw-content-health' ) ),
			'oauth_failed'       => array( 'error', __( 'Google sign-in did not complete. See the error below.', 'hw-content-health' ) ),
			'months_fetched'     => array( 'success', __( 'Monthly figures updated for the Seasonal planner.', 'hw-content-health' ) ),
			'months_failed'      => array( 'error', __( 'Some monthly figures could not be fetched. See the error below; the months already fetched were kept.', 'hw-content-health' ) ),
			'links_checked'      => array( 'success', __( 'A batch of links was checked.', 'hw-content-health' ) ),
			'links_none'         => array( 'success', __( 'Every link has been checked recently; nothing was due.', 'hw-content-health' ) ),
			'redirected_404'     => array( 'success', __( 'Redirected with a 301. You can undo this from the Log.', 'hw-content-health' ) ),
			'ignored_404'        => array( 'success', __( 'Hidden from the 404 list. You can undo this from the Log.', 'hw-content-health' ) ),
			'chain_fixed'        => array( 'success', __( 'The redirect now points straight to the final page. You can undo this from the Log.', 'hw-content-health' ) ),
			'link_fixed'         => array( 'success', __( 'Link updated in the article. You can undo this from the Log.', 'hw-content-health' ) ),
			'link_not_found'     => array( 'error', __( 'That link is no longer in the article, so nothing was changed.', 'hw-content-health' ) ),
			'plan_added'         => array( 'success', __( 'Added to the plan.', 'hw-content-health' ) ),
			'plan_updated'       => array( 'success', __( 'Plan updated.', 'hw-content-health' ) ),
			'plan_deleted'       => array( 'success', __( 'Removed from the plan.', 'hw-content-health' ) ),
			'plan_missing'       => array( 'error', __( 'Nothing was added: a working title is needed.', 'hw-content-health' ) ),
			'gap_dismissed'      => array( 'success', __( 'Hidden from the gaps for six months.', 'hw-content-health' ) ),
			'undo_changed'       => array( 'error', __( 'The article has been edited since, so it was not changed back. Use the article\'s revisions to restore the old link.', 'hw-content-health' ) ),
		);

		if ( isset( $messages[ $key ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
		}

		settings_errors( HWCH_Settings::OPTION );
	}

	/**
	 * Last sync, any error, and the Sync now button.
	 *
	 * @return void
	 */
	private static function sync_bar() {
		$last  = get_option( 'hwch_last_sync' );
		$range = get_option( 'hwch_last_sync_range' );
		$error = get_option( 'hwch_last_error' );
		?>
		<div class="hwch-sync">
			<p>
				<?php if ( $last ) : ?>
					<?php
					printf(
						/* translators: 1: time since sync, 2: start date, 3: end date */
						esc_html__( 'Search Console data last updated %1$s ago, covering %2$s to %3$s. It updates automatically every week.', 'hw-content-health' ),
						esc_html( human_time_diff( strtotime( $last . ' UTC' ) ) ),
						esc_html( date_i18n( get_option( 'date_format' ), strtotime( $range['start'] ) ) ),
						esc_html( date_i18n( get_option( 'date_format' ), strtotime( $range['end'] ) ) )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'No Search Console data yet. Connect it in Settings, then run the first sync.', 'hw-content-health' ); ?>
				<?php endif; ?>
			</p>

			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'hwch_action' ); ?>
					<input type="hidden" name="action" value="hwch_sync">
					<button class="button"><?php esc_html_e( 'Sync now', 'hw-content-health' ); ?></button>
				</form>
			<?php endif; ?>
		</div>

		<?php $status = HWCH_Store::status(); ?>
		<?php if ( $status['pending'] ) : ?>
			<div class="notice notice-info inline hwch-steps" id="hwch-steps">
				<p>
					<strong><?php esc_html_e( 'Collecting the rest in the background:', 'hw-content-health' ); ?></strong>
					<span id="hwch-steps-list"><?php echo esc_html( implode( ', ', $status['pending'] ) ); ?></span>.
					<?php esc_html_e( 'Each part runs on its own, so the server never times out. Reports that use a part fill in when it finishes; keeping this page open speeds it up.', 'hw-content-health' ); ?>
				</p>
			</div>
			<script>
			( function () {
				var box = document.getElementById( 'hwch-steps' ), list = document.getElementById( 'hwch-steps-list' );
				var body = 'action=hwch_step&_wpnonce=<?php echo esc_js( wp_create_nonce( 'hwch_step' ) ); ?>';
				function next() {
					fetch( '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( r ) {
							var d = r.data || {};
							if ( d.pending && d.pending.length ) {
								list.textContent = d.pending.join( ', ' );
								setTimeout( next, d.busy ? 5000 : 500 );
							} else {
								box.className = 'notice notice-success inline hwch-steps';
								box.innerHTML = '<p><?php echo esc_js( __( 'Everything is collected.', 'hw-content-health' ) ); ?> <a href="">' + '<?php echo esc_js( __( 'Reload the page to see the results.', 'hw-content-health' ) ); ?>' + '</a></p>';
							}
						} )
						.catch( function () { setTimeout( next, 15000 ); } );
				}
				setTimeout( next, 1000 );
			} )();
			</script>
		<?php endif; ?>

		<?php if ( $error ) : ?>
			<div class="notice notice-error"><p><strong><?php esc_html_e( 'Last sync failed:', 'hw-content-health' ); ?></strong> <?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>
		<?php
	}

	/**
	 * One of the two reports.
	 *
	 * @param string $report refresh or prune.
	 * @return void
	 */
	private static function report_tab( $report ) {
		if ( ! HWCH_Store::has_data() ) {
			echo '<p class="hwch-empty">' . esc_html__( 'This report appears after the first successful sync. Until then every article would look like it had no traffic.', 'hw-content-health' ) . '</p>';
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : ( 'refresh' === $report ? 'clicks' : 'post_date_gmt' );
		$order   = isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : ( isset( $_GET['order'] ) ? 'desc' : ( 'refresh' === $report ? 'desc' : 'asc' ) );
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		if ( 'refresh' === $report ) {
			HWCH_Lang::picker();
		}

		$rows  = 'refresh' === $report ? HWCH_Reports::refresh() : HWCH_Reports::prune();
		$rows  = HWCH_Reports::sort( $rows, $orderby, $order );
		$total = count( $rows );
		$rows  = array_slice( $rows, ( $paged - 1 ) * HWCH_Export::per( self::PER_PAGE ), HWCH_Export::per( self::PER_PAGE ) );
		$s     = HWCH_Settings::all();

		if ( 'refresh' === $report ) {
			$intro = sprintf(
				/* translators: 1: clicks, 2: months, 3: months */
				__( 'Articles with at least %1$s clicks in the last %2$s months that have not been updated for over %3$s months, most clicks first. The trend compares clicks in the last 90 days with the 90 days before.', 'hw-content-health' ),
				number_format_i18n( $s['refresh_min_click'] ),
				$s['window_months'],
				$s['refresh_age']
			);
			$columns = array(
				'post_title'        => __( 'Article', 'hw-content-health' ),
				'post_modified_gmt' => __( 'Last updated', 'hw-content-health' ),
				'clicks'            => __( 'Clicks', 'hw-content-health' ),
				'impressions'       => __( 'Impressions', 'hw-content-health' ),
				'position'          => __( 'Avg. position', 'hw-content-health' ),
				'trend'             => __( 'Clicks trend', 'hw-content-health' ),
			);
		} else {
			$intro = sprintf(
				/* translators: 1: months, 2: impressions, 3: months */
				__( 'English articles published over %3$s months ago with no clicks and at most %2$s impressions in the last %1$s months. Check backlinks before acting (the Backlinks column shows referring domains when Ahrefs is connected): redirecting to a related article keeps any links it has. Nothing is deleted; articles move to draft and can be restored from the Log.', 'hw-content-health' ),
				$s['window_months'],
				number_format_i18n( $s['prune_max_impr'] ),
				$s['prune_min_age']
			);
			$columns = array(
				'post_title'    => __( 'Article', 'hw-content-health' ),
				'post_date_gmt' => __( 'Published', 'hw-content-health' ),
				'impressions'   => __( 'Impressions', 'hw-content-health' ),
			);
		}
		?>
		<p class="hwch-intro"><?php echo esc_html( $intro ); ?></p>
		<p class="hwch-count">
			<?php
			/* translators: %s: number of articles */
			echo esc_html( sprintf( _n( '%s article', '%s articles', $total, 'hw-content-health' ), number_format_i18n( $total ) ) );
			?>
		</p>

		<?php if ( ! $total ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'Nothing to show with the current settings.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<?php foreach ( $columns as $key => $label ) : ?>
						<?php
						$next = ( $orderby === $key && 'desc' === $order ) ? 'asc' : 'desc';
						$url  = add_query_arg( array( 'orderby' => $key, 'order' => $next, 'paged' => 1 ) );
						?>
						<th class="<?php echo $orderby === $key ? 'hwch-sorted' : ''; ?>"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?><?php echo $orderby === $key ? ( 'asc' === $order ? ' ▲' : ' ▼' ) : ''; ?></a></th>
					<?php endforeach; ?>
					<?php if ( 'prune' === $report ) : ?>
						<th><?php esc_html_e( 'Backlinks', 'hw-content-health' ); ?></th>
					<?php endif; ?>
					<th><?php esc_html_e( 'Actions', 'hw-content-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php self::report_row( $row, $report, $columns ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::pagination( $total, $paged );
	}

	/**
	 * One row of a report.
	 *
	 * @param array  $row     Row.
	 * @param string $report  refresh or prune.
	 * @param array  $columns Columns shown.
	 * @return void
	 */
	private static function report_row( $row, $report, $columns ) {
		$id     = (int) $row['ID'];
		$queued = (int) get_post_meta( $id, '_hwch_queued', true );
		$url    = get_permalink( $id );
		?>
		<tr>
			<?php foreach ( array_keys( $columns ) as $key ) : ?>
				<td>
					<?php
					switch ( $key ) {
						case 'post_title':
							printf(
								'<strong><a href="%1$s">%2$s</a></strong><div class="hwch-url"><a href="%3$s" target="_blank" rel="noopener">%4$s</a></div>',
								esc_url( get_edit_post_link( $id ) ),
								esc_html( $row['post_title'] ? $row['post_title'] : __( '(no title)', 'hw-content-health' ) ),
								esc_url( $url ),
								esc_html( wp_parse_url( $url, PHP_URL_PATH ) )
							);
							if ( $queued ) {
								/* translators: %s: date */
								echo '<span class="hwch-badge">' . esc_html( sprintf( __( 'Queued %s', 'hw-content-health' ), date_i18n( get_option( 'date_format' ), $queued ) ) ) . '</span>';
							}
							$refreshed = (int) get_post_meta( $id, '_hwch_refreshed', true );
							if ( $refreshed ) {
								/* translators: %s: date */
								echo ' <span class="hwch-badge hwch-badge--done">' . esc_html( sprintf( __( 'Refreshed %s', 'hw-content-health' ), date_i18n( get_option( 'date_format' ), $refreshed ) ) ) . '</span>';
							}
							break;
						case 'post_modified_gmt':
						case 'post_date_gmt':
							$time = strtotime( $row[ $key ] . ' UTC' );
							echo esc_html( date_i18n( get_option( 'date_format' ), $time ) );
							/* translators: %s: time since */
							echo '<div class="hwch-muted">' . esc_html( sprintf( __( '%s ago', 'hw-content-health' ), human_time_diff( $time ) ) ) . '</div>';
							break;
						case 'position':
							echo esc_html( (float) $row['position'] ? number_format_i18n( (float) $row['position'], 1 ) : '–' );
							break;
						case 'trend':
							$trend = HWCH_Reports::trend( $row );
							if ( null === $trend ) {
								echo '<span class="hwch-muted">–</span>';
							} else {
								printf( '<span class="%1$s">%2$s%3$s%%</span>', esc_attr( $trend < 0 ? 'hwch-down' : 'hwch-up' ), $trend > 0 ? '+' : '', esc_html( number_format_i18n( $trend ) ) );
							}
							break;
						default:
							echo esc_html( number_format_i18n( (int) $row[ $key ] ) );
					}
					?>
				</td>
			<?php endforeach; ?>

			<?php if ( 'prune' === $report ) : ?>
				<td><?php echo HWCH_Ahrefs::links_cell( $id ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
			<?php endif; ?>

			<td class="hwch-actions">
				<?php if ( 'refresh' === $report ) : ?>
					<a class="button button-small button-primary" href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit', 'hw-content-health' ); ?></a>
					<?php if ( ! $queued ) : ?>
<a class="button button-small" href="<?php echo esc_url( HWCH_Brief::url( $id ) ); ?>"><?php esc_html_e( 'Refresh brief', 'hw-content-health' ); ?></a>
						<?php self::button( 'queue', $id, __( 'Queue for refresh', 'hw-content-health' ) ); ?>
					<?php elseif ( ! get_post_meta( $id, '_hwch_refreshed', true ) ) : ?>
						<?php self::button( 'refreshed', $id, __( 'Mark refreshed', 'hw-content-health' ) ); ?>
					<?php endif; ?>
					<?php self::button( 'dismiss', $id, __( 'Dismiss', 'hw-content-health' ), array( 'report' => 'refresh' ) ); ?>
				<?php else : ?>
					<form class="hwch-redirect" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'hwch_action' ); ?>
						<input type="hidden" name="action" value="hwch_redirect">
						<input type="hidden" name="post_id" value="<?php echo esc_attr( $id ); ?>">
						<input type="text" name="target" placeholder="<?php esc_attr_e( 'Redirect to: URL or post ID', 'hw-content-health' ); ?>" required>
						<button class="button button-small button-primary" onclick="return confirm('<?php echo esc_js( __( 'Redirect this article with a 301 and move it to draft?', 'hw-content-health' ) ); ?>');"><?php esc_html_e( 'Redirect', 'hw-content-health' ); ?></button>
					</form>
					<?php self::button( 'unpublish', $id, __( 'Unpublish only', 'hw-content-health' ), array(), __( 'Move this article to draft without a redirect? Its URL will return a 404.', 'hw-content-health' ) ); ?>
					<?php self::button( 'dismiss', $id, __( 'Keep', 'hw-content-health' ), array( 'report' => 'prune' ) ); ?>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * A small form button for an action.
	 *
	 * @param string $action  Action name without prefix.
	 * @param int    $post_id Article.
	 * @param string $label   Button label.
	 * @param array  $extra   Extra hidden fields.
	 * @param string $confirm Optional confirmation question.
	 * @return void
	 */
	public static function button( $action, $post_id, $label, $extra = array(), $confirm = '' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'hwch_action' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( 'hwch_' . $action ); ?>">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>">
			<?php foreach ( $extra as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<button class="button button-small"<?php echo $confirm ? ' onclick="return confirm(\'' . esc_js( $confirm ) . '\');"' : ''; ?>><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Page links.
	 *
	 * @param int $total Rows.
	 * @param int $paged Current page.
	 * @return void
	 */
	private static function pagination( $total, $paged ) {
		$pages = (int) ceil( $total / self::PER_PAGE );

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

	/**
	 * Overlapping articles, grouped, with the actions for each group.
	 *
	 * @return void
	 */
	private static function overlap_tab() {
		if ( ! get_option( 'hwch_last_overlap' ) ) {
			echo '<p class="hwch-empty">' . esc_html__( 'This report appears after the next successful sync, which now also pulls search queries.', 'hw-content-health' ) . '</p>';
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$filter = isset( $_GET['label'] ) ? sanitize_key( $_GET['label'] ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$labels = array(
			'duplicate' => __( 'Likely duplicates', 'hw-content-health' ),
			'strong'    => __( 'Strong overlap', 'hw-content-health' ),
			'related'   => __( 'Related', 'hw-content-health' ),
		);

		$all    = HWCH_Overlap::groups();
		$counts = array_count_values( wp_list_pluck( $all, 'label' ) );
		$groups = $filter && isset( $labels[ $filter ] ) ? array_values( wp_list_filter( $all, array( 'label' => $filter ) ) ) : $all;
		$total  = count( $groups );
		$per    = 20;
		$groups = array_slice( $groups, ( $paged - 1 ) * HWCH_Export::per( $per ), HWCH_Export::per( $per ) );

		$metrics = HWCH_Overlap::metrics();
		$seo_key = HWCH_Overlap::seo_title_key();
		$ahrefs  = HWCH_Ahrefs::has_data();
		?>
		<p class="hwch-intro">
			<?php esc_html_e( 'English articles competing for the same searches, or with near-identical titles, grouped together. Articles about different destinations are never grouped, however alike their titles. The suggested article to keep has the most clicks; with Ahrefs connected, the one with the most backlinks is marked too. Redirecting the others to it moves them to draft with a 301, which can be undone from the Log. If a group is fine as it is, mark it so and it will not come back.', 'hw-content-health' ); ?>
		</p>

		<ul class="subsubsub hwch-filter">
			<li><a href="<?php echo esc_url( remove_query_arg( array( 'label', 'paged' ) ) ); ?>" class="<?php echo $filter ? '' : 'current'; ?>"><?php esc_html_e( 'All', 'hw-content-health' ); ?> <span class="count">(<?php echo esc_html( count( $all ) ); ?>)</span></a> |</li>
			<?php $i = 0; ?>
			<?php foreach ( $labels as $key => $label ) : ?>
				<li><a href="<?php echo esc_url( add_query_arg( array( 'label' => $key, 'paged' => 1 ) ) ); ?>" class="<?php echo $filter === $key ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?> <span class="count">(<?php echo esc_html( isset( $counts[ $key ] ) ? $counts[ $key ] : 0 ); ?>)</span></a><?php echo ++$i < count( $labels ) ? ' |' : ''; ?></li>
			<?php endforeach; ?>
		</ul>
		<div class="clear"></div>

		<?php if ( ! $total ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'No overlapping articles with the current settings.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<?php foreach ( $groups as $g => $group ) : ?>
			<?php
			$form_id = 'hwch-merge-' . $paged . '-' . $g;

			// The article other sites link to most, when it isn't the suggested one.
			$most_linked = 0;
			if ( $ahrefs ) {
				$best = 0;
				foreach ( $group['posts'] as $candidate ) {
					$data = HWCH_Ahrefs::for_post( $candidate );
					if ( $data && $data['rd'] > $best ) {
						$best        = $data['rd'];
						$most_linked = (int) $candidate;
					}
				}
			}
			$overlap = max( wp_list_pluck( $group['pairs'], 'overlap' ) );
			$title   = max( wp_list_pluck( $group['pairs'], 'title' ) );
			?>
			<div class="hwch-group hwch-group--<?php echo esc_attr( $group['label'] ); ?>">
				<div class="hwch-group-head">
					<span class="hwch-label"><?php echo esc_html( $labels[ $group['label'] ] ); ?></span>
					<span class="hwch-muted">
						<?php
						$bits = array();
						if ( $overlap ) {
							/* translators: %s: percentage */
							$bits[] = sprintf( __( 'Up to %s%% of searches shared', 'hw-content-health' ), number_format_i18n( $overlap ) );
						}
						if ( $title ) {
							/* translators: %s: percentage */
							$bits[] = sprintf( __( 'titles up to %s%% alike', 'hw-content-health' ), number_format_i18n( $title ) );
						}
						/* translators: %s: clicks */
						$bits[] = sprintf( __( '%s clicks between them', 'hw-content-health' ), number_format_i18n( $group['clicks'] ) );
						echo esc_html( implode( ' · ', $bits ) );
						?>
					</span>
				</div>

				<table class="widefat hwch-table">
					<thead>
						<tr>
							<th class="hwch-narrow"><?php esc_html_e( 'Keep', 'hw-content-health' ); ?></th>
							<th class="hwch-narrow"><?php esc_html_e( 'Redirect', 'hw-content-health' ); ?></th>
							<th><?php esc_html_e( 'Article', 'hw-content-health' ); ?></th>
							<th><?php esc_html_e( 'Clicks', 'hw-content-health' ); ?></th>
							<th><?php esc_html_e( 'Impressions', 'hw-content-health' ); ?></th>
							<th><?php esc_html_e( 'Avg. position', 'hw-content-health' ); ?></th>
							<th><?php esc_html_e( 'Last updated', 'hw-content-health' ); ?></th>
							<?php if ( $ahrefs ) : ?>
								<th><?php esc_html_e( 'Backlinks', 'hw-content-health' ); ?></th>
							<?php endif; ?>
							<th><?php echo esc_html( $seo_key ? __( 'SEO title', 'hw-content-health' ) : __( 'Title', 'hw-content-health' ) ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $group['posts'] as $n => $post_id ) : ?>
							<?php
							$m         = isset( $metrics[ $post_id ] ) ? $metrics[ $post_id ] : array( 'clicks' => 0, 'impressions' => 0, 'position' => 0 );
							$url       = get_permalink( $post_id );
							$post_name = get_post_field( 'post_title', $post_id, 'raw' );
							$seo_value = $seo_key ? (string) get_post_meta( $post_id, $seo_key, true ) : $post_name;
							$title_id  = 'hwch-title-' . $post_id . '-' . $g;
							?>
							<tr>
								<td class="hwch-narrow"><input type="radio" name="keeper" value="<?php echo esc_attr( $post_id ); ?>" form="<?php echo esc_attr( $form_id ); ?>" <?php checked( 0, $n ); ?> aria-label="<?php esc_attr_e( 'Keep this article', 'hw-content-health' ); ?>"></td>
								<td class="hwch-narrow"><input type="checkbox" name="redirect[]" value="<?php echo esc_attr( $post_id ); ?>" form="<?php echo esc_attr( $form_id ); ?>" aria-label="<?php esc_attr_e( 'Redirect this article to the kept one', 'hw-content-health' ); ?>"></td>
								<td>
									<strong><a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"><?php echo esc_html( $post_name ); ?></a></strong>
									<?php if ( 0 === $n ) : ?>
										<span class="hwch-badge"><?php esc_html_e( 'Suggested to keep', 'hw-content-health' ); ?></span>
									<?php endif; ?>
									<?php if ( $ahrefs && $n && (int) $post_id === $most_linked ) : ?>
										<span class="hwch-badge hwch-badge--links" title="<?php esc_attr_e( 'More sites link to this one than to the suggested article. Consider keeping this one instead, or redirecting so its links are kept.', 'hw-content-health' ); ?>"><?php esc_html_e( 'Most backlinks', 'hw-content-health' ); ?></span>
									<?php endif; ?>
									<div class="hwch-url"><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $url, PHP_URL_PATH ) ); ?></a></div>
								</td>
								<td><?php echo esc_html( number_format_i18n( (int) $m['clicks'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $m['impressions'] ) ); ?></td>
								<td><?php echo esc_html( (float) $m['position'] ? number_format_i18n( (float) $m['position'], 1 ) : '–' ); ?></td>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( get_post_field( 'post_modified_gmt', $post_id ) . ' UTC' ) ) ); ?></td>
								<?php if ( $ahrefs ) : ?>
									<td><?php echo HWCH_Ahrefs::links_cell( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<?php endif; ?>
								<td class="hwch-seo">
									<input type="text" name="seo_title" value="<?php echo esc_attr( $seo_value ); ?>" placeholder="<?php echo esc_attr( $post_name ); ?>" form="<?php echo esc_attr( $title_id ); ?>">
									<button class="button button-small" form="<?php echo esc_attr( $title_id ); ?>"><?php esc_html_e( 'Save', 'hw-content-health' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="hwch-queries">
					<?php foreach ( $group['pairs'] as $pair ) : ?>
						<div class="hwch-pair">
							<strong><?php echo esc_html( get_the_title( (int) $pair['post_a'] ) ); ?></strong>
							<span class="hwch-muted">&harr;</span>
							<strong><?php echo esc_html( get_the_title( (int) $pair['post_b'] ) ); ?></strong>
							<span class="hwch-muted">
								<?php
								$bits = array();
								if ( (int) $pair['overlap'] ) {
									/* translators: 1: percentage, 2: impressions */
									$bits[] = sprintf( __( '%1$s%% of searches shared (%2$s impressions)', 'hw-content-health' ), number_format_i18n( $pair['overlap'] ), number_format_i18n( $pair['shared'] ) );
								}
								if ( (int) $pair['title'] ) {
									/* translators: %s: percentage */
									$bits[] = sprintf( __( 'titles %s%% alike', 'hw-content-health' ), number_format_i18n( $pair['title'] ) );
								}
								echo esc_html( ' · ' . implode( ' · ', $bits ) );
								?>
							</span>
							<?php if ( $pair['queries'] ) : ?>
								<table class="hwch-qtable">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Shared search', 'hw-content-health' ); ?></th>
											<th><?php esc_html_e( 'Position (left)', 'hw-content-health' ); ?></th>
											<th><?php esc_html_e( 'Position (right)', 'hw-content-health' ); ?></th>
											<th><?php esc_html_e( 'Impressions', 'hw-content-health' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $pair['queries'] as $q ) : ?>
											<tr>
												<td><?php echo esc_html( $q[0] ); ?></td>
												<td><?php echo esc_html( number_format_i18n( (float) $q[2], 1 ) ); ?></td>
												<td><?php echo esc_html( number_format_i18n( (float) $q[4], 1 ) ); ?></td>
												<td><?php echo esc_html( number_format_i18n( (int) $q[1] + (int) $q[3] ) ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="hwch-group-actions">
					<form id="<?php echo esc_attr( $form_id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'hwch_action' ); ?>
						<input type="hidden" name="action" value="hwch_merge">
						<button class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Redirect the ticked articles to the one you are keeping, with a 301, and move them to draft?', 'hw-content-health' ) ); ?>');"><?php esc_html_e( 'Redirect ticked articles to the kept one', 'hw-content-health' ); ?></button>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'hwch_action' ); ?>
						<input type="hidden" name="action" value="hwch_fine">
						<?php foreach ( $group['posts'] as $post_id ) : ?>
							<input type="hidden" name="posts[]" value="<?php echo esc_attr( $post_id ); ?>">
						<?php endforeach; ?>
						<button class="button"><?php esc_html_e( 'These are fine as they are', 'hw-content-health' ); ?></button>
					</form>

					<?php foreach ( $group['posts'] as $post_id ) : ?>
						<form id="<?php echo esc_attr( 'hwch-title-' . $post_id . '-' . $g ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hwch-hidden-form">
							<?php wp_nonce_field( 'hwch_action' ); ?>
							<input type="hidden" name="action" value="hwch_seo_title">
							<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>">
						</form>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endforeach; ?>
		<?php
		self::pagination_for( $total, $paged, $per );
	}

	/**
	 * Page links with a custom page size, for the reports in other classes.
	 *
	 * @param int $total Items.
	 * @param int $paged Current page.
	 * @param int $per   Items per page.
	 * @return void
	 */
	public static function paginate( $total, $paged, $per ) {
		self::pagination_for( $total, $paged, $per );
	}

	/**
	 * Page links with a custom page size.
	 *
	 * @param int $total Items.
	 * @param int $paged Current page.
	 * @param int $per   Items per page.
	 * @return void
	 */
	private static function pagination_for( $total, $paged, $per ) {
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

	/**
	 * The log of every action, with Undo.
	 *
	 * @return void
	 */
	private static function log_tab() {
		global $wpdb;

		$table   = HWCH_Store::table( 'log' );
		$entries = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB
		$labels  = self::log_labels();

		if ( ! $entries ) {
			echo '<p class="hwch-empty">' . esc_html__( 'No actions yet.', 'hw-content-health' ) . '</p>';
			return;
		}
		?>
		<p class="hwch-intro"><?php esc_html_e( 'The last 200 actions. Undo restores the article exactly as it was: its status, and any redirect is removed.', 'hw-content-health' ); ?></p>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Article', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'Action', 'hw-content-health' ); ?></th>
					<th><?php esc_html_e( 'By', 'hw-content-health' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<?php
					$details = (array) json_decode( (string) $entry['details'], true );
					$user    = get_userdata( (int) $entry['user_id'] );
					$label   = isset( $labels[ $entry['action'] ] ) ? $labels[ $entry['action'] ] : $entry['action'];
					?>
					<tr>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $entry['created_at'] . ' UTC' ) ) ); ?></td>
						<td>
							<?php if ( (int) $entry['post_id'] ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( (int) $entry['post_id'] ) ); ?>"><?php echo esc_html( get_the_title( (int) $entry['post_id'] ) ); ?></a>
							<?php elseif ( ! empty( $details['path'] ) ) : ?>
								<span class="hwch-url"><?php echo esc_html( rawurldecode( $details['path'] ) ); ?></span>
							<?php else : ?>
								<span class="hwch-muted">–</span>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( $label ); ?>
							<?php if ( ! empty( $details['target'] ) ) : ?>
								<div class="hwch-muted">→ <?php echo esc_html( $details['target'] ); ?></div>
							<?php endif; ?>
							<?php if ( 'seo_title' === $entry['action'] ) : ?>
								<div class="hwch-muted"><?php echo esc_html( ( '' !== (string) $details['previous'] ? $details['previous'] : __( '(default)', 'hw-content-health' ) ) . ' → ' . $details['title'] ); ?></div>
							<?php endif; ?>
							<?php if ( 'add_tag' === $entry['action'] && ! empty( $details['name'] ) ) : ?>
								<div class="hwch-muted"><?php echo esc_html( $details['name'] ); ?></div>
							<?php endif; ?>
							<?php if ( 'merge_tags' === $entry['action'] ) : ?>
								<?php /* translators: 1: old tag, 2: kept tag, 3: number of articles */ ?>
								<div class="hwch-muted"><?php echo esc_html( sprintf( __( '%1$s → %2$s (%3$s articles)', 'hw-content-health' ), $details['from_name'], $details['keep_name'], number_format_i18n( count( (array) $details['posts'] ) ) ) ); ?></div>
							<?php endif; ?>
							<?php if ( 'fix_link' === $entry['action'] ) : ?>
								<div class="hwch-muted"><?php echo esc_html( rawurldecode( (string) wp_parse_url( $details['old'], PHP_URL_PATH ) ) . ' → ' . rawurldecode( (string) wp_parse_url( $details['new'], PHP_URL_PATH ) ) ); ?></div>
							<?php endif; ?>
							<?php if ( 'fix_chain' === $entry['action'] ) : ?>
								<div class="hwch-muted"><?php echo esc_html( rawurldecode( $details['source'] ) . ' → ' . rawurldecode( (string) wp_parse_url( $details['target'], PHP_URL_PATH ) ) ); ?></div>
							<?php endif; ?>
							<?php if ( 'fine' === $entry['action'] && ! empty( $details['posts'] ) ) : ?>
								<div class="hwch-muted"><?php echo esc_html( implode( ', ', array_map( 'get_the_title', $details['posts'] ) ) ); ?></div>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $user ? $user->display_name : '–' ); ?></td>
						<td>
							<?php if ( $entry['undone_at'] ) : ?>
								<span class="hwch-muted"><?php esc_html_e( 'Undone', 'hw-content-health' ); ?></span>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'hwch_action' ); ?>
									<input type="hidden" name="action" value="hwch_undo">
									<input type="hidden" name="log_id" value="<?php echo esc_attr( $entry['id'] ); ?>">
									<button class="button button-small"><?php esc_html_e( 'Undo', 'hw-content-health' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The settings form.
	 *
	 * @return void
	 */
	private static function settings_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<p>' . esc_html__( 'Only administrators can change these settings.', 'hw-content-health' ) . '</p>';
			return;
		}

		$s        = HWCH_Settings::all();
		$key      = HWCH_Settings::key();
		$name     = HWCH_Settings::OPTION;
		$from_cfg = defined( 'HWCH_GSC_KEY_FILE' );
		?>
		<form method="post" action="options.php" class="hwch-settings">
			<?php settings_fields( 'hwch' ); ?>

			<h2><?php esc_html_e( 'Search Console connection', 'hw-content-health' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="hwch-property"><?php esc_html_e( 'Property', 'hw-content-health' ); ?></label></th>
					<td>
						<input id="hwch-property" class="regular-text" name="<?php echo esc_attr( $name ); ?>[property]" value="<?php echo esc_attr( $s['property'] ); ?>" placeholder="sc-domain:hostelworld.com">
						<p class="description"><?php esc_html_e( 'Exactly as Search Console shows it: sc-domain:hostelworld.com for a domain property, or the full URL with a trailing slash for a URL-prefix property.', 'hw-content-health' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Sign in with', 'hw-content-health' ); ?></th>
					<td>
						<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[auth]" value="oauth" <?php checked( $s['auth'], 'oauth' ); ?>> <?php esc_html_e( 'My Google account (Connect with Google)', 'hw-content-health' ); ?></label><br>
						<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[auth]" value="service" <?php checked( $s['auth'], 'service' ); ?>> <?php esc_html_e( 'A service account (needs a property owner to add it)', 'hw-content-health' ); ?></label>
						<p class="description"><?php esc_html_e( 'Save after switching.', 'hw-content-health' ); ?></p>
					</td>
				</tr>
				<?php if ( 'oauth' === $s['auth'] ) : ?>
					<?php $connection = HWCH_OAuth::connection(); ?>
					<tr>
						<th><label for="hwch-client-id"><?php esc_html_e( 'OAuth client ID', 'hw-content-health' ); ?></label></th>
						<td><input id="hwch-client-id" class="large-text code" name="<?php echo esc_attr( $name ); ?>[client_id]" value="<?php echo esc_attr( $s['client_id'] ); ?>" placeholder="….apps.googleusercontent.com"></td>
					</tr>
					<tr>
						<th><label for="hwch-client-secret"><?php esc_html_e( 'OAuth client secret', 'hw-content-health' ); ?></label></th>
						<td>
							<input id="hwch-client-secret" class="regular-text code" type="password" autocomplete="new-password" name="<?php echo esc_attr( $name ); ?>[client_secret]" value="" placeholder="<?php echo $s['client_secret'] ? esc_attr__( 'Saved. Paste a new one to replace it.', 'hw-content-health' ) : ''; ?>">
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Redirect URI', 'hw-content-health' ); ?></th>
						<td>
							<input class="large-text code" readonly onfocus="this.select();" value="<?php echo esc_attr( HWCH_OAuth::redirect_uri() ); ?>">
							<p class="description"><?php esc_html_e( 'Add this, exactly, under Authorised redirect URIs on the OAuth client in Google Cloud.', 'hw-content-health' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Google account', 'hw-content-health' ); ?></th>
						<td>
							<?php if ( $connection ) : ?>
								<p>
									<?php
									printf(
										/* translators: 1: email, 2: date */
										esc_html__( 'Connected as %1$s since %2$s.', 'hw-content-health' ),
										'<strong>' . esc_html( $connection['email'] ? $connection['email'] : __( 'a Google account', 'hw-content-health' ) ) . '</strong>', // phpcs:ignore WordPress.Security.EscapeOutput
										esc_html( date_i18n( get_option( 'date_format' ), $connection['connected_at'] ) )
									);
									?>
								</p>
								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hwch_oauth_start' ), 'hwch_oauth_start' ) ); ?>"><?php esc_html_e( 'Reconnect', 'hw-content-health' ); ?></a>
								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hwch_oauth_disconnect' ), 'hwch_oauth_disconnect' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Disconnect this Google account? The weekly sync will stop until an account is connected again.', 'hw-content-health' ) ); ?>');"><?php esc_html_e( 'Disconnect', 'hw-content-health' ); ?></a>
							<?php elseif ( $s['client_id'] && $s['client_secret'] ) : ?>
								<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hwch_oauth_start' ), 'hwch_oauth_start' ) ); ?>"><?php esc_html_e( 'Connect with Google', 'hw-content-health' ); ?></a>
								<p class="description"><?php esc_html_e( 'Sign in with the Google account that can see this property in Search Console.', 'hw-content-health' ); ?></p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Save the client ID and secret first. The Connect with Google button then appears here.', 'hw-content-health' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php else : ?>
				<tr class="hwch-auth-service">
						<th><label for="hwch-key"><?php esc_html_e( 'Service account key', 'hw-content-health' ); ?></label></th>
						<td>
							<?php if ( $from_cfg ) : ?>
								<p><?php esc_html_e( 'The key file set in wp-config.php (HWCH_GSC_KEY_FILE) is being used.', 'hw-content-health' ); ?></p>
							<?php else : ?>
								<?php if ( $key ) : ?>
									<p>
										<?php
										/* translators: %s: service account email */
										echo esc_html( sprintf( __( 'A key is saved for %s. Paste a new one to replace it.', 'hw-content-health' ), $key['client_email'] ) );
										?>
									</p>
								<?php endif; ?>
								<textarea id="hwch-key" class="large-text code" rows="5" name="<?php echo esc_attr( $name ); ?>[key_json]" placeholder="{ &quot;type&quot;: &quot;service_account&quot;, ... }"></textarea>
								<?php if ( $key ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_key]" value="1"> <?php esc_html_e( 'Remove the saved key', 'hw-content-health' ); ?></label>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'The JSON key of a Google Cloud service account with the Search Console API enabled, added as a user on the property. The key is never shown again after saving. To keep it out of the database, put the file on the server and define HWCH_GSC_KEY_FILE in wp-config.php instead.', 'hw-content-health' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th><label for="hwch-prefix"><?php esc_html_e( 'Blog URL', 'hw-content-health' ); ?></label></th>
					<td>
						<input id="hwch-prefix" class="regular-text" name="<?php echo esc_attr( $name ); ?>[url_prefix]" value="<?php echo esc_attr( $s['url_prefix'] ); ?>">
						<p class="description"><?php esc_html_e( 'Only Search Console pages under this URL are used, so the rest of hostelworld.com is ignored.', 'hw-content-health' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="hwch-window"><?php esc_html_e( 'Data window', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-window" type="number" min="1" max="16" name="<?php echo esc_attr( $name ); ?>[window_months]" value="<?php echo esc_attr( $s['window_months'] ); ?>"> <?php esc_html_e( 'months (Search Console keeps 16)', 'hw-content-health' ); ?></td>
				</tr>
			</table>

			<?php HWCH_Ahrefs::settings_fields( $s, $name ); ?>

			<h2><?php esc_html_e( 'Worth refreshing', 'hw-content-health' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="hwch-refresh-age"><?php esc_html_e( 'Not updated for over', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-refresh-age" type="number" min="1" max="60" name="<?php echo esc_attr( $name ); ?>[refresh_age]" value="<?php echo esc_attr( $s['refresh_age'] ); ?>"> <?php esc_html_e( 'months', 'hw-content-health' ); ?></td>
				</tr>
				<tr>
					<th><label for="hwch-refresh-min"><?php esc_html_e( 'At least', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-refresh-min" type="number" min="0" name="<?php echo esc_attr( $name ); ?>[refresh_min_click]" value="<?php echo esc_attr( $s['refresh_min_click'] ); ?>"> <?php esc_html_e( 'clicks in the data window', 'hw-content-health' ); ?></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Candidates to prune', 'hw-content-health' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="hwch-prune-age"><?php esc_html_e( 'Published over', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-prune-age" type="number" min="0" max="60" name="<?php echo esc_attr( $name ); ?>[prune_min_age]" value="<?php echo esc_attr( $s['prune_min_age'] ); ?>"> <?php esc_html_e( 'months ago, so new articles have time to be indexed', 'hw-content-health' ); ?></td>
				</tr>
				<tr>
					<th><label for="hwch-prune-max"><?php esc_html_e( 'At most', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-prune-max" type="number" min="0" name="<?php echo esc_attr( $name ); ?>[prune_max_impr]" value="<?php echo esc_attr( $s['prune_max_impr'] ); ?>"> <?php esc_html_e( 'impressions and no clicks in the data window', 'hw-content-health' ); ?></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Overlapping articles', 'hw-content-health' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="hwch-overlap-days"><?php esc_html_e( 'Search data from the last', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-overlap-days" type="number" min="28" max="480" name="<?php echo esc_attr( $name ); ?>[overlap_days]" value="<?php echo esc_attr( $s['overlap_days'] ); ?>"> <?php esc_html_e( 'days', 'hw-content-health' ); ?></td>
				</tr>
				<tr>
					<th><label for="hwch-overlap-min"><?php esc_html_e( 'Flag when they share at least', 'hw-content-health' ); ?></label></th>
					<td>
						<input id="hwch-overlap-min" type="number" min="5" max="100" name="<?php echo esc_attr( $name ); ?>[overlap_min]" value="<?php echo esc_attr( $s['overlap_min'] ); ?>"> <?php esc_html_e( '% of the smaller article\'s search impressions', 'hw-content-health' ); ?>
						<p class="description"><?php esc_html_e( 'The share of the smaller article\'s impressions that come from searches the other article also appears for.', 'hw-content-health' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="hwch-overlap-impr"><?php esc_html_e( 'And at least', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-overlap-impr" type="number" min="0" name="<?php echo esc_attr( $name ); ?>[overlap_min_impr]" value="<?php echo esc_attr( $s['overlap_min_impr'] ); ?>"> <?php esc_html_e( 'shared impressions, so tiny articles are not flagged on a handful of searches', 'hw-content-health' ); ?></td>
				</tr>
				<tr>
					<th><label for="hwch-title-min"><?php esc_html_e( 'Or titles at least', 'hw-content-health' ); ?></label></th>
					<td>
						<input id="hwch-title-min" type="number" min="30" max="100" name="<?php echo esc_attr( $name ); ?>[title_min]" value="<?php echo esc_attr( $s['title_min'] ); ?>"> <?php esc_html_e( '% alike', 'hw-content-health' ); ?>
						<p class="description"><?php esc_html_e( 'Share of meaningful words the titles have in common, ignoring filler words, numbers and years. Catches duplicates with too little traffic to compare by search.', 'hw-content-health' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Quick wins', 'hw-content-health' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="hwch-qw-ctr-impr"><?php esc_html_e( 'Low clicks: at least', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-qw-ctr-impr" type="number" min="0" name="<?php echo esc_attr( $name ); ?>[qw_ctr_impr]" value="<?php echo esc_attr( $s['qw_ctr_impr'] ); ?>"> <?php esc_html_e( 'impressions in 28 days', 'hw-content-health' ); ?></td>
				</tr>
				<tr>
					<th><label for="hwch-qw-ctr-ratio"><?php esc_html_e( 'Flag when CTR is under', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-qw-ctr-ratio" type="number" min="5" max="100" name="<?php echo esc_attr( $name ); ?>[qw_ctr_ratio]" value="<?php echo esc_attr( $s['qw_ctr_ratio'] ); ?>"> <?php esc_html_e( '% of the typical CTR for its position', 'hw-content-health' ); ?></td>
				</tr>
				<tr>
					<th><label for="hwch-qw-strike"><?php esc_html_e( 'Striking distance: at least', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-qw-strike" type="number" min="0" name="<?php echo esc_attr( $name ); ?>[qw_strike_impr]" value="<?php echo esc_attr( $s['qw_strike_impr'] ); ?>"> <?php esc_html_e( 'impressions in 28 days, for a search at positions 8 to 20', 'hw-content-health' ); ?></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Refresh impact', 'hw-content-health' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="hwch-impact-days"><?php esc_html_e( 'Compare', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-impact-days" type="number" min="14" max="90" name="<?php echo esc_attr( $name ); ?>[impact_days]" value="<?php echo esc_attr( $s['impact_days'] ); ?>"> <?php esc_html_e( 'days before a refresh with the same number of days after', 'hw-content-health' ); ?></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Out of date, On-page and Tags & hubs', 'hw-content-health' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="hwch-event-tags"><?php esc_html_e( 'Event tags', 'hw-content-health' ); ?></label></th>
					<td>
						<input id="hwch-event-tags" class="regular-text" name="<?php echo esc_attr( $name ); ?>[event_tags]" value="<?php echo esc_attr( $s['event_tags'] ); ?>">
						<p class="description"><?php esc_html_e( 'Tag slugs, separated by commas. Articles with any of them are checked for staleness.', 'hw-content-health' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="hwch-event-age"><?php esc_html_e( 'Flag event articles not updated for', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-event-age" type="number" min="1" max="60" name="<?php echo esc_attr( $name ); ?>[event_age]" value="<?php echo esc_attr( $s['event_age'] ); ?>"> <?php esc_html_e( 'months', 'hw-content-health' ); ?></td>
				</tr>
				<tr>
					<th><label for="hwch-short"><?php esc_html_e( 'Short articles: under', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-short" type="number" min="50" max="5000" name="<?php echo esc_attr( $name ); ?>[short_words]" value="<?php echo esc_attr( $s['short_words'] ); ?>"> <?php esc_html_e( 'words', 'hw-content-health' ); ?></td>
				</tr>
				<tr>
					<th><label for="hwch-hub-min"><?php esc_html_e( 'Flag hubs with fewer than', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-hub-min" type="number" min="1" name="<?php echo esc_attr( $name ); ?>[hub_min]" value="<?php echo esc_attr( $s['hub_min'] ); ?>"> <?php esc_html_e( 'English articles tagged', 'hw-content-health' ); ?></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Seasonal planner', 'hw-content-health' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="hwch-season-lead-max"><?php esc_html_e( 'Refresh window', 'hw-content-health' ); ?></label></th>
					<td>
						<?php
						printf(
							/* translators: 1: weeks field, 2: weeks field */
							esc_html__( 'opens %1$s weeks and is due %2$s weeks before a season starts', 'hw-content-health' ),
							'<input id="hwch-season-lead-max" type="number" min="1" max="26" name="' . esc_attr( $name ) . '[season_lead_max]" value="' . esc_attr( $s['season_lead_max'] ) . '">', // phpcs:ignore WordPress.Security.EscapeOutput
							'<input id="hwch-season-lead-min" type="number" min="0" max="25" name="' . esc_attr( $name ) . '[season_lead_min]" value="' . esc_attr( $s['season_lead_min'] ) . '">' // phpcs:ignore WordPress.Security.EscapeOutput
						);
						?>
					</td>
				</tr>
				<tr>
					<th><label for="hwch-season-min"><?php esc_html_e( 'Seasonal articles need at least', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-season-min" type="number" min="0" name="<?php echo esc_attr( $name ); ?>[season_min_clicks]" value="<?php echo esc_attr( $s['season_min_clicks'] ); ?>"> <?php esc_html_e( 'clicks over the months stored', 'hw-content-health' ); ?></td>
				</tr>
				<tr>
					<th><label for="hwch-season-strength"><?php esc_html_e( 'And a best month at least', 'hw-content-health' ); ?></label></th>
					<td>
						<input id="hwch-season-strength" type="number" min="110" max="1000" step="10" name="<?php echo esc_attr( $name ); ?>[season_strength]" value="<?php echo esc_attr( $s['season_strength'] ); ?>"> <?php esc_html_e( '% of the average month', 'hw-content-health' ); ?>
						<p class="description"><?php esc_html_e( '150 means the best month gets one and a half times the clicks of an average month. Raise it to see only the most seasonal articles.', 'hw-content-health' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Content planner', 'hw-content-health' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="hwch-planner-min"><?php esc_html_e( 'Gaps need at least', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-planner-min" type="number" min="1" name="<?php echo esc_attr( $name ); ?>[planner_min_impr]" value="<?php echo esc_attr( $s['planner_min_impr'] ); ?>"> <?php esc_html_e( 'impressions in the search data window', 'hw-content-health' ); ?></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'AI Overviews', 'hw-content-health' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="hwch-aio-drop"><?php esc_html_e( 'Flag articles whose click rate fell by', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-aio-drop" type="number" min="10" max="90" name="<?php echo esc_attr( $name ); ?>[aio_ctr_drop]" value="<?php echo esc_attr( $s['aio_ctr_drop'] ); ?>"> <?php esc_html_e( '% or more, with at least', 'hw-content-health' ); ?> <input type="number" min="1" name="<?php echo esc_attr( $name ); ?>[aio_min_impr]" value="<?php echo esc_attr( $s['aio_min_impr'] ); ?>"> <?php esc_html_e( 'impressions in 90 days', 'hw-content-health' ); ?></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Question coverage', 'hw-content-health' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="hwch-q-min"><?php esc_html_e( 'Questions with at least', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-q-min" type="number" min="1" name="<?php echo esc_attr( $name ); ?>[q_min_impr]" value="<?php echo esc_attr( $s['q_min_impr'] ); ?>"> <?php esc_html_e( 'impressions in the search data window', 'hw-content-health' ); ?></td>
				</tr>
				<tr>
					<th><label for="hwch-faq-class"><?php esc_html_e( 'FAQ question class', 'hw-content-health' ); ?></label></th>
					<td>
						<input id="hwch-faq-class" class="regular-text code" name="<?php echo esc_attr( $name ); ?>[faq_class]" value="<?php echo esc_attr( $s['faq_class'] ); ?>" placeholder="faq-question">
						<p class="description"><?php esc_html_e( 'Optional. Headings, the Details block, the Yoast and Rank Math FAQ blocks, accordion titles and bold questions are recognised already. If the blog\'s FAQ accordion marks its questions with another CSS class, enter it here.', 'hw-content-health' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Broken links and 404s', 'hw-content-health' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( '404 log', 'hw-content-health' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[log_404]" value="0">
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[log_404]" value="1" <?php checked( 1, (int) $s['log_404'] ); ?>> <?php esc_html_e( 'Record blog addresses that return a 404', 'hw-content-health' ); ?></label>
						<p class="description"><?php esc_html_e( 'Only addresses under the blog URL, and not files such as images or scripts.', 'hw-content-health' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="hwch-recheck"><?php esc_html_e( 'Check outbound links again after', 'hw-content-health' ); ?></label></th>
					<td><input id="hwch-recheck" type="number" min="7" max="365" name="<?php echo esc_attr( $name ); ?>[link_recheck]" value="<?php echo esc_attr( $s['link_recheck'] ); ?>"> <?php esc_html_e( 'days', 'hw-content-health' ); ?></td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php HWCH_Ahrefs::action_forms(); ?>
		<?php
	}
}
