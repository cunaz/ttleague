<?php
/**
 * Benachrichtigungen zu Anlässen über die Dienste: Bestätigung, Nachrücken, Absage, Dedupe.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Events;

use TTLeague\Accounts\Preferences;
use TTLeague\Events\EventMessages;
use TTLeague\Events\EventNotifications;
use TTLeague\Events\EventRepository;
use TTLeague\Notifications\Channel;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Notification;
use TTLeague\Notifications\NotificationLog;
use TTLeague\Notifications\Notifier;
use TTLeague\Notifications\Recipient;
use TTLeague\Notifications\RecipientResolver;
use TTLeague\Tests\Integration\Notifications\RecordingChannel;

/**
 * Daten siehe EventTestCase. Die Dienste melden ihre Ereignisse hier direkt an EventNotifications;
 * E-Mail und Push sind aufgezeichnete Kanäle.
 */
final class EventNotificationsTest extends EventTestCase {

	private RecordingChannel $email;

	private RecordingChannel $push;

	private EventNotifications $notifications;

	protected function setUp(): void {
		parent::setUp();
		$this->email         = new RecordingChannel( 'email' );
		$this->push          = new RecordingChannel( 'push' );
		$this->notifications = new EventNotifications(
			new EventRepository( $this->db ),
			new RecipientResolver( $this->db ),
			new Notifier( new Preferences( $this->db ), new NotificationLog( $this->db, $this->clock ), array( $this->push, $this->email ) ),
			new EventMessages( new FakeLinks() ),
			static fn( int $user_id ): ?string => 208 === $user_id ? 'TK-Konto' : null
		);
		$this->events        = $this->event_service( $this->notifications );
		$this->registrations = $this->registration_service( $this->clock, $this->notifications );
	}

	private function p( string $key ): string {
		return 'p' . $this->person[ $key ];
	}

	/**
	 * Empfänger => Arten der Nachrichten, in der Reihenfolge des Versands.
	 *
	 * @return list<string>
	 */
	private function sent(): array {
		return array_map( static fn( array $s ): string => $s['to'] . ' ' . $s['event'], $this->email->sent );
	}

	public function test_registering_is_confirmed_to_the_person_herself(): void {
		$event  = $this->event( array( 'post_id' => '42' ) );
		$result = $this->registrations->register( $event, $this->person['h1'], $this->actor['h1'] );

		self::assertCount( 1, $this->email->sent );
		$sent = $this->email->sent[0];
		self::assertSame( array( $this->p( 'h1' ), 'event_registered', 'registration', $result['id'], 'registered' ), array( $sent['to'], $sent['event'], $sent['ref_type'], $sent['ref_id'], $sent['ref_key'] ) );
		self::assertSame( 'Anmeldung bestätigt: Plauschturnier', $sent['subject'] );
		$notification = $sent['notification'];
		self::assertSame( EventTypes::EVENT_REGISTRATION, $notification->type );
		self::assertSame(
			array(
				'Du bist für Plauschturnier (Fr, 20.11.2026, 19:30, Turnhalle Seefeld) angemeldet.',
				'Kannst du nicht teilnehmen, melde dich bitte wieder ab: bis zum Anmeldeschluss (So, 15.11.2026, 18:00) auf der Website, danach bei der TK.',
			),
			$notification->paragraphs
		);
		self::assertSame(
			array(
				'url'   => 'https://liga.example.org/?p=42',
				'label' => 'Zum Anlass',
			),
			$notification->action
		);
		self::assertSame( 'https://liga.example.org/mein-bereich/', $notification->settings_url );
		self::assertSame( 'ttleague-event-' . $event, $notification->push['tag'] );
	}

