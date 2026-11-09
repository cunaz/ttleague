<?php
/**
 * Tests der Anzeigeformate.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use TTLeague\Frontend\Support\Format;

final class FormatTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once __DIR__ . '/Stubs/WordPress.php';
	}

	public function test_dates_with_weekday_in_swiss_format(): void {
		self::assertSame( 'Di, 8.9.2026', Format::date( '2026-09-08 20:00:00' ) );
		self::assertSame( 'So, 1.11.2026', Format::date( '2026-11-01' ) );
		self::assertSame( '8.9.2026', Format::date_plain( '2026-09-08 20:00:00' ) );
		self::assertSame( '8.9.', Format::date_short( '2026-09-08' ) );
		self::assertSame( 'Di, 8.9.2026, 20:00', Format::datetime( '2026-09-08 20:00:00' ) );
		self::assertSame( 'Di, 8.9.2026', Format::datetime( '2026-09-08' ) );
		self::assertSame( '', Format::date( null ) );
		self::assertSame( '', Format::date( '2026-02-30' ) );
	}

	public function test_times_and_ranges(): void {
		self::assertSame( '20:00', Format::time( '2026-09-08 20:00:00' ) );
		self::assertSame( '19:15', Format::time( '19:15:00' ) );
		self::assertSame( '', Format::time( '2026-09-08' ) );
		self::assertSame( '21.–24.9.2026', Format::date_range( '2026-09-21', '2026-09-24' ) );
		self::assertSame( '28.9.–1.10.2026', Format::date_range( '2026-09-28', '2026-10-01' ) );
		self::assertSame( '28.12.2026–2.1.2027', Format::date_range( '2026-12-28', '2027-01-02' ) );
		self::assertSame( '5.3.2027', Format::date_range( '2027-03-05', '2027-03-05' ) );
	}

	public function test_weekdays(): void {
		self::assertSame( 'Montag', Format::weekday( 1 ) );
		self::assertSame( 'Sonntag', Format::weekday( 7 ) );
		self::assertSame( '', Format::weekday( null ) );
		self::assertSame( 'Do', Format::weekday_short( 4 ) );
	}

	public function test_scores_ratios_and_points(): void {
		self::assertSame( '7:3', Format::score( 7, 3 ) );
		self::assertSame( '–', Format::score( null, 3 ) );
		self::assertSame( '42:38', Format::ratio( 42, 38 ) );
		self::assertSame( '6', Format::points( '6.0' ) );
		self::assertSame( '5,5', Format::points( 5.5 ) );
		self::assertSame( '4,25', Format::points( '4.25' ) );
		self::assertSame( '–', Format::points( null ) );
	}

	public function test_classification_and_rating(): void {
		self::assertSame( 'D5', Format::classification( 5 ) );
		self::assertSame( 'C6', Format::classification( 6 ) );
		self::assertSame( 'A22', Format::classification( 22 ) );
		self::assertSame( '', Format::classification( null ) );
		self::assertSame( '1105', Format::rating( 1104.6 ) );
		self::assertSame( '-3', Format::rating( -3.4 ) );
		self::assertSame( '–', Format::rating( null ) );
		self::assertSame( '+9,0', Format::delta( 9.0 ) );
		self::assertSame( '−10,5', Format::delta( -10.46 ) );
		self::assertSame( '±0,0', Format::delta( 0.01 ) );
	}

	public function test_status_labels_and_markers(): void {
		self::assertSame( 'provisorisch', Format::fixture_status( 'provisional' ) );
		self::assertSame( 'Forfait', Format::fixture_status( 'forfeit' ) );
		self::assertSame( 'verschoben', Format::fixture_marker( 'postponed' ) );
		self::assertNull( Format::fixture_marker( 'final' ) );
		self::assertNull( Format::fixture_marker( 'confirmed' ) );
		self::assertSame( 'w.o.', Format::game_status( 'walkover' ) );
		self::assertSame( 'nicht gespielt', Format::game_status( 'not_played' ) );
		self::assertSame( '', Format::game_status( 'played' ) );
		self::assertSame( 'Ranglistenturnier', Format::kind( 'RL' ) );
		self::assertSame( 'Cup', Format::kind_short( 'CUP' ) );
	}

	public function test_address_and_map_link(): void {
		$address = Format::address( 'Musterstrasse 1', '8000', 'Musterstadt' );
		self::assertSame( 'Musterstrasse 1, 8000 Musterstadt', $address );
		self::assertSame( '8000 Musterstadt', Format::address( null, '8000', 'Musterstadt' ) );
		self::assertSame( 'https://www.openstreetmap.org/search?query=Musterstrasse%201%2C%208000%20Musterstadt', Format::map_url( $address ) );
	}
}
