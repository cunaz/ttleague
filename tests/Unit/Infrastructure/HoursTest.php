<?php
/**
 * Stunden als verstrichene Zeit über die Zeitumstellung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Infrastructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Infrastructure\Time\Hours;

final class HoursTest extends TestCase {

	/**
	 * @return array<string,array{0:string,1:int,2:string}>
	 */
	public static function cases(): array {
		return array(
			'gewöhnlicher Tag'        => array( '2026-11-10 20:00:00', 24, '2026-11-11 20:00:00 CET' ),
			'Beginn der Sommerzeit'   => array( '2027-03-27 20:00:00', 24, '2027-03-28 21:00:00 CEST' ),
			'Ende der Sommerzeit'     => array( '2026-10-24 20:00:00', 24, '2026-10-25 19:00:00 CET' ),
			'48 Stunden über Oktober' => array( '2026-10-24 20:00:00', 48, '2026-10-26 19:00:00 CET' ),
		);
	}

	#[DataProvider( 'cases' )]
	public function test_hours_are_elapsed_hours_in_local_time( string $start, int $hours, string $expected ): void {
		$zone  = new \DateTimeZone( 'Europe/Zurich' );
		$begin = new \DateTimeImmutable( $start, $zone );
		$end   = Hours::after( $begin, $hours );

		self::assertSame( $expected, $end->format( 'Y-m-d H:i:s T' ) );
		self::assertSame( $hours * 3600, $end->getTimestamp() - $begin->getTimestamp() );
		self::assertSame( 'Europe/Zurich', $end->getTimezone()->getName() );
	}
}
