<?php
/**
 * Tests der iCalendar-Erzeugung: Faltung, Maskierung, Zeitzone, Ganztag, Kalenderkopf.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Calendar;

use PHPUnit\Framework\TestCase;
use TTLeague\Calendar\IcsEvent;
use TTLeague\Calendar\IcsWriter;

final class IcsWriterTest extends TestCase {

	private static function zurich( string $time ): \DateTimeImmutable {
		return new \DateTimeImmutable( $time, new \DateTimeZone( 'Europe/Zurich' ) );
	}

	private static function stamp(): \DateTimeImmutable {
		return new \DateTimeImmutable( '2026-11-09 15:30:00', new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Entfaltet eine Datei in logische Zeilen.
	 *
	 * @return list<string>
	 */
	private static function unfold( string $ics ): array {
		return explode( "\r\n", rtrim( (string) preg_replace( "/\r\n[ \t]/", '', $ics ), "\r\n" ) );
	}

	public function test_short_lines_stay_unchanged(): void {
		self::assertSame( 'SUMMARY:Seefeld 1 – Altstetten 1', IcsWriter::fold( 'SUMMARY:Seefeld 1 – Altstetten 1' ) );
		$exact = 'DESCRIPTION:' . str_repeat( 'a', 63 );
		self::assertSame( 75, strlen( $exact ) );
		self::assertSame( $exact, IcsWriter::fold( $exact ) );
	}

	public function test_long_ascii_lines_are_folded_at_75_octets(): void {
		$line   = 'DESCRIPTION:' . str_repeat( 'x', 200 );
		$folded = IcsWriter::fold( $line );
		$parts  = explode( "\r\n", $folded );
		self::assertCount( 3, $parts );
		self::assertSame( 75, strlen( $parts[0] ) );
		self::assertSame( 75, strlen( $parts[1] ), 'Leerzeichen plus 74 Oktette' );
		self::assertSame( ' ', $parts[1][0], 'Fortsetzungszeilen beginnen mit einem Leerzeichen' );
		self::assertSame( ' ', $parts[2][0] );
		self::assertSame( $line, str_replace( "\r\n ", '', $folded ) );
	}

	public function test_folding_never_splits_utf8_characters(): void {
		// «ü» und «ö» (je 2 Oktette) und der Halbgeviertstrich (3 Oktette) an jeder möglichen Grenze.
		foreach ( range( 60, 66 ) as $padding ) {
			$line   = 'LOCATION:' . str_repeat( 'a', $padding ) . str_repeat( 'Zürich – Höngg ', 8 );
			$folded = IcsWriter::fold( $line );
			foreach ( explode( "\r\n", $folded ) as $part ) {
				self::assertLessThanOrEqual( 75, strlen( $part ) );
				self::assertTrue( mb_check_encoding( $part, 'UTF-8' ), 'jede Zeile ist gültiges UTF-8: ' . bin2hex( $part ) );
			}
			self::assertSame( $line, str_replace( "\r\n ", '', $folded ) );
		}
	}

	public function test_folding_keeps_grapheme_clusters_together(): void {
		// «é» als e plus kombinierender Akzent (3 Oktette): Das e endet bei Oktett 74.
		$accent = 'SUMMARY:' . str_repeat( 'b', 62 ) . "Cafe\u{0301} Turnier" . str_repeat( ' Turnier', 8 );
		// Emoji mit Hautton (8 Oktette): Das Emoji allein endet bei Oktett 74.
		$emoji = 'SUMMARY:' . str_repeat( 'b', 62 ) . "\u{1F3D3}\u{1F3FD} Pingpong" . str_repeat( ' Pingpong', 8 );
		foreach ( array( $accent, $emoji ) as $line ) {
			$folded = IcsWriter::fold( $line );
			$parts  = explode( "\r\n", $folded );
			foreach ( $parts as $part ) {
				self::assertLessThanOrEqual( 75, strlen( $part ) );
				self::assertTrue( mb_check_encoding( $part, 'UTF-8' ) );
				self::assertStringStartsNotWith( " \u{0301}", $part, 'Akzent nicht vom Buchstaben getrennt' );
				self::assertStringStartsNotWith( " \u{1F3FD}", $part, 'Hautton nicht vom Emoji getrennt' );
			}
			self::assertSame( $line, str_replace( "\r\n ", '', $folded ) );
		}
		self::assertStringEndsWith( 'Caf', explode( "\r\n", IcsWriter::fold( $accent ) )[0] );
		self::assertStringEndsWith( 'b', explode( "\r\n", IcsWriter::fold( $emoji ) )[0] );
	}

	public function test_text_values_are_escaped(): void {
		self::assertSame( 'Musterstrasse 1\, 8000 Musterstadt', IcsWriter::text( 'Musterstrasse 1, 8000 Musterstadt' ) );
		self::assertSame( 'A\; B', IcsWriter::text( 'A; B' ) );
		self::assertSame( 'C:\\\\Temp', IcsWriter::text( 'C:\\Temp' ) );
		self::assertSame( 'Zeile 1\nZeile 2\nZeile 3\nZeile 4', IcsWriter::text( "Zeile 1\nZeile 2\r\nZeile 3\rZeile 4" ) );
		self::assertSame( 'Tab	bleibt', IcsWriter::text( "Tab\tbleibt" ) );
		self::assertSame( 'ohne Steuerzeichen', IcsWriter::text( "ohne\x07 Steuer\x1Bzeichen" ) );
		self::assertSame( '\\\\\\, \\\\\\;', IcsWriter::text( '\\, \\;' ), 'Backslash wird vor Komma und Semikolon maskiert' );
		self::assertTrue( mb_check_encoding( IcsWriter::text( "kaputt \xC3" ), 'UTF-8' ) );
	}

	public function test_calendar_header_and_crlf(): void {
		$ics = ( new IcsWriter( '-//ttleague//Test//DE' ) )->calendar( 'Seefeld 1 – Spiele 2026/27', array(), self::stamp(), 'Spielplan, Resultate' );
		self::assertStringStartsWith( "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//ttleague//Test//DE\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n", $ics );
		self::assertStringEndsWith( "END:VCALENDAR\r\n", $ics );
		self::assertSame( 0, preg_match( "/(?<!\r)\n/", $ics ), 'nur CRLF als Zeilenende' );
		$lines = self::unfold( $ics );
		self::assertContains( 'X-WR-CALNAME:Seefeld 1 – Spiele 2026/27', $lines );
		self::assertContains( 'NAME:Seefeld 1 – Spiele 2026/27', $lines );
		self::assertContains( 'X-WR-CALDESC:Spielplan\, Resultate', $lines );
		self::assertContains( 'X-WR-TIMEZONE:Europe/Zurich', $lines );
		self::assertContains( 'REFRESH-INTERVAL;VALUE=DURATION:PT6H', $lines );
		self::assertContains( 'X-PUBLISHED-TTL:PT6H', $lines );
		self::assertContains( 'TZID:Europe/Zurich', $lines );
		self::assertStringNotContainsString( 'BEGIN:VEVENT', $ics );
	}

	public function test_vtimezone_rules_match_the_time_zone_database(): void {
		$lines = IcsWriter::timezone();
		self::assertSame( 'BEGIN:VTIMEZONE', $lines[0] );
		self::assertSame( 'END:VTIMEZONE', $lines[ count( $lines ) - 1 ] );
		self::assertContains( 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', $lines );
		self::assertContains( 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', $lines );

		// Startdaten der Regeln sind selbst letzte Sonntage im März bzw. Oktober.
		self::assertContains( 'DTSTART:19700329T020000', $lines );
		self::assertContains( 'DTSTART:19701025T030000', $lines );
		self::assertSame( '7', ( new \DateTimeImmutable( '1970-03-29' ) )->format( 'N' ) );
		self::assertSame( '7', ( new \DateTimeImmutable( '1970-10-25' ) )->format( 'N' ) );

		// Die Regeln entsprechen den Umstellungen der Zeitzonendatenbank 2020–2040.
		$zone = new \DateTimeZone( 'Europe/Zurich' );
		foreach ( range( 2020, 2040 ) as $year ) {
			$transitions = $zone->getTransitions( (int) gmmktime( 0, 0, 0, 1, 1, $year ), (int) gmmktime( 0, 0, 0, 12, 31, $year ) );
			$changes     = array_slice( $transitions, 1 );
			self::assertCount( 2, $changes, (string) $year );
			$summer = new \DateTimeImmutable( '@' . $changes[0]['ts'] );
			$winter = new \DateTimeImmutable( '@' . $changes[1]['ts'] );
			self::assertSame( ( new \DateTimeImmutable( "last sunday of march $year 01:00", new \DateTimeZone( 'UTC' ) ) )->getTimestamp(), $summer->getTimestamp(), "Sommerzeit $year" );
			self::assertSame( ( new \DateTimeImmutable( "last sunday of october $year 01:00", new \DateTimeZone( 'UTC' ) ) )->getTimestamp(), $winter->getTimestamp(), "Winterzeit $year" );
			self::assertSame( 7200, $changes[0]['offset'] );
			self::assertSame( 3600, $changes[1]['offset'] );
		}
	}

	public function test_timed_event_uses_local_time_with_tzid(): void {
		$event = new IcsEvent(
			'fixture-12@example.org',
			'Seefeld 1 – Altstetten 1 (Gruppe A) 6:4',
			self::zurich( '2026-09-08 20:00:00' ),
			150,
			false,
			'Turnhalle Seefeld, Musterstrasse 1, 8000 Musterstadt',
			"Meisterschaft 2026/27, Gruppe A\nMatchreport: https://example.org/begegnung/12/",
			'https://example.org/begegnung/12/',
			IcsEvent::STATUS_CONFIRMED,
			array( 'Meisterschaft' ),
			array( 47.366667, 8.55 )
		);
		$lines = IcsWriter::event( $event, self::stamp() );
		self::assertSame( 'BEGIN:VEVENT', $lines[0] );
		self::assertContains( 'UID:fixture-12@example.org', $lines );
		self::assertContains( 'DTSTAMP:20261109T153000Z', $lines );
		self::assertContains( 'DTSTART;TZID=Europe/Zurich:20260908T200000', $lines );
		self::assertContains( 'DTEND;TZID=Europe/Zurich:20260908T223000', $lines );
		self::assertContains( 'SUMMARY:Seefeld 1 – Altstetten 1 (Gruppe A) 6:4', $lines );
		self::assertContains( 'LOCATION:Turnhalle Seefeld\, Musterstrasse 1\, 8000 Musterstadt', $lines );
		self::assertContains( 'DESCRIPTION:Meisterschaft 2026/27\, Gruppe A\nMatchreport: https://example.org/begegnung/12/', $lines );
		self::assertContains( 'URL:https://example.org/begegnung/12/', $lines );
		self::assertContains( 'STATUS:CONFIRMED', $lines );
		self::assertContains( 'CATEGORIES:Meisterschaft', $lines );
		self::assertContains( 'GEO:47.366667;8.55', $lines );
		self::assertContains( 'TRANSP:OPAQUE', $lines );
		self::assertSame( 'END:VEVENT', $lines[ count( $lines ) - 1 ] );
	}

	public function test_times_are_converted_into_the_calendar_time_zone(): void {
		$utc   = new \DateTimeImmutable( '2026-09-08 18:00:00', new \DateTimeZone( 'UTC' ) );
		$lines = IcsWriter::event( new IcsEvent( 'x@example.org', 'Test', $utc, 120 ), self::stamp() );
		self::assertContains( 'DTSTART;TZID=Europe/Zurich:20260908T200000', $lines );
		self::assertContains( 'DTEND;TZID=Europe/Zurich:20260908T220000', $lines );

		$stamp = new \DateTimeImmutable( '2026-07-01 12:00:00', new \DateTimeZone( 'Europe/Zurich' ) );
		self::assertSame( '20260701T100000Z', IcsWriter::utc( $stamp ) );
	}

	public function test_duration_is_absolute_across_daylight_saving_changes(): void {
		// In der Nacht auf den 25.10.2026 wird die Uhr um 03:00 auf 02:00 zurückgestellt.
		$lines = IcsWriter::event( new IcsEvent( 'x@example.org', 'Nacht', self::zurich( '2026-10-25 01:30:00' ), 180 ), self::stamp() );
		self::assertContains( 'DTSTART;TZID=Europe/Zurich:20261025T013000', $lines );
		self::assertContains( 'DTEND;TZID=Europe/Zurich:20261025T033000', $lines, 'drei Stunden echte Dauer' );
	}

	public function test_all_day_event_without_known_time(): void {
		$lines = IcsWriter::event( new IcsEvent( 'fixture-7@example.org', 'Termin offen', self::zurich( '2026-12-31 00:00:00' ), 150, true ), self::stamp() );
		self::assertContains( 'DTSTART;VALUE=DATE:20261231', $lines );
		self::assertContains( 'DTEND;VALUE=DATE:20270101', $lines );
		self::assertContains( 'TRANSP:TRANSPARENT', $lines );
		self::assertSame( array(), preg_grep( '/TZID/', $lines ) );

		$dst = IcsWriter::event( new IcsEvent( 'x@example.org', 'Umstellung', self::zurich( '2027-03-28 00:00:00' ), 0, true ), self::stamp() );
		self::assertContains( 'DTSTART;VALUE=DATE:20270328', $dst );
		self::assertContains( 'DTEND;VALUE=DATE:20270329', $dst );
	}

	public function test_cancelled_event_and_optional_properties(): void {
		$lines = IcsWriter::event( new IcsEvent( 'fixture-9@example.org', 'Annulliert: A – B', self::zurich( '2026-11-02 20:00:00' ), 120, false, '', '', '', IcsEvent::STATUS_CANCELLED ), self::stamp() );
		self::assertContains( 'STATUS:CANCELLED', $lines );
		self::assertContains( 'TRANSP:TRANSPARENT', $lines );
		self::assertSame( array(), preg_grep( '/^(LOCATION|DESCRIPTION|URL|GEO|CATEGORIES):/', $lines ) );

		$unknown = IcsWriter::event( new IcsEvent( 'y@example.org', 'X', self::zurich( '2026-11-02 20:00:00' ), 120, false, '', '', '', 'UNSINN' ), self::stamp() );
		self::assertSame( array(), preg_grep( '/^STATUS:/', $unknown ) );
	}

	public function test_complete_calendar_is_folded_and_well_formed(): void {
		$long   = str_repeat( 'Begegnung mit sehr langem Titel, Zürich; ', 5 );
		$events = array(
			new IcsEvent( 'fixture-1@example.org', $long, self::zurich( '2026-09-08 20:00:00' ), 150, false, 'Halle, Weg 1, 8000 Musterstadt', $long ),
			new IcsEvent( 'fixture-2@example.org', 'Offen', self::zurich( '2026-09-09 00:00:00' ), 150, true ),
		);
		$ics    = ( new IcsWriter() )->calendar( 'Test', $events, self::stamp() );
		foreach ( explode( "\r\n", rtrim( $ics, "\r\n" ) ) as $line ) {
			self::assertLessThanOrEqual( 75, strlen( $line ) );
			self::assertTrue( mb_check_encoding( $line, 'UTF-8' ) );
		}
		$lines = self::unfold( $ics );
		self::assertSame( 2, count( preg_grep( '/^BEGIN:VEVENT$/', $lines ) ) );
		self::assertSame( 2, count( preg_grep( '/^END:VEVENT$/', $lines ) ) );
		self::assertContains( 'SUMMARY:' . IcsWriter::text( $long ), $lines );
		self::assertSame( 1, substr_count( $ics, 'BEGIN:VTIMEZONE' ) );
		self::assertLessThan( strpos( $ics, 'BEGIN:VEVENT' ), strpos( $ics, 'END:VTIMEZONE' ) );
	}
}
