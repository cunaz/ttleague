<?php
/**
 * Integrationstests: Aufgebot und Erinnerungen des Ranglistenturniers.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\RankingTournament;

use TTLeague\Accounts\Preferences;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\NotificationLog;
use TTLeague\Notifications\Notifier;
use TTLeague\Notifications\RecipientResolver;
use TTLeague\RankingTournament\Events;
use TTLeague\RankingTournament\Links;
use TTLeague\RankingTournament\Notices;
use TTLeague\RankingTournament\Rounds;
use TTLeague\Tests\Integration\Notifications\RecordingChannel;

/**
 * Daten siehe RlTestCase; alle Spielerinnen und Spieler haben eine E-Mail-Adresse. Die Dienste
 * lösen das Aufgebot über dieselbe Verdrahtung aus wie WordPressEvents. Anmeldeschluss ist der
 * 10. September 2026, die erste Runde dauert vom 21. bis 24. September.
 */
final class NoticesTest extends RlTestCase {

	private RecordingChannel $email;

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
		$this->email = new RecordingChannel( 'email' );
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
			new Notifier( new Preferences( $this->db ), new NotificationLog( $this->db, $this->clock ), array( $this->email ) ),
			$links
		);
	}

	/**
	 * Runden-Dienst, der das Aufgebot wie in WordPress über Notices verschickt.
	 */
	private function sending_rounds(): Rounds {
		$notices = $this->notices();
		$events  = new class( $notices ) implements Events {
			public function __construct( private readonly Notices $notices ) {}

			public function changed( string $area, int $id, bool $games = false ): void {
			}

			public function callup( int $round_id, array $group_ids, int $user_id, bool $changed ): void {
				$this->notices->callup( $group_ids, $changed );
			}
		};
		return new Rounds( $this->db, $this->clock, new AuditLog( $this->db ), $events );
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

	public function test_publishing_calls_up_everyone_once(): void {
		$this->register( array( 's1', 's2', 'w1', 'w2' ) );
		$round  = $this->round( '2026-09-21' );
		$rounds = $this->sending_rounds();
		$rounds->propose( $this->tk, $round );
		self::assertSame( array(), $this->email->sent, 'Der Vorschlag verschickt nichts.' );
		$group = $this->repo()->groups( $round )[0];

		$rounds->publish( $this->tk, $round );

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

		// Dasselbe Aufgebot geht nicht noch einmal hinaus.
		$this->notices()->callup( array( $group['id'] ), false );
		self::assertCount( 4, $this->email->sent );
	}

	public function test_changes_reach_only_the_changed_group_and_not_absent_players(): void {
		$this->register( array( 's1', 's2', 'w1', 'w2', 'o1', 'o2', 'a1', 'a2' ) );
		$round  = $this->round( '2026-09-21' );
		$rounds = $this->sending_rounds();
		$rounds->propose( $this->tk, $round );
		$rounds->publish( $this->tk, $round );
		[ $one, $two ] = $this->repo()->groups( $round );
		self::assertCount( 8, $this->email->sent );
		$this->email->sent = array();

		// Eine Person meldet sich für die Runde ab; danach verschiebt die TK ihre Gruppe.
		$absent = $one['members'][0]['player_id'];
		$this->registrations()->withdraw_from_round( $this->tk, $absent, $round );
		$rounds->save_group( $this->tk, $round, $one['id'], array( 'start_at' => '2026-09-24 20:00' ) );

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
		self::assertSame( $expected, $this->sent( 'rl_callup' ) );
		self::assertSame( array(), array_intersect( $this->sent( 'rl_callup' ), array_map( static fn( array $m ): string => 'p' . $m['person_id'], $two['members'] ) ) );
	}

	public function test_callup_respects_the_notification_settings(): void {
		$this->register( array( 's1', 's2' ) );
		$round = $this->round( '2026-09-21' );
		( new Preferences( $this->db ) )->set( $this->seeder->person_of( $this->player['s2'] ), EventTypes::RL_CALLUP, false, false );
		$rounds = $this->sending_rounds();
		$rounds->propose( $this->tk, $round );
		$rounds->publish( $this->tk, $round );

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