	public function test_the_waitlist_and_moving_up_are_announced(): void {
		$event = $this->event( array( 'max_participants' => '1' ) );
		$this->registrations->register( $event, $this->person['h1'], $this->actor['h1'] );
		$this->registrations->register( $event, $this->person['h2'], $this->actor['h2'] );

		self::assertSame( 'Warteliste: Plauschturnier', $this->email->sent[1]['subject'] );
		self::assertSame( 'waitlist', $this->email->sent[1]['ref_key'] );
		self::assertSame( 'Die Höchstzahl ist erreicht. Du stehst auf der Warteliste für Plauschturnier (Fr, 20.11.2026, 19:30, Turnhalle Seefeld).', $this->email->sent[1]['notification']->paragraphs[0] );
		self::assertNull( $this->email->sent[1]['notification']->action, 'ohne Ausschreibung kein Link' );
		self::assertSame( 'https://liga.example.org/mein-bereich/', $this->email->sent[1]['notification']->push['url'] );

		$this->email->sent = array();
		$this->registrations->unregister( $event, $this->person['h1'], $this->actor['h1'] );

		self::assertSame( array( $this->p( 'h1' ) . ' event_unregistered', $this->p( 'h2' ) . ' event_promoted' ), $this->sent() );
		self::assertSame( 'Du bist von Plauschturnier (Fr, 20.11.2026, 19:30, Turnhalle Seefeld) abgemeldet.', $this->email->sent[0]['notification']->paragraphs[0] );
		self::assertSame( 'Platz frei: Plauschturnier', $this->email->sent[1]['subject'] );
		self::assertStringContainsString( 'Du bist von der Warteliste nachgerückt und jetzt für Plauschturnier', $this->email->sent[1]['notification']->paragraphs[0] );
	}

	public function test_a_registration_by_someone_else_names_who_did_it(): void {
		$event = $this->event();

		$this->registrations->register( $event, $this->person['h1'], $this->actor['chef_home'] );
		$this->registrations->register( $event, $this->person['a1'], $this->actor['tk_account'] );
		$this->registrations->unregister( $event, $this->person['h1'], $this->actor['tk'] );

		self::assertSame( array( $this->p( 'h1' ) . ' event_registered', $this->p( 'a1' ) . ' event_registered', $this->p( 'h1' ) . ' event_unregistered' ), $this->sent(), 'die Nachricht geht an die betroffene Person, nicht an die handelnde' );
		self::assertStringStartsWith( 'Charlotte Chef hat dich für Plauschturnier', $this->email->sent[0]['notification']->paragraphs[0] );
		self::assertStringStartsWith( 'TK-Konto hat dich für Plauschturnier', $this->email->sent[1]['notification']->paragraphs[0] );
		self::assertStringStartsWith( 'Tamara Kommission hat dich von Plauschturnier', $this->email->sent[2]['notification']->paragraphs[0] );
	}

	public function test_raising_the_limit_tells_everyone_who_moved_up(): void {
		$event = $this->event( array( 'max_participants' => '1' ) );
		$this->register_all( $event, array( 'h1', 'h2', 'h3' ) );
		$this->email->sent = array();

		$this->events->update( $event, $this->input( array( 'max_participants' => '3' ) ), $this->actor['tk'] );

		self::assertSame( array( $this->p( 'h2' ) . ' event_promoted', $this->p( 'h3' ) . ' event_promoted' ), $this->sent() );
	}

