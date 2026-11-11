<?php
/**
 * Benachrichtigungen des Cups: Auslosung in Schritten, neue Runde, Erinnerung vor der Frist.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Cup;

use TTLeague\Accounts\Preferences;
use TTLeague\Cup\CupMessages;
use TTLeague\Cup\CupNotifications;
use TTLeague\Cup\CupRepository;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Notifications\NotificationLog;
use TTLeague\Notifications\Notifier;
use TTLeague\Notifications\RecipientResolver;
use TTLeague\Tests\Integration\Notifications\FakeLinks;
use TTLeague\Tests\Integration\Notifications\RecordingChannel;

/**
 * Daten siehe CupTestCase; hier haben alle Personen eine E-Mail-Adresse. Eine Cupmannschaft
 * erreicht drei Personen: ihre beiden Spieler und die TK-Chefin oder den TK-Chef des Clubs.
 */
final class CupNotificationsTest extends CupTestCase {

	private RecordingChannel $email;

	private CupNotifications $notifications;

	/** @var list<string> */
	private array $errors = array();

	protected function setUp(): void {
		parent::setUp();
		$this->db->query( "UPDATE {$this->db->table( 'person' )} SET email = CONCAT('person.', id, '@example.org')" );
		$this->email = new RecordingChannel( 'email' );
		$this->wire();
	}

	/**
	 * Benachrichtigungen mit der aktuellen Uhr, Versandfenster 7 bis 22 Uhr.
	 */
	private function wire(): void {
		$this->notifications   = new CupNotifications(
			new CupRepository( $this->db ),
			new RecipientResolver( $this->db ),
			new Notifier( new Preferences( $this->db ), new NotificationLog( $this->db, $this->clock ), array( $this->email ) ),
			new CupMessages( new FakeLinks() ),
			$this->clock,
			new \DateTimeZone( self::ZONE ),
			array(),
			function ( \Throwable $e, string $context ): void {
				$this->errors[] = $context . ': ' . $e->getMessage();
			}
		);
		$this->events->forward = new AnnouncingCupEvents( $this->notifications );
	}

	private function at( string $utc ): void {
		$this->clock = new FixedClock( $utc );
		$this->build_services();
		$this->wire();
	}

	/**
	 * Empfänger einer Mannschaft als Schlüssel (p + Person), Spieler zuerst.
	 *
	 * @return list<string>
	 */
	private function people( string $club ): array {
		return array(
			'p' . $this->seeder->person_of( $this->player[ $club ][1] ),
			'p' . $this->seeder->person_of( $this->player[ $club ][2] ),
			'p' . (int) $this->chef[ $club ]->person_id,
		);
	}

