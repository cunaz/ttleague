<?php
/**
 * Verlauf der Wertungszahl als serverseitiges Inline-SVG mit Tabelle als Alternative.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Frontend\Support\Format;

/**
 * Eine Linie pro Saison (die Wertungszahl wird zu Saisonbeginn auf Klassierung × 100
 * neu verankert); Saisongrenzen als senkrechte Haarlinie mit Saisonname, dazwischen
 * eine Lücke statt einer Verbindungslinie. Farben kommen per CSS aus den Theme-Presets.
 * Pro Spieltag ein unsichtbares Hover-Band mit nativer Beschriftung (title). Das SVG wird
 * auf die Fläche gestreckt; Beschriftungen sind HTML und bleiben auf dem Handy lesbar.
 */
final class RatingChart {

	/**
	 * Tagespunkte: letzter Wert pro Datum mit Summe der Änderungen und Anzahl Spiele.
	 *
	 * @param list<array{at:string,before:float,after:float,delta:float,game_id:int}> $history Verlauf.
	 * @param list<array{slug:string,name:string,start_date:string,end_date:string}>  $seasons Saisons.
	 * @return list<array{date:string,season:string,season_name:string,value:float,start:float,delta:float,games:int}>
	 */
	public static function days( array $history, array $seasons ): array {
		$days = array();
		foreach ( $history as $event ) {
			$date = substr( $event['at'], 0, 10 );
			if ( ! isset( $days[ $date ] ) ) {
				$season        = self::season_of( $date, $seasons );
				$days[ $date ] = array(
					'date'        => $date,
					'season'      => null === $season ? '' : $season['slug'],
					'season_name' => null === $season ? '' : $season['name'],
					'value'       => $event['after'],
					'start'       => $event['before'],
					'delta'       => 0.0,
					'games'       => 0,
				);
			}
			$days[ $date ]['value']  = $event['after'];
			$days[ $date ]['delta'] += $event['delta'];
			++$days[ $date ]['games'];
		}
		ksort( $days );
		return array_values( $days );
	}

