<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Schedule\RoundRobinScheduler;

final class RoundRobinSchedulerTest extends TestCase {

	/**
	 * @param list<list<array{home:mixed,away:mixed}>> $rounds Runden.
	 * @return array<string,int> Paarung => Anzahl.
	 */
	private function pair_counts( array $rounds ): array {
		$counts = array();
		foreach ( $rounds as $round ) {
			foreach ( $round as $p ) {
				$key            = min( $p['home'], $p['away'] ) . '-' . max( $p['home'], $p['away'] );
				$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
			}
		}
		return $counts;
	}

	public function test_seven_teams_give_seven_rounds_with_byes(): void {
		$rounds = ( new RoundRobinScheduler() )->single_round_robin( range( 1, 7 ) );
		self::assertCount( 7, $rounds );
		foreach ( $rounds as $round ) {
			self::assertCount( 3, $round );
			$teams = array_merge( array_column( $round, 'home' ), array_column( $round, 'away' ) );
			self::assertCount( 6, array_unique( $teams ), 'Kein Team spielt zweimal pro Runde.' );
		}
		$counts = $this->pair_counts( $rounds );
		self::assertCount( 21, $counts );
		self::assertSame( array( 1 ), array_values( array_unique( $counts ) ) );
	}

	public function test_six_teams_balanced_home_and_away(): void {
		$rounds = ( new RoundRobinScheduler() )->single_round_robin( range( 1, 6 ) );
		self::assertCount( 5, $rounds );
		$home = array_fill( 1, 6, 0 );
		foreach ( $rounds as $round ) {
			foreach ( $round as $p ) {
				++$home[ $p['home'] ];
			}
		}
		foreach ( $home as $team => $count ) {
			self::assertContains( $count, array( 2, 3 ), "Team $team hat $count Heimspiele." );
		}
	}

	public function test_double_round_robin_mirrors_home_right(): void {
		$rounds = ( new RoundRobinScheduler() )->double_round_robin( range( 1, 7 ) );
		self::assertCount( 14, $rounds );
		self::assertSame( 1, $rounds[0][0]['leg'] );
		self::assertSame( 2, $rounds[7][0]['leg'] );
		self::assertSame( $rounds[0][0]['home'], $rounds[7][0]['away'] );
		self::assertSame( $rounds[0][0]['away'], $rounds[7][0]['home'] );
		$counts = $this->pair_counts( $rounds );
		self::assertSame( array( 2 ), array_values( array_unique( $counts ) ) );
	}

	public function test_dates_follow_home_weekday(): void {
		$scheduler = new RoundRobinScheduler();
		$rounds    = $scheduler->single_round_robin( array( 'Seefeld 1', 'Altstetten 1' ) );
		$weeks     = array( new \DateTimeImmutable( '2026-09-07' ) ); // Montag
		$dated     = $scheduler->assign_dates( $rounds, $weeks, static fn( $team ) => 'Seefeld 1' === $team ? 2 : 4 );
		$pair      = $dated[0][0];
		$expected  = 'Seefeld 1' === $pair['home'] ? '2026-09-08' : '2026-09-10';
		self::assertSame( $expected, $pair['date']->format( 'Y-m-d' ) );
	}

	public function test_too_few_weeks_is_rejected(): void {
		$scheduler = new RoundRobinScheduler();
		$this->expectException( \InvalidArgumentException::class );
		$scheduler->assign_dates( $scheduler->single_round_robin( range( 1, 4 ) ), array( new \DateTimeImmutable() ), static fn() => 1 );
	}
}
