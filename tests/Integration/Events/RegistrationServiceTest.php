<?php
/**
 * Integrationstests der An- und Abmeldung: Rechte, Höchstzahl, Warteliste, Nachrücken, Frist.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Events;

use PHPUnit\Framework\Attributes\DataProvider;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Tests\Integration\Database\RecordingDb;
use TTLeague\Tests\Integration\Results\TriggerClock;

/**
 * Daten siehe EventTestCase.
 */
final class RegistrationServiceTest extends EventTestCase {

	public function test_a_person_registers_herself_with_a_note(): void {
		$event = $this->event();

		$result = $this->registrations->register( $event, $this->person['h1'], $this->actor['h1'], "  Komme  etwas\nspäter " );

		self::assertSame( 'registered', $result['status'] );
		$row = $this->db->get_row( "SELECT * FROM {$this->db->table( 'registration' )} WHERE id = %d", array( $result['id'] ) );
		self::assertSame(
			array(
				'id'         => (string) $result['id'],
				'event_id'   => (string) $event,
				'person_id'  => (string) $this->person['h1'],
				'status'     => 'registered',
				'note'       => 'Komme etwas später',
				'created_at' => '2026-11-10 12:00:00',
			),
			$row,
			'Zeitpunkt in UTC, Bemerkung einzeilig'
		);
		self::assertSame( array( array( 'registered', $event, $result['id'], $this->person['h1'], 'registered', $this->actor['h1']->user_id ) ), $this->listener->calls );
		self::assertSame( array( 'registered' ), $this->audit_actions( 'registration' ) );
	}

	public function test_a_note_is_cut_to_the_column_length(): void {
		$event = $this->event();

		$result = $this->registrations->register( $event, $this->person['h1'], $this->actor['h1'], str_repeat( 'ä', 201 ) );

		self::assertSame( str_repeat( 'ä', 200 ), $this->db->get_var( "SELECT note FROM {$this->db->table( 'registration' )} WHERE id = %d", array( $result['id'] ) ) );
	}