	/**
	 * Grafik als HTML: gestrecktes SVG (Linien mit nicht skalierender Strichbreite) und
	 * HTML-Beschriftungen, die auf jeder Breite lesbar bleiben. Leer ohne Verlauf.
	 *
	 * @param list<array<string,mixed>> $days  Tagespunkte aus days().
	 * @param string                    $id    Eindeutiges Präfix für IDs.
	 * @param string                    $title Titel (Name der Grafik).
	 * @param string                    $desc  Beschreibung für Screenreader.
	 * @return array{svg:string,html:string} SVG (für wp_kses) und umgebendes HTML mit Platzhalter {svg}.
	 */
	public static function parts( array $days, string $id, string $title, string $desc ): array {
		if ( array() === $days ) {
			return array(
				'svg'  => '',
				'html' => '',
			);
		}
		$first = self::timestamp( $days[0]['date'] );
		$last  = self::timestamp( $days[ count( $days ) - 1 ]['date'] );
		$span  = max( 1, $last - $first );

		$values = array();
		foreach ( $days as $day ) {
			$values[] = $day['value'];
			$values[] = $day['start'];
		}
		$min  = min( $values );
		$max  = max( $values );
		$step = self::tick_step( $max - $min );
		$low  = floor( ( $min - $step * 0.15 ) / $step ) * $step;
		$high = ceil( ( $max + $step * 0.15 ) / $step ) * $step;
		if ( $high - $low < $step * 2 ) {
			$high = $low + $step * 2;
		}

		// Koordinaten 0..1000 in beide Richtungen; das SVG wird auf die Fläche gestreckt.
		$x = static fn( string $date ): float => 1 === count( $days ) ? 500.0 : ( self::timestamp( $date ) - $first ) / $span * 1000;
		$y = static fn( float $value ): float => 1000 - ( $value - $low ) / ( $high - $low ) * 1000;

		$svg    = '<svg class="ttl-chart__svg" viewBox="0 0 1000 1000" preserveAspectRatio="none" aria-hidden="true" focusable="false">';
		$labels = '';

		$svg .= '<g class="ttl-chart__grid">';
		for ( $tick = $low; $tick <= $high + 0.001; $tick += $step ) {
			$ty      = $y( $tick );
			$svg    .= '<line x1="0" x2="1000" y1="' . self::num( $ty ) . '" y2="' . self::num( $ty ) . '" vector-effect="non-scaling-stroke"></line>';
			$labels .= '<span class="ttl-chart__ytick" style="top:' . self::pct( $ty ) . '">' . esc_html( (string) (int) $tick ) . '</span>';
		}
		$svg .= '</g>';

		$segments = array();
		foreach ( $days as $day ) {
			$segments[ $day['season'] ][] = $day;
		}
		$svg     .= '<g class="ttl-chart__seasons">';
		$previous = null;
		foreach ( $segments as $segment ) {
			if ( null !== $previous ) {
				$bx   = ( $x( $segment[0]['date'] ) + $x( $previous['date'] ) ) / 2;
				$svg .= '<line class="ttl-chart__boundary" x1="' . self::num( $bx ) . '" x2="' . self::num( $bx ) . '" y1="0" y2="1000" vector-effect="non-scaling-stroke"></line>';
			}
			$name = (string) $segment[0]['season_name'];
			if ( '' !== $name ) {
				$labels .= '<span class="ttl-chart__season" style="left:' . self::pct( $x( $segment[0]['date'] ) ) . '">' . esc_html( self::short_season( $name ) ) . '</span>';
			}
			$previous = $segment[ count( $segment ) - 1 ];
		}
		$svg .= '</g><g class="ttl-chart__lines">';
		foreach ( $segments as $segment ) {
			$points = array( self::num( $x( $segment[0]['date'] ) ) . ',' . self::num( $y( $segment[0]['start'] ) ) );
			foreach ( $segment as $day ) {
				$points[] = self::num( $x( $day['date'] ) ) . ',' . self::num( $y( $day['value'] ) );
			}
			$svg .= '<polyline class="ttl-chart__line" points="' . implode( ' ', $points ) . '" vector-effect="non-scaling-stroke"></polyline>';
		}
		$svg .= '</g><g class="ttl-chart__hover">';

		$count = count( $days );
		foreach ( $days as $i => $day ) {
			$cx    = $x( $day['date'] );
			$left  = 0 === $i ? 0.0 : ( $cx + $x( $days[ $i - 1 ]['date'] ) ) / 2;
			$right = $count - 1 === $i ? 1000.0 : ( $cx + $x( $days[ $i + 1 ]['date'] ) ) / 2;
			/* translators: 1: Datum, 2: Wertungszahl, 3: Veränderung, 4: Anzahl Spiele. */
			$label = sprintf( _n( '%1$s: %2$s (%3$s, %4$d Spiel)', '%1$s: %2$s (%3$s, %4$d Spiele)', $day['games'], 'ttleague' ), Format::date( $day['date'] ), Format::rating( $day['value'] ), Format::delta( $day['delta'] ), $day['games'] );
			$svg  .= '<g class="ttl-chart__band"><rect x="' . self::num( $left ) . '" y="0" width="' . self::num( max( 0.5, $right - $left ) ) . '" height="1000"></rect>'
				. '<line x1="' . self::num( $cx ) . '" x2="' . self::num( $cx ) . '" y1="0" y2="1000" vector-effect="non-scaling-stroke"></line>'
				. '<title>' . esc_html( $label ) . '</title></g>';
		}
		$svg .= '</g></svg>';

		$first_year = (int) substr( $days[0]['date'], 0, 4 ) + 1;
		$end        = $days[ $count - 1 ];
		$last_year  = (int) substr( $end['date'], 0, 4 );
		for ( $year = $first_year; $year <= $last_year; $year++ ) {
			$tx = $x( $year . '-01-01' );
			if ( $tx >= 0 && $tx <= 1000 ) {
				$labels .= '<span class="ttl-chart__year" style="left:' . self::pct( $tx ) . '">' . esc_html( (string) $year ) . '</span>';
			}
		}
		$labels .= '<span class="ttl-chart__end" style="left:' . self::pct( $x( $end['date'] ) ) . ';top:' . self::pct( $y( $end['value'] ) ) . '"><span class="ttl-chart__end-value">' . esc_html( Format::rating( $end['value'] ) ) . '</span></span>';

		$html = '<div class="ttl-chart__plot" role="img" aria-labelledby="' . esc_attr( $id ) . '-title" aria-describedby="' . esc_attr( $id ) . '-desc">'
			. '<span class="screen-reader-text" id="' . esc_attr( $id ) . '-title">' . esc_html( $title ) . '</span>'
			. '<span class="screen-reader-text" id="' . esc_attr( $id ) . '-desc">' . esc_html( $desc ) . '</span>'
			. '<div class="ttl-chart__area" aria-hidden="true">{svg}' . $labels . '</div></div>';

		return array(
			'svg'  => $svg,
			'html' => $html,
		);
	}

