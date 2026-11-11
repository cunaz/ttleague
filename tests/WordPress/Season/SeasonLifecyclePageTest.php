<?php
/**
 * Spielplan bereinigen, Freigabe mit Mitteilungen über WP-Cron, Rückzug und Abschluss über die
 * Seite «Saison», mit echtem WordPress.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Season;

use TTLeague\Access\Capabilities;
use TTLeague\Infrastructure\Cache\DataVersion;
use TTLeague\Infrastructure\Flash;
use TTLeague\Notifications\Notifications;
use TTLeague\ReadModel\Cache\WpCache;
use TTLeague\ReadModel\ReadModel;
use TTLeague\Results\Results;
use TTLeague\Season\SeasonActions;
use TTLeague\Season\SeasonAdmin;
use TTLeague\Season\SeasonNotifications;
use TTLeague\Season\SeasonPage;
use TTLeague\Season\SeasonRepository;
use TTLeague\Tk\Tk;

/**
 * Daten siehe SeasonWpTestCase, hier mit zwei Tischen pro Lokal: Der Entwurf hat vier harte
 * Kollisionen (zwei Begegnungen am selben Abend im selben Lokal).
 */
final class SeasonLifecyclePageTest extends SeasonWpTestCase {

	/** @var list<string> */
	private array $errors = array();

	public function set_up(): void {
		parent::set_up();
		$this->errors = array();
		add_action(
			'ttleague_notification_error',
			function ( \Throwable $error, string $context ): void {
				$this->errors[] = $context . ': ' . $error->getMessage();
			},
			10,
			2
		);
	}

	protected function tables(): ?int {
		return 2;
	}

	private function page( int $step = 0, int $fixture = 0 ): string {
		return SeasonPage::html( $step, $fixture, Flash::take( Tk::FLASH, get_current_user_id() ) );
	}

