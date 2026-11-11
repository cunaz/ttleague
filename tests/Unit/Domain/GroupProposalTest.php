<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Ranking\GroupProposal;
use TTLeague\Domain\Rules\SeasonRules;

/**
 * Rundenwoche Montag, 16. bis Donnerstag, 19. November 2026. Vier Clubs (1 bis 4) mit je einem
 * Lokal (10, 20, 30, 40), Spieltag Montag bis Donnerstag. Person n gehört zum Club (n mod 4) + 1.
 */
final class GroupProposalTest extends TestCase {

	private const MON = '2026-11-16';
	private const TUE = '2026-11-17';
	private const WED = '2026-11-18';
	private const THU = '2026-11-19';

	/**
	 * @return iterable<string,array{0:int,1:list<int>}>
	 */
	public static function sizes(): iterable {
		$expected = array(
			5  => array( 5 ),
			6  => array( 6 ),
			7  => array( 7 ),
			8  => array( 4, 4 ),
			11 => array( 6, 5 ),
			12 => array( 6, 6 ),
			13 => array( 7, 6 ),
			14 => array( 5, 5, 4 ),
			18 => array( 6, 6, 6 ),
			19 => array( 7, 6, 6 ),
			20 => array( 5, 5, 5, 5 ),
			25 => array( 7, 6, 6, 6 ),
			40 => array( 6, 6, 6, 6, 6, 5, 5 ),
		);
		foreach ( $expected as $count => $sizes ) {
			yield $count . ' Angemeldete' => array( $count, $sizes );
		}
	}

	/**
	 * @param list<int> $sizes Erwartete Gruppengrössen.
	 */
	#[DataProvider( 'sizes' )]
	public function test_group_sizes( int $count, array $sizes ): void {
		self::assertSame( $sizes, ( new GroupProposal( new SeasonRules() ) )->group_sizes( $count ) );
	}

	public function test_sizes_from_five_to_forty_follow_the_rules(): void {
		$proposal = new GroupProposal( new SeasonRules() );
		for ( $count = 5; $count <= 40; $count++ ) {
			$sizes = $proposal->group_sizes( $count );

			self::assertSame( $count, array_sum( $sizes ), "$count: alle sind eingeteilt" );
			self::assertLessThanOrEqual( 1, max( $sizes ) - min( $sizes ), "$count: möglichst gleich gross" );
			// Die Siebnergruppe gibt es nur, wenn sonst eine Person eine weitere Gruppe verlangt.
			$exception = $count > 6 && 1 === $count % 6;
			self::assertLessThanOrEqual( $exception ? 7 : 6, max( $sizes ), "$count: Höchstgrösse" );
			self::assertSame( $exception ? 1 : 0, count( array_filter( $sizes, static fn( int $size ): bool => $size > 6 ) ), "$count: Ausnahmegrösse" );
			self::assertCount( (int) ceil( $count / 6 ) - ( $exception ? 1 : 0 ), $sizes, "$count: Anzahl Gruppen" );
		}
	}

	public function test_sizes_follow_the_season_rules(): void {
		$proposal = new GroupProposal( new SeasonRules( rl_group_size: 4, rl_group_size_max: 5 ) );

		self::assertSame( array( 4, 4 ), $proposal->group_sizes( 8 ) );
		self::assertSame( array( 5, 4 ), $proposal->group_sizes( 9 ) );
		self::assertSame( array( 4, 3, 3 ), $proposal->group_sizes( 10 ) );
		// Ohne Ausnahmegrösse gibt es nie eine grössere Gruppe.
		self::assertSame( array( 4, 3 ), ( new GroupProposal( new SeasonRules( rl_group_size: 6, rl_group_size_max: 6 ) ) )->group_sizes( 7 ) );
		self::assertSame( array(), $proposal->group_sizes( 0 ) );
	}

