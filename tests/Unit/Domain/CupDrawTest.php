<?php
/**
 * Auslosung des Mannschaftscups: Tableaugrösse, Setzung, Freilose, gleicher Club, Startwert.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Cup\Draw;
use TTLeague\Domain\Cup\DrawRandom;

/**
 * Die Mannschaften haben die IDs 1 bis n. Ohne andere Angabe hat Mannschaft 1 die höchste
 * Punktesumme, Mannschaft n die tiefste, und jede gehört zu einem eigenen Club.
 */
final class CupDrawTest extends TestCase {

	/**
	 * @param array<int,int> $clubs Meldung => Club; fehlende gehören zu einem eigenen Club.
	 * @return list<array{id:int,club_id:int,points_total:int,registered_at:string,seed:int|null}>
	 */
	private static function entries( int $count, array $clubs = array() ): array {
		$entries = array();
		for ( $id = 1; $id <= $count; $id++ ) {
			$entries[] = array(
				'id'            => $id,
				'club_id'       => $clubs[ $id ] ?? 100 + $id,
				'points_total'  => 40 - $id,
				'registered_at' => '2026-09-01 10:00:00',
				'seed'          => null,
			);
		}
		return $entries;
	}

	/**
	 * @return array<string,array{0:int}>
	 */
	public static function counts(): array {
		$counts = array();
		for ( $count = 5; $count <= 16; $count++ ) {
			$counts[ $count . ' Mannschaften' ] = array( $count );
		}
		return $counts;
	}

	public function test_the_bracket_is_the_next_power_of_two(): void {
		self::assertSame(
			array( 2, 4, 4, 8, 8, 8, 8, 16, 16, 32 ),
			array_map( array( Draw::class, 'size' ), array( 2, 3, 4, 5, 6, 7, 8, 9, 16, 17 ) )
		);
		self::assertSame( array( 1, 2, 3, 4 ), array_map( array( Draw::class, 'rounds' ), array( 2, 4, 8, 16 ) ) );
	}

	public function test_seed_positions_keep_the_top_seeds_apart(): void {
		self::assertSame( array( 1, 4, 2, 3 ), Draw::seed_positions( 4 ) );
		self::assertSame( array( 1, 8, 4, 5, 2, 7, 3, 6 ), Draw::seed_positions( 8 ) );
		self::assertSame( array( 1, 16, 8, 9, 4, 13, 5, 12, 2, 15, 7, 10, 3, 14, 6, 11 ), Draw::seed_positions( 16 ) );
	}

	#[DataProvider( 'counts' )]
	public function test_every_team_is_drawn_once_into_a_bracket_of_the_right_size( int $count ): void {
		foreach ( array( 1, 7, 2026 ) as $seed ) {
			$draw = Draw::draw( self::entries( $count ), $seed );

			$size = $count <= 8 ? 8 : 16;
			self::assertSame( $size, $draw['size'] );
			self::assertSame( 8 === $size ? 3 : 4, $draw['rounds'] );
			self::assertCount( $size, $draw['positions'] );
			$teams = array_values( array_filter( $draw['positions'], static fn( ?int $id ): bool => null !== $id ) );
			sort( $teams );
			self::assertSame( range( 1, $count ), $teams, 'jede Mannschaft genau einmal' );
			self::assertSame( array(), Draw::problems( $draw['positions'], range( 1, $count ) ) );
		}
	}

	#[DataProvider( 'counts' )]
	public function test_byes_go_to_the_highest_seeds( int $count ): void {
		foreach ( array( 3, 11, 500 ) as $seed ) {
			$draw = Draw::draw( self::entries( $count ), $seed );
			$byes = $draw['size'] - $count;

			$with_bye = array();
			foreach ( Draw::pairings( $draw['positions'] ) as $pairing ) {
				self::assertNotNull( $pairing['home'], 'keine Paarung aus zwei Freilosen, die Mannschaft steht vorn' );
				if ( null === $pairing['away'] ) {
					$with_bye[] = $pairing['home'];
				}
			}
			sort( $with_bye );
			self::assertSame( $byes > 0 ? range( 1, $byes ) : array(), $with_bye, 'Freilose für die Nummern 1 bis ' . $byes );
			self::assertGreaterThanOrEqual( $byes, $draw['seeded'] );
		}
	}

