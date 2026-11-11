<?php
/**
 * Absagemitteilungen: bei der Absage nur vorgemerkt, über WP-Cron in Schritten versendet,
 * vom stündlichen Lauf nachgeholt.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Events;

use TTLeague\Accounts\AccountService;
use TTLeague\Events\AdminActions;
use TTLeague\Events\EventNotifications;
use TTLeague\Events\Events;
use TTLeague\Infrastructure\Flash;
use TTLeague\Notifications\Notifications;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tk\Tk;

/**
 * Daten siehe EventsTestCase.
 */
final class CancellationTest extends EventsTestCase {

	/**
	 * Die TK sagt über das Formular im Backend ab.
	 */
	private function cancel( int $event, string $user = 'tk' ): void {
		wp_set_current_user( $this->user[ $user ] );
		$this->post(
			AdminActions::STATUS . '_' . $event,
			array(
				'event'    => (string) $event,
				'decision' => 'cancel',
				'confirm'  => '1',
			)
		);
		$this->redirect_of( array( AdminActions::class, 'status' ) );
	}

	/**
	 * Geplante Schritte des Versands: Argumente (Anlass, Person davor) in der Reihenfolge der Planung.
	 *
	 * @return list<array{0:int,1:int}>
	 */
	private function scheduled(): array {
		$steps = array();
		foreach ( _get_cron_array() as $hooks ) {
			foreach ( $hooks[ Events::CANCELLATION_HOOK ] ?? array() as $event ) {
				$steps[] = array_map( 'intval', $event['args'] );
			}
		}
		return $steps;
	}