	public function test_everyone_is_assigned_once_from_five_to_forty(): void {
		$proposal = new GroupProposal( new SeasonRules() );
		for ( $count = 5; $count <= 40; $count++ ) {
			$result = $proposal->propose( array( 1 => self::people( $count ) ), self::slots( 3 ) );

			$members = array_merge( ...array_column( $result['groups'], 'members' ) );
			sort( $members );
			self::assertSame( range( 1, $count ), $members, "$count: jede Person genau einmal" );
			self::assertSame( array(), $result['unassigned'] );
			$sizes = array_map( 'count', array_column( $result['groups'], 'members' ) );
			rsort( $sizes );
			self::assertSame( $proposal->group_sizes( $count ), $sizes, "$count: Gruppengrössen" );
			self::assertSame( range( 1, count( $sizes ) ), array_column( $result['groups'], 'group_no' ) );
		}
	}

	public function test_each_group_gets_its_own_evening_with_a_host_player_as_chief(): void {
		$result = ( new GroupProposal( new SeasonRules() ) )->propose( array( 1 => self::people( 24 ) ), self::slots() );

		self::assertCount( 4, $result['groups'] );
		$used = array();
		foreach ( $result['groups'] as $group ) {
			self::assertNotNull( $group['slot'] );
			$used[] = $group['slot']['venue'] . '@' . $group['slot']['date'];
			// Gruppenchef ist die erstgenannte Person des Platzclubs.
			$hosts = array_values( array_filter( $group['members'], static fn( int $id ): bool => self::club( $id ) === $group['slot']['club'] ) );
			self::assertNotSame( array(), $hosts, 'Jede Gruppe hat eine Person des Platzclubs.' );
			self::assertSame( $hosts[0], $group['chief'] );
		}
		self::assertCount( 4, array_unique( $used ), 'Ein Abend in einem Lokal dient einer Gruppe.' );
	}

	public function test_chief_is_first_of_group_without_host_player(): void {
		// Niemand aus der Kategorie gehört zum Club 9, dem einzigen mit Lokal.
		$result = ( new GroupProposal( new SeasonRules() ) )->propose(
			array( 1 => self::people( 5 ) ),
			array(
				array(
					'club'  => 9,
					'venue' => 90,
					'date'  => self::TUE,
				),
			)
		);

		self::assertCount( 1, $result['groups'] );
		self::assertSame( 9, $result['groups'][0]['slot']['club'] );
		self::assertSame( array( 1, 2, 3, 4, 5 ), $result['groups'][0]['members'] );
		self::assertSame( 1, $result['groups'][0]['chief'] );
	}

	public function test_slots_are_shared_between_categories(): void {
		$slots  = array_slice( self::slots(), 0, 2 );
		$result = ( new GroupProposal( new SeasonRules() ) )->propose(
			array(
				2 => self::people( 6, 100 ),
				1 => self::people( 6 ),
			),
			$slots
		);

		self::assertSame( array( 1, 2 ), array_column( $result['groups'], 'category' ), 'Kategorien in aufsteigender Reihenfolge.' );
		self::assertNotSame( $result['groups'][0]['slot'], $result['groups'][1]['slot'] );
		self::assertNotNull( $result['groups'][0]['slot'] );
		self::assertNotNull( $result['groups'][1]['slot'] );
	}

	public function test_groups_without_enough_evenings_have_no_slot(): void {
		$result = ( new GroupProposal( new SeasonRules() ) )->propose( array( 1 => self::people( 12 ) ), array_slice( self::slots(), 0, 1 ) );

		self::assertCount( 2, $result['groups'] );
		self::assertNotNull( $result['groups'][0]['slot'] );
		self::assertNull( $result['groups'][1]['slot'], 'Die TK setzt den Abend von Hand.' );
		self::assertSame( array(), $result['unassigned'] );

		$none = ( new GroupProposal( new SeasonRules() ) )->propose( array( 1 => self::people( 7 ) ), array() );
		self::assertCount( 1, $none['groups'] );
		self::assertNull( $none['groups'][0]['slot'] );
		self::assertCount( 7, $none['groups'][0]['members'] );
	}

