<?php
/**
 * CSV export for every report tab.
 *
 * Rather than a separate export routine per report — eighteen of them, each
 * drifting from what the screen shows — the export renders the tab exactly as
 * you see it, with your filters, language and sort order, but with every row
 * instead of one page, and turns its tables into a CSV. What you download is
 * what the tab says.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tab to CSV.
 */
class HWCH_Export {

	/**
	 * True while an export is being built.
	 *
	 * @var bool
	 */
	private static $active = false;

	/**
	 * Tabs with nothing worth exporting.
	 *
	 * @var array
	 */
	const SKIP = array( 'todo', 'settings', 'brief' );

	/**
	 * Query arguments that must not be carried into an export: paging (an
	 * export is every page) and the one-off "recalculate now" links.
	 *
	 * @var array
	 */
	const DROP_ARGS = array( 'page', 'paged', 'refresh', 'action', '_wpnonce', 'hwch_msg' );

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_hwch_export', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Page size for a report: as normal on screen, everything during an export.
	 *
	 * @param int $per Rows per page on screen.
	 * @return int
	 */
	public static function per( $per ) {
		return self::$active ? PHP_INT_MAX : (int) $per;
	}

	/**
	 * Whether an export is being built.
	 *
	 * @return bool
	 */
	public static function active() {
		return self::$active;
	}

	/**
	 * The Export CSV button, carrying the tab's current filters with it.
	 *
	 * @param string $tab Current tab.
	 * @return void
	 */
	public static function button( $tab ) {
		if ( in_array( $tab, self::SKIP, true ) ) {
			return;
		}

		$args = array();

		// phpcs:ignore WordPress.Security.NonceVerification -- read-only: copies the current view into the link.
		foreach ( wp_unslash( $_GET ) as $key => $value ) {
			if ( in_array( $key, self::DROP_ARGS, true ) || ! is_scalar( $value ) ) {
				continue;
			}

			$args[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
		}

		$args['tab']    = $tab;
		$args['action'] = 'hwch_export';

		$url = wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'hwch_export' );
		?>
		<p class="hwch-export">
			<a class="button" href="<?php echo esc_url( $url ); ?>">
				<span class="dashicons dashicons-download" aria-hidden="true"></span>
				<?php esc_html_e( 'Export this tab as CSV', 'hw-content-health' ); ?>
			</a>
			<span class="hwch-muted"><?php esc_html_e( 'Every row, not just this page, with the filters you have on now.', 'hw-content-health' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Builds the CSV and sends it as a download.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export this report.', 'hw-content-health' ), 403 );
		}

		check_admin_referer( 'hwch_export' );

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';

		if ( '' === $tab || in_array( $tab, self::SKIP, true ) || ! HWCH_Admin::is_tab( $tab ) ) {
			wp_die( esc_html__( 'That tab cannot be exported.', 'hw-content-health' ) );
		}

		// Render the tab the way the screen does, minus paging.
		foreach ( self::DROP_ARGS as $key ) {
			unset( $_GET[ $key ] );
		}
		$_GET['page'] = 'hwch';
		$_GET['tab']  = $tab;

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- big sites, every row.
		}

		self::$active = true;
		ob_start();

		try {
			HWCH_Admin::render_tab( $tab );
		} catch ( Throwable $e ) {
			ob_end_clean();
			self::$active = false;
			wp_die( esc_html( sprintf( /* translators: %s: error */ __( 'The export could not be built: %s', 'hw-content-health' ), $e->getMessage() ) ) );
		}

		$html         = ob_get_clean();
		self::$active = false;

		$rows = self::html_to_rows( $html );

		if ( ! $rows ) {
			wp_die(
				esc_html__( 'There is no table on this tab to export right now. It may still be waiting for the first sync, or the current filters show nothing.', 'hw-content-health' ),
				'',
				array( 'back_link' => true )
			);
		}

