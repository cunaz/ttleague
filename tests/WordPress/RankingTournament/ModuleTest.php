<?php
/**
 * Das Modul meldet Backend-Seite, Benachrichtigungstypen, Exporte und Routen an.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\RankingTournament;

use TTLeague\Export\Exports;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Notifications;
use TTLeague\RankingTournament\AdminPage;
use TTLeague\RankingTournament\RankingTournament;
use TTLeague\Tk\Tk;

final class ModuleTest extends RlTestCase {

	public function test_module_is_registered_at_the_extension_points(): void {
		$pages = array_column( Tk::pages(), null, 'slug' );
		self::assertArrayHasKey( RankingTournament::PAGE, $pages );
		self::assertSame( 'Ranglistenturnier', $pages[ RankingTournament::PAGE ]['title'] );
		self::assertSame( array( AdminPage::class, 'render' ), $pages[ RankingTournament::PAGE ]['render'] );
		self::assertLessThan( Tk::ACCOUNTS_POSITION, $pages[ RankingTournament::PAGE ]['position'] );

		$labels = EventTypes::labels();
		self::assertArrayHasKey( EventTypes::RL_CALLUP, $labels );
		self::assertArrayHasKey( EventTypes::RL_REMINDER, $labels );

		$exports = Exports::all();
		self::assertArrayHasKey( 'rl-anmeldungen', $exports );
		self::assertArrayHasKey( 'rl-rangliste', $exports );

		// Die Erinnerungen hängen am stündlichen Fristenlauf, ohne eigenen Cron.
		self::assertNotFalse( has_action( Notifications::CRON_HOOK, array( RankingTournament::class, 'run_reminders' ) ) );
		self::assertNotFalse( has_action( 'ttleague_my_area_sections', array( \TTLeague\RankingTournament\MyArea::class, 'section' ) ) );
		self::assertSame( 'http://example.org/erfassen/?rl-gruppe=7', RankingTournament::entry_url( 7 ) );
	}

	public function test_hourly_run_sends_the_reminders(): void {
		$this->register( array( 'h1', 'a1' ) );
		$start = new \DateTimeImmutable( wp_date( 'Y-m-d' ) . ' +5 days' );
		$round = RankingTournament::rounds()->create( $this->tk(), $this->competition, $start->format( 'Y-m-d' ), $start->modify( '+3 days' )->format( 'Y-m-d' ) );
		RankingTournament::registrations()->set_blocked_dates( $this->tk(), $this->league->id['a1'], $round, array() );
		// Der Test läuft zu jeder Tageszeit: Versandfenster rund um die Uhr.
		add_filter(
			'ttleague_notification_hours',
			static fn( array $hours ): array => array(
				'from_hour'  => 0,
				'until_hour' => 24,
			) + $hours
		);
		$this->mails = array();

		RankingTournament::run_reminders();

		self::assertCount( 1, $this->mails, 'Nur wer keine Sperrdaten gemeldet hat.' );
		self::assertSame( 'h1@example.invalid', $this->mails[0]->to );
		self::assertSame( 'Ranglistenturnier: Sperrdaten für die 1. Runde fehlen', $this->mails[0]->subject );
		self::assertSame( 'notification_rl_reminder', $this->mails[0]->type );
		RankingTournament::run_reminders();
		self::assertCount( 1, $this->mails, 'Kein zweiter Versand.' );
	}

	public function test_exports_list_registrations_and_standings(): void {
		$this->register( array( 'h1', 'h3' ) );
		RankingTournament::registrations()->withdraw( $this->tk(), $this->league->id['h3'] );

		$csv = (string) Exports::csv( 'rl-anmeldungen' );
		self::assertStringStartsWith( "\xEF\xBB\xBFKategorie;Name;Vorname;Lizenz;Club;Klassierung;Status;Angemeldet am\r\n", $csv );
		self::assertMatchesRegularExpression( '/\r\n1;Seefelder;Spieler1;\d+;Seefeld;10;angemeldet;\d\d\.\d\d\.\d{4} \d\d:\d\d\r\n/', $csv );
		self::assertMatchesRegularExpression( '/\r\n2;Seefelder;Spieler3;\d+;Seefeld;8;abgemeldet;/', $csv );

		// Rangliste erst nach dem Abschluss einer Runde.
		self::assertSame( 1, substr_count( (string) Exports::csv( 'rl-rangliste' ), "\r\n" ), 'Nur die Kopfzeile.' );
		[ $round, $group ] = $this->published_group();
		RankingTournament::entry()->save( $group, $this->sheet( $group ), $this->tk() );
		RankingTournament::rounds()->close( $this->tk(), $round );
		$lines = explode( "\r\n", trim( (string) Exports::csv( 'rl-rangliste' ) ) );
		self::assertCount( 5, $lines );
		self::assertSame( '1;1;Spieler1 Seefelder;Seefeld;10;R1 6;6;9;0;ja', $lines[1] );
		self::assertSame( '1;4;Spieler2 Wollishofer;Wollishofen;9;R1 3;3;0;9;ja', $lines[4] );
	}
}