	public function test_nobody_plays_on_a_blocked_evening(): void {
		$people = self::people( 24 );
		// Jede zweite Person ist an zwei Abenden gesperrt, jede fünfte am Montag.
		foreach ( $people as $i => $person ) {
			if ( 0 === $person['id'] % 2 ) {
				$people[ $i ]['blocked'] = 0 === $person['id'] % 4 ? array( self::MON, self::TUE ) : array( self::WED, self::THU );
			}
			if ( 0 === $person['id'] % 5 ) {
				$people[ $i ]['blocked'][] = self::MON;
			}
		}
		$result = ( new GroupProposal( new SeasonRules() ) )->propose( array( 1 => $people ), self::slots() );

		$blocked = array_column( $people, 'blocked', 'id' );
		$seen    = 0;
		foreach ( $result['groups'] as $group ) {
			foreach ( $group['members'] as $id ) {
				self::assertNotContains( $group['slot']['date'], $blocked[ $id ], "Person $id spielt an einem gesperrten Abend." );
				++$seen;
			}
			self::assertLessThanOrEqual( 7, count( $group['members'] ) );
		}
		self::assertSame( 24, $seen + count( $result['unassigned'] ) );
		self::assertSame( array(), $result['unassigned'], 'Mit vier Abenden geht es für alle auf.' );
	}

	public function test_person_blocked_on_every_evening_stays_unassigned(): void {
		$people               = self::people( 7 );
		$people[2]['blocked'] = array( self::MON, self::TUE, self::WED, self::THU );
		// Ein Abend ausserhalb der möglichen Abende ändert nichts.
		$people[3]['blocked'] = array( '2026-11-20' );
		$result               = ( new GroupProposal( new SeasonRules() ) )->propose( array( 1 => $people ), self::slots() );

		self::assertSame(
			array(
				array(
					'id'       => 3,
					'category' => 1,
					'reason'   => GroupProposal::BLOCKED,
				),
			),
			$result['unassigned']
		);
		self::assertCount( 1, $result['groups'] );
		self::assertSame( array( 1, 2, 4, 5, 6, 7 ), $result['groups'][0]['members'] );
	}

	public function test_person_free_only_on_an_unused_evening_stays_unassigned(): void {
		// Sechs Personen, eine Gruppe: Alle können am Montag, Person 6 nur am Donnerstag.
		$people = self::people( 6 );
		foreach ( $people as $i => $person ) {
			$people[ $i ]['blocked'] = 6 === $person['id'] ? array( self::MON, self::TUE, self::WED ) : array( self::THU );
		}
		$result = ( new GroupProposal( new SeasonRules() ) )->propose( array( 1 => $people ), self::slots() );

		self::assertCount( 1, $result['groups'] );
		self::assertSame( array( 1, 2, 3, 4, 5 ), $result['groups'][0]['members'] );
		self::assertNotSame( self::THU, $result['groups'][0]['slot']['date'] );
		self::assertSame( array( 6 ), array_column( $result['unassigned'], 'id' ) );
		self::assertSame( array( GroupProposal::BLOCKED ), array_column( $result['unassigned'], 'reason' ) );
	}

	public function test_too_many_people_for_one_evening_stay_unassigned(): void {
		// Zwei Abende, zwölf Personen: Neun können nur am Montag, drei nur am Dienstag. Am Montag
		// haben höchstens sieben Platz.
		$people = self::people( 12 );
		foreach ( $people as $i => $person ) {
			$people[ $i ]['blocked'] = $person['id'] <= 9 ? array( self::TUE ) : array( self::MON );
		}
		$result = ( new GroupProposal( new SeasonRules() ) )->propose( array( 1 => $people ), array_slice( self::slots(), 0, 2 ) );

		$by_date = array();
		foreach ( $result['groups'] as $group ) {
			$by_date[ $group['slot']['date'] ] = $group['members'];
		}
		self::assertCount( 7, $by_date[ self::MON ], 'Bis zur Ausnahmegrösse, nicht darüber.' );
		self::assertSame( array( 10, 11, 12 ), $by_date[ self::TUE ] );
		self::assertCount( 2, $result['unassigned'] );
		self::assertSame( array( GroupProposal::FULL ), array_values( array_unique( array_column( $result['unassigned'], 'reason' ) ) ) );
		foreach ( $result['unassigned'] as $row ) {
			self::assertLessThanOrEqual( 9, $row['id'] );
		}
	}

