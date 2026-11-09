<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Standings\Ratio;

final class RatioTest extends TestCase {

	public function test_comparisons(): void {
		self::assertSame( 1, Ratio::compare( 5, 0, 10, 1 ), 'Ohne Niederlage schlägt jedes endliche Verhältnis.' );
		self::assertSame( -1, Ratio::compare( 10, 1, 5, 0 ) );
		self::assertSame( 1, Ratio::compare( 6, 0, 5, 0 ), 'Zwei unendliche: mehr Siege gewinnt.' );
		self::assertSame( 0, Ratio::compare( 0, 0, 0, 0 ) );
		self::assertSame( 0, Ratio::compare( 3, 1, 6, 2 ) );
		self::assertSame( 1, Ratio::compare( 4, 2, 3, 2 ) );
		self::assertSame( 0, Ratio::compare( 0, 0, 0, 5 ), 'Keine Spiele und nur Niederlagen zählen beide als 0.' );
		self::assertSame( 1, Ratio::compare( 2, 2, 0, 0 ) );
		self::assertSame( -1, Ratio::compare( 0, 0, 2, 2 ) );
	}
}
