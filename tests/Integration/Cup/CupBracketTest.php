<?php
/**
 * Tableau gegen MariaDB: nachrücken nach Sieg und Forfait, Freilos, Rückzug, Korrektur, Fristen,
 * Halbfinal und Final.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Cup;

use TTLeague\Access\AccessPolicy;
use TTLeague\Cup\CupEvents;

/**
 * Acht Mannschaften (CupTestCase::eight()): Seefeld – Wollishofen (1/1), Oerlikon – Altstetten
 * (1/2), Bergwiese – Lindenhof (1/3), Wiedikon – Enge (1/4), Frist der ersten Runde 30.11.2026.
 * Die Uhr steht auf Montag, 2.11.2026, 09:00 Ortszeit.
 */
final class CupBracketTest extends CupTestCase {

	public function test_the_winner_moves_to_its_place_once_the_result_is_confirmed(): void {
		$ids     = $this->eight();
		$fixture = $this->fixture_of( 1, 1 );

		$this->results->submit( $fixture, $this->payload( $fixture, 'away' ), $this->tk );
		self::assertNull( $this->tie( 2, 1 )['home'], 'provisorisch rückt niemand nach' );

		$this->results->decide( $fixture, $this->tk, array( 'action' => 'confirm' ) );

		$next = $this->tie( 2, 1 );
		self::assertSame( array( $ids['wollishofen'], null, null ), array( $next['home'], $next['away'], $next['fixture'] ), 'Sieger der oberen Paarung steht oben; die Begegnung wartet auf den Gegner' );
		$audit = $this->audit( 'cup_tie', $next['id'] );
		self::assertSame( array( 'advanced', array( 1, 1 ), 'result' ), array( $audit[0]['action'], $audit[0]['after']['from'], $audit[0]['after']['by'] ) );

		// Sieger der unteren Paarung: Altstetten gewinnt auswärts in Oerlikon.
		$count = count( $this->events->calls );
		$this->play( $this->fixture_of( 1, 2 ), 'away' );

		$next = $this->tie( 2, 1 );
		self::assertSame( array( $ids['wollishofen'], $ids['altstetten'] ), array( $next['home'], $next['away'] ) );
		self::assertNotNull( $next['fixture'], 'beide Plätze besetzt: die Begegnung entsteht' );
		$row = $this->fixture_row( $next['fixture']['id'] );
		self::assertSame(
			array( 'scheduled', null, '2', '201', (string) $ids['wollishofen'], (string) $ids['altstetten'] ),
			array( $row['status'], $row['scheduled_at'], $row['round'], $row['match_no'], $row['home_entry_id'], $row['away_entry_id'] ),
			'ohne Termin, der obere Platz hat Heimrecht'
		);
		self::assertNull( $next['deadline'], 'die Frist der Runde setzt die TK' );
		self::assertSame( array( $this->cup_a, CupEvents::ADVANCED, $this->tk->user_id, array( $next['id'] ) ), $this->events->calls[ $count ], 'die neue Paarung wird gemeldet' );

		// Ein weiterer Abgleich ändert nichts.
		$report = $this->bracket->sync( $this->cup_a );
		self::assertSame( array( array(), false, array() ), array( $report['created'], $report['changed'], $report['blocked'] ) );
		self::assertSame( 5, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'fixture' )} WHERE competition_id = %d", array( $this->cup_a ) ) );
	}

	public function test_a_forfeit_moves_the_winner_on(): void {
		$ids     = $this->eight();
		$fixture = $this->fixture_of( 1, 3 );

		$this->results->decide(
			$fixture,
			$this->tk,
			array(
				'action'  => 'forfeit',
				'winner'  => 'home',
				'comment' => 'Lindenhof ist nicht angetreten.',
			)
		);

		self::assertSame( $ids['bergwiese'], $this->tie( 2, 2 )['home'] );
	}

	public function test_a_side_confirms_and_the_cup_goes_on_to_the_final(): void {
		$ids = $this->eight();

		// Die Gastseite bestätigt selbst (nicht die TK).
		$fixture = $this->fixture_of( 1, 1 );
		$this->results->submit( $fixture, $this->payload( $fixture, 'home' ), $this->player_actor( 'seefeld', 1 ) );
		$this->results->confirm( $fixture, $this->player_actor( 'wollishofen', 1 ) );
		self::assertSame( $ids['seefeld'], $this->tie( 2, 1 )['home'] );

		$this->play( $this->fixture_of( 1, 2 ), 'home' );
		$this->play( $this->fixture_of( 1, 3 ), 'home' );
		$this->play( $this->fixture_of( 1, 4 ), 'away' );
		$this->play( $this->fixture_of( 2, 1 ), 'away' );
		$this->play( $this->fixture_of( 2, 2 ), 'home' );

		$final = $this->tie( 3, 1 );
		self::assertSame( array( $ids['oerlikon'], $ids['bergwiese'] ), array( $final['home'], $final['away'] ) );
		$this->play( $final['fixture']['id'], 'home' );
		self::assertSame( 7, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'fixture' )} WHERE competition_id = %d AND status = 'confirmed'", array( $this->cup_a ) ) );
		self::assertSame( array(), $this->bracket->conflicts( $this->cup_a ) );
	}

	public function test_a_correction_with_another_winner_moves_the_other_team_while_the_next_fixture_is_open(): void {
		$ids = $this->eight();
		$f11 = $this->fixture_of( 1, 1 );
		$this->play( $f11, 'home' );
		$this->play( $this->fixture_of( 1, 2 ), 'home' );
		$old = $this->fixture_of( 2, 1 );
		self::assertSame( array( $ids['seefeld'], $ids['oerlikon'] ), array( $this->tie( 2, 1 )['home'], $this->tie( 2, 1 )['away'] ) );

		// Die TK korrigiert: Wollishofen hat gewonnen.
		$this->results->decide(
			$f11,
			$this->tk,
			array(
				'action' => 'correct',
				'result' => $this->payload( $f11, 'away' ),
			)
		);

		$next = $this->tie( 2, 1 );
		self::assertSame( array( $ids['wollishofen'], $ids['oerlikon'] ), array( $next['home'], $next['away'] ) );
		self::assertNotSame( $old, $next['fixture']['id'], 'neue Begegnung mit der richtigen Mannschaft' );
		self::assertSame( 'cancelled', $this->fixture_row( $old )['status'], 'die bisherige ist annulliert' );
		$row = $this->fixture_row( $next['fixture']['id'] );
		self::assertSame( array( 'scheduled', (string) $ids['wollishofen'], (string) $ids['oerlikon'] ), array( $row['status'], $row['home_entry_id'], $row['away_entry_id'] ) );
		self::assertSame( array(), $this->bracket->conflicts( $this->cup_a ) );
		self::assertSame( 'cup_cancelled', $this->audit( 'fixture', $old )[0]['action'] );
	}

	public function test_a_correction_does_not_touch_a_next_fixture_with_a_result(): void {
		$ids = $this->eight();
		$f11 = $this->fixture_of( 1, 1 );
		$this->play( $f11, 'home' );
		$this->play( $this->fixture_of( 1, 2 ), 'home' );
		$semi = $this->fixture_of( 2, 1 );
		// Der Halbfinal ist schon erfasst (provisorisch genügt).
		$this->results->submit( $semi, $this->payload( $semi, 'home' ), $this->tk );

		$this->results->decide(
			$f11,
			$this->tk,
			array(
				'action' => 'correct',
				'result' => $this->payload( $f11, 'away' ),
			)
		);

		$next = $this->tie( 2, 1 );
		self::assertSame( array( $ids['seefeld'], $semi ), array( $next['home'], $next['fixture']['id'] ), 'das Tableau bleibt stehen' );
		self::assertSame( 'provisional', $this->fixture_row( $semi )['status'] );
		self::assertSame(
			array(
				array(
					'tie'      => $next['id'],
					'round'    => 2,
					'slot'     => 1,
					'fixture'  => $semi,
					'current'  => 'Seefeld 1',
					'expected' => 'Wollishofen 1',
				),
			),
			$this->bracket->conflicts( $this->cup_a ),
			'Hinweis an die TK'
		);

		// Setzt die TK den Halbfinal zurück, rückt das Tableau beim nächsten Abgleich nach.
		$this->results->decide( $semi, $this->tk, array( 'action' => 'reset' ) );
		self::assertSame( $ids['wollishofen'], $this->tie( 2, 1 )['home'] );
		self::assertSame( array(), $this->bracket->conflicts( $this->cup_a ) );
	}

	public function test_resetting_a_result_takes_the_winner_back(): void {
		$ids = $this->eight();
		$f11 = $this->fixture_of( 1, 1 );
		$this->play( $f11, 'home' );
		$this->play( $this->fixture_of( 1, 2 ), 'home' );
		$semi = $this->fixture_of( 2, 1 );

		$this->results->decide( $f11, $this->tk, array( 'action' => 'reset' ) );

		$next = $this->tie( 2, 1 );
		self::assertSame( array( null, $ids['oerlikon'], null ), array( $next['home'], $next['away'], $next['fixture'] ) );
		self::assertSame( 'cancelled', $this->fixture_row( $semi )['status'] );
	}

	public function test_a_withdrawn_team_waiting_in_the_next_round_gives_a_walkover(): void {
		$ids = $this->eight();
		$this->play( $this->fixture_of( 1, 1 ), 'home' );

		// Seefeld steht im Halbfinal und zieht zurück, bevor der Gegner feststeht.
		$this->entries->withdraw( $ids['seefeld'], $this->tk );
		self::assertSame( array( $ids['seefeld'], null ), array( $this->tie( 2, 1 )['home'], $this->tie( 2, 1 )['away'] ) );
		self::assertNull( $this->tie( 3, 1 )['home'], 'der Gegner steht noch nicht fest' );

		$this->play( $this->fixture_of( 1, 2 ), 'away' );

		$semi = $this->tie( 2, 1 );
		self::assertSame( $ids['altstetten'], $semi['away'] );
		self::assertNull( $semi['fixture'], 'keine Begegnung gegen eine zurückgezogene Mannschaft' );
		self::assertSame( $ids['altstetten'], $this->tie( 3, 1 )['home'], 'Altstetten steht kampflos im Final' );
		$audit = $this->audit( 'cup_tie', $this->tie( 3, 1 )['id'] );
		self::assertSame( 'walkover', $audit[0]['after']['by'] );
	}

	public function test_nobody_moves_on_when_both_teams_of_a_tie_withdraw(): void {
		$ids = $this->eight();

		$this->entries->withdraw( $ids['seefeld'], $this->tk );
		$this->entries->withdraw( $ids['wollishofen'], $this->tk );

		// Die erste Begegnung war nach dem ersten Rückzug schon forfait für Wollishofen gewertet.
		self::assertSame( $ids['wollishofen'], $this->tie( 2, 1 )['home'] );
		$this->play( $this->fixture_of( 1, 2 ), 'home' );
		self::assertNull( $this->tie( 2, 1 )['fixture'] );
		self::assertSame( $ids['oerlikon'], $this->tie( 3, 1 )['home'], 'Oerlikon kommt kampflos weiter' );
	}

	public function test_a_withdrawal_without_the_result_service_cancels_the_open_fixture(): void {
		$ids = $this->eight();
		// Tableau ohne Anbindung an die Erfassung (die Begegnung lässt sich nicht forfait werten).
		$bracket = new \TTLeague\Cup\Bracket( $this->db, $this->clock, new \DateTimeZone( self::ZONE ), new \TTLeague\Infrastructure\AuditLog( $this->db ), $this->events );
		$fixture = $this->fixture_of( 1, 4 );
		$this->db->update( 'cup_entry', array( 'status' => 'withdrawn' ), array( 'id' => $ids['enge'] ) );

		$bracket->entry_withdrawn( $ids['enge'], $this->tk );

		self::assertSame( 'cancelled', $this->fixture_row( $fixture )['status'] );
		self::assertNull( $this->tie( 1, 4 )['fixture'] );
		self::assertSame( $ids['wiedikon'], $this->tie( 2, 2 )['away'] );
	}

	public function test_the_tk_sets_and_extends_deadlines(): void {
		$this->eight();
		$this->play( $this->fixture_of( 1, 1 ), 'home' );
		$this->play( $this->fixture_of( 1, 2 ), 'home' );

		self::expect_error( fn() => $this->bracket->set_round_deadline( $this->cup_a, 2, '2026-12-20', $this->chef['seefeld'] ), 403 );
		$past = self::expect_error( fn() => $this->bracket->set_round_deadline( $this->cup_a, 2, '2026-11-01', $this->tk ), 422 );
		self::assertSame( array( 'deadline' ), array_column( $past->errors, 'field' ) );
		self::expect_error( fn() => $this->bracket->set_round_deadline( $this->cup_a, 2, 'bald', $this->tk ), 422 );
		self::expect_error( fn() => $this->bracket->set_round_deadline( $this->cup_a, 4, '2026-12-20', $this->tk ), 404 );

		$result = $this->bracket->set_round_deadline( $this->cup_a, 2, '2026-12-20', $this->tk );
		self::assertSame(
			array(
				'round'    => 2,
				'deadline' => '2026-12-20',
				'ties'     => 2,
			),
			$result
		);
		self::assertSame( array( '2026-12-20', '2026-12-20', '2026-11-30' ), array( $this->tie( 2, 1 )['deadline'], $this->tie( 2, 2 )['deadline'], $this->tie( 1, 3 )['deadline'] ) );
		self::assertSame( 'cup_round_deadline', $this->audit( 'competition', $this->cup_a )[3]['action'] );

		// Eine einzelne Paarung verlängern; heute ist als Frist erlaubt.
		$tie = $this->tie( 1, 3 );
		self::assertSame( '2026-11-02', $this->bracket->set_tie_deadline( $tie['id'], '2026-11-02', $this->tk )['deadline'] );
		$this->bracket->set_tie_deadline( $tie['id'], '2026-12-07', $this->tk );
		self::assertSame( array( '2026-12-07', '2026-11-30' ), array( $this->tie( 1, 3 )['deadline'], $this->tie( 1, 4 )['deadline'] ) );
		$audit = $this->audit( 'cup_tie', $tie['id'] );
		self::assertSame( array( 'deadline', array( 'deadline' => '2026-11-02' ), array( 'deadline' => '2026-12-07' ) ), array( $audit[2]['action'], $audit[2]['before'], $audit[2]['after'] ) );
		self::expect_error( fn() => $this->bracket->set_tie_deadline( $tie['id'], '2026-12-07', $this->chef['bergwiese'] ), 403 );
		self::expect_error( fn() => $this->bracket->set_tie_deadline( 99999, '2026-12-07', $this->tk ), 404 );
	}

	public function test_fixtures_without_result_are_overdue_the_day_after_the_deadline(): void {
		$this->eight();
		$this->play( $this->fixture_of( 1, 1 ), 'home' );
		$this->bracket->set_tie_deadline( $this->tie( 1, 3 )['id'], '2026-12-05', $this->tk );

		// Am Tag der Frist (30.11.2026, 23:30 Ortszeit) ist noch nichts abgelaufen.
		$this->clock = new \TTLeague\Infrastructure\Time\FixedClock( '2026-11-30 22:30:00' );
		$this->build_services();
		self::assertSame( array(), $this->bracket->overdue() );

		// Eine Stunde später, am 1.12.: die Begegnungen ohne Resultat mit Frist 30.11.
		$this->clock->advance( '+1 hour' );
		$overdue = $this->bracket->overdue();
		self::assertSame(
			array( 'Oerlikon 1 – Altstetten 1', 'Wiedikon 1 – Enge 1' ),
			array_map( static fn( array $row ): string => $row['home'] . ' – ' . $row['away'], $overdue ),
			'ohne die gespielte Begegnung und ohne die verlängerte'
		);
		self::assertSame(
			array( 'Mannschaftscup A', 1, 3, '2026-11-30', $this->fixture_of( 1, 2 ), null ),
			array( $overdue[0]['competition'], $overdue[0]['round'], $overdue[0]['rounds'], $overdue[0]['deadline'], $overdue[0]['fixture'], $overdue[0]['scheduled_at'] )
		);
		self::assertTrue( $this->bracket->tableau( $this->cup_a )['ties'][1][1]['overdue'] );
		self::assertFalse( $this->bracket->tableau( $this->cup_a )['ties'][1][2]['overdue'] );

		// Ein erfasstes Resultat (auch provisorisch) nimmt die Begegnung von der Liste.
		$fixture = $this->fixture_of( 1, 2 );
		$this->results->submit( $fixture, $this->payload( $fixture, 'home' ), $this->tk );
		self::assertSame( array( 'Wiedikon 1 – Enge 1' ), array_map( static fn( array $row ): string => $row['home'] . ' – ' . $row['away'], $this->bracket->overdue() ) );
	}

	public function test_the_tk_schedules_semi_final_and_final_with_place_and_date(): void {
		$this->eight();
		$this->play( $this->fixture_of( 1, 1 ), 'home' );
		$this->play( $this->fixture_of( 1, 2 ), 'home' );
		$semi  = $this->tie( 2, 1 );
		$venue = $this->seeder->venue( $this->club['affoltern'], 'Sporthalle Affoltern' );

		$quarter = self::expect_error( fn() => $this->bracket->schedule( $this->tie( 1, 3 )['id'], $this->tk, array( 'scheduled_at' => '2026-12-12 14:00' ) ), 409 );
		self::assertStringContainsString( 'Nur Halbfinal und Final', $quarter->getMessage() );
		self::expect_error( fn() => $this->bracket->schedule( $semi['id'], $this->chef['seefeld'], array( 'scheduled_at' => '2026-12-12 14:00' ) ), 403 );
		self::expect_error( fn() => $this->bracket->schedule( $this->tie( 3, 1 )['id'], $this->tk, array( 'scheduled_at' => '2026-12-12 14:00' ) ), 409 );
		$invalid = self::expect_error(
			fn() => $this->bracket->schedule(
				$semi['id'],
				$this->tk,
				array(
					'scheduled_at' => '2026-11-02 08:59',
					'venue_id'     => '99999',
				)
			),
			422
		);
		self::assertSame( array( 'scheduled_at', 'venue_id' ), array_column( $invalid->errors, 'field' ) );
		self::expect_error( fn() => $this->bracket->schedule( $semi['id'], $this->tk, array( 'scheduled_at' => '2027-08-01 14:00' ) ), 422 );
		self::expect_error( fn() => $this->bracket->schedule( $semi['id'], $this->tk, array( 'scheduled_at' => '' ) ), 422 );

		$count  = count( $this->events->calls );
		$result = $this->bracket->schedule(
			$semi['id'],
			$this->tk,
			array(
				'scheduled_at' => '2026-12-12T14:00',
				'venue_id'     => (string) $venue,
			)
		);

		self::assertSame(
			array(
				'tie'          => $semi['id'],
				'fixture'      => $semi['fixture']['id'],
				'scheduled_at' => '2026-12-12 14:00:00',
				'venue_id'     => $venue,
			),
			$result
		);
		$row = $this->fixture_row( $semi['fixture']['id'] );
		self::assertSame( array( 'scheduled', '2026-12-12 14:00:00', (string) $venue ), array( $row['status'], $row['scheduled_at'], $row['venue_id'] ) );
		$event = (array) $this->db->get_row( "SELECT type, comment, data_json FROM {$this->db->table( 'fixture_event' )} WHERE fixture_id = %d", array( $semi['fixture']['id'] ) );
		self::assertSame( array( 'postponed', 'Termin: Sa, 12.12.2026, 14:00. Affoltern: Sporthalle Affoltern.' ), array( $event['type'], $event['comment'] ) );
		self::assertSame( 'propose', json_decode( (string) $event['data_json'], true )['action'], 'im Verlauf als «Termin vereinbart»' );
		self::assertSame( 'date_set', $this->audit( 'fixture', $semi['fixture']['id'] )[0]['action'] );
		self::assertSame( array( $this->cup_a, CupEvents::SCHEDULED, $this->tk->user_id, array( $semi['id'] ) ), $this->events->calls[ $count ] );

		// Mit Resultat lässt sich nichts mehr ansetzen.
		$this->play( $semi['fixture']['id'], 'home' );
		self::expect_error( fn() => $this->bracket->schedule( $semi['id'], $this->tk, array( 'scheduled_at' => '2026-12-19 14:00' ) ), 409 );
	}

	public function test_the_tk_swaps_the_home_right_of_an_open_fixture(): void {
		$ids     = $this->eight();
		$tie     = $this->tie( 1, 2 );
		$fixture = $tie['fixture']['id'];
		$home    = $this->player_actor( 'oerlikon', 1 );
		$away    = $this->player_actor( 'altstetten', 1 );

		self::expect_error( fn() => $this->bracket->swap_home( $tie['id'], $this->chef['oerlikon'] ), 403 );
		$result = $this->bracket->swap_home( $tie['id'], $this->tk );

		self::assertSame( array( $ids['altstetten'], $ids['oerlikon'] ), array( $result['home'], $result['away'] ) );
		$row = $this->fixture_row( $fixture );
		self::assertSame( array( (string) $ids['altstetten'], (string) $ids['oerlikon'] ), array( $row['home_entry_id'], $row['away_entry_id'] ) );
		self::assertSame( array( $ids['oerlikon'], $ids['altstetten'] ), array( $this->tie( 1, 2 )['home'], $this->tie( 1, 2 )['away'] ), 'die Plätze im Tableau bleiben' );
		$policy = new AccessPolicy( $this->db );
		self::assertTrue( $policy->can_enter_result( (int) $away->person_id, $fixture ), 'das neue Heimteam erfasst' );
		self::assertFalse( $policy->can_enter_result( (int) $home->person_id, $fixture ) );
		self::assertSame( 'home_swapped', $this->audit( 'fixture', $fixture )[0]['action'] );

		// Der Sieger rückt unabhängig vom Heimrecht auf den Platz seiner Paarung nach.
		$this->play( $fixture, 'home' );
		self::assertSame( $ids['altstetten'], $this->tie( 2, 1 )['away'] );
		self::expect_error( fn() => $this->bracket->swap_home( $tie['id'], $this->tk ), 409 );

		// Im Halbfinal gibt es kein Heimrecht.
		$this->play( $this->fixture_of( 1, 1 ), 'home' );
		$neutral = self::expect_error( fn() => $this->bracket->swap_home( $this->tie( 2, 1 )['id'], $this->tk ), 409 );
		self::assertStringContainsString( 'kein Heimrecht', $neutral->getMessage() );
	}

	public function test_the_bracket_only_changes_in_the_active_season(): void {
		$this->eight();
		$this->db->update( 'season', array( 'status' => 'archived' ), array( 'id' => $this->season ) );

		self::expect_error( fn() => $this->bracket->set_round_deadline( $this->cup_a, 1, '2026-12-20', $this->tk ), 409 );
		self::expect_error( fn() => $this->bracket->swap_home( $this->tie( 1, 1 )['id'], $this->tk ), 409 );
	}

	public function test_fixtures_of_other_competitions_do_not_touch_the_bracket(): void {
		$this->eight();
		$this->team( 'seefeld', 3, 4, $this->cup_b );
		$this->team( 'oerlikon', 3, 4, $this->cup_b );
		$entries = $this->repo->entries( $this->cup_b );
		$fixture = $this->seeder->fixture(
			$this->cup_b,
			array(
				'home_entry_id' => $entries[0]['id'],
				'away_entry_id' => $entries[1]['id'],
				'scheduled_at'  => '2026-11-01 19:00:00',
			)
		);
		$count   = count( $this->events->calls );

		// Cup B hat kein Tableau (wie eine importierte Saison): Die Erfassung läuft ohne Abgleich.
		$this->play( $fixture, 'home' );

		self::assertSame( 'confirmed', $this->fixture_row( $fixture )['status'] );
		self::assertCount( $count, $this->events->calls );
		self::assertSame( array(), $this->repo->ties( $this->cup_b ) );
	}
}