		$name = sprintf( 'content-health-%s-%s.csv', $tab, wp_date( 'Y-m-d' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );

		$out = fopen( 'php://output', 'w' );

		// Byte order mark, so Excel opens accented titles and other languages
		// correctly instead of as garbled characters.
		fwrite( $out, "\xEF\xBB\xBF" );

		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'safe_cell' ), $row ), ',', '"', '\\' );
		}

		fclose( $out );
		exit;
	}

	/**
	 * Stops a cell being read as a formula by Excel or Sheets. A title that
	 * happens to start with "=" or "+" would otherwise run as one.
	 *
	 * Plain numbers, including negative ones and "+12%", are left as they are
	 * so they still sort and sum.
	 *
	 * @param string $value Cell.
	 * @return string
	 */
	public static function safe_cell( $value ) {
		$value = (string) $value;

		if ( '' === $value || is_numeric( str_replace( array( '%', ',' ), '', $value ) ) ) {
			return $value;
		}

		if ( in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Turns every report table in the tab's HTML into CSV rows.
	 *
	 * Each table becomes a block: its heading, the column names, then the rows,
	 * with a blank line before the next table. Buttons, forms and the Actions
	 * column are left out. An article's address, shown under its title on
	 * screen, gets a column of its own so the sheet can be filtered by it.
	 *
	 * @param string $html Rendered tab.
	 * @return array Rows.
	 */
	public static function html_to_rows( $html ) {
		if ( '' === trim( (string) $html ) || ! class_exists( 'DOMDocument' ) ) {
			return array();
		}

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();

		$xpath  = new DOMXPath( $doc );
		$tables = $xpath->query( '//table[contains(concat(" ", normalize-space(@class), " "), " hwch-table ")][not(ancestor::table)]' );
		$blocks = array();

		foreach ( $tables as $table ) {
			$block = self::table_rows( $table, $xpath );

			if ( count( $block ) < 2 && $tables->length > 1 ) {
				continue; // A header with no rows.
			}

			if ( $block ) {
				$blocks[] = array(
					'title' => self::table_title( $table, $xpath ),
					'rows'  => $block,
				);
			}
		}

		if ( ! $blocks ) {
			return array();
		}

		if ( 1 === count( $blocks ) ) {
			return $blocks[0]['rows'];
		}

		// Many tables with the same columns — the groups on Overlapping
		// articles, say — become one table with a Group column, which is far
		// easier to sort and filter than a stack of separate blocks.
		$same = true;

		foreach ( $blocks as $block ) {
			if ( $block['rows'][0] !== $blocks[0]['rows'][0] ) {
				$same = false;
				break;
			}
		}

		$rows = array();

		if ( $same ) {
			$rows[] = array_merge( array( __( 'Group', 'hw-content-health' ) ), $blocks[0]['rows'][0] );

			foreach ( $blocks as $n => $block ) {
				$label = $block['title'] ? $block['title'] : sprintf( /* translators: %d: group number */ __( 'Group %d', 'hw-content-health' ), $n + 1 );

				foreach ( array_slice( $block['rows'], 1 ) as $row ) {
					$rows[] = array_merge( array( $label ), $row );
				}
			}

			return $rows;
		}

		// Different tables on one tab: one labelled block each.
		foreach ( $blocks as $block ) {
			if ( $rows ) {
				$rows[] = array();
			}

			if ( '' !== $block['title'] ) {
				$rows[] = array( $block['title'] );
			}

			foreach ( $block['rows'] as $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * The heading a table sits under, for labelling its block.
	 *
	 * @param DOMElement $table Table.
	 * @param DOMXPath   $xpath XPath.
	 * @return string
	 */
	private static function table_title( $table, $xpath ) {
		// A table inside a group box is labelled by the group's own heading.
		$group = $xpath->query( 'ancestor::*[contains(concat(" ", normalize-space(@class), " "), " hwch-group ")][1]/*[contains(concat(" ", normalize-space(@class), " "), " hwch-group-head ")]', $table );

		if ( $group->length ) {
			$parts = array();
			foreach ( $group->item( 0 )->childNodes as $child ) {
				$text = self::clean( $child->textContent );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
			}
			return implode( ' · ', $parts );
		}

		$heading = $xpath->query( 'preceding::*[self::h2 or self::h3][1]', $table );

		return $heading->length ? self::clean( $heading->item( 0 )->textContent ) : '';
	}

	/**
	 * One table as rows: header first.
	 *
	 * @param DOMElement $table Table.
	 * @param DOMXPath   $xpath XPath.
	 * @return array
	 */
	private static function table_rows( $table, $xpath ) {
		$trs  = $xpath->query( './thead/tr | ./tbody/tr | ./tr | ./tfoot/tr', $table );
		$grid = array();
		$skip = array();
		$urls = array();

		foreach ( $trs as $tr ) {
			$cells = array();

			foreach ( $tr->childNodes as $cell ) {
				if ( ! $cell instanceof DOMElement || ! in_array( strtolower( $cell->nodeName ), array( 'td', 'th' ), true ) ) {
					continue;
				}

				$cells[] = $cell;
			}

			if ( $cells ) {
				$grid[] = $cells;
			}
		}

		if ( ! $grid ) {
			return array();
		}

		// The first row is the header when it is made of th cells.
		$has_header = 'th' === strtolower( $grid[0][0]->nodeName ) && 'thead' === strtolower( $grid[0][0]->parentNode->parentNode->nodeName );
		$actions    = array( strtolower( __( 'Actions', 'hw-content-health' ) ), 'actions', '' );

		// Columns to leave out: Actions, and any column that is nothing but
		// buttons and forms.
		$width = 0;
		foreach ( $grid as $cells ) {
			$width = max( $width, count( $cells ) );
		}

		for ( $c = 0; $c < $width; $c++ ) {
			$only_controls = true;
			$any           = false;

			foreach ( $grid as $r => $cells ) {
				if ( ! isset( $cells[ $c ] ) || ( $has_header && 0 === $r ) ) {
					continue;
				}

				$any = true;

				if ( false !== strpos( ' ' . $cells[ $c ]->getAttribute( 'class' ) . ' ', ' hwch-actions ' ) ) {
					continue;
				}

				if ( '' !== self::cell_text( $cells[ $c ], $xpath ) ) {
					$only_controls = false;
				}

				if ( $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " hwch-url ")]//a', $cells[ $c ] )->length ) {
					$urls[ $c ] = true;
				}
			}

			$header_text = ( $has_header && isset( $grid[0][ $c ] ) ) ? strtolower( self::clean( $grid[0][ $c ]->textContent ) ) : null;

			$is_actions = null !== $header_text && in_array( $header_text, array_slice( $actions, 0, 2 ), true );

			// A column marked hwch-noexport is a shortcut for the screen only,
			// such as the "Check in Ahrefs" links.
			if ( $has_header && isset( $grid[0][ $c ] ) && false !== strpos( ' ' . $grid[0][ $c ]->getAttribute( 'class' ) . ' ', ' hwch-noexport ' ) ) {
				$is_actions = true;
			}

			if ( $is_actions || ( $any && $only_controls ) ) {
				$skip[ $c ] = true;
			}
		}

		$out = array();

		foreach ( $grid as $r => $cells ) {
			$row = array();

			for ( $c = 0; $c < $width; $c++ ) {
				if ( isset( $skip[ $c ] ) ) {
					continue;
				}

				$cell = isset( $cells[ $c ] ) ? $cells[ $c ] : null;

				if ( $has_header && 0 === $r ) {
					$label = $cell ? self::clean( str_replace( array( '▲', '▼' ), '', $cell->textContent ) ) : '';
					$row[] = $label;

					if ( isset( $urls[ $c ] ) ) {
						$row[] = __( 'URL', 'hw-content-health' );
					}
					continue;
				}

				$row[] = $cell ? self::number( self::cell_text( $cell, $xpath, isset( $urls[ $c ] ) ) ) : '';

				if ( isset( $urls[ $c ] ) ) {
					$link  = $cell ? $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " hwch-url ")]//a', $cell ) : null;
					$row[] = ( $link && $link->length ) ? $link->item( 0 )->getAttribute( 'href' ) : '';
				}
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * The readable text of a cell: controls dropped, separate pieces joined
	 * with " · " rather than run together, and a small table inside the cell
	 * flattened to "a: b; c: d".
	 *
	 * @param DOMElement $cell      Cell.
	 * @param DOMXPath   $xpath     XPath.
	 * @param bool       $skip_urls Leave out the address line (it has its own column).
	 * @return string
	 */
	private static function cell_text( $cell, $xpath, $skip_urls = false ) {
		$parts = array();
		self::collect( $cell, $parts, $skip_urls );

		$parts = array_values( array_filter( array_map( array( __CLASS__, 'clean' ), $parts ), 'strlen' ) );

		// "3 months ago" under a date is for reading on screen; in a sheet it
		// only stops the date column sorting.
		$ago   = sprintf( __( '%s ago', 'hw-content-health' ), '' );
		$parts = array_values(
			array_filter(
				$parts,
				function ( $part ) use ( $ago ) {
					return ! ( count( explode( ' ', $part ) ) <= 4 && '' !== trim( $ago ) && substr( $part, -strlen( trim( $ago ) ) ) === trim( $ago ) && preg_match( '/\d|an? /', $part ) );
				}
			)
		);

		return implode( ' · ', $parts );
	}

	/**
	 * Walks a cell, gathering one piece of text per block or badge.
	 *
	 * @param DOMNode $node      Node.
	 * @param array   $parts     Collected pieces.
	 * @param bool    $skip_urls Skip .hwch-url.
	 * @return void
	 */
	private static function collect( $node, &$parts, $skip_urls ) {
		$blocks = array( 'div', 'p', 'li', 'ul', 'ol', 'br', 'span', 'details', 'summary', 'small', 'strong', 'em', 'code' );
		$buffer = '';

		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMText ) {
				$buffer .= $child->textContent;
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$name  = strtolower( $child->nodeName );
			$class = ' ' . $child->getAttribute( 'class' ) . ' ';

			// A field the tab lets you edit in place, like a title or a meta
			// description, holds that article's current value: keep it.
			if ( 'input' === $name && in_array( strtolower( $child->getAttribute( 'type' ) ? $child->getAttribute( 'type' ) : 'text' ), array( 'text', 'search', 'url' ), true ) ) {
				$buffer = self::flush( $buffer, $parts );
				self::flush( $child->getAttribute( 'value' ), $parts );
				continue;
			}

			if ( 'textarea' === $name ) {
				$buffer = self::flush( $buffer, $parts );
				self::flush( $child->textContent, $parts );
				continue;
			}

			if ( 'form' === $name ) {
				// Forms hold buttons, but also the editable fields above.
				$buffer = self::flush( $buffer, $parts );
				self::collect( $child, $parts, $skip_urls );
				continue;
			}

			if ( in_array( $name, array( 'button', 'input', 'select', 'label', 'script', 'style', 'svg', 'noscript', 'template' ), true ) ) {
				continue;
			}

			if ( false !== strpos( $class, ' screen-reader-text ' ) || false !== strpos( $class, ' dashicons ' ) || ( 'a' === $name && false !== strpos( $class, ' button ' ) ) ) {
				continue;
			}

			if ( $skip_urls && false !== strpos( $class, ' hwch-url ' ) ) {
				continue;
			}

			if ( 'table' === $name ) {
				$buffer = self::flush( $buffer, $parts );
				$lines  = array();

				foreach ( $child->getElementsByTagName( 'tr' ) as $tr ) {
					$cells = array();
					foreach ( $tr->childNodes as $td ) {
						if ( $td instanceof DOMElement ) {
							$cells[] = self::clean( $td->textContent );
						}
					}
					$cells = array_filter( $cells, 'strlen' );
					if ( $cells ) {
						$lines[] = implode( ': ', $cells );
					}
				}

				if ( $lines ) {
					$parts[] = implode( '; ', $lines );
				}
				continue;
			}

			// Inline pieces like a link or an emphasised word belong to the
			// sentence around them; block pieces and badges start a new part.
			if ( in_array( $name, array( 'a', 'strong', 'em', 'b', 'i', 'code', 'abbr', 'time', 'small' ), true ) && false === strpos( $class, 'badge' ) && false === strpos( $class, 'hwch-muted' ) ) {
				$inner = array();
				self::collect( $child, $inner, $skip_urls );
				$buffer .= implode( ' ', $inner );
				continue;
			}

			if ( in_array( $name, $blocks, true ) || 'span' === $name ) {
				$buffer = self::flush( $buffer, $parts );
				self::collect( $child, $parts, $skip_urls );
				continue;
			}

			$buffer = self::flush( $buffer, $parts );
			self::collect( $child, $parts, $skip_urls );
		}

		self::flush( $buffer, $parts );
	}

	/**
	 * Adds buffered text as a part.
	 *
	 * @param string $buffer Text.
	 * @param array  $parts  Parts.
	 * @return string Empty buffer.
	 */
	private static function flush( $buffer, &$parts ) {
		if ( '' !== trim( $buffer ) ) {
			$parts[] = $buffer;
		}

		return '';
	}

	/**
	 * Collapses whitespace.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function clean( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/[\s\x{00A0}]+/u', ' ', $text );

		return trim( (string) $text, " \t\n\r\0\x0B·|" );
	}

	/**
	 * "12,345" becomes 12345 so spreadsheets read it as a number. Anything
	 * that is not purely a number is left alone.
	 *
	 * @param string $text Cell text.
	 * @return string
	 */
	private static function number( $text ) {
		if ( preg_match( '/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $text ) ) {
			return str_replace( ',', '', $text );
		}

		return $text;
	}
}
