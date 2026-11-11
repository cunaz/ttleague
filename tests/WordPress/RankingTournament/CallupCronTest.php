<?php
/**
 * Versand der Aufgebote über WP-Cron in begrenzten Schritten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\RankingTournament;

use TTLeague\Notifications\Notifications;
use TTLeague\RankingTournament\Notices;
use TTLeague\RankingTournament\RankingTournament;
use TTLeague\Tests\Integration\Frontend\Seeder;

/**
 * Freigabe und Änderung einer Gruppe merken den Versand nur vor (einmaliges Ereignis
 * ttleague_rl_callups). Die Tests spielen WP-Cron mit run_callup_events().
 */
final class CallupCronTest extends RlTestCase {

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

	public function test_hooks_are_registered(): void {
		self::assertNotFalse( has_action( RankingTournament::CALLUP_HOOK, array( RankingTournament::class, 'send_callups' ) ) );
		self::assertNotFalse( has_action( Notifications::CRON_HOOK, array( RankingTournament::class, 'catch_up_callups' ) ) );
	}

	public function test_publishing_only_schedules_the_callups(): void {
		$this->register( array( 'h1', 'h2', 'a1', 'a2' ) );
		$round = $this->planned_round();
		RankingTournament::rounds()->propose( $this->tk(), $round );
		$this->mails = array();

		RankingTournament::rounds()->publish( $this->tk(), $round );

		self::assertSame( array(), $this->mails, 'Die Freigabe versendet nichts.' );
		self::assertSame( array( array( $round, 0 ) ), $this->waiting_callup_events() );
		self::assertTrue( RankingTournament::callups_waiting( $round ) );
		self::assertFalse( RankingTournament::callups_waiting( $round + 1 ) );

		self::assertSame( array( array( $round, 0 ) ), $this->run_callup_events() );
		self::assertCount( 4, $this->mails );
		self::assertSame( 'notification_rl_callup', $this->mails[0]->type );
		self::assertSame( array(), $this->waiting_callup_events(), 'Ohne Rest kein weiterer Schritt.' );

		// Nach vollständigem Versand: nichts mehr, auch nicht über den stündlichen Lauf.
		RankingTournament::send_callups( $round, 0 );
		RankingTournament::catch_up_callups();
		self::assertCount( 4, $this->mails );
		self::assertSame( array(), $this->waiting_callup_events() );
	}

	public function test_step_sends_one_batch_and_schedules_the_rest(): void {
		[ $round, $persons ] = $this->big_group( Notices::CALLUP_BATCH + 2 );

		// Erster Schritt: genau ein Stapel, der Rest ist für die Personen danach eingeplant.
		$this->run_callup_events();
		self::assertCount( Notices::CALLUP_BATCH, $this->mails );
		self::assertSame( array( array( $round, $persons[ Notices::CALLUP_BATCH - 1 ] ) ), $this->waiting_callup_events() );

		// Zweiter Schritt: die übrigen zwei, danach wartet nichts mehr.
		$this->run_callup_events();
		self::assertCount( Notices::CALLUP_BATCH + 2, $this->mails );
		self::assertSame( array(), $this->waiting_callup_events() );
		$to = array_map( static fn( $mail ): string => $mail->to, $this->mails );
		self::assertCount( Notices::CALLUP_BATCH + 2, array_unique( $to ), 'Jede Person genau einmal.' );
		self::assertSame( array(), $this->run_callup_events() );
		self::assertSame( array(), RankingTournament::notices()->rounds_with_pending_callups() );
	}

	public function test_exactly_one_batch_leaves_no_rest(): void {
		$this->big_group( Notices::CALLUP_BATCH );

		$this->run_callup_events();

		self::assertCount( Notices::CALLUP_BATCH, $this->mails );
		self::assertSame( array(), $this->waiting_callup_events() );
	}

	public function test_changing_a_group_schedules_a_callup_for_its_members_only(): void {
		$this->register( array( 'h1', 'h2', 'a1', 'a2', 'h3', 'a3' ) );
		$round = $this->planned_round();
		RankingTournament::rounds()->propose( $this->tk(), $round );
		RankingTournament::rounds()->publish( $this->tk(), $round );
		$this->run_callup_events();
		self::assertCount( 6, $this->mails );
		$this->mails   = array();
		[ $one, $two ] = RankingTournament::repository()->groups( $round );

		// Speichern ohne Änderung merkt nichts vor.
		RankingTournament::rounds()->save_group( $this->tk(), $round, $one['id'], array( 'start_at' => $one['start_at'] ) );
		self::assertSame( array(), $this->waiting_callup_events() );

		RankingTournament::rounds()->save_group( $this->tk(), $round, $one['id'], array( 'note' => 'Eingang hinten' ) );
		self::assertSame( array(), $this->mails );
		self::assertSame( array( array( $round, 0 ) ), $this->waiting_callup_events() );
		$this->run_callup_events();

		self::assertCount( count( $one['members'] ), $this->mails );
		self::assertStringStartsWith( 'Geändertes Aufgebot Ranglistenturnier: 1. Runde, Kategorie 1', $this->mails[0]->subject );
		$other = array();
		foreach ( $two['members'] as $member ) {
			$other[] = (string) $this->db->get_var( "SELECT email FROM {$this->db->table( 'person' )} WHERE id = %d", array( $member['person_id'] ) );
		}
		self::assertSame( array(), array_intersect( $other, array_map( static fn( $mail ): string => $mail->to, $this->mails ) ) );
	}