	public function test_closed_venue_and_matchday_decide_the_evenings(): void {
		$venues = array(
			array(
				'id'      => 10,
				'club'    => 1,
				'weekday' => 1,
			),
			array(
				'id'      => 20,
				'club'    => 2,
				'weekday' => 2,
			),
			array(
				'id'      => 30,
				'club'    => 3,
				'weekday' => 3,
			),
			// Spieltag Freitag liegt ausserhalb der Rundenwoche, ein Lokal ohne Spieltag fällt weg.
			array(
				'id'      => 50,
				'club'    => 5,
				'weekday' => 5,
			),
			array(
				'id'      => 60,
				'club'    => 6,
				'weekday' => null,
			),
		);
		$closures = array(
			// Lokal 10 ist genau am Montag gesperrt, Lokal 20 bis und mit Montag (Dienstag wieder offen).
			array(
				'venue' => 10,
				'from'  => self::MON,
				'to'    => self::MON,
			),
			array(
				'venue' => 20,
				'from'  => '2026-11-09',
				'to'    => self::MON,
			),
			// Lokal 30 ist ab Mittwoch gesperrt.
			array(
				'venue' => 30,
				'from'  => self::WED,
				'to'    => '2026-11-30',
			),
		);

		self::assertSame(
			array(
				array(
					'club'  => 2,
					'venue' => 20,
					'date'  => self::TUE,
				),
			),
			GroupProposal::slots( self::MON, self::THU, $venues, $closures )
		);
		self::assertSame(
			array( self::MON, self::TUE, self::WED ),
			array_column( GroupProposal::slots( self::MON, self::THU, $venues, array() ), 'date' )
		);
		self::assertSame( array(), GroupProposal::slots( self::THU, self::MON, $venues, array() ), 'Ende vor Beginn.' );

		// Ein Lokal mit Platz für zwei Gruppen liefert den Abend zweimal.
		$double = GroupProposal::slots(
			self::MON,
			self::THU,
			array(
				array(
					'id'      => 20,
					'club'    => 2,
					'weekday' => 2,
					'groups'  => 2,
				),
			),
			array()
		);
		self::assertCount( 2, $double );
		$two = ( new GroupProposal( new SeasonRules() ) )->propose( array( 1 => self::people( 12 ) ), $double );
		self::assertSame( array( 20, 20 ), array( $two['groups'][0]['slot']['venue'], $two['groups'][1]['slot']['venue'] ) );

		// Das gesperrte Lokal kommt im Vorschlag nicht vor.
		$result = ( new GroupProposal( new SeasonRules() ) )->propose( array( 1 => self::people( 6 ) ), GroupProposal::slots( self::MON, self::THU, $venues, $closures ) );
		self::assertSame( 20, $result['groups'][0]['slot']['venue'] );
	}

	public function test_three_rounds_bring_new_opponents(): void {
		$proposal = new GroupProposal( new SeasonRules() );
		$people   = self::people( 18 );
		$history  = array();
		$hosted   = array();
		$repeats  = array();
		$groups   = array();
		for ( $round = 1; $round <= 3; $round++ ) {
			$result            = $proposal->propose( array( 1 => $people ), self::slots(), $history, false, $hosted );
			$repeats[ $round ] = $result['repeats'];
			$groups[ $round ]  = array_column( $result['groups'], 'members' );
			foreach ( $result['groups'] as $group ) {
				$hosted[ $group['slot']['club'] ] = ( $hosted[ $group['slot']['club'] ] ?? 0 ) + 1;
				foreach ( $group['members'] as $a ) {
					foreach ( $group['members'] as $b ) {
						if ( $a < $b ) {
							$key             = GroupProposal::pair_key( $a, $b );
							$history[ $key ] = ( $history[ $key ] ?? 0 ) + 1;
						}
					}
				}
			}
		}

		self::assertSame( 0, $repeats[1] );
		// 18 Personen in drei Sechsergruppen: Aus jeder alten Gruppe kommen höchstens zwei wieder
		// zusammen, das sind neun Paarungen von 45. Ohne Blick auf die Vorrunden wären es alle 45.
		self::assertSame( 9, $repeats[2] );
		self::assertLessThanOrEqual( 18, $repeats[3] );
		self::assertSame( 2, max( $history ), 'Niemand trifft dieselbe Person in allen drei Runden.' );
		self::assertNotEquals( $groups[1], $groups[2] );
		// Der Platzclub wechselt: Nach drei Runden mit je drei Gruppen war jeder der vier Clubs an der Reihe.
		self::assertCount( 4, $hosted );
		self::assertLessThanOrEqual( 3, max( $hosted ) );
	}

