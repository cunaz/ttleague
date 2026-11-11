<?php
/**
 * Integrationstests: Aufgebot und Erinnerungen des Ranglistenturniers.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\RankingTournament;

use TTLeague\Accounts\Preferences;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\NotificationLog;
use TTLeague\Notifications\Notifier;
use TTLeague\Notifications\RecipientResolver;
use TTLeague\RankingTournament\Links;
use TTLeague\RankingTournament\Notices;
use TTLeague\Tests\Integration\Notifications\RecordingChannel;

/**
 * Daten siehe RlTestCase; alle Spielerinnen und Spieler haben eine E-Mail-Adresse. Die Dienste
 * merken das Aufgebot nur vor (Events::callup); versendet wird in Schritten über
 * Notices::send_callups(), wie es in WordPress das Cron-Ereignis tut. Anmeldeschluss ist der
 * 10. September 2026, die erste Runde dauert vom 21. bis 24. September.
 */
final class NoticesTest extends RlTestCase {

	private RecordingChannel $email;

	/** @var list<string> Gemeldete Fehler des Versands. */
	private array $errors = array();

	protected function setUp(): void {
		parent::setUp();
		$this->prepare();
	}

	/**
	 * @param array<string,mixed> $format Zusätze zu format_json der Konkurrenz.
	 */
	private function prepare( array $format = array() ): void {
		$this->seed(
			array(),
			$format + array(
				'categories'            => array(
					array(
						'no'  => 1,
						'min' => 1,
					),
				),
				'registration_deadline' => '2026-09-10',
			)
		);
		$this->email  = new RecordingChannel( 'email' );
		$this->errors = array();
		foreach ( $this->player as $key => $player ) {
			$this->db->update( 'person', array( 'email' => $key . '@example.invalid' ), array( 'id' => $this->seeder->person_of( $player ) ) );
		}
	}

	private function notices(): Notices {
		$links = new class() implements Links {
			public function my_area(): string {
				return 'https://liga.example.org/mein-bereich/';
			}
		};
		return new Notices(
			$this->db,
			$this->clock,
			new \DateTimeZone( 'Europe/Zurich' ),
			new RecipientResolver( $this->db ),
			new Notifier(
				new Preferences( $this->db ),
				new NotificationLog( $this->db, $this->clock ),
				array( $this->email ),
				function ( \Throwable $error, string $context ): void {
					$this->errors[] = $context . ': ' . $error->getMessage();
				}
			),
			$links
		);
	}

	/**
	 * Freigegebene Runde vom 21. bis 24. September mit den angemeldeten Personen.
	 *
	 * @param list<string> $players Schlüssel der Spieler.
	 */
	private function published( array $players ): int {
		$this->register( $players );
		$round = $this->round( '2026-09-21' );
		$this->rounds()->propose( $this->tk, $round );
		$this->rounds()->publish( $this->tk, $round );
		return $round;
	}

	private function key( string $player ): string {
		return 'p' . $this->seeder->person_of( $this->player[ $player ] );
	}

	/**
	 * @param list<string> $players Schlüssel der Spieler.
	 * @return list<string> Empfänger, sortiert.
	 */
	private function keys( array $players ): array {
		$keys = array_map( fn( string $player ): string => $this->key( $player ), $players );
		sort( $keys );
		return $keys;
	}

	/**
	 * @return list<string> Empfänger einer Art, sortiert.
	 */
	private function sent( string $event ): array {
		$keys = $this->email->recipients( $event );
		sort( $keys );
		return $keys;
	}

