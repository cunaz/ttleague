<?php
/**
 * Versand: Einstellungen, Dedupe, Rückfall auf E-Mail, handelnde Person.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Notifications;

use TTLeague\Accounts\Preferences;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Notification;
use TTLeague\Notifications\NotificationLog;
use TTLeague\Notifications\Notifier;

final class NotifierTest extends NotificationTestCase {

	private function reminder( int $ref_id = 0, string $key = '2026-11-10 20:00' ): Notification {
		$ctx = $this->repo->context( $ref_id > 0 ? $ref_id : $this->id['f1'] );
		$n   = $this->messages->match_reminder( $ctx, null );
		return new Notification( $n->type, $n->event, $n->ref_type, $n->ref_id, $key, $n->subject, $n->paragraphs, $n->lists, $n->action, $n->settings_url, $n->push );
	}

	private function log_count(): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'notification_log' )}" );
	}

	public function test_email_is_the_default_and_people_without_valid_address_are_skipped(): void {
		$ctx    = $this->repo->context( $this->id['f1'] );
		$report = $this->notifier->send( $this->reminder(), $this->recipients->side( $ctx, 'home' ) );

		self::assertSame( array( $this->p( 'captain_h1' ), $this->p( 'chef_home' ) ), $this->email->recipients( 'match_reminder' ) );
		self::assertSame( array(), $this->push->sent, 'Push ist ohne Einstellung aus' );
		self::assertSame( array( 'sent', 'sent' ), array_column( $report, 'status' ) );
		self::assertSame( 2, $this->log_count(), 'nichts protokolliert für die Person ohne Adresse' );
	}

	public function test_each_message_goes_out_once_per_person_reference_and_channel(): void {
		$ctx        = $this->repo->context( $this->id['f1'] );
		$recipients = $this->recipients->sides( $ctx );

		$this->notifier->send( $this->reminder(), $recipients );
		$second = $this->notifier->send( $this->reminder(), $recipients );

		self::assertCount( 4, $this->email->sent );
		self::assertSame( array( 'duplicate' ), array_values( array_unique( array_column( $second, 'status' ) ) ) );

		$this->notifier->send( $this->reminder( 0, '2026-11-24 20:00' ), $recipients );
		self::assertCount( 8, $this->email->sent, 'neuer Termin, neue Erinnerung' );

		$this->notifier->send( $this->reminder( $this->id['derby'] ), $this->recipients->sides( $this->repo->context( $this->id['derby'] ) ) );
		self::assertCount( 11, $this->email->sent, 'andere Begegnung: Captains beider Seiten und TK-Chef einmal' );
	}

	public function test_duplicate_recipients_in_one_call_get_one_message(): void {
		$ctx = $this->repo->context( $this->id['f1'] );
		$this->notifier->send( $this->reminder(), array_merge( $this->recipients->side( $ctx, 'home' ), $this->recipients->side( $ctx, 'home' ) ) );
		self::assertCount( 2, $this->email->sent );
	}

	public function test_preferences_choose_the_channels(): void {
		$prefs = new Preferences( $this->db );
		$prefs->set( $this->person['captain_h1'], EventTypes::MATCH_REMINDER, false, true );
		$prefs->set( $this->person['chef_home'], EventTypes::MATCH_REMINDER, false, false );
		$prefs->set( $this->person['captain_a1'], EventTypes::MATCH_REMINDER, true, true );
		$this->push->push_people = array( $this->person['captain_h1'], $this->person['captain_a1'] );

		$this->notifier->send( $this->reminder(), $this->recipients->sides( $this->repo->context( $this->id['f1'] ) ) );

		self::assertSame( array( $this->p( 'captain_h1' ), $this->p( 'captain_a1' ) ), $this->push->recipients( 'match_reminder' ) );
		self::assertSame( array( $this->p( 'captain_a1' ), $this->p( 'chef_away' ) ), $this->email->recipients( 'match_reminder' ), 'TK-Chef Seefeld hat beides abgewählt' );
	}

	public function test_push_only_without_subscription_falls_back_to_email(): void {
		( new Preferences( $this->db ) )->set( $this->person['captain_h1'], EventTypes::MATCH_REMINDER, false, true );

		$report = $this->notifier->send( $this->reminder(), $this->recipients->persons( array( $this->person['captain_h1'] ) ) );

		self::assertSame( array(), $this->push->sent );
		self::assertSame( array( $this->p( 'captain_h1' ) ), $this->email->recipients( 'match_reminder' ) );
		self::assertSame( 'email', $report[0]['channel'] );
	}

	public function test_failed_push_falls_back_to_email_and_failed_delivery_is_retried_later(): void {
		( new Preferences( $this->db ) )->set( $this->person['captain_h1'], EventTypes::MATCH_REMINDER, false, true );
		$this->push->push_people = array( $this->person['captain_h1'] );
		$this->push->fail        = true;
		$recipient               = $this->recipients->persons( array( $this->person['captain_h1'] ) );

		$report = $this->notifier->send( $this->reminder(), $recipient );
		self::assertSame( array( 'push', 'email' ), array_column( $report, 'channel' ) );
		self::assertSame( array( 'failed', 'sent' ), array_column( $report, 'status' ) );
		self::assertSame( 1, $this->log_count(), 'nur die E-Mail ist protokolliert' );

		$this->push->fail = false;
		$this->notifier->send( $this->reminder(), $recipient );
		self::assertSame( array( $this->p( 'captain_h1' ) ), $this->push->recipients( 'match_reminder' ), 'nächster Versuch klappt' );
		self::assertCount( 1, $this->email->sent );
	}

	public function test_failed_email_is_released_for_a_later_attempt(): void {
		$this->email->fail = true;
		$recipient         = $this->recipients->persons( array( $this->person['captain_a1'] ) );
		self::assertSame( 'failed', $this->notifier->send( $this->reminder(), $recipient )[0]['status'] );
		self::assertSame( 0, $this->log_count() );

		$this->email->fail = false;
		self::assertSame( 'sent', $this->notifier->send( $this->reminder(), $recipient )[0]['status'] );
	}

	public function test_exceptions_of_a_channel_are_reported_and_do_not_stop_others(): void {
		$errors                  = array();
		$this->push->throw       = new \RuntimeException( 'Dienst nicht erreichbar' );
		$this->push->push_people = array( $this->person['captain_h1'] );
		$notifier                = new Notifier(
			new Preferences( $this->db ),
			new NotificationLog( $this->db, $this->clock ),
			array( $this->push, $this->email ),
			static function ( \Throwable $e, string $context ) use ( &$errors ): void {
				$errors[] = $e->getMessage() . ' | ' . $context;
			}
		);
		( new Preferences( $this->db ) )->set( $this->person['captain_h1'], EventTypes::MATCH_REMINDER, true, true );

		$report = $notifier->send( $this->reminder(), $this->recipients->persons( array( $this->person['captain_h1'], $this->person['captain_a1'] ) ) );

		self::assertSame( array( 'failed', 'sent', 'sent' ), array_column( $report, 'status' ) );
		self::assertCount( 1, $errors );
		self::assertStringContainsString( 'Dienst nicht erreichbar | push match_reminder ' . $this->p( 'captain_h1' ), $errors[0] );
	}

	public function test_acting_person_is_left_out(): void {
		$recipients = $this->recipients->sides( $this->repo->context( $this->id['f1'] ) );

		$this->notifier->send( $this->reminder(), $recipients, 101, null );
		self::assertNotContains( $this->p( 'captain_h1' ), $this->email->recipients( 'match_reminder' ), 'über das Konto' );

		$this->notifier->send( $this->reminder( 0, 'x' ), $recipients, null, $this->person['chef_away'] );
		self::assertNotContains( $this->p( 'chef_away' ), array_slice( $this->email->recipients( 'match_reminder' ), 3 ), 'über die Person' );
	}

	public function test_accounts_without_person_are_logged_by_account(): void {
		$tk = $this->recipients->tk();
		$this->notifier->send( $this->reminder(), $tk );
		$this->notifier->send( $this->reminder(), $tk );

		self::assertSame( array( $this->p( 'tk' ), 'u108', $this->p( 'admin' ) ), $this->email->recipients( 'match_reminder' ) );
		self::assertSame( '108', $this->db->get_var( "SELECT user_id FROM {$this->db->table( 'notification_log' )} WHERE person_id = 0" ) );
	}

	public function test_reservation_of_an_aborted_delivery_is_retried_after_the_lease(): void {
		$recipient = $this->recipients->persons( array( $this->person['captain_a1'] ) );
		// Ein Lauf reserviert und endet vor dem Versand (Zeitlimit, schwerer Fehler).
		self::assertNotNull( ( new NotificationLog( $this->db, $this->clock ) )->claim( $recipient[0], $this->reminder(), 'email' ) );

		$this->clock->advance( '+' . ( NotificationLog::LEASE_MINUTES - 1 ) . ' minutes' );
		self::assertSame( 'duplicate', $this->notifier->send( $this->reminder(), $recipient )[0]['status'], 'ein laufender Versand wird nicht doppelt ausgeführt' );
		self::assertSame( array(), $this->email->sent );

		$this->clock->advance( '+1 minute' );
		self::assertSame( 'sent', $this->notifier->send( $this->reminder(), $recipient )[0]['status'] );
		self::assertSame( array( $this->p( 'captain_a1' ) ), $this->email->recipients( 'match_reminder' ) );
		self::assertSame( array( 'sent' ), $this->db->get_col( "SELECT status FROM {$this->db->table( 'notification_log' )}" ) );

		$this->clock->advance( '+1 day' );
		self::assertSame( 'duplicate', $this->notifier->send( $this->reminder(), $recipient )[0]['status'], 'bestätigt bleibt versendet' );
		self::assertCount( 1, $this->email->sent );
	}

	public function test_aborted_push_falls_back_to_email_after_the_lease(): void {
		( new Preferences( $this->db ) )->set( $this->person['captain_h1'], EventTypes::MATCH_REMINDER, false, true );
		$this->push->push_people = array( $this->person['captain_h1'] );
		$recipient               = $this->recipients->persons( array( $this->person['captain_h1'] ) );
		( new NotificationLog( $this->db, $this->clock ) )->claim( $recipient[0], $this->reminder(), 'push' );

		$report = $this->notifier->send( $this->reminder(), $recipient );
		self::assertSame( array( 'duplicate' ), array_column( $report, 'status' ), 'kein Rückfall, solange der Push noch laufen kann' );

		$this->push->fail = true;
		$this->clock->advance( '+' . NotificationLog::LEASE_MINUTES . ' minutes' );
		$report = $this->notifier->send( $this->reminder(), $recipient );
		self::assertSame( array( 'push', 'email' ), array_column( $report, 'channel' ) );
		self::assertSame( array( 'failed', 'sent' ), array_column( $report, 'status' ) );
		self::assertSame( array( $this->p( 'captain_h1' ) ), $this->email->recipients( 'match_reminder' ) );
	}

	public function test_an_expired_reservation_is_taken_over_once_and_stays_with_the_new_owner(): void {
		$log       = new NotificationLog( $this->db, $this->clock );
		$recipient = $this->recipients->persons( array( $this->person['captain_a1'] ) )[0];
		$first     = $log->claim( $recipient, $this->reminder(), 'email' );
		self::assertNotNull( $first );
		$this->clock->advance( '+' . NotificationLog::LEASE_MINUTES . ' minutes' );

		$second = $log->claim( $recipient, $this->reminder(), 'email' );
		self::assertNotNull( $second );
		self::assertNull( $log->claim( $recipient, $this->reminder(), 'email' ), 'nur ein Lauf übernimmt' );

		// Der hängende erste Versuch meldet sich spät zurück: Er ändert die übernommene Reservierung nicht.
		$log->release( $recipient, $this->reminder(), 'email', $first );
		$log->confirm( $recipient, $this->reminder(), 'email', $first );
		self::assertFalse( $log->was_sent( $recipient, $this->reminder(), 'email' ) );
		self::assertSame( 1, $this->log_count() );

		$log->confirm( $recipient, $this->reminder(), 'email', $second );
		self::assertTrue( $log->was_sent( $recipient, $this->reminder(), 'email' ) );
	}

	public function test_old_log_entries_are_purged(): void {
		$log = new NotificationLog( $this->db, $this->clock );
		$this->notifier->send( $this->reminder(), $this->recipients->persons( array( $this->person['captain_a1'] ) ) );
		$this->clock->advance( '+366 days' );
		self::assertSame( 1, $log->purge() );
		self::assertSame( 0, $this->log_count() );
	}
}
