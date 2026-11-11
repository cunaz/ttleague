<?php
/**
 * Regeln des Cup-Tableaus: Platz in der nächsten Runde, Heimrecht, Stand einer Paarung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Cup\BracketRules as Rules;

final class CupBracketRulesTest extends TestCase {

	/**
	 * @return array{status:string,home_entry:int|null,away_entry:int|null,home_score:int|null,away_score:int|null}
	 */
	private static function fixture( string $status, ?int $home_score = null, ?int $away_score = null, int $home = 1, int $away = 2 ): array {
		return array(
			'status'     => $status,
			'home_entry' => $home,
			'away_entry' => $away,
			'home_score' => $home_score,
			'away_score' => $away_score,
		);
	}

	public function test_the_winner_moves_to_the_next_round_and_the_upper_place_is_at_home(): void {
		// Tableau mit 8 Plätzen: drei Runden.
		self::assertSame(
			array(
				'round' => 2,
				'slot'  => 1,
				'side'  => 'home',
			),
			Rules::next( 1, 1, 3 )
		);
		self::assertSame(
			array(
				'round' => 2,
				'slot'  => 1,
				'side'  => 'away',
			),
			Rules::next( 1, 2, 3 )
		);
		self::assertSame(
			array(
				'round' => 2,
				'slot'  => 2,
				'side'  => 'home',
			),
			Rules::next( 1, 3, 3 )
		);
		self::assertSame(
			array(
				'round' => 3,
				'slot'  => 1,
				'side'  => 'away',
			),
			Rules::next( 2, 2, 3 )
		);
		self::assertNull( Rules::next( 3, 1, 3 ), 'nach dem Final kommt nichts' );
	}

	public function test_feeder_is_the_inverse_of_next(): void {
		foreach ( array( 1, 2, 3 ) as $round ) {
			for ( $slot = 1; $slot <= 2 ** ( 4 - $round ); $slot++ ) {
				$next = Rules::next( $round, $slot, 4 );
				self::assertNotNull( $next );
				self::assertSame(
					array(
						'round' => $round,
						'slot'  => $slot,
					),
					Rules::feeder( $next['round'], $next['slot'], $next['side'] )
				);
			}
		}
		self::assertNull( Rules::feeder( 1, 1, 'home' ) );
	}

	public function test_semi_final_and_final_have_no_home_right(): void {
		self::assertSame(
			array( false, false, true, true ),
			array( Rules::is_neutral( 1, 4 ), Rules::is_neutral( 2, 4 ), Rules::is_neutral( 3, 4 ), Rules::is_neutral( 4, 4 ) )
		);
		self::assertFalse( Rules::is_neutral( 1, 3 ), 'Viertelfinal im Tableau mit 8 Plätzen' );
		self::assertTrue( Rules::is_neutral( 2, 3 ) );
	}

	/**
	 * @return array<string,array{0:string,1:int|null,2:int|null,3:string|null}>
	 */
	public static function fixture_results(): array {
		return array(
			'bestätigt, Heimsieg'                => array( 'confirmed', 3, 1, 'home' ),
			'abgeschlossen, Gastsieg'            => array( 'final', 2, 3, 'away' ),
			'forfait für den Gast'               => array( 'forfeit', 0, 10, 'away' ),
			'provisorisch zählt noch nicht'      => array( 'provisional', 3, 0, null ),
			'strittig zählt noch nicht'          => array( 'disputed', 3, 0, null ),
			'angesetzt'                          => array( 'scheduled', null, null, null ),
			'bestätigt ohne Resultat'            => array( 'confirmed', null, null, null ),
			'unentschieden gibt es im Cup nicht' => array( 'final', 2, 2, null ),
		);
	}

	#[DataProvider( 'fixture_results' )]
	public function test_a_fixture_has_a_winner_once_confirmed_final_or_forfeit( string $status, ?int $home, ?int $away, ?string $winner ): void {
		self::assertSame( $winner, Rules::fixture_winner( $status, $home, $away ) );
	}

	public function test_only_a_fixture_without_any_result_may_get_another_team(): void {
		self::assertTrue( Rules::fixture_untouched( 'scheduled', null, null ) );
		self::assertTrue( Rules::fixture_untouched( 'postponed', null, null ) );
		self::assertFalse( Rules::fixture_untouched( 'provisional', 3, 1 ) );
		self::assertFalse( Rules::fixture_untouched( 'confirmed', 3, 1 ) );
		self::assertFalse( Rules::fixture_untouched( 'forfeit', 10, 0 ) );
		self::assertFalse( Rules::fixture_untouched( 'cancelled', null, null ) );
	}

	public function test_a_confirmed_result_decides_the_tie(): void {
		$outcome = Rules::outcome( 1, 2, true, true, array(), self::fixture( 'confirmed', 1, 3 ) );

		self::assertSame(
			array(
				'state'  => 'decided',
				'winner' => 2,
				'by'     => 'result',
			),
			$outcome
		);
		self::assertSame( 1, Rules::outcome( 1, 2, true, true, array(), self::fixture( 'forfeit', 10, 0 ) )['winner'] );
	}

	public function test_a_tie_waits_for_both_teams_and_the_result(): void {
		self::assertSame( 'pending', Rules::outcome( 1, 2, true, true, array(), null )['state'], 'ohne Begegnung' );
		self::assertSame( 'pending', Rules::outcome( 1, 2, true, true, array(), self::fixture( 'scheduled' ) )['state'] );
		self::assertSame( 'pending', Rules::outcome( 1, 2, true, true, array(), self::fixture( 'provisional', 3, 0 ) )['state'], 'provisorisch rückt niemand nach' );
		self::assertSame( 'pending', Rules::outcome( 1, null, true, false, array(), null )['state'], 'der Gegner aus der Vorrunde steht noch nicht fest' );
		self::assertSame( 'pending', Rules::outcome( null, null, false, false, array(), null )['state'] );
	}

	public function test_a_bye_moves_on_without_a_fixture(): void {
		self::assertSame(
			array(
				'state'  => 'decided',
				'winner' => 1,
				'by'     => 'walkover',
			),
			Rules::outcome( 1, null, true, true, array(), null )
		);
		self::assertSame( 2, Rules::outcome( null, 2, true, true, array(), null )['winner'] );
	}

	public function test_the_opponent_of_a_withdrawn_team_moves_on(): void {
		$withdrawn = array( 2 => true );

		self::assertSame(
			array(
				'state'  => 'decided',
				'winner' => 1,
				'by'     => 'walkover',
			),
			Rules::outcome( 1, 2, true, true, $withdrawn, self::fixture( 'scheduled' ) )
		);
		self::assertSame( 'pending', Rules::outcome( null, 2, false, true, $withdrawn, null )['state'], 'der Gegner steht noch nicht fest' );
	}

	public function test_nobody_moves_on_from_a_tie_without_teams(): void {
		self::assertSame( 'dead', Rules::outcome( 1, 2, true, true, array( 1 => true, 2 => true ), null )['state'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kurze Liste.
		self::assertSame( 'dead', Rules::outcome( 1, null, true, true, array( 1 => true ), null )['state'], 'Freilos zurückgezogen' );
		self::assertSame( 'dead', Rules::outcome( null, null, true, true, array(), null )['state'] );
	}

	public function test_a_result_stands_when_the_winner_withdraws_later(): void {
		$outcome = Rules::outcome( 1, 2, true, true, array( 1 => true ), self::fixture( 'final', 3, 2 ) );

		self::assertSame( 1, $outcome['winner'], 'der Rückzug wirkt in der nächsten Paarung' );
		self::assertSame( 'result', $outcome['by'] );
	}

	public function test_a_result_of_other_teams_does_not_decide_the_tie(): void {
		// Die Begegnung gehört noch zu einer früheren Besetzung der Paarung.
		$stale = self::fixture( 'confirmed', 3, 0, 7, 2 );

		self::assertSame( 'pending', Rules::outcome( 1, 2, true, true, array(), $stale )['state'] );
	}
}
