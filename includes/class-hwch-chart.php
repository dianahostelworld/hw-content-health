<?php
/**
 * Small inline SVG charts for the admin screens: no chart library needed.
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

/**
 * Draws line charts and sparklines.
 */
class HWCH_Chart {

	/**
	 * A line chart of one or two series, with the first and last labels.
	 *
	 * @param array  $series List of array( values, colour, label, dashed ).
	 * @param array  $labels X labels, one per value.
	 * @param string $title  Accessible title.
	 * @param array  $marks  Annotations: list of array( from, to, kind, label ),
	 *                       where from and to are value positions. kind
	 *                       "google" draws a shaded band for the rollout;
	 *                       "change" draws a line on the day.
	 * @return string SVG markup.
	 */
	public static function lines( $series, $labels, $title, $marks = array() ) {
		$w   = 640;
		$h   = 170;
		$pad = 24;
		$max = 0;

		foreach ( $series as $line ) {
			$max = max( $max, $line['values'] ? max( $line['values'] ) : 0 );
		}

		$max = $max ? $max : 1;
		$svg = sprintf( '<svg class="hwch-chart" viewBox="0 0 %1$d %2$d" role="img" aria-label="%3$s" preserveAspectRatio="none">', $w, $h, esc_attr( $title ) );

		for ( $i = 0; $i <= 3; $i++ ) {
			$y    = $pad + ( $h - 2 * $pad ) * $i / 3;
			$svg .= sprintf( '<line x1="0" x2="%1$d" y1="%2$.1f" y2="%2$.1f" class="hwch-grid"/>', $w, $y );
		}

		$n = 0;
		foreach ( $series as $line ) {
			$n = max( $n, count( $line['values'] ) );
		}

		// Annotations sit behind the lines, so the lines stay readable.
		foreach ( $marks as $mark ) {
			$x1 = $n > 1 ? $w * $mark['from'] / ( $n - 1 ) : 0;
			$x2 = $n > 1 ? $w * $mark['to'] / ( $n - 1 ) : 0;

			if ( in_array( $mark['kind'], array( 'google', 'issue' ), true ) ) {
				$svg .= sprintf(
					'<rect x="%1$.1f" y="%2$d" width="%3$.1f" height="%4$d" class="hwch-mark-band%6$s"><title>%5$s</title></rect>',
					$x1,
					$pad - 8,
					max( 3, $x2 - $x1 ),
					$h - 2 * $pad + 8,
					esc_html( $mark['label'] ),
					'issue' === $mark['kind'] ? ' hwch-mark-band--issue' : ''
				);
			} else {
				$svg .= sprintf(
					'<g class="hwch-mark-change"><title>%3$s</title><line x1="%1$.1f" x2="%1$.1f" y1="%2$d" y2="%4$d"/><circle cx="%1$.1f" cy="%2$d" r="3.5"/></g>',
					$x1,
					$pad - 6,
					esc_html( $mark['label'] ),
					$h - $pad
				);
			}
		}

		foreach ( $series as $line ) {
			$svg .= sprintf(
				'<polyline fill="none" stroke="%1$s" stroke-width="2" %2$s points="%3$s"/>',
				esc_attr( $line['colour'] ),
				! empty( $line['dashed'] ) ? 'stroke-dasharray="5 4" opacity="0.55"' : '',
				esc_attr( self::points( $line['values'], $max, $w, $h, $pad ) )
			);
		}

		if ( $labels ) {
			$svg .= sprintf( '<text x="0" y="%1$d" class="hwch-axis">%2$s</text>', $h - 4, esc_html( reset( $labels ) ) );
			$svg .= sprintf( '<text x="%1$d" y="%2$d" class="hwch-axis" text-anchor="end">%3$s</text>', $w, $h - 4, esc_html( end( $labels ) ) );
		}

		$svg .= sprintf( '<text x="0" y="12" class="hwch-axis">%s</text>', esc_html( number_format_i18n( $max ) ) );

		return $svg . '</svg>';
	}

