<?php
/**
 * Aufstellungsregel einer Cupmannschaft (Art. 20.1 und 20.2).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Cup\LineupRule;

/**
 * Gemeldet sind die Spieler 1 (Klassierung 10) und 2 (Klassierung 8), Punktesumme 18.
 */
final class CupLineupRuleTest extends TestCase {

	private const ORIGINAL = array( 1, 2 );

	public function test_the_registered_pair_may_play(): void {
		self::assertSame(
			array(),
			LineupRule::violations(
				self::ORIGINAL,
				18,
				array(
					1 => 10,
					2 => 8,
				)
			)
		);
	}

	public function test_one_original_player_with_a_substitute_within_the_points_is_allowed(): void {
		self::assertSame(
			array(),
			LineupRule::violations(
				self::ORIGINAL,
				18,
				array(
					1 => 10,
					3 => 8,
				)
			),
			'Summe genau an der Grenze'
		);
		self::assertSame(
			array(),
			LineupRule::violations(
				self::ORIGINAL,
				18,
				array(
					2 => 8,
					3 => 4,
				)
			)
		);
	}

	public function test_without_an_original_player_the_lineup_is_not_allowed(): void {
		self::assertSame(
			array( LineupRule::NO_ORIGINAL ),
			LineupRule::violations(
				self::ORIGINAL,
				18,
				array(
					3 => 8,
					4 => 7,
				)
			)
		);
	}

	public function test_the_sum_of_the_fielded_players_must_not_exceed_the_registered_total(): void {
		self::assertSame(
			array( LineupRule::POINTS_EXCEEDED ),
			LineupRule::violations(
				self::ORIGINAL,
				18,
				array(
					1 => 10,
					3 => 9,
				)
			),
			'ein Punkt über der Summe der Meldung'
		);
		self::assertSame(
			array( LineupRule::NO_ORIGINAL, LineupRule::POINTS_EXCEEDED ),
			LineupRule::violations(
				self::ORIGINAL,
				18,
				array(
					3 => 12,
					4 => 9,
				)
			)
		);
	}

	public function test_the_registered_pair_is_not_measured_against_a_later_classification(): void {
		// Spieler 1 wurde seit der Meldung höher klassiert.
		self::assertSame(
			array(),
			LineupRule::violations(
				self::ORIGINAL,
				18,
				array(
					1 => 11,
					2 => 8,
				)
			)
		);
	}

	public function test_missing_data_of_the_entry_skips_the_check(): void {
		$fielded = array(
			3 => 12,
			4 => 9,
		);

		self::assertSame( array( LineupRule::NO_ORIGINAL ), LineupRule::violations( self::ORIGINAL, null, $fielded ), 'ohne Punktesumme keine Summenprüfung' );
		self::assertSame( array(), LineupRule::violations( array(), null, $fielded ), 'importierte Meldung ohne Spieler' );
		self::assertSame( array(), LineupRule::violations( self::ORIGINAL, 18, array() ), 'ohne Aufstellung nichts zu prüfen' );
	}

	public function test_points_count_missing_classifications_as_zero(): void {
		self::assertSame(
			10,
			LineupRule::points(
				array(
					1 => 10,
					5 => null,
				)
			)
		);
	}

	public function test_a_substitute_of_another_cup_team_is_blocked(): void {
		$other = array(
			3 => 'Seefeld 2',
			1 => 'Seefeld 3',
		);

		self::assertSame( 'Seefeld 2', LineupRule::blocked_by( 3, self::ORIGINAL, $other ) );
		self::assertNull( LineupRule::blocked_by( 4, self::ORIGINAL, $other ), 'in keiner anderen Cupmannschaft' );
		self::assertNull( LineupRule::blocked_by( 1, self::ORIGINAL, $other ), 'Spieler der Meldung sind nie gesperrt' );
	}
}