	public function test_publishing_sends_nothing_until_the_step_runs(): void {
		$this->register( array( 's1', 's2', 'w1', 'w2' ) );
		$round = $this->round( '2026-09-21' );
		$this->rounds()->propose( $this->tk, $round );
		self::assertSame( array(), $this->notices()->rounds_with_pending_callups(), 'Eine Runde in Planung hat kein Aufgebot.' );
		$group = $this->repo()->groups( $round )[0];

		// Die Freigabe merkt den Versand nur vor.
		$this->rounds()->publish( $this->tk, $round );
		self::assertSame( array(), $this->email->sent );
		self::assertSame( array( $round ), array_column( $this->events->callups, 'round' ) );
		self::assertSame( array( $round ), $this->notices()->rounds_with_pending_callups() );

		$result = $this->notices()->send_callups( $round );

		self::assertSame(
			array(
				'sent'   => 4,
				'failed' => 0,
				'next'   => null,
			),
			$result
		);
		self::assertSame( $this->keys( array( 's1', 's2', 'w1', 'w2' ) ), $this->sent( 'rl_callup' ) );
		$mail = $this->email->sent[0];
		self::assertSame( 'rl_group', $mail['ref_type'] );
		self::assertSame( $group['id'], $mail['ref_id'] );
		self::assertSame( 'Aufgebot Ranglistenturnier: 1. Runde, Kategorie 1, Gruppe 1', $mail['subject'] );
		self::assertSame( EventTypes::RL_CALLUP, $mail['notification']->type );
		$text = implode( "\n", $mail['notification']->paragraphs );
		// Lokal, Adresse, Abend, Zeit, Mitspielende, Gruppenchef.
		self::assertStringContainsString( 'Wann: Mo, 21.9.2026, 19:15', $text );
		self::assertStringContainsString( 'Wo: Halle Seefeld, Musterstrasse 1, 8000 Musterstadt (Platzclub Seefeld)', $text );
		self::assertStringContainsString( 'Spieler1 Wollishofener (Wollishofen)', $text );
		self::assertStringContainsString( 'Gruppenchef: Spieler1 Seefelder.', $text );
		self::assertSame( 'https://liga.example.org/mein-bereich/', $mail['notification']->action['url'] );

		// Nach vollständigem Versand geht nichts mehr hinaus, und nichts steht mehr aus.
		self::assertSame(
			array(
				'sent'   => 0,
				'failed' => 0,
				'next'   => null,
			),
			$this->notices()->send_callups( $round )
		);
		self::assertCount( 4, $this->email->sent );
		self::assertSame( array(), $this->notices()->rounds_with_pending_callups() );
	}

	public function test_a_step_sends_one_batch_and_names_the_rest(): void {
		// Acht Aufgebotene in zwei Gruppen; eine Person hat keine Adresse und zählt nicht zum Stapel.
		$round   = $this->published( array( 's1', 's2', 'w1', 'w2', 'o1', 'o2', 'a1', 'a2' ) );
		$persons = array();
		foreach ( $this->repo()->groups( $round ) as $group ) {
			$persons = array_merge( $persons, array_column( $group['members'], 'person_id' ) );
		}
		sort( $persons );
		self::assertCount( 8, $persons );
		$this->db->update( 'person', array( 'email' => null ), array( 'id' => $persons[1] ) );
		$reachable = array_values( array_diff( $persons, array( $persons[1] ) ) );
		$notices   = $this->notices();

		// Erster Schritt: drei Versände in der Reihenfolge der Person-ID, der Rest folgt nach der dritten.
		$first = $notices->send_callups( $round, 0, 3 );
		self::assertSame(
			array(
				'sent'   => 3,
				'failed' => 0,
				'next'   => $reachable[2],
			),
			$first
		);
		self::assertSame( array( 'p' . $reachable[0], 'p' . $reachable[1], 'p' . $reachable[2] ), $this->email->recipients( 'rl_callup' ) );

		// Fortsetzung über die Person-ID.
		$second = $notices->send_callups( $round, $first['next'], 3 );
		self::assertSame( array( 3, $reachable[5] ), array( $second['sent'], $second['next'] ) );
		$third = $notices->send_callups( $round, $second['next'], 3 );
		self::assertSame(
			array(
				'sent'   => 1,
				'failed' => 0,
				'next'   => null,
			),
			$third
		);
		$sent = array_map( static fn( string $key ): int => (int) substr( $key, 1 ), $this->email->recipients( 'rl_callup' ) );
		self::assertSame( $reachable, $sent, 'Jede erreichbare Person genau einmal.' );

		// Genau an der Grenze: Ein Stapel in der Grösse des Rests lässt keinen Rest.
		$this->db->query( "DELETE FROM {$this->db->table( 'notification_log' )}" );
		$this->email->sent = array();
		self::assertSame( array( 7, null ), array_values( array_intersect_key( $notices->send_callups( $round, 0, 7 ), array_flip( array( 'sent', 'next' ) ) ) ) );
		// Wer ohne Adresse bleibt, hält den stündlichen Lauf nicht in Gang: Gibt es niemand Erreichbaren mehr, steht nichts aus.
		$this->db->update( 'person', array( 'status' => 'inactive' ), array( 'id' => $persons[1] ) );
		self::assertSame( array(), $notices->rounds_with_pending_callups() );
	}

