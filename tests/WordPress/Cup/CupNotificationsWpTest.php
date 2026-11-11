<?php
/**
 * Mitteilungen des Cups in WordPress: Versand in Schritten über WP-Cron, Auffangnetz im
 * stündlichen Lauf, direkte Mitteilungen, Erinnerung vor der Frist.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Cup;

use TTLeague\Cup\Cup;
use TTLeague\Cup\CupEvents;
use TTLeague\Cup\CupNotifications;
use TTLeague\Notifications\Notifications;

/**
 * Daten siehe CupWpTestCase: acht Mannschaften aus vier Clubs. Jede Paarung der ersten Runde
 * erreicht sechs Personen (vier Spieler und die TK-Chefs der beiden Clubs), zusammen 24
 * Mitteilungen. Das Versandfenster ist für die Tests ganztägig offen.
 */
final class CupNotificationsWpTest extends CupWpTestCase {

	/** @var list<string> */
	private array $errors = array();

	public function set_up(): void {
		parent::set_up();
		$this->errors = array();
		add_filter(
			'ttleague_notification_hours',
			static fn(): array => array(
				'from_hour'   => 0,
				'until_hour'  => 24,
				'digest_hour' => 7,
			)
		);
		add_action(
			'ttleague_notification_error',
			function ( \Throwable $error, string $context ): void {
				$this->errors[] = $context . ': ' . $error->getMessage();
			},
			10,
			2
		);
	}

