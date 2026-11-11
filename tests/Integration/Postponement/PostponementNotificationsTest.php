<?php
/**
 * Benachrichtigungen der Verschiebung: Empfänger, Texte, Erinnerung, Fristen nach dem neuen Termin.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Postponement;

use TTLeague\Accounts\Preferences;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Notifications\Deadlines;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\FixtureQueries;
use TTLeague\Notifications\NotificationLog;
use TTLeague\Notifications\Notifier;
use TTLeague\Postponement\PostponementMessages as M;

/**
 * Empfänger wie in der Erfassung: Heimseite von f1 sind Hanna Heim (Captain) und Charlotte Chef
 * (TK-Chefin Seefeld), Gastseite Gustav Gast und Wilma Gastchef. Ein zweiter Captain von
 * Seefeld 1 hat keine gültige Adresse, einer ist inaktiv. TK: Tamara Kommission, das TK-Konto
 * 108 und Anton Verwalter. Der Dienst meldet seine Ereignisse hier direkt an die
 * Benachrichtigungen.
 */
final class PostponementNotificationsTest extends PostponementTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->postponements = $this->postponement_service( $this->postponement_notifications() );
	}

	/**
	 * @return list<string>
	 */
	private function home(): array {
		return array( $this->p( 'captain_h1' ), $this->p( 'chef_home' ) );
	}

	/**
	 * @return list<string>
	 */
	private function away(): array {
		return array( $this->p( 'captain_a1' ), $this->p( 'chef_away' ) );
	}

	/**
	 * @return list<string>
	 */
	private function tk(): array {
		return array( $this->p( 'tk' ), 'u108', $this->p( 'admin' ) );
	}

	/**
	 * Letzte Nachricht einer Art.
	 *
	 * @return array<string,mixed>
	 */
	private function last( string $event ): array {
		$sent = array_values( array_filter( $this->email->sent, static fn( array $s ): bool => $s['event'] === $event ) );
		self::assertNotSame( array(), $sent, 'keine Nachricht ' . $event );
		return $sent[ count( $sent ) - 1 ];
	}

	public function test_the_request_goes_to_the_other_side(): void {
		$id = $this->request_f1()['id'];

		self::assertSame( $this->away(), $this->email->recipients( M::REQUEST ) );
		self::assertCount( 2, $this->email->sent, 'weder die eigene Seite noch die TK' );
		$sent = $this->last( M::REQUEST );
		self::assertSame( array( 'postponement', $id, '' ), array( $sent['ref_type'], $sent['ref_id'], $sent['ref_key'] ) );
		self::assertSame( EventTypes::POSTPONEMENT, $sent['notification']->type );
		self::assertSame( 'Verschiebung beantragt: Seefeld 1 – Wollishofen 1', $sent['subject'] );
		self::assertSame(
			array(
				'Hanna Heim möchte die Begegnung Seefeld 1 – Wollishofen 1 (Meisterschaft, Gruppe B) verschieben: von Di, 10.11.2026, 20:00 auf Di, 17.11.2026, 20:00.',
				'Begründung: «Die Halle ist an diesem Abend belegt.»',
				'Bitte stimme zu oder lehne ab. Mit deiner Zustimmung gilt der neue Termin.',
			),
			$sent['notification']->paragraphs
		);
		self::assertSame( 'https://liga.example.org/erfassen/?begegnung=' . $this->id['f1'], $sent['notification']->action['url'] );
		self::assertSame( 'ttleague-fixture-' . $this->id['f1'], $sent['notification']->push['tag'] );
	}

	public function test_the_request_says_when_the_tk_must_approve(): void {
		$this->request_f1( '2026-12-15 20:00', 'chef_away' );

		self::assertSame( $this->home(), $this->email->recipients( M::REQUEST ) );
		$paragraphs = $this->last( M::REQUEST )['notification']->paragraphs;
		self::assertStringStartsWith( 'Wilma Gastchef möchte die Begegnung', $paragraphs[0] );
		self::assertSame( 'Bitte stimme zu oder lehne ab. Nach deiner Zustimmung entscheidet die TK über die Bewilligung.', $paragraphs[2] );
	}

	public function test_acceptance_goes_to_the_requester_and_to_the_tk_for_information(): void {
		$id                = $this->request_f1()['id'];
		$this->email->sent = array();

		$this->postponements->accept( $id, $this->actor['captain_a1'] );

		self::assertSame( $this->home(), $this->email->recipients( M::ACCEPTED ) );
		self::assertSame( $this->tk(), $this->email->recipients( M::TK_INFO ) );
		self::assertCount( 5, $this->email->sent, 'nicht an die Gastseite, die zugestimmt hat' );
		$accepted = $this->last( M::ACCEPTED );
		self::assertSame( 'Verschiebung vereinbart: Seefeld 1 – Wollishofen 1', $accepted['subject'] );
		self::assertSame(
			array(
				'Gustav Gast hat zugestimmt. Die Begegnung Seefeld 1 – Wollishofen 1 (Meisterschaft, Gruppe B) findet am Di, 17.11.2026, 20:00 statt.',
				'Bisheriger Termin: Di, 10.11.2026, 20:00.',
			),
			$accepted['notification']->paragraphs
		);
		$info = $this->last( M::TK_INFO );
		self::assertSame( EventTypes::POSTPONEMENT_TK, $info['notification']->type );
		self::assertSame( 'Zur Kenntnis: Verschiebung, Seefeld 1 – Wollishofen 1', $info['subject'] );
		self::assertSame( 'Eine Bewilligung ist nicht nötig.', $info['notification']->paragraphs[2] );
		self::assertStringContainsString( 'von Di, 10.11.2026, 20:00 auf Di, 17.11.2026, 20:00', $info['notification']->paragraphs[0] );
	}

	public function test_acceptance_beyond_the_limit_asks_the_tk_for_approval_and_the_decision_goes_to_both_sides(): void {
		$id = $this->request_f1( '2026-12-15 20:00' )['id'];
		$this->postponements->accept( $id, $this->actor['chef_away'] );

		self::assertSame( $this->home(), $this->email->recipients( M::ACCEPTED ) );
		self::assertSame( $this->tk(), $this->email->recipients( M::TK_APPROVAL ) );
		self::assertSame( array(), $this->email->recipients( M::TK_INFO ) );
		$accepted = $this->last( M::ACCEPTED );
		self::assertSame( 'Zustimmung, Bewilligung der TK offen: Seefeld 1 – Wollishofen 1', $accepted['subject'] );
		self::assertSame( 'Die TK muss die Verschiebung noch bewilligen. Bis dahin gilt der bisherige Termin Di, 10.11.2026, 20:00.', $accepted['notification']->paragraphs[1] );
		$approval = $this->last( M::TK_APPROVAL );
		self::assertSame( EventTypes::POSTPONEMENT_TK, $approval['notification']->type );
		self::assertSame( 'Verschiebung zur Bewilligung: Seefeld 1 – Wollishofen 1', $approval['subject'] );
		self::assertSame( 'Der neue Termin liegt mehr als 14 Tage nach dem bisherigen. Deshalb entscheidet die TK.', $approval['notification']->paragraphs[2] );
		self::assertSame( 'Entscheiden', $approval['notification']->action['label'] );

		$this->email->sent = array();
		$this->postponements->decide(
			$id,
			$this->actor['tk'],
			array(
				'action'  => 'approve',
				'comment' => 'Ausnahmsweise.',
			)
		);

		self::assertSame( array_merge( $this->home(), $this->away() ), $this->email->recipients( M::DECIDED ) );
		self::assertCount( 4, $this->email->sent, 'die TK erhält ihren eigenen Entscheid nicht' );
		$decided = $this->last( M::DECIDED );
		self::assertSame( EventTypes::POSTPONEMENT, $decided['notification']->type );
		self::assertSame( 'Verschiebung bewilligt: Seefeld 1 – Wollishofen 1', $decided['subject'] );
		self::assertSame(
			array(
				'Die TK hat die Verschiebung der Begegnung Seefeld 1 – Wollishofen 1 (Meisterschaft, Gruppe B) bewilligt. Neuer Termin: Di, 15.12.2026, 20:00.',
				'Bisheriger Termin: Di, 10.11.2026, 20:00.',
				'Bemerkung der TK: «Ausnahmsweise.»',
			),
			$decided['notification']->paragraphs
		);
	}

	public function test_a_rejection_of_the_tk_goes_to_both_sides_with_the_comment(): void {
		$id                = $this->request_f1( '2026-12-15 20:00' )['id'];
		$this->email->sent = array();

		$this->postponements->decide(
			$id,
			$this->actor['tk_account'],
			array(
				'action'  => 'reject',
				'comment' => 'Zu spät in der Vorrunde.',
			)
		);

		self::assertSame( array_merge( $this->home(), $this->away() ), $this->email->recipients( M::DECIDED ) );
		$decided = $this->last( M::DECIDED );
		self::assertSame( 'Verschiebung von der TK abgelehnt: Seefeld 1 – Wollishofen 1', $decided['subject'] );
		self::assertSame(
			array(
				'Die TK hat den Antrag abgelehnt, die Begegnung Seefeld 1 – Wollishofen 1 (Meisterschaft, Gruppe B) auf Di, 15.12.2026, 20:00 zu verschieben. Es bleibt beim Termin Di, 10.11.2026, 20:00.',
				'Bemerkung der TK: «Zu spät in der Vorrunde.»',
			),
			$decided['notification']->paragraphs
		);
	}

	public function test_a_postponement_by_the_tk_goes_to_both_sides(): void {
		$this->postponements->request(
			$this->id['f1'],
			$this->actor['tk'],
			array(
				'new_date' => '2027-01-12 20:00',
				'reason'   => 'Die Halle wird saniert.',
			)
		);

		self::assertSame( array_merge( $this->home(), $this->away() ), $this->email->recipients( M::DECIDED ) );
		self::assertCount( 4, $this->email->sent );
		$decided = $this->last( M::DECIDED );
		self::assertSame( 'Verschoben durch die TK: Seefeld 1 – Wollishofen 1', $decided['subject'] );
		self::assertSame(
			array(
				'Die TK hat die Begegnung Seefeld 1 – Wollishofen 1 (Meisterschaft, Gruppe B) verschoben. Neuer Termin: Di, 12.1.2027, 20:00.',
				'Bisheriger Termin: Di, 10.11.2026, 20:00.',
				'Begründung: «Die Halle wird saniert.»',
			),
			$decided['notification']->paragraphs
		);
	}

	public function test_decline_and_withdrawal(): void {
		$first             = $this->request_f1()['id'];
		$this->email->sent = array();
		$this->postponements->decline( $first, $this->actor['captain_a1'], 'An dem Abend haben wir Training.' );

		self::assertSame( $this->home(), $this->email->recipients( M::DECLINED ) );
		self::assertCount( 2, $this->email->sent );
		self::assertSame(
			array(
				'Gustav Gast hat den Antrag abgelehnt, die Begegnung Seefeld 1 – Wollishofen 1 (Meisterschaft, Gruppe B) auf Di, 17.11.2026, 20:00 zu verschieben. Es bleibt beim Termin Di, 10.11.2026, 20:00.',
				'Bemerkung: «An dem Abend haben wir Training.»',
			),
			$this->last( M::DECLINED )['notification']->paragraphs
		);

		$second            = $this->request_f1( '2026-11-18 20:00' )['id'];
		$this->email->sent = array();
		$this->postponements->withdraw( $second, $this->actor['chef_home'] );

		self::assertSame( $this->away(), $this->email->recipients( M::WITHDRAWN ) );
		self::assertCount( 2, $this->email->sent );
		self::assertStringStartsWith( 'Charlotte Chef hat den Antrag zurückgezogen', $this->last( M::WITHDRAWN )['notification']->paragraphs[0] );
	}

	public function test_in_a_club_derby_nobody_is_told_about_the_own_action(): void {
		$payload = array(
			'new_date' => '2026-11-19 20:00',
			'reason'   => 'Beide Mannschaften spielen am selben Abend.',
		);
		// Captain von Seefeld 1 beantragt: Gegenseite sind der Captain von Seefeld 2 und die TK-Chefin.
		$id = $this->postponements->request( $this->id['derby'], $this->actor['captain_h1'], $payload )['id'];
		self::assertSame( array( $this->p( 'captain_h2' ), $this->p( 'chef_home' ) ), $this->email->recipients( M::REQUEST ) );

		// Die TK-Chefin stimmt zu: Die Antwort geht an den Captain von Seefeld 1, nicht an sie selbst.
		$this->postponements->accept( $id, $this->actor['chef_home'] );
		self::assertSame( array( $this->p( 'captain_h1' ) ), $this->email->recipients( M::ACCEPTED ) );

		// Die TK-Chefin beantragt selbst: Beide Captains sind Gegenseite.
		$this->email->sent = array();
		$this->postponements->request( $this->id['derby'], $this->actor['chef_home'], array( 'new_date' => '2026-11-20 20:00' ) + $payload );
		self::assertSame( array( $this->p( 'captain_h2' ), $this->p( 'captain_h1' ) ), $this->email->recipients( M::REQUEST ) );
	}

	public function test_a_proposal_for_a_cup_round_and_its_approval(): void {
		$fixture = $this->cup_without_date( '2026-11-30' );
		$home    = $this->cup_actor( 'cup_h1' );
		$away    = $this->cup_actor( 'cup_a1' );

		$id = $this->postponements->request( $fixture, $home, array( 'new_date' => '2026-12-03 19:00' ) )['id'];

		self::assertSame( array( $this->p( 'cup_a1' ), $this->p( 'cup_a2' ), $this->p( 'chef_away' ) ), $this->email->recipients( M::REQUEST ) );
		$request = $this->last( M::REQUEST );
		self::assertSame( 'Terminvorschlag: Seefeld 1 – Wollishofen 1', $request['subject'] );
		self::assertSame(
			array(
				'Spieler1 Seefelder schlägt für die Begegnung Seefeld 1 – Wollishofen 1 (Mannschaftscup A) diesen Termin vor: Do, 3.12.2026, 19:00.',
				'Bitte stimme zu oder lehne ab. Nach deiner Zustimmung entscheidet die TK über die Bewilligung.',
			),
			$request['notification']->paragraphs
		);

		$this->postponements->accept( $id, $away );
		self::assertSame( array( $this->p( 'cup_h1' ), $this->p( 'cup_h2' ), $this->p( 'chef_home' ) ), $this->email->recipients( M::ACCEPTED ), 'die ganze Heimseite, auch wer vorgeschlagen hat' );
		self::assertSame( 'Die TK muss den Termin noch bewilligen.', $this->last( M::ACCEPTED )['notification']->paragraphs[1] );
		$approval = $this->last( M::TK_APPROVAL );
		self::assertSame( 'Termin zur Bewilligung: Seefeld 1 – Wollishofen 1', $approval['subject'] );
		self::assertSame( 'Der Termin liegt nach der Frist der Runde (30.11.2026). Deshalb entscheidet die TK.', $approval['notification']->paragraphs[1] );
	}

	public function test_preferences_of_the_event_type_apply(): void {
		( new Preferences( $this->db ) )->set( $this->person['captain_a1'], EventTypes::POSTPONEMENT, false, false );
		( new Preferences( $this->db ) )->set( $this->person['tk'], EventTypes::POSTPONEMENT_TK, false, false );

		$id = $this->request_f1()['id'];
		self::assertSame( array( $this->p( 'chef_away' ) ), $this->email->recipients( M::REQUEST ) );
		$this->postponements->accept( $id, $this->actor['chef_away'] );
		self::assertSame( array( 'u108', $this->p( 'admin' ) ), $this->email->recipients( M::TK_INFO ) );
	}

	public function test_the_other_side_is_reminded_once_after_three_days(): void {
		$id                = $this->request_f1()['id'];
		$notifications     = $this->postponement_notifications();
		$this->email->sent = array();

		$this->clock->advance( '+3 days -1 second' );
		self::assertSame( array(), $notifications->remind(), 'eine Sekunde vor drei Tagen' );

		$this->clock->advance( '+1 second' );
		$report = $notifications->remind();
		self::assertSame( array( M::REMINDER ), array_column( $report, 'event' ) );
		self::assertSame( array( $id ), array_column( $report, 'ref' ) );
		self::assertSame( $this->away(), $this->email->recipients( M::REMINDER ) );
		$sent = $this->last( M::REMINDER );
		self::assertSame( EventTypes::POSTPONEMENT, $sent['notification']->type );
		self::assertSame( 'Erinnerung: Verschiebung beantworten, Seefeld 1 – Wollishofen 1', $sent['subject'] );
		self::assertSame(
			array(
				'Der Antrag, die Begegnung Seefeld 1 – Wollishofen 1 (Meisterschaft, Gruppe B) von Di, 10.11.2026, 20:00 auf Di, 17.11.2026, 20:00 zu verschieben, wartet seit 3 Tagen auf eure Antwort.',
				'Bitte stimme zu oder lehne ab. Nach 7 Tagen ohne Antwort kann die TK an eurer Stelle entscheiden.',
			),
			$sent['notification']->paragraphs
		);

		// Jeder weitere stündliche Lauf findet den Antrag wieder, versendet aber nichts mehr.
		$this->clock->advance( '+1 hour' );
		$notifications->remind();
		$this->clock->advance( '+2 days' );
		$notifications->remind();
		self::assertCount( 2, $this->email->sent, 'nur einmal pro Person' );
	}

	public function test_a_failing_request_is_reported_and_does_not_stop_the_run(): void {
		$this->request_f1();
		$errors        = array();
		$notifications = $this->postponement_notifications(
			array(
				'from_hour'  => 0,
				'until_hour' => 24,
			),
			static function ( \Throwable $e, string $context ) use ( &$errors ): void {
				$errors[] = $context;
			}
		);
		$this->email->sent = array();
		$this->clock->advance( '+3 days' );
		// Ungültige Regeln der Saison: Der Kontext der Begegnung lässt sich nicht laden.
		$this->db->update( 'season', array( 'rules_json' => '{"sets_to_win":0}' ), array( 'status' => 'active' ) );

		self::assertSame( array(), $notifications->remind(), 'kein Abbruch' );
		self::assertCount( 1, $errors );
		self::assertStringStartsWith( 'Erinnerung zur Verschiebung ', $errors[0] );
		self::assertSame( array(), $this->email->sent );

		// Ist der Fehler behoben, geht die Erinnerung im nächsten Lauf hinaus.
		$this->db->update( 'season', array( 'rules_json' => '{}' ), array( 'status' => 'active' ) );
		self::assertSame( array( M::REMINDER ), array_column( $notifications->remind(), 'event' ) );
	}

	public function test_answered_requests_are_not_reminded(): void {
		$declined = $this->request_f1()['id'];
		$this->postponements->decline( $declined, $this->actor['captain_a1'] );
		$accepted = $this->request_f1( '2026-12-15 20:00' )['id'];
		$this->postponements->accept( $accepted, $this->actor['captain_a1'] );
		$derby = $this->postponements->request(
			$this->id['derby'],
			$this->actor['captain_h2'],
			array(
				'new_date' => '2026-11-19 20:00',
				'reason'   => 'Terminkollision.',
			)
		)['id'];
		$this->postponements->withdraw( $derby, $this->actor['captain_h2'] );
		$this->email->sent = array();

		$this->clock->advance( '+4 days' );
		self::assertSame( array(), $this->postponement_notifications()->remind(), 'abgelehnt, zugestimmt (wartet auf die TK) und zurückgezogen' );
		self::assertSame( array(), $this->email->sent );
	}

	public function test_no_reminder_for_the_requester_in_a_derby_or_once_a_result_is_entered(): void {
		// Die TK-Chefin (beide Seiten) beantragt im Clubduell: erinnert werden nur die Captains.
		$this->postponements->request(
			$this->id['derby'],
			$this->actor['chef_home'],
			array(
				'new_date' => '2026-11-19 20:00',
				'reason'   => 'Terminkollision.',
			)
		);
		// Für f1 ist inzwischen ein Resultat erfasst: Zustimmen geht nicht mehr.
		$this->request_f1( '2026-11-24 20:00' );
		$this->db->update( 'fixture', array( 'status' => 'provisional' ), array( 'id' => $this->id['f1'] ) );
		$this->email->sent = array();

		$this->clock->advance( '+3 days' );
		$report = $this->postponement_notifications()->remind();

		self::assertCount( 1, $report );
		self::assertSame( array( $this->p( 'captain_h2' ), $this->p( 'captain_h1' ) ), $this->email->recipients( M::REMINDER ) );
	}

	public function test_reminders_wait_for_the_morning(): void {
		$this->request_f1();
		$this->email->sent = array();
		$notifications     = $this->postponement_notifications(
			array(
				'from_hour'  => 7,
				'until_hour' => 22,
			)
		);

		// Drei Tage nach dem Antrag (Donnerstag, 5.11., 09:00) wäre fällig; um 22:00 und 06:59 ruht der Versand.
		$this->clock->advance( '+3 days 13 hours' );
		self::assertSame( array(), $notifications->remind(), '22:00 Ortszeit' );
		$this->clock->advance( '+8 hours 59 minutes' );
		self::assertSame( array(), $notifications->remind(), '06:59 Ortszeit' );
		$this->clock->advance( '+1 minute' );
		self::assertCount( 1, $notifications->remind(), '07:00 Ortszeit' );
		self::assertSame( $this->away(), $this->email->recipients( M::REMINDER ) );
	}

	/**
	 * Stündlicher Lauf der Fristen zu einer Ortszeit, Versandfenster 0 bis 24 Uhr.
	 */
	private function deadlines_at( string $local ): void {
		$utc   = ( new \DateTimeImmutable( $local, new \DateTimeZone( self::ZONE ) ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		$clock = new FixedClock( $utc->format( 'Y-m-d H:i:s' ) );
		$log   = new NotificationLog( $this->db, $clock );
		( new Deadlines(
			$clock,
			new \DateTimeZone( self::ZONE ),
			new FixtureQueries( $this->db ),
			$this->repo,
			$this->recipients,
			new Notifier( new Preferences( $this->db ), $log, array( $this->push, $this->email ) ),
			$this->messages,
			$log,
			array(
				'from_hour'   => 0,
				'until_hour'  => 24,
				'digest_hour' => 7,
			)
		) )->run();
	}

	/**
	 * Nachrichten einer Art zu f1 als «Empfänger@Termin».
	 *
	 * @return list<string>
	 */
	private function f1_messages( string $event ): array {
		$out = array();
		foreach ( $this->email->sent as $sent ) {
			if ( $sent['event'] === $event && 'fixture' === $sent['ref_type'] && $sent['ref_id'] === $this->id['f1'] ) {
				$out[] = $sent['to'] . '@' . $sent['ref_key'];
			}
		}
		return $out;
	}

	public function test_after_a_postponement_the_match_reminder_goes_out_again_for_the_new_date(): void {
		// Sonntag, 8.11., 20:00: Erinnerung zwei Tage vor dem Spiel vom 10.11.
		$this->deadlines_at( '2026-11-08 20:00:00' );
		$both = array_merge( $this->home(), $this->away() );
		self::assertSame( array_map( static fn( string $to ): string => $to . '@2026-11-10 20:00', $both ), $this->f1_messages( 'match_reminder' ) );

		// Montag, 9.11.: verschoben auf Dienstag, 17.11., 20:00.
		$this->clock = new FixedClock( '2026-11-09 08:00:00' );
		$service     = $this->postponement_service( $this->postponement_notifications() );
		$id          = $service->request(
			$this->id['f1'],
			$this->actor['captain_h1'],
			array(
				'new_date' => '2026-11-17 20:00',
				'reason'   => 'Die Halle ist belegt.',
			)
		)['id'];
		$service->accept( $id, $this->actor['captain_a1'] );
		self::assertSame( 'postponed', $this->fixture()['status'] );
		$this->email->sent = array();

		// Am alten Spieltag und danach: keine Erinnerung, kein «Resultat fehlt» für den alten Termin.
		$this->deadlines_at( '2026-11-10 12:00:00' );
		$this->deadlines_at( '2026-11-11 21:00:00' );
		$this->deadlines_at( '2026-11-13 08:00:00' );
		$this->deadlines_at( '2026-11-15 19:59:59' );
		self::assertSame( array(), $this->f1_messages( 'match_reminder' ) );
		self::assertSame( array(), $this->f1_messages( 'result_missing' ) );
		self::assertSame( array(), $this->f1_messages( 'result_missing_tk' ) );

		// Zwei Tage vor dem neuen Termin geht die Erinnerung erneut hinaus, einmal.
		$this->deadlines_at( '2026-11-15 20:00:00' );
		$this->deadlines_at( '2026-11-16 12:00:00' );
		self::assertSame( array_map( static fn( string $to ): string => $to . '@2026-11-17 20:00', $both ), $this->f1_messages( 'match_reminder' ) );

		// Auch die Frist der Erfassung rechnet ab dem neuen Termin.
		$this->deadlines_at( '2026-11-18 19:59:59' );
		self::assertSame( array(), $this->f1_messages( 'result_missing' ) );
		$this->deadlines_at( '2026-11-18 20:00:00' );
		self::assertSame( array_map( static fn( string $to ): string => $to . '@2026-11-17 20:00', $this->home() ), $this->f1_messages( 'result_missing' ) );
	}

	public function test_a_fixture_postponed_before_the_reminder_is_only_reminded_for_the_new_date(): void {
		$id = $this->request_f1( '2026-11-17 20:00' )['id'];
		$this->postponements->accept( $id, $this->actor['captain_a1'] );
		$this->email->sent = array();

		$this->deadlines_at( '2026-11-08 20:00:00' );
		$this->deadlines_at( '2026-11-10 19:00:00' );
		self::assertSame( array(), $this->f1_messages( 'match_reminder' ), 'für den alten Termin geht nichts mehr hinaus' );

		$this->deadlines_at( '2026-11-15 20:00:00' );
		self::assertCount( 4, $this->f1_messages( 'match_reminder' ) );
		self::assertSame( array( '2026-11-17 20:00' ), array_values( array_unique( array_column( array_filter( $this->email->sent, static fn( array $s ): bool => 'match_reminder' === $s['event'] ), 'ref_key' ) ) ) );
	}

	public function test_deadline_queries_treat_postponed_fixtures_like_scheduled_ones(): void {
		$id = $this->request_f1( '2026-11-17 20:00' )['id'];
		$this->postponements->accept( $id, $this->actor['captain_a1'] );
		$queries = new FixtureQueries( $this->db );
		$f1      = array(
			'id'           => $this->id['f1'],
			'scheduled_at' => '2026-11-17 20:00:00',
		);

		self::assertSame( 'postponed', $this->fixture()['status'] );
		self::assertContains( $f1, $queries->open_between( $this->id['season'], '2026-11-17 00:00:00', '2026-11-17 23:59:59' ) );
		self::assertContains( $f1, $queries->open_until( $this->id['season'], '2026-11-30 00:00:00' ) );
		self::assertNotContains( $this->id['f1'], array_column( $queries->open_between( $this->id['season'], '2026-11-10 00:00:00', '2026-11-10 23:59:59' ), 'id' ) );
	}
}
