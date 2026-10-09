<?php
/**
 * Import: fill meta descriptions, SEO titles and post titles in bulk from a
 * spreadsheet, with a preview first and every change undoable.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Download a template of what is missing, fill it in, upload it, check the
 * preview, import. Each change is logged like a one-by-one edit, so it can be
 * undone from the Log, and a whole import can be undone at once.
 */
class HWCH_Import {

	const COL_DESC  = 'New meta description';
	const COL_SEO   = 'New SEO title';
	const COL_TITLE = 'New post title';
	const DESC_MAX  = 160;
	const TITLE_MAX = 60;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		foreach ( array( 'template', 'upload', 'apply', 'cancel', 'undo' ) as $a ) {
			add_action( 'admin_post_hwch_import_' . $a, array( __CLASS__, $a ) );
		}
	}

	/**
	 * Fields the import can fill: column => label, log action, where it is saved.
	 *
	 * @return array
	 */
	private static function fields() {
		$out = array();
		if ( HWCH_Onpage::desc_key() ) {
			$out['desc'] = array( 'col' => self::COL_DESC, 'label' => __( 'Meta description', 'hw-content-health' ), 'key' => HWCH_Onpage::desc_key(), 'max' => self::DESC_MAX );
		}
		if ( HWCH_Overlap::seo_title_key() ) {
			$out['seo'] = array( 'col' => self::COL_SEO, 'label' => __( 'SEO title', 'hw-content-health' ), 'key' => HWCH_Overlap::seo_title_key(), 'max' => self::TITLE_MAX );
		}
		$out['title'] = array( 'col' => self::COL_TITLE, 'label' => __( 'Post title', 'hw-content-health' ), 'key' => 'post_title', 'max' => self::TITLE_MAX );
		return $out;
	}

	/**
	 * Current value of a field.
	 *
	 * @param int    $id  Post ID.
	 * @param string $key Meta key or post_title.
	 * @return string
	 */
	private static function current( $id, $key ) {
		return 'post_title' === $key ? (string) get_post_field( 'post_title', $id, 'raw' ) : (string) get_post_meta( $id, $key, true );
	}

	/**
	 * Each article's top search (last 90 days), for the template.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array id => search.
	 */
	private static function top_searches( $ids ) {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
			$rows = $wpdb->get_results( 'SELECT post_id, query, impressions FROM ' . HWCH_Store::table( 'queries' ) . ' WHERE post_id IN (' . implode( ',', $chunk ) . ') ORDER BY impressions DESC', ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows as $r ) {
				if ( ! isset( $out[ (int) $r['post_id'] ] ) ) {
					$out[ (int) $r['post_id'] ] = $r['query'];
				}
			}
		}
		return $out;
	}

	/**
	 * Download a template: missing meta descriptions, missing SEO titles, or
	 * duplicate titles.
	 *
	 * @return void
	 */
	public static function template() {
		check_admin_referer( 'hwch_action' );
		if ( ! current_user_can( HWCH_Actions::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'hw-content-health' ), 403 );
		}
		$kind = isset( $_POST['kind'] ) ? sanitize_key( $_POST['kind'] ) : 'desc';
		$data = HWCH_Onpage::data( true );
		$seo  = HWCH_Overlap::seo_title_key();
		$rows = array();

		if ( 'titles' === $kind ) {
			$ids  = array_merge( array(), ...array_map( 'array_values', $data['titles'] ? $data['titles'] : array( array() ) ) );
			$top  = self::top_searches( $ids );
			$head = array( 'Group', 'URL', 'ID', 'Post title', 'SEO title now', 'Top search', self::COL_SEO, self::COL_TITLE );
			foreach ( $data['titles'] as $g => $group ) {
				foreach ( $group as $id ) {
					$rows[] = array( $g + 1, get_permalink( $id ), $id, get_post_field( 'post_title', $id, 'raw' ), $seo ? (string) get_post_meta( $id, $seo, true ) : '', $top[ $id ] ?? '', '', '' );
				}
			}
			$name = 'duplicate-titles';
		} elseif ( 'seo' === $kind ) {
			$ids = array();
			foreach ( HWCH_Reports::english_ids( get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) ) as $id ) {
				if ( $seo && '' === trim( (string) get_post_meta( $id, $seo, true ) ) ) {
					$ids[] = $id;
				}
			}
			$top  = self::top_searches( $ids );
			$head = array( 'URL', 'ID', 'Post title', 'Top search', self::COL_SEO );
			foreach ( $ids as $id ) {
				$rows[] = array( get_permalink( $id ), $id, get_post_field( 'post_title', $id, 'raw' ), $top[ $id ] ?? '', '' );
			}
			$name = 'missing-seo-titles';
		} else {
			$ids  = $data['desc'];
			$top  = self::top_searches( $ids );
			$head = array( 'URL', 'ID', 'Post title', 'SEO title now', 'Top search', self::COL_DESC );
			foreach ( $ids as $id ) {
				$rows[] = array( get_permalink( $id ), $id, get_post_field( 'post_title', $id, 'raw' ), $seo ? (string) get_post_meta( $id, $seo, true ) : '', $top[ $id ] ?? '', '' );
			}
			$name = 'missing-meta-descriptions';
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, $head, ',', '"', '\\' );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( 'HWCH_Export', 'safe_cell' ), $row ), ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Read an uploaded CSV into the changes it asks for, and keep them for the
	 * preview.
	 *
	 * @return void
	 */
	public static function upload() {
		check_admin_referer( 'hwch_action' );
		if ( ! current_user_can( HWCH_Actions::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'hw-content-health' ), 403 );
		}
		$file = $_FILES['csv'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::back( __( 'Choose a CSV file first.', 'hw-content-health' ), 'error' );
		}
		$text = (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}
		$lines = preg_split( "/\r\n|\n|\r/", $text );
		$first = (string) ( $lines[0] ?? '' );
		$sep   = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ( substr_count( $first, "\t" ) > substr_count( $first, ',' ) ? "\t" : ',' );

		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $fh, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		rewind( $fh );
		$head = fgetcsv( $fh, 0, $sep, '"', '\\' );
		if ( ! $head ) {
			self::back( __( 'That file is empty.', 'hw-content-health' ), 'error' );
		}
		$head   = array_map( function ( $h ) { return strtolower( trim( (string) $h ) ); }, $head );
		$col_id = array_search( 'id', $head, true );
		$col_u  = array_search( 'url', $head, true );
		$fields = self::fields();
		$cols   = array();
		foreach ( $fields as $f => $def ) {
			$i = array_search( strtolower( $def['col'] ), $head, true );
			if ( false !== $i ) {
				$cols[ $f ] = $i;
			}
		}
		if ( ( false === $col_id && false === $col_u ) || ! $cols ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			/* translators: 1-3: column names */
			self::back( sprintf( __( 'The file needs a URL or ID column and at least one of these columns: %1$s, %2$s, %3$s. Use one of the templates to start.', 'hw-content-health' ), self::COL_DESC, self::COL_SEO, self::COL_TITLE ), 'error' );
		}

		$changes = array();
		$line    = 1;
		while ( false !== ( $row = fgetcsv( $fh, 0, $sep, '"', '\\' ) ) ) {
			$line++;
			if ( array( null ) === $row ) {
				continue;
			}
			$id  = false !== $col_id ? absint( $row[ $col_id ] ?? 0 ) : 0;
			$url = false !== $col_u ? trim( (string) ( $row[ $col_u ] ?? '' ) ) : '';
			if ( ( ! $id || 'post' !== get_post_type( $id ) ) && $url ) {
				$id = url_to_postid( $url );
			}
			foreach ( $cols as $f => $i ) {
				$value = trim( (string) ( $row[ $i ] ?? '' ) );
				if ( '' === $value ) {
					continue;
				}
				$value     = 'desc' === $f ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
				$changes[] = array( 'line' => $line, 'id' => $id && get_post( $id ) ? $id : 0, 'url' => $url, 'field' => $f, 'value' => $value );
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $changes ) {
			self::back( __( 'No filled-in cells were found, so there is nothing to import.', 'hw-content-health' ), 'error' );
		}
		set_transient( 'hwch_import_' . get_current_user_id(), array( 'file' => sanitize_file_name( $file['name'] ), 'changes' => $changes ), DAY_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=hwch&tab=import' ) );
		exit;
	}

	/**
	 * Apply the previewed changes.
	 *
	 * @return void
	 */
	public static function apply() {
		check_admin_referer( 'hwch_action' );
		if ( ! current_user_can( HWCH_Actions::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'hw-content-health' ), 403 );
		}
		$pending = get_transient( 'hwch_import_' . get_current_user_id() );
		if ( ! is_array( $pending ) ) {
			self::back( __( 'That preview has expired. Upload the file again.', 'hw-content-health' ), 'error' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$replace = ! empty( $_POST['replace'] );
		$fields  = self::fields();
		$import  = 'imp' . time();
		$done    = 0;
		$skipped = 0;

		foreach ( $pending['changes'] as $c ) {
			if ( ! $c['id'] || ! isset( $fields[ $c['field'] ] ) ) {
				$skipped++;
				continue;
			}
			$key = $fields[ $c['field'] ]['key'];
			$was = self::current( $c['id'], $key );
			if ( $was === $c['value'] || ( '' !== trim( $was ) && ! $replace ) ) {
				$skipped++;
				continue;
			}
			if ( 'post_title' === $key ) {
				wp_update_post( array( 'ID' => $c['id'], 'post_title' => $c['value'] ) );
			} else {
				update_post_meta( $c['id'], $key, $c['value'] );
			}
			$details = array( 'field' => $key, 'previous' => $was, 'import' => $import );
			if ( 'desc' === $c['field'] ) {
				HWCH_Actions::log( $c['id'], 'meta_desc', $details + array( 'desc' => $c['value'] ) );
			} else {
				HWCH_Actions::log( $c['id'], 'seo_title', $details + array( 'title' => $c['value'] ) );
			}
			$done++;
		}

		delete_transient( 'hwch_onpage' );
		delete_transient( 'hwch_import_' . get_current_user_id() );
		$hist = (array) get_option( 'hwch_imports', array() );
		array_unshift( $hist, array( 'id' => $import, 'at' => time(), 'file' => $pending['file'], 'done' => $done, 'user' => get_current_user_id() ) );
		update_option( 'hwch_imports', array_slice( $hist, 0, 20 ), false );

		/* translators: 1: changed, 2: skipped */
		self::back( sprintf( __( 'Imported %1$s changes. %2$s rows were skipped. Each change is in the Log, and the whole import can be undone below.', 'hw-content-health' ), number_format_i18n( $done ), number_format_i18n( $skipped ) ), 'success' );
	}

	/**
	 * Throw the preview away.
	 *
	 * @return void
	 */
	public static function cancel() {
		check_admin_referer( 'hwch_action' );
		delete_transient( 'hwch_import_' . get_current_user_id() );
		self::back( __( 'Cancelled. Nothing was changed.', 'hw-content-health' ), 'success' );
	}

	/**
	 * Undo a whole import: every change still in place goes back to what it
	 * was before.
	 *
	 * @return void
	 */
	public static function undo() {
		global $wpdb;
		check_admin_referer( 'hwch_action' );
		if ( ! current_user_can( HWCH_Actions::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'hw-content-health' ), 403 );
		}
		$import = isset( $_POST['import'] ) ? sanitize_key( $_POST['import'] ) : '';
		if ( '' === $import ) {
			self::back( '', 'error' );
		}
		$table   = HWCH_Store::table( 'log' );
		$entries = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE undone_at IS NULL AND action IN ('meta_desc','seo_title') AND details LIKE %s ORDER BY id DESC", '%' . $wpdb->esc_like( '"import":"' . $import . '"' ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$n       = 0;
		foreach ( (array) $entries as $e ) {
			$d  = (array) json_decode( (string) $e['details'], true );
			$id = (int) $e['post_id'];
			if ( ! get_post( $id ) ) {
				continue;
			}
			if ( 'post_title' === $d['field'] ) {
				wp_update_post( array( 'ID' => $id, 'post_title' => $d['previous'] ) );
			} elseif ( '' === (string) $d['previous'] ) {
				delete_post_meta( $id, $d['field'] );
			} else {
				update_post_meta( $id, $d['field'], $d['previous'] );
			}
			$wpdb->update( $table, array( 'undone_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $e['id'] ) ); // phpcs:ignore WordPress.DB
			$n++;
		}
		delete_transient( 'hwch_onpage' );
		delete_transient( 'hwch_todo_counts' );
		$hist = (array) get_option( 'hwch_imports', array() );
		foreach ( $hist as &$h ) {
			if ( $h['id'] === $import ) {
				$h['undone'] = time();
			}
		}
		update_option( 'hwch_imports', $hist, false );
		/* translators: %s: count */
		self::back( sprintf( __( 'Undone: %s changes went back to what they were before the import.', 'hw-content-health' ), number_format_i18n( $n ) ), 'success' );
	}

	/**
	 * Back to the Import page with a message.
	 *
	 * @param string $msg  Message.
	 * @param string $type success or error.
	 * @return void
	 */
	private static function back( $msg, $type ) {
		if ( $msg ) {
			set_transient( 'hwch_import_msg_' . get_current_user_id(), array( $type, $msg ), 120 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=hwch&tab=import' ) );
		exit;
	}

	/**
	 * Hidden fields for a form.
	 *
	 * @param string $action Action.
	 * @return string
	 */
	private static function fields_html( $action ) {
		return wp_nonce_field( 'hwch_action', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="hwch_import_' . esc_attr( $action ) . '">';
	}

	/**
	 * The Import page.
	 *
	 * @return void
	 */
	public static function render() {
		$uid = get_current_user_id();
		$msg = get_transient( 'hwch_import_msg_' . $uid );
		if ( $msg ) {
			delete_transient( 'hwch_import_msg_' . $uid );
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $msg[0] ), esc_html( $msg[1] ) );
		}
		$pending = get_transient( 'hwch_import_' . $uid );
		if ( is_array( $pending ) ) {
			self::preview( $pending );
			return;
		}
		$post  = esc_url( admin_url( 'admin-post.php' ) );
		$f     = self::fields();
		$data  = HWCH_Onpage::data();
		?>
		<p class="hwch-intro"><?php esc_html_e( 'Fill in missing fields for many articles at once. Download a template, fill in the empty column in Excel or Google Sheets, save it as CSV and upload it here. You see a preview before anything is saved, and every change can be undone.', 'hw-content-health' ); ?></p>
		<?php if ( ! isset( $f['desc'] ) ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Neither Yoast nor Rank Math is active, so meta descriptions and SEO titles can\'t be imported. Post titles still can.', 'hw-content-health' ); ?></p></div>
		<?php endif; ?>

		<h3><?php esc_html_e( '1. Download a template', 'hw-content-health' ); ?></h3>
		<div class="hwch-import-templates">
			<?php
			$kinds = array(
				'desc'   => array( __( 'Missing meta descriptions', 'hw-content-health' ), sprintf( /* translators: %s: count */ __( '%s English articles. Fill in "New meta description".', 'hw-content-health' ), number_format_i18n( count( $data['desc'] ) ) ), isset( $f['desc'] ) ),
				'seo'    => array( __( 'Missing SEO titles', 'hw-content-health' ), __( 'English articles with no SEO title set, so Google uses the post title. Fill in "New SEO title".', 'hw-content-health' ), isset( $f['seo'] ) ),
				'titles' => array( __( 'Duplicate titles', 'hw-content-health' ), sprintf( /* translators: %s: count */ _n( '%s group of articles sharing a title. Fill in "New SEO title", "New post title" or both.', '%s groups of articles sharing a title. Fill in "New SEO title", "New post title" or both.', count( $data['titles'] ), 'hw-content-health' ), number_format_i18n( count( $data['titles'] ) ) ), true ),
			);
			foreach ( $kinds as $k => $def ) :
				if ( ! $def[2] ) {
					continue;
				}
				?>
				<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="hwch-import-card">
					<?php echo self::fields_html( 'template' ); // phpcs:ignore ?>
					<input type="hidden" name="kind" value="<?php echo esc_attr( $k ); ?>">
					<strong><?php echo esc_html( $def[0] ); ?></strong>
					<span class="hwch-muted"><?php echo esc_html( $def[1] ); ?></span>
					<button class="button"><?php esc_html_e( 'Download CSV', 'hw-content-health' ); ?></button>
				</form>
			<?php endforeach; ?>
		</div>

		<h3><?php esc_html_e( '2. Upload the filled-in file', 'hw-content-health' ); ?></h3>
		<form method="post" action="<?php echo $post; // phpcs:ignore ?>" enctype="multipart/form-data" class="hwch-import-upload">
			<?php echo self::fields_html( 'upload' ); // phpcs:ignore ?>
			<label for="hwch-csv" class="screen-reader-text"><?php esc_html_e( 'CSV file', 'hw-content-health' ); ?></label>
			<input type="file" id="hwch-csv" name="csv" accept=".csv,text/csv" required>
			<button class="button button-primary"><?php esc_html_e( 'Upload and preview', 'hw-content-health' ); ?></button>
		</form>
		<p class="hwch-muted"><?php echo esc_html( sprintf( /* translators: 1-3: column names */ __( 'Rows are matched by ID, or by URL. Columns read: "%1$s", "%2$s" and "%3$s". Empty cells are ignored, so a blank never clears a field. In Excel, save with File > Save As > "CSV UTF-8".', 'hw-content-health' ), self::COL_DESC, self::COL_SEO, self::COL_TITLE ) ); ?></p>

		<?php
		$hist = (array) get_option( 'hwch_imports', array() );
		if ( $hist ) :
			?>
			<h3><?php esc_html_e( 'Past imports', 'hw-content-health' ); ?></h3>
			<table class="widefat striped hwch-table">
				<thead><tr><th><?php esc_html_e( 'When', 'hw-content-health' ); ?></th><th><?php esc_html_e( 'File', 'hw-content-health' ); ?></th><th><?php esc_html_e( 'Changes', 'hw-content-health' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $hist as $h ) : ?>
					<tr>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $h['at'] + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ); ?></td>
						<td><?php echo esc_html( $h['file'] ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $h['done'] ) ); ?></td>
						<td class="hwch-actions">
							<?php if ( ! empty( $h['undone'] ) ) : ?>
								<span class="hwch-muted"><?php esc_html_e( 'Undone', 'hw-content-health' ); ?></span>
							<?php elseif ( $h['done'] ) : ?>
								<form method="post" action="<?php echo $post; // phpcs:ignore ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Put every change from this import back to what it was before?', 'hw-content-health' ) ); ?>');">
									<?php echo self::fields_html( 'undo' ); // phpcs:ignore ?>
									<input type="hidden" name="import" value="<?php echo esc_attr( $h['id'] ); ?>">
									<button class="button button-small"><?php esc_html_e( 'Undo this import', 'hw-content-health' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * The preview: what would change, before anything is saved.
	 *
	 * @param array $pending File and changes.
	 * @return void
	 */
	private static function preview( $pending ) {
		$fields = self::fields();
		$rows   = array();
		$count  = array( 'fill' => 0, 'has' => 0, 'same' => 0, 'bad' => 0 );
		foreach ( $pending['changes'] as $c ) {
			$def = $fields[ $c['field'] ] ?? null;
			$was = ( $c['id'] && $def ) ? self::current( $c['id'], $def['key'] ) : '';
			if ( ! $c['id'] || ! $def ) {
				$status = 'bad';
			} elseif ( $was === $c['value'] ) {
				$status = 'same';
			} elseif ( '' !== trim( $was ) ) {
				$status = 'has';
			} else {
				$status = 'fill';
			}
			$count[ $status ]++;
			$rows[] = $c + array( 'status' => $status, 'was' => $was, 'def' => $def );
		}
		$order = array( 'bad' => 0, 'has' => 1, 'fill' => 2, 'same' => 3 );
		usort( $rows, function ( $a, $b ) use ( $order ) { return $order[ $a['status'] ] <=> $order[ $b['status'] ] ?: $a['line'] <=> $b['line']; } );
		$post = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<p class="hwch-intro">
			<?php
			/* translators: 1: file, 2: fill, 3: has, 4: same, 5: bad */
			echo esc_html( sprintf( __( 'Preview of %1$s. Nothing has been saved yet. %2$s will fill an empty field, %3$s already have a value, %4$s are unchanged and %5$s couldn\'t be matched to an article.', 'hw-content-health' ), $pending['file'], number_format_i18n( $count['fill'] ), number_format_i18n( $count['has'] ), number_format_i18n( $count['same'] ), number_format_i18n( $count['bad'] ) ) );
			?>
		</p>
		<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="hwch-import-apply">
			<?php echo self::fields_html( 'apply' ); // phpcs:ignore ?>
			<?php if ( $count['has'] ) : ?>
				<label><input type="checkbox" name="replace" value="1" id="hwch-replace"> <?php echo esc_html( sprintf( /* translators: %s: count */ __( 'Also replace existing values (%s)', 'hw-content-health' ), number_format_i18n( $count['has'] ) ) ); ?></label>
			<?php endif; ?>
			<button class="button button-primary"><?php esc_html_e( 'Import', 'hw-content-health' ); ?></button>
		</form>
		<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="hwch-inline">
			<?php echo self::fields_html( 'cancel' ); // phpcs:ignore ?>
			<button class="button"><?php esc_html_e( 'Cancel', 'hw-content-health' ); ?></button>
		</form>

		<table class="widefat striped hwch-table hwch-import-table">
			<thead><tr><th><?php esc_html_e( 'Article', 'hw-content-health' ); ?></th><th><?php esc_html_e( 'Field', 'hw-content-health' ); ?></th><th><?php esc_html_e( 'Now', 'hw-content-health' ); ?></th><th><?php esc_html_e( 'From your file', 'hw-content-health' ); ?></th><th><?php esc_html_e( 'Status', 'hw-content-health' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<?php $len = mb_strlen( $r['value'] ); ?>
				<tr>
					<td>
						<?php if ( $r['id'] ) : ?>
							<strong><a href="<?php echo esc_url( get_edit_post_link( $r['id'] ) ); ?>"><?php echo esc_html( get_the_title( $r['id'] ) ); ?></a></strong>
						<?php else : ?>
							<span class="hwch-muted"><?php echo esc_html( $r['url'] ? $r['url'] : sprintf( /* translators: %d: line */ __( 'Line %d', 'hw-content-health' ), $r['line'] ) ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $r['def'] ? $r['def']['label'] : '' ); ?></td>
					<td><?php echo '' !== $r['was'] ? esc_html( $r['was'] ) : '<span class="hwch-muted">' . esc_html__( '(empty)', 'hw-content-health' ) . '</span>'; ?></td>
					<td><?php echo esc_html( $r['value'] ); ?>
						<?php if ( $r['def'] && $len > $r['def']['max'] ) : ?>
							<div class="hwch-status hwch-status--late"><?php echo esc_html( sprintf( /* translators: 1: length, 2: max */ __( '%1$d characters, over %2$d', 'hw-content-health' ), $len, $r['def']['max'] ) ); ?></div>
						<?php else : ?>
							<div class="hwch-muted"><?php echo esc_html( sprintf( /* translators: %d: length */ __( '%d characters', 'hw-content-health' ), $len ) ); ?></div>
						<?php endif; ?>
					</td>
					<td>
						<?php
						$labels = array(
							'fill' => array( 'done', __( 'Will fill', 'hw-content-health' ) ),
							'has'  => array( 'now', __( 'Has a value already', 'hw-content-health' ) ),
							'same' => array( '', __( 'Unchanged', 'hw-content-health' ) ),
							'bad'  => array( 'late', __( 'Article not found', 'hw-content-health' ) ),
						);
						$l      = $labels[ $r['status'] ];
						echo '<span class="hwch-status' . ( $l[0] ? ' hwch-status--' . esc_attr( $l[0] ) : '' ) . '">' . esc_html( $l[1] ) . '</span>';
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
