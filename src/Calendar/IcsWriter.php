<?php
/**
 * Erzeugt iCalendar-Dateien (RFC 5545) für Kalender-Abos.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Calendar;

/**
 * Reine Klasse ohne WordPress. Formalien nach RFC 5545:
 *
 * - Zeilenende CRLF, auch nach der letzten Zeile.
 * - Zeilen länger als 75 Oktette werden gefaltet (CRLF plus Leerzeichen); gefaltet wird nur
 *   zwischen ganzen Zeichen (Graphem-Clustern), nie innerhalb einer UTF-8-Sequenz.
 * - Textwerte maskieren Backslash, Semikolon, Komma und Zeilenumbruch.
 * - Zeiten mit Uhrzeit stehen als Ortszeit mit TZID=Europe/Zurich, der Kalender enthält
 *   dazu den VTIMEZONE-Block mit den Regeln für Sommer- und Winterzeit. Ganztagesereignisse
 *   stehen als Datum (VALUE=DATE) mit dem Folgetag als Ende. DTSTAMP ist UTC.
 * - Kalenderkopf mit Name, Zeitzone und Aktualisierungsintervall (REFRESH-INTERVAL nach
 *   RFC 7986 und X-PUBLISHED-TTL für ältere Programme).
 */
final class IcsWriter {

	public const TIMEZONE = 'Europe/Zurich';

	/**
	 * Höchstlänge einer Zeile in Oktetten ohne Zeilenende (RFC 5545, 3.1).
	 */
	public const LINE_LIMIT = 75;

	public const CRLF = "\r\n";

	/**
	 * @param string $prodid  Kennung des erzeugenden Programms (PRODID).
	 * @param string $refresh Empfohlenes Aktualisierungsintervall als Dauer, z. B. PT6H.
	 */
	public function __construct(
		private readonly string $prodid = '-//ttleague//Spielbetrieb//DE',
		private readonly string $refresh = 'PT6H',
	) {}

	/**
	 * Vollständiger Kalender.
	 *
	 * @param string             $name        Kalendername, z. B. «Seefeld 1 – Spiele 2026/27».
	 * @param list<IcsEvent>     $events      Ereignisse.
	 * @param \DateTimeImmutable $stamp       Erstellungszeitpunkt der Daten (DTSTAMP).
	 * @param string             $description Beschreibung des Kalenders.
	 */
	public function calendar( string $name, array $events, \DateTimeImmutable $stamp, string $description = '' ): string {
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:' . self::text( $this->prodid ),
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'NAME:' . self::text( $name ),
			'X-WR-CALNAME:' . self::text( $name ),
		);
		if ( '' !== $description ) {
			$lines[] = 'DESCRIPTION:' . self::text( $description );
			$lines[] = 'X-WR-CALDESC:' . self::text( $description );
		}
		$lines[] = 'X-WR-TIMEZONE:' . self::TIMEZONE;
		$lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:' . $this->refresh;
		$lines[] = 'X-PUBLISHED-TTL:' . $this->refresh;

		$lines = array_merge( $lines, self::timezone() );
		foreach ( $events as $event ) {
			$lines = array_merge( $lines, self::event( $event, $stamp ) );
		}
		$lines[] = 'END:VCALENDAR';

