<?php
/**
 * Formatierung für die Anzeige: Datum, Zeit, Resultate, Klassierung, Status.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Support;

use TTLeague\Domain\Rating\ClassificationScale;

/**
 * Reine Funktionen (ausser den Übersetzungsaufrufen). Zeitangaben der Datenbank sind
 * Ortszeit im Format «Y-m-d H:i:s» und werden nicht umgerechnet.
 */
final class Format {

	/**
	 * «Di, 8.9.2026»; leer bei fehlendem oder ungültigem Datum.
	 */
	public static function date( ?string $value ): string {
		$parts = self::parts( $value );
		if ( null === $parts ) {
			return '';
		}
		return sprintf( '%s, %d.%d.%d', self::weekday_short( $parts['weekday'] ), $parts['day'], $parts['month'], $parts['year'] );
	}

	/**
	 * «8.9.2026» ohne Wochentag.
	 */
	public static function date_plain( ?string $value ): string {
		$parts = self::parts( $value );
		return null === $parts ? '' : sprintf( '%d.%d.%d', $parts['day'], $parts['month'], $parts['year'] );
	}

	/**
	 * «8.9.» für enge Spalten.
	 */
	public static function date_short( ?string $value ): string {
		$parts = self::parts( $value );
		return null === $parts ? '' : sprintf( '%d.%d.', $parts['day'], $parts['month'] );
	}

	/**
	 * «20:00»; leer ohne Zeitanteil.
	 */
	public static function time( ?string $value ): string {
		if ( null === $value || ! preg_match( '/(?:^|\s)(\d{2}):(\d{2})(?::\d{2})?$/', trim( $value ), $m ) ) {
			return '';
		}
		return $m[1] . ':' . $m[2];
	}

	/**
	 * «Di, 8.9.2026, 20:00».
	 */
	public static function datetime( ?string $value ): string {
		$date = self::date( $value );
		$time = self::time( $value );
		if ( '' === $date ) {
			return '';
		}
		return '' === $time ? $date : $date . ', ' . $time;
	}

	/**
	 * Zeitraum «21.–24.9.2026», über Monatsgrenzen «28.9.–1.10.2026».
	 */
	public static function date_range( ?string $from, ?string $to ): string {
		$a = self::parts( $from );
		$b = self::parts( $to );
		if ( null === $a || null === $b ) {
			return self::date_plain( $from ?? $to );
		}
		if ( $a['year'] === $b['year'] && $a['month'] === $b['month'] ) {
			return $a['day'] === $b['day'] ? self::date_plain( $from ) : sprintf( '%d.–%d.%d.%d', $a['day'], $b['day'], $b['month'], $b['year'] );
		}
		if ( $a['year'] === $b['year'] ) {
			return sprintf( '%d.%d.–%d.%d.%d', $a['day'], $a['month'], $b['day'], $b['month'], $b['year'] );
		}
		return self::date_plain( $from ) . '–' . self::date_plain( $to );
	}

	/**
	 * Wochentag 1 (Montag) bis 7 (Sonntag) ausgeschrieben.
	 */
	public static function weekday( ?int $iso ): string {
		$names = array(
			1 => __( 'Montag', 'ttleague' ),
			2 => __( 'Dienstag', 'ttleague' ),
			3 => __( 'Mittwoch', 'ttleague' ),
			4 => __( 'Donnerstag', 'ttleague' ),
			5 => __( 'Freitag', 'ttleague' ),
			6 => __( 'Samstag', 'ttleague' ),
			7 => __( 'Sonntag', 'ttleague' ),
		);
		return $names[ $iso ] ?? '';
	}

	/**
	 * Wochentag kurz: Mo, Di, Mi, Do, Fr, Sa, So.
	 */
	public static function weekday_short( ?int $iso ): string {
		$names = array(
			1 => __( 'Mo', 'ttleague' ),
			2 => __( 'Di', 'ttleague' ),
			3 => __( 'Mi', 'ttleague' ),
			4 => __( 'Do', 'ttleague' ),
			5 => __( 'Fr', 'ttleague' ),
			6 => __( 'Sa', 'ttleague' ),
			7 => __( 'So', 'ttleague' ),
		);
		return $names[ $iso ] ?? '';
	}

	/**
	 * Resultat «7:3»; ohne Resultat «–».
	 */
	public static function score( ?int $home, ?int $away ): string {
		if ( null === $home || null === $away ) {
			return '–';
		}
		return $home . ':' . $away;
	}

	/**
	 * Verhältnis «42:38».
	 */
	public static function ratio( int $won, int $lost ): string {
		return $won . ':' . $lost;
	}

	/**
	 * Klassierung als Label (D1 … A22), leer ohne Wert.
	 */
	public static function classification( ?int $classification ): string {
		return null === $classification || $classification < 1 ? '' : ClassificationScale::label( $classification );
	}