	public function test_hourly_run_reschedules_a_lost_event_without_sending(): void {
		[ $round ] = $this->published_group();
		self::assertSame( array( array( $round, 0 ) ), $this->waiting_callup_events() );

		// Wartet schon ein Schritt, plant der stündliche Lauf keinen zweiten.
		RankingTournament::catch_up_callups();
		self::assertCount( 1, $this->waiting_callup_events() );

		// Das einmalige Ereignis geht verloren (z. B. Cron-Eintrag überschrieben).
		wp_unschedule_hook( RankingTournament::CALLUP_HOOK );
		self::assertSame( array(), $this->waiting_callup_events() );
		$this->mails = array();

		self::assertSame( 'ttleague_notifications_hourly', Notifications::CRON_HOOK );
		do_action( 'ttleague_notifications_hourly' );

		self::assertSame( array(), array_filter( $this->mails, static fn( $mail ): bool => 'notification_rl_callup' === $mail->type ), 'Der stündliche Lauf versendet selbst nichts.' );
		self::assertSame( array( array( $round, 0 ) ), $this->waiting_callup_events() );
		$this->run_callup_events();
		self::assertCount( 4, array_filter( $this->mails, static fn( $mail ): bool => 'notification_rl_callup' === $mail->type ) );

		// Wartet eine Fortsetzung für die Runde, gilt das ebenfalls als «ein Schritt wartet».
		RankingTournament::schedule_callups( $round, 17 );
		self::assertTrue( RankingTournament::callups_waiting( $round ) );
	}

	public function test_failure_to_schedule_is_reported(): void {
		$this->register( array( 'h1', 'h2', 'a1', 'a2' ) );
		$round = $this->planned_round();
		RankingTournament::rounds()->propose( $this->tk(), $round );
		add_filter( 'pre_schedule_event', static fn() => new \WP_Error( 'kaputt', 'Cron-Tabelle gesperrt' ) );
		$log      = (string) tempnam( get_temp_dir(), 'ttl-log' );
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Fehlerprotokoll im Test umleiten.
		try {
			RankingTournament::rounds()->publish( $this->tk(), $round );
		} finally {
			ini_set( 'error_log', false === $previous ? '' : $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- siehe oben.
			wp_delete_file( $log );
		}

		// Die Freigabe ist gespeichert; das Scheitern steht im Fehlerprotokoll der Benachrichtigungen.
		self::assertSame( 'published', RankingTournament::repository()->round( $round )['status'] );
		self::assertCount( 1, $this->errors );
		self::assertStringContainsString( 'Der Versand der Aufgebote liess sich nicht einplanen: Cron-Tabelle gesperrt', $this->errors[0] );
		self::assertStringContainsString( 'Runde ' . $round, $this->errors[0] );
	}

	public function test_a_waiting_duplicate_is_not_an_error(): void {
		[ $round ] = $this->published_group();
		// Die TK ändert gleich nach der Freigabe eine Gruppe: Derselbe Schritt wartet schon.
		RankingTournament::schedule_callups( $round );

		self::assertSame( array(), $this->errors );
		self::assertSame( array( array( $round, 0 ) ), $this->waiting_callup_events() );
	}

	/**
	 * Freigegebene Runde mit einer einzigen Gruppe aus $size Personen der Kategorie 1, alle mit
	 * E-Mail-Adresse. Die Freigabe hat den Versand vorgemerkt.
	 *
	 * @return array{0:int,1:list<int>} Runde und Personen nach ID.
	 */
	private function big_group( int $size ): array {
		$seeder  = new Seeder( $this->db );
		$players = array();
		$persons = array();
		for ( $i = 1; $i <= $size; $i++ ) {
			// Eigene Lizenznummern ab 95001: Die Liga hat ihre Spieler schon ab 90001 angelegt.
			$person = $seeder->person( 'Seefelder', 'Zusatz' . $i );
			$player = $this->db->insert(
				'player',
				array(
					'person_id'  => $person,
					'license_no' => 95000 + $i,
				)
			);
			$seeder->register( $player, $this->league->id['season'], $this->league->id['club_home'], 12 );
			$this->db->update( 'person', array( 'email' => 'zusatz' . $i . '@example.invalid' ), array( 'id' => $person ) );
			RankingTournament::registrations()->register( $this->tk(), $player );
			$players[] = $player;
			$persons[] = $person;
		}
		$round = $this->planned_round();
		RankingTournament::rounds()->save_group(
			$this->tk(),
			$round,
			null,
			array(
				'category' => 1,
				'venue_id' => $this->venue['home'],
				'start_at' => $this->monday() . ' 19:30',
				'members'  => $players,
			)
		);
		RankingTournament::rounds()->publish( $this->tk(), $round );
		$this->mails = array();
		sort( $persons );
		return array( $round, $persons );
	}
}
