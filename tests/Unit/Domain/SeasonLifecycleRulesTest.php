<?php
/**
 * Voraussetzungen für Freigabe und Abschluss einer Saison.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Season\LifecycleRules;

final class SeasonLifecycleRulesTest extends TestCase {

	/**
	 * Stand einer Saison, die sich freigeben lässt.
	 *
	 * @param array<string,mixed> $change Abweichungen.
	 * @return array<string,mixed>
	 */
	private static function facts( array $change = array() ): array {
		return $change + array(
			'rules_error'                       => null,
			'unreviewed'                        => array(),
			'teams'                             => 6,
			'unassigned'                        => array(),
			'groups_without_schedule'           => array(),
			'schedule_error'                    => null,
			'hard_conflicts'                    => 0,
			'predecessor'                       => '2026/27',
			'predecessor_open_fixtures'         => 0,
			'predecessor_classification_closed' => true,
		);
	}

	public function test_a_complete_season_has_nothing_open(): void {
		self::assertSame( array(), LifecycleRules::publish_blockers( self::facts() ) );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:string,2:bool}>
	 */
	public static function single_blockers(): array {
		return array(
			'ungültige Regeln'            => array( array( 'rules_error' => 'sets_to_win muss mindestens 1 sein.' ), LifecycleRules::RULES, false ),
			'ungeprüfte Meldung'          => array( array( 'unreviewed' => array( 'TTC Seefeld' ) ), LifecycleRules::REGISTRATIONS, false ),
			'keine Mannschaft'            => array( array( 'teams' => 0 ), LifecycleRules::NO_TEAMS, false ),
			'Mannschaft ohne Gruppe'      => array( array( 'unassigned' => array( 'Seefeld 3' ) ), LifecycleRules::UNASSIGNED, false ),
			'Gruppe ohne Spielplan'       => array( array( 'groups_without_schedule' => array( 'Gruppe B' ) ), LifecycleRules::NO_SCHEDULE, false ),
			'Spielplan nicht prüfbar'     => array( array( 'schedule_error' => 'Team steht in keiner Gruppe.' ), LifecycleRules::UNCHECKED, false ),
			'eine harte Kollision'        => array( array( 'hard_conflicts' => 1 ), LifecycleRules::CONFLICTS, true ),
			'offene Begegnung im Vorjahr' => array( array( 'predecessor_open_fixtures' => 1 ), LifecycleRules::PREDECESSOR, true ),
			'Klassierung offen'           => array( array( 'predecessor_classification_closed' => false ), LifecycleRules::PREDECESSOR, true ),
		);
	}

	/**
	 * @param array<string,mixed> $change Abweichung vom freigabefähigen Stand.
	 */
	#[DataProvider( 'single_blockers' )]
	public function test_each_missing_prerequisite_blocks_on_its_own( array $change, string $code, bool $confirmable ): void {
		$open = LifecycleRules::publish_blockers( self::facts( $change ) );
		self::assertSame( array( $code ), array_column( $open, 'code' ) );
		self::assertSame( $confirmable, $open[0]['confirmable'] );
		self::assertNotSame( '', $open[0]['message'] );
	}

	public function test_the_messages_name_what_is_missing(): void {
		$open = LifecycleRules::publish_blockers(
			self::facts(
				array(
					'unreviewed'              => array( 'TTC Seefeld', 'TTC Oerlikon' ),
					'unassigned'              => array( 'Seefeld 3' ),
					'groups_without_schedule' => array( 'Gruppe B' ),
					'hard_conflicts'          => 3,
				)
			)
		);
		$text = implode( ' ', array_column( $open, 'message' ) );
		self::assertStringContainsString( 'TTC Seefeld, TTC Oerlikon', $text );
		self::assertStringContainsString( 'Seefeld 3', $text );
		self::assertStringContainsString( 'Gruppe B', $text );
		self::assertStringContainsString( '3 harte Kollisionen', $text );
	}

	public function test_hard_conflicts_can_be_confirmed_explicitly(): void {
		$facts = self::facts( array( 'hard_conflicts' => 2 ) );
		self::assertSame( array(), LifecycleRules::publish_blockers( $facts, array( LifecycleRules::CONFLICTS ) ) );
		self::assertCount( 1, LifecycleRules::publish_blockers( $facts, array( LifecycleRules::PREDECESSOR ) ), 'Eine andere Bestätigung gilt nicht für die Kollisionen.' );
	}

	public function test_an_unfinished_predecessor_can_be_confirmed_explicitly(): void {
		$facts = self::facts(
			array(
				'predecessor_open_fixtures'         => 4,
				'predecessor_classification_closed' => false,
			)
		);
		$open  = LifecycleRules::publish_blockers( $facts );
		self::assertSame( array( LifecycleRules::PREDECESSOR ), array_column( $open, 'code' ) );
		self::assertStringContainsString( '2026/27', $open[0]['message'] );
		self::assertStringContainsString( '4 Begegnungen', $open[0]['message'] );
		self::assertStringContainsString( 'Klassierung', $open[0]['message'] );
		self::assertSame( array(), LifecycleRules::publish_blockers( $facts, array( LifecycleRules::PREDECESSOR ) ) );
	}

	public function test_without_a_predecessor_its_state_does_not_matter(): void {
		$facts = self::facts(
			array(
				'predecessor'                       => null,
				'predecessor_open_fixtures'         => 0,
				'predecessor_classification_closed' => false,
			)
		);
		self::assertSame( array(), LifecycleRules::publish_blockers( $facts ) );
	}

	public function test_a_confirmation_never_covers_the_other_prerequisites(): void {
		$open = LifecycleRules::publish_blockers(
			self::facts(
				array(
					'unreviewed'     => array( 'TTC Seefeld' ),
					'schedule_error' => 'kaputt',
					'hard_conflicts' => 2,
				)
			),
			LifecycleRules::CONFIRMABLE
		);
		self::assertSame( array( LifecycleRules::REGISTRATIONS, LifecycleRules::UNCHECKED ), array_column( $open, 'code' ) );
	}

	public function test_a_finished_season_can_be_closed(): void {
		self::assertSame(
			array(),
			LifecycleRules::close_blockers(
				array(
					'final'     => 40,
					'forfeit'   => 2,
					'confirmed' => 1,
					'cancelled' => 1,
				),
				true
			)
		);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function open_statuses(): array {
		return array(
			'angesetzt'   => array( 'scheduled' ),
			'verschoben'  => array( 'postponed' ),
			'unbestätigt' => array( 'provisional' ),
			'strittig'    => array( 'disputed' ),
		);
	}

	#[DataProvider( 'open_statuses' )]
	public function test_one_open_fixture_blocks_closing( string $status ): void {
		$open = LifecycleRules::close_blockers( array( $status => 1 ), true );
		self::assertSame( array( LifecycleRules::OPEN_FIXTURES ), array_column( $open, 'code' ) );
		self::assertFalse( $open[0]['confirmable'] );
	}

	public function test_open_fixtures_are_counted_together(): void {
		$open = LifecycleRules::close_blockers(
			array(
				'scheduled' => 2,
				'disputed'  => 1,
				'final'     => 9,
			),
			true
		);
		self::assertStringContainsString( '3 Begegnungen', $open[0]['message'] );
	}

	public function test_an_open_classification_blocks_closing(): void {
		$open = LifecycleRules::close_blockers( array(), false );
		self::assertSame( array( LifecycleRules::CLASSIFICATION ), array_column( $open, 'code' ) );
	}
}
