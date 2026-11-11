<?php
/**
 * Integrationstests der Verwaltung von Anlässen: anlegen, ändern, schliessen, absagen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Events;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Daten siehe EventTestCase.
 */
final class EventServiceTest extends EventTestCase {

	public function test_the_tk_creates_an_open_event(): void {
		$id = $this->events->create( $this->input( array( 'max_participants' => '16' ) ), $this->actor['tk'] );

		self::assertSame(
			array(
				'id'               => $id,
				'season_id'        => $this->id['season'],
				'type'             => 'tournament',
				'title'            => 'Plauschturnier',
				'starts_at'        => '2026-11-20 19:30:00',
				'ends_at'          => '2026-11-20 22:00:00',
				'location'         => 'Turnhalle Seefeld',
				'deadline'         => '2026-11-15 18:00:00',
				'max_participants' => 16,
				'post_id'          => null,
				'status'           => 'open',
				'created_by'       => $this->actor['tk']->user_id,
			),
			$this->events->repository()->find( $id )
		);
		self::assertSame( array( array( 'event_changed', $id, $this->actor['tk']->user_id, 'created' ) ), $this->listener->calls );
		self::assertSame( array( 'created' ), $this->audit_actions( 'event' ) );
	}

	/**
	 * @return iterable<string,array{string}>
	 */
	public static function others(): iterable {
		yield 'TK-Chefin' => array( 'chef_home' );
		yield 'Captain' => array( 'captain' );
		yield 'Spieler' => array( 'h1' );
		yield 'Konto ohne Person' => array( 'unlinked' );
	}

