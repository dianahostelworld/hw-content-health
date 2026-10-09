<?php
/**
 * Ahrefs: backlinks and organic search estimates for every blog article,
 * alongside the Search Console data.
 *
 * Two bulk requests per sync, never one per article, so the whole blog costs
 * a known, fixed number of API units:
 * - Pages by backlinks: referring domains and backlinks for every page that
 *   has links, most-linked first.
 * - Top pages: Ahrefs' estimated organic traffic, keyword count and top
 *   keyword for each page, highest traffic first.
 * Both run with the weekly Search Console sync, or from Sync Ahrefs now.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Ahrefs connection, its sync and the Ahrefs tab.
 */
class HWCH_Ahrefs {

	const API      = 'https://api.ahrefs.com/v3/';
	const DATA     = 'hwch_ahrefs_pages';
	const META     = 'hwch_ahrefs_meta';
	const PER_PAGE = 50;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'hwch_weekly_sync', array( __CLASS__, 'sync' ), 20 );
		add_action( 'admin_post_hwch_ahrefs_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_hwch_ahrefs_sync', array( __CLASS__, 'handle_sync' ) );
	}

	/* ---------- Connection -------------------------------------------- */

	/**
	 * The API key: HWCH_AHREFS_KEY in wp-config.php wins over the settings,
	 * so it can be kept out of the database.
	 *
	 * @return string
	 */
	public static function key() {
		if ( defined( 'HWCH_AHREFS_KEY' ) && HWCH_AHREFS_KEY ) {
			return (string) HWCH_AHREFS_KEY;
		}

		return (string) HWCH_Settings::get( 'ahrefs_key' );
	}

	/**
	 * Whether a key is set.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return '' !== self::key();
	}

	/**
	 * The blog address Ahrefs is asked about, without the scheme.
	 *
	 * @return string
	 */
	public static function target() {
		$prefix = (string) HWCH_Settings::get( 'url_prefix' );
		$prefix = $prefix ? $prefix : home_url( '/' );

		return trailingslashit( preg_replace( '#^https?://#', '', $prefix ) );
	}

	/**
	 * One GET request to the API.
	 *
	 * @param string $endpoint Path after /v3/.
	 * @param array  $args     Query arguments.
	 * @return array|WP_Error Decoded body.
	 */
	public static function get( $endpoint, $args = array() ) {
		$key = self::key();

		if ( '' === $key ) {
			return new WP_Error( 'hwch_ahrefs_key', __( 'No Ahrefs API key is saved.', 'hw-content-health' ) );
		}

		$response = wp_remote_get(
			add_query_arg( array_map( 'rawurlencode', $args ), self::API . $endpoint ),
			array(
				'timeout' => 120,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'hwch_ahrefs_auth', __( 'Ahrefs rejected the API key. Check it was copied in full, that it has not expired, and that your Ahrefs plan includes API access (Enterprise, or the API add-on).', 'hw-content-health' ) );
		}

		if ( 429 === $code ) {
			return new WP_Error( 'hwch_ahrefs_limit', __( 'Ahrefs says the API unit allowance or rate limit has been reached. Try again later, or lower the row limits in Settings.', 'hw-content-health' ) );
		}

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			$detail = is_array( $body ) ? wp_json_encode( $body ) : wp_remote_retrieve_body( $response );
			/* translators: 1: HTTP status, 2: response */
			return new WP_Error( 'hwch_ahrefs_http', sprintf( __( 'Ahrefs answered with an error (%1$s): %2$s', 'hw-content-health' ), $code, wp_strip_all_tags( substr( (string) $detail, 0, 300 ) ) ) );
		}

		return $body;
	}

	/**
	 * Plan, units used and key expiry. Reading this costs no units.
	 *
	 * @return array|WP_Error
	 */
	public static function usage() {
		$body = self::get( 'subscription-info/limits-and-usage' );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return isset( $body['limits_and_usage'] ) ? (array) $body['limits_and_usage'] : array();
	}

	/* ---------- Sync ---------------------------------------------------- */

	/**
	 * Pulls both reports and stores them by normalised URL.
	 *
	 * @return true|WP_Error
	 */
	public static function sync() {
		if ( ! self::enabled() ) {
			return new WP_Error( 'hwch_ahrefs_key', __( 'No Ahrefs API key is saved.', 'hw-content-health' ) );
		}

		$s      = HWCH_Settings::all();
		$target = self::target();
		$pages  = array();

		// Backlinks first: they matter most for prune and merge decisions.
		$links = self::get(
			'site-explorer/pages-by-backlinks',
			array(
				'target'   => $target,
				'mode'     => 'prefix',
				'history'  => 'live',
				'select'   => 'url_to,refdomains_target,links_to_target,dofollow_to_target',
				'order_by' => 'refdomains_target:desc',
				'limit'    => (int) $s['ahrefs_links_rows'],
			)
		);

		if ( is_wp_error( $links ) ) {
			self::fail( $links );
			return $links;
		}

		$link_rows = isset( $links['pages'] ) ? (array) $links['pages'] : array();

		foreach ( $link_rows as $row ) {
			$key = HWCH_GSC::normalise( isset( $row['url_to'] ) ? $row['url_to'] : '' );

			if ( '' === $key ) {
				continue;
			}

			// The same page can appear under http and https; keep the larger.
			$rd = (int) ( isset( $row['refdomains_target'] ) ? $row['refdomains_target'] : 0 );

			if ( isset( $pages[ $key ]['rd'] ) && $pages[ $key ]['rd'] >= $rd ) {
				continue;
			}

			$pages[ $key ]['rd'] = $rd;
			$pages[ $key ]['bl'] = (int) ( isset( $row['links_to_target'] ) ? $row['links_to_target'] : 0 );
			$pages[ $key ]['df'] = (int) ( isset( $row['dofollow_to_target'] ) ? $row['dofollow_to_target'] : 0 );
		}

		$top = self::get(
			'site-explorer/top-pages',
			array(
				'target'   => $target,
				'mode'     => 'prefix',
				'date'     => gmdate( 'Y-m-d' ),
				'select'   => 'url,sum_traffic,keywords,top_keyword,top_keyword_best_position,top_keyword_volume',
				'order_by' => 'sum_traffic:desc',
				'limit'    => (int) $s['ahrefs_top_rows'],
			)
		);

		if ( is_wp_error( $top ) ) {
			self::fail( $top );
			return $top;
		}

		$top_rows = isset( $top['pages'] ) ? (array) $top['pages'] : array();

		foreach ( $top_rows as $row ) {
			$key = HWCH_GSC::normalise( isset( $row['url'] ) ? $row['url'] : '' );

			if ( '' === $key || isset( $pages[ $key ]['tr'] ) ) {
				continue;
			}

			$pages[ $key ]['tr'] = (int) ( isset( $row['sum_traffic'] ) ? $row['sum_traffic'] : 0 );
			$pages[ $key ]['kw'] = (int) ( isset( $row['keywords'] ) ? $row['keywords'] : 0 );
			$pages[ $key ]['tk'] = (string) ( isset( $row['top_keyword'] ) ? $row['top_keyword'] : '' );
			$pages[ $key ]['tp'] = (int) ( isset( $row['top_keyword_best_position'] ) ? $row['top_keyword_best_position'] : 0 );
			$pages[ $key ]['tv'] = (int) ( isset( $row['top_keyword_volume'] ) ? $row['top_keyword_volume'] : 0 );
		}

		update_option( self::DATA, $pages, false );
		update_option(
			self::META,
			array(
				'synced'         => time(),
				'links_rows'     => count( $link_rows ),
				'top_rows'       => count( $top_rows ),
				// Fewer rows back than asked for means every linked page was
				// covered, so a page that is missing has no live backlinks.
				'links_complete' => count( $link_rows ) < (int) $s['ahrefs_links_rows'],
				'top_complete'   => count( $top_rows ) < (int) $s['ahrefs_top_rows'],
				'error'          => '',
			),
			false
		);

		return true;
	}

	/**
	 * Keeps the error for the settings screen.
	 *
	 * @param WP_Error $error Error.
	 * @return void
	 */
	private static function fail( $error ) {
		$meta          = self::meta();
		$meta['error'] = $error->get_error_message();
		$meta['failed'] = time();
		update_option( self::META, $meta, false );
	}

	/**
	 * Sync details.
	 *
	 * @return array
	 */
	public static function meta() {
		return wp_parse_args(
			(array) get_option( self::META, array() ),
			array(
				'synced'         => 0,
				'links_rows'     => 0,
				'top_rows'       => 0,
				'links_complete' => false,
				'top_complete'   => false,
				'error'          => '',
				'failed'         => 0,
			)
		);
	}

	/**
	 * Whether a sync has ever stored data.
	 *
	 * @return bool
	 */
	public static function has_data() {
		return (bool) self::meta()['synced'];
	}

	/* ---------- Reading ------------------------------------------------- */

	/**
	 * Everything stored, by normalised URL.
	 *
	 * @return array
	 */
	public static function pages() {
		static $pages = null;

		if ( null === $pages ) {
			$pages = get_option( self::DATA, array() );
			$pages = is_array( $pages ) ? $pages : array();
		}

		return $pages;
	}

	/**
	 * Ahrefs figures for one article.
	 *
	 * @param int $post_id Article.
	 * @return array|null rd, bl, df, tr, kw, tk, tp, tv; null when there is no data at all.
	 */
	public static function for_post( $post_id ) {
		if ( ! self::has_data() ) {
			return null;
		}

		$pages = self::pages();
		$key   = HWCH_GSC::normalise( get_permalink( $post_id ) );
		$row   = isset( $pages[ $key ] ) ? $pages[ $key ] : array();

		return wp_parse_args( $row, array( 'rd' => 0, 'bl' => 0, 'df' => 0, 'tr' => 0, 'kw' => 0, 'tk' => '', 'tp' => 0, 'tv' => 0 ) );
	}

	/**
	 * A referring-domains cell: the number, linking to the page's backlinks
	 * in Ahrefs. "0" only when the sync covered every linked page; otherwise
	 * an unknown is shown as such.
	 *
	 * @param int $post_id Article.
	 * @return string HTML.
	 */
	public static function links_cell( $post_id ) {
		$url  = get_permalink( $post_id );
		$link = 'https://app.ahrefs.com/v2-site-explorer/backlinks?mode=exact&target=' . rawurlencode( $url );
		$data = self::for_post( $post_id );

		if ( null === $data ) {
			return '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html__( 'Check in Ahrefs', 'hw-content-health' ) . '</a>';
		}

		if ( ! $data['rd'] && ! self::meta()['links_complete'] ) {
			return '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener" title="' . esc_attr__( 'Not in the most-linked pages Ahrefs returned, so probably few or none. Raise the row limit in Settings to cover every page.', 'hw-content-health' ) . '">' . esc_html__( 'Few or none', 'hw-content-health' ) . '</a>';
		}

		$text = sprintf(
			/* translators: 1: referring domains, 2: backlinks */
			_n( '%1$s domain', '%1$s domains', $data['rd'], 'hw-content-health' ),
			number_format_i18n( $data['rd'] )
		);

		$out = '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener" class="' . ( $data['rd'] ? 'hwch-ah-links' : 'hwch-muted' ) . '">' . esc_html( $text ) . '</a>';

		if ( $data['bl'] ) {
			/* translators: %s: backlinks */
			$out .= '<div class="hwch-muted">' . esc_html( sprintf( _n( '%s backlink', '%s backlinks', $data['bl'], 'hw-content-health' ), number_format_i18n( $data['bl'] ) ) ) . '</div>';
		}

		return $out;
	}

	/* ---------- Actions ------------------------------------------------- */

	/**
	 * Test connection: plan, usage and expiry.
	 *
	 * @return void
	 */
	public static function handle_test() {
		check_admin_referer( 'hwch_ahrefs' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Only administrators can do this.', 'hw-content-health' ) );
		}

		$usage = self::usage();

		if ( is_wp_error( $usage ) ) {
			set_transient( 'hwch_ahrefs_notice', array( 'error', $usage->get_error_message() ), 300 );
		} else {
			set_transient( 'hwch_ahrefs_usage', $usage, DAY_IN_SECONDS );
			set_transient( 'hwch_ahrefs_notice', array( 'success', __( 'Connected to Ahrefs.', 'hw-content-health' ) ), 300 );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=hwch&tab=settings#hwch-ahrefs' ) );
		exit;
	}

	/**
	 * Sync Ahrefs now.
	 *
	 * @return void
	 */
	public static function handle_sync() {
		check_admin_referer( 'hwch_ahrefs' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Only administrators can do this.', 'hw-content-health' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$result = self::sync();

		if ( is_wp_error( $result ) ) {
			set_transient( 'hwch_ahrefs_notice', array( 'error', $result->get_error_message() ), 300 );
		} else {
			$meta = self::meta();
			set_transient(
				'hwch_ahrefs_notice',
				array(
					'success',
					/* translators: 1: pages with backlinks, 2: pages with traffic */
					sprintf( __( 'Ahrefs data updated: %1$s pages with backlinks and %2$s pages with search traffic.', 'hw-content-health' ), number_format_i18n( $meta['links_rows'] ), number_format_i18n( $meta['top_rows'] ) ),
				),
				300
			);
			delete_transient( 'hwch_ahrefs_usage' );
		}

		$back = isset( $_POST['back'] ) && 'ahrefs' === $_POST['back'] ? 'ahrefs' : 'settings#hwch-ahrefs'; // phpcs:ignore WordPress.Security.NonceVerification
		wp_safe_redirect( admin_url( 'admin.php?page=hwch&tab=' . $back ) );
		exit;
	}

	/**
	 * The message left by Test or Sync, if any.
	 *
	 * @return void
	 */
	public static function notice() {
		$notice = get_transient( 'hwch_ahrefs_notice' );

		if ( $notice ) {
			delete_transient( 'hwch_ahrefs_notice' );
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
		}
	}

	/**
	 * Rough API units per sync for the current row limits. Ahrefs charges per
	 * row by the columns asked for: referring domains cost 5 units a row and
	 * traffic or volume 10 each, with a minimum of 50 a request.
	 *
	 * @return int
	 */
	public static function units_estimate() {
		$s = HWCH_Settings::all();

		return max( 50, (int) $s['ahrefs_links_rows'] * 5 ) + max( 50, (int) $s['ahrefs_top_rows'] * 20 );
	}

	/* ---------- Settings section ---------------------------------------- */

	/**
	 * The Ahrefs part of the settings form (inside the plugin's options form).
	 *
	 * @param array  $s    Settings.
	 * @param string $name Option name.
	 * @return void
	 */
	public static function settings_fields( $s, $name ) {
		$from_cfg = defined( 'HWCH_AHREFS_KEY' ) && HWCH_AHREFS_KEY;
		?>
		<h2 id="hwch-ahrefs"><?php esc_html_e( 'Ahrefs connection', 'hw-content-health' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Optional. Adds backlinks and Ahrefs organic estimates to the reports: referring domains on Candidates to prune and Overlapping articles, and an Ahrefs tab. Refreshed with the weekly sync.', 'hw-content-health' ); ?></p>
		<?php self::notice(); ?>
		<table class="form-table">
			<tr>
				<th><label for="hwch-ahrefs-key"><?php esc_html_e( 'API key', 'hw-content-health' ); ?></label></th>
				<td>
					<?php if ( $from_cfg ) : ?>
						<p><?php esc_html_e( 'The key set in wp-config.php (HWCH_AHREFS_KEY) is being used.', 'hw-content-health' ); ?></p>
					<?php else : ?>
						<input id="hwch-ahrefs-key" class="regular-text code" type="password" autocomplete="new-password" name="<?php echo esc_attr( $name ); ?>[ahrefs_key]" value="" placeholder="<?php echo $s['ahrefs_key'] ? esc_attr__( 'Saved. Paste a new one to replace it.', 'hw-content-health' ) : ''; ?>">
						<?php if ( $s['ahrefs_key'] ) : ?>
							<br><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_ahrefs_key]" value="1"> <?php esc_html_e( 'Remove the saved key', 'hw-content-health' ); ?></label>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'From Ahrefs: Account settings > API keys. Needs a plan with API access. The key is never shown again after saving. To keep it out of the database, define HWCH_AHREFS_KEY in wp-config.php instead.', 'hw-content-health' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="hwch-ahrefs-links"><?php esc_html_e( 'Pages with backlinks', 'hw-content-health' ); ?></label></th>
				<td>
					<input id="hwch-ahrefs-links" type="number" min="100" max="50000" step="100" name="<?php echo esc_attr( $name ); ?>[ahrefs_links_rows]" value="<?php echo esc_attr( $s['ahrefs_links_rows'] ); ?>"> <?php esc_html_e( 'most-linked pages per sync', 'hw-content-health' ); ?>
					<p class="description"><?php esc_html_e( 'Set this higher than the number of blog pages that have links, so a page missing from the list can be read as having none.', 'hw-content-health' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="hwch-ahrefs-top"><?php esc_html_e( 'Pages with search traffic', 'hw-content-health' ); ?></label></th>
				<td><input id="hwch-ahrefs-top" type="number" min="100" max="50000" step="100" name="<?php echo esc_attr( $name ); ?>[ahrefs_top_rows]" value="<?php echo esc_attr( $s['ahrefs_top_rows'] ); ?>"> <?php esc_html_e( 'top pages by Ahrefs traffic per sync', 'hw-content-health' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Cost', 'hw-content-health' ); ?></th>
				<td>
					<?php
					$units = self::units_estimate();
					/* translators: 1: units per sync, 2: units per month */
					echo esc_html( sprintf( __( 'About %1$s API units per sync, roughly %2$s a month with the weekly sync. Ahrefs charges per row returned, so these are the most it can cost.', 'hw-content-health' ), number_format_i18n( $units ), number_format_i18n( $units * 4.3 ) ) );
					?>
				</td>
			</tr>
			<?php if ( self::enabled() ) : ?>
				<tr>
					<th><?php esc_html_e( 'Status', 'hw-content-health' ); ?></th>
					<td>
						<?php self::status(); ?>
						<p>
							<button class="button" form="hwch-ahrefs-test"><?php esc_html_e( 'Test connection', 'hw-content-health' ); ?></button>
							<button class="button" form="hwch-ahrefs-sync"><?php esc_html_e( 'Sync Ahrefs now', 'hw-content-health' ); ?></button>
						</p>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * The Test and Sync forms, printed after the settings form closes (forms
	 * cannot be nested; the buttons above point at them by id).
	 *
	 * @return void
	 */
	public static function action_forms() {
		foreach ( array( 'test', 'sync' ) as $what ) {
			printf(
				'<form id="hwch-ahrefs-%1$s" method="post" action="%2$s" class="hwch-hidden-form">%3$s<input type="hidden" name="action" value="hwch_ahrefs_%1$s"></form>',
				esc_attr( $what ),
				esc_url( admin_url( 'admin-post.php' ) ),
				wp_nonce_field( 'hwch_ahrefs', '_wpnonce', true, false ) // phpcs:ignore WordPress.Security.EscapeOutput
			);
		}
	}

	/**
	 * Last sync, last error and the usage from the last Test.
	 *
	 * @return void
	 */
	public static function status() {
		$meta  = self::meta();
		$usage = get_transient( 'hwch_ahrefs_usage' );

		if ( $meta['synced'] ) {
			/* translators: 1: date, 2: pages with backlinks, 3: pages with traffic */
			echo '<p>' . esc_html( sprintf( __( 'Last synced %1$s: %2$s pages with backlinks, %3$s pages with search traffic.', 'hw-content-health' ), date_i18n( get_option( 'date_format' ) . ' H:i', $meta['synced'] ), number_format_i18n( $meta['links_rows'] ), number_format_i18n( $meta['top_rows'] ) ) ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Not synced yet. Click Sync Ahrefs now, or wait for the weekly sync.', 'hw-content-health' ) . '</p>';
		}

		if ( $meta['error'] && $meta['failed'] >= $meta['synced'] ) {
			echo '<p class="hwch-down">' . esc_html( $meta['error'] ) . '</p>';
		}

		if ( is_array( $usage ) && $usage ) {
			$parts = array();

			if ( ! empty( $usage['subscription'] ) ) {
				/* translators: %s: plan */
				$parts[] = sprintf( __( 'Plan: %s', 'hw-content-health' ), $usage['subscription'] );
			}

			$used  = isset( $usage['units_usage_workspace'] ) ? $usage['units_usage_workspace'] : null;
			$limit = isset( $usage['units_limit_workspace'] ) ? $usage['units_limit_workspace'] : null;

			if ( null !== $used ) {
				/* translators: 1: used, 2: limit */
				$parts[] = null !== $limit ? sprintf( __( '%1$s of %2$s API units used this month', 'hw-content-health' ), number_format_i18n( $used ), number_format_i18n( $limit ) ) : sprintf( __( '%s API units used this month', 'hw-content-health' ), number_format_i18n( $used ) );
			}

			if ( isset( $usage['units_usage_api_key'] ) ) {
				/* translators: %s: units */
				$parts[] = sprintf( __( '%s by this key', 'hw-content-health' ), number_format_i18n( $usage['units_usage_api_key'] ) );
			}

			if ( ! empty( $usage['usage_reset_date'] ) ) {
				/* translators: %s: date */
				$parts[] = sprintf( __( 'resets %s', 'hw-content-health' ), date_i18n( get_option( 'date_format' ), strtotime( $usage['usage_reset_date'] ) ) );
			}

			if ( ! empty( $usage['api_key_expiration_date'] ) ) {
				/* translators: %s: date */
				$parts[] = sprintf( __( 'key expires %s', 'hw-content-health' ), date_i18n( get_option( 'date_format' ), strtotime( $usage['api_key_expiration_date'] ) ) );
			}

			echo '<p class="hwch-muted">' . esc_html( implode( ' · ', $parts ) ) . '</p>';
		}
	}

	/* ---------- The Ahrefs tab ------------------------------------------ */

	/**
	 * Rows for the tab: every article Ahrefs has data for, with its Search
	 * Console figures beside it.
	 *
	 * @param string $view all, links, gap.
	 * @return array
	 */
	public static function rows( $view ) {
		$metrics = HWCH_Overlap::metrics();
		$map     = HWCH_Store::url_map();
		$rows    = array();

		foreach ( self::pages() as $key => $data ) {
			$post_id = isset( $map[ $key ] ) ? (int) $map[ $key ] : HWCH_Store::post_id_for_url( 'https://' . $key . '/' );

			if ( ! $post_id || 'post' !== get_post_type( $post_id ) ) {
				continue;
			}

			$data = wp_parse_args( $data, array( 'rd' => 0, 'bl' => 0, 'df' => 0, 'tr' => 0, 'kw' => 0, 'tk' => '', 'tp' => 0, 'tv' => 0 ) );
			$gsc  = isset( $metrics[ $post_id ] ) ? $metrics[ $post_id ] : array( 'clicks' => 0, 'impressions' => 0 );

			$row = array_merge(
				$data,
				array(
					'ID'     => $post_id,
					'clicks' => (int) $gsc['clicks'],
					'impr'   => (int) $gsc['impressions'],
				)
			);

			if ( 'links' === $view && ! ( $row['rd'] && ! $row['tr'] && ! $row['clicks'] ) ) {
				continue;
			}

			// Ahrefs estimates meaningful traffic, but Search Console recorded
			// next to none for the article over the data window.
			if ( 'gap' === $view && ! ( $row['tr'] >= 50 && $row['clicks'] < $row['tr'] ) ) {
				continue;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * The tab.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! self::enabled() ) {
			echo '<p class="hwch-empty">' . esc_html__( 'Add an Ahrefs API key in Settings to see backlinks and Ahrefs search estimates for every article.', 'hw-content-health' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=hwch&tab=settings#hwch-ahrefs' ) ) . '">' . esc_html__( 'Go to Settings', 'hw-content-health' ) . '</a></p>';
			return;
		}

		self::notice();

		if ( ! self::has_data() ) {
			echo '<p class="hwch-empty">' . esc_html__( 'No Ahrefs data yet.', 'hw-content-health' ) . '</p>';
			self::sync_button();
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$view    = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'all';
		$view    = in_array( $view, array( 'all', 'links', 'gap' ), true ) ? $view : 'all';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : ( 'links' === $view ? 'rd' : 'tr' );
		$order   = isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : 'desc';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$views = array(
			'all'   => array( __( 'All articles', 'hw-content-health' ), __( 'Every article Ahrefs has backlinks or search estimates for, next to its Search Console figures for the data window.', 'hw-content-health' ) ),
			'links' => array( __( 'Links but no search traffic', 'hw-content-health' ), __( 'Articles other sites link to that get no search traffic in either Ahrefs or Search Console. Their links are worth keeping: refresh the article, or redirect it to a related one rather than unpublishing it.', 'hw-content-health' ) ),
			'gap'   => array( __( 'Ahrefs and Search Console disagree', 'hw-content-health' ), __( 'Articles Ahrefs estimates get at least 50 search visits a month, but which Search Console credits with fewer clicks than that in the whole data window. Often a redirect, a canonical pointing elsewhere, or another article taking the clicks.', 'hw-content-health' ) ),
		);

		$all  = array( 'all' => count( self::rows( 'all' ) ), 'links' => count( self::rows( 'links' ) ), 'gap' => count( self::rows( 'gap' ) ) );
		$rows = self::rows( $view );

		$sortable = array( 'tr', 'kw', 'tp', 'rd', 'bl', 'clicks', 'impr' );
		if ( in_array( $orderby, $sortable, true ) ) {
			usort(
				$rows,
				function ( $a, $b ) use ( $orderby, $order ) {
					$cmp = $a[ $orderby ] <=> $b[ $orderby ];
					return 'asc' === $order ? $cmp : -$cmp;
				}
			);
		}

		$total = count( $rows );
		$per   = HWCH_Export::per( self::PER_PAGE );
		$rows  = array_slice( $rows, ( $paged - 1 ) * $per, $per );
		$meta  = self::meta();
		?>
		<p class="hwch-intro"><?php echo esc_html( $views[ $view ][1] ); ?></p>

		<ul class="subsubsub hwch-filter">
			<?php $i = 0; ?>
			<?php foreach ( $views as $key => $v ) : ?>
				<li><a href="<?php echo esc_url( add_query_arg( array( 'view' => $key, 'paged' => 1, 'orderby' => false, 'order' => false ) ) ); ?>" class="<?php echo $view === $key ? 'current' : ''; ?>"><?php echo esc_html( $v[0] ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $all[ $key ] ) ); ?>)</span></a><?php echo ++$i < count( $views ) ? ' |' : ''; ?></li>
			<?php endforeach; ?>
		</ul>
		<div class="clear"></div>

		<p class="hwch-muted">
			<?php
			/* translators: %s: date */
			echo esc_html( sprintf( __( 'Ahrefs data from %s. Ahrefs traffic is an estimate of monthly visits from Google; Search Console clicks are real, over the whole data window.', 'hw-content-health' ), date_i18n( get_option( 'date_format' ), $meta['synced'] ) ) );
			?>
		</p>

		<?php if ( ! $total ) : ?>
			<p class="hwch-empty"><?php esc_html_e( 'Nothing to show here.', 'hw-content-health' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<?php
		$columns = array(
			'article' => __( 'Article', 'hw-content-health' ),
			'tr'      => __( 'Ahrefs traffic / month', 'hw-content-health' ),
			'kw'      => __( 'Keywords', 'hw-content-health' ),
			'tk'      => __( 'Top keyword', 'hw-content-health' ),
			'tp'      => __( 'Position', 'hw-content-health' ),
			'rd'      => __( 'Referring domains', 'hw-content-health' ),
			'bl'      => __( 'Backlinks', 'hw-content-health' ),
			'clicks'  => __( 'GSC clicks', 'hw-content-health' ),
			'impr'    => __( 'GSC impressions', 'hw-content-health' ),
		);
		?>
		<table class="widefat striped hwch-table">
			<thead>
				<tr>
					<?php foreach ( $columns as $key => $label ) : ?>
						<?php if ( in_array( $key, $sortable, true ) ) : ?>
							<?php $next = ( $orderby === $key && 'desc' === $order ) ? 'asc' : 'desc'; ?>
							<th class="<?php echo $orderby === $key ? 'hwch-sorted' : ''; ?>"><a href="<?php echo esc_url( add_query_arg( array( 'orderby' => $key, 'order' => $next, 'paged' => 1 ) ) ); ?>"><?php echo esc_html( $label ); ?><?php echo $orderby === $key ? ( 'asc' === $order ? ' ▲' : ' ▼' ) : ''; ?></a></th>
						<?php else : ?>
							<th><?php echo esc_html( $label ); ?></th>
						<?php endif; ?>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $url = get_permalink( $row['ID'] ); ?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( get_edit_post_link( $row['ID'] ) ); ?>"><?php echo esc_html( get_the_title( $row['ID'] ) ); ?></a></strong>
							<div class="hwch-url"><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $url, PHP_URL_PATH ) ); ?></a></div>
						</td>
						<td><?php echo esc_html( number_format_i18n( $row['tr'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['kw'] ) ); ?></td>
						<td>
							<?php echo esc_html( $row['tk'] ? $row['tk'] : '–' ); ?>
							<?php if ( $row['tv'] ) : ?>
								<div class="hwch-muted">
									<?php
									/* translators: %s: searches */
									echo esc_html( sprintf( __( '%s searches / month', 'hw-content-health' ), number_format_i18n( $row['tv'] ) ) );
									?>
								</div>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row['tp'] ? $row['tp'] : '–' ); ?></td>
						<td><a href="<?php echo esc_url( 'https://app.ahrefs.com/v2-site-explorer/backlinks?mode=exact&target=' . rawurlencode( $url ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( number_format_i18n( $row['rd'] ) ); ?></a></td>
						<td><?php echo esc_html( number_format_i18n( $row['bl'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['impr'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		HWCH_Admin::paginate( $total, $paged, $per );
		self::sync_button();
	}

	/**
	 * Sync Ahrefs now, under the tab.
	 *
	 * @return void
	 */
	private static function sync_button() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hwch-ahrefs-sync">
			<?php wp_nonce_field( 'hwch_ahrefs' ); ?>
			<input type="hidden" name="action" value="hwch_ahrefs_sync">
			<input type="hidden" name="back" value="ahrefs">
			<button class="button"><?php esc_html_e( 'Sync Ahrefs now', 'hw-content-health' ); ?></button>
			<span class="hwch-muted">
				<?php
				/* translators: %s: units */
				echo esc_html( sprintf( __( 'Uses about %s API units.', 'hw-content-health' ), number_format_i18n( self::units_estimate() ) ) );
				?>
			</span>
		</form>
		<?php
	}
}
