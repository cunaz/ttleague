<?php
/**
 * Integrationstests der Benachrichtigungen der Meldung: Entscheide und Erinnerungen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Registration;

use PHPUnit\Framework\Attributes\DataProvider;
use TTLeague\Accounts\Preferences;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\NotificationLog;
use TTLeague\Notifications\Notifier;
use TTLeague\Notifications\RecipientResolver;
use TTLeague\Registration\Decision;
use TTLeague\Registration\RegistrationMessages;
use TTLeague\Registration\RegistrationNotifications;
use TTLeague\Tests\Integration\Notifications\FakeLinks;
use TTLeague\Tests\Integration\Notifications\RecordingChannel;

/**
 * Daten siehe RegistrationTestCase. TK-Chefs der geplanten Saison: Charlotte Chef (Seefeld, mit
 * E-Mail), Walter Wolli und Nina Neu (Wollishofen; hier beide mit E-Mail). Meldefenster 1. bis
 * 31. Mai 2027.
 */
final class RegistrationNotificationsTest extends RegistrationTestCase {

	private RecordingChannel $email;

	private RegistrationNotifications $notifications;

	protected function setUp(): void {
		parent::setUp();
		foreach ( array( 'wolli', 'neu', 'alt' ) as $key ) {
			$this->db->update( 'person', array( 'email' => $key . '@example.invalid' ), array( 'id' => $this->id[ 'person_' . $key ] ) );
		}
		$this->email = new RecordingChannel( 'email' );
		$this->clock( '2027-05-24 08:00:00' );
	}

	/**
	 * Benachrichtigungen mit einer Uhr auf diesem Zeitpunkt (UTC); das Versandprotokoll bleibt.
	 *
	 * @param list<int>|null $days Stufen der Erinnerung.
	 */
	private function clock( string $utc, ?array $days = null ): void {
		$clock               = new FixedClock( $utc );
		$this->notifications = new RegistrationNotifications(
			$this->db,
			$clock,
			new \DateTimeZone( 'Europe/Zurich' ),
			new RecipientResolver( $this->db ),
			new Notifier( new Preferences( $this->db ), new NotificationLog( $this->db, $clock ), array( $this->email ) ),
			new RegistrationMessages( new FakeLinks() ),
			array(),
			$days ?? array( 7, 2 )
		);
	}

	/**
	 * @return list<string> Empfänger der Erinnerungen als «p{Person}».
	 */
	private function reminded(): array {
		return $this->email->recipients( RegistrationMessages::REMINDER );
	}

	private function p( string $key ): string {
		return 'p' . $this->id[ 'person_' . $key ];
	}

	/**
	 * @return iterable<string,array{string,int}>
	 */
	public static function reminder_times(): iterable {
		yield 'acht Tage vorher nichts' => array( '2027-05-23 10:00:00', 0 );
		yield 'sieben Tage vorher' => array( '2027-05-24 08:00:00', 3 );
		yield 'sieben Tage vorher, aber vor 7 Uhr Ortszeit' => array( '2027-05-24 04:30:00', 0 );
		yield 'sieben Tage vorher, aber nach 22 Uhr Ortszeit' => array( '2027-05-24 20:30:00', 0 );
		yield 'zwei Tage vorher' => array( '2027-05-29 08:00:00', 3 );
		yield 'am letzten Tag' => array( '2027-05-31 08:00:00', 3 );
		yield 'nach Ablauf nichts mehr' => array( '2027-06-01 08:00:00', 0 );
	}

	#[DataProvider( 'reminder_times' )]
	public function test_reminders_go_out_seven_and_two_days_before_the_window_closes( string $utc, int $count ): void {
		$this->clock( $utc );

		$this->notifications->remind();

		self::assertCount( $count, $this->reminded() );
	}

	public function test_reminder_goes_to_the_tk_chefs_of_clubs_with_an_open_registration(): void {
		$this->complete_registration();
		$this->registration->submit( $this->actor['chef'], $this->id['next'], $this->id['home'] );

		$report = $this->notifications->remind();

		self::assertSame( array( $this->p( 'wolli' ), $this->p( 'neu' ) ), $this->reminded(), 'Seefeld hat eingereicht; Alma Alt ist nur in der aktiven Saison TK-Chefin' );
		self::assertCount( 1, $report );
		$sent = $this->email->sent[0];
		self::assertSame( 'club_season', $sent['ref_type'] );
		self::assertSame( '2027-05-31:7', $sent['ref_key'] );
		self::assertSame( 'Meldung 2027/28: noch 7 Tage', $sent['subject'] );
		self::assertSame( EventTypes::REGISTRATION_REMINDER, $sent['notification']->type );
		self::assertStringContainsString( 'TTC Wollishofen', $sent['notification']->paragraphs[0] );
		self::assertStringContainsString( '31.5.2027', $sent['notification']->paragraphs[0] );
		self::assertSame( 'https://liga.example.org/mein-bereich/#ttl-meldung-' . $this->id['away'], $sent['notification']->action['url'] );
	}