	#[DataProvider( 'counts' )]
	public function test_seeds_one_and_two_can_only_meet_in_the_final( int $count ): void {
		foreach ( range( 1, 25 ) as $seed ) {
			$draw = Draw::draw( self::entries( $count ), $seed );
			$half = intdiv( $draw['size'], 2 );
			$at   = array_flip( array_filter( $draw['positions'], static fn( ?int $id ): bool => null !== $id ) );

			self::assertSame( array( 1, 2 ), array_slice( $draw['seeding'], 0, 2 ) );
			self::assertNotSame( intdiv( $at[1], $half ), intdiv( $at[2], $half ), 'Nummer 1 und 2 in verschiedenen Hälften' );
			if ( $draw['seeded'] >= 4 ) {
				$quarters = array_map( static fn( int $id ): int => intdiv( $at[ $id ], intdiv( $half, 2 ) ), array( 1, 2, 3, 4 ) );
				self::assertCount( 4, array_unique( $quarters ), 'Nummern 1 bis 4 in verschiedenen Vierteln' );
			}
		}
	}

	public function test_a_quarter_of_the_bracket_is_seeded_and_at_least_the_teams_with_a_bye(): void {
		self::assertSame(
			array(
				5  => 3,
				6  => 2,
				7  => 2,
				8  => 2,
				9  => 7,
				10 => 6,
				11 => 5,
				12 => 4,
				13 => 4,
				16 => 4,
			),
			array_combine( array( 5, 6, 7, 8, 9, 10, 11, 12, 13, 16 ), array_map( array( Draw::class, 'seeded_count' ), array( 5, 6, 7, 8, 9, 10, 11, 12, 13, 16 ) ) )
		);
	}

	public function test_seeding_is_by_points_then_time_of_registration(): void {
		$entries = array(
			array(
				'id'            => 1,
				'club_id'       => 1,
				'points_total'  => 20,
				'registered_at' => '2026-09-03 08:00:00',
			),
			array(
				'id'            => 2,
				'club_id'       => 2,
				'points_total'  => 24,
				'registered_at' => '2026-09-05 08:00:00',
			),
			array(
				'id'            => 3,
				'club_id'       => 3,
				'points_total'  => 20,
				'registered_at' => '2026-09-02 08:00:00',
			),
			array(
				'id'            => 4,
				'club_id'       => 4,
				'points_total'  => 20,
				'registered_at' => '2026-09-02 08:00:00',
			),
			array(
				'id'            => 5,
				'club_id'       => 5,
				'points_total'  => 12,
				'registered_at' => null,
			),
		);

		self::assertSame( array( 2, 3, 4, 1, 5 ), array_column( Draw::seeding( $entries ), 'id' ), 'höchste Summe zuerst, bei Gleichstand die frühere Meldung, dann die ID' );

		// Die TK setzt die schwächste Mannschaft als Nummer 1.
		$entries[4]['seed'] = 1;
		self::assertSame( array( 5, 2, 3, 4, 1 ), array_column( Draw::seeding( $entries ), 'id' ) );
		self::assertSame( 5, Draw::draw( $entries, 9 )['seeding'][0] );
	}

	#[DataProvider( 'counts' )]
	public function test_the_draw_is_reproducible_with_its_seed( int $count ): void {
		$entries = self::entries( $count );

		self::assertSame( Draw::draw( $entries, 4711 ), Draw::draw( $entries, 4711 ) );
		self::assertSame( Draw::draw( $entries, 4711 ), Draw::draw( array_reverse( $entries ), 4711 ), 'unabhängig von der Reihenfolge der Eingabe' );

		$distinct = array();
		foreach ( range( 1, 30 ) as $seed ) {
			$distinct[ implode( ',', array_map( 'strval', Draw::draw( $entries, $seed )['positions'] ) ) ] = true;
		}
		self::assertGreaterThan( 1, count( $distinct ), 'andere Startwerte ergeben andere Tableaus' );
	}

	public function test_a_known_seed_gives_a_known_bracket(): void {
		// Hält den Generator fest: Ändert er sich, lassen sich alte Auslosungen nicht mehr nachvollziehen.
		$random = new DrawRandom( 2026 );
		self::assertSame( array( 703, 824, 267 ), array( $random->below( 1000 ), $random->below( 1000 ), $random->below( 1000 ) ) );

		$draw = Draw::draw( self::entries( 11 ), 2026 );
		self::assertSame( array( 1, null, 7, 8, 4, null, 5, null, 2, null, 11, 6, 3, null, 10, 9 ), $draw['positions'] );
		self::assertSame( 2026, $draw['seed'] );
		self::assertSame( 5, $draw['seeded'] );
	}