		return implode( self::CRLF, array_map( array( self::class, 'fold' ), $lines ) ) . self::CRLF;
	}

	/**
	 * Zeilen eines Ereignisses (ungefaltet).
	 *
	 * @return list<string>
	 */
	public static function event( IcsEvent $event, \DateTimeImmutable $stamp ): array {
		$lines = array(
			'BEGIN:VEVENT',
			'UID:' . self::text( $event->uid ),
			'DTSTAMP:' . self::utc( $stamp ),
		);
		if ( $event->all_day ) {
			$day     = $event->start->format( 'Ymd' );
			$next    = $event->start->setTime( 12, 0 )->modify( '+1 day' )->format( 'Ymd' );
			$lines[] = 'DTSTART;VALUE=DATE:' . $day;
			$lines[] = 'DTEND;VALUE=DATE:' . $next;
		} else {
			$zone    = new \DateTimeZone( self::TIMEZONE );
			$start   = $event->start->setTimezone( $zone );
			$end     = $start->setTimestamp( $start->getTimestamp() + 60 * max( 0, $event->minutes ) );
			$lines[] = 'DTSTART;TZID=' . self::TIMEZONE . ':' . $start->format( 'Ymd\THis' );
			$lines[] = 'DTEND;TZID=' . self::TIMEZONE . ':' . $end->format( 'Ymd\THis' );
		}
		$lines[] = 'SUMMARY:' . self::text( $event->summary );
		if ( '' !== $event->location ) {
			$lines[] = 'LOCATION:' . self::text( $event->location );
		}
		if ( null !== $event->geo ) {
			$lines[] = sprintf( 'GEO:%s;%s', self::decimal( $event->geo[0] ), self::decimal( $event->geo[1] ) );
		}
		if ( '' !== $event->description ) {
			$lines[] = 'DESCRIPTION:' . self::text( $event->description );
		}
		if ( '' !== $event->url ) {
			$lines[] = 'URL:' . self::uri( $event->url );
		}
		if ( in_array( $event->status, array( IcsEvent::STATUS_CONFIRMED, IcsEvent::STATUS_TENTATIVE, IcsEvent::STATUS_CANCELLED ), true ) ) {
			$lines[] = 'STATUS:' . $event->status;
		}
		if ( array() !== $event->categories ) {
			$lines[] = 'CATEGORIES:' . implode( ',', array_map( array( self::class, 'text' ), $event->categories ) );
		}
		$lines[] = 'TRANSP:' . ( $event->all_day || IcsEvent::STATUS_CANCELLED === $event->status ? 'TRANSPARENT' : 'OPAQUE' );
		$lines[] = 'END:VEVENT';
		return $lines;
	}

	/**
	 * VTIMEZONE für Europe/Zurich: Sommerzeit vom letzten Sonntag im März 02:00 bis zum
	 * letzten Sonntag im Oktober 03:00 (Ortszeit), sonst MEZ. Die Startdaten 1970 sind
	 * die verbreitete, mit Outlook verträgliche Form; massgeblich sind die Regeln.
	 *
	 * @return list<string>
	 */
	public static function timezone(): array {
		return array(
			'BEGIN:VTIMEZONE',
			'TZID:' . self::TIMEZONE,
			'X-LIC-LOCATION:' . self::TIMEZONE,
			'BEGIN:DAYLIGHT',
			'TZNAME:CEST',
			'TZOFFSETFROM:+0100',
			'TZOFFSETTO:+0200',
			'DTSTART:19700329T020000',
			'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
			'END:DAYLIGHT',
			'BEGIN:STANDARD',
			'TZNAME:CET',
			'TZOFFSETFROM:+0200',
			'TZOFFSETTO:+0100',
			'DTSTART:19701025T030000',
			'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
			'END:STANDARD',
			'END:VTIMEZONE',
		);
	}

	/**
	 * Textwert maskieren (RFC 5545, 3.3.11): Backslash, Semikolon, Komma, Zeilenumbruch.
	 * Ungültiges UTF-8 und Steuerzeichen ausser Tabulator werden entfernt.
	 */
	public static function text( string $value ): string {
		$value = mb_scrub( $value, 'UTF-8' );
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$value = (string) preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/', '', $value );
		return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\\;', '\\,', '\\n' ), $value );
	}

	/**
	 * Zeile falten: höchstens 75 Oktette pro Zeile, Fortsetzungszeilen beginnen mit einem
	 * Leerzeichen (das mitzählt). Getrennt wird nur zwischen Graphem-Clustern; ein
	 * ungewöhnlich langer Cluster wird zwischen Codepunkten getrennt.
	 */
	public static function fold( string $line ): string {
		if ( strlen( $line ) <= self::LINE_LIMIT ) {
			return $line;
		}
		$out    = '';
		$length = 0;
		foreach ( self::pieces( $line ) as $piece ) {
			$bytes = strlen( $piece );
			if ( $length + $bytes > self::LINE_LIMIT ) {
				$out   .= self::CRLF . ' ';
				$length = 1;
			}
			$out    .= $piece;
			$length += $bytes;
		}
		return $out;
	}

	/**
	 * Zeitpunkt in UTC, z. B. 20261109T153000Z.
	 */
	public static function utc( \DateTimeImmutable $time ): string {
		return $time->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
	}

	/**
	 * Unteilbare Stücke einer Zeile: Graphem-Cluster, die in eine Fortsetzungszeile passen.
	 *
	 * @return list<string>
	 */
	private static function pieces( string $line ): array {
		if ( false === preg_match_all( '/\X/u', $line, $matches ) || '' === $line || array() === $matches[0] ) {
			return str_split( $line );
		}
		$pieces = array();
		foreach ( $matches[0] as $cluster ) {
			if ( strlen( $cluster ) < self::LINE_LIMIT ) {
				$pieces[] = $cluster;
				continue;
			}
			foreach ( mb_str_split( $cluster, 1, 'UTF-8' ) as $char ) {
				$pieces[] = $char;
			}
		}
		return $pieces;
	}

	/**
	 * URI ohne Steuerzeichen und Leerzeichen; Kommas und Semikolons bleiben (RFC 5545, 3.3.13).
	 */
	private static function uri( string $url ): string {
		return (string) preg_replace( '/[\x00-\x20\x7F]/', '', $url );
	}

	/**
	 * Dezimalzahl mit Punkt und höchstens sechs Nachkommastellen.
	 */
	private static function decimal( float $value ): string {
		$text = rtrim( rtrim( number_format( $value, 6, '.', '' ), '0' ), '.' );
		return '-0' === $text ? '0' : $text;
	}
}
