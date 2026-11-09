<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

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
}
