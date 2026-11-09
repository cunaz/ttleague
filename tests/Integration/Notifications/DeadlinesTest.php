<?php
/**
 * Fristen an ihren Grenzen mit fester Uhr.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Notifications;

use TTLeague\Accounts\Preferences;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Notifications\Deadlines;
use TTLeague\Notifications\FixtureQueries;
use TTLeague\Notifications\NotificationLog;
use TTLeague\Notifications\Notifier;

/**
 * Begegnung f1 am Dienstag, 10.11.2026, 20:00 Ortszeit (19:00 UTC), Cup am 12.11.2026, 19:30,
 * Clubduell am 17.11.2026, 20:00. Ohne Angabe gilt das Versandfenster 0 bis 24 Uhr.
 */
final class DeadlinesTest extends NotificationTestCase {

	private const ALL_DAY = array(
		'from_hour'   => 0,
		'until_hour'  => 24,
		'digest_hour' => 7,
	);

	/**
	 * Lauf zu einer Ortszeit (Europe/Zurich).
	 *
	 * @param array<string,int> $options Versandfenster.
	 * @return list<array<string,mixed>>
	 */
	private function run_at( string $local, array $options = self::ALL_DAY ): array {
		$utc       = ( new \DateTimeImmutable( $local, new \DateTimeZone( 'Europe/Zurich' ) ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		$clock     = new FixedClock( $utc->format( 'Y-m-d H:i:s' ) );
		$log       = new NotificationLog( $this->db, $clock );
		$deadlines = new Deadlines(
			$clock,
			new \DateTimeZone( 'Europe/Zurich' ),
			new FixtureQueries( $this->db ),
			$this->repo,
			$this->recipients,
			new Notifier( new Preferences( $this->db ), $log, array( $this->push, $this->email ) ),
			$this->messages,
			$log,
			$options
		);
		return $deadlines->run();
	}

	/**
	 * Empfänger einer Art zu einer Begegnung bzw. einem Bezug.
	 *
	 * @return list<string>
	 */
	private function to( string $event, ?int $ref = null ): array {
		$out = array();
		foreach ( $this->email->sent as $sent ) {
			if ( $sent['event'] === $event && ( null === $ref || $sent['ref_id'] === $ref ) ) {
				$out[] = $sent['to'];
			}
		}
		return $out;
	}

	/**
	 * @return list<string>
	 */
	private function both_sides(): array {
		return array( $this->p( 'captain_h1' ), $this->p( 'chef_home' ), $this->p( 'captain_a1' ), $this->p( 'chef_away' ) );
	}

	/**
	 * @return list<string>
	 */
	private function tk(): array {
		return array( $this->p( 'tk' ), 'u108', $this->p( 'admin' ) );
	}

	private function enter_f1_at( string $utc ): int {
		$this->clock = new FixedClock( $utc );
		$this->service()->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );
		return (int) $this->db->get_var( "SELECT MAX(id) FROM {$this->db->table( 'fixture_event' )} WHERE fixture_id = %d", array( $this->id['f1'] ) );
	}

	public function test_match_reminder_two_days_before_kickoff_in_local_time(): void {
		$this->run_at( '2026-11-08 19:59:59' );
		self::assertSame( array(), $this->to( 'match_reminder', $this->id['f1'] ) );

		$this->run_at( '2026-11-08 20:00:00' );
		self::assertSame( $this->both_sides(), $this->to( 'match_reminder', $this->id['f1'] ) );
		$sent = $this->email->sent[0];
		self::assertSame( '2026-11-10 20:00', $sent['ref_key'] );
		self::assertStringContainsString( 'Spiellokal: Turnhalle Seefeld, Musterstrasse 1, 8000 Musterstadt', $sent['notification']->paragraphs[1] );

		$this->run_at( '2026-11-09 12:00:00' );
		$this->run_at( '2026-11-10 19:59:00' );
		self::assertCount( 4, $this->to( 'match_reminder', $this->id['f1'] ), 'nur einmal' );
		self::assertSame( array(), $this->to( 'match_reminder', $this->id['derby'] ) );
	}

	public function test_clock_is_utc_and_schedule_is_local_time(): void {
		$clock = new FixedClock( '2026-11-08 18:59:59' );
		$log   = new NotificationLog( $this->db, $clock );
		$run   = fn() => ( new Deadlines( $clock, new \DateTimeZone( 'Europe/Zurich' ), new FixtureQueries( $this->db ), $this->repo, $this->recipients, new Notifier( new Preferences( $this->db ), $log, array( $this->email ) ), $this->messages, $log, self::ALL_DAY ) )->run();
		$run();
		self::assertSame( array(), $this->to( 'match_reminder', $this->id['f1'] ) );
		$clock->advance( '+1 second' );
		$run();
		self::assertCount( 4, $this->to( 'match_reminder', $this->id['f1'] ), '19:00 UTC sind 20:00 in Zürich' );
	}

	public function test_postponed_fixture_is_reminded_again_for_its_new_date(): void {
		$this->run_at( '2026-11-08 20:00:00' );
		$this->db->update(
			'fixture',
			array(
				'status'       => 'postponed',
				'scheduled_at' => '2026-11-24 20:00:00',
			),
			array( 'id' => $this->id['f1'] )
		);
		$this->run_at( '2026-11-22 19:59:00' );
		self::assertCount( 4, $this->to( 'match_reminder', $this->id['f1'] ) );
		$this->run_at( '2026-11-22 20:00:00' );
		self::assertCount( 8, $this->to( 'match_reminder', $this->id['f1'] ) );
		self::assertSame( '2026-11-24 20:00', $this->email->sent[ count( $this->email->sent ) - 1 ]['ref_key'] );
	}

	public function test_nothing_is_sent_at_night_and_the_morning_run_catches_up(): void {
		$this->run_at( '2026-11-08 22:00:00', array() );
		$this->run_at( '2026-11-09 06:59:00', array() );
		self::assertSame( array(), $this->email->sent );

		$this->run_at( '2026-11-09 07:00:00', array() );
		self::assertSame( $this->both_sides(), $this->to( 'match_reminder', $this->id['f1'] ) );
	}

	public function test_missing_result_reminds_home_side_after_24_hours_and_tk_after_48_hours(): void {
		$this->run_at( '2026-11-11 19:59:00' );
		self::assertSame( array(), $this->to( 'result_missing', $this->id['f1'] ) );

		$this->run_at( '2026-11-11 20:00:00' );
		self::assertSame( array( $this->p( 'captain_h1' ), $this->p( 'chef_home' ) ), $this->to( 'result_missing', $this->id['f1'] ) );
		$sent = $this->email->sent[ count( $this->email->sent ) - 1 ];
		self::assertSame( 'Bitte erfasse es bis Do, 12.11.2026, 20:00 (48 Stunden nach Anspielzeit).', $sent['notification']->paragraphs[1] );
		self::assertSame( array(), $this->to( 'result_missing_tk' ) );

		$this->run_at( '2026-11-12 19:59:00' );
		self::assertSame( array(), $this->to( 'result_missing_tk' ) );
		$this->run_at( '2026-11-12 20:00:00' );
		self::assertSame( $this->tk(), $this->to( 'result_missing_tk', $this->id['f1'] ) );
		self::assertCount( 2, $this->to( 'result_missing', $this->id['f1'] ), 'Heimseite nur einmal' );
	}

	public function test_forfeit_proposal_after_16_days_without_result(): void {
		$this->run_at( '2026-11-26 19:59:00' );
		self::assertSame( array(), $this->to( 'forfeit_proposal' ) );
		self::assertSame( $this->tk(), $this->to( 'result_missing_tk', $this->id['f1'] ) );

		$this->run_at( '2026-11-26 20:00:00' );
		self::assertSame( $this->tk(), $this->to( 'forfeit_proposal', $this->id['f1'] ) );
	}

	public function test_first_run_after_16_days_proposes_forfeit_without_reminding_the_home_side(): void {
		$this->run_at( '2026-11-27 09:00:00' );
		self::assertSame( $this->tk(), $this->to( 'forfeit_proposal', $this->id['f1'] ) );
		self::assertSame( array(), $this->to( 'result_missing', $this->id['f1'] ) );
		self::assertSame( array(), $this->to( 'result_missing_tk', $this->id['f1'] ) );
	}

	public function test_entered_result_stops_the_missing_result_reminders(): void {
		$this->enter_f1_at( '2026-11-10 21:30:00' );
		$this->run_at( '2026-11-12 20:00:00' );
		self::assertSame( array(), $this->to( 'result_missing' ) );
		self::assertSame( array(), $this->to( 'result_missing_tk' ) );
	}

	public function test_confirmation_reminders_after_3_and_6_days_then_the_tk_after_7(): void {
		$event = $this->enter_f1_at( '2026-11-10 09:00:00' );
		$away  = array( $this->p( 'captain_a1' ), $this->p( 'chef_away' ) );

		$this->run_at( '2026-11-13 09:59:59' );
		self::assertSame( array(), $this->to( 'confirm_reminder' ) );
		$this->run_at( '2026-11-13 10:00:00' );
		self::assertSame( $away, $this->to( 'confirm_reminder', $event ) );
		self::assertSame( '3', $this->email->sent[ count( $this->email->sent ) - 1 ]['ref_key'] );

		$this->run_at( '2026-11-16 09:59:00' );
		self::assertCount( 2, $this->to( 'confirm_reminder' ) );
		$this->run_at( '2026-11-16 10:00:00' );
		self::assertSame( array_merge( $away, $away ), $this->to( 'confirm_reminder', $event ) );
		self::assertSame( '6', $this->email->sent[ count( $this->email->sent ) - 1 ]['ref_key'] );

		$this->run_at( '2026-11-17 09:59:00' );
		self::assertSame( array(), $this->to( 'confirm_overdue' ) );
		$this->run_at( '2026-11-17 10:00:00' );
		self::assertSame( $this->tk(), $this->to( 'confirm_overdue', $event ) );
		$this->run_at( '2026-11-18 10:00:00' );
		self::assertCount( 3, $this->to( 'confirm_overdue' ) );
		self::assertCount( 4, $this->to( 'confirm_reminder' ) );
	}

	public function test_late_first_run_sends_only_the_highest_due_step(): void {
		$event = $this->enter_f1_at( '2026-11-10 09:00:00' );
		$this->run_at( '2026-11-16 12:00:00' );
		self::assertSame( array( $this->p( 'captain_a1' ), $this->p( 'chef_away' ) ), $this->to( 'confirm_reminder', $event ) );
		$steps = array_column( array_filter( $this->email->sent, static fn( array $s ): bool => 'confirm_reminder' === $s['event'] ), 'ref_key' );
		self::assertSame( array( '6', '6' ), array_values( $steps ) );
	}

	public function test_a_changed_result_restarts_the_confirmation_reminders(): void {
		$first = $this->enter_f1_at( '2026-11-10 09:00:00' );
		$this->run_at( '2026-11-13 10:00:00' );
		$this->clock = new FixedClock( '2026-11-13 12:00:00' );
		$this->service()->submit( $this->id['f1'], $this->seven_three(), $this->actor['captain_h1'] );
		$second = (int) $this->db->get_var( "SELECT MAX(id) FROM {$this->db->table( 'fixture_event' )} WHERE type = 'edited'" );

		$this->run_at( '2026-11-16 12:59:00' );
		self::assertSame( array(), $this->to( 'confirm_reminder', $second ), 'die Frist läuft ab der Änderung' );
		$this->run_at( '2026-11-16 13:00:00' );
		self::assertSame( array( $this->p( 'captain_a1' ), $this->p( 'chef_away' ) ), $this->to( 'confirm_reminder', $second ) );
		self::assertCount( 2, $this->to( 'confirm_reminder', $first ) );
	}

	public function test_confirmed_result_ends_the_reminders(): void {
		$this->enter_f1_at( '2026-11-10 09:00:00' );
		$this->service()->confirm( $this->id['f1'], $this->actor['captain_a1'] );
		$this->run_at( '2026-11-20 10:00:00' );
		self::assertSame( array(), $this->to( 'confirm_reminder' ) );
		self::assertSame( array(), $this->to( 'confirm_overdue' ) );
	}

	public function test_values_come_from_the_season_rules(): void {
		$this->seed(
			array(
				'match_reminder_days'   => 3,
				'result_reminder_hours' => 12,
				'entry_deadline_hours'  => 36,
				'confirm_reminder_days' => array( 2 ),
				'objection_days'        => 4,
				'forfeit_after_days'    => 10,
			)
		);
		$this->run_at( '2026-11-07 19:59:00' );
		self::assertSame( array(), $this->to( 'match_reminder', $this->id['f1'] ) );
		$this->run_at( '2026-11-07 20:00:00' );
		self::assertCount( 4, $this->to( 'match_reminder', $this->id['f1'] ) );

		$this->run_at( '2026-11-11 08:00:00' );
		self::assertCount( 2, $this->to( 'result_missing', $this->id['f1'] ) );
		self::assertSame( array(), $this->to( 'result_missing_tk' ) );
		$this->run_at( '2026-11-12 08:00:00' );
		self::assertSame( $this->tk(), $this->to( 'result_missing_tk', $this->id['f1'] ) );
		$this->run_at( '2026-11-20 20:00:00' );
		self::assertSame( $this->tk(), $this->to( 'forfeit_proposal', $this->id['f1'] ) );

		$this->clock = new FixedClock( '2026-11-14 09:00:00' );
		$this->service()->submit( $this->id['cup'], $this->cup_payload(), new \TTLeague\Results\Actor( 120, $this->person['cup_h1'], false ) );
		$this->run_at( '2026-11-16 10:00:00' );
		self::assertCount( 3, $this->to( 'confirm_reminder' ), 'Cup-Spieler und TK-Chefin nach 2 Tagen' );
		$this->run_at( '2026-11-18 10:00:00' );
		self::assertSame( $this->tk(), $this->to( 'confirm_overdue' ) );
	}

	public function test_daily_tk_digest_only_with_open_points(): void {
		$this->db->update( 'fixture', array( 'status' => 'final' ), array( 'id' => $this->id['f1'] ) );
		$this->db->update( 'fixture', array( 'status' => 'final' ), array( 'id' => $this->id['derby'] ) );
		$this->db->update( 'fixture', array( 'status' => 'cancelled' ), array( 'id' => $this->id['cup'] ) );
		$this->run_at( '2026-11-20 08:00:00' );
		self::assertSame( array(), $this->to( 'tk_digest' ), 'nichts offen' );

		$this->db->update( 'fixture', array( 'status' => 'scheduled' ), array( 'id' => $this->id['f1'] ) );
		$this->db->update(
			'fixture',
			array(
				'status'     => 'disputed',
				'home_score' => 6,
				'away_score' => 4,
			),
			array( 'id' => $this->id['derby'] )
		);
		$this->run_at( '2026-11-20 06:59:00', array( 'digest_hour' => 7 ) + self::ALL_DAY );
		self::assertSame( array(), $this->to( 'tk_digest' ), 'erst ab 7 Uhr' );

		$this->run_at( '2026-11-20 07:00:00' );
		self::assertSame( $this->tk(), $this->to( 'tk_digest', 20261120 ) );
		$digest = $this->email->sent[ count( $this->email->sent ) - 1 ]['notification'];
		self::assertSame( 'TK: 2 offene Punkte am Fr, 20.11.2026', $digest->subject );
		self::assertSame( array( 'Resultat fehlt seit mehr als 48 Stunden (1)', 'Einsprachen (1)' ), array_column( $digest->lists, 'heading' ) );

		$this->run_at( '2026-11-20 18:00:00' );
		self::assertCount( 3, $this->to( 'tk_digest' ), 'einmal pro Tag' );
		$this->run_at( '2026-11-21 07:30:00' );
		self::assertSame( $this->tk(), $this->to( 'tk_digest', 20261121 ) );
	}

	public function test_old_log_entries_are_purged_on_each_run(): void {
		$this->run_at( '2026-11-08 20:00:00' );
		self::assertSame( '4', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'notification_log' )}" ) );
		$this->db->update( 'fixture', array( 'status' => 'cancelled' ), array( 'id' => $this->id['f1'] ) );
		$this->db->update( 'fixture', array( 'status' => 'cancelled' ), array( 'id' => $this->id['cup'] ) );
		$this->db->update( 'fixture', array( 'status' => 'cancelled' ), array( 'id' => $this->id['derby'] ) );
		$this->run_at( '2027-11-10 12:00:00' );
		self::assertSame( '0', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'notification_log' )}" ) );
	}

	/**
	 * Cup Seefeld 1 gegen Wollishofen 1, Heim gewinnt 3:0.
	 *
	 * @return array<string,mixed>
	 */
	private function cup_payload(): array {
		$games = array();
		foreach ( \TTLeague\Domain\Match\GameOrder::cup() as $i => $slot ) {
			if ( $i >= 3 ) {
				$games[] = array(
					'sequence' => $slot['sequence'],
					'status'   => 'not_played',
				);
				continue;
			}
			$game = array(
				'sequence'  => $slot['sequence'],
				'status'    => 'played',
				'sets_home' => 3,
				'sets_away' => 1,
			);
			if ( \TTLeague\Domain\Match\GameOrder::DOUBLE === $slot['type'] ) {
				$game['home_players'] = array( $this->id['h1'], $this->id['h2'] );
				$game['away_players'] = array( $this->id['a1'], $this->id['a2'] );
			}
			$games[] = $game;
		}
		return array(
			'lineup' => array(
				'home' => array(
					'A' => $this->id['h1'],
					'B' => $this->id['h2'],
				),
				'away' => array(
					'Y' => $this->id['a1'],
					'Z' => $this->id['a2'],
				),
			),
			'games'  => $games,
		);
	}
}