	public function test_changes_reach_only_the_changed_group_and_not_absent_players(): void {
		$round         = $this->published( array( 's1', 's2', 'w1', 'w2', 'o1', 'o2', 'a1', 'a2' ) );
		$rounds        = $this->rounds();
		[ $one, $two ] = $this->repo()->groups( $round );
		self::assertSame( 8, $this->notices()->send_callups( $round )['sent'] );
		$this->email->sent = array();

		// Eine Person meldet sich für die Runde ab: Für die anderen ändert sich das Aufgebot nicht.
		$absent = $one['members'][0]['player_id'];
		$this->registrations()->withdraw_from_round( $this->tk, $absent, $round );
		self::assertSame( array(), $this->notices()->rounds_with_pending_callups() );
		self::assertSame( 0, $this->notices()->send_callups( $round )['sent'] );

		// Danach verschiebt die TK diese Gruppe.
		$rounds->save_group( $this->tk, $round, $one['id'], array( 'start_at' => '2026-09-24 20:00' ) );
		self::assertSame( array(), $this->email->sent, 'Auch die Änderung merkt den Versand nur vor.' );
		self::assertSame( array( $round ), $this->notices()->rounds_with_pending_callups() );
		$this->notices()->send_callups( $round );

		$expected = array();
		foreach ( array_slice( $one['members'], 1 ) as $member ) {
			$expected[] = 'p' . $member['person_id'];
		}
		sort( $expected );
		self::assertSame( $expected, $this->sent( 'rl_callup' ), 'Nur die aufgebotenen Mitglieder der geänderten Gruppe.' );
		self::assertStringStartsWith( 'Geändertes Aufgebot Ranglistenturnier:', $this->email->sent[0]['subject'] );
		self::assertStringContainsString( 'Wann: Do, 24.9.2026, 20:00', implode( "\n", $this->email->sent[0]['notification']->paragraphs ) );
		self::assertStringNotContainsString( $one['members'][0]['name'], implode( "\n", $this->email->sent[0]['notification']->paragraphs ), 'Abgemeldete stehen nicht unter den Mitspielenden.' );

		// Zurück auf die alte Zeit ist wieder eine Änderung: Das Aufgebot geht erneut hinaus.
		$this->email->sent = array();
		$rounds->save_group( $this->tk, $round, $one['id'], array( 'start_at' => $one['start_at'] ) );
		$this->notices()->send_callups( $round );
		self::assertSame( $expected, $this->sent( 'rl_callup' ) );
		self::assertSame( array(), array_intersect( $this->sent( 'rl_callup' ), array_map( static fn( array $m ): string => 'p' . $m['person_id'], $two['members'] ) ) );

		// Wer neu in die Gruppe kommt, erhält ein Aufgebot, die bisherigen ein geändertes.
		$this->email->sent = array();
		$moved             = $two['members'][0];
		$rounds->save_group( $this->tk, $round, $one['id'], array( 'members' => array_merge( array_column( $one['members'], 'player_id' ), array( $moved['player_id'] ) ) ) );
		$this->notices()->send_callups( $round );
		$subjects = array();
		foreach ( $this->email->sent as $mail ) {
			$subjects[ $mail['to'] ] = strtok( $mail['subject'], ':' );
		}
		// Die gewechselte Person hatte schon ein Aufgebot dieser Runde (für ihre alte Gruppe).
		self::assertSame( 'Geändertes Aufgebot Ranglistenturnier', $subjects[ 'p' . $moved['person_id'] ] );
		self::assertCount( 3 + 1 + 3, $subjects, 'Drei bisherige und die neue Person der Gruppe, dazu die drei der verlassenen Gruppe.' );
	}

	public function test_first_callup_after_a_late_registration_is_not_called_changed(): void {
		$round = $this->published( array( 's1', 's2', 'w1', 'w2' ) );
		$this->notices()->send_callups( $round );
		$this->email->sent = array();

		// Die TK meldet nach und nimmt die Person in die Gruppe.
		$this->register( array( 'o1' ) );
		$group = $this->repo()->groups( $round )[0];
		$this->rounds()->save_group( $this->tk, $round, $group['id'], array( 'members' => array_merge( array_column( $group['members'], 'player_id' ), array( $this->player['o1'] ) ) ) );
		$this->notices()->send_callups( $round );

		$subjects = array_column( $this->email->sent, 'subject', 'to' );
		self::assertCount( 5, $subjects );
		self::assertStringStartsWith( 'Aufgebot Ranglistenturnier:', $subjects[ $this->key( 'o1' ) ] );
		self::assertStringStartsWith( 'Geändertes Aufgebot Ranglistenturnier:', $subjects[ $this->key( 's1' ) ] );
	}

