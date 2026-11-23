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
 * Verbindet die Wertungszahlen der gespielten Partien in Spielreihenfolge. Den Übergang
 * zur neu verankerten Saison kennzeichnet eine gestrichelte Linie ohne eigenen Spielpunkt.
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
	 * @param list<array<string,mixed>> $events Chronologische Wertungsereignisse mit Saison.
	 * @param string                    $id     Eindeutiges Präfix für IDs.
	 * @param string                    $title  Titel (Name der Grafik).
	 * @param string                    $desc   Beschreibung für Screenreader.
	 * @return array{svg:string,html:string} SVG (für wp_kses) und umgebendes HTML mit Platzhalter {svg}.
	 */
	public static function parts( array $events, string $id, string $title, string $desc ): array {
		if ( array() === $events ) {
			return array(
				'svg'  => '',
				'html' => '',
			);
		}

		// Pro Saison ein zusätzlicher Platz für den Startwert vor ihrer ersten Partie.
		$segments = array();
		foreach ( $events as $i => $event ) {
			if ( 0 === $i || $event['season'] !== $events[ $i - 1 ]['season'] ) {
				$segments[] = $i;
			}
		}
		$count = count( $events );
		$slots = $count + count( $segments );

		$low  = min( min( array_column( $events, 'before' ) ), min( array_column( $events, 'after' ) ) );
		$high = max( max( array_column( $events, 'before' ) ), max( array_column( $events, 'after' ) ) );
		$step = self::tick_step( $high - $low );
		$low  = floor( ( $low - $step * 0.15 ) / $step ) * $step;
		$high = max( $low + 2 * $step, ceil( ( $high + $step * 0.15 ) / $step ) * $step );
		$y    = static fn( float $rating ): string => self::num( 100 * ( $high - $rating ) / ( $high - $low ) );
		$left = static fn( float $slot ): string => self::num( 100 * $slot / $slots ) . '%';

		$svg   = '<svg class="ttl-chart__svg" viewBox="0 0 ' . $slots . ' 100" preserveAspectRatio="none" aria-hidden="true" focusable="false"><g class="ttl-chart__grid">';
		$scale = '';
		for ( $tick = $low; $tick <= $high + 0.001; $tick += $step ) {
			$ty     = $y( $tick );
			$svg   .= '<line x1="0" x2="' . $slots . '" y1="' . $ty . '" y2="' . $ty . '" vector-effect="non-scaling-stroke"></line>';
			$scale .= '<span class="ttl-chart__ytick" style="top:' . $ty . '%">' . esc_html( (string) (int) $tick ) . '</span>';
		}
		$svg .= '</g>';

		$labels         = '';
		$buttons        = '';
		$last_date_slot = -3;
		foreach ( $segments as $segment => $first ) {
			$stop   = $segments[ $segment + 1 ] ?? $count;
			$anchor = $first + $segment + 0.5;
			$start  = $y( $events[ $first ]['before'] );
			if ( $first > 0 ) {
				$previous = $y( $events[ $first - 1 ]['after'] );
				$svg     .= '<line class="ttl-chart__boundary" x1="' . $anchor . '" x2="' . $anchor . '" y1="0" y2="100" vector-effect="non-scaling-stroke"></line>'
					. '<line class="ttl-chart__reset" x1="' . ( $anchor - 1 ) . '" y1="' . $previous . '" x2="' . $anchor . '" y2="' . $start . '" vector-effect="non-scaling-stroke"></line>';
			}
			$name    = self::short_season( $events[ $first ]['season_name'] );
			$labels .= '<span class="ttl-chart__season" aria-hidden="true" style="left:' . $left( $anchor - 0.5 ) . ';width:' . $left( $stop - $first + 1 ) . '"><span>' . esc_html( $name ) . '</span></span>';
			$points  = array( $anchor . ',' . $start );
			for ( $i = $first; $i < $stop; ++$i ) {
				$event    = $events[ $i ];
				$slot     = $i + $segment + 1.5;
				$after    = $y( $event['after'] );
				$points[] = $slot . ',' . $after;
				$date     = substr( $event['at'], 0, 10 );
				if ( $slot - $last_date_slot >= 3 && ( $first === $i || substr( $events[ $i - 1 ]['at'], 0, 10 ) !== $date ) ) {
					$labels        .= '<span class="ttl-chart__date" aria-hidden="true" style="left:' . $left( $slot ) . '">' . esc_html( Format::date_short( $date ) ) . '</span>';
					$last_date_slot = $slot;
				}
				/* translators: 1: Spielnummer im Verlauf, 2: Datum, 3: Wertungszahl, 4: Veränderung. */
				$label    = sprintf( __( 'Spiel %1$d am %2$s, Wertungszahl %3$s (%4$s)', 'ttleague' ), $i + 1, Format::date_plain( $date ), Format::rating( $event['after'] ), Format::delta( $event['delta'] ) );
				$state    = $i === $count - 1 ? 'aria-pressed="true" tabindex="0"' : 'aria-pressed="false" tabindex="-1"';
				$buttons .= '<button type="button" disabled class="ttl-chart__point" data-index="' . $i . '" style="left:' . $left( $slot ) . ';top:' . $after . '%" ' . $state . ' aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '"></button>';
			}
			$svg .= '<polyline class="ttl-chart__line" points="' . implode( ' ', $points ) . '" vector-effect="non-scaling-stroke"></polyline>';
		}

		$html = '<div class="ttl-chart__frame" role="group" aria-labelledby="' . esc_attr( $id ) . '-title" aria-describedby="' . esc_attr( $id ) . '-desc">'
			. '<span class="screen-reader-text" id="' . esc_attr( $id ) . '-title">' . esc_html( $title ) . '</span>'
			. '<span class="screen-reader-text" id="' . esc_attr( $id ) . '-desc">' . esc_html( $desc ) . '</span>'
			. '<div class="ttl-chart__scale" aria-hidden="true">' . $scale . '</div>'
			. '<div class="ttl-chart__scroll"><div class="ttl-chart__plot" style="min-width:' . ( 1.75 * $slots ) . 'rem">'
			. '<div class="ttl-chart__area">{svg}' . $labels . $buttons . '</div></div></div></div>';

		return array(
			'svg'  => $svg . '</svg>',
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

	private static function num( float $value ): string {
		return rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );
	}
}
