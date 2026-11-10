<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Rules\SeasonRules;

final class SeasonRulesTest extends TestCase {

	public function test_defaults_match_region_zuerich_2026(): void {
		$rules = new SeasonRules();
		self::assertSame( 3, $rules->substitute_max );
		self::assertSame( 48, $rules->entry_deadline_hours );
		self::assertSame( 7, $rules->objection_days );
		self::assertSame( 10, $rules->team_match_games );
		self::assertSame( SeasonRules::CUP_FIRST_TO_THREE, $rules->cup_mode );
		self::assertFalse( $rules->set_points_enabled );
		self::assertTrue( $rules->allows_any_team_in_category( 'D' ) );
		self::assertFalse( $rules->allows_any_team_in_category( 'B' ) );
	}

	public function test_reminder_timings_have_defaults_and_can_be_set_per_season(): void {
		$rules = new SeasonRules();
		self::assertSame( 2, $rules->match_reminder_days );
		self::assertSame( 24, $rules->result_reminder_hours );
		self::assertSame( array( 3, 6 ), $rules->confirm_reminder_days );
		self::assertSame( 16, $rules->forfeit_after_days );

		$custom = SeasonRules::from_array(
			array(
				'match_reminder_days'   => '3',
				'confirm_reminder_days' => array( '2', 5 ),
			)
		);
		self::assertSame( 3, $custom->match_reminder_days );
		self::assertSame( array( 2, 5 ), $custom->confirm_reminder_days );
		self::assertSame( 24, $custom->result_reminder_hours );
	}

	public function test_from_array_merges_partial_data(): void {
		$rules = SeasonRules::from_array(
			array(
				'substitute_max' => 2,
				'cup_mode'       => 'all_games',
				'unknown'        => 'x',
			)
		);
		self::assertSame( 2, $rules->substitute_max );
		self::assertSame( SeasonRules::CUP_ALL_GAMES, $rules->cup_mode );
		self::assertSame( 7, $rules->objection_days );
		self::assertArrayNotHasKey( 'unknown', $rules->to_array() );
	}

	public function test_round_trip_through_array(): void {
		$rules = new SeasonRules( rating_k: 32.0 );
		$copy  = SeasonRules::from_array( $rules->to_array() );
		self::assertSame( $rules->to_array(), $copy->to_array() );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function invalid_reminders(): array {
		return array(
			'Spiel steht an negativ'           => array( array( 'match_reminder_days' => -1 ) ),
			'Resultat fehlt zur Anspielzeit'   => array( array( 'result_reminder_hours' => 0 ) ),
			'Resultat fehlt negativ'           => array( array( 'result_reminder_hours' => -5 ) ),
			'Bestätigung am Tag 0'             => array( array( 'confirm_reminder_days' => array( 0, 3 ) ) ),
			'Bestätigung negativ'              => array( array( 'confirm_reminder_days' => array( -1 ) ) ),
			'Bestätigung erst mit der TK'      => array( array( 'confirm_reminder_days' => array( 3, 7 ) ) ),
			'Bestätigung nach Einsprachefrist' => array(
				array(
					'objection_days'        => 4,
					'confirm_reminder_days' => array( 5 ),
				),
			),
			'Standardstufen ausdrücklich'      => array(
				array(
					'objection_days'        => 4,
					'confirm_reminder_days' => array( 3, 6 ),
				),
			),
		);
	}

	/**
	 * @param array<string,mixed> $rules Regeln.
	 */
	#[DataProvider( 'invalid_reminders' )]
	public function test_invalid_reminder_timings_are_rejected( array $rules ): void {
		$this->expectException( \InvalidArgumentException::class );
		SeasonRules::from_array( $rules );
	}

	public function test_boundary_reminder_timings_are_valid(): void {
		$rules = SeasonRules::from_array(
			array(
				'match_reminder_days'   => 0,
				'result_reminder_hours' => 1,
				'confirm_reminder_days' => array( 1, 6 ),
				'objection_days'        => 7,
			)
		);
		self::assertSame( 0, $rules->match_reminder_days, '0 schaltet die Erinnerung vor dem Spiel aus' );
		self::assertSame( array( 1, 6 ), $rules->confirm_reminder_days );
	}

	public function test_default_confirm_reminders_stop_before_a_shorter_objection_period(): void {
		self::assertSame( array( 3 ), SeasonRules::from_array( array( 'objection_days' => 4 ) )->confirm_reminder_days );
		self::assertSame( array(), SeasonRules::from_array( array( 'objection_days' => 0 ) )->confirm_reminder_days );
		self::assertSame( array( 3, 6 ), SeasonRules::from_array( array( 'objection_days' => 10 ) )->confirm_reminder_days );
	}

	public function test_invalid_cup_mode_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new SeasonRules( cup_mode: 'sudden_death' );
	}

	public function test_rl_points_by_rank_and_group_size(): void {
		$rules = new SeasonRules();
		self::assertSame( 6, $rules->rl_points_for_rank( 1, 6 ) );
		self::assertSame( 1, $rules->rl_points_for_rank( 6, 6 ) );
		self::assertSame( 0, $rules->rl_points_for_rank( 7, 6 ) );
		self::assertSame( 7, $rules->rl_points_for_rank( 1, 7 ) );
		self::assertSame( 1, $rules->rl_points_for_rank( 7, 7 ) );
	}

	public function test_season_operation_parameters_have_defaults_and_can_be_set(): void {
		$rules = new SeasonRules();
		self::assertSame( 2, $rules->schedule_tables_per_match );
		self::assertSame( 10, $rules->classification_min_games );
		self::assertSame( 6, $rules->rl_group_size );
		self::assertSame( 7, $rules->rl_group_size_max );

		$custom = SeasonRules::from_array(
			array(
				'schedule_tables_per_match' => 1,
				'classification_min_games'  => 0,
				'rl_group_size'             => 5,
				'rl_group_size_max'         => 5,
			)
		);
		self::assertSame( 1, $custom->schedule_tables_per_match );
		self::assertSame( 0, $custom->classification_min_games );
		self::assertSame( 5, $custom->rl_group_size );
		self::assertSame( 5, $custom->rl_group_size_max );
		self::assertEquals( $custom, SeasonRules::from_array( $custom->to_array() ) );
	}

	/**
	 * @return array<string,array{array<string,int>}>
	 */
	public static function invalid_operation_parameters(): array {
		return array(
			'keine Tische'              => array( array( 'schedule_tables_per_match' => 0 ) ),
			'negative Mindestspiele'    => array( array( 'classification_min_games' => -1 ) ),
			'Gruppe zu klein'           => array( array( 'rl_group_size' => 2 ) ),
			'Ausnahme unter der Grösse' => array(
				array(
					'rl_group_size'     => 6,
					'rl_group_size_max' => 5,
				),
			),
		);
	}

	/**
	 * @param array<string,int> $rules Regeln.
	 */
	#[DataProvider( 'invalid_operation_parameters' )]
	public function test_invalid_operation_parameters_are_rejected( array $rules ): void {
		$this->expectException( \InvalidArgumentException::class );
		SeasonRules::from_array( $rules );
	}

	public function test_bonus_point_follows_the_usual_group_size(): void {
		$rules = SeasonRules::from_array(
			array(
				'rl_group_size'     => 5,
				'rl_group_size_max' => 6,
			)
		);
		self::assertSame( 6, $rules->rl_points_for_rank( 1, 5 ), 'übliche Grösse ohne Zusatzpunkt' );
		self::assertSame( 7, $rules->rl_points_for_rank( 1, 6 ), 'grössere Gruppe mit Zusatzpunkt' );
	}
}
