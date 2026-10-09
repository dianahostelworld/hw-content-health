<?php
/**
 * Language filter: which language the Dashboard, Tracked URLs, Quick wins
 * and Worth refreshing show.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Knows the blog's languages (from WPML or Polylang), which one is chosen,
 * and how to tell an article's or URL's language.
 */
class HWCH_Lang {

	const META = 'hwch_lang';

	/**
	 * The site's languages.
	 *
	 * @return array Map of code => name. Empty when no language plugin runs.
	 */
	public static function languages() {
		static $langs = null;

		if ( null !== $langs ) {
			return $langs;
		}

		$langs = array();

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			foreach ( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) as $code => $lang ) {
				$langs[ $code ] = ! empty( $lang['translated_name'] ) ? $lang['translated_name'] : ( ! empty( $lang['native_name'] ) ? $lang['native_name'] : $code );
			}
		} elseif ( function_exists( 'pll_languages_list' ) ) {
			$slugs = pll_languages_list( array( 'fields' => 'slug' ) );
			$names = pll_languages_list( array( 'fields' => 'name' ) );

			foreach ( (array) $slugs as $i => $slug ) {
				$langs[ $slug ] = isset( $names[ $i ] ) ? $names[ $i ] : $slug;
			}
		}

		return $langs;
	}

	/**
	 * The site's main language, whose URLs have no language folder.
	 *
	 * @return string
	 */
	public static function default_code() {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return (string) apply_filters( 'wpml_default_language', 'en' );
		}

		if ( function_exists( 'pll_default_language' ) ) {
			return (string) pll_default_language();
		}

		return 'en';
	}

	/**
	 * The chosen language, remembered per person: '' means all languages.
	 *
	 * @return string
	 */
	public static function current() {
		$langs = self::languages();

		if ( ! $langs ) {
			return '';
		}

		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['hwlang'] ) ) {
			$code = sanitize_key( $_GET['hwlang'] );
			$code = isset( $langs[ $code ] ) ? $code : '';
			update_user_meta( get_current_user_id(), self::META, $code );

			return $code;
		}
		// phpcs:enable

		$saved = (string) get_user_meta( get_current_user_id(), self::META, true );

		return isset( $langs[ $saved ] ) ? $saved : '';
	}

	/**
	 * The language folders of the other languages, e.g. es, ja, ko.
	 *
	 * @return string[]
	 */
	public static function other_codes() {
		return array_values( array_diff( array_keys( self::languages() ), array( self::default_code() ) ) );
	}

	/**
	 * A URL's language, from its folder after the blog address
	 * (/blog/es/… is Spanish; no folder is the main language).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function of_url( $url ) {
		$prefix = (string) wp_parse_url( (string) HWCH_Settings::get( 'url_prefix' ), PHP_URL_PATH );
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( $prefix && 0 === strpos( $path, $prefix ) ) {
			$path = substr( $path, strlen( $prefix ) );
		}

		$first = strtolower( (string) strtok( ltrim( $path, '/' ), '/' ) );

		return in_array( $first, self::other_codes(), true ) ? $first : self::default_code();
	}

	/**
	 * Which of these articles are in a language.
	 *
	 * @param int[]  $ids  Post IDs.
	 * @param string $code Language code.
	 * @return int[]
	 */
	public static function ids_in( $ids, $code ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );

		if ( ! $ids || '' === $code ) {
			return $ids;
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$found = array();

			foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
				$found = array_merge(
					$found,
					$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->prepare(
							"SELECT element_id FROM {$wpdb->prefix}icl_translations WHERE element_type = 'post_post' AND language_code = %s AND element_id IN (" . implode( ',', $chunk ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL
							$code
						)
					)
				);
			}

			return array_map( 'intval', $found );
		}

		if ( function_exists( 'pll_get_post_language' ) ) {
			return self::pll_filter( $ids, $code, false );
		}

		return $ids;
	}

	/**
	 * The articles in a Polylang language, read in one query per 2,000
	 * articles instead of one lookup per article. Same rule as
	 * pll_get_post_language(): the language term's slug. Keeps the order of
	 * $ids.
	 *
	 * @param int[]  $ids    Post IDs.
	 * @param string $code   Language slug (en, fr…).
	 * @param bool   $prefix Match any slug starting with $code (en, en-gb…).
	 * @return int[]
	 */
	public static function pll_filter( $ids, $code, $prefix ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );

		if ( ! $ids ) {
			return array();
		}

		$found = array();

		foreach ( array_chunk( $ids, 2000 ) as $chunk ) {
			$sql = "SELECT tr.object_id FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'language'
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				WHERE t.slug " . ( $prefix ? 'LIKE %s' : '= %s' ) . '
				AND tr.object_id IN (' . implode( ',', $chunk ) . ')';

			foreach ( (array) $wpdb->get_col( $wpdb->prepare( $sql, $prefix ? $wpdb->esc_like( $code ) . '%' : $code ) ) as $id ) { // phpcs:ignore WordPress.DB
				$found[ (int) $id ] = true;
			}
		}

		return array_values(
			array_filter(
				$ids,
				function ( $id ) use ( $found ) {
					return isset( $found[ $id ] );
				}
			)
		);
	}

	/**
	 * Search Console page filters for the chosen language.
	 *
	 * @param string $code Language code, or '' for all.
	 * @return array Extra filters (each dimension/operator/expression).
	 */
	public static function gsc_filters( $code ) {
		if ( '' === $code || ! self::languages() ) {
			return array();
		}

		$prefix = untrailingslashit( (string) HWCH_Settings::get( 'url_prefix' ) );

		if ( $code !== self::default_code() ) {
			return array( array( 'dimension' => 'page', 'operator' => 'contains', 'expression' => $prefix . '/' . $code . '/' ) );
		}

		$others = self::other_codes();

		if ( ! $others ) {
			return array();
		}

		$path = untrailingslashit( (string) wp_parse_url( $prefix, PHP_URL_PATH ) );

		return array( array( 'dimension' => 'page', 'operator' => 'excludingRegex', 'expression' => preg_quote( $path, '' ) . '/(' . implode( '|', array_map( 'preg_quote', $others ) ) . ')/' ) );
	}

	/**
	 * The language picker.
	 *
	 * @return void
	 */
	public static function picker() {
		$langs = self::languages();

		if ( ! $langs ) {
			return;
		}

		$current = self::current();
		?>
		<div class="hwch-lang">
			<span class="hwch-muted"><?php esc_html_e( 'Language:', 'hw-content-health' ); ?></span>
			<a class="<?php echo '' === $current ? 'current' : ''; ?>" href="<?php echo esc_url( remove_query_arg( array( 'paged', 'refresh' ), add_query_arg( 'hwlang', 'all' ) ) ); ?>"><?php esc_html_e( 'All', 'hw-content-health' ); ?></a>
			<?php foreach ( $langs as $code => $name ) : ?>
				<a class="<?php echo $current === $code ? 'current' : ''; ?>" href="<?php echo esc_url( remove_query_arg( array( 'paged', 'refresh' ), add_query_arg( 'hwlang', $code ) ) ); ?>"><?php echo esc_html( $name ); ?></a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Articles and pages whose address has a language folder other than
	 * English (/blog/de/…), for sites where neither WPML nor Polylang tells
	 * the plugin an article's language. A folder only counts once at least
	 * three published articles use it, so an ordinary two-letter address is
	 * never mistaken for a language. Worked out once a day.
	 *
	 * @return array Map of post ID => language code.
	 */
	public static function folders() {
		global $wpdb;

		$cached = get_transient( 'hwch_lang_folders' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$found = array();
		$uses  = array();

		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status = 'publish'" ) as $id ) { // phpcs:ignore WordPress.DB
			$code = self::folder_in( get_permalink( (int) $id ) );

			if ( $code ) {
				$found[ (int) $id ] = $code;
				$uses[ $code ]      = ( isset( $uses[ $code ] ) ? $uses[ $code ] : 0 ) + 1;
			}
		}

		$found = array_filter(
			$found,
			function ( $code ) use ( $uses ) {
				return $uses[ $code ] >= 3;
			}
		);

		set_transient( 'hwch_lang_folders', $found, DAY_IN_SECONDS );

		return $found;
	}

	/**
	 * The language folder in an address, if it has one that is not English:
	 * the first part after the blog address, when it looks like a language
	 * code (de, pt-br, zh-hans…).
	 *
	 * @param string $url URL.
	 * @return string Code, or ''.
	 */
	public static function folder_in( $url ) {
		$prefix = untrailingslashit( (string) wp_parse_url( (string) HWCH_Settings::get( 'url_prefix' ), PHP_URL_PATH ) );
		$path   = (string) wp_parse_url( (string) $url, PHP_URL_PATH );

		if ( $prefix && 0 === strpos( $path, $prefix . '/' ) ) {
			$path = substr( $path, strlen( $prefix ) );
		}

		// The language folder is the first part after the blog address, or the
		// second if the blog folder itself is still in the path (when the
		// Blog URL setting is the site root). The last part is the article
		// itself, never a folder.
		$parts = array_values( array_filter( explode( '/', strtolower( trim( $path, '/' ) ) ), 'strlen' ) );

		foreach ( array_slice( $parts, 0, 2 ) as $i => $part ) {
			if ( $i < count( $parts ) - 1 && preg_match( '/^[a-z]{2}(-[a-z]{2,4})?$/', $part ) ) {
				return 0 === strpos( $part, 'en' ) ? '' : $part;
			}
		}

		return '';
	}

	/**
	 * Whether one article or page is in English, whatever the language set-up.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	public static function is_english_post( $id ) {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, $id );
			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
				return 0 === strpos( $details['language_code'], 'en' );
			}
		} elseif ( function_exists( 'pll_get_post_language' ) && pll_get_post_language( $id ) ) {
			return 0 === strpos( (string) pll_get_post_language( $id ), 'en' );
		}

		$folders = self::folders();

		return isset( $folders[ $id ] ) ? false : '' === self::folder_in( get_permalink( $id ) );
	}
}