	public function test_each_step_is_sent_once_and_a_missed_run_is_caught_up(): void {
		$this->notifications->remind();
		$this->clock( '2027-05-24 09:00:00' );
		$this->notifications->remind();
		$this->clock( '2027-05-26 09:00:00' );
		$this->notifications->remind();
		self::assertCount( 3, $this->reminded(), 'die erste Stufe geht pro Person einmal hinaus' );

		$this->clock( '2027-05-30 09:00:00' );
		$this->notifications->remind();
		$this->clock( '2027-05-31 09:00:00' );
		$this->notifications->remind();

		self::assertCount( 6, $this->reminded(), 'die zweite Stufe auch nur einmal, nachgeholt am Tag danach' );
		self::assertSame( 'Meldung 2027/28: noch 1 Tag', $this->email->sent[3]['subject'] );
		self::assertSame( '2027-05-31:2', $this->email->sent[3]['ref_key'] );
	}

	public function test_a_longer_window_reminds_again_and_preferences_are_respected(): void {
		$this->notifications->remind();
		self::assertCount( 3, $this->reminded() );

		$this->db->update( 'season', array( 'registration_closes' => '2027-06-15' ), array( 'id' => $this->id['next'] ) );
		( new Preferences( $this->db ) )->set( $this->id['person_neu'], EventTypes::REGISTRATION_REMINDER, false, false );
		$this->clock( '2027-06-08 09:00:00' );
		$this->notifications->remind();

		self::assertSame(
			array( $this->p( 'chef' ), $this->p( 'wolli' ) ),
			array_slice( $this->reminded(), 3 ),
			'neuer letzter Tag, neue Erinnerung; Nina Neu hat sie abbestellt'
		);
	}

	public function test_no_reminder_without_a_planned_season_and_the_days_are_a_parameter(): void {
		$this->clock( '2027-05-21 09:00:00', array( 10 ) );
		$this->notifications->remind();
		self::assertCount( 3, $this->reminded() );
		self::assertSame( '2027-05-31:10', $this->email->sent[0]['ref_key'] );

		$this->db->update( 'season', array( 'status' => 'archived' ), array( 'id' => $this->id['next'] ) );
		$this->clock( '2027-05-29 09:00:00' );
		$this->notifications->remind();
		self::assertCount( 3, $this->reminded() );
	}

	public function test_a_decision_goes_to_the_tk_chefs_of_the_club_in_that_season(): void {
		$decision = new Decision( Decision::REGISTRATION_RETURNED, $this->id['next'], '2027/28', $this->id['away'], 'TTC Wollishofen', 77, 303, null, '', 'Bitte Mannschaft 2 ergänzen.' );

		$this->notifications->decided( $decision );
		$this->notifications->decided( $decision );

		self::assertSame( array( $this->p( 'wolli' ), $this->p( 'neu' ) ), $this->email->recipients( RegistrationMessages::DECIDED ), 'jede Person einmal pro Entscheid' );
		$sent = $this->email->sent[0];
		self::assertSame( 'audit_log', $sent['ref_type'] );
		self::assertSame( 77, $sent['ref_id'] );
		self::assertSame( EventTypes::REGISTRATION_DECIDED, $sent['notification']->type );
		self::assertSame( 'Meldung 2027/28 zurückgegeben: TTC Wollishofen', $sent['subject'] );
		self::assertSame( 'Bemerkung der TK: Bitte Mannschaft 2 ergänzen.', $sent['notification']->paragraphs[1] );
	}

	public function test_decisions_of_the_services_notify_but_never_the_deciding_person(): void {
		$this->at( '2026-12-01 10:00:00' );
		$this->registration->confirm_player( $this->actor['chef'], $this->id['now'], $this->id['home'], $this->id['p_ehemalig'] );
		$this->review->approve_player( $this->actor['tk'], $this->id['now'], $this->id['home'], $this->id['p_ehemalig'] );
		$decision = $this->events->decisions[0];

		$this->notifications->decided( $decision );

		self::assertSame( array( $this->p( 'chef' ), $this->p( 'alt' ) ), $this->email->recipients( RegistrationMessages::DECIDED ), 'TK-Chefs der aktiven Saison' );
		self::assertSame( 'Meldung freigegeben: Erika Ehemalig', $this->email->sent[0]['subject'] );

		// Entscheidet eine TK-Chefin, die auch zur TK gehört, erhält sie selbst keine Nachricht.
		$own = new Decision( Decision::REQUEST_REJECTED, $this->id['now'], '2026/27', $this->id['home'], 'TTC Seefeld', 78, 301, $this->id['person_chef'], 'Noah Neuling', 'Doppelt.' );
		$this->notifications->decided( $own );

		self::assertSame( array( $this->p( 'alt' ) ), array_slice( $this->email->recipients( RegistrationMessages::DECIDED ), 2 ) );
	}
}