	/**
	 * Wertungszahl ganzzahlig gerundet, «–» ohne Wert.
	 */
	public static function rating( ?float $rating ): string {
		return null === $rating ? '–' : (string) (int) round( $rating );
	}

	/**
	 * Veränderung der Wertungszahl mit Vorzeichen und einer Nachkommastelle: «+9,0», «−10,5».
	 */
	public static function delta( float $delta ): string {
		$text = number_format( abs( $delta ), 1, ',', '' );
		if ( '0,0' === $text ) {
			return '±0,0';
		}
		return ( $delta < 0 ? '−' : '+' ) . $text;
	}

	/**
	 * Punkte mit Dezimalkomma ohne überflüssige Nullen: 6.0 → «6», 5.5 → «5,5».
	 */
	public static function points( int|float|string|null $points ): string {
		if ( null === $points || '' === $points ) {
			return '–';
		}
		$value = round( (float) $points, 2 );
		if ( floor( $value ) === $value ) {
			return (string) (int) $value;
		}
		return rtrim( rtrim( number_format( $value, 2, ',', '' ), '0' ), ',' );
	}

	/**
	 * Bezeichnung eines Begegnungsstatus.
	 */
	public static function fixture_status( string $status ): string {
		$labels = array(
			'scheduled'   => __( 'angesetzt', 'ttleague' ),
			'provisional' => __( 'provisorisch', 'ttleague' ),
			'confirmed'   => __( 'bestätigt', 'ttleague' ),
			'disputed'    => __( 'strittig', 'ttleague' ),
			'final'       => __( 'definitiv', 'ttleague' ),
			'postponed'   => __( 'verschoben', 'ttleague' ),
			'forfeit'     => __( 'Forfait', 'ttleague' ),
			'cancelled'   => __( 'annulliert', 'ttleague' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Markierung für den Spielplan: nur Status, die auffallen sollen; sonst null.
	 */
	public static function fixture_marker( string $status ): ?string {
		if ( in_array( $status, array( 'provisional', 'disputed', 'postponed', 'forfeit', 'cancelled' ), true ) ) {
			return self::fixture_status( $status );
		}
		return null;
	}

	/**
	 * Kennzeichnung eines Spiels: «w.o.», «nicht gespielt» oder leer.
	 */
	public static function game_status( string $status ): string {
		if ( 'walkover' === $status ) {
			return __( 'w.o.', 'ttleague' );
		}
		if ( 'not_played' === $status ) {
			return __( 'nicht gespielt', 'ttleague' );
		}
		return '';
	}

	/**
	 * Konkurrenz ausgeschrieben.
	 */
	public static function kind( string $kind ): string {
		$labels = array(
			'MM'  => __( 'Meisterschaft', 'ttleague' ),
			'CUP' => __( 'Cup', 'ttleague' ),
			'RL'  => __( 'Ranglistenturnier', 'ttleague' ),
		);
		return $labels[ $kind ] ?? $kind;
	}

	/**
	 * Konkurrenz kurz: MM, Cup, RL.
	 */
	public static function kind_short( string $kind ): string {
		$labels = array(
			'MM'  => __( 'MM', 'ttleague' ),
			'CUP' => __( 'Cup', 'ttleague' ),
			'RL'  => __( 'RL', 'ttleague' ),
		);
		return $labels[ $kind ] ?? $kind;
	}

	/**
	 * Adresse in einer Zeile: «Musterstrasse 1, 8000 Musterstadt».
	 */
	public static function address( ?string $street, ?string $zip, ?string $city ): string {
		$place = trim( trim( (string) $zip ) . ' ' . trim( (string) $city ) );
		return implode( ', ', array_filter( array( trim( (string) $street ), $place ), static fn( string $p ): bool => '' !== $p ) );
	}

	/**
	 * Kartenlink: OpenStreetMap-Suche über die Adresse.
	 */
	public static function map_url( string $address ): string {
		return 'https://www.openstreetmap.org/search?query=' . rawurlencode( $address );
	}

	/**
	 * @return array{year:int,month:int,day:int,weekday:int}|null
	 */
	private static function parts( ?string $value ): ?array {
		if ( null === $value || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', trim( $value ), $m ) ) {
			return null;
		}
		$year  = (int) $m[1];
		$month = (int) $m[2];
		$day   = (int) $m[3];
		if ( ! checkdate( $month, $day, $year ) ) {
			return null;
		}
		$weekday = (int) ( new \DateTimeImmutable( sprintf( '%04d-%02d-%02d', $year, $month, $day ), new \DateTimeZone( 'UTC' ) ) )->format( 'N' );
		return array(
			'year'    => $year,
			'month'   => $month,
			'day'     => $day,
			'weekday' => $weekday,
		);
	}
}