	/**
	 * Eingeplante Versandschritte als Listen ihrer Argumente (Konkurrenz, Paarung davor).
	 *
	 * @return list<list<int>>
	 */
	private function scheduled(): array {
		$steps = array();
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( $hooks[ Cup::ROUND_HOOK ] ?? array() as $event ) {
				$steps[] = array_map( 'intval', $event['args'] );
			}
		}
		return $steps;
	}

	/**
	 * Zweite TK-Chefin je Club: Damit erreicht eine Paarung acht Personen, die Auslosung 32.
	 */
	private function second_chefs(): void {
		foreach ( $this->club as $key => $club ) {
			$person = $this->seeder->person( 'Stellvertretung', ucfirst( $key ) );
			$this->db->update( 'person', array( 'email' => 'vize.' . $key . '@example.invalid' ), array( 'id' => $person ) );
			$this->seeder->role( $this->season, $club, $person, 'tk_chef' );
		}
	}

	public function test_the_release_only_schedules_and_the_steps_send_in_batches(): void {
		$this->second_chefs();
		$this->released();

		self::assertSame( array( array( $this->cup, 0 ) ), $this->scheduled(), 'ein einmaliges Ereignis ab der ersten Paarung' );
		self::assertSame( array(), $this->mails );
		self::assertSame( 10, has_action( Cup::ROUND_HOOK, array( Cup::class, 'send_round' ) ), 'der Schritt hängt am einmaligen Ereignis' );

		Cup::send_round( $this->cup, 0 );

		self::assertCount( CupNotifications::ROUND_BATCH, $this->mails, 'ein Schritt versendet höchstens einen Stapel' );
		self::assertSame( 'notification_cup_round', $this->mails[0]->type );
		self::assertSame( 'spieler.seefeld1@example.invalid', $this->mails[0]->to );
		self::assertStringContainsString( 'Mannschaftscup A, Viertelfinal: Seefeld 1 – Wollishofen 1', $this->mails[0]->subject );
		self::assertStringContainsString( '/erfassen/?begegnung=' . $this->tie( 1, 1 )['fixture']['id'], $this->mails[0]->text );
		$rest = array( $this->cup, $this->tie( 1, 3 )['id'] );
		self::assertContains( $rest, $this->scheduled(), 'der Rest ist ab der angefangenen Paarung eingeplant' );

		// Im selben PHP-Prozess läuft kein zweiter Stapel: Der Schritt plant sich neu ein.
		wp_unschedule_hook( Cup::ROUND_HOOK );
		Cup::send_round( ...$rest );
		self::assertCount( CupNotifications::ROUND_BATCH, $this->mails );
		self::assertSame( array( $rest ), $this->scheduled() );

		// In der nächsten Anfrage folgt der Rest, ohne Doppel.
		Cup::reset_request();
		wp_unschedule_hook( Cup::ROUND_HOOK );
		Cup::send_round( ...$rest );
		self::assertCount( 32, $this->mails );
		self::assertSame( array(), $this->scheduled(), 'nichts mehr offen' );
		$per_person = array_count_values( array_map( static fn( $mail ): string => $mail->to . '|' . $mail->subject, $this->mails ) );
		self::assertSame( 32, count( $per_person ), 'niemand erhält dieselbe Mitteilung zweimal' );
		self::assertSame( array(), $this->errors );
	}

	public function test_the_hourly_run_only_reschedules_what_is_pending(): void {
		$this->released();
		// Das Ereignis der Freigabe geht verloren.
		wp_unschedule_hook( Cup::ROUND_HOOK );

		self::assertSame( 20, has_action( Notifications::CRON_HOOK, array( Cup::class, 'catch_up_rounds' ) ), 'am stündlichen Lauf der Benachrichtigungen' );
		Cup::catch_up_rounds();

		self::assertSame( array( array( $this->cup, 0 ) ), $this->scheduled(), 'der stündliche Lauf plant den Versand neu ein' );
		self::assertSame( array(), array_filter( $this->mails, static fn( $mail ): bool => 'notification_cup_round' === $mail->type ), 'und versendet selbst nichts' );

		// Wartet schon ein Schritt, kommt kein zweiter dazu.
		Cup::catch_up_rounds();
		self::assertCount( 1, $this->scheduled() );

		// Nach dem Versand gibt es nichts mehr nachzuholen.
		wp_unschedule_hook( Cup::ROUND_HOOK );
		Cup::send_round( $this->cup, 0 );
		self::assertCount( 24, $this->mails );
		Cup::catch_up_rounds();
		self::assertSame( array(), $this->scheduled() );
		self::assertCount( 24, $this->mails );
	}

	public function test_a_step_that_cannot_be_scheduled_is_reported(): void {
		$this->teams( 8 );
		add_filter( 'pre_schedule_event', static fn() => new \WP_Error( 'kaputt', 'Cron gesperrt' ) );

		$log      = (string) tempnam( get_temp_dir(), 'ttl-log' );
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Fehlerprotokoll im Test umleiten.
		try {
			Cup::on_changed( $this->cup, CupEvents::DRAW_RELEASED, $this->users['tk'] );
		} finally {
			ini_set( 'error_log', false === $previous ? '' : $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- siehe oben.
		}
		$logged = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Protokoll aus dem Test.
		wp_delete_file( $log );

		self::assertStringContainsString( 'liess sich nicht einplanen', $logged, 'auch im Fehlerprotokoll' );
		self::assertSame( array(), $this->scheduled() );
		self::assertCount( 1, $this->errors );
		self::assertStringContainsString( 'Cup-Konkurrenz ' . $this->cup . ': Versand der Cup-Mitteilungen liess sich nicht einplanen (kaputt: Cron gesperrt).', $this->errors[0] );
	}

	public function test_a_single_new_fixture_is_announced_directly(): void {
		$this->released();
		wp_unschedule_hook( Cup::ROUND_HOOK );
		$this->mails = array();

		$this->play( $this->tie( 1, 1 )['fixture']['id'], 'home' );
		$this->play( $this->tie( 1, 2 )['fixture']['id'], 'away' );

		$round = array_values( array_filter( $this->mails, static fn( $mail ): bool => 'notification_cup_round' === $mail->type ) );
		self::assertCount( 6, $round, 'vier Spieler und zwei TK-Chefs der neuen Paarung' );
		self::assertStringContainsString( 'Mannschaftscup A, Halbfinal: Seefeld 1 – Altstetten 1', $round[0]->subject );
		self::assertStringContainsString( 'Ort und Termin setzt die TK an', $round[0]->text );
		self::assertSame( array(), $this->scheduled(), 'ohne Umweg über WP-Cron' );
	}

	public function test_the_deadline_reminder_runs_with_the_hourly_deadlines(): void {
		$this->released();
		wp_unschedule_hook( Cup::ROUND_HOOK );
		// Frist der ersten Paarung in sieben Tagen, der zweiten in acht; die dritte hat einen Termin.
		$this->db->update( 'cup_tie', array( 'deadline' => $this->date( 7 ) ), array( 'id' => $this->tie( 1, 1 )['id'] ) );
		$this->db->update( 'cup_tie', array( 'deadline' => $this->date( 8 ) ), array( 'id' => $this->tie( 1, 2 )['id'] ) );
		$this->db->update( 'cup_tie', array( 'deadline' => $this->date( 3 ) ), array( 'id' => $this->tie( 1, 3 )['id'] ) );
		$this->db->update( 'fixture', array( 'scheduled_at' => $this->date( 2 ) . ' 19:00:00' ), array( 'id' => $this->tie( 1, 3 )['fixture']['id'] ) );

		Cup::run_deadlines();

		$reminders = array_values( array_filter( $this->mails, static fn( $mail ): bool => 'notification_cup_deadline' === $mail->type ) );
		self::assertCount( 6, $reminders, 'nur die Paarung, deren Frist in sieben Tagen abläuft' );
		self::assertSame( 'Cup: Die Frist läuft ab, Seefeld 1 – Wollishofen 1', $reminders[0]->subject );
		self::assertNotFalse( has_action( Notifications::CRON_HOOK, array( Cup::class, 'run_deadlines' ) ), 'am stündlichen Lauf der Fristen, ohne eigenen Cron' );

		Cup::run_deadlines();
		self::assertCount( 6, array_filter( $this->mails, static fn( $mail ): bool => 'notification_cup_deadline' === $mail->type ), 'einmal pro Frist' );
		self::assertSame( array(), $this->errors );
	}

	public function test_deactivation_removes_pending_steps(): void {
		$this->released();
		self::assertNotSame( array(), $this->scheduled() );

		Cup::deactivate();

		self::assertSame( array(), $this->scheduled() );
	}
}