	public function test_a_failing_recipient_does_not_stop_the_others(): void {
		$round  = $this->published( array( 's1', 's2', 'w1', 'w2' ) );
		$broken = $this->key( 's2' );
		// Der Versand an eine Person scheitert mit einer Ausnahme (z. B. Mailserver lehnt die Adresse ab).
		$this->email->during = static function ( \TTLeague\Notifications\Recipient $recipient ) use ( $broken ): void {
			if ( $recipient->key() === $broken ) {
				throw new \RuntimeException( 'Zustellung abgelehnt' );
			}
		};

		$result = $this->notices()->send_callups( $round );

		self::assertSame(
			array(
				'sent'   => 3,
				'failed' => 1,
				'next'   => null,
			),
			$result
		);
		self::assertSame( $this->keys( array( 's1', 'w1', 'w2' ) ), $this->sent( 'rl_callup' ) );
		self::assertCount( 1, $this->errors );
		self::assertStringContainsString( 'rl_callup ' . $broken, $this->errors[0] );
		// Der gescheiterte Versand steht weiter aus; der nächste Schritt holt genau ihn nach.
		self::assertSame( array( $round ), $this->notices()->rounds_with_pending_callups() );
		$this->email->during = null;
		$this->email->sent   = array();
		self::assertSame( 1, $this->notices()->send_callups( $round )['sent'] );
		self::assertSame( array( $broken ), $this->sent( 'rl_callup' ) );
	}

	public function test_no_callup_after_the_evening_or_the_closing(): void {
		$round = $this->published( array( 's1', 's2', 'w1', 'w2' ) );
		$group = $this->repo()->groups( $round )[0];
		self::assertSame( '2026-09-21 19:15:00', $group['start_at'] );

		// Eine Minute vor Spielbeginn (Ortszeit) steht das Aufgebot noch aus, danach nicht mehr.
		$this->clock = new FixedClock( '2026-09-21 17:14:00' );
		self::assertSame( array( $round ), $this->notices()->rounds_with_pending_callups() );
		$this->clock = new FixedClock( '2026-09-21 17:16:00' );
		self::assertSame( array(), $this->notices()->rounds_with_pending_callups() );
		self::assertSame( 0, $this->notices()->send_callups( $round )['sent'] );

		// Abgeschlossene Runde: nichts, auch wenn der Termin in der Zukunft läge.
		$this->clock = new FixedClock( '2026-09-01 10:00:00' );
		$this->entry()->save( $group['id'], $this->sheet( $group['id'] ), $this->tk );
		$this->rounds()->close( $this->tk, $round );
		self::assertSame( array(), $this->notices()->rounds_with_pending_callups() );
		self::assertSame( 0, $this->notices()->send_callups( $round )['sent'] );
		self::assertSame( array(), $this->email->sent );
	}

	public function test_callup_respects_the_notification_settings(): void {
		( new Preferences( $this->db ) )->set( $this->seeder->person_of( $this->player['s2'] ), EventTypes::RL_CALLUP, false, false );
		$round = $this->published( array( 's1', 's2' ) );

		// Wer das Aufgebot abgewählt hat, kostet keinen Versand und zählt nicht.
		self::assertSame( 1, $this->notices()->send_callups( $round, 0, 1 )['sent'] );
		self::assertSame( array( $this->key( 's1' ) ), $this->sent( 'rl_callup' ) );
	}

	public function test_reminder_before_the_registration_deadline(): void {
		// Nur die Erinnerung vor dem Anmeldeschluss (drei Tage davor bis und mit Anmeldeschluss).
		$this->prepare( array( 'blocked_reminder_days' => 0 ) );
		$this->register( array( 's1', 's2', 's3' ) );
		$round = $this->round( '2026-09-21' );
		$this->registrations()->set_blocked_dates( $this->actor( 's2' ), $this->player['s2'], $round, array() );
		$this->registrations()->withdraw( $this->tk, $this->player['s3'] );

		// 6. September, 12 Uhr: vier Tage vorher, noch nichts.
		$this->clock = new FixedClock( '2026-09-06 10:00:00' );
		self::assertSame( array(), $this->notices()->reminders() );
		// 7. September, 6:59 Uhr Ortszeit: Ruhezeit. Um 7 Uhr geht die Erinnerung hinaus.
		$this->clock = new FixedClock( '2026-09-07 04:59:00' );
		self::assertSame( array(), $this->notices()->reminders() );
		$this->clock = new FixedClock( '2026-09-07 05:00:00' );
		$report      = $this->notices()->reminders();

		self::assertCount( 1, $report );
		// s1 hat nichts gemeldet; s2 hat «kein Abend gesperrt» gemeldet, s3 ist abgemeldet.
		self::assertSame( array( $this->key( 's1' ) ), $this->sent( 'rl_reminder' ) );
		$mail = $this->email->sent[0];
		self::assertSame( array( 'rl_round', $round, 'deadline' ), array( $mail['ref_type'], $mail['ref_id'], $mail['ref_key'] ) );
		self::assertSame( EventTypes::RL_REMINDER, $mail['notification']->type );
		self::assertSame( 'Ranglistenturnier: Sperrdaten bis zum Anmeldeschluss am 10.9.2026', $mail['subject'] );
		self::assertStringContainsString( '1. Runde (21.–24.9.2026)', $mail['notification']->paragraphs[0] );

		// Jede Person einmal, auch in späteren Läufen.
		$this->clock = new FixedClock( '2026-09-08 10:00:00' );
		$this->notices()->reminders();
		self::assertCount( 1, $this->email->sent );
	}

