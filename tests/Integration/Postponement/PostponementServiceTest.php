<?php
/**
 * Verschiebung gegen MariaDB: alle Übergänge, Rollen, Wirkung auf die Begegnung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Postponement;

use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Results\ResultException;
use TTLeague\Tests\Integration\Frontend\Seeder;

/**
 * Daten siehe PostponementTestCase. f1 ist am Dienstag, 10.11.2026, 20:00 angesetzt; die Uhr
 * steht auf Montag, 2.11.2026, 09:00 Ortszeit.
 */
final class PostponementServiceTest extends PostponementTestCase {

	public function test_a_side_requests_and_nothing_changes_until_the_other_side_answers(): void {
		$result = $this->request_f1();

		self::assertSame( 'requested', $result['status'] );
		self::assertSame( 'postpone', $result['kind'] );
		self::assertSame( '2026-11-10 20:00:00', $result['old_date'] );
		self::assertSame( '2026-11-17 20:00:00', $result['new_date'] );
		self::assertSame( 'Hanna Heim', $result['requested_by'] );
		self::assertSame( 'home', $result['requester_side'] );
		self::assertSame( '2026-11-02T08:00:00Z', $result['requested_at'] );
		self::assertFalse( $result['needs_approval'] );
		self::assertSame( 'scheduled', $result['fixture']['status'] );

		$row = $this->postponement( $result['id'] );
		self::assertSame( (string) $this->id['f1'], $row['fixture_id'] );
		self::assertSame( '101', $row['requested_by'] );
		self::assertSame( 'Die Halle ist an diesem Abend belegt.', $row['reason'] );
		self::assertSame( '2026-11-02 08:00:00', $row['created_at'], 'Zeitstempel in UTC' );
		self::assertNull( $row['accepted_by'] );
		self::assertNull( $row['approved_at'] );

		self::assertSame(
			array(
				'status'       => 'scheduled',
				'scheduled_at' => '2026-11-10 20:00:00',
			),
			$this->fixture()
		);
		self::assertSame( array( array( $result['id'], 'request', 101, $this->person['captain_h1'] ) ), $this->events->calls );
		self::assertSame( '1', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'audit_log' )} WHERE entity = 'postponement' AND entity_id = %d AND action = 'requested' AND user_id = 101", array( $result['id'] ) ) );
		self::assertSame( '0', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'fixture_event' )}" ) );
	}

	public function test_acceptance_within_the_limit_takes_effect_at_once(): void {
		$id = $this->request_f1( '2026-11-24 21:00' )['id'];
		$this->clock->advance( '+1 day' );

		$result = $this->postponements->accept( $id, $this->actor['captain_a1'] );

		self::assertSame( 'approved', $result['status'], 'genau 14 Tage: die Zustimmung genügt' );
		self::assertSame( 'Gustav Gast', $result['accepted_by'] );
		self::assertNull( $result['decided_by'] );
		self::assertSame(
			array(
				'status'       => 'postponed',
				'scheduled_at' => '2026-11-24 21:00:00',
			),
			$this->fixture()
		);
		$row = $this->postponement( $id );
		self::assertSame( array( '102', '2026-11-03 08:00:00', null, '2026-11-03 08:00:00' ), array( $row['accepted_by'], $row['responded_at'], $row['approved_by'], $row['approved_at'] ) );
		self::assertSame( '2026-11-10 20:00:00', $row['old_date'], 'der bisherige Termin bleibt im Antrag' );

		$event = (array) $this->db->get_row( "SELECT * FROM {$this->db->table( 'fixture_event' )} WHERE fixture_id = %d", array( $this->id['f1'] ) );
		self::assertSame( 'postponed', $event['type'] );
		self::assertSame( '102', $event['user_id'] );
		self::assertSame( (string) $this->person['captain_a1'], $event['person_id'] );
		self::assertSame( 'Neuer Termin: Di, 24.11.2026, 21:00 (bisher Di, 10.11.2026, 20:00). Die Halle ist an diesem Abend belegt.', $event['comment'] );
		self::assertSame(
			array(
				'action'          => 'postpone',
				'postponement_id' => $id,
				'old_date'        => '2026-11-10 20:00:00',
				'new_date'        => '2026-11-24 21:00:00',
			),
			json_decode( (string) $event['data_json'], true )
		);

		$audit = (array) $this->db->get_row( "SELECT * FROM {$this->db->table( 'audit_log' )} WHERE entity = 'fixture' AND entity_id = %d", array( $this->id['f1'] ) );
		self::assertSame( 'postponed', $audit['action'] );
		self::assertSame( '102', $audit['user_id'] );
		self::assertSame(
			array(
				'status'       => 'scheduled',
				'scheduled_at' => '2026-11-10 20:00:00',
			),
			json_decode( (string) $audit['before_json'], true )
		);
		self::assertSame( '2026-11-24 21:00:00', json_decode( (string) $audit['after_json'], true )['scheduled_at'] );
		self::assertSame( array( 'request', 'accept' ), array_column( $this->events->calls, 1 ) );
	}

	public function test_acceptance_beyond_the_limit_waits_for_the_tk(): void {
		$request = $this->request_f1( '2026-11-25 19:00' );
		self::assertTrue( $request['needs_approval'], '15 Tage nach dem bisherigen Termin' );

		$accepted = $this->postponements->accept( $request['id'], $this->actor['chef_away'] );

		self::assertSame( 'accepted', $accepted['status'] );
		self::assertSame(
			array(
				'status'       => 'scheduled',
				'scheduled_at' => '2026-11-10 20:00:00',
			),
			$this->fixture(),
			'bis zur Bewilligung gilt der bisherige Termin'
		);
		$row = $this->postponement( $request['id'] );
		self::assertSame( array( '107', null, null ), array( $row['accepted_by'], $row['approved_by'], $row['approved_at'] ) );
		self::assertSame( '0', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'fixture_event' )}" ) );

		self::expect_error( fn() => $this->postponements->decide( $request['id'], $this->actor['captain_h1'], array( 'action' => 'approve' ) ), 403 );
		self::expect_error( fn() => $this->postponements->withdraw( $request['id'], $this->actor['captain_h1'] ), 409 );

		$this->clock->advance( '+2 hours' );
		$approved = $this->postponements->decide(
			$request['id'],
			$this->actor['tk'],
			array(
				'action'  => 'approve',
				'comment' => 'In Ordnung.',
			)
		);

		self::assertSame( 'approved', $approved['status'] );
		self::assertSame( 'Tamara Kommission', $approved['decided_by'] );
		self::assertSame( 'In Ordnung.', $approved['response_note'] );
		self::assertSame(
			array(
				'status'       => 'postponed',
				'scheduled_at' => '2026-11-25 19:00:00',
			),
			$this->fixture()
		);
		$row = $this->postponement( $request['id'] );
		self::assertSame( array( '107', '103', '2026-11-02 10:00:00' ), array( $row['accepted_by'], $row['approved_by'], $row['approved_at'] ) );
		self::assertSame( '103', $this->db->get_var( "SELECT user_id FROM {$this->db->table( 'fixture_event' )} WHERE fixture_id = %d AND type = 'postponed'", array( $this->id['f1'] ) ) );
	}

	public function test_the_limit_comes_from_the_rules_of_the_season(): void {
		$this->seed( array( 'postponement_approval_days' => 7 ) );
		$this->clock         = new FixedClock( '2026-11-02 08:00:00' );
		$this->postponements = $this->postponement_service();

		$seven = $this->request_f1( '2026-11-17 22:00' );
		self::assertFalse( $seven['needs_approval'] );
		self::assertSame( 'approved', $this->postponements->accept( $seven['id'], $this->actor['captain_a1'] )['status'], 'genau 7 Tage' );

		// Jetzt am 17.11.; acht Tage später braucht die Bewilligung.
		$eight = $this->request_f1( '2026-11-25 20:00' );
		self::assertTrue( $eight['needs_approval'] );
		self::assertSame( 'accepted', $this->postponements->accept( $eight['id'], $this->actor['captain_a1'] )['status'] );
	}

	public function test_moving_a_fixture_forward_never_needs_approval(): void {
		// Zuerst weit nach hinten (TK), dann um Monate nach vorn.
		$this->postponements->request(
			$this->id['f1'],
			$this->actor['tk'],
			array(
				'new_date' => '2027-03-02 20:00',
				'reason'   => 'Hallensanierung.',
			)
		);

		$forward = $this->request_f1( '2026-11-05 20:00', 'captain_a1' );

		self::assertFalse( $forward['needs_approval'] );
		self::assertSame( 'away', $forward['requester_side'] );
		self::assertSame( 'approved', $this->postponements->accept( $forward['id'], $this->actor['captain_h1'] )['status'] );
		self::assertSame( '2026-11-05 20:00:00', $this->fixture()['scheduled_at'] );
	}

	public function test_the_tk_rejects_an_accepted_request_only_with_a_comment(): void {
		$id = $this->request_f1( '2026-12-15 20:00' )['id'];
		$this->postponements->accept( $id, $this->actor['captain_a1'] );

		$error = self::expect_error( fn() => $this->postponements->decide( $id, $this->actor['tk'], array( 'action' => 'reject' ) ), 422 );
		self::assertSame( array( 'comment' ), array_column( $error->errors, 'field' ) );
		self::expect_error(
			fn() => $this->postponements->decide(
				$id,
				$this->actor['tk'],
				array(
					'action'  => 'reject',
					'comment' => '   ',
				)
			),
			422
		);
		self::expect_error( fn() => $this->postponements->decide( $id, $this->actor['tk'], array( 'action' => 'vertagen' ) ), 422 );
		self::assertSame( 'accepted', $this->postponement( $id )['status'] );

		$rejected = $this->postponements->decide(
			$id,
			$this->actor['tk_account'],
			array(
				'action'  => 'reject',
				'comment' => 'Zu spät in der Vorrunde.',
			)
		);

		self::assertSame( 'rejected', $rejected['status'] );
		self::assertSame( 'TK-Konto', $rejected['decided_by'], 'TK-Konto ohne Person' );
		self::assertSame( 'Zu spät in der Vorrunde.', $rejected['response_note'] );
		self::assertSame(
			array(
				'status'       => 'scheduled',
				'scheduled_at' => '2026-11-10 20:00:00',
			),
			$this->fixture()
		);
		self::expect_error( fn() => $this->postponements->decide( $id, $this->actor['tk'], array( 'action' => 'approve' ) ), 409 );
	}

	public function test_the_other_side_declines_with_an_optional_note(): void {
		$first = $this->request_f1()['id'];

		$declined = $this->postponements->decline( $first, $this->actor['captain_a1'], '  An dem Abend haben wir Training.  ' );

		self::assertSame( 'declined', $declined['status'] );
		self::assertSame( 'An dem Abend haben wir Training.', $declined['response_note'] );
		$row = $this->postponement( $first );
		self::assertSame( array( null, '2026-11-02 08:00:00', null ), array( $row['accepted_by'], $row['responded_at'], $row['approved_at'] ) );
		self::assertSame( '2026-11-10 20:00:00', $this->fixture()['scheduled_at'] );

		$second = $this->request_f1( '2026-11-18 20:00' )['id'];
		self::assertSame( 'declined', $this->postponements->decline( $second, $this->actor['chef_away'] )['status'], 'ohne Bemerkung' );
		self::assertNull( $this->postponement( $second )['response_note'] );

		$third = $this->request_f1( '2026-11-19 20:00' )['id'];
		$error = self::expect_error( fn() => $this->postponements->decline( $third, $this->actor['captain_a1'], str_repeat( 'x', 2001 ) ), 422 );
		self::assertSame( array( 'note' ), array_column( $error->errors, 'field' ) );
		self::expect_error( fn() => $this->postponements->accept( $first, $this->actor['captain_a1'] ), 409 );
	}

	public function test_the_requesting_side_withdraws(): void {
		$first = $this->request_f1()['id'];
		self::expect_error( fn() => $this->postponements->withdraw( $first, $this->actor['captain_a1'] ), 403 );
		self::expect_error( fn() => $this->postponements->withdraw( $first, $this->actor['tk'] ), 403 );

		self::assertSame( 'withdrawn', $this->postponements->withdraw( $first, $this->actor['captain_h1'] )['status'] );
		self::assertSame( '2026-11-10 20:00:00', $this->fixture()['scheduled_at'] );
		self::expect_error( fn() => $this->postponements->accept( $first, $this->actor['captain_a1'] ), 409 );
		self::expect_error( fn() => $this->postponements->withdraw( $first, $this->actor['captain_h1'] ), 409 );

		// Auch der TK-Chef des antragstellenden Clubs kann zurückziehen.
		$second = $this->request_f1()['id'];
		self::assertSame( 'withdrawn', $this->postponements->withdraw( $second, $this->actor['chef_home'] )['status'] );
	}

	public function test_only_the_other_side_answers(): void {
		$id = $this->request_f1()['id'];

		$own = self::expect_error( fn() => $this->postponements->accept( $id, $this->actor['captain_h1'] ), 403 );
		self::assertStringContainsString( 'nicht selbst beantworten', $own->getMessage() );
		self::expect_error( fn() => $this->postponements->accept( $id, $this->actor['chef_home'] ), 403 );
		self::expect_error( fn() => $this->postponements->decline( $id, $this->actor['chef_home'] ), 403 );
		self::expect_error( fn() => $this->postponements->accept( $id, $this->actor['captain_h2'] ), 403 );
		self::expect_error( fn() => $this->postponements->accept( $id, $this->actor['tk'] ), 403 );
		self::expect_error( fn() => $this->postponements->accept( $id, new \TTLeague\Results\Actor( 999, null, false ) ), 403 );
		self::assertSame( 'requested', $this->postponement( $id )['status'] );

		self::expect_error( fn() => $this->postponements->accept( 999999, $this->actor['captain_a1'] ), 404 );
		self::expect_error( fn() => $this->postponements->decide( 999999, $this->actor['tk'], array( 'action' => 'approve' ) ), 404 );
	}

	public function test_people_without_a_role_cannot_request(): void {
		$payload = array(
			'new_date' => '2026-11-17 20:00',
			'reason'   => 'Bitte verschieben.',
		);
		$nobody  = new \TTLeague\Results\Actor( 120, $this->person['unconnected'], false );

		self::expect_error( fn() => $this->postponements->request( $this->id['f1'], $nobody, $payload ), 403 );
		self::expect_error( fn() => $this->postponements->request( $this->id['f1'], $this->actor['captain_h2'], $payload ), 403 );
		self::expect_error( fn() => $this->postponements->request( $this->id['f1'], new \TTLeague\Results\Actor( 121, null, false ), $payload ), 403 );
		self::expect_error( fn() => $this->postponements->request( 999999, $this->actor['captain_h1'], $payload ), 404 );
		self::expect_error( fn() => $this->postponements->overview( $this->id['f1'], $nobody ), 403 );
		self::assertSame( '0', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'postponement' )}" ) );
	}

	public function test_the_tk_decides_in_place_of_the_other_side(): void {
		// Mehr als 14 Tage: Der Entscheid der TK schliesst die Bewilligung ein, nie «accepted».
		$first    = $this->request_f1( '2026-12-15 20:00' )['id'];
		$approved = $this->postponements->decide( $first, $this->actor['tk'], array( 'action' => 'approve' ) );

		self::assertSame( 'approved', $approved['status'] );
		$row = $this->postponement( $first );
		self::assertSame( array( null, null, '103' ), array( $row['accepted_by'], $row['responded_at'], $row['approved_by'] ) );
		self::assertSame(
			array(
				'status'       => 'postponed',
				'scheduled_at' => '2026-12-15 20:00:00',
			),
			$this->fixture()
		);

		$second = $this->request_f1( '2026-12-16 20:00', 'captain_a1' )['id'];
		self::expect_error( fn() => $this->postponements->decide( $second, $this->actor['tk'], array( 'action' => 'reject' ) ), 422 );
		$rejected = $this->postponements->decide(
			$second,
			$this->actor['tk'],
			array(
				'action'  => 'reject',
				'comment' => 'Einmal verschieben genügt.',
			)
		);
		self::assertSame( 'rejected', $rejected['status'] );
		self::assertSame( '2026-12-15 20:00:00', $this->fixture()['scheduled_at'] );
		self::assertSame( array( 'request', 'approve', 'request', 'reject' ), array_column( $this->events->calls, 1 ) );
	}

	public function test_a_request_of_the_tk_takes_effect_at_once(): void {
		$result = $this->postponements->request(
			$this->id['f1'],
			$this->actor['tk'],
			array(
				'new_date' => '2027-01-12T20:00',
				'reason'   => 'Die Halle wird saniert.',
			)
		);

		self::assertSame( 'approved', $result['status'] );
		self::assertSame( 'Tamara Kommission', $result['requested_by'] );
		self::assertSame( 'Tamara Kommission', $result['decided_by'] );
		self::assertNull( $result['requester_side'] );
		self::assertSame(
			array(
				'status'       => 'postponed',
				'scheduled_at' => '2027-01-12 20:00:00',
			),
			$this->fixture()
		);
		self::assertSame( 'postponed', $result['fixture']['status'] );
		$row = $this->postponement( $result['id'] );
		self::assertSame( array( '103', '103', '2026-11-02 08:00:00' ), array( $row['requested_by'], $row['approved_by'], $row['approved_at'] ) );
		self::assertSame( '1', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'fixture_event' )} WHERE fixture_id = %d AND type = 'postponed'", array( $this->id['f1'] ) ) );
		self::assertSame( array( array( $result['id'], 'request', 103, $this->person['tk'] ) ), $this->events->calls );
	}

	public function test_there_is_at_most_one_open_request_per_fixture(): void {
		$first = $this->request_f1( '2026-12-15 20:00' )['id'];

		$second = self::expect_error( fn() => $this->request_f1( '2026-11-18 20:00', 'captain_a1' ), 409 );
		self::assertStringContainsString( 'schon einen offenen Antrag', $second->getMessage() );
		self::expect_error( fn() => $this->request_f1( '2026-11-18 20:00', 'tk' ), 409 );

		// Auch «accepted» ist offen.
		$this->postponements->accept( $first, $this->actor['captain_a1'] );
		self::expect_error( fn() => $this->request_f1( '2026-11-18 20:00' ), 409 );
		self::assertSame( '1', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'postponement' )}" ) );

		// Andere Begegnungen sind nicht betroffen, und nach dem Entscheid ist wieder ein Antrag möglich.
		$derby = $this->postponements->request(
			$this->id['derby'],
			$this->actor['captain_h2'],
			array(
				'new_date' => '2026-11-19 20:00',
				'reason'   => 'Terminkollision.',
			)
		);
		self::assertSame( 'requested', $derby['status'] );
		$this->postponements->decide(
			$first,
			$this->actor['tk'],
			array(
				'action'  => 'reject',
				'comment' => 'Nein.',
			)
		);
		self::assertSame( 'requested', $this->request_f1( '2026-11-18 20:00' )['status'] );
	}

	public function test_a_request_waits_for_the_lock_of_the_fixture(): void {
		$other = self::another_connection();
		$other->pdo()->beginTransaction();
		$other->get_var( "SELECT id FROM {$other->table( 'fixture' )} WHERE id = %d FOR UPDATE", array( $this->id['f1'] ) );
		$this->db->pdo()->exec( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$waited = null;
		try {
			$this->request_f1();
		} catch ( \PDOException $e ) {
			$waited = $e;
		} finally {
			$other->pdo()->rollBack();
			$this->db->pdo()->exec( 'SET SESSION innodb_lock_wait_timeout = DEFAULT' );
		}

		self::assertInstanceOf( \PDOException::class, $waited, 'der Antrag hätte auf die Sperre der Begegnung warten müssen' );
		self::assertStringContainsString( '1205 Lock wait timeout', $waited->getMessage() );
		self::assertSame( '0', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'postponement' )}" ), 'nichts gespeichert' );
		self::assertSame( array(), $this->events->calls );
		self::assertSame( 'requested', $this->request_f1()['status'], 'nach der Freigabe' );
	}

	public function test_an_answer_waits_for_the_lock_of_the_fixture(): void {
		$id    = $this->request_f1()['id'];
		$other = self::another_connection();
		$other->pdo()->beginTransaction();
		$other->get_var( "SELECT id FROM {$other->table( 'fixture' )} WHERE id = %d FOR UPDATE", array( $this->id['f1'] ) );
		$this->db->pdo()->exec( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$waited = null;
		try {
			// Die Ablehnung schreibt nur in den Antrag: Ohne die Sperre käme sie sofort durch.
			$this->postponements->decline( $id, $this->actor['captain_a1'] );
		} catch ( \PDOException $e ) {
			$waited = $e;
		} finally {
			$other->pdo()->rollBack();
			$this->db->pdo()->exec( 'SET SESSION innodb_lock_wait_timeout = DEFAULT' );
		}

		self::assertInstanceOf( \PDOException::class, $waited, 'die Antwort hätte auf die Sperre der Begegnung warten müssen' );
		self::assertStringContainsString( '1205 Lock wait timeout', $waited->getMessage() );
		self::assertSame( 'requested', $this->postponement( $id )['status'] );
		self::assertSame( 'approved', $this->postponements->accept( $id, $this->actor['captain_a1'] )['status'], 'nach der Freigabe' );
	}

	public function test_in_a_club_derby_the_requester_does_not_accept_the_own_request(): void {
		$payload = array(
			'new_date' => '2026-11-19 20:00',
			'reason'   => 'Beide Mannschaften spielen am selben Abend.',
		);
		// Die TK-Chefin von Seefeld vertritt beide Seiten.
		$first = $this->postponements->request( $this->id['derby'], $this->actor['chef_home'], $payload );
		self::assertNull( $first['requester_side'], 'beide Seiten' );

		$own = self::expect_error( fn() => $this->postponements->accept( $first['id'], $this->actor['chef_home'] ), 403 );
		self::assertStringContainsString( 'nicht selbst beantworten', $own->getMessage() );
		self::expect_error( fn() => $this->postponements->decline( $first['id'], $this->actor['chef_home'] ), 403 );
		self::assertSame( 'requested', $this->postponement( $first['id'] )['status'] );
		self::assertSame( 'approved', $this->postponements->accept( $first['id'], $this->actor['captain_h2'] )['status'], 'ein Captain stimmt zu' );
		self::assertSame( '2026-11-19 20:00:00', $this->fixture( 'derby' )['scheduled_at'] );

		// Ein Captain beantragt: Der eigene zweite Captain antwortet nicht, die TK-Chefin und der andere Captain schon.
		$second = $this->postponements->request( $this->id['derby'], $this->actor['captain_h1'], array( 'new_date' => '2026-11-20 20:00' ) + $payload );
		self::assertSame( 'home', $second['requester_side'] );
		self::expect_error( fn() => $this->postponements->accept( $second['id'], $this->actor['captain_h1'] ), 403 );
		self::assertSame( 'approved', $this->postponements->accept( $second['id'], $this->actor['chef_home'] )['status'] );

		$third = $this->postponements->request( $this->id['derby'], $this->actor['captain_h2'], array( 'new_date' => '2026-11-21 14:00' ) + $payload );
		self::assertSame( 'away', $third['requester_side'] );
		self::assertSame( 'declined', $this->postponements->decline( $third['id'], $this->actor['captain_h1'] )['status'] );
	}

	public function test_only_open_fixtures_can_be_rescheduled(): void {
		$id = $this->request_f1()['id'];
		// Die Heimseite erfasst inzwischen das Resultat (Uhr nach der Anspielzeit).
		$this->clock = new FixedClock( '2026-11-10 21:30:00' );
		$this->service()->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );
		$this->postponements = $this->postponement_service();

		$late = self::expect_error( fn() => $this->postponements->accept( $id, $this->actor['captain_a1'] ), 409 );
		self::assertStringContainsString( 'schon ein Resultat', $late->getMessage() );
		self::expect_error( fn() => $this->postponements->decide( $id, $this->actor['tk'], array( 'action' => 'approve' ) ), 409 );
		self::assertSame( 'requested', $this->postponement( $id )['status'] );
		self::assertSame( 'provisional', $this->fixture()['status'] );

		$overview = $this->postponements->overview( $this->id['f1'], $this->actor['captain_a1'] );
		self::assertFalse( $overview['can']['accept'] );
		self::assertTrue( $overview['can']['decline'], 'aufräumen bleibt möglich' );
		self::assertSame( 'declined', $this->postponements->decline( $id, $this->actor['captain_a1'] )['status'] );

		self::expect_error( fn() => $this->request_f1( '2026-11-24 20:00' ), 409 );
		self::expect_error( fn() => $this->request_f1( '2026-11-24 20:00', 'tk' ), 409 );
		foreach ( array( 'confirmed', 'disputed', 'final', 'forfeit', 'cancelled' ) as $status ) {
			$this->db->update( 'fixture', array( 'status' => $status ), array( 'id' => $this->id['f1'] ) );
			self::expect_error( fn() => $this->request_f1( '2026-11-24 20:00', 'tk' ), 409 );
		}
		self::assertFalse( $this->postponements->overview( $this->id['f1'], $this->actor['tk'] )['can']['request'] );
	}

	public function test_a_postponed_fixture_can_be_postponed_again_and_entered_normally(): void {
		$first = $this->request_f1( '2026-11-17 20:00' )['id'];
		$this->postponements->accept( $first, $this->actor['captain_a1'] );
		self::assertSame( 'postponed', $this->fixture()['status'] );

		// Zweite Verschiebung: Der bisherige Termin ist jetzt der 17.11.
		$second = $this->request_f1( '2026-12-01 20:00', 'captain_a1' );
		self::assertSame( '2026-11-17 20:00:00', $second['old_date'] );
		self::assertFalse( $second['needs_approval'], 'genau 14 Tage nach dem 17.11.' );
		$this->postponements->accept( $second['id'], $this->actor['captain_h1'] );
		self::assertSame(
			array(
				'status'       => 'postponed',
				'scheduled_at' => '2026-12-01 20:00:00',
			),
			$this->fixture()
		);

		// Nach dem Spiel erfasst die Heimseite wie gewohnt, die Gastseite bestätigt.
		$this->clock = new FixedClock( '2026-12-01 21:30:00' );
		$entered     = $this->service()->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );
		self::assertSame( 'provisional', $entered['status'] );
		self::assertSame( array(), $entered['warnings'], 'Fristen rechnen ab dem neuen Termin' );
		self::assertSame( '2026-12-01 20:00:00', $this->db->get_var( "SELECT MIN(played_at) FROM {$this->db->table( 'game' )} WHERE fixture_id = %d", array( $this->id['f1'] ) ) );
		self::assertSame( 'confirmed', $this->service()->confirm( $this->id['f1'], $this->actor['captain_a1'] )['status'] );
		self::assertSame( array( 'postponed', 'postponed', 'entered', 'confirmed' ), array_column( $this->repo->events( $this->id['f1'] ), 'type' ) );
	}

	public function test_planned_and_archived_seasons_are_closed_even_for_the_tk(): void {
		$s       = new Seeder( $this->db );
		$payload = array(
			'new_date' => '2027-09-14 20:00',
			'reason'   => 'Spielplan anpassen.',
		);
		foreach ( array(
			'planned'  => array( '2027-28', '2027-09-07 20:00:00' ),
			'archived' => array( '2025-26', '2025-11-11 20:00:00' ),
		) as $status => [ $slug, $date ] ) {
			$season  = $s->season( $slug, $status );
			$group   = $s->competition( $season, 'MM', 'Gruppe B', 'B', 1 );
			$home    = $s->team( $season, 1, 1, 'Seefeld 1', $group );
			$away    = $s->team( $season, 2, 1, 'Wollishofen 1', $group );
			$fixture = $s->fixture(
				$group,
				array(
					'home_team_id' => $home,
					'away_team_id' => $away,
					'scheduled_at' => $date,
				)
			);
			$s->role( $season, 1, $this->person['captain_h1'], 'captain', $home );

			$tk = self::expect_error( fn() => $this->postponement_service()->request( $fixture, $this->actor['tk'], $payload ), 409 );
			self::assertStringContainsString( 'planned' === $status ? 'noch nicht freigegeben' : 'abgeschlossen', $tk->getMessage() );
			self::expect_error( fn() => $this->postponement_service()->request( $fixture, $this->actor['captain_h1'], $payload ), 403 );
			self::assertFalse( $this->postponement_service()->overview( $fixture, $this->actor['tk'] )['can']['request'] );
			self::assertSame( $date, $this->db->get_var( "SELECT scheduled_at FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $fixture ) ) );
		}
		self::assertSame( '0', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'postponement' )}" ) );
	}

	public function test_an_open_request_cannot_take_effect_after_the_season_is_archived(): void {
		$id = $this->request_f1( '2026-12-15 20:00' )['id'];
		$this->postponements->accept( $id, $this->actor['captain_a1'] );
		$this->db->update( 'season', array( 'status' => 'archived' ), array( 'id' => $this->id['season'] ) );
		$service = $this->postponement_service();

		self::expect_error( fn() => $service->decide( $id, $this->actor['tk'], array( 'action' => 'approve' ) ), 409 );
		self::assertSame( '2026-11-10 20:00:00', $this->fixture()['scheduled_at'] );
		self::assertSame(
			'rejected',
			$service->decide(
				$id,
				$this->actor['tk'],
				array(
					'action'  => 'reject',
					'comment' => 'Saison abgeschlossen.',
				)
			)['status']
		);
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:list<string>}>
	 */
	public static function invalid_requests(): array {
		$reason = array( 'reason' => 'Die Halle ist belegt.' );
		return array(
			'ohne Termin'                    => array( $reason, array( 'new_date' ) ),
			'ohne Begründung'                => array( array( 'new_date' => '2026-11-17 20:00' ), array( 'reason' ) ),
			'beides fehlt'                   => array( array(), array( 'new_date', 'reason' ) ),
			'nur Datum'                      => array( array( 'new_date' => '2026-11-17' ) + $reason, array( 'new_date' ) ),
			'kein Datum'                     => array( array( 'new_date' => 'nächste Woche' ) + $reason, array( 'new_date' ) ),
			'Zahl statt Text'                => array( array( 'new_date' => 20261117 ) + $reason, array( 'new_date' ) ),
			'30. Februar'                    => array( array( 'new_date' => '2027-02-30 20:00' ) + $reason, array( 'new_date' ) ),
			'Stunde, die es nicht gibt'      => array( array( 'new_date' => '2027-03-28 02:30' ) + $reason, array( 'new_date' ) ),
			'25 Uhr'                         => array( array( 'new_date' => '2026-11-17 25:00' ) + $reason, array( 'new_date' ) ),
			'bisheriger Termin'              => array( array( 'new_date' => '2026-11-10 20:00' ) + $reason, array( 'new_date' ) ),
			'in der Vergangenheit'           => array( array( 'new_date' => '2026-11-01 20:00' ) + $reason, array( 'new_date' ) ),
			'genau jetzt'                    => array( array( 'new_date' => '2026-11-02 09:00' ) + $reason, array( 'new_date' ) ),
			'nach dem Ende der Saison'       => array( array( 'new_date' => '2027-08-01 00:00' ) + $reason, array( 'new_date' ) ),
			'Begründung zu lang'             => array(
				array(
					'new_date' => '2026-11-17 20:00',
					'reason'   => str_repeat( 'x', 2001 ),
				),
				array( 'reason' ),
			),
			'Begründung nur aus Leerzeichen' => array(
				array(
					'new_date' => '2026-11-17 20:00',
					'reason'   => "  \n ",
				),
				array( 'reason' ),
			),
		);
	}

	/**
	 * @param array<string,mixed> $payload Eingabe.
	 * @param list<string>        $fields  Erwartete Feldfehler.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalid_requests' )]
	public function test_invalid_requests_are_refused_with_field_errors( array $payload, array $fields ): void {
		$error = self::expect_error( fn() => $this->postponements->request( $this->id['f1'], $this->actor['captain_h1'], $payload ), 422 );

		self::assertSame( ResultException::INVALID, $error->error_code );
		self::assertSame( $fields, array_column( $error->errors, 'field' ) );
		self::assertSame( '0', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'postponement' )}" ) );
		self::assertSame( array(), $this->events->calls );
	}

	public function test_the_new_date_is_local_time_within_the_season_and_in_the_future(): void {
		// Jetzt ist es 09:00 Ortszeit: 09:01 liegt in der Zukunft, obwohl es in UTC erst 08:00 ist.
		$soon = $this->request_f1( '2026-11-02 09:01' );
		self::assertSame( '2026-11-02 09:01:00', $soon['new_date'] );
		$this->postponements->withdraw( $soon['id'], $this->actor['captain_h1'] );

		// Letzter Tag der Saison (31.7.2027) bis kurz vor Mitternacht; die TK verschiebt direkt.
		$last = $this->request_f1( '2027-07-31T23:59:30', 'tk' );
		self::assertSame( '2027-07-31 23:59:00', $last['new_date'], 'Sekunden entfallen' );
		self::assertSame( '2027-07-31 23:59:00', $this->fixture()['scheduled_at'] );

		// Vor dem Beginn der Saison: nur mit einer Uhr im Juli prüfbar.
		$this->clock = new FixedClock( '2026-07-20 08:00:00' );
		$before      = self::expect_error(
			fn() => $this->postponement_service()->request(
				$this->id['derby'],
				$this->actor['tk'],
				array(
					'new_date' => '2026-07-31 20:00',
					'reason'   => 'Vorziehen.',
				)
			),
			422
		);
		self::assertStringContainsString( '1.8.2026 bis 31.7.2027', $before->errors[0]['message'] );
		self::assertSame(
			'approved',
			$this->postponement_service()->request(
				$this->id['derby'],
				$this->actor['tk'],
				array(
					'new_date' => '2026-08-01 00:00',
					'reason'   => 'Vorziehen.',
				)
			)['status'],
			'erster Tag der Saison'
		);
	}

	public function test_a_request_is_void_once_the_date_of_the_fixture_has_changed(): void {
		$id = $this->request_f1()['id'];
		// Jemand anderes ändert den Termin (z. B. ein anderes Werkzeug der TK).
		$this->db->update( 'fixture', array( 'scheduled_at' => '2026-11-11 20:00:00' ), array( 'id' => $this->id['f1'] ) );

		$error = self::expect_error( fn() => $this->postponements->accept( $id, $this->actor['captain_a1'] ), 409 );
		self::assertStringContainsString( 'seit dem Antrag geändert', $error->getMessage() );
		self::expect_error( fn() => $this->postponements->decide( $id, $this->actor['tk'], array( 'action' => 'approve' ) ), 409 );
		self::assertSame( '2026-11-11 20:00:00', $this->fixture()['scheduled_at'] );
		self::assertFalse( $this->postponements->overview( $this->id['f1'], $this->actor['captain_a1'] )['can']['accept'] );
		self::assertSame( 'withdrawn', $this->postponements->withdraw( $id, $this->actor['captain_h1'] )['status'] );
	}

	public function test_the_other_side_cannot_accept_a_date_that_has_passed(): void {
		$id = $this->request_f1( '2026-11-05 20:00' )['id'];

		$this->clock->advance( '+3 days 10 hours 59 minutes' ); // 5.11., 19:59 Ortszeit.
		self::assertTrue( $this->postponements->overview( $this->id['f1'], $this->actor['captain_a1'] )['can']['accept'] );
		$this->clock->advance( '+1 minute' ); // 20:00: Der Termin ist erreicht.
		$overview = $this->postponements->overview( $this->id['f1'], $this->actor['captain_a1'] );
		self::assertFalse( $overview['can']['accept'] );
		self::assertTrue( $overview['can']['decline'] );

		$error = self::expect_error( fn() => $this->postponements->accept( $id, $this->actor['captain_a1'] ), 409 );
		self::assertStringContainsString( 'schon vorbei', $error->getMessage() );
		self::assertSame( '2026-11-10 20:00:00', $this->fixture()['scheduled_at'] );

		// Die TK kann einen vergangenen Termin gutheissen, etwa wenn schon gespielt wurde.
		self::assertSame( 'approved', $this->postponements->decide( $id, $this->actor['tk'], array( 'action' => 'approve' ) )['status'] );
		self::assertSame( '2026-11-05 20:00:00', $this->fixture()['scheduled_at'] );
	}

	public function test_overview_shows_what_each_person_may_do(): void {
		$none = array(
			'request'  => false,
			'accept'   => false,
			'decline'  => false,
			'withdraw' => false,
			'approve'  => false,
			'reject'   => false,
		);
		$f1   = $this->id['f1'];
		$only = static fn( string ...$actions ): array => array_replace( $none, array_fill_keys( $actions, true ) );

		$before = $this->postponements->overview( $f1, $this->actor['captain_a1'] );
		self::assertNull( $before['open'] );
		self::assertSame( array(), $before['history'] );
		self::assertSame( 'postpone', $before['kind'] );
		self::assertSame( $only( 'request' ), $before['can'] );
		self::assertSame(
			array(
				'approval_days' => 14,
				'season_start'  => '2026-08-01',
				'season_end'    => '2027-07-31',
			),
			$before['rules']
		);
		self::assertSame( array( $f1, 'MM', 'scheduled', '2026-11-10 20:00:00', 'Gruppe B', 'Seefeld 1', 'Wollishofen 1', null ), array_values( $before['fixture'] ) );

		$id = $this->request_f1( '2026-12-15 20:00' )['id'];
		self::assertSame( $id, $this->postponements->overview( $f1, $this->actor['captain_h1'] )['open']['id'] );
		self::assertSame( $only( 'withdraw' ), $this->postponements->overview( $f1, $this->actor['captain_h1'] )['can'] );
		self::assertSame( $only( 'withdraw' ), $this->postponements->overview( $f1, $this->actor['chef_home'] )['can'] );
		self::assertSame( $only( 'accept', 'decline' ), $this->postponements->overview( $f1, $this->actor['captain_a1'] )['can'] );
		self::assertSame( $only( 'approve', 'reject' ), $this->postponements->overview( $f1, $this->actor['tk'] )['can'] );
		self::assertSame( $only( 'approve', 'reject' ), $this->postponements->overview( $f1, $this->actor['tk_account'] )['can'] );

		$this->postponements->accept( $id, $this->actor['captain_a1'] );
		$accepted = $this->postponements->overview( $f1, $this->actor['captain_h1'] );
		self::assertSame( 'accepted', $accepted['open']['status'] );
		self::assertTrue( $accepted['open']['needs_approval'] );
		self::assertSame( $none, $accepted['can'] );
		self::assertSame( $none, $this->postponements->overview( $f1, $this->actor['captain_a1'] )['can'] );
		self::assertSame( $only( 'approve', 'reject' ), $this->postponements->overview( $f1, $this->actor['tk'] )['can'] );

		$this->postponements->decide(
			$id,
			$this->actor['tk'],
			array(
				'action'  => 'reject',
				'comment' => 'Zu spät.',
			)
		);
		$after = $this->postponements->overview( $f1, $this->actor['captain_h1'] );
		self::assertNull( $after['open'] );
		self::assertTrue( $after['can']['request'] );
		self::assertSame( array( $id ), array_column( $after['history'], 'id' ) );
		self::assertSame( 'rejected', $after['history'][0]['status'] );
		self::assertSame( 'von der TK abgelehnt', $after['history'][0]['status_label'] );
		self::assertFalse( $after['history'][0]['needs_approval'] );
	}

	public function test_my_area_lists_open_requests_and_answers_first(): void {
		// Eigener Antrag für f1, Antrag der Gegenseite für eine zweite Begegnung.
		$s      = new Seeder( $this->db );
		$row    = (array) $this->db->get_row( "SELECT competition_id, home_team_id, away_team_id FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $this->id['f1'] ) );
		$return = $s->fixture(
			(int) $row['competition_id'],
			array(
				'home_team_id' => (int) $row['away_team_id'],
				'away_team_id' => (int) $row['home_team_id'],
				'scheduled_at' => '2027-02-09 20:00:00',
			)
		);
		$own    = $this->request_f1()['id'];
		$theirs = $this->postponements->request(
			$return,
			$this->actor['captain_a1'],
			array(
				'new_date' => '2027-02-10 20:00',
				'reason'   => 'Fasnacht.',
			)
		)['id'];
		$this->postponements->request(
			$this->id['derby'],
			$this->actor['captain_h2'],
			array(
				'new_date' => '2026-11-19 20:00',
				'reason'   => 'Terminkollision.',
			)
		);

		$mine = $this->postponements->mine( $this->actor['captain_h1'] );
		self::assertSame( array( $theirs, $this->postponement_id( 'derby' ), $own ), array_map( static fn( array $item ): int => $item['postponement']['id'], $mine['requests'] ), 'zuerst, was zu beantworten ist' );
		self::assertTrue( $mine['requests'][0]['can']['accept'] );
		self::assertTrue( $mine['requests'][2]['can']['withdraw'] );
		self::assertSame( 'Wollishofen 1', $mine['requests'][0]['fixture']['home'] );
		self::assertSame( array(), $mine['unscheduled'] );

		$away = $this->postponements->mine( $this->actor['captain_a1'] );
		self::assertSame( array( $own, $theirs ), array_map( static fn( array $item ): int => $item['postponement']['id'], $away['requests'] ), 'das Clubduell betrifft Wollishofen nicht' );

		self::assertSame(
			array(
				'requests'    => array(),
				'unscheduled' => array(),
			),
			$this->postponements->mine( new \TTLeague\Results\Actor( 120, $this->person['unconnected'], false ) )
		);
		self::assertSame(
			array(
				'requests'    => array(),
				'unscheduled' => array(),
			),
			$this->postponements->mine( $this->actor['tk_account'] )
		);
	}

	public function test_the_tk_overview_lists_approvals_and_requests_unanswered_for_seven_days(): void {
		$waiting = $this->request_f1( '2026-12-15 20:00' )['id'];
		$derby   = $this->postponements->request(
			$this->id['derby'],
			$this->actor['captain_h2'],
			array(
				'new_date' => '2026-12-16 20:00',
				'reason'   => 'Terminkollision.',
			)
		)['id'];
		$this->postponements->accept( $derby, $this->actor['captain_h1'] );

		$lists = $this->postponements->for_tk( $this->actor['tk'] );
		self::assertSame( array( $derby ), array_map( static fn( array $item ): int => $item['postponement']['id'], $lists['to_approve'] ) );
		self::assertSame( array(), $lists['unanswered'], 'ein neuer Antrag gehört noch nicht zur TK' );
		self::assertTrue( $lists['to_approve'][0]['can']['approve'] );

		$this->clock->advance( '+7 days -1 second' );
		self::assertSame( array(), $this->postponements->for_tk( $this->actor['tk'] )['unanswered'], 'eine Sekunde vor sieben Tagen' );
		$this->clock->advance( '+1 second' );
		$lists = $this->postponements->for_tk( $this->actor['tk'] );
		self::assertSame( array( $waiting ), array_map( static fn( array $item ): int => $item['postponement']['id'], $lists['unanswered'] ), 'genau sieben Tage' );
		self::assertTrue( $lists['unanswered'][0]['can']['approve'] );
		self::assertTrue( $lists['unanswered'][0]['can']['reject'] );
		self::assertCount( 1, $lists['to_approve'] );

		$this->postponements->decline( $waiting, $this->actor['captain_a1'] );
		$this->postponements->decide( $derby, $this->actor['tk'], array( 'action' => 'approve' ) );
		self::assertSame(
			array(
				'to_approve' => array(),
				'unanswered' => array(),
			),
			$this->postponements->for_tk( $this->actor['tk'] )
		);
	}

	public function test_the_tk_lists_are_empty_for_everybody_else(): void {
		$id = $this->request_f1( '2026-12-15 20:00' )['id'];
		$this->postponements->accept( $id, $this->actor['captain_a1'] );

		self::assertCount( 1, $this->postponements->for_tk( $this->actor['tk'] )['to_approve'] );
		self::assertSame(
			array(
				'to_approve' => array(),
				'unanswered' => array(),
			),
			$this->postponements->for_tk( $this->actor['captain_h1'] )
		);
	}

	private function postponement_id( string $fixture ): int {
		return (int) $this->db->get_var( "SELECT MAX(id) FROM {$this->db->table( 'postponement' )} WHERE fixture_id = %d", array( $this->id[ $fixture ] ) );
	}
}
