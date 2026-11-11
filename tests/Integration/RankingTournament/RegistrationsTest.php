<?php
/**
 * Integrationstests: Anmeldung, Kategorien, Sperrdaten und Abmeldung im Ranglistenturnier.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\RankingTournament;

use TTLeague\RankingTournament\Registrations;
use TTLeague\Results\Actor;

final class RegistrationsTest extends RlTestCase {

	public function test_person_registers_herself_with_category_from_classification(): void {
		$result = $this->registrations()->register( $this->actor( 's1' ), $this->player['s1'] );

		self::assertSame( 1, $result['category'] );
		$row = $this->repo()->participant( $this->id['competition'], $this->player['s1'] );
		self::assertSame( 'registered', $row['status'] );
		self::assertSame( 100 + $this->player['s1'], $row['registered_by'] );
		self::assertSame( '2026-09-01 10:00:00', $row['registered_at'] );
		self::assertSame( array( array( 'rl_participant', $result['id'], false ) ), $this->events->changed );
		$audit = $this->audit( 'rl_participant', $result['id'] );
		self::assertSame( array( 'registered' ), array_column( $audit, 'action' ) );
		self::assertSame( 1, $audit[0]['after']['category'] );
	}

	public function test_category_limits_are_inclusive(): void {
		// Klassierungen 18, 16, 12, 9, 8, 3 bei Grenzen 16, 9 und 1.
		$expected = array(
			's1' => 1,
			's2' => 1,
			's3' => 2,
			's4' => 2,
			's5' => 3,
			's6' => 3,
		);
		foreach ( $expected as $key => $category ) {
			self::assertSame( $category, $this->registrations()->register( $this->actor( $key ), $this->player[ $key ] )['category'], $key );
		}
	}

	public function test_tk_chef_registers_players_of_her_club_only(): void {
		$service = $this->registrations();
		$service->register( $this->chef(), $this->player['s3'] );
		self::assertSame( 'registered', $this->repo()->participant( $this->id['competition'], $this->player['s3'] )['status'] );
		self::assertSame( 901, $this->repo()->participant( $this->id['competition'], $this->player['s3'] )['registered_by'] );

		$this->assert_fails( 403, fn() => $service->register( $this->chef(), $this->player['w1'] ) );
		$this->assert_fails( 403, fn() => $service->register( $this->nobody(), $this->player['s1'] ) );
		$this->assert_fails( 403, fn() => $service->register( $this->actor( 's1' ), $this->player['s2'] ) );
		$this->assert_fails( 403, fn() => $service->register( new Actor( 950, null, false ), $this->player['s2'] ) );
		self::assertNull( $this->repo()->participant( $this->id['competition'], $this->player['w1'] ) );
		self::assertNull( $this->repo()->participant( $this->id['competition'], $this->player['s2'] ) );
	}

	public function test_registration_closes_after_the_deadline_day(): void {
		$service = $this->registrations();
		// Anmeldeschluss 10. September: 23:59 Ortszeit (21:59 UTC) geht noch.
		$this->clock = new \TTLeague\Infrastructure\Time\FixedClock( '2026-09-10 21:59:00' );
		$service     = $this->registrations();
		$service->register( $this->actor( 's1' ), $this->player['s1'] );
		$service->register( $this->chef(), $this->player['s2'] );
		self::assertTrue( $service->registration_open( $service->active_competition() ) );

		// Eine Minute später ist in Zürich der 11. September.
		$this->clock = new \TTLeague\Infrastructure\Time\FixedClock( '2026-09-10 22:00:00' );
		$service     = $this->registrations();
		self::assertFalse( $service->registration_open( $service->active_competition() ) );
		$this->assert_fails( 409, fn() => $service->register( $this->actor( 's3' ), $this->player['s3'] ) );
		$this->assert_fails( 409, fn() => $service->register( $this->chef(), $this->player['s4'] ) );
		self::assertNull( $this->repo()->participant( $this->id['competition'], $this->player['s3'] ) );

		// Die TK meldet nach.
		$service->register( $this->tk, $this->player['s3'] );
		self::assertSame( 'registered', $this->repo()->participant( $this->id['competition'], $this->player['s3'] )['status'] );
		// Abmelden bleibt möglich.
		$service->withdraw( $this->actor( 's1' ), $this->player['s1'] );
		self::assertSame( 'withdrawn', $this->repo()->participant( $this->id['competition'], $this->player['s1'] )['status'] );
	}

	public function test_registering_twice_changes_nothing(): void {
		$service = $this->registrations();
		$first   = $service->register( $this->actor( 's1' ), $this->player['s1'] );
		$this->clock->advance( '+1 hour' );
		$second = $service->register( $this->chef(), $this->player['s1'] );

		self::assertSame( $first, $second );
		self::assertSame( '2026-09-01 10:00:00', $this->repo()->participant( $this->id['competition'], $this->player['s1'] )['registered_at'] );
		self::assertCount( 1, $this->audit( 'rl_participant', $first['id'] ) );
		self::assertCount( 1, $this->events->changed );
	}

	public function test_without_categories_the_tk_sets_the_category(): void {
		$this->seed( array(), array() );
		$service = $this->registrations();
		$result  = $service->register( $this->actor( 's1' ), $this->player['s1'] );
		self::assertSame( 0, $result['category'], 'Ohne Grenzen bleibt die Kategorie leer.' );

		$this->assert_fails( 403, fn() => $service->set_category( $this->chef(), $this->player['s1'], 2 ) );
		$service->set_category( $this->tk, $this->player['s1'], 2 );
		self::assertSame( 2, $this->repo()->participant( $this->id['competition'], $this->player['s1'] )['category'] );
		$audit = $this->audit( 'rl_participant', $result['id'] );
		self::assertSame( 'category', $audit[1]['action'] );
		self::assertSame( array( 'category' => 0 ), $audit[1]['before'] );
		self::assertSame( array( 'category' => 2 ), $audit[1]['after'] );
		$this->assert_fails( 422, fn() => $service->set_category( $this->tk, $this->player['s1'], 0 ) );
		$this->assert_fails( 409, fn() => $service->set_category( $this->tk, $this->player['s2'], 1 ) );
	}

	public function test_only_the_tk_chooses_a_category(): void {
		$service = $this->registrations();
		// Die Person hat Klassierung 12 (Kategorie 2); ihre eigene Angabe zählt nicht.
		self::assertSame( 2, $service->register( $this->actor( 's3' ), $this->player['s3'], 1 )['category'] );
		self::assertSame( 1, $service->register( $this->tk, $this->player['s4'], 1 )['category'] );
		$this->assert_fails( 422, fn() => $service->register( $this->tk, $this->player['s5'], 4 ) );
		$this->assert_fails( 422, fn() => $service->set_category( $this->tk, $this->player['s3'], 9 ) );

		// Nach Ab- und Wiederanmeldung bleibt die von der TK gesetzte Kategorie.
		$service->set_category( $this->tk, $this->player['s3'], 3 );
		$service->withdraw( $this->actor( 's3' ), $this->player['s3'] );
		self::assertSame( 3, $service->register( $this->actor( 's3' ), $this->player['s3'] )['category'] );
	}

	public function test_only_reported_active_players_can_register(): void {
		$service  = $this->registrations();
		$stranger = $this->seeder->player( 'Fremder', 'Franz' );
		$this->assert_fails( 404, fn() => $service->register( $this->tk, $stranger ) );
		$this->assert_fails( 403, fn() => $service->register( new Actor( 960, $this->seeder->person_of( $stranger ), false ), $stranger ) );

		$this->db->update( 'player_season', array( 'status' => 'inactive' ), array( 'player_id' => $this->player['s1'] ) );
		$this->assert_fails( 409, fn() => $service->register( $this->actor( 's1' ), $this->player['s1'] ) );
	}

	public function test_withdrawing_from_the_tournament(): void {
		$service = $this->registrations();
		$this->assert_fails( 409, fn() => $service->withdraw( $this->actor( 's1' ), $this->player['s1'] ) );
		$id = $service->register( $this->actor( 's1' ), $this->player['s1'] )['id'];
		$this->assert_fails( 403, fn() => $service->withdraw( $this->nobody(), $this->player['s1'] ) );

		$service->withdraw( $this->chef(), $this->player['s1'] );
		self::assertSame( 'withdrawn', $this->repo()->participant( $this->id['competition'], $this->player['s1'] )['status'] );
		self::assertSame( array( 'registered', 'withdrawn' ), array_column( $this->audit( 'rl_participant', $id ), 'action' ) );
		// Ein zweites Abmelden ändert nichts.
		$service->withdraw( $this->chef(), $this->player['s1'] );
		self::assertCount( 2, $this->audit( 'rl_participant', $id ) );
	}

	public function test_withdrawing_leaves_planned_groups_and_marks_published_ones_absent(): void {
		$this->register( array( 's1', 's2', 'w1', 'w2', 'o1', 'o2' ) );
		$first = $this->round( '2026-09-21' );
		$this->rounds()->propose( $this->tk, $first );
		$this->rounds()->publish( $this->tk, $first );
		$group = $this->repo()->groups( $first )[0];

		$this->registrations()->withdraw( $this->actor( 'w1' ), $this->player['w1'] );
		$members = array_column( $this->repo()->group( $group['id'] )['members'], 'status', 'player_id' );
		self::assertSame( 'absent', $members[ $this->player['w1'] ], 'In der freigegebenen Runde gilt die Person als abgemeldet.' );
		self::assertCount( 6, $members );

		// Runde abschliessen, nächste einteilen: Wer sich danach abmeldet, fällt aus dem Vorschlag.
		$this->entry()->save( $group['id'], $this->sheet( $group['id'], array( $this->player['w1'] => 'absent' ) ), $this->tk );
		$this->rounds()->close( $this->tk, $first );
		$second = $this->round( '2026-10-19' );
		$this->rounds()->propose( $this->tk, $second );
		$planned = $this->repo()->groups( $second )[0];
		self::assertNotContains( $this->player['w1'], array_column( $planned['members'], 'player_id' ) );
		$chief = $planned['chief_player_id'];
		$this->registrations()->withdraw( $this->tk, $chief );
		$after = $this->repo()->group( $planned['id'] );
		self::assertNotContains( $chief, array_column( $after['members'], 'player_id' ) );
		self::assertCount( 4, $after['members'] );
		self::assertNull( $after['chief_player_id'], 'Die TK bestimmt einen neuen Gruppenchef.' );
		// Die abgeschlossene Runde bleibt unverändert.
		self::assertContains( $chief, array_column( $this->repo()->group( $group['id'] )['members'], 'player_id' ) );
	}

	public function test_blocked_dates_are_stored_as_availability(): void {
		$this->register( array( 's1', 's2' ) );
		$round   = $this->round( '2026-09-21' );
		$service = $this->registrations();
		$person  = $this->seeder->person_of( $this->player['s1'] );

		self::assertSame( array(), $this->repo()->answered( array( $person ), '2026-09-21', '2026-09-24' ) );
		$saved = $service->set_blocked_dates( $this->actor( 's1' ), $this->player['s1'], $round, array( '2026-09-23', '2026-09-21', '2026-09-23' ) );
		self::assertSame( array( '2026-09-21', '2026-09-23' ), $saved );
		$rows = $this->db->get_results( "SELECT date_from, date_to, context FROM {$this->db->table( 'availability' )} WHERE person_id = %d ORDER BY context, date_from", array( $person ) );
		self::assertSame(
			array(
				array(
					'date_from' => '2026-09-21',
					'date_to'   => '2026-09-21',
					'context'   => 'rl',
				),
				array(
					'date_from' => '2026-09-23',
					'date_to'   => '2026-09-23',
					'context'   => 'rl',
				),
				array(
					'date_from' => '2026-09-21',
					'date_to'   => '2026-09-24',
					'context'   => 'rl_answer',
				),
			),
			$rows
		);

		// Die neue Meldung ersetzt die alte; «kein Abend gesperrt» ist auch eine Meldung.
		$service->set_blocked_dates( $this->chef(), $this->player['s1'], $round, array( '2026-09-24' ) );
		self::assertSame( array( $person => array( '2026-09-24' ) ), $this->repo()->blocked_dates( array( $person ), '2026-09-21', '2026-09-24' ) );
		$service->set_blocked_dates( $this->tk, $this->player['s1'], $round, array() );
		self::assertSame( array(), $this->repo()->blocked_dates( array( $person ), '2026-09-21', '2026-09-24' ) );
		self::assertSame( array( $person ), $this->repo()->answered( array( $person ), '2026-09-21', '2026-09-24' ) );
		$participant = $this->repo()->participant( $this->id['competition'], $this->player['s1'] )['id'];
		self::assertSame( array( 'registered', 'blocked_dates', 'blocked_dates', 'blocked_dates' ), array_column( $this->audit( 'rl_participant', $participant ), 'action' ) );
	}

	public function test_blocked_dates_must_be_evenings_of_the_round_week(): void {
		$this->register( array( 's1' ) );
		$round   = $this->round( '2026-09-21' );
		$service = $this->registrations();

		// Montag bis Donnerstag gehören zur Woche, der Freitag und der Sonntag davor nicht.
		self::assertSame( array( '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24' ), Registrations::evenings( $this->repo()->round( $round ) ) );
		$this->assert_fails( 422, fn() => $service->set_blocked_dates( $this->actor( 's1' ), $this->player['s1'], $round, array( '2026-09-25' ) ) );
		$this->assert_fails( 422, fn() => $service->set_blocked_dates( $this->actor( 's1' ), $this->player['s1'], $round, array( '2026-09-20' ) ) );
		$this->assert_fails( 422, fn() => $service->set_blocked_dates( $this->actor( 's1' ), $this->player['s1'], $round, array( 'morgen' ) ) );
		$this->assert_fails( 404, fn() => $service->set_blocked_dates( $this->actor( 's1' ), $this->player['s1'], $round + 99, array() ) );
		// Nur für Angemeldete, nur für sich, den eigenen Club oder durch die TK.
		$this->assert_fails( 409, fn() => $service->set_blocked_dates( $this->actor( 's2' ), $this->player['s2'], $round, array() ) );
		$this->assert_fails( 403, fn() => $service->set_blocked_dates( $this->nobody(), $this->player['s1'], $round, array() ) );
		self::assertSame( 0, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'availability' )}" ) );
	}

	public function test_blocked_dates_are_fixed_once_the_round_is_published(): void {
		$this->register( array( 's1', 's2', 'w1', 'w2' ) );
		$round   = $this->round( '2026-09-21' );
		$service = $this->registrations();
		$this->rounds()->propose( $this->tk, $round );
		// Eingeteilt, aber noch nicht freigegeben: Sperrdaten lassen sich ändern.
		$service->set_blocked_dates( $this->actor( 's1' ), $this->player['s1'], $round, array( '2026-09-24' ) );

		$this->rounds()->publish( $this->tk, $round );
		$this->assert_fails( 409, fn() => $service->set_blocked_dates( $this->actor( 's1' ), $this->player['s1'], $round, array() ) );
		$this->assert_fails( 409, fn() => $service->set_blocked_dates( $this->tk, $this->player['s1'], $round, array() ) );
		$person = $this->seeder->person_of( $this->player['s1'] );
		self::assertSame( array( $person => array( '2026-09-24' ) ), $this->repo()->blocked_dates( array( $person ), '2026-09-21', '2026-09-24' ) );
	}

	public function test_withdrawing_from_a_published_round(): void {
		$this->register( array( 's1', 's2', 'w1', 'w2' ) );
		$round   = $this->round( '2026-09-21' );
		$service = $this->registrations();
		$this->rounds()->propose( $this->tk, $round );
		$group = $this->repo()->groups( $round )[0];

		// Vor der Freigabe gibt es kein Aufgebot, von dem man sich abmelden könnte.
		$this->assert_fails( 409, fn() => $service->withdraw_from_round( $this->actor( 's1' ), $this->player['s1'], $round ) );
		$this->rounds()->publish( $this->tk, $round );
		$this->assert_fails( 403, fn() => $service->withdraw_from_round( $this->nobody(), $this->player['s1'], $round ) );
		$this->assert_fails( 409, fn() => $service->withdraw_from_round( $this->tk, $this->player['o1'], $round ) );

		$service->withdraw_from_round( $this->actor( 's1' ), $this->player['s1'], $round );
		$members = array_column( $this->repo()->group( $group['id'] )['members'], 'status', 'player_id' );
		self::assertSame( 'absent', $members[ $this->player['s1'] ] );
		self::assertSame( 'scheduled', $members[ $this->player['s2'] ] );
		// Die Anmeldung zum Turnier bleibt.
		self::assertSame( 'registered', $this->repo()->participant( $this->id['competition'], $this->player['s1'] )['status'] );
		self::assertSame( 'player_absent', $this->audit( 'rl_group', $group['id'] )[0]['action'] ?? null );
		$this->assert_fails( 409, fn() => $service->withdraw_from_round( $this->actor( 's1' ), $this->player['s1'], $round ) );

		// Ist der Gruppenzettel erfasst, meldet sich niemand mehr ab.
		$this->entry()->save( $group['id'], $this->sheet( $group['id'], array( $this->player['s1'] => 'absent' ) ), $this->tk );
		$this->assert_fails( 409, fn() => $service->withdraw_from_round( $this->chef(), $this->player['s2'], $round ) );
	}

	public function test_tk_configures_the_tournament(): void {
		$this->db->delete( 'competition', array( 'id' => $this->id['competition'] ) );
		$service  = $this->registrations();
		$settings = array(
			'categories'            => array(
				array(
					'no'  => 2,
					'min' => 1,
				),
				array(
					'no'  => 1,
					'min' => 10,
				),
			),
			'registration_deadline' => '2026-09-15',
			'start_time'            => '19:45',
		);
		$this->assert_fails( 403, fn() => $service->configure( $this->chef(), $this->id['season'], $settings ) );
		self::assertNull( $service->active_competition() );

		$id          = $service->configure( $this->tk, $this->id['season'], $settings );
		$competition = $service->active_competition();
		self::assertSame( $id, $competition['id'] );
		self::assertSame( 'Ranglistenturnier', $competition['name'] );
		self::assertSame( '2026-09-15', $competition['deadline'] );
		self::assertSame( '19:45:00', $competition['start_time'] );
		self::assertSame( array( 10, 1 ), array_column( $competition['categories'], 'min' ) );

		// Ändern legt keine zweite Konkurrenz an; ungültige Angaben werden abgelehnt.
		self::assertSame( $id, $service->configure( $this->tk, $this->id['season'], array( 'name' => 'RL 2026/27' ) + $settings ) );
		self::assertSame( 'RL 2026/27', $service->active_competition()['name'] );
		$this->assert_fails(
			422,
			fn() => $service->configure(
				$this->tk,
				$this->id['season'],
				array(
					'categories' => array(
						array(
							'no'  => 0,
							'min' => 3,
						),
					),
				)
			)
		);
		$this->assert_fails( 422, fn() => $service->configure( $this->tk, $this->id['season'], array( 'registration_deadline' => '31.12.2026' ) ) );
		$this->assert_fails( 404, fn() => $service->configure( $this->tk, $this->id['season'] + 5, array() ) );
		self::assertSame( array( 'created', 'configured' ), array_column( $this->audit( 'rl_competition', $id ), 'action' ) );
	}

	public function test_configure_changes_only_what_is_passed(): void {
		$service = $this->registrations();
		$service->configure(
			$this->tk,
			$this->id['season'],
			array(
				'start_time'    => '19:45',
				'reminder_days' => 9,
			)
		);

		// Nur die Spielzeit war übergeben: Anmeldeschluss, Kategorien und Name bleiben.
		$competition = $service->active_competition();
		self::assertSame( '19:45:00', $competition['start_time'] );
		self::assertSame( '2026-09-10', $competition['deadline'] );
		self::assertSame( array( 16, 9, 1 ), array_column( $competition['categories'], 'min' ) );
		self::assertSame( 'Ranglistenturnier', $competition['name'] );
		self::assertArrayNotHasKey( 'reminder_days', $competition['format'], 'Unbekannte Schlüssel der Eingabe werden nicht gespeichert.' );

		// Nur der Name.
		$service->configure( $this->tk, $this->id['season'], array( 'name' => 'RL Region' ) );
		$competition = $service->active_competition();
		self::assertSame( array( 'RL Region', '2026-09-10', '19:45:00' ), array( $competition['name'], $competition['deadline'], $competition['start_time'] ) );

		// Ein ausdrücklich leerer Wert löscht die Einstellung, die anderen bleiben.
		$service->configure( $this->tk, $this->id['season'], array( 'registration_deadline' => '' ) );
		$competition = $service->active_competition();
		self::assertNull( $competition['deadline'] );
		self::assertSame( '19:45:00', $competition['start_time'] );
		self::assertCount( 3, $competition['categories'] );
		$service->configure(
			$this->tk,
			$this->id['season'],
			array(
				'start_time' => null,
				'categories' => array(),
			)
		);
		$competition = $service->active_competition();
		self::assertNull( $competition['start_time'] );
		self::assertSame( array(), $competition['categories'] );
		self::assertSame( 'RL Region', $competition['name'] );
	}
}