	/**
	 * Alle harten Kollisionen über die Vorschläge der Prüfung beheben.
	 */
	private function resolve( int $season_id ): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$hard = array_values( array_filter( SeasonAdmin::scheduling()->overview( $season_id )['conflicts'], static fn( array $conflict ): bool => $conflict['hard'] ) );
			if ( array() === $hard ) {
				return;
			}
			$suggestion = $hard[0]['suggestions'][0];
			$this->post(
				SeasonActions::SUGGESTION,
				array(
					'fixture'   => (string) $suggestion['fixture_id'],
					'starts_at' => $suggestion['starts_at'],
				),
				$suggestion['fixture_id']
			);
		}
		self::fail( 'Die Kollisionen liessen sich nicht beheben.' );
	}

	/**
	 * Captain der geplanten Saison mit Konto.
	 */
	private function new_captain( string $team ): int {
		$person = $this->seed->person( 'Neucaptain', ucfirst( $team ) );
		$this->db->update( 'person', array( 'email' => 'captain.' . $team . '@example.invalid' ), array( 'id' => $person ) );
		$this->seed->role( $this->id['next'], (int) $this->db->get_var( "SELECT club_id FROM {$this->db->table( 'team' )} WHERE id = %d", array( $this->id[ 'next_' . $team ] ) ), $person, 'captain', $this->id[ 'next_' . $team ] );
		return $person;
	}

	public function test_the_report_offers_suggestions_and_accepting_one_checks_again(): void {
		$next    = $this->ready();
		$fixture = $this->fixture( 'wollishofen_1', 'seefeld_1' );
		$html    = $this->page( SeasonPage::SCHEDULE );
		self::assertStringContainsString( 'Harte Kollisionen: 4.', $html );
		self::assertStringContainsString( 'Halle Wollishofen: Kapazität des Lokals am 08.09.2027 überschritten', $html );
		self::assertStringContainsString( 'Wollishofen 1 – Seefeld 1 auf Mi, 29.9.2027, 19:30 verlegen', $html );
		self::assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( SeasonActions::SUGGESTION . '_' . $fixture ) . '"', $html );
		self::assertStringContainsString( '4 harte Kollisionen.', $html, 'Der Reiter zeigt den Stand.' );

		$target = $this->post(
			SeasonActions::SUGGESTION,
			array(
				'fixture'   => (string) $fixture,
				'starts_at' => '2027-09-29 19:30:00',
			),
			$fixture
		);

		self::assertSame( SeasonPage::url( SeasonPage::SCHEDULE ), $target );
		self::assertSame( '2027-09-29 19:30:00', $this->db->get_var( "SELECT scheduled_at FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $fixture ) ) );
		$html = $this->page( SeasonPage::SCHEDULE );
		self::assertStringContainsString( 'Der Vorschlag ist übernommen. Der Plan ist neu geprüft.', $html );
		self::assertStringContainsString( 'Harte Kollisionen: 3.', $html );

		$this->post(
			SeasonActions::SUGGESTION,
			array(
				'fixture'   => (string) $fixture,
				'starts_at' => '2027-09-29 19:30:00',
			),
			$fixture
		);
		self::assertStringContainsString( 'Dieser Vorschlag gilt nicht mehr', $this->page( SeasonPage::SCHEDULE ) );
		unset( $next );
	}

	public function test_a_fixture_is_moved_by_hand_and_its_home_right_swapped(): void {
		$this->ready();
		$fixture = $this->fixture( 'oerlikon_1', 'wollishofen_1' );
		$html    = $this->page( SeasonPage::SCHEDULE, $fixture );
		self::assertStringContainsString( '<h3>Oerlikon 1 – Wollishofen 1</h3>', $html );
		self::assertStringContainsString( 'name="date" required value="2027-09-16"', $html );
		self::assertStringContainsString( 'name="time" required value="20:00"', $html );
		self::assertMatchesRegularExpression( '#<option value="' . $this->id['venue_oerlikon'] . '" selected>Halle Oerlikon</option>#', $html );
		self::assertStringContainsString( 'name="action" value="' . SeasonActions::SWAP . '"', $html );

		$this->post(
			SeasonActions::MOVE,
			array(
				'fixture' => (string) $fixture,
				'date'    => '2027-09-30',
				'time'    => '19:15',
				'venue'   => (string) $this->id['venue_altstetten'],
			),
			$fixture
		);
		$row = $this->db->get_row( "SELECT scheduled_at, venue_id FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $fixture ) );
		self::assertSame( '2027-09-30 19:15:00', $row['scheduled_at'] );
		self::assertSame( (string) $this->id['venue_altstetten'], $row['venue_id'] );

		$target = $this->post(
			SeasonActions::MOVE,
			array(
				'fixture' => (string) $fixture,
				'date'    => '2029-01-01',
				'time'    => '19:15',
			),
			$fixture
		);
		self::assertSame( SeasonPage::url( SeasonPage::SCHEDULE, array( 'fixture' => (string) $fixture ) ), $target, 'Nach einem Fehler bleibt die Begegnung offen.' );
		self::assertStringContainsString( 'Das Datum liegt nicht in der Saison.', $this->page( SeasonPage::SCHEDULE, $fixture ) );

		$this->post(
			SeasonActions::SWAP,
			array(
				'fixture'     => (string) $fixture,
				'with_return' => '1',
			),
			$fixture
		);
		$row = $this->db->get_row( "SELECT home_team_id, venue_id FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $fixture ) );
		self::assertSame( (string) $this->id['next_wollishofen_1'], $row['home_team_id'] );
		self::assertSame( (string) $this->id['venue_wollishofen'], $row['venue_id'] );
		self::assertSame( 1, $this->rows( 'fixture', 'home_team_id = ' . $this->id['next_oerlikon_1'] . ' AND away_team_id = ' . $this->id['next_wollishofen_1'] ), 'Auch das Rückspiel ist getauscht.' );
	}

	public function test_the_publication_lists_what_is_missing(): void {
		$next = $this->with_weeks();
		$this->db->update(
			'club_season',
			array( 'status' => 'submitted' ),
			array(
				'season_id' => $next,
				'club_id'   => $this->id['club_oerlikon'],
			)
		);

		$html = $this->page( SeasonPage::PUBLISH );

		self::assertStringContainsString( 'Was noch fehlt', $html );
		self::assertStringContainsString( 'Noch nicht geprüfte Meldungen: TTC Oerlikon.', $html );
		self::assertStringContainsString( 'Der Spielplan fehlt oder passt nicht mehr zu den Mannschaften: Gruppe A, Gruppe B.', $html );
		self::assertStringContainsString( 'Die Saison 2026/27 wird mit der Freigabe archiviert, aber die Klassierung ist nicht abgeschlossen.', $html );
		self::assertStringNotContainsString( 'name="action" value="' . SeasonActions::PUBLISH . '"', $html, 'Solange etwas fehlt, gibt es kein Formular.' );

		$this->post(
			SeasonActions::PUBLISH,
			array(
				'season'  => (string) $next,
				'confirm' => '2027/28',
				'accept'  => array(
					'conflicts'   => '1',
					'predecessor' => '1',
				),
			),
			$next
		);
		self::assertSame( 'planned', $this->season_status( $next ), 'Auch ein gebautes Formular gibt nicht frei.' );
		self::assertStringContainsString( 'Die Saison lässt sich noch nicht freigeben.', $this->page( SeasonPage::PUBLISH ) );
	}

	public function test_hard_collisions_are_confirmed_explicitly_or_resolved(): void {
		$next = $this->ready();
		$html = $this->page( SeasonPage::PUBLISH );
		self::assertStringContainsString( 'Bereit zur Freigabe, mit ausdrücklicher Bestätigung.', $html );
		self::assertStringContainsString( 'name="accept[conflicts]" value="1" required', $html );
		self::assertStringContainsString( 'Der Spielplan hat 4 harte Kollisionen.', $html );
		self::assertStringNotContainsString( 'accept[predecessor]', $html );

		$fields = array(
			'season'  => (string) $next,
			'confirm' => '2027/28',
		);
		self::assertSame( SeasonPage::url( SeasonPage::PUBLISH ), $this->post( SeasonActions::PUBLISH, $fields, $next ) );
		self::assertSame( 'planned', $this->season_status( $next ), 'Ohne Bestätigung keine Freigabe.' );

		$this->resolve( $next );
		$html = $this->page( SeasonPage::PUBLISH );
		self::assertStringContainsString( 'Bereit zur Freigabe.', $html );
		self::assertStringNotContainsString( 'accept[conflicts]', $html );

		$this->post( SeasonActions::PUBLISH, array( 'confirm' => '2026/27' ) + $fields, $next );
		self::assertSame( 'planned', $this->season_status( $next ) );
		self::assertStringContainsString( 'Bitte bestätige mit dem Namen der Saison: 2027/28', $this->page( SeasonPage::PUBLISH ) );

		$this->post( SeasonActions::PUBLISH, $fields, $next );
		self::assertSame( 'active', $this->season_status( $next ) );
	}

	public function test_publishing_switches_both_seasons_and_only_schedules_the_messages(): void {
		$next = $this->ready();
		$this->new_captain( 'seefeld_1' );
		$version = DataVersion::get();
		$model   = new ReadModel( $this->db, new WpCache() );
		self::assertSame( array( '2026-27' ), array_column( $model->seasons->all(), 'slug' ) );

		$target = $this->post(
			SeasonActions::PUBLISH,
			array(
				'season'  => (string) $next,
				'confirm' => '2027/28',
				'accept'  => array( 'conflicts' => '1' ),
			),
			$next
		);

		self::assertSame( SeasonPage::url( SeasonPage::CLOSE ), $target );
		self::assertSame( 'active', $this->season_status( $next ) );
		self::assertSame( 'archived', $this->season_status( $this->id['active'] ) );
		self::assertGreaterThan( $version, DataVersion::get() );
		self::assertSame( array( '2027-28', '2026-27' ), array_column( ( new ReadModel( $this->db, new WpCache() ) )->seasons->all(), 'slug' ), 'Die Lesemodelle sehen die neue Saison trotz Cache.' );
		self::assertSame( array(), $this->mails, 'In der Anfrage der Freigabe geht keine Mitteilung hinaus.' );
		self::assertNotFalse( wp_next_scheduled( SeasonAdmin::PUBLISHED_HOOK, array( $next, 0 ) ) );
		self::assertTrue( SeasonAdmin::published_scheduled( $next ) );
		$html = $this->page( SeasonPage::CLOSE );
		self::assertStringContainsString( 'Die Saison ist freigegeben und jetzt aktiv.', $html );
		self::assertStringContainsString( 'Aktive Saison: 2027/28', $html );
		self::assertStringContainsString( 'Keine geplante Saison.', $html );
		self::assertSame( array(), $this->errors );
	}

	public function test_the_cron_step_sends_the_schedule_to_captains_and_club_officers(): void {
		$next    = $this->ready();
		$captain = $this->new_captain( 'seefeld_1' );
		SeasonAdmin::lifecycle()->publish( $this->tk(), $next, '2027/28', array( 'conflicts' ) );
		wp_unschedule_hook( SeasonAdmin::PUBLISHED_HOOK );

		do_action( SeasonAdmin::PUBLISHED_HOOK, $next, 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook des Plugins.

		self::assertCount( 5, $this->mails, 'Vier TK-Chefs und ein Captain.' );
		$to = array_column( $this->mails, null, 'to' );
		self::assertArrayHasKey( 'captain.seefeld_1@example.invalid', $to );
		$mail = $to['captain.seefeld_1@example.invalid'];
		self::assertSame( 'Saison 2027/28: Der Spielplan ist freigegeben', $mail->subject );
		self::assertSame( 'notification_season_published', $mail->type );
		self::assertStringContainsString( 'Mi, 8.9.2027, 19:30: Wollishofen 1 – Seefeld 1 (Halle Wollishofen)', $mail->text );
		self::assertStringContainsString( '/kalender/team/2027-28/seefeld-1.ics', $mail->text );
		self::assertStringNotContainsString( 'Seefeld 2 –', $mail->text, 'Der Captain erhält nur seine Mannschaft.' );
		self::assertStringContainsString( '/kalender/club/seefeld.ics', $to[ 'person' . $this->id['chef_seefeld'] . '@example.invalid' ]->text );
		self::assertFalse( SeasonAdmin::published_scheduled( $next ), 'Kein Rest, kein weiterer Schritt.' );

		do_action( SeasonAdmin::PUBLISHED_HOOK, $next, 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook des Plugins.
		self::assertCount( 5, $this->mails, 'Ein zweiter Lauf versendet nichts doppelt.' );
		self::assertSame( array(), $this->errors );
		unset( $captain );
	}

	public function test_a_step_sends_one_batch_and_schedules_the_rest(): void {
		$next = $this->ready();
		for ( $i = 1; $i <= SeasonNotifications::BATCH + 3; $i++ ) {
			$person = $this->seed->person( 'Vielcaptain', 'Nummer' . $i );
			$this->db->update( 'person', array( 'email' => 'viele' . $i . '@example.invalid' ), array( 'id' => $person ) );
			$this->seed->role( $next, $this->id['club_seefeld'], $person, 'captain', $this->id['next_seefeld_1'] );
		}
		SeasonAdmin::lifecycle()->publish( $this->tk(), $next, '2027/28', array( 'conflicts' ) );
		wp_unschedule_hook( SeasonAdmin::PUBLISHED_HOOK );

		do_action( SeasonAdmin::PUBLISHED_HOOK, $next, 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook des Plugins.

		self::assertCount( SeasonNotifications::BATCH, $this->mails, 'Ein Schritt versendet höchstens einen Stapel.' );
		self::assertTrue( SeasonAdmin::published_scheduled( $next ) );
		$steps = array();
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( $hooks[ SeasonAdmin::PUBLISHED_HOOK ] ?? array() as $event ) {
				$steps[] = $event['args'];
			}
		}
		self::assertCount( 1, $steps );
		self::assertSame( $next, $steps[0][0] );
		self::assertGreaterThan( 0, $steps[0][1], 'Der nächste Schritt macht nach der letzten Person weiter.' );

		wp_unschedule_hook( SeasonAdmin::PUBLISHED_HOOK );
		do_action( SeasonAdmin::PUBLISHED_HOOK, $steps[0][0], $steps[0][1] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook des Plugins.
		self::assertCount( SeasonNotifications::BATCH + 3 + 4, $this->mails );
		self::assertFalse( SeasonAdmin::published_scheduled( $next ) );
	}

	public function test_the_hourly_run_only_schedules_what_is_pending(): void {
		$next = $this->ready();
		do_action( Notifications::CRON_HOOK ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook des Plugins.
		self::assertFalse( SeasonAdmin::published_scheduled( $next ), 'Vor der Freigabe steht nichts aus.' );

		SeasonAdmin::lifecycle()->publish( $this->tk(), $next, '2027/28', array( 'conflicts' ) );
		// Das Ereignis der Freigabe ging verloren.
		wp_unschedule_hook( SeasonAdmin::PUBLISHED_HOOK );
		$this->mails = array();

		SeasonAdmin::catch_up_published();

		self::assertSame( array(), $this->mails, 'Der stündliche Lauf versendet selbst nichts.' );
		self::assertTrue( SeasonAdmin::published_scheduled( $next ) );
		$count = static function (): int {
			$events = 0;
			foreach ( (array) _get_cron_array() as $hooks ) {
				$events += count( $hooks[ SeasonAdmin::PUBLISHED_HOOK ] ?? array() );
			}
			return $events;
		};
		self::assertSame( 1, $count() );
		SeasonAdmin::catch_up_published();
		self::assertSame( 1, $count(), 'Wartet schon ein Schritt, kommt kein zweiter dazu.' );

		wp_unschedule_hook( SeasonAdmin::PUBLISHED_HOOK );
		do_action( SeasonAdmin::PUBLISHED_HOOK, $next, 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook des Plugins.
		self::assertCount( 4, $this->mails );
		SeasonAdmin::catch_up_published();
		self::assertFalse( SeasonAdmin::published_scheduled( $next ), 'Hat jede Person ihre Mitteilung, plant der Lauf nichts mehr ein.' );
		self::assertSame( array(), $this->errors );
	}

	public function test_a_failure_to_schedule_is_reported_and_caught_up_later(): void {
		$next     = $this->ready();
		$fail     = static fn() => new \WP_Error( 'could_not_set', 'Cron nicht speicherbar' );
		add_filter( 'pre_schedule_event', $fail );
		$log      = (string) tempnam( get_temp_dir(), 'ttl-log' );
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Fehlerprotokoll im Test umleiten.

		try {
			SeasonAdmin::lifecycle()->publish( $this->tk(), $next, '2027/28', array( 'conflicts' ) );
		} finally {
			ini_set( 'error_log', false === $previous ? '' : $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- siehe oben.
		}
		$logged = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokale Testdatei.
		wp_delete_file( $log );
		self::assertStringContainsString( 'liess sich nicht einplanen', $logged, 'Der Fehler steht im Fehlerprotokoll.' );

		self::assertSame( 'active', $this->season_status( $next ), 'Die Freigabe selbst ist gespeichert.' );
		self::assertCount( 1, $this->errors );
		self::assertStringContainsString( 'liess sich nicht einplanen (could_not_set: Cron nicht speicherbar)', $this->errors[0] );
		self::assertFalse( SeasonAdmin::published_scheduled( $next ) );

		remove_filter( 'pre_schedule_event', $fail );
		SeasonAdmin::catch_up_published();
		self::assertTrue( SeasonAdmin::published_scheduled( $next ), 'Der stündliche Lauf plant nach.' );
	}

	public function test_captains_of_the_new_season_get_their_rights_with_the_publication(): void {
		$next    = $this->ready();
		$person  = $this->new_captain( 'wollishofen_1' );
		$user    = $this->account( 'subscriber', 'captain.wollishofen_1@example.invalid', $person );
		$fixture = $this->fixture( 'wollishofen_1', 'seefeld_1' );
		self::assertFalse( user_can( $user, Capabilities::ENTER_RESULT, $fixture ) );
		self::assertFalse( user_can( $user, Capabilities::VIEW_CONTACTS ) );

		$this->post(
			SeasonActions::PUBLISH,
			array(
				'season'  => (string) $next,
				'confirm' => '2027/28',
				'accept'  => array( 'conflicts' => '1' ),
			),
			$next
		);

		self::assertTrue( user_can( $user, Capabilities::ENTER_RESULT, $fixture ), 'Auch in derselben Anfrage gelten die neuen Rechte.' );
		self::assertTrue( user_can( $user, Capabilities::VIEW_CONTACTS ) );
		self::assertTrue( user_can( $this->user['chef'], Capabilities::MANAGE_CLUB, $this->id['club_seefeld'] ), 'Die vorgetragene TK-Chefin verwaltet ihren Club weiter.' );
	}

	public function test_a_team_is_withdrawn_from_the_page(): void {
		$fixture = $this->seed->fixture(
			$this->id['group_a'],
			array(
				'home_team_id' => $this->id['seefeld_1'],
				'away_team_id' => $this->id['oerlikon_1'],
				'scheduled_at' => '2026-10-06 20:00:00',
				'status'       => 'final',
				'home_score'   => 9,
				'away_score'   => 1,
			)
		);
		$html    = $this->page( SeasonPage::CLOSE );
		self::assertStringContainsString( 'Rückzug einer Mannschaft (Art. 13.3)', $html );
		self::assertStringContainsString( 'name="action" value="' . SeasonActions::WITHDRAW . '"', $html );
		wp_unschedule_hook( Results::RECALC_HOOK );
		$fields = array(
			'season' => (string) $this->id['active'],
			'team'   => (string) $this->id['seefeld_1'],
		);

		$this->post( SeasonActions::WITHDRAW, $fields + array( 'reason' => '' ), $this->id['active'] );
		self::assertSame( 'active', $this->db->get_var( "SELECT status FROM {$this->db->table( 'team' )} WHERE id = %d", array( $this->id['seefeld_1'] ) ) );
		self::assertStringContainsString( 'Bitte begründe den Rückzug.', $this->page( SeasonPage::CLOSE ) );

		$version = DataVersion::get();
		$target  = $this->post( SeasonActions::WITHDRAW, $fields + array( 'reason' => 'Zu wenige Spieler' ), $this->id['active'] );

		self::assertSame( SeasonPage::url( SeasonPage::CLOSE ), $target );
		self::assertSame( 'withdrawn', $this->db->get_var( "SELECT status FROM {$this->db->table( 'team' )} WHERE id = %d", array( $this->id['seefeld_1'] ) ) );
		$row = $this->db->get_row( "SELECT status, home_score, away_score FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $fixture ) );
		self::assertSame( array( 'forfeit', '0', '10' ), array_values( (array) $row ) );
		self::assertGreaterThan( $version, DataVersion::get() );
		self::assertNotFalse( wp_next_scheduled( Results::RECALC_HOOK ), 'Die Wertungszahl wird neu gerechnet.' );
		self::assertSame( array(), $this->mails, 'Der Rückzug löst keine Mitteilung je Begegnung aus.' );
		$html = $this->page( SeasonPage::CLOSE );
		self::assertStringContainsString( 'Die Mannschaft ist zurückgezogen. Forfait gewertete Begegnungen: 1.', $html );
		self::assertStringContainsString( 'Zurückgezogen: Seefeld 1.', $html );
		self::assertStringNotContainsString( '<option value="' . $this->id['seefeld_1'] . '">', $html );

		$group = ( new ReadModel( $this->db, new WpCache() ) )->championship->group_by_id( $this->id['active'], $this->id['group_a'] );
		self::assertSame( $this->id['seefeld_1'], $group['standings'][2]['team_id'] );
		self::assertTrue( $group['standings'][2]['withdrawn'] );
	}

	public function test_single_fixtures_of_the_active_season_are_scheduled_and_cancelled(): void {
		$html = $this->page( SeasonPage::SCHEDULE );
		self::assertStringContainsString( 'Aktive Saison 2026/27: einzelne Begegnung', $html );
		self::assertStringContainsString( 'Seefeld 1 (Gruppe A)', $html );
		$fields = array( 'season' => (string) $this->id['active'] );

		$this->post(
			SeasonActions::FIXTURE_ADD,
			$fields + array(
				'home' => (string) $this->id['seefeld_1'],
				'away' => (string) $this->id['oerlikon_1'],
				'date' => '2027-05-11',
				'time' => '20:00',
			),
			$this->id['active']
		);
		$fixture = (int) $this->db->get_var( "SELECT id FROM {$this->db->table( 'fixture' )} WHERE competition_id = %d", array( $this->id['group_a'] ) );
		self::assertGreaterThan( 0, $fixture );
		self::assertStringContainsString( 'Die Begegnung ist angesetzt.', $this->page( SeasonPage::SCHEDULE ) );

		$this->post(
			SeasonActions::FIXTURE_ADD,
			$fields + array(
				'home' => (string) $this->id['seefeld_1'],
				'away' => (string) $this->id['seefeld_2'],
				'date' => '2027-05-11',
				'time' => '20:00',
			),
			$this->id['active']
		);
		self::assertSame( 1, $this->rows( 'fixture' ), 'Mannschaften verschiedener Gruppen spielen nicht gegeneinander.' );
		self::assertStringContainsString( 'Die Mannschaft gehört nicht zu dieser Gruppe.', $this->page( SeasonPage::SCHEDULE ) );

		$this->post(
			SeasonActions::FIXTURE_CANCEL,
			$fields + array(
				'fixture' => (string) $fixture,
				'reason'  => 'Rang steht fest',
			),
			$this->id['active']
		);
		self::assertSame( 'cancelled', $this->db->get_var( "SELECT status FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $fixture ) ) );
	}

	public function test_the_season_is_closed_when_nothing_is_open(): void {
		$open = $this->seed->fixture(
			$this->id['group_a'],
			array(
				'home_team_id' => $this->id['seefeld_1'],
				'away_team_id' => $this->id['oerlikon_1'],
				'status'       => 'provisional',
				'home_score'   => 6,
				'away_score'   => 4,
			)
		);
		$html = $this->page( SeasonPage::CLOSE );
		self::assertStringContainsString( 'Eine Begegnung ist noch angesetzt, verschoben, unbestätigt oder strittig.', $html );
		self::assertStringContainsString( 'Die Klassierung ist noch nicht abgeschlossen.', $html );
		self::assertStringNotContainsString( 'name="action" value="' . SeasonActions::CLOSE . '"', $html );
		$fields = array(
			'season'  => (string) $this->id['active'],
			'confirm' => '2026/27',
		);
		$this->post( SeasonActions::CLOSE, $fields, $this->id['active'] );
		self::assertSame( 'active', $this->season_status( $this->id['active'] ) );

		$this->db->update( 'fixture', array( 'status' => 'final' ), array( 'id' => $open ) );
		$this->db->update( 'season', array( 'classification_closed_at' => '2027-06-20 18:00:00' ), array( 'id' => $this->id['active'] ) );
		$html = $this->page( SeasonPage::CLOSE );
		self::assertStringContainsString( 'Die Saison 2026/27 lässt sich abschliessen.', $html );
		self::assertStringContainsString( 'name="action" value="' . SeasonActions::CLOSE . '"', $html );

		$this->post( SeasonActions::CLOSE, array( 'confirm' => 'ja' ) + $fields, $this->id['active'] );
		self::assertSame( 'active', $this->season_status( $this->id['active'] ) );
		$this->post( SeasonActions::CLOSE, $fields, $this->id['active'] );

		self::assertSame( 'archived', $this->season_status( $this->id['active'] ) );
		self::assertNull( ( new SeasonRepository( $this->db ) )->active() );
		$html = $this->page( SeasonPage::CLOSE );
		self::assertStringContainsString( 'Die Saison ist abgeschlossen und archiviert.', $html );
		self::assertStringContainsString( 'Es gibt keine aktive Saison.', $html );
		self::assertSame( array(), $this->mails );
	}

	public function test_the_late_registration_deadline_of_the_active_season_is_changed(): void {
		$html = $this->page( SeasonPage::CLOSE );
		self::assertStringContainsString( 'name="late_registration_until"', $html );

		$target = $this->post(
			SeasonActions::WINDOW,
			array(
				'season'                  => (string) $this->id['active'],
				'step'                    => (string) SeasonPage::CLOSE,
				'registration_opens'      => '',
				'registration_closes'     => '',
				'late_registration_until' => '2027-02-28',
			),
			$this->id['active']
		);

		self::assertSame( SeasonPage::url( SeasonPage::CLOSE ), $target );
		self::assertSame( '2027-02-28', ( new SeasonRepository( $this->db ) )->active()['late_registration_until'] );
		self::assertStringContainsString( 'name="late_registration_until" value="2027-02-28"', $this->page( SeasonPage::CLOSE ) );
	}
}
