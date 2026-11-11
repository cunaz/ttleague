<?php
/**
 * Einteilung in Kategorien und Gruppen: Vorschlag und weiche Hinweise (Art. 11).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Season\GroupingRules;

final class SeasonGroupingRulesTest extends TestCase {

	private const CLUBS = array(
		1 => 'TTC Seefeld',
		2 => 'TTC Wollishofen',
		3 => 'TTC Oerlikon',
	);

	/**
	 * @return array{id:int,name:string,category:string|null}
	 */
	private static function group( int $id, string $category, int $group_no = 1 ): array {
		return array(
			'id'       => $id,
			'name'     => 'Gruppe ' . $category . ( $group_no > 1 ? $group_no : '' ),
			'category' => $category,
		);
	}

	/**
	 * @return array{id:int,name:string,club_id:int,competition_id:int|null}
	 */
	private static function team( int $id, int $club_id, int $number, ?int $group ): array {
		$short = array(
			1 => 'Seefeld',
			2 => 'Wollishofen',
			3 => 'Oerlikon',
		);
		return array(
			'id'             => $id,
			'name'           => $short[ $club_id ] . ' ' . $number,
			'club_id'        => $club_id,
			'competition_id' => $group,
		);
	}

	/**
	 * @param list<array<string,mixed>> $hints Hinweise.
	 * @return list<string>
	 */
	private static function codes( array $hints ): array {
		return array_column( $hints, 'code' );
	}

	public function test_a_team_of_last_year_stays_in_its_group(): void {
		$proposal = GroupingRules::proposal(
			array(
				array(
					'id'      => 11,
					'club_id' => 1,
					'number'  => 1,
				),
				array(
					'id'      => 12,
					'club_id' => 1,
					'number'  => 2,
				),
				array(
					'id'      => 13,
					'club_id' => 2,
					'number'  => 1,
				),
			),
			array(
				array(
					'club_id'  => 1,
					'number'   => 1,
					'category' => 'A',
					'group_no' => 1,
				),
				array(
					'club_id'  => 1,
					'number'   => 2,
					'category' => 'B',
					'group_no' => 2,
				),
			)
		);
		self::assertSame(
			array(
				'category' => 'A',
				'group_no' => 1,
			),
			$proposal[11]
		);
		self::assertSame(
			array(
				'category' => 'B',
				'group_no' => 2,
			),
			$proposal[12]
		);
		self::assertNull( $proposal[13], 'Eine neue Mannschaft bleibt ohne Gruppe.' );
	}

	public function test_the_same_number_of_another_club_is_not_a_predecessor(): void {
		$proposal = GroupingRules::proposal(
			array(
				array(
					'id'      => 11,
					'club_id' => 2,
					'number'  => 1,
				),
			),
			array(
				array(
					'club_id'  => 1,
					'number'   => 1,
					'category' => 'A',
					'group_no' => 1,
				),
				array(
					'club_id'  => 2,
					'number'   => 2,
					'category' => 'B',
					'group_no' => 1,
				),
			)
		);
		self::assertNull( $proposal[11] );
	}

	public function test_a_team_without_group_last_year_gets_no_proposal(): void {
		$proposal = GroupingRules::proposal(
			array(
				array(
					'id'      => 11,
					'club_id' => 1,
					'number'  => 1,
				),
			),
			array(
				array(
					'club_id'  => 1,
					'number'   => 1,
					'category' => null,
					'group_no' => null,
				),
			)
		);
		self::assertNull( $proposal[11] );
	}

	public function test_three_teams_of_a_club_in_one_category_are_a_hint(): void {
		$groups = array( self::group( 1, 'A' ), self::group( 2, 'B' ) );
		$teams  = array(
			self::team( 1, 1, 1, 1 ),
			self::team( 2, 1, 2, 1 ),
			self::team( 3, 1, 3, 1 ),
			self::team( 4, 2, 1, 2 ),
			self::team( 5, 2, 2, 2 ),
			self::team( 6, 3, 1, 2 ),
		);
		$hints  = GroupingRules::hints( $groups, $teams, self::CLUBS );
		self::assertSame( array( GroupingRules::CLUB_LIMIT ), self::codes( $hints ) );
		self::assertSame( 1, $hints[0]['club_id'] );
		self::assertStringContainsString( 'TTC Seefeld hat 3 Mannschaften in der Kategorie A', $hints[0]['message'] );
		self::assertStringContainsString( 'Art. 11.4', $hints[0]['message'] );
	}

	public function test_two_teams_of_a_club_in_one_category_are_allowed(): void {
		$groups = array( self::group( 1, 'A' ), self::group( 2, 'B' ) );
		$teams  = array(
			self::team( 1, 1, 1, 1 ),
			self::team( 2, 1, 2, 1 ),
			self::team( 3, 2, 1, 1 ),
			self::team( 4, 2, 2, 2 ),
			self::team( 5, 3, 1, 2 ),
			self::team( 6, 3, 2, 2 ),
		);
		self::assertSame( array(), GroupingRules::hints( $groups, $teams, self::CLUBS ) );
	}

	public function test_the_lowest_category_is_exempt(): void {
		$groups = array( self::group( 1, 'A' ), self::group( 2, 'B' ) );
		$teams  = array(
			self::team( 1, 2, 1, 1 ),
			self::team( 2, 3, 1, 1 ),
			self::team( 3, 2, 2, 1 ),
			self::team( 4, 1, 1, 2 ),
			self::team( 5, 1, 2, 2 ),
			self::team( 6, 1, 3, 2 ),
		);
		self::assertSame( 'B', GroupingRules::lowest_category( $groups ) );
		self::assertSame( array(), GroupingRules::hints( $groups, $teams, self::CLUBS ) );
	}

	public function test_all_groups_of_a_category_count_together(): void {
		$groups = array( self::group( 1, 'B', 1 ), self::group( 2, 'B', 2 ), self::group( 3, 'C' ) );
		$teams  = array(
			self::team( 1, 1, 1, 1 ),
			self::team( 2, 1, 2, 1 ),
			self::team( 3, 2, 1, 1 ),
			self::team( 4, 1, 3, 2 ),
			self::team( 5, 2, 2, 2 ),
			self::team( 6, 3, 1, 2 ),
			self::team( 7, 3, 2, 3 ),
			self::team( 8, 3, 3, 3 ),
			self::team( 9, 2, 3, 3 ),
		);
		$hints  = GroupingRules::hints( $groups, $teams, self::CLUBS );
		self::assertSame( array( GroupingRules::CLUB_LIMIT ), self::codes( $hints ) );
		self::assertSame( 1, $hints[0]['club_id'] );
	}

	public function test_a_group_with_two_teams_is_a_hint_and_three_are_enough(): void {
		$groups = array( self::group( 1, 'A' ), self::group( 2, 'B' ) );
		$teams  = array(
			self::team( 1, 1, 1, 1 ),
			self::team( 2, 2, 1, 1 ),
			self::team( 3, 3, 1, 2 ),
			self::team( 4, 1, 2, 2 ),
			self::team( 5, 2, 2, 2 ),
		);
		$hints  = GroupingRules::hints( $groups, $teams );
		self::assertSame( array( GroupingRules::SMALL_GROUP ), self::codes( $hints ) );
		self::assertSame( 1, $hints[0]['group_id'] );
		self::assertStringContainsString( 'Gruppe A hat nur 2 Mannschaften', $hints[0]['message'] );
	}

	public function test_an_empty_group_and_an_unassigned_team_are_hints(): void {
		$groups = array( self::group( 1, 'A' ) );
		$hints  = GroupingRules::hints( $groups, array( self::team( 7, 1, 1, null ) ) );
		self::assertSame( array( GroupingRules::UNASSIGNED, GroupingRules::SMALL_GROUP ), self::codes( $hints ) );
		self::assertSame( 7, $hints[0]['team_id'] );
		self::assertStringContainsString( 'Seefeld 1', $hints[0]['message'] );
		self::assertStringContainsString( 'noch keine Mannschaft', $hints[1]['message'] );
	}

	public function test_a_team_in_a_group_of_another_season_counts_as_unassigned(): void {
		$hints = GroupingRules::hints( array(), array( self::team( 7, 1, 1, 99 ) ) );
		self::assertSame( array( GroupingRules::UNASSIGNED ), self::codes( $hints ) );
	}

	public function test_a_new_group_gets_its_default_name(): void {
		self::assertSame( 'Gruppe A', GroupingRules::group( ' a ', 1, '' )['name'] );
		self::assertSame( 'Gruppe B2', GroupingRules::group( 'B', 2, '' )['name'] );
		$group = GroupingRules::group( 'C', 1, ' Gruppe C Ost ' );
		self::assertSame( 'Gruppe C Ost', $group['name'] );
		self::assertSame( array(), $group['errors'] );
	}

	public function test_an_invalid_group_names_its_fields(): void {
		self::assertSame( array( 'category' ), array_column( GroupingRules::group( 'AB', 1, '' )['errors'], 'field' ) );
		self::assertSame( array( 'group_no' ), array_column( GroupingRules::group( 'A', 0, '' )['errors'], 'field' ) );
		self::assertSame( array( 'group_no' ), array_column( GroupingRules::group( 'A', 100, '' )['errors'], 'field' ) );
		self::assertSame( array(), GroupingRules::group( 'A', 99, '' )['errors'] );
		self::assertSame( array( 'name' ), array_column( GroupingRules::group( 'A', 1, str_repeat( 'x', 81 ) )['errors'], 'field' ) );
		self::assertSame( array(), GroupingRules::group( 'A', 1, str_repeat( 'x', 80 ) )['errors'] );
	}

	public function test_deadlines_are_not_carried_into_the_next_season(): void {
		self::assertSame(
			array(
				'points_limit'  => 20,
				'categories'    => array( 1 => array( 1, 5 ) ),
				'reminder_days' => 3,
			),
			GroupingRules::carried_format(
				array(
					'points_limit'          => 20,
					'registration_deadline' => '2026-09-30',
					'categories'            => array( 1 => array( 1, 5 ) ),
					'round_deadlines'       => array( '2026-11-30' ),
					'reminder_days'         => 3,
				)
			)
		);
	}
}
