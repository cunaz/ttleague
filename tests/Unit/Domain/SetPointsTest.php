<?php
/**
 * Tests der Prüfung von Satzresultaten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Match\SetPoints;

final class SetPointsTest extends TestCase {

	public function test_valid_sets(): void {
		self::assertSame( array(), SetPoints::validate( array( array( 11, 8 ), array( 9, 11 ), array( 11, 5 ), array( 14, 12 ) ), 3, 1 ) );
		self::assertSame( array(), SetPoints::validate( array( array( 3, 11 ), array( 10, 12 ), array( 7, 11 ) ), 0, 3 ) );
	}

	public function test_single_set_rules(): void {
		self::assertNull( SetPoints::set_error( 11, 0 ) );
		self::assertNull( SetPoints::set_error( 12, 10 ) );
		self::assertNull( SetPoints::set_error( 20, 22 ) );
		self::assertNotNull( SetPoints::set_error( 10, 8 ), 'nicht beendet' );
		self::assertNotNull( SetPoints::set_error( 11, 10 ), 'ab 10:10 zwei Punkte Vorsprung' );
		self::assertNotNull( SetPoints::set_error( 13, 10 ), 'zu viele Punkte nach 10:10' );
		self::assertNotNull( SetPoints::set_error( 12, 8 ), 'Satz endet bei 11' );
		self::assertNotNull( SetPoints::set_error( -1, 11 ) );
	}

	public function test_sets_must_match_result(): void {
		self::assertCount( 1, SetPoints::validate( array( array( 11, 8 ), array( 11, 9 ), array( 11, 5 ) ), 3, 1 ), 'Anzahl Sätze' );
		self::assertCount( 1, SetPoints::validate( array( array( 11, 8 ), array( 9, 11 ), array( 11, 5 ), array( 5, 11 ) ), 3, 1 ), 'Satzverhältnis' );
	}

	public function test_no_set_after_decision(): void {
		$errors = SetPoints::validate( array( array( 11, 8 ), array( 11, 9 ), array( 11, 5 ), array( 5, 11 ) ), 3, 1 );
		self::assertNotSame( array(), $errors );
		self::assertStringContainsString( 'Satz 4', implode( ' ', $errors ) );
	}

	public function test_totals(): void {
		self::assertSame( array( 41, 36 ), SetPoints::totals( array( array( 11, 8 ), array( 9, 11 ), array( 11, 5 ), array( 10, 12 ) ) ) );
	}
}