	/**
	 * Führt den nächsten geplanten Schritt aus, wie WP-Cron es tut, und liefert seine Argumente.
	 *
	 * @return array{0:int,1:int}
	 */
	private function run_next(): array {
		$steps = $this->scheduled();
		self::assertNotSame( array(), $steps, 'ein Schritt ist geplant' );
		wp_clear_scheduled_hook( Events::CANCELLATION_HOOK, $steps[0] );
		do_action_ref_array( Events::CANCELLATION_HOOK, $steps[0] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- der Hook des Plugins (ttleague_event_cancellation), wie WP-Cron ihn auslöst.
		return $steps[0];
	}

	/**
	 * Meldet so viele weitere erfundene Personen mit E-Mail-Adresse an.
	 */
	private function crowd( int $event, int $number ): void {
		$seeder   = new Seeder( $this->db );
		$accounts = new AccountService( $this->db );
		for ( $i = 1; $i <= $number; $i++ ) {
			$person = $seeder->person( 'Probe', 'Person' . $i );
			$accounts->set_email( $person, 'probe' . $i . '@example.invalid' );
			$this->db->insert(
				'registration',
				array(
					'event_id'   => $event,
					'person_id'  => $person,
					'status'     => 'registered',
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}
	}

	public function test_the_cron_hooks_are_registered(): void {
		self::assertSame( 10, has_action( Events::CANCELLATION_HOOK, array( Events::class, 'send_cancellations' ) ) );
		self::assertSame( 20, has_action( Notifications::CRON_HOOK, array( Events::class, 'catch_up_cancellations' ) ), 'Auffangnetz am stündlichen Lauf' );
	}

	public function test_cancelling_sends_nothing_and_schedules_the_delivery(): void {
		$event = $this->event( array( 'max_participants' => '1' ) );
		$this->register_all( $event, array( 'h1', 'a1' ) );

		$this->cancel( $event );

		self::assertSame( array(), $this->mails, 'die Absage selbst versendet nichts' );
		self::assertSame( array( array( $event, 0 ) ), $this->scheduled() );
		self::assertLessThanOrEqual( time(), wp_next_scheduled( Events::CANCELLATION_HOOK, array( $event, 0 ) ), 'sofort fällig' );
		self::assertSame( 'Anlass abgesagt. Die Mitteilungen an 2 angemeldete Personen werden in den nächsten Minuten versendet.', Flash::take( Tk::FLASH, $this->user['tk'] )['message'] );

		$this->run_next();

		self::assertSame(
			array(
				'a1@example.invalid' => array( 'Abgesagt: Plauschturnier' ),
				'h1@example.invalid' => array( 'Abgesagt: Plauschturnier' ),
			),
			$this->subjects(),
			'Teilnehmende und Warteliste'
		);
		self::assertSame( array(), $this->scheduled(), 'kein Rest, kein weiterer Schritt' );
	}

	public function test_an_event_without_registrations_says_so(): void {
		$event = $this->event();

		$this->cancel( $event );

		self::assertSame( 'Anlass abgesagt. Es war niemand angemeldet.', Flash::take( Tk::FLASH, $this->user['tk'] )['message'] );
	}

	/**
	 * 28 Angemeldete bei Schritten zu 25: Der erste Lauf versendet 25 und plant den Rest, der
	 * zweite versendet 3, ein dritter Lauf von vorn versendet nichts.
	 */
	public function test_delivery_runs_in_steps_and_schedules_the_rest(): void {
		$event = $this->event();
		$this->register_all( $event, array( 'h1', 'a1', 'nobody' ) );
		$this->crowd( $event, EventNotifications::CANCEL_BATCH );
		$this->cancel( $event );

		$this->run_next();

		self::assertCount( EventNotifications::CANCEL_BATCH, $this->mails, 'höchstens ein Schritt pro Lauf' );
		$rest = $this->scheduled();
		self::assertCount( 1, $rest, 'der Rest ist eingeplant' );
		self::assertSame( $event, $rest[0][0] );
		self::assertGreaterThan( 0, $rest[0][1], 'ab der zuletzt besuchten Person' );

		$this->run_next();

		self::assertCount( EventNotifications::CANCEL_BATCH + 3, $this->mails );
		self::assertCount( EventNotifications::CANCEL_BATCH + 3, array_unique( array_map( static fn( $mail ): string => $mail->to, $this->mails ) ), 'jede Person genau einmal' );
		self::assertSame( array(), $this->scheduled() );

		Events::send_cancellations( $event, 0 );

		self::assertCount( EventNotifications::CANCEL_BATCH + 3, $this->mails, 'nach vollständigem Versand nichts mehr' );
		self::assertSame( array(), $this->scheduled() );
	}

	public function test_the_cancelling_account_gets_no_notice_in_any_run(): void {
		$chef = new \WP_User( $this->user['chef'] );
		$chef->add_role( 'ttl_tk' );
		$event = $this->event();
		$this->register_all( $event, array( 'chef', 'h1' ) );

		$this->cancel( $event, 'chef' );
		$this->run_next();
		Events::catch_up_cancellations();

		self::assertSame( array( 'h1@example.invalid' => array( 'Abgesagt: Plauschturnier' ) ), $this->subjects() );
	}

	public function test_the_hourly_run_catches_up_when_the_single_event_is_lost(): void {
		$event = $this->event();
		$other = $this->event( array( 'title' => 'Nicht abgesagt' ) );
		$this->register_all( $event, array( 'h1', 'a1' ) );
		$this->register_all( $other, array( 'h3' ) );
		$this->cancel( $event );
		wp_unschedule_hook( Events::CANCELLATION_HOOK );

		Events::catch_up_cancellations();

		self::assertSame(
			array(
				'a1@example.invalid' => array( 'Abgesagt: Plauschturnier' ),
				'h1@example.invalid' => array( 'Abgesagt: Plauschturnier' ),
			),
			$this->subjects()
		);

		Events::catch_up_cancellations();
		self::assertCount( 2, $this->mails, 'der nächste stündliche Lauf versendet nichts doppelt' );
	}

	public function test_the_hourly_run_leaves_cancellations_older_than_seven_days(): void {
		$event = $this->event();
		$this->register_all( $event, array( 'h1' ) );
		$this->cancel( $event );
		wp_unschedule_hook( Events::CANCELLATION_HOOK );
		$this->db->query(
			"UPDATE {$this->db->table( 'audit_log' )} SET created_at = %s WHERE entity = 'event' AND entity_id = %d AND action = 'cancelled'",
			array( gmdate( 'Y-m-d H:i:s', time() - EventNotifications::CANCEL_CATCH_UP_DAYS * DAY_IN_SECONDS - 60 ), $event )
		);

		Events::catch_up_cancellations();

		self::assertSame( array(), $this->mails );
	}

	public function test_a_failing_mail_is_retried_by_the_hourly_run_and_does_not_stop_the_others(): void {
		$event = $this->event();
		$this->register_all( $event, array( 'h1', 'a1' ) );
		$this->cancel( $event );
		$fail = static fn( $pre, array $mail ) => 'h1@example.invalid' === $mail['to'] ? false : $pre;
		add_filter( 'pre_wp_mail', $fail, 10, 2 );

		$this->error_log_of( fn() => $this->run_next() );

		self::assertSame( '1', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'notification_log' )} WHERE event_type = 'event_cancelled' AND status = 'sent' AND person_id = %d", array( $this->person['a1'] ) ), 'die andere Person hat die Mitteilung' );
		self::assertSame( '0', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'notification_log' )} WHERE event_type = 'event_cancelled' AND person_id = %d", array( $this->person['h1'] ) ) );

		remove_filter( 'pre_wp_mail', $fail, 10 );
		$this->mails = array();
		Events::catch_up_cancellations();

		self::assertSame( array( 'h1@example.invalid' => array( 'Abgesagt: Plauschturnier' ) ), $this->subjects(), 'nachgeholt, ohne die andere Person erneut anzuschreiben' );
	}
}
