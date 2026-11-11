<?php
/**
 * Spielwochen: Vorschlag und Prüfung der Auswahl.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Season\ScheduleWeeks;

final class SeasonScheduleWeeksTest extends TestCase {

	public function test_the_weeks_of_a_season_are_its_mondays(): void {
		// Der 1. August 2027 ist ein Sonntag, der 31. Juli 2028 ein Montag.
		$mondays = ScheduleWeeks::mondays( '2027-08-01', '2028-07-31' );
		self::assertSame( '2027-08-02', $mondays[0] );
		self::assertSame( '2028-07-31', end( $mondays ) );
		self::assertCount( 53, $mondays );
		foreach ( $mondays as $monday ) {
			self::assertSame( '1', ( new \DateTimeImmutable( $monday ) )->format( 'N' ) );
		}
	}

	public function test_a_season_starting_on_a_monday_includes_that_day(): void {
		self::assertSame( array( '2027-08-02', '2027-08-09' ), ScheduleWeeks::mondays( '2027-08-02', '2027-08-15' ) );
	}

	public function test_the_proposal_splits_at_the_turn_of_the_year(): void {
		$proposal = ScheduleWeeks::proposal( '2027-08-01', '2028-07-31' );
		self::assertSame( '2027-08-02', $proposal['first_leg_weeks'][0] );
		self::assertSame( '2027-12-27', end( $proposal['first_leg_weeks'] ), 'Die Woche über den Jahreswechsel zählt zur Vorrunde.' );
		self::assertSame( '2028-01-03', $proposal['second_leg_weeks'][0] );
		self::assertSame( array(), $proposal['blocked'] );
		self::assertCount( 53, array_merge( $proposal['first_leg_weeks'], $proposal['second_leg_weeks'] ) );
	}

	public function test_weeks_of_ranking_tournament_rounds_are_left_out(): void {
		$proposal = ScheduleWeeks::proposal(
			'2027-08-01',
			'2028-07-31',
			array(
				array(
					'from' => '2027-10-11',
					'to'   => '2027-10-15',
				),
				array(
					'from' => '2028-02-06',
					'to'   => '2028-02-07',
				),
			)
		);
		self::assertSame( array( '2027-10-11', '2028-01-31', '2028-02-07' ), $proposal['blocked'] );
		self::assertNotContains( '2027-10-11', $proposal['first_leg_weeks'] );
		self::assertContains( '2027-10-04', $proposal['first_leg_weeks'] );
		self::assertContains( '2027-10-18', $proposal['first_leg_weeks'] );
		self::assertNotContains( '2028-01-31', $proposal['second_leg_weeks'], 'Der Sonntag, 6. Februar, gehört zur Woche vom 31. Januar.' );
		self::assertNotContains( '2028-02-07', $proposal['second_leg_weeks'] );
		self::assertContains( '2028-02-14', $proposal['second_leg_weeks'] );
	}

	public function test_a_round_blocks_exactly_the_weeks_it_touches(): void {
		$sunday = array(
			array(
				'from' => '2027-10-17',
				'to'   => '2027-10-17',
			),
		);
		self::assertTrue( ScheduleWeeks::is_blocked( '2027-10-11', $sunday ) );
		self::assertFalse( ScheduleWeeks::is_blocked( '2027-10-18', $sunday ) );
		self::assertFalse( ScheduleWeeks::is_blocked( '2027-10-04', $sunday ) );
	}

	public function test_a_round_with_only_one_date_blocks_its_week(): void {
		$round = array(
			array(
				'from' => '2027-10-13',
				'to'   => null,
			),
		);
		self::assertTrue( ScheduleWeeks::is_blocked( '2027-10-11', $round ) );
		self::assertFalse(
			ScheduleWeeks::is_blocked(
				'2027-10-11',
				array(
					array(
						'from' => null,
						'to'   => null,
					),
				)
			)
		);
	}

	public function test_a_valid_selection_is_sorted_and_deduplicated(): void {
		$checked = ScheduleWeeks::validate( array( '2027-09-13', '2027-09-06', '2027-09-13' ), array( '2028-01-10' ), '2027-08-01', '2028-07-31' );
		self::assertSame( array(), $checked['errors'] );
		self::assertSame( array( '2027-09-06', '2027-09-13' ), $checked['first_leg_weeks'] );
		self::assertSame( array( '2028-01-10' ), $checked['second_leg_weeks'] );
	}

	public function test_a_week_must_be_a_monday(): void {
		$checked = ScheduleWeeks::validate( array( '2027-09-07' ), array(), '2027-08-01', '2028-07-31' );
		self::assertSame( array( 'first_leg_weeks' ), array_column( $checked['errors'], 'field' ) );
		self::assertSame( array(), $checked['first_leg_weeks'] );
	}

	public function test_a_week_must_lie_in_the_season(): void {
		self::assertSame( array(), ScheduleWeeks::validate( array( '2027-08-02' ), array( '2028-07-31' ), '2027-08-02', '2028-07-31' )['errors'] );
		$before = ScheduleWeeks::validate( array( '2027-07-26' ), array(), '2027-08-01', '2028-07-31' );
		self::assertSame( array( 'first_leg_weeks' ), array_column( $before['errors'], 'field' ) );
		$after = ScheduleWeeks::validate( array(), array( '2028-08-07' ), '2027-08-01', '2028-07-31' );
		self::assertSame( array( 'second_leg_weeks' ), array_column( $after['errors'], 'field' ) );
	}

	public function test_the_second_leg_starts_after_the_first(): void {
		$overlap = ScheduleWeeks::validate( array( '2027-09-06', '2027-09-20' ), array( '2027-09-13', '2027-09-27' ), '2027-08-01', '2028-07-31' );
		self::assertSame( array( 'second_leg_weeks' ), array_column( $overlap['errors'], 'field' ) );
		$adjacent = ScheduleWeeks::validate( array( '2027-09-06' ), array( '2027-09-13' ), '2027-08-01', '2028-07-31' );
		self::assertSame( array(), $adjacent['errors'] );
	}

	public function test_a_week_belongs_to_one_leg_only(): void {
		$checked = ScheduleWeeks::validate( array( '2027-09-06' ), array( '2027-09-06' ), '2027-08-01', '2028-07-31' );
		self::assertSame( array( 'second_leg_weeks' ), array_column( $checked['errors'], 'field' ) );
		self::assertStringContainsString( '2027-09-06', $checked['errors'][0]['message'] );
	}

	public function test_garbage_is_rejected(): void {
		$checked = ScheduleWeeks::validate( array( 'Montag', array( '2027-09-06' ), 7 ), array(), '2027-08-01', '2028-07-31' );
		self::assertCount( 3, $checked['errors'] );
	}

	public function test_stored_weeks_ignore_invalid_entries(): void {
		self::assertSame(
			array(
				'first_leg_weeks'  => array( '2027-09-06', '2027-09-13' ),
				'second_leg_weeks' => array(),
			),
			ScheduleWeeks::stored(
				array(
					'first_leg_weeks'  => array( '2027-09-13', '2027-09-07', 'x', '2027-09-06' ),
					'second_leg_weeks' => 'keine Liste',
				)
			)
		);
	}
}