	public function test_cancelling_itself_sends_nothing(): void {
		$event = $this->event();
		$this->register_all( $event, array( 'h1', 'h2' ) );
		$this->email->sent = array();

		$this->events->cancel( $event, $this->actor['tk'] );

		self::assertSame( array(), $this->email->sent, 'der Versand läuft getrennt in Schritten' );
		self::assertSame( '0', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'notification_log' )} WHERE event_type = 'event_cancelled'" ) );
	}

	public function test_cancellation_notices_reach_participants_and_the_waitlist_but_not_the_cancelling_account(): void {
		$event = $this->event( array( 'max_participants' => '2' ) );
		$this->register_all( $event, array( 'h1', 'tk', 'h2', 'a1', 'nobody' ) );
		$this->db->update( 'person', array( 'status' => 'inactive' ), array( 'id' => $this->person['a1'] ) );
		$this->db->update( 'person', array( 'email' => null ), array( 'id' => $this->person['nobody'] ) );
		$this->events->cancel( $event, $this->actor['tk'] );
		$this->email->sent = array();

		$result = $this->notifications->send_cancellations( $event );

		self::assertSame(
			array(
				'sent'   => 2,
				'failed' => 0,
				'next'   => null,
			),
			$result
		);
		self::assertEqualsCanonicalizing( array( $this->p( 'h1' ) . ' event_cancelled', $this->p( 'h2' ) . ' event_cancelled' ), $this->sent(), 'nicht an das Konto, das abgesagt hat, nicht an inaktive Personen, nicht ohne Adresse' );
		$sent = $this->email->sent[0];
		self::assertSame( array( 'event', $event, '' ), array( $sent['ref_type'], $sent['ref_id'], $sent['ref_key'] ) );
		self::assertSame( 'Abgesagt: Plauschturnier', $sent['subject'] );
		self::assertSame( array( 'Der Anlass Plauschturnier (Fr, 20.11.2026, 19:30, Turnhalle Seefeld) ist abgesagt. Deine Anmeldung ist damit hinfällig.' ), $sent['notification']->paragraphs );
	}

	/**
	 * Fünf Angemeldete, Schritte zu zwei Empfängern: 2, 2, 1. Jeder Schritt macht nach der zuletzt
	 * besuchten Person weiter; ein Durchgang von vorn nach vollständigem Versand versendet nichts.
	 */
	public function test_cancellation_notices_go_out_in_limited_steps(): void {
		$event = $this->event();
		$this->register_all( $event, array( 'h1', 'h2', 'h3', 'h4', 'a1' ) );
		$this->events->cancel( $event, $this->actor['tk_account'] );
		$this->email->sent = array();
		$order             = array( $this->person['h1'], $this->person['h2'], $this->person['h3'], $this->person['h4'], $this->person['a1'] );
		sort( $order );

		$first = $this->notifications->send_cancellations( $event, 0, 2 );
		self::assertSame(
			array(
				'sent'   => 2,
				'failed' => 0,
				'next'   => $order[1],
			),
			$first,
			'höchstens ein Schritt, der Rest bleibt'
		);
		self::assertSame( array( 'p' . $order[0], 'p' . $order[1] ), $this->email->recipients( 'event_cancelled' ) );

		$second = $this->notifications->send_cancellations( $event, $first['next'], 2 );
		self::assertSame( $order[3], $second['next'] );
		$third = $this->notifications->send_cancellations( $event, $second['next'], 2 );
		self::assertSame(
			array(
				'sent'   => 1,
				'failed' => 0,
				'next'   => null,
			),
			$third,
			'der letzte Schritt versendet nur den Rest'
		);
		self::assertSame( array_map( static fn( int $id ): string => 'p' . $id, $order ), $this->email->recipients( 'event_cancelled' ), 'jede Person genau einmal' );

		$again = $this->notifications->send_cancellations( $event, 0, 2 );
		self::assertSame(
			array(
				'sent'   => 0,
				'failed' => 0,
				'next'   => null,
			),
			$again,
			'nach vollständigem Versand nichts mehr'
		);
		self::assertCount( 5, $this->email->sent );
	}

	/**
	 * Ein Durchgang von vorn überspringt, wer die Mitteilung schon hat, und versendet nur den Rest:
	 * so holt der stündliche Lauf einen abgebrochenen Versand nach.
	 */
	public function test_a_fresh_pass_sends_only_to_those_still_without_the_notice(): void {
		$event = $this->event();
		$this->register_all( $event, array( 'h1', 'h2', 'h3' ) );
		$this->events->cancel( $event, $this->actor['tk_account'] );
		$this->email->sent = array();
		$this->notifications->send_cancellations( $event, 0, 2 );

		$result = $this->notifications->send_cancellations( $event, 0, 2 );

		self::assertSame( 1, $result['sent'] );
		self::assertNull( $result['next'] );
		self::assertCount( 3, $this->email->sent );
		self::assertCount( 3, array_unique( $this->email->recipients( 'event_cancelled' ) ) );
	}

	/**
	 * Der Kanal wirft bei einer Person schon bei der Prüfung der Erreichbarkeit (das fängt der
	 * Notifier nicht ab). Die übrigen erhalten ihre Mitteilung trotzdem, der Fehler wird gemeldet
	 * und zählt zum Schritt; ein späterer Durchgang versucht es bei dieser Person erneut.
	 */
	public function test_an_error_for_one_recipient_does_not_stop_the_others(): void {
		$event = $this->event();
		$this->register_all( $event, array( 'h1', 'h2', 'h3' ) );
		$this->events->cancel( $event, $this->actor['tk_account'] );
		$broken        = $this->person['h2'];
		$errors        = array();
		$inner         = new RecordingChannel( 'email' );
		$channel       = new class( $inner, $broken ) implements Channel {

			public bool $broken_now = true;

			public function __construct( private readonly RecordingChannel $inner, private readonly int $broken ) {}

			public function name(): string {
				return self::EMAIL;
			}

			public function reaches( Recipient $recipient ): bool {
				if ( $this->broken_now && $recipient->person_id === $this->broken ) {
					throw new \RuntimeException( 'Adressprüfung gescheitert' );
				}
				return $this->inner->reaches( $recipient );
			}

			public function deliver( Recipient $recipient, Notification $notification ): bool {
				return $this->inner->deliver( $recipient, $notification );
			}
		};
		$notifications = new EventNotifications(
			new EventRepository( $this->db ),
			new RecipientResolver( $this->db ),
			new Notifier( new Preferences( $this->db ), new NotificationLog( $this->db, $this->clock ), array( $channel ) ),
			new EventMessages( new FakeLinks() ),
			null,
			static function ( \Throwable $error, string $context ) use ( &$errors ): void {
				$errors[] = $error->getMessage() . ' | ' . $context;
			}
		);

		$result = $notifications->send_cancellations( $event );

		self::assertSame(
			array(
				'sent'   => 2,
				'failed' => 1,
				'next'   => null,
			),
			$result
		);
		self::assertEqualsCanonicalizing( array( $this->p( 'h1' ), $this->p( 'h3' ) ), $inner->recipients( 'event_cancelled' ) );
		self::assertSame( array( 'Adressprüfung gescheitert | event_cancelled p' . $broken ), $errors );

		$channel->broken_now = false;
		$retry               = $notifications->send_cancellations( $event );
		self::assertSame( 1, $retry['sent'], 'nur noch die Person von vorhin' );
		self::assertCount( 3, $inner->sent );
	}

	public function test_a_failed_delivery_counts_as_a_step_and_is_retried_by_a_later_pass(): void {
		$event = $this->event();
		$this->register_all( $event, array( 'h1', 'h2', 'h3' ) );
		$this->events->cancel( $event, $this->actor['tk_account'] );
		$this->email->sent = array();
		$this->email->fail = true;

		$failed = $this->notifications->send_cancellations( $event, 0, 2 );

		self::assertSame( 0, $failed['sent'] );
		self::assertSame( 2, $failed['failed'], 'auch gescheiterte Versuche begrenzen den Schritt' );
		self::assertNotNull( $failed['next'] );

		$this->email->fail = false;
		$this->notifications->send_cancellations( $event, 0, 2 );
		$this->notifications->send_cancellations( $event, 0, 2 );
		self::assertCount( 3, array_unique( $this->email->recipients( 'event_cancelled' ) ) );
		self::assertCount( 3, $this->email->sent );
	}

	public function test_only_cancelled_events_get_cancellation_notices(): void {
		$event = $this->event();
		$this->register_all( $event, array( 'h1' ) );
		$this->email->sent = array();

		$open = $this->notifications->send_cancellations( $event );
		$none = $this->notifications->send_cancellations( 99999 );

		self::assertSame( array( 0, null ), array( $open['sent'], $open['next'] ) );
		self::assertSame( 0, $none['sent'] );
		self::assertSame( array(), $this->email->sent );
	}

	/**
	 * Nachgeholt wird sieben Tage lang; massgebend ist der Zeitpunkt der Absage im Änderungsprotokoll.
	 */
	public function test_catching_up_covers_events_cancelled_in_the_last_seven_days(): void {
		$recent = $this->event();
		$border = $this->event( array( 'title' => 'Genau sieben Tage' ) );
		$old    = $this->event( array( 'title' => 'Älter' ) );
		$open   = $this->event( array( 'title' => 'Offen' ) );
		foreach ( array( $recent, $border, $old ) as $event ) {
			$this->events->cancel( $event, $this->actor['tk'] );
		}
		$audit = $this->db->table( 'audit_log' );
		$at    = fn( int $event, string $time ) => $this->db->query( "UPDATE {$audit} SET created_at = %s WHERE entity = 'event' AND entity_id = %d AND action = 'cancelled'", array( $time, $event ) );
		$at( $recent, '2026-11-09 12:00:00' );
		$at( $border, '2026-11-03 12:00:00' );
		$at( $old, '2026-11-03 11:59:59' );

		self::assertSame( array( $recent, $border ), $this->notifications->recently_cancelled( $this->clock->now() ) );
		self::assertNotContains( $open, $this->notifications->recently_cancelled( $this->clock->now() ) );
		self::assertSame( $this->actor['tk']->user_id, $this->events->repository()->cancelled_by( $recent ) );
		self::assertNull( $this->events->repository()->cancelled_by( $open ) );
	}

	public function test_no_message_is_sent_twice_for_the_same_registration(): void {
		$event  = $this->event();
		$result = $this->registrations->register( $event, $this->person['h1'], $this->actor['h1'] );

		$this->notifications->registered( $event, $result['id'], $this->person['h1'], 'registered', $this->actor['h1']->user_id );

		self::assertSame( array( $this->p( 'h1' ) . ' event_registered' ), $this->sent() );
	}

	public function test_a_new_registration_after_leaving_is_confirmed_again(): void {
		$event = $this->event();

		$first = $this->registrations->register( $event, $this->person['h1'], $this->actor['h1'] );
		$this->registrations->unregister( $event, $this->person['h1'], $this->actor['h1'] );
		$second = $this->registrations->register( $event, $this->person['h1'], $this->actor['h1'] );

		self::assertNotSame( $first['id'], $second['id'] );
		self::assertSame( array( $first['id'], $first['id'], $second['id'] ), array_column( $this->email->sent, 'ref_id' ) );
		self::assertSame( array( 'event_registered', 'event_unregistered', 'event_registered' ), array_column( $this->email->sent, 'event' ) );
	}

	public function test_the_person_chooses_the_channels_for_the_event_type(): void {
		$event                   = $this->event();
		$prefs                   = new Preferences( $this->db );
		$this->push->push_people = array( $this->person['h1'], $this->person['h2'] );
		$prefs->set( $this->person['h1'], EventTypes::EVENT_REGISTRATION, false, true );
		$prefs->set( $this->person['h3'], EventTypes::EVENT_REGISTRATION, false, false );

		$this->register_all( $event, array( 'h1', 'h2', 'h3' ) );

		self::assertSame( array( $this->p( 'h1' ) ), $this->push->recipients( 'event_registered' ), 'nur Push gewählt' );
		self::assertSame( array( $this->p( 'h2' ) ), $this->email->recipients( 'event_registered' ), 'Standard: E-Mail; abgewählt: nichts' );
	}

	public function test_an_unknown_event_sends_nothing(): void {
		$this->notifications->registered( 99999, 1, $this->person['h1'], 'registered', 101 );
		$this->notifications->unregistered( 99999, 1, $this->person['h1'], 'registered', 101 );
		$this->notifications->promoted( 99999, 1, $this->person['h1'] );
		$this->notifications->cancelled( 99999, 101 );
		$this->notifications->event_changed( 99999, 101, 'updated' );

		self::assertSame( array(), $this->email->sent );
	}
}