	#[DataProvider( 'others' )]
	public function test_only_the_tk_manages_events( string $actor ): void {
		$event = $this->event();

		$errors = array(
			$this->failure( fn() => $this->events->create( $this->input(), $this->actor[ $actor ] ) ),
			$this->failure( fn() => $this->events->update( $event, $this->input( array( 'title' => 'Übernommen' ) ), $this->actor[ $actor ] ) ),
			$this->failure( fn() => $this->events->close( $event, $this->actor[ $actor ] ) ),
			$this->failure( fn() => $this->events->cancel( $event, $this->actor[ $actor ] ) ),
		);
		$this->events->close( $event, $this->actor['tk'] );
		$errors[] = $this->failure( fn() => $this->events->reopen( $event, $this->actor[ $actor ] ) );

		self::assertSame( array( 403, 403, 403, 403, 403 ), array_map( static fn( $e ): int => $e->status, $errors ) );
		self::assertSame( '1', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'event' )}" ) );
		self::assertSame( 'Plauschturnier', $this->events->repository()->find( $event )['title'] );
		self::assertSame( 'closed', $this->status_of( $event ) );
	}

	public function test_invalid_input_and_unknown_season_are_rejected_with_fields(): void {
		$invalid = $this->failure( fn() => $this->events->create( $this->input( array( 'title' => '' ) ), $this->actor['tk'] ) );
		$season  = $this->failure( fn() => $this->events->create( $this->input( array( 'season_id' => '99999' ) ), $this->actor['tk'] ) );

		self::assertSame( 422, $invalid->status );
		self::assertSame( array( 'title' ), array_column( $invalid->errors, 'field' ) );
		self::assertSame( 422, $season->status );
		self::assertSame( array( 'season_id' ), array_column( $season->errors, 'field' ) );
		self::assertSame( '0', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'event' )}" ) );
		self::assertSame( array(), $this->listener->calls );
	}

	public function test_update_changes_the_fields_and_keeps_status_and_creator(): void {
		$event = $this->event();
		$this->events->close( $event, $this->actor['tk'] );
		$this->listener->calls = array();

		$this->events->update(
			$event,
			$this->input(
				array(
					'season_id' => '',
					'type'      => 'meeting',
					'title'     => 'Captains-Sitzung',
					'starts_at' => '2026-12-01T19:00',
					'ends_at'   => '',
					'location'  => '',
					'deadline'  => '',
					'post_id'   => '77',
				)
			),
			$this->actor['tk_account']
		);

		$after = $this->events->repository()->find( $event );
		self::assertSame( array( null, 'meeting', 'Captains-Sitzung', '2026-12-01 19:00:00', null, null, null, 77 ), array( $after['season_id'], $after['type'], $after['title'], $after['starts_at'], $after['ends_at'], $after['location'], $after['deadline'], $after['post_id'] ) );
		self::assertSame( 'closed', $after['status'] );
		self::assertSame( $this->actor['tk']->user_id, $after['created_by'] );
		self::assertSame( array( array( 'event_changed', $event, 208, 'updated' ) ), $this->listener->calls );
		$log = $this->db->get_row( "SELECT before_json, after_json, user_id FROM {$this->db->table( 'audit_log' )} WHERE entity = 'event' AND action = 'updated'" );
		self::assertSame( 'Plauschturnier', json_decode( (string) $log['before_json'], true )['title'] );
		self::assertSame( 'Captains-Sitzung', json_decode( (string) $log['after_json'], true )['title'] );
		self::assertSame( '208', $log['user_id'] );
	}

	public function test_raising_the_limit_promotes_the_longest_waiting_in_order(): void {
		$event = $this->event( array( 'max_participants' => '1' ) );
		$this->register_all( $event, array( 'h1', 'h2', 'h3', 'h4' ) );
		$this->listener->calls = array();

		$result = $this->events->update( $event, $this->input( array( 'max_participants' => '3' ) ), $this->actor['tk'] );

		self::assertSame( array( $this->person['h2'], $this->person['h3'] ), $result['promoted'] );
		self::assertSame(
			array(
				'h1' => 'registered',
				'h2' => 'registered',
				'h3' => 'registered',
				'h4' => 'waitlist',
			),
			$this->list_of( $event )
		);
		self::assertSame( array( 'event_changed', 'promoted', 'promoted' ), $this->listener->names() );
		self::assertSame( array( 'promoted', 'promoted' ), array_slice( $this->audit_actions( 'registration' ), -2 ) );
	}

	public function test_removing_the_limit_promotes_the_whole_waitlist(): void {
		$event = $this->event( array( 'max_participants' => '1' ) );
		$this->register_all( $event, array( 'h1', 'h2', 'h3' ) );

		$result = $this->events->update( $event, $this->input( array( 'max_participants' => '' ) ), $this->actor['tk'] );

		self::assertSame( array( $this->person['h2'], $this->person['h3'] ), $result['promoted'] );
		self::assertSame( array( 'registered' ), array_values( array_unique( $this->list_of( $event ) ) ) );
	}

	public function test_lowering_the_limit_keeps_registrations_and_blocks_moving_up(): void {
		$event = $this->event( array( 'max_participants' => '3' ) );
		$this->register_all( $event, array( 'h1', 'h2', 'h3' ) );

		$result = $this->events->update( $event, $this->input( array( 'max_participants' => '1' ) ), $this->actor['tk'] );
		$late   = $this->registrations->register( $event, $this->person['h4'], $this->actor['h4'] );
		$first  = $this->registrations->unregister( $event, $this->person['h1'], $this->actor['h1'] );
		$second = $this->registrations->unregister( $event, $this->person['h2'], $this->actor['h2'] );
		$third  = $this->registrations->unregister( $event, $this->person['h3'], $this->actor['h3'] );

		self::assertSame( array(), $result['promoted'] );
		self::assertSame( 'waitlist', $late['status'] );
		self::assertSame( array(), $first['promoted'], 'zwei Teilnehmende bei Höchstzahl 1' );
		self::assertSame( array(), $second['promoted'], 'ein Teilnehmender bei Höchstzahl 1' );
		self::assertSame( array( $this->person['h4'] ), $third['promoted'], 'erst jetzt ist ein Platz frei' );
	}

	public function test_close_reopen_and_cancel_follow_the_status(): void {
		$event = $this->event();
		$tk    = $this->actor['tk'];

		self::assertSame( 409, $this->failure( fn() => $this->events->reopen( $event, $tk ) )->status, 'offen lässt sich nicht öffnen' );
		$this->events->close( $event, $tk );
		self::assertSame( 'closed', $this->status_of( $event ) );
		self::assertSame( 409, $this->failure( fn() => $this->events->close( $event, $tk ) )->status, 'geschlossen lässt sich nicht schliessen' );
		$this->events->reopen( $event, $tk );
		self::assertSame( 'open', $this->status_of( $event ) );
		$this->events->close( $event, $tk );
		$this->events->cancel( $event, $tk );
		self::assertSame( 'cancelled', $this->status_of( $event ), 'auch ein geschlossener Anlass lässt sich absagen' );

		$again  = $this->failure( fn() => $this->events->cancel( $event, $tk ) );
		$reopen = $this->failure( fn() => $this->events->reopen( $event, $tk ) );
		$close  = $this->failure( fn() => $this->events->close( $event, $tk ) );
		$update = $this->failure( fn() => $this->events->update( $event, $this->input( array( 'title' => 'Doch nicht' ) ), $tk ) );

		self::assertSame( array( 409, 409, 409, 409 ), array( $again->status, $reopen->status, $close->status, $update->status ) );
		self::assertSame( 'Der Anlass ist bereits abgesagt.', $again->getMessage() );
		self::assertSame( 'Ein abgesagter Anlass lässt sich nicht mehr ändern.', $update->getMessage() );
		self::assertSame( 'Plauschturnier', $this->events->repository()->find( $event )['title'] );
		self::assertSame( array( 'created', 'closed', 'reopened', 'closed', 'cancelled' ), $this->audit_actions( 'event' ) );
		self::assertSame( array( 'event_changed', 'event_changed', 'event_changed', 'cancelled' ), $this->listener->names(), 'abgewiesene Aktionen melden nichts' );
	}

	public function test_cancelling_keeps_the_registrations(): void {
		$event = $this->event( array( 'max_participants' => '1' ) );
		$this->register_all( $event, array( 'h1', 'h2' ) );
		$this->listener->calls = array();

		$this->events->cancel( $event, $this->actor['tk'] );

		self::assertSame(
			array(
				'h1' => 'registered',
				'h2' => 'waitlist',
			),
			$this->list_of( $event )
		);
		self::assertSame( array( $this->person['h1'], $this->person['h2'] ), $this->events->repository()->registered_people( $event ) );
		self::assertSame( array( array( 'cancelled', $event, $this->actor['tk']->user_id ) ), $this->listener->calls );
	}

	public function test_unknown_event(): void {
		$tk = $this->actor['tk'];

		self::assertSame( 404, $this->failure( fn() => $this->events->update( 99999, $this->input(), $tk ) )->status );
		self::assertSame( 404, $this->failure( fn() => $this->events->close( 99999, $tk ) )->status );
		self::assertSame( 404, $this->failure( fn() => $this->events->cancel( 99999, $tk ) )->status );
	}

	public function test_the_list_counts_participants_and_waitlist_per_event(): void {
		$first  = $this->event( array( 'max_participants' => '1' ) );
		$second = $this->event(
			array(
				'title'     => 'Training',
				'starts_at' => '2026-12-05T10:00',
				'ends_at'   => '',
				'deadline'  => '',
			)
		);
		$this->register_all( $first, array( 'h1', 'h2', 'h3' ) );
		$this->register_all( $second, array( 'a1' ) );

		$list = $this->events->repository()->all();

		self::assertSame( array( $second, $first ), array_column( $list, 'id' ), 'die neuesten zuerst' );
		self::assertSame( array( 1, 1 ), array_column( $list, 'registered' ) );
		self::assertSame( array( 0, 2 ), array_column( $list, 'waitlist' ) );
	}

	public function test_the_participant_list_names_club_time_and_note(): void {
		$event = $this->event( array( 'max_participants' => '2' ) );
		$this->registrations->register( $event, $this->person['a1'], $this->actor['a1'], 'Vegetarisch' );
		$this->clock->advance( '+1 minute' );
		$this->registrations->register( $event, $this->person['captain'], $this->actor['captain'] );
		$this->clock->advance( '+1 minute' );
		$this->registrations->register( $event, $this->person['nobody'], $this->actor['nobody'] );

		$rows = $this->events->repository()->registrations( $event, $this->id['season'] );

		self::assertSame( array( 'Spieler1 Wollishofer', 'Hanna Heim', 'Nina Niemand' ), array_column( $rows, 'name' ) );
		self::assertSame( array( 'Wollishofen', 'Seefeld', null ), array_column( $rows, 'club' ), 'Club aus der Meldung, sonst aus der Funktion' );
		self::assertSame( array( 'registered', 'registered', 'waitlist' ), array_column( $rows, 'status' ) );
		self::assertSame( array( 'Vegetarisch', null, null ), array_column( $rows, 'note' ) );
		self::assertSame( array( '2026-11-10 12:00:00', '2026-11-10 12:01:00', '2026-11-10 12:02:00' ), array_column( $rows, 'created_at' ) );
	}

	public function test_candidates_are_active_people_of_the_season_not_yet_registered(): void {
		$event = $this->event();
		$this->register_all( $event, array( 'h1' ) );
		$this->db->update( 'person', array( 'status' => 'inactive' ), array( 'id' => $this->person['h2'] ) );
		$repo = $this->events->repository();

		$with_season = array_column( $repo->candidates( $event, $this->id['season'] ), 'person_id' );
		$all         = array_column( $repo->candidates( $event, null ), 'person_id' );

		self::assertEqualsCanonicalizing(
			array( $this->person['h3'], $this->person['h4'], $this->person['a1'], $this->person['a2'], $this->person['chef_home'], $this->person['chef_away'], $this->person['captain'] ),
			$with_season,
			'gemeldete Spieler und Funktionsträger der Saison'
		);
		self::assertContains( $this->person['nobody'], $all, 'ohne Saison alle aktiven Personen' );
		self::assertNotContains( $this->person['h1'], $all );
		self::assertNotContains( $this->person['h2'], $all );
	}
}