	/**
	 * Zustellungen einer Art zu einer Paarung.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function sent( string $event, int $tie_id ): array {
		return array_values( array_filter( $this->email->sent, static fn( array $s ): bool => $s['event'] === $event && $s['ref_id'] === $tie_id ) );
	}

	public function test_the_draw_is_announced_in_bounded_steps_without_duplicates(): void {
		$this->eight();
		self::assertSame( array(), $this->email->sent, 'die Freigabe selbst versendet nichts' );
		self::assertSame( array( $this->cup_a ), $this->notifications->competitions_to_catch_up() );

		$first = $this->notifications->send_round( $this->cup_a, 0, 10 );
		self::assertSame(
			array(
				'sent'   => 10,
				'failed' => 0,
				'next'   => $this->tie( 1, 1 )['id'],
			),
			$first,
			'zehn Empfänger, die zweite Paarung ist angefangen'
		);
		$second = $this->notifications->send_round( $this->cup_a, (int) $first['next'], 10 );
		self::assertSame( array( 10, $this->tie( 1, 3 )['id'] ), array( $second['sent'], $second['next'] ) );
		$third = $this->notifications->send_round( $this->cup_a, (int) $second['next'], 10 );
		self::assertSame(
			array(
				'sent'   => 4,
				'failed' => 0,
				'next'   => null,
			),
			$third
		);

		self::assertCount( 24, $this->email->sent, 'acht Mannschaften mit je drei Personen' );
		self::assertCount( 24, array_unique( array_column( $this->email->sent, 'to' ) ), 'niemand doppelt' );
		self::assertSame( array( 'cup_round' ), array_values( array_unique( array_column( $this->email->sent, 'event' ) ) ) );
		self::assertSame( array_merge( $this->people( 'seefeld' ), $this->people( 'wollishofen' ) ), array_column( $this->sent( 'cup_round', $this->tie( 1, 1 )['id'] ), 'to' ), 'Heimteam zuerst' );

		// Ein weiterer Durchgang versendet nichts mehr, und der stündliche Lauf hat nichts nachzuholen.
		self::assertSame( 0, $this->notifications->send_round( $this->cup_a )['sent'] );
		self::assertCount( 24, $this->email->sent );
		self::assertSame( array(), $this->notifications->competitions_to_catch_up() );
	}

	public function test_the_message_of_a_round_names_pairing_home_right_and_deadline(): void {
		$this->eight();
		$this->notifications->send_round( $this->cup_a );

		$tie          = $this->tie( 1, 1 );
		$notification = $this->sent( 'cup_round', $tie['id'] )[0]['notification'];
		self::assertSame( array( 'cup_round', 'cup_tie', $tie['id'] ), array( $notification->type, $notification->ref_type, $notification->ref_id ) );
		self::assertSame( 'Mannschaftscup A, Viertelfinal: Seefeld 1 – Wollishofen 1', $notification->subject );
		self::assertSame(
			array(
				'Die Auslosung für den Mannschaftscup A ist freigegeben. Viertelfinal: Seefeld 1 – Wollishofen 1.',
				'Heimrecht hat Seefeld 1. Das Heimteam schlägt einen Termin vor, der Gegner stimmt zu.',
				'Die Runde ist bis am 30.11.2026 zu spielen.',
			),
			$notification->paragraphs
		);
		self::assertSame(
			array(
				'url'   => 'https://liga.example.org/erfassen/?begegnung=' . $tie['fixture']['id'],
				'label' => 'Begegnung öffnen',
			),
			$notification->action,
			'führt zum Terminvorschlag auf der Erfassungsseite'
		);
	}

	public function test_teams_with_a_bye_are_told_so(): void {
		$ids = $this->teams( 5 );
		$this->release( array( 'seefeld', null, 'altstetten', 'bergwiese', 'wollishofen', null, 'oerlikon', null ), $ids );

		$result = $this->notifications->send_round( $this->cup_a );

		self::assertSame( array( 21, null ), array( $result['sent'], $result['next'] ), 'drei Freilose, zwei Begegnungen mit je sechs Personen' );
		$bye = $this->sent( 'cup_bye', $this->tie( 1, 1 )['id'] );
		self::assertSame( $this->people( 'seefeld' ), array_column( $bye, 'to' ) );
		self::assertSame( 'Mannschaftscup A: Freilos für Seefeld 1', $bye[0]['subject'] );
		self::assertSame( 'Die Auslosung für den Mannschaftscup A ist freigegeben. Seefeld 1 hat ein Freilos (Viertelfinal) und steht in der nächsten Runde.', $bye[0]['notification']->paragraphs[0] );
		self::assertNull( $bye[0]['notification']->action );
		// Wollishofen und Oerlikon hatten ein Freilos und treffen sich schon im Halbfinal.
		$semi = $this->sent( 'cup_round', $this->tie( 2, 2 )['id'] );
		self::assertSame( array_merge( $this->people( 'wollishofen' ), $this->people( 'oerlikon' ) ), array_column( $semi, 'to' ) );
		self::assertSame( 'Ort und Termin setzt die TK an; ihr erhaltet dann eine Nachricht.', $semi[0]['notification']->paragraphs[1] );
	}

	public function test_a_new_round_and_changes_by_the_tk_go_out_directly(): void {
		$this->eight();
		$this->notifications->send_round( $this->cup_a );
		$count = count( $this->email->sent );

		$this->play( $this->fixture_of( 1, 1 ), 'home' );
		self::assertCount( $count, $this->email->sent, 'ein Sieger allein ist noch keine Begegnung' );
		$this->play( $this->fixture_of( 1, 2 ), 'away' );

		$semi = $this->tie( 2, 1 );
		$sent = $this->sent( 'cup_round', $semi['id'] );
		self::assertSame( array_merge( $this->people( 'seefeld' ), $this->people( 'altstetten' ) ), array_column( $sent, 'to' ) );
		self::assertSame( 'Mannschaftscup A, Halbfinal: Seefeld 1 – Altstetten 1', $sent[0]['subject'] );
		self::assertSame( 'Im Mannschaftscup A steht die nächste Begegnung fest. Halbfinal: Seefeld 1 – Altstetten 1.', $sent[0]['notification']->paragraphs[0] );

		// Die TK setzt den Halbfinal an: neue Mitteilung mit Termin und Ort.
		$venue = $this->seeder->venue( $this->club['affoltern'], 'Sporthalle Affoltern' );
		$this->bracket->schedule(
			$semi['id'],
			$this->tk,
			array(
				'scheduled_at' => '2026-12-12 14:00',
				'venue_id'     => $venue,
			)
		);
		$sent = $this->sent( 'cup_round', $semi['id'] );
		self::assertCount( 12, $sent );
		self::assertSame( 'Die TK hat die Begegnung angesetzt: Sa, 12.12.2026, 14:00, Affoltern: Sporthalle Affoltern.', $sent[6]['notification']->paragraphs[1] );
		self::assertNotSame( $sent[0]['ref_key'], $sent[6]['ref_key'] );

		// Getauschtes Heimrecht in der ersten Runde: beide Mannschaften erfahren es.
		$tie = $this->tie( 1, 3 );
		$this->bracket->swap_home( $tie['id'], $this->tk );
		$sent = $this->sent( 'cup_round', $tie['id'] );
		self::assertCount( 12, $sent, 'sechs zur Auslosung, sechs zum neuen Heimrecht' );
		self::assertSame( 'Heimrecht hat Lindenhof 1. Das Heimteam schlägt einen Termin vor, der Gegner stimmt zu.', $sent[6]['notification']->paragraphs[1] );
		self::assertSame( array_merge( $this->people( 'lindenhof' ), $this->people( 'bergwiese' ) ), array_column( array_slice( $sent, 6 ), 'to' ) );

		// Dieselbe Mitteilung geht kein zweites Mal hinaus.
		$total = count( $this->email->sent );
		self::assertSame( 0, $this->notifications->announce( $this->cup_a, array( $tie['id'], $semi['id'] ) )['sent'] );
		self::assertCount( $total, $this->email->sent );
	}

	public function test_a_failed_delivery_is_counted_and_caught_up_later(): void {
		$this->eight();
		$this->email->fail = true;

		$failed = $this->notifications->send_round( $this->cup_a, 0, 5 );
		self::assertSame( array( 0, 5, 0 ), array( $failed['sent'], $failed['failed'], $failed['next'] ), 'auch Fehlversuche begrenzen den Schritt' );
		self::assertSame( array( $this->cup_a ), $this->notifications->competitions_to_catch_up() );

		$this->email->fail = false;
		self::assertSame( 24, $this->notifications->send_round( $this->cup_a )['sent'] );

		// Nach sieben Tagen holt der stündliche Lauf nichts mehr nach.
		$this->truncate_notifications();
		self::assertSame( array( $this->cup_a ), $this->notifications->competitions_to_catch_up() );
		$this->db->query( "UPDATE {$this->db->table( 'audit_log' )} SET created_at = '2026-10-26 07:59:59' WHERE entity = 'cup_tie'" );
		self::assertSame( array(), $this->notifications->competitions_to_catch_up(), 'eine Sekunde älter als sieben Tage' );
		self::assertSame( 0, $this->notifications->send_round( $this->cup_a )['sent'] );
	}

	private function truncate_notifications(): void {
		$this->db->query( "DELETE FROM {$this->db->table( 'notification_log' )}" );
	}

	public function test_the_deadline_reminder_goes_out_seven_days_before(): void {
		$this->eight();
		// Paarung 1/2 hat einen Termin, 1/3 ein Resultat: beide brauchen keine Erinnerung.
		$this->db->update( 'fixture', array( 'scheduled_at' => '2026-11-28 19:00:00' ), array( 'id' => $this->fixture_of( 1, 2 ) ) );
		$this->play( $this->fixture_of( 1, 3 ), 'home' );

		// Sonntag, 22.11.2026, 10:00 Ortszeit: noch acht Tage bis zur Frist.
		$this->at( '2026-11-22 09:00:00' );
		self::assertSame( array(), $this->notifications->remind_deadlines(), 'acht Tage vor der Frist (30.11.)' );

		// Montag, 23.11.2026, 00:30 Ortszeit: sieben Tage vorher, aber Nachtruhe.
		$this->at( '2026-11-22 23:30:00' );
		self::assertSame( array(), $this->notifications->remind_deadlines(), 'ausserhalb des Versandfensters' );

		$this->at( '2026-11-23 06:00:00' );
		$report = $this->notifications->remind_deadlines();

		self::assertSame( array( $this->tie( 1, 1 )['id'], $this->tie( 1, 4 )['id'] ), array_column( $report, 'ref' ), 'nur Begegnungen ohne Termin und ohne Resultat' );
		$sent = $this->sent( 'cup_deadline', $this->tie( 1, 1 )['id'] );
		self::assertSame( array_merge( $this->people( 'seefeld' ), $this->people( 'wollishofen' ) ), array_column( $sent, 'to' ) );
		$notification = $sent[0]['notification'];
		self::assertSame( array( 'cup_deadline', 'cup_tie', '2026-11-30' ), array( $notification->type, $notification->ref_type, $notification->ref_key ) );
		self::assertSame( 'Cup: Die Frist läuft ab, Seefeld 1 – Wollishofen 1', $notification->subject );
		self::assertSame( 'Die Begegnung Seefeld 1 – Wollishofen 1 (Mannschaftscup A, Viertelfinal) ist bis am 30.11.2026 zu spielen. Es ist noch kein Termin vereinbart.', $notification->paragraphs[0] );
		self::assertSame( 'Termin vereinbaren', $notification->action['label'] );

		// Der nächste Lauf wiederholt nichts.
		$this->at( '2026-11-24 08:00:00' );
		$this->notifications->remind_deadlines();
		self::assertCount( 12, $this->email->sent );

		// Verlängert die TK die Frist, gilt die Erinnerung für die neue Frist.
		$this->bracket->set_tie_deadline( $this->tie( 1, 1 )['id'], '2026-12-10', $this->tk );
		$this->at( '2026-12-02 08:00:00' );
		self::assertSame( array(), $this->notifications->remind_deadlines(), 'acht Tage vor dem 10.12.; die Frist vom 30.11. ist abgelaufen' );
		$this->at( '2026-12-03 08:00:00' );
		self::assertSame( array( $this->tie( 1, 1 )['id'] ), array_column( $this->notifications->remind_deadlines(), 'ref' ) );
		self::assertSame( '2026-12-10', end( $this->email->sent )['ref_key'] );
		self::assertSame( array(), $this->errors );
	}

	public function test_the_reminder_still_goes_out_on_the_day_of_the_deadline_but_not_after(): void {
		$this->eight();

		$this->at( '2026-11-30 20:30:00' );
		self::assertCount( 4, $this->notifications->remind_deadlines(), 'am Tag der Frist, 21:30 Ortszeit' );

		$this->truncate_notifications();
		$this->email->sent = array();
		$this->at( '2026-12-01 08:00:00' );
		self::assertSame( array(), $this->notifications->remind_deadlines(), 'nach der Frist steht die Begegnung bei der TK' );
	}

	public function test_inactive_people_and_archived_seasons_get_nothing(): void {
		$this->eight();
		$inactive = $this->seeder->person_of( $this->player['seefeld'][1] );
		$this->db->update( 'person', array( 'status' => 'inactive' ), array( 'id' => $inactive ) );

		$this->notifications->send_round( $this->cup_a );
		self::assertNotContains( 'p' . $inactive, array_column( $this->email->sent, 'to' ) );
		self::assertCount( 23, $this->email->sent );

		$this->db->update( 'season', array( 'status' => 'archived' ), array( 'id' => $this->season ) );
		$this->at( '2026-11-25 08:00:00' );
		self::assertSame( array(), $this->notifications->remind_deadlines() );
		self::assertSame( array(), $this->notifications->competitions_to_catch_up() );
	}
}