	public function test_the_generator_stays_in_range_and_shuffles_without_losing_items(): void {
		$random = new DrawRandom( 0 );
		$seen   = array();
		for ( $i = 0; $i < 300; $i++ ) {
			$value = $random->below( 3 );
			self::assertContains( $value, array( 0, 1, 2 ) );
			$seen[ $value ] = true;
		}
		self::assertCount( 3, $seen );
		$shuffled = ( new DrawRandom( -5 ) )->shuffle( range( 1, 16 ) );
		self::assertNotSame( range( 1, 16 ), $shuffled );
		sort( $shuffled );
		self::assertSame( range( 1, 16 ), $shuffled );
	}

	/**
	 * @return array<string,array{0:int,1:array<int,int>,2:int}>
	 */
	public static function club_cases(): array {
		return array(
			'8 Mannschaften, vier aus einem Club'    => array(
				8,
				array(
					1 => 1,
					2 => 1,
					5 => 1,
					8 => 1,
				),
				0,
			),
			'8 Mannschaften, je zwei aus vier Clubs' => array(
				8,
				array(
					1 => 1,
					2 => 2,
					3 => 3,
					4 => 4,
					5 => 1,
					6 => 2,
					7 => 3,
					8 => 4,
				),
				0,
			),
			'16 Mannschaften, acht aus einem Club'   => array(
				16,
				array(
					1  => 1,
					3  => 1,
					6  => 1,
					9  => 1,
					10 => 1,
					12 => 1,
					14 => 1,
					16 => 1,
				),
				0,
			),
			'11 Mannschaften, drei ungesetzte aus einem Club' => array(
				11,
				array(
					6  => 1,
					8  => 1,
					10 => 1,
				),
				0,
			),
			'8 Mannschaften, fünf aus einem Club: eine Paarung ist unvermeidlich' => array(
				8,
				array(
					1 => 1,
					2 => 1,
					4 => 1,
					6 => 1,
					7 => 1,
				),
				1,
			),
		);
	}

	/**
	 * @param array<int,int> $clubs Meldung => Club.
	 */
	#[DataProvider( 'club_cases' )]
	public function test_teams_of_the_same_club_are_kept_apart_while_possible( int $count, array $clubs, int $unavoidable ): void {
		$entries = self::entries( $count, $clubs );
		$by_id   = array_column( $entries, 'club_id', 'id' );
		foreach ( range( 1, 200 ) as $seed ) {
			$draw = Draw::draw( $entries, $seed );
			self::assertSame( $unavoidable, Draw::club_clashes( $draw['positions'], $by_id ), 'Startwert ' . $seed );
		}
	}

	public function test_who_is_named_first_is_drawn(): void {
		$first = array();
		foreach ( range( 1, 40 ) as $seed ) {
			foreach ( Draw::pairings( Draw::draw( self::entries( 8 ), $seed )['positions'] ) as $pairing ) {
				if ( in_array( 1, array( $pairing['home'], $pairing['away'] ), true ) ) {
					$first[ 1 === $pairing['home'] ? 'home' : 'away' ] = true;
				}
			}
		}
		self::assertCount( 2, $first, 'die Nummer 1 hat nicht immer Heimrecht' );
	}

	public function test_a_draw_needs_two_distinct_teams(): void {
		$this->expectException( \InvalidArgumentException::class );
		Draw::draw( self::entries( 1 ), 1 );
	}

	public function test_a_team_cannot_be_drawn_twice(): void {
		$entries   = self::entries( 4 );
		$entries[] = $entries[0];
		$this->expectException( \InvalidArgumentException::class );
		Draw::draw( $entries, 1 );
	}

	public function test_a_changed_bracket_is_checked(): void {
		$ids = range( 1, 6 );

		self::assertSame( array(), Draw::problems( array( 1, null, 2, null, 3, 4, 5, 6 ), $ids ) );
		self::assertSame( array( 'size' ), Draw::problems( array( 1, 2, 3, 4, 5, 6 ), $ids ) );
		self::assertSame( array( 'size' ), Draw::problems( array( 1, null ), array( 1 ) ) );
		self::assertSame( array( 'duplicate', 'missing' ), Draw::problems( array( 1, null, 2, null, 3, 4, 5, 5 ), $ids ) );
		self::assertSame( array( 'unknown', 'missing' ), Draw::problems( array( 1, null, 2, null, 3, 4, 5, 99 ), $ids ) );
		self::assertSame( array( 'empty_pairing' ), Draw::problems( array( 1, 2, null, null, 3, 4, 5, 6 ), $ids ) );

		self::assertSame( array( 1, null, 2, 3 ), Draw::normalize( array( null, 1, 2, 3 ) ) );
	}
}