	public function test_history_is_ignored_only_without_history(): void {
		$proposal = new GroupProposal( new SeasonRules() );
		$people   = self::people( 12 );
		$first    = $proposal->propose( array( 1 => $people ), self::slots() );
		$history  = array();
		foreach ( $first['groups'] as $group ) {
			foreach ( $group['members'] as $a ) {
				foreach ( $group['members'] as $b ) {
					if ( $a < $b ) {
						$history[ GroupProposal::pair_key( $a, $b ) ] = 1;
					}
				}
			}
		}
		$second = $proposal->propose( array( 1 => $people ), self::slots(), $history );

		// Zwei Sechsergruppen: je drei aus jeder alten Gruppe, also zwölf wiederholte Paarungen statt 30.
		self::assertSame( 12, $second['repeats'] );
	}

	public function test_final_is_one_group_per_category(): void {
		$result = ( new GroupProposal( new SeasonRules() ) )->propose(
			array(
				1 => self::people( 8 ),
				2 => self::people( 6, 100 ),
			),
			self::slots(),
			array(),
			true
		);

		self::assertCount( 2, $result['groups'] );
		self::assertCount( 8, $result['groups'][0]['members'] );
		self::assertCount( 6, $result['groups'][1]['members'] );
		self::assertSame( array(), $result['unassigned'] );
	}

	public function test_proposal_is_deterministic(): void {
		$proposal = new GroupProposal( new SeasonRules() );
		$people   = self::people( 23 );
		foreach ( $people as $i => $person ) {
			if ( 0 === $person['id'] % 3 ) {
				$people[ $i ]['blocked'] = array( self::TUE );
			}
		}
		$history = array(
			GroupProposal::pair_key( 1, 2 ) => 2,
			GroupProposal::pair_key( 5, 9 ) => 1,
			GroupProposal::pair_key( 7, 8 ) => 1,
		);
		$first   = $proposal->propose( array( 1 => $people ), self::slots(), $history );

		self::assertSame( $first, $proposal->propose( array( 1 => $people ), self::slots(), $history ) );
		// Die Reihenfolge der Slots in der Eingabe spielt keine Rolle.
		self::assertSame( $first, $proposal->propose( array( 1 => $people ), array_reverse( self::slots() ), $history ) );
		self::assertSame( '3|12', GroupProposal::pair_key( 12, 3 ) );
	}

	/**
	 * Personen $offset + 1 bis $offset + $count, verteilt auf die vier Clubs.
	 *
	 * @return list<array{id:int,club:int,blocked:list<string>}>
	 */
	private static function people( int $count, int $offset = 0 ): array {
		$people = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$people[] = array(
				'id'      => $offset + $i,
				'club'    => self::club( $offset + $i ),
				'blocked' => array(),
			);
		}
		return $people;
	}

	private static function club( int $id ): int {
		return $id % 4 + 1;
	}

	/**
	 * Abende der vier Lokale an ihrem Spieltag, auf Wunsch mit mehreren Lokalen pro Club.
	 *
	 * @return list<array{club:int,venue:int,date:string}>
	 */
	private static function slots( int $venues_per_club = 1 ): array {
		$venues = array();
		for ( $club = 1; $club <= 4; $club++ ) {
			for ( $n = 0; $n < $venues_per_club; $n++ ) {
				$venues[] = array(
					'id'      => $club * 10 + $n,
					'club'    => $club,
					'weekday' => $club,
				);
			}
		}
		return GroupProposal::slots( self::MON, self::THU, $venues, array() );
	}
}
