<?php
/**
 * Versand per E-Mail in WordPress: ein Fehlschlag von wp_mail wird gemeldet, eine absichtlich
 * unterdrückte Nachricht nicht.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Notifications;

use TTLeague\Infrastructure\Mail\Mailer;
use TTLeague\Infrastructure\Mail\Message;
use TTLeague\Notifications\Notification;
use TTLeague\Notifications\Notifications;
use TTLeague\Notifications\Recipient;
use TTLeague\Tests\WordPress\TestCase;

final class MailFailureTest extends TestCase {

	/** @var list<string> */
	private array $errors = array();

	public function set_up(): void {
		parent::set_up();
		$this->errors = array();
		add_action(
			'ttleague_notification_error',
			function ( \Throwable $error, string $context ): void {
				$this->errors[] = $error->getMessage() . ' | ' . $context;
			},
			10,
			2
		);
	}

	public function test_mailer_tells_a_failure_from_a_suppressed_message(): void {
		$mailer  = new Mailer();
		$message = new Message( 'person@example.org', 'Betreff', 'Text' );

		self::assertTrue( $mailer->send_or_throw( $message ) );

		add_filter( 'pre_wp_mail', '__return_false' );
		self::assertFalse( $mailer->send( $message ), 'send() bleibt bei false' );
		try {
			$mailer->send_or_throw( $message );
			self::fail( 'Ein Fehlschlag von wp_mail muss eine Ausnahme werfen.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringNotContainsString( 'person@example.org', $e->getMessage() );
		}
		remove_filter( 'pre_wp_mail', '__return_false' );

		add_filter( 'ttleague_mail', '__return_false', 20 );
		self::assertFalse( $mailer->send_or_throw( $message ), 'unterdrückt ist kein Fehler' );
	}

	public function test_failed_email_of_a_notification_is_reported(): void {
		add_filter( 'pre_wp_mail', '__return_false' );
		$report = $this->send();

		self::assertSame( array( 'failed' ), array_column( $report, 'status' ) );
		self::assertSame( array( 'wp_mail meldet einen Fehler, die E-Mail ist nicht versendet. | email result_disputed p990001' ), $this->errors );
	}

	public function test_suppressed_email_of_a_notification_is_not_reported(): void {
		add_filter( 'ttleague_mail', '__return_false', 20 );
		$report = $this->send();

		self::assertSame( array( 'failed' ), array_column( $report, 'status' ), 'nicht versendet, die Reservierung ist frei' );
		self::assertSame( array(), $this->errors );
	}

	/**
	 * Eine Sofortmeldung über die Kanäle der Website; das Fehlerprotokoll geht in eine Datei.
	 *
	 * @return list<array{recipient:string,channel:string,status:string}>
	 */
	private function send(): array {
		$recipient    = new Recipient( 990001, null, 'person@example.org', 'Erfundene Person', 'Erfundene' );
		$notification = new Notification( 'result_disputed', 'result_disputed', 'fixture_event', 990001, '', 'Einsprache', array( 'Text' ), array(), null, 'https://example.org/mein-bereich/', array( 'title' => 'Einsprache' ) );
		$log          = (string) tempnam( get_temp_dir(), 'ttl-log' );
		$previous     = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Fehlerprotokoll im Test umleiten.
		try {
			return Notifications::notifier()->send( $notification, array( $recipient ) );
		} finally {
			ini_set( 'error_log', false === $previous ? '' : $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- siehe oben.
			wp_delete_file( $log );
		}
	}
}