	public function test_registering_twice_is_rejected(): void {
		$event = $this->event( array( 'max_participants' => '1' ) );
		$this->register_all( $event, array( 'h1', 'h2' ) );
		$this->listener->calls = array();

		$registered = $this->failure( fn() => $this->registrations->register( $event, $this->person['h1'], $this->actor['h1'] ) );
		$waiting    = $this->failure( fn() => $this->registrations->register( $event, $this->person['h2'], $this->actor['h2'] ) );

		self::assertSame( 409, $registered->status );
		self::assertSame( 'Diese Person ist bereits angemeldet.', $registered->getMessage() );
		self::assertSame( 'Diese Person steht bereits auf der Warteliste.', $waiting->getMessage() );
		self::assertSame( '2', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'registration' )}" ) );
		self::assertSame( array(), $this->listener->calls );
	}

	public function test_the_waitlist_starts_exactly_at_the_limit(): void {
		$event = $this->event( array( 'max_participants' => '2' ) );

		$first  = $this->registrations->register( $event, $this->person['h1'], $this->actor['h1'] );
		$second = $this->registrations->register( $event, $this->person['h2'], $this->actor['h2'] );
		$third  = $this->registrations->register( $event, $this->person['h3'], $this->actor['h3'] );

		self::assertSame( array( 'registered', 'registered', 'waitlist' ), array( $first['status'], $second['status'], $third['status'] ) );
		self::assertSame(
			array(
				'registered' => 2,
				'waitlist'   => 1,
			),
			$this->events->repository()->counts( $event )
		);
	}

	public function test_without_a_limit_everyone_is_registered(): void {
		$event = $this->event();
		$this->register_all( $event, array( 'h1', 'h2', 'h3', 'h4', 'a1', 'a2' ) );

		self::assertSame( array( 'registered' ), array_values( array_unique( $this->list_of( $event ) ) ) );
		self::assertCount( 6, $this->list_of( $event ) );
	}

	public function test_the_longest_waiting_person_moves_up_when_a_place_becomes_free(): void {
		$event = $this->event( array( 'max_participants' => '2' ) );
		$this->register_all( $event, array( 'h1', 'h2', 'h3', 'h4', 'a1' ) );
		$this->listener->calls = array();

		$result = $this->registrations->unregister( $event, $this->person['h1'], $this->actor['h1'] );

		self::assertSame( 'registered', $result['status'] );
		self::assertSame( array( $this->person['h3'] ), $result['promoted'] );
		self::assertSame(
			array(
				'h2' => 'registered',
				'h3' => 'registered',
				'h4' => 'waitlist',
				'a1' => 'waitlist',
			),
			$this->list_of( $event )
		);
		self::assertSame( array( 'unregistered', 'promoted' ), $this->listener->names() );
		self::assertSame( $this->person['h3'], $this->listener->calls[1][3] );
		self::assertSame( array( 'unregistered', 'promoted' ), array_slice( $this->audit_actions( 'registration' ), -2 ) );

		$this->registrations->unregister( $event, $this->person['h2'], $this->actor['h2'] );
		self::assertSame(
			array(
				'h3' => 'registered',
				'h4' => 'registered',
				'a1' => 'waitlist',
			),
			$this->list_of( $event ),
			'danach die nächste in der Reihenfolge der Anmeldung'
		);
	}

	/**
	 * Die Reihenfolge der Warteliste ist der Zeitpunkt der Anmeldung, nicht die ID: h4 hat die
	 * höhere ID, aber den früheren Zeitpunkt. Bei gleichem Zeitpunkt entscheidet die ID.
	 */
	public function test_the_waitlist_is_ordered_by_time_then_by_id(): void {
		$event = $this->event( array( 'max_participants' => '1' ) );
		$this->register_all( $event, array( 'h1', 'h2', 'h3', 'h4', 'a1' ) );
		$table = $this->db->table( 'registration' );
		$this->db->query( "UPDATE {$table} SET created_at = %s WHERE person_id = %d", array( '2026-11-10 11:00:00', $this->person['h4'] ) );
		$this->db->query( "UPDATE {$table} SET created_at = %s WHERE person_id IN (%d, %d)", array( '2026-11-10 11:30:00', $this->person['h2'], $this->person['a1'] ) );

		$promoted = array();
		foreach ( array( 'h1', 'h4', 'h2', 'a1' ) as $leaving ) {
			$promoted[] = $this->registrations->unregister( $event, $this->person[ $leaving ], $this->actor['tk'] )['promoted'];
		}

		self::assertSame(
			array(
				array( $this->person['h4'] ),
				array( $this->person['h2'] ),
				array( $this->person['a1'] ),
				array( $this->person['h3'] ),
			),
			$promoted
		);
	}

	public function test_leaving_the_waitlist_promotes_nobody(): void {
		$event = $this->event( array( 'max_participants' => '1' ) );
		$this->register_all( $event, array( 'h1', 'h2', 'h3' ) );
		$this->listener->calls = array();

		$result = $this->registrations->unregister( $event, $this->person['h2'], $this->actor['h2'] );

		self::assertSame( 'waitlist', $result['status'] );
		self::assertSame( array(), $result['promoted'] );
		self::assertSame(
			array(
				'h1' => 'registered',
				'h3' => 'waitlist',
			),
			$this->list_of( $event )
		);
		self::assertSame( array( 'unregistered' ), $this->listener->names() );
	}

	public function test_unregistering_without_a_registration_is_rejected(): void {
		$event = $this->event();

		$error = $this->failure( fn() => $this->registrations->unregister( $event, $this->person['h1'], $this->actor['h1'] ) );

		self::assertSame( 409, $error->status );
		self::assertSame( 'Diese Person ist nicht angemeldet.', $error->getMessage() );
		self::assertSame( array(), $this->listener->calls );
	}

	/**
	 * Anmeldeschluss 15.11.2026, 18:00 Ortszeit (17:00 UTC).
	 *
	 * @return iterable<string,array{string,bool}>
	 */
	public static function deadline_moments(): iterable {
		yield 'eine Sekunde vor dem Anmeldeschluss' => array( '2026-11-15 16:59:59', true );
		yield 'genau zum Anmeldeschluss' => array( '2026-11-15 17:00:00', true );
		yield 'eine Sekunde danach' => array( '2026-11-15 17:00:01', false );
	}

	#[DataProvider( 'deadline_moments' )]
	public function test_registration_ends_one_second_after_the_deadline( string $now_utc, bool $open ): void {
		$event = $this->event();
		$this->registrations->register( $event, $this->person['h2'], $this->actor['h2'] );
		$service = $this->registration_service( new FixedClock( $now_utc ), $this->listener );

		if ( $open ) {
			self::assertSame( 'registered', $service->register( $event, $this->person['h1'], $this->actor['h1'] )['status'] );
			self::assertSame( 'registered', $service->unregister( $event, $this->person['h2'], $this->actor['h2'] )['status'] );
			self::assertSame( 'registered', $service->register( $event, $this->person['h3'], $this->actor['chef_home'] )['status'], 'TK-Chefin für ihren Spieler' );
			return;
		}
		$register   = $this->failure( fn() => $service->register( $event, $this->person['h1'], $this->actor['h1'] ) );
		$unregister = $this->failure( fn() => $service->unregister( $event, $this->person['h2'], $this->actor['h2'] ) );
		$chef       = $this->failure( fn() => $service->register( $event, $this->person['h3'], $this->actor['chef_home'] ) );

		self::assertSame( 409, $register->status );
		self::assertSame( 'Der Anmeldeschluss ist vorbei. Änderungen nimmt die TK entgegen.', $register->getMessage() );
		self::assertSame( 409, $unregister->status );
		self::assertSame( 409, $chef->status );
		self::assertSame( array( 'h2' => 'registered' ), $this->list_of( $event ) );
	}

	public function test_after_the_deadline_only_the_tk_changes_and_the_waitlist_still_moves_up(): void {
		$event = $this->event( array( 'max_participants' => '1' ) );
		$this->register_all( $event, array( 'h1', 'h2' ) );
		$service = $this->registration_service( new FixedClock( '2026-11-16 08:00:00' ), $this->listener );

		$removed = $service->unregister( $event, $this->person['h1'], $this->actor['tk'] );
		$added   = $service->register( $event, $this->person['a1'], $this->actor['tk_account'], 'telefonisch gemeldet' );

		self::assertSame( array( $this->person['h2'] ), $removed['promoted'] );
		self::assertSame( 'waitlist', $added['status'], 'auch die TK überschreitet die Höchstzahl nicht' );
		self::assertSame(
			array(
				'h2' => 'registered',
				'a1' => 'waitlist',
			),
			$this->list_of( $event )
		);
	}

	public function test_without_a_deadline_registration_ends_with_the_start(): void {
		$event = $this->event( array( 'deadline' => '' ) );
		// Beginn 20.11.2026, 19:30 Ortszeit ist 18:30 UTC.
		$at_start = $this->registration_service( new FixedClock( '2026-11-20 18:30:00' ), $this->listener );
		$late     = $this->registration_service( new FixedClock( '2026-11-20 18:30:01' ), $this->listener );

		self::assertSame( 'registered', $at_start->register( $event, $this->person['h1'], $this->actor['h1'] )['status'] );
		self::assertSame( 409, $this->failure( fn() => $late->register( $event, $this->person['h2'], $this->actor['h2'] ) )->status );
	}

	public function test_a_closed_event_is_changed_by_the_tk_only(): void {
		$event = $this->event();
		$this->registrations->register( $event, $this->person['h1'], $this->actor['h1'] );
		$this->events->close( $event, $this->actor['tk'] );

		$register   = $this->failure( fn() => $this->registrations->register( $event, $this->person['h2'], $this->actor['h2'] ) );
		$unregister = $this->failure( fn() => $this->registrations->unregister( $event, $this->person['h1'], $this->actor['h1'] ) );

		self::assertSame( 409, $register->status );
		self::assertSame( 'Die Anmeldung ist geschlossen. Änderungen nimmt die TK entgegen.', $register->getMessage() );
		self::assertSame( 409, $unregister->status );
		self::assertSame( 'registered', $this->registrations->register( $event, $this->person['h2'], $this->actor['tk'] )['status'] );
		self::assertSame( 'registered', $this->registrations->unregister( $event, $this->person['h1'], $this->actor['tk'] )['status'] );

		$this->events->reopen( $event, $this->actor['tk'] );
		self::assertSame( 'registered', $this->registrations->register( $event, $this->person['h1'], $this->actor['h1'] )['status'], 'nach dem Öffnen wieder für alle' );
	}

	public function test_a_cancelled_event_is_closed_for_everyone(): void {
		$event = $this->event();
		$this->registrations->register( $event, $this->person['h1'], $this->actor['h1'] );
		$this->events->cancel( $event, $this->actor['tk'] );

		$errors = array(
			$this->failure( fn() => $this->registrations->register( $event, $this->person['h2'], $this->actor['h2'] ) ),
			$this->failure( fn() => $this->registrations->unregister( $event, $this->person['h1'], $this->actor['h1'] ) ),
			$this->failure( fn() => $this->registrations->register( $event, $this->person['h2'], $this->actor['tk'] ) ),
			$this->failure( fn() => $this->registrations->unregister( $event, $this->person['h1'], $this->actor['tk'] ) ),
		);

		foreach ( $errors as $error ) {
			self::assertSame( 409, $error->status );
			self::assertSame( 'Der Anlass ist abgesagt.', $error->getMessage() );
		}
		self::assertSame( array( 'h1' => 'registered' ), $this->list_of( $event ), 'die Anmeldungen bleiben stehen' );
	}

	/**
	 * @return iterable<string,array{string,string,bool}>
	 */
	public static function rights(): iterable {
		yield 'für sich selbst' => array( 'h1', 'h1', true );
		yield 'Captain ohne Lizenz für sich selbst' => array( 'captain', 'captain', true );
		yield 'Person ohne Funktion für sich selbst' => array( 'nobody', 'nobody', true );
		yield 'TK-Chefin für einen Spieler ihres Clubs' => array( 'chef_home', 'h2', true );
		yield 'TK-Chefin für einen Spieler eines anderen Clubs' => array( 'chef_home', 'a1', false );
		yield 'TK-Chefin für eine Person ohne Meldung' => array( 'chef_home', 'nobody', false );
		yield 'TK-Chefin für den Captain ihres Clubs ohne Lizenz' => array( 'chef_home', 'captain', false );
		yield 'Spieler für einen Clubkollegen' => array( 'h1', 'h2', false );
		yield 'Captain für einen Spieler des Clubs' => array( 'captain', 'h1', false );
		yield 'TK mit Person für irgendwen' => array( 'tk', 'a2', true );
		yield 'TK-Konto ohne Person für irgendwen' => array( 'tk_account', 'nobody', true );
		yield 'Konto ohne Person' => array( 'unlinked', 'h1', false );
	}

	#[DataProvider( 'rights' )]
	public function test_who_may_register_and_unregister_whom( string $actor, string $person, bool $allowed ): void {
		$event = $this->event();
		$this->registrations->register( $event, $this->person['a2'], $this->actor['tk'] );
		$this->registrations->register( $event, $this->person['a1'], $this->actor['a1'] );
		$this->listener->calls = array();

		if ( $allowed ) {
			if ( 'a2' !== $person ) {
				self::assertSame( 'registered', $this->registrations->register( $event, $this->person[ $person ], $this->actor[ $actor ] )['status'] );
			}
			self::assertSame( 'registered', $this->registrations->unregister( $event, $this->person[ $person ], $this->actor[ $actor ] )['status'] );
			return;
		}
		$register   = $this->failure( fn() => $this->registrations->register( $event, $this->person[ $person ], $this->actor[ $actor ] ) );
		$unregister = $this->failure( fn() => $this->registrations->unregister( $event, $this->person['a1'], $this->actor[ $actor ] ) );

		self::assertSame( 403, $register->status );
		self::assertSame( 403, $unregister->status, 'auch abmelden darf nur, wer anmelden dürfte' );
		self::assertSame( array( 'a2', 'a1' ), array_keys( $this->list_of( $event ) ) );
		self::assertSame( array(), $this->listener->calls );
	}

	public function test_guests_cannot_register(): void {
		$event = $this->event();
		$guest = new \TTLeague\Results\Actor( 0, null, false );

		self::assertSame( 403, $this->failure( fn() => $this->registrations->register( $event, $this->person['h1'], $guest ) )->status );
		self::assertSame( 403, $this->failure( fn() => $this->registrations->register( $event, 0, $guest ) )->status );
	}

	/**
	 * Die Clubzugehörigkeit gilt in der Saison des Anlasses. Ein Anlass der geplanten Saison:
	 * h1 wechselt dann zu Wollishofen, Charlotte Chef bleibt TK-Chefin von Seefeld.
	 */
	public function test_club_membership_is_taken_from_the_season_of_the_event(): void {
		$planned = $this->seeder->season( '2027-28', 'planned' );
		$this->seeder->register( $this->id['h1'], $planned, $this->id['club_away'], 9 );
		$this->seeder->register( $this->id['h2'], $planned, $this->id['club_home'], 9 );
		$this->seeder->role( $planned, $this->id['club_home'], $this->person['chef_home'], 'tk_chef' );
		$this->seeder->role( $planned, $this->id['club_away'], $this->person['chef_away'], 'tk_chef' );
		$event = $this->event( array( 'season_id' => (string) $planned ) );

		self::assertSame( 403, $this->failure( fn() => $this->registrations->register( $event, $this->person['h1'], $this->actor['chef_home'] ) )->status );
		self::assertSame( 'registered', $this->registrations->register( $event, $this->person['h1'], $this->actor['chef_away'] )['status'] );
		self::assertSame( 'registered', $this->registrations->register( $event, $this->person['h2'], $this->actor['chef_home'] )['status'] );
		self::assertSame( array( $this->person['h2'] ), array_column( $this->registrations->club_players( $this->actor['chef_home'], $this->events->repository()->find( $event ) ), 'person_id' ) );
	}

	/**
	 * Die TK ändert die Saison des Anlasses, während die TK-Chefin an- oder abmeldet: In der neuen
	 * Saison ist der Spieler für einen anderen Club gemeldet. Geprüft wird deshalb unter der Sperre
	 * noch einmal.
	 */
	public function test_a_season_change_between_check_and_write_is_checked_again_under_the_lock(): void {
		$planned = $this->seeder->season( '2027-28', 'planned' );
		$this->seeder->register( $this->id['h1'], $planned, $this->id['club_away'], 10 );
		$this->seeder->role( $planned, $this->id['club_home'], $this->person['chef_home'], 'tk_chef' );
		$event   = $this->event();
		$move    = fn() => $this->db->update( 'event', array( 'season_id' => $planned ), array( 'id' => $event ) );
		$restore = fn() => $this->db->update( 'event', array( 'season_id' => $this->id['season'] ), array( 'id' => $event ) );

		$clock              = new TriggerClock( new FixedClock( '2026-11-10 12:00:00' ) );
		$service            = $this->registration_service( $clock, $this->listener );
		$clock->before_next = $move;
		self::assertSame( 403, $this->failure( fn() => $service->register( $event, $this->person['h1'], $this->actor['chef_home'] ) )->status );
		self::assertSame( array(), $this->list_of( $event ), 'nicht angemeldet' );

		$restore();
		$this->registrations->register( $event, $this->person['h1'], $this->actor['chef_home'] );
		$clock              = new TriggerClock( new FixedClock( '2026-11-10 12:00:00' ) );
		$service            = $this->registration_service( $clock, $this->listener );
		$clock->before_next = $move;
		self::assertSame( 403, $this->failure( fn() => $service->unregister( $event, $this->person['h1'], $this->actor['chef_home'] ) )->status );
		self::assertCount( 1, $this->list_of( $event ), 'nicht abgemeldet' );
	}

	public function test_without_a_season_the_active_season_decides(): void {
		$event = $this->event( array( 'season_id' => '' ) );

		self::assertSame( 'registered', $this->registrations->register( $event, $this->person['h1'], $this->actor['chef_home'] )['status'] );
		self::assertSame( 403, $this->failure( fn() => $this->registrations->register( $event, $this->person['a1'], $this->actor['chef_home'] ) )->status );
	}

	public function test_a_tk_chef_of_a_past_season_has_no_rights(): void {
		$this->db->update( 'season', array( 'status' => 'archived' ), array( 'id' => $this->id['season'] ) );
		$next = $this->seeder->season( '2027-28', 'active' );
		$this->seeder->register( $this->id['h1'], $next, $this->id['club_home'], 9 );
		$event = $this->event( array( 'season_id' => (string) $next ) );

		self::assertSame( 403, $this->failure( fn() => $this->registrations->register( $event, $this->person['h1'], $this->actor['chef_home'] ) )->status );
	}

	public function test_unknown_event_unknown_person_and_inactive_person(): void {
		$event = $this->event();
		$this->db->update( 'person', array( 'status' => 'inactive' ), array( 'id' => $this->person['h2'] ) );

		$no_event  = $this->failure( fn() => $this->registrations->register( $event + 1, $this->person['h1'], $this->actor['h1'] ) );
		$no_person = $this->failure( fn() => $this->registrations->register( $event, 99999, $this->actor['tk'] ) );
		$inactive  = $this->failure( fn() => $this->registrations->register( $event, $this->person['h2'], $this->actor['tk'] ) );

		self::assertSame( array( 404, 404, 409 ), array( $no_event->status, $no_person->status, $inactive->status ) );
		self::assertSame( 'Diese Person ist nicht mehr aktiv.', $inactive->getMessage() );
		self::assertSame( array(), $this->list_of( $event ) );
	}

	public function test_club_players_lists_active_players_of_the_managed_clubs_with_their_registration(): void {
		$event = $this->event( array( 'max_participants' => '1' ) );
		$this->register_all( $event, array( 'h2', 'h3', 'a1' ) );
		$this->db->update( 'person', array( 'status' => 'inactive' ), array( 'id' => $this->person['h4'] ) );
		$data = $this->events->repository()->find( $event );

		$players = $this->registrations->club_players( $this->actor['chef_home'], $data );

		self::assertSame(
			array(
				$this->person['h1'] => null,
				$this->person['h2'] => 'registered',
				$this->person['h3'] => 'waitlist',
			),
			array_column( $players, 'registration', 'person_id' )
		);
		self::assertSame( array( 'Seefeld' ), array_values( array_unique( array_column( $players, 'club' ) ) ) );
		self::assertSame( array(), $this->registrations->club_players( $this->actor['h1'], $data ), 'ohne Funktion keine Liste' );
		self::assertSame( array(), $this->registrations->club_players( $this->actor['tk_account'], $data ) );
	}

	/**
	 * Zwei gleichzeitige Anmeldungen für den letzten Platz: Die andere Verbindung hält die Sperre
	 * des Anlasses und hat den Platz vergeben, aber noch nicht festgeschrieben. Ohne die Sperre
	 * sähe diese Anmeldung den Platz als frei und trüge sich als Teilnehmerin ein. Mit der Sperre
	 * wartet sie; hier bricht sie nach einer Sekunde ab und schreibt nichts.
	 */
	public function test_a_concurrent_registration_waits_for_the_lock_of_the_event(): void {
		$event = $this->event( array( 'max_participants' => '1' ) );
		$other = self::another_connection();
		$other->pdo()->beginTransaction();
		$other->get_var( "SELECT id FROM {$other->table( 'event' )} WHERE id = %d FOR UPDATE", array( $event ) );
		$other->insert(
			'registration',
			array(
				'event_id'   => $event,
				'person_id'  => $this->person['h1'],
				'status'     => 'registered',
				'created_at' => '2026-11-10 11:59:59',
			)
		);
		$this->db->pdo()->exec( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$waited = null;
		try {
			$this->registrations->register( $event, $this->person['h2'], $this->actor['h2'] );
		} catch ( \PDOException $e ) {
			$waited = $e;
		} finally {
			$other->pdo()->commit();
			$this->db->pdo()->exec( 'SET SESSION innodb_lock_wait_timeout = DEFAULT' );
		}

		self::assertInstanceOf( \PDOException::class, $waited, 'die Anmeldung hätte auf die Sperre des Anlasses warten müssen' );
		self::assertStringContainsString( '1205 Lock wait timeout', $waited->getMessage() );
		self::assertSame( array( 'h1' => 'registered' ), $this->list_of( $event ), 'nichts geschrieben' );
		self::assertSame( array(), $this->listener->calls );
		self::assertSame( 'waitlist', $this->registrations->register( $event, $this->person['h2'], $this->actor['h2'] )['status'], 'nach der Freigabe: der Platz ist vergeben' );
		self::assertSame(
			array(
				'registered' => 1,
				'waitlist'   => 1,
			),
			$this->events->repository()->counts( $event )
		);
	}

	/**
	 * Die Uhr wird nach der Prüfung von Rechten und Frist und vor der Schreibtransaktion gelesen.
	 * Genau dann vergibt eine andere Person den letzten Platz. Gezählt wird erst unter der Sperre,
	 * die zweite Anmeldung kommt deshalb auf die Warteliste.
	 */
	public function test_the_place_is_counted_under_the_lock_not_before(): void {
		$event              = $this->event( array( 'max_participants' => '1' ) );
		$clock              = new TriggerClock( new FixedClock( '2026-11-10 12:00:00' ) );
		$service            = $this->registration_service( $clock, $this->listener );
		$other              = self::another_connection();
		$clock->before_next = function () use ( $other, $event ): void {
			$this->registration_service( new FixedClock( '2026-11-10 11:59:59' ), new RecordingListener(), $other )->register( $event, $this->person['h1'], $this->actor['h1'] );
		};

		$result = $service->register( $event, $this->person['h2'], $this->actor['h2'] );

		self::assertSame( 'waitlist', $result['status'] );
		self::assertSame(
			array(
				'h1' => 'registered',
				'h2' => 'waitlist',
			),
			$this->list_of( $event )
		);
	}

	/**
	 * Die TK sagt den Anlass genau zwischen Prüfung und Schreiben ab: Die Anmeldung liest den
	 * Anlass unter der Sperre neu und wird abgewiesen.
	 */
	public function test_a_cancellation_between_check_and_write_rejects_the_registration(): void {
		$event              = $this->event();
		$clock              = new TriggerClock( new FixedClock( '2026-11-10 12:00:00' ) );
		$service            = $this->registration_service( $clock, $this->listener );
		$clock->before_next = fn() => $this->events->cancel( $event, $this->actor['tk'] );

		$error = $this->failure( fn() => $service->register( $event, $this->person['h1'], $this->actor['h1'] ) );

		self::assertSame( 409, $error->status );
		self::assertSame( 'Der Anlass ist abgesagt.', $error->getMessage() );
		self::assertSame( array(), $this->list_of( $event ) );
		self::assertSame( array( 'cancelled' ), $this->listener->names(), 'keine Meldung über eine Anmeldung' );
	}

	/**
	 * Die Sperre ist die erste Abfrage jeder Schreibtransaktion; gezählt und geschrieben wird danach.
	 */
	public function test_every_write_locks_the_event_first(): void {
		$event = $this->event( array( 'max_participants' => '1' ) );
		$this->register_all( $event, array( 'h1', 'h2' ) );
		$lock = 'SELECT id, season_id, type, title, starts_at, ends_at, location, deadline, max_participants, post_id, status, created_by FROM ' . $this->db->table( 'event' ) . ' WHERE id = %d FOR UPDATE';

		$db = new RecordingDb( $this->db );
		$this->registration_service( $this->clock, $this->listener, $db )->register( $event, $this->person['h3'], $this->actor['h3'] );
		self::assertSame( $lock, $db->statements( true )[0], 'anmelden' );
		self::assertSame( array(), preg_grep( '/' . preg_quote( $this->db->table( 'registration' ), '/' ) . '/', $db->statements( false ) ), 'keine Abfrage der Anmeldungen vor der Sperre' );

		$db = new RecordingDb( $this->db );
		$this->registration_service( $this->clock, $this->listener, $db )->unregister( $event, $this->person['h1'], $this->actor['h1'] );
		self::assertSame( $lock, $db->statements( true )[0], 'abmelden' );
		self::assertSame( array(), preg_grep( '/' . preg_quote( $this->db->table( 'registration' ), '/' ) . '/', $db->statements( false ) ) );

		foreach ( array( 'update', 'close', 'reopen', 'cancel' ) as $action ) {
			$db      = new RecordingDb( $this->db );
			$service = $this->event_service( $this->listener, $db );
			'update' === $action ? $service->update( $event, $this->input( array( 'max_participants' => '2' ) ), $this->actor['tk'] ) : $service->$action( $event, $this->actor['tk'] );
			self::assertSame( $lock, $db->statements( true )[0], $action );
		}
	}
}
