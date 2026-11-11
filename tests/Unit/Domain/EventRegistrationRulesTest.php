<?php
/**
 * Tests der Regeln für die Anmeldung zu Anlässen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Events\RegistrationRules as Rules;

final class EventRegistrationRulesTest extends TestCase {

	private static function zurich(): \DateTimeZone {
		return new \DateTimeZone( 'Europe/Zurich' );
	}

	private static function utc( string $time ): \DateTimeImmutable {
		return new \DateTimeImmutable( $time, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Anmeldeschluss 15.11.2026, 18:00 Ortszeit Zürich (Winterzeit) ist 17:00 UTC.
	 *
	 * @return iterable<string,array{string,bool}>
	 */
	public static function deadline_cases(): iterable {
		yield 'eine Sekunde vorher' => array( '2026-11-15 16:59:59', false );
		yield 'genau zum Anmeldeschluss' => array( '2026-11-15 17:00:00', false );
		yield 'eine Sekunde danach' => array( '2026-11-15 17:00:01', true );
		yield 'am Tag danach' => array( '2026-11-16 08:00:00', true );
	}

	#[DataProvider( 'deadline_cases' )]
	public function test_deadline_is_local_time_and_still_open_at_the_exact_moment( string $now_utc, bool $passed ): void {
		self::assertSame( $passed, Rules::deadline_passed( '2026-11-15 18:00:00', self::utc( $now_utc ), self::zurich() ) );
	}

	public function test_deadline_follows_summer_time(): void {
		// 15.6.2026, 18:00 in Zürich ist 16:00 UTC.
		self::assertFalse( Rules::deadline_passed( '2026-06-15 18:00:00', self::utc( '2026-06-15 16:00:00' ), self::zurich() ) );
		self::assertTrue( Rules::deadline_passed( '2026-06-15 18:00:00', self::utc( '2026-06-15 16:00:01' ), self::zurich() ) );
	}

	public function test_without_a_deadline_the_start_is_the_deadline(): void {
		self::assertSame( '2026-11-20 19:30:00', Rules::effective_deadline( null, '2026-11-20 19:30:00' ) );
		self::assertSame( '2026-11-20 19:30:00', Rules::effective_deadline( '', '2026-11-20 19:30:00' ) );
		self::assertSame( '2026-11-15 18:00:00', Rules::effective_deadline( '2026-11-15 18:00:00', '2026-11-20 19:30:00' ) );
		self::assertNull( Rules::blocked( Rules::OPEN, null, '2026-11-20 19:30:00', self::utc( '2026-11-20 18:30:00' ), self::zurich() ) );
		self::assertSame( Rules::BLOCK_DEADLINE, Rules::blocked( Rules::OPEN, null, '2026-11-20 19:30:00', self::utc( '2026-11-20 18:30:01' ), self::zurich() ) );
	}

	/**
	 * @return iterable<string,array{string,string,bool,string|null}>
	 */
	public static function blocked_cases(): iterable {
		$before = '2026-11-15 17:00:00';
		$after  = '2026-11-15 17:00:01';
		yield 'offen, vor der Frist' => array( Rules::OPEN, $before, false, null );
		yield 'offen, nach der Frist' => array( Rules::OPEN, $after, false, Rules::BLOCK_DEADLINE );
		yield 'offen, nach der Frist, TK' => array( Rules::OPEN, $after, true, null );
		yield 'geschlossen' => array( Rules::CLOSED, $before, false, Rules::BLOCK_CLOSED );
		yield 'geschlossen, TK' => array( Rules::CLOSED, $before, true, null );
		yield 'abgesagt' => array( Rules::CANCELLED, $before, false, Rules::BLOCK_CANCELLED );
		yield 'abgesagt, TK' => array( Rules::CANCELLED, $before, true, Rules::BLOCK_CANCELLED );
		yield 'unbekannter Status' => array( 'draft', $before, false, Rules::BLOCK_CLOSED );
	}

	#[DataProvider( 'blocked_cases' )]
	public function test_blocked( string $status, string $now_utc, bool $by_tk, ?string $expected ): void {
		self::assertSame( $expected, Rules::blocked( $status, '2026-11-15 18:00:00', '2026-11-20 19:30:00', self::utc( $now_utc ), self::zurich(), $by_tk ) );
	}

	public function test_placement_switches_to_the_waitlist_exactly_at_the_limit(): void {
		self::assertSame( Rules::REGISTERED, Rules::placement( 3, 2 ), 'letzter freier Platz' );
		self::assertSame( Rules::WAITLIST, Rules::placement( 3, 3 ), 'Höchstzahl erreicht' );
		self::assertSame( Rules::WAITLIST, Rules::placement( 3, 4 ), 'nach einer Senkung der Höchstzahl' );
		self::assertSame( Rules::REGISTERED, Rules::placement( null, 500 ), 'ohne Höchstzahl' );
	}

	public function test_free_places(): void {
		self::assertSame( 1, Rules::free_places( 3, 2 ) );
		self::assertSame( 0, Rules::free_places( 3, 3 ) );
		self::assertSame( 0, Rules::free_places( 3, 5 ), 'nie negativ' );
		self::assertNull( Rules::free_places( null, 5 ) );
	}

	public function test_promotions_fill_free_places_only(): void {
		self::assertSame( 1, Rules::promotions( 3, 2, 4 ), 'ein Platz frei' );
		self::assertSame( 0, Rules::promotions( 3, 3, 4 ), 'voll' );
		self::assertSame( 0, Rules::promotions( 3, 5, 4 ), 'über der gesenkten Höchstzahl' );
		self::assertSame( 2, Rules::promotions( 6, 2, 2 ), 'mehr Plätze als Wartende' );
		self::assertSame( 0, Rules::promotions( 6, 2, 0 ), 'niemand wartet' );
		self::assertSame( 4, Rules::promotions( null, 9, 4 ), 'Höchstzahl aufgehoben' );
	}
}