	/**
	 * Twelve small bars, January to December, for an article's clicks by
	 * calendar month. The months of its season are highlighted.
	 *
	 * @param array $values Map of calendar month (1-12) => clicks (null if unknown).
	 * @param int[] $season Calendar months to highlight.
	 * @return string SVG markup.
	 */
	public static function months( $values, $season = array() ) {
		$max = max( array_map( 'floatval', $values ) );
		$max = $max > 0 ? $max : 1;
		$svg = '<svg class="hwch-months" viewBox="0 0 120 30" aria-hidden="true" preserveAspectRatio="none">';

		for ( $m = 1; $m <= 12; $m++ ) {
			$value = isset( $values[ $m ] ) ? (float) $values[ $m ] : 0;
			$bar   = max( 1, round( 28 * $value / $max, 1 ) );
			$svg  .= sprintf(
				'<rect x="%1$d" y="%2$.1f" width="8" height="%3$.1f" class="%4$s"/>',
				( $m - 1 ) * 10,
				30 - $bar,
				$bar,
				in_array( $m, $season, true ) ? 'hwch-months-on' : 'hwch-months-off'
			);
		}

		return $svg . '</svg><div class="hwch-months-axis" aria-hidden="true"><span>J</span><span>D</span></div>';
	}

	/**
	 * A tiny trend line for a table cell.
	 *
	 * @param int[] $values Values.
	 * @return string SVG markup.
	 */
	public static function spark( $values ) {
		if ( count( $values ) < 2 ) {
			return '';
		}

		$max = max( $values ) ? max( $values ) : 1;

		return sprintf(
			'<svg class="hwch-spark" viewBox="0 0 110 28" aria-hidden="true" preserveAspectRatio="none"><polyline fill="none" stroke="#2271b1" stroke-width="1.5" points="%s"/></svg>',
			esc_attr( self::points( $values, $max, 110, 28, 2 ) )
		);
	}

	/**
	 * Polyline points for a series.
	 *
	 * @param array $values Values.
	 * @param float $max    Top of the scale.
	 * @param int   $w      Width.
	 * @param int   $h      Height.
	 * @param int   $pad    Vertical padding.
	 * @return string
	 */
	private static function points( $values, $max, $w, $h, $pad ) {
		$values = array_values( $values );
		$n      = count( $values );
		$out    = array();

		foreach ( $values as $i => $value ) {
			$x     = $n > 1 ? $w * $i / ( $n - 1 ) : 0;
			$y     = $h - $pad - ( $h - 2 * $pad ) * ( $value / $max );
			$out[] = round( $x, 1 ) . ',' . round( $y, 1 );
		}

		return implode( ' ', $out );
	}

	/**
	 * A change figure, coloured: green for better, red for worse. For
	 * position, lower is better, so the colours flip.
	 *
	 * @param float|null $now      Current value.
	 * @param float|null $before   Previous value.
	 * @param string     $kind     percent (relative change), points (difference) or position.
	 * @return string HTML.
	 */
	public static function change( $now, $before, $kind = 'percent' ) {
		if ( 'position' === $kind ) {
			if ( ! $now || ! $before ) {
				return '<span class="hwch-muted">–</span>';
			}

			$diff = round( $before - $now, 1 );

			if ( 0.0 === (float) $diff ) {
				return '<span class="hwch-muted">0</span>';
			}

			return sprintf( '<span class="%1$s">%2$s%3$s</span>', $diff > 0 ? 'hwch-up' : 'hwch-down', $diff > 0 ? '▲' : '▼', esc_html( number_format_i18n( abs( $diff ), 1 ) ) );
		}

		if ( ! $before ) {
			return $now ? '<span class="hwch-up">' . esc_html__( 'new', 'hw-content-health' ) . '</span>' : '<span class="hwch-muted">–</span>';
		}

		$pct = round( ( $now - $before ) / $before * 100 );

		return sprintf( '<span class="%1$s">%2$s%3$s%%</span>', $pct < 0 ? 'hwch-down' : 'hwch-up', $pct > 0 ? '+' : '', esc_html( number_format_i18n( $pct ) ) );
	}
}