	public function test_deadline_reminder_ends_with_the_deadline_day(): void {
		$this->prepare( array( 'blocked_reminder_days' => 0 ) );
		$this->register( array( 's1' ) );
		$this->round( '2026-09-21' );

		// Am Tag nach dem Anmeldeschluss nicht mehr, am Tag selbst (21:59 Uhr Ortszeit) noch.
		$this->clock = new FixedClock( '2026-09-11 10:00:00' );
		self::assertSame( array(), $this->notices()->reminders() );
		$this->clock = new FixedClock( '2026-09-10 19:59:00' );
		self::assertCount( 1, $this->notices()->reminders() );
		// Ab 22 Uhr ist Ruhezeit.
		$this->prepare( array( 'blocked_reminder_days' => 0 ) );
		$this->register( array( 's1' ) );
		$this->round( '2026-09-21' );
		$this->clock = new FixedClock( '2026-09-10 20:00:00' );
		self::assertSame( array(), $this->notices()->reminders() );
	}

	public function test_reminder_before_the_round_is_divided(): void {
		// Nur die Erinnerung vor der Einteilung: ab zehn Tagen vor Beginn der Rundenwoche.
		$this->prepare( array( 'reminder_days' => 0 ) );
		$this->register( array( 's1', 's2', 'w1', 'w2' ) );
		$round = $this->round( '2026-09-21' );
		$this->registrations()->set_blocked_dates( $this->actor( 'w2' ), $this->player['w2'], $round, array( '2026-09-22' ) );

		// 10. September: elf Tage vorher, nichts. 11. September: zehn Tage vorher.
		$this->clock = new FixedClock( '2026-09-10 10:00:00' );
		self::assertSame( array(), $this->notices()->reminders() );
		$this->clock = new FixedClock( '2026-09-11 10:00:00' );
		$this->notices()->reminders();
		self::assertSame( $this->keys( array( 's1', 's2', 'w1' ) ), $this->sent( 'rl_reminder' ) );
		self::assertSame( 'blocked', $this->email->sent[0]['ref_key'] );
		self::assertSame( 'Ranglistenturnier: Sperrdaten für die 1. Runde fehlen', $this->email->sent[0]['subject'] );

		// Ist die Runde eingeteilt, erinnert nichts mehr; auch nicht, wer neu dazukommt.
		$this->register( array( 'o1' ) );
		$this->rounds()->propose( $this->tk, $round );
		$this->clock = new FixedClock( '2026-09-12 10:00:00' );
		self::assertSame( array(), $this->notices()->reminders() );
		self::assertCount( 3, $this->email->sent );
	}

	public function test_both_reminders_use_separate_occasions_and_the_later_one_wins(): void {
		// Standardwerte: drei Tage vor dem Anmeldeschluss, zehn Tage vor der Runde. Beginnt die
		// Runde am 14. September, sind am 8. September beide fällig: Es geht nur «blocked» hinaus.
		$this->register( array( 's1' ) );
		$round       = $this->round( '2026-09-14' );
		$this->clock = new FixedClock( '2026-09-08 10:00:00' );
		$report      = $this->notices()->reminders();

		self::assertCount( 1, $report );
		self::assertSame( array( 'blocked' ), array_column( $this->email->sent, 'ref_key' ) );
		self::assertSame( $round, $this->email->sent[0]['ref_id'] );
	}

	public function test_without_planned_round_or_participants_nothing_is_sent(): void {
		$this->clock = new FixedClock( '2026-09-09 10:00:00' );
		self::assertSame( array(), $this->notices()->reminders() );
		$this->register( array( 's1' ) );
		self::assertSame( array(), $this->notices()->reminders(), 'Ohne Runde in Planung gibt es keine Sperrdaten zu melden.' );
		self::assertSame( array(), $this->email->sent );
	}
}