	/**
	 * Erlaubtes Markup für wp_kses.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed_html(): array {
		$common = array(
			'class'             => true,
			'id'                => true,
			'aria-hidden'       => true,
			'x'                 => true,
			'y'                 => true,
			'x1'                => true,
			'x2'                => true,
			'y1'                => true,
			'y2'                => true,
			'width'             => true,
			'height'            => true,
			'points'            => true,
			'cx'                => true,
			'cy'                => true,
			'r'                 => true,
			'text-anchor'       => true,
			'dominant-baseline' => true,
			'vector-effect'     => true,
		);
		return array(
			'svg'      => $common + array(
				'viewbox'             => true,
				'focusable'           => true,
				'preserveaspectratio' => true,
				'xmlns'               => true,
			),
			'title'    => array( 'id' => true ),
			'desc'     => array( 'id' => true ),
			'g'        => $common,
			'line'     => $common,
			'polyline' => $common,
			'circle'   => $common,
			'rect'     => $common,
			'text'     => $common,
		);
	}

	/**
	 * Abstand der Hilfslinien: 50, 100, 200 oder 500.
	 */
	private static function tick_step( float $range ): float {
		foreach ( array( 25.0, 50.0, 100.0, 200.0, 250.0, 500.0 ) as $step ) {
			if ( $range / $step <= 5 ) {
				return $step;
			}
		}
		return 1000.0;
	}

	/**
	 * «2026/27» → «26/27».
	 */
	private static function short_season( string $name ): string {
		return preg_match( '/^\d{2}(\d{2}\/\d{2})$/', $name, $m ) ? $m[1] : $name;
	}

	/**
	 * @param list<array{slug:string,name:string,start_date:string,end_date:string}> $seasons Saisons.
	 * @return array{slug:string,name:string,start_date:string,end_date:string}|null
	 */
	private static function season_of( string $date, array $seasons ): ?array {
		foreach ( $seasons as $season ) {
			if ( $date >= $season['start_date'] && $date <= $season['end_date'] ) {
				return $season;
			}
		}
		return null;
	}

	private static function timestamp( string $date ): int {
		return (int) ( new \DateTimeImmutable( substr( $date, 0, 10 ), new \DateTimeZone( 'UTC' ) ) )->format( 'U' );
	}

	/**
	 * Koordinate 0..1000 als Prozentwert für CSS.
	 */
	private static function pct( float $value ): string {
		return self::num( max( 0.0, min( 1000.0, $value ) ) / 10 ) . '%';
	}

	private static function num( float $value ): string {
		return rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' );
	}
}
