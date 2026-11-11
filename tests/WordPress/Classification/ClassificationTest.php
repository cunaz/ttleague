<?php
/**
 * Klassierung in WordPress: Seite, Formularaktionen und CSV nur mit ttl_tk.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Classification;

use PHPUnit\Framework\Attributes\DataProvider;
use TTLeague\Access\Capabilities;
use TTLeague\Classification\Actions;
use TTLeague\Classification\Classification;
use TTLeague\Classification\Page;
use TTLeague\Classification\ProposalFilter;
use TTLeague\Export\Exports;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Cache\DataVersion;
use TTLeague\Infrastructure\Database\PdoDb;
use TTLeague\Infrastructure\Flash;
use TTLeague\Rating\RecalcLock;
use TTLeague\Results\Actor;
use TTLeague\Results\Results;
use TTLeague\Tests\Integration\Classification\Scenario;
use TTLeague\Tests\WordPress\TestCase;
use TTLeague\Tk\Tk;

/**
 * Daten siehe Scenario: sechs gemeldete Spieler der aktiven Saison, Vorschlag weicht bei Muster
 * (C6 → C8) und Vorlage (C7 → C6) ab.
 */
final class ClassificationTest extends TestCase {

	private Scenario $scenario;

	/** @var array<string,int> */
	private array $id = array();

	private int $tk;

	/** Sperre der Neuberechnung auf einer zweiten Verbindung, siehe other_process(). */
	private ?RecalcLock $other = null;

	public function set_up(): void {
		parent::set_up();
		$this->scenario = new Scenario( $this->db );
		$this->id       = $this->scenario->id;
		$this->tk       = $this->account( 'ttl_tk', 'tk@example.invalid' );
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		if ( null !== $this->other ) {
			$this->other->release();
			$this->other = null;
		}
		parent::tear_down();
	}

	public function test_module_registers_page_export_and_actions(): void {
		$page = array_column( Tk::pages(), null, 'slug' )[ Classification::PAGE ] ?? null;
		self::assertNotNull( $page );
		self::assertSame( 'Klassierung', $page['title'] );
		self::assertSame( array( Page::class, 'render' ), $page['render'] );
		self::assertLessThan( Tk::ACCOUNTS_POSITION, $page['position'] );

		foreach ( array( Classification::COMPUTE, Classification::ACCEPT, Classification::ACCEPT_OPEN, Classification::OVERRIDE, Classification::CLOSE, Classification::REOPEN ) as $action ) {
			self::assertNotFalse( has_action( 'admin_post_' . $action ), $action );
			self::assertFalse( has_action( 'admin_post_nopriv_' . $action ), $action . ' nur angemeldet' );
		}

		$export = Exports::all()[ Classification::EXPORT ] ?? null;
		self::assertNotNull( $export );
		self::assertSame( 'classification', Classification::EXPORT, 'Name für «wp ttleague export classification»' );
		self::assertSame( array( 'Lizenznummer', 'Name', 'Club', 'Bisherige Klassierung', 'Wertungszahl', 'Spiele', 'Vorschlag', 'Entscheid', 'Bemerkung' ), $export['header'] );
	}

	public function test_menu_entry_needs_the_tk_capability(): void {
		global $submenu;
		wp_set_current_user( $this->tk );
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Menü für den Test neu aufbauen.

		Tk::menu();

		$entry = array_column( $submenu[ Tk::OVERVIEW_PAGE ] ?? array(), null, 2 )[ Classification::PAGE ] ?? null;
		self::assertNotNull( $entry );
		self::assertSame( 'ttl_tk', $entry[1] );

		// Die Regeln der Tabelle kommen nur auf dieser Seite dazu.
		Classification::styles( get_plugin_page_hookname( Tk::ACCOUNTS_PAGE, Tk::OVERVIEW_PAGE ) );
		self::assertStringNotContainsString( '.ttl-cl-table', implode( '', (array) wp_styles()->get_data( 'common', 'after' ) ) );
		Classification::styles( get_plugin_page_hookname( Classification::PAGE, Tk::OVERVIEW_PAGE ) );
		self::assertStringContainsString( '.ttl-cl-table', implode( '', (array) wp_styles()->get_data( 'common', 'after' ) ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function accounts_without_tk(): array {
		return array(
			'Abonnent' => array( 'subscriber' ),
			'TK-Chef'  => array( 'chef' ),
			'Gast'     => array( 'guest' ),
		);
	}

	#[DataProvider( 'accounts_without_tk' )]
	public function test_page_is_denied_without_the_tk_capability( string $who ): void {
		$this->compute();
		$this->sign_in( $who );

		ob_start();
		try {
			Page::render();
			self::fail( 'Abbruch erwartet.' );
		} catch ( \WPDieException $e ) {
			self::assertSame( 'Dazu fehlt dir die Berechtigung.', $e->getMessage() );
		} finally {
			$html = (string) ob_get_clean();
		}
		self::assertSame( '', $html, 'keine Zeile ausgegeben' );
	}

	public function test_page_offers_only_the_calculation_before_the_first_proposal(): void {
		wp_set_current_user( $this->tk );

		$html = $this->render();

		self::assertStringContainsString( 'Es gibt noch keinen Vorschlag.', $html );
		self::assertStringContainsString( '<button type="submit" class="button button-primary">Vorschlag berechnen</button>', $html );
		self::assertStringNotContainsString( '<table', $html );
		self::assertStringNotContainsString( Classification::CLOSE, $html );
		self::assertStringNotContainsString( 'list=classification', $html );
	}

	public function test_page_without_an_active_season_has_no_forms(): void {
		wp_set_current_user( $this->tk );
		$this->db->update( 'season', array( 'status' => 'archived' ), array( 'id' => $this->id['season'] ) );

		$html = $this->render();

		self::assertStringContainsString( 'Es gibt keine aktive Saison.', $html );
		self::assertStringNotContainsString( '<form', $html );
	}

	public function test_page_lists_every_player_with_proposal_and_forms(): void {
		$this->compute();
		wp_set_current_user( $this->tk );

		$html = $this->render();

		self::assertSame(
			array( 'Ablage Beat', 'Beispiel Anna', 'Muster Hans', 'Entwurf Petra', 'Probe Lukas', 'Vorlage Sara' ),
			array_map( static fn( array $cells ): string => explode( 'Lizenz', $cells[0] )[0], $this->table( $html ) ),
			'alle Spieler auf einer Seite, nach Club und Name'
		);
		$muster = $this->cells( $html, 'muster' );
		self::assertSame( array( 'Muster HansLizenz 90001', 'TTC Seefeld', 'C6', '813', '3', 'C8', '+2', 'offen', '' ), array_slice( $muster, 0, 9 ) );
		self::assertSame( array( 'C7', '640', '3', 'C6', '−1' ), array_slice( $this->cells( $html, 'vorlage' ), 2, 5 ) );
		self::assertSame( array( 'C9', '–', '0', 'C9', '–' ), array_slice( $this->cells( $html, 'probe' ), 2, 5 ), 'ohne Wertungszahl' );
		self::assertStringContainsString( '6 Spieler, 6 ohne Entscheid, 2 Vorschläge weichen von der bisherigen Klassierung ab.', $html );
		self::assertStringContainsString( 'weniger als 3 gewertete Spiele', $html, 'Mindestzahl aus den Regeln der Saison' );

		foreach ( array( Classification::COMPUTE, Classification::ACCEPT, Classification::ACCEPT_OPEN, Classification::OVERRIDE, Classification::CLOSE ) as $action ) {
			self::assertStringContainsString( '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="_wpnonce" value="' . wp_create_nonce( $action ) . '">', $html, $action );
		}
		self::assertStringNotContainsString( Classification::REOPEN, $html );
		self::assertStringContainsString( 'Alle offenen übernehmen (6)', $html );
		self::assertMatchesRegularExpression( '/<button type="submit" class="button" disabled=\'disabled\'>Klassierung abschliessen<\/button>/', $html, 'abschliessen erst, wenn alles entschieden ist' );
		self::assertStringContainsString( 'list=classification', $html );
		self::assertSame( 6, substr_count( $html, 'name="proposed"' ), 'jede Zeile mit «übernehmen»' );
		self::assertSame( 6, substr_count( $html, 'name="note" rows="2" required' ), 'Bemerkung ist beim Übersteuern Pflicht' );

		preg_match_all( '/\sid="([^"]+)"/', $html, $ids );
		self::assertSame( array_values( array_unique( $ids[1] ) ), $ids[1], 'jede id nur einmal' );
		preg_match_all( '/<label for="([^"]+)"/', $html, $labels );
		self::assertSame( array(), array_values( array_diff( $labels[1], $ids[1] ) ), 'jedes Label zeigt auf ein Feld' );
	}

	public function test_page_applies_the_filter_of_the_request_and_passes_it_to_the_forms(): void {
		$this->compute();
		wp_set_current_user( $this->tk );
		Classification::service()->accept( $this->actor(), $this->proposal( 'entwurf' ) );
		$_GET = array(
			'page'      => Classification::PAGE,
			'club'      => (string) $this->id['club_wollishofen'],
			'open'      => '1',
			'deviating' => 'unsinn',
		);

		$html = $this->render();

		self::assertSame( array( 'Probe Lukas', 'Vorlage Sara' ), array_map( static fn( array $cells ): string => explode( 'Lizenz', $cells[0] )[0], $this->table( $html ) ) );
		self::assertStringContainsString( 'Alle offenen übernehmen (2)', $html );
		$hidden = '<input type="hidden" name="club" value="' . $this->id['club_wollishofen'] . '"><input type="hidden" name="open" value="1">';
		self::assertSame( 7, substr_count( $html, $hidden ), 'berechnen, abschliessen, alle übernehmen und je zwei Formulare für zwei Zeilen' );
		self::assertStringContainsString( 'id="ttl-cl-deviating" name="deviating" value="1">', $html, 'ungültiger Filter gilt als nicht gesetzt' );
		self::assertStringNotContainsString( '<input type="hidden" name="deviating"', $html );
		self::assertStringContainsString( 'id="ttl-cl-open" name="open" value="1" checked=\'checked\'>', $html );
		self::assertStringContainsString( 'club=' . $this->id['club_wollishofen'], $html );
		self::assertStringContainsString( '6 Spieler, 5 ohne Entscheid', $html, 'die Zahlen gelten für die ganze Saison' );
	}

	public function test_page_escapes_names_and_notes(): void {
		$this->compute();
		wp_set_current_user( $this->tk );
		$this->db->update( 'person', array( 'last_name' => '<script>alert(1)</script>' ), array( 'id' => $this->scenario->seeder->person_of( $this->id['muster'] ) ) );
		$this->db->update( 'club', array( 'name' => 'TTC <b>Seefeld</b>' ), array( 'id' => $this->id['club_seefeld'] ) );
		Classification::service()->override( $this->actor(), $this->proposal( 'muster' ), 7, '</textarea><img src=x onerror=alert(2)> "Zitat"' );

		$html = $this->render();

		self::assertStringNotContainsString( '<script>alert(1)', $html );
		self::assertStringNotContainsString( '<b>Seefeld', $html );
		self::assertStringNotContainsString( '<img src=x', $html );
		self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; Hans', $html );
		self::assertStringContainsString( '&lt;/textarea&gt;&lt;img src=x onerror=alert(2)&gt; &quot;Zitat&quot;</textarea>', $html );
	}

	public function test_decided_rows_show_the_decision_and_closed_classification_is_read_only(): void {
		$this->compute();
		wp_set_current_user( $this->tk );
		$service = Classification::service();
		$service->override( $this->actor(), $this->proposal( 'vorlage' ), 7, 'Lange verletzt, bleibt C7.' );
		$service->accept_open( $this->actor(), new ProposalFilter() );

		$open = $this->render();
		self::assertSame( 'C8', $this->cells( $open, 'muster' )[7] );
		self::assertSame( array( 'C7übersteuert', 'Lange verletzt, bleibt C7.' ), array_slice( $this->cells( $open, 'vorlage' ), 7, 2 ) );
		self::assertSame( 1, substr_count( $open, 'name="proposed"' ), 'übernehmen nur noch bei der abweichenden Zeile' );
		self::assertMatchesRegularExpression( '/<button type="submit" class="button">Klassierung abschliessen<\/button>/', $open );

		$service->close( $this->actor() );
		$closed = $this->render();

		self::assertStringContainsString( 'Abgeschlossen am ', $closed );
		foreach ( array( Classification::COMPUTE, Classification::ACCEPT, Classification::ACCEPT_OPEN, Classification::OVERRIDE, Classification::CLOSE ) as $action ) {
			self::assertStringNotContainsString( $action . '"', $closed, $action );
		}
		self::assertStringContainsString( 'name="action" value="' . Classification::REOPEN . '"', $closed );
		self::assertCount( 9, $this->cells( $closed, 'muster' ), 'ohne Spalte «Aktion»' );
		self::assertStringContainsString( 'list=classification', $closed, 'der Export bleibt' );
	}

	public function test_compute_action_writes_the_proposal_and_bumps_the_data_version(): void {
		wp_set_current_user( $this->tk );
		$version = DataVersion::get();
		$this->post( Classification::COMPUTE );

		$target = $this->redirect_of( array( Actions::class, 'compute' ) );

		self::assertSame( Tk::page_url( Classification::PAGE ), $target );
		self::assertSame( 6, $this->count_rows() );
		self::assertSame(
			array(
				'type'    => 'success',
				'message' => 'Vorschlag berechnet: 6 Spieler, davon 6 neu und 0 aktualisiert.',
				'details' => array(),
			),
			Flash::take( Tk::FLASH, $this->tk )
		);
		self::assertGreaterThan( $version, DataVersion::get() );
		self::assertSame( (string) $this->tk, $this->db->get_var( "SELECT user_id FROM {$this->db->table( 'audit_log' )} WHERE action = 'classification_computed'" ) );
	}

	public function test_compute_action_first_runs_the_pending_rating_recalculation(): void {
		wp_set_current_user( $this->tk );
		// Wie nach einer Resultatänderung: Die Neuberechnung ist eingeplant, die gespeicherten Wertungszahlen sind alt.
		Results::schedule_rating_recalc();
		self::assertNotFalse( wp_next_scheduled( Results::RECALC_HOOK ) );
		$this->post( Classification::COMPUTE );

		$this->redirect_of( array( Actions::class, 'compute' ) );

		self::assertFalse( wp_next_scheduled( Results::RECALC_HOOK ), 'ausgeführt statt weiter eingeplant' );
		$rating = $this->db->get_var( "SELECT rating FROM {$this->db->table( 'player_rating' )} WHERE player_id = %d", array( $this->id['muster'] ) );
		self::assertNotSame( '812.50', $rating, 'aus den Spielen neu berechnet' );
		self::assertSame( $rating, $this->db->get_var( "SELECT rating FROM {$this->db->table( 'classification_proposal' )} WHERE player_id = %d", array( $this->id['muster'] ) ) );
		$flash = Flash::take( Tk::FLASH, $this->tk );
		self::assertSame( 'success', $flash['type'] ?? null );
		self::assertSame( array( 'Zuvor wurde die ausstehende Neuberechnung der Wertungszahl ausgeführt.' ), $flash['details'] ?? null );
		self::assertTrue( $this->other_process()->acquire( 0 ), 'die Sperre der Neuberechnung ist wieder frei' );
	}

	public function test_a_failed_rating_recalculation_stays_scheduled_and_frees_the_lock(): void {
		Results::schedule_rating_recalc();
		$this->db->update( 'season', array( 'rules_json' => '{"sets_to_win":0}' ), array( 'id' => $this->id['season'] ) );

		try {
			Classification::refresh_ratings();
			self::fail( 'Fehler erwartet.' );
		} catch ( \InvalidArgumentException ) {
			self::assertNotFalse( wp_next_scheduled( Results::RECALC_HOOK ) );
		}
		self::assertSame( '812.50', $this->db->get_var( "SELECT rating FROM {$this->db->table( 'player_rating' )} WHERE player_id = %d", array( $this->id['muster'] ) ) );
		self::assertTrue( $this->other_process()->acquire( 0 ), 'die Sperre der Neuberechnung ist wieder frei' );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function actions_needing_current_ratings(): array {
		return array(
			'berechnen'    => array( Classification::COMPUTE, 'compute' ),
			'abschliessen' => array( Classification::CLOSE, 'close' ),
		);
	}

	/**
	 * Die Neuberechnung scheitert mit einem Error (Division durch null bei rating_scale 0). Die
	 * Regeln selbst sind für die Klassierung gültig, der Dienst kommt also bis zur Neuberechnung.
	 */
	#[DataProvider( 'actions_needing_current_ratings' )]
	public function test_a_failing_recalculation_ends_as_feedback_not_as_a_crash( string $action, string $method ): void {
		$this->prepare( $method );
		wp_set_current_user( $this->tk );
		$this->db->update( 'season', array( 'rules_json' => '{"classification_min_games":3,"rating_scale":0}' ), array( 'id' => $this->id['season'] ) );
		Results::schedule_rating_recalc();
		$before = $this->snapshot();
		$this->post( $action );
		$log      = (string) tempnam( sys_get_temp_dir(), 'ttl' );
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Fehlerprotokoll im Test umleiten.

		try {
			$target = $this->redirect_of( array( Actions::class, $method ) );
			$logged = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test liest die eigene Datei.
		} finally {
			ini_set( 'error_log', false === $previous ? '' : $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- siehe oben.
			unlink( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- eigene temporäre Datei.
		}

		self::assertSame( Tk::page_url( Classification::PAGE ), $target, 'zurück auf die Seite statt kritischer Fehler' );
		self::assertSame(
			array(
				'type'    => 'error',
				'message' => 'Die ausstehende Neuberechnung der Wertungszahl ist gescheitert; sie bleibt eingeplant. Bitte später erneut versuchen.',
				'details' => array(),
			),
			Flash::take( Tk::FLASH, $this->tk )
		);
		self::assertSame( $before, $this->snapshot(), 'weder berechnet noch abgeschlossen' );
		self::assertNotFalse( wp_next_scheduled( Results::RECALC_HOOK ), 'bleibt eingeplant' );
		self::assertStringContainsString( 'DivisionByZeroError', $logged, 'die Ursache steht im Fehlerprotokoll' );
		self::assertTrue( $this->other_process()->acquire( 0 ), 'die Sperre der Neuberechnung ist wieder frei' );
	}

	/**
	 * Ein Lauf im Hintergrund rechnet gerade: WP-Cron hat den geplanten Eintrag schon entfernt,
	 * das Ergebnis ist noch nicht gespeichert. Ohne die Sperre sähe die Klassierung nichts
	 * Ausstehendes und arbeitete mit alten Wertungszahlen.
	 */
	public function test_compute_and_close_wait_for_a_running_recalculation_and_give_up_after_the_wait(): void {
		$this->compute();
		wp_set_current_user( $this->tk );
		Classification::service()->accept_open( $this->actor(), new ProposalFilter() );
		$before = $this->snapshot();
		self::assertFalse( wp_next_scheduled( Results::RECALC_HOOK ) );
		self::assertTrue( $this->other_process()->acquire( 0 ) );
		add_filter( 'ttleague_rating_recalc_lock_wait', static fn(): int => 1 );
		$log      = (string) tempnam( sys_get_temp_dir(), 'ttl' );
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Fehlerprotokoll im Test umleiten.

		try {
			foreach ( self::actions_needing_current_ratings() as $name => [ $action, $method ] ) {
				$this->post( $action );
				$start = microtime( true );

				$this->redirect_of( array( Actions::class, $method ) );

				self::assertGreaterThanOrEqual( 0.9, microtime( true ) - $start, $name . ': hat auf den Lauf gewartet' );
				$flash = Flash::take( Tk::FLASH, $this->tk );
				self::assertSame( 'error', $flash['type'] ?? null, $name );
				self::assertStringContainsString( 'Neuberechnung der Wertungszahl ist gescheitert', $flash['message'] ?? '', $name );
				self::assertSame( $before, $this->snapshot(), $name . ': weder berechnet noch abgeschlossen' );
			}
		} finally {
			ini_set( 'error_log', false === $previous ? '' : $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- siehe oben.
			unlink( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- eigene temporäre Datei.
		}

		// Der Lauf ist fertig: Die Sperre ist frei, alles geht wie bisher.
		$this->other_process()->release();
		$this->post( Classification::CLOSE );
		$this->redirect_of( array( Actions::class, 'close' ) );
		self::assertSame( 'success', Flash::take( Tk::FLASH, $this->tk )['type'] ?? null );
		self::assertNotNull( $this->closed_at() );
	}

	public function test_the_scheduled_recalculation_holds_the_lock_while_it_runs(): void {
		$held  = null;
		$probe = function () use ( &$held ): void {
			// Während des Laufs (kurz vor seinem Ende) von einer anderen Verbindung aus gesehen.
			$held = ! $this->other_process()->acquire( 0 );
		};
		add_action( 'update_option_' . DataVersion::OPTION, $probe );
		add_action( 'add_option_' . DataVersion::OPTION, $probe );

		Results::recalc_ratings();

		self::assertTrue( $held, 'während der Neuberechnung belegt' );
		self::assertNotSame( '812.50', $this->db->get_var( "SELECT rating FROM {$this->db->table( 'player_rating' )} WHERE player_id = %d", array( $this->id['muster'] ) ) );
		self::assertTrue( $this->other_process()->acquire( 0 ), 'danach wieder frei' );
	}

	public function test_the_scheduled_recalculation_is_rescheduled_while_another_run_holds_the_lock(): void {
		self::assertTrue( $this->other_process()->acquire( 0 ) );
		add_filter( 'ttleague_rating_recalc_lock_wait', static fn(): int => 1 );
		// WP-Cron entfernt den Eintrag, bevor es den Lauf startet.
		self::assertFalse( wp_next_scheduled( Results::RECALC_HOOK ) );

		Results::recalc_ratings();

		self::assertSame( '812.50', $this->db->get_var( "SELECT rating FROM {$this->db->table( 'player_rating' )} WHERE player_id = %d", array( $this->id['muster'] ) ), 'nicht doppelt gerechnet' );
		self::assertNotFalse( wp_next_scheduled( Results::RECALC_HOOK ), 'neu eingeplant statt verloren' );
	}

	public function test_a_write_during_the_recalculation_keeps_it_scheduled(): void {
		Results::schedule_rating_recalc();
		// Ein Resultat wird gespeichert, während gerechnet wird: Sein eigenes Einplanen ändert am
		// bestehenden Eintrag nichts, es hinterlässt aber einen Eintrag im audit_log.
		$write = function (): void {
			( new AuditLog( $this->db ) )->record( 'fixture', 1, 'result_entered', 1 );
			Results::schedule_rating_recalc();
		};
		add_action( 'update_option_' . DataVersion::OPTION, $write );
		add_action( 'add_option_' . DataVersion::OPTION, $write );

		self::assertTrue( Classification::refresh_ratings() );
		self::assertNotFalse( wp_next_scheduled( Results::RECALC_HOOK ), 'der Lauf für das neue Resultat steht weiter aus' );

		remove_action( 'update_option_' . DataVersion::OPTION, $write );
		remove_action( 'add_option_' . DataVersion::OPTION, $write );
		self::assertTrue( Classification::refresh_ratings() );
		self::assertFalse( wp_next_scheduled( Results::RECALC_HOOK ), 'ohne weiteren Schreibvorgang entfernt' );
		self::assertFalse( Classification::refresh_ratings(), 'nichts steht mehr aus' );
	}

	public function test_compute_action_reports_decisions_reset_by_a_changed_proposal(): void {
		$this->compute();
		wp_set_current_user( $this->tk );
		Classification::service()->accept( $this->actor(), $this->proposal( 'muster' ) );
		Classification::service()->override( $this->actor(), $this->proposal( 'vorlage' ), 7, 'Bleibt C7.' );
		$this->db->update( 'player_rating', array( 'rating' => 951.0 ), array( 'player_id' => $this->id['muster'] ) );
		$this->db->update( 'player_rating', array( 'rating' => 760.0 ), array( 'player_id' => $this->id['vorlage'] ) );
		$this->post( Classification::COMPUTE );

		$this->redirect_of( array( Actions::class, 'compute' ) );

		$flash = Flash::take( Tk::FLASH, $this->tk );
		self::assertSame( 'warning', $flash['type'] ?? null );
		self::assertSame( 'Vorschlag berechnet: 6 Spieler, davon 0 neu und 2 aktualisiert.', $flash['message'] ?? null );
		self::assertSame( array( '1 übernommener Entscheid zurückgesetzt, weil sich der Vorschlag geändert hat. Die Zeile ist wieder offen (Filter «noch offen»).' ), $flash['details'] ?? null );
		self::assertSame( 1, $this->decided_rows(), 'nur die Übersteuerung bleibt' );

		$_GET = array( 'open' => '1' );
		self::assertContains( 'Muster Hans', array_map( static fn( array $cells ): string => explode( 'Lizenz', $cells[0] )[0], $this->table( $this->render() ) ), 'unter «noch offen»' );
	}

	public function test_close_action_runs_the_pending_recalculation_and_refuses_outdated_rows(): void {
		$this->compute();
		wp_set_current_user( $this->tk );
		Classification::service()->accept_open( $this->actor(), new ProposalFilter() );
		$before = $this->snapshot();
		Results::schedule_rating_recalc();
		$this->post( Classification::CLOSE );

		$this->redirect_of( array( Actions::class, 'close' ) );

		self::assertFalse( wp_next_scheduled( Results::RECALC_HOOK ) );
		$flash = Flash::take( Tk::FLASH, $this->tk );
		self::assertSame( 'error', $flash['type'] ?? null );
		// Die echte Neuberechnung ändert die Wertungszahl aller fünf Spieler mit Spielen.
		self::assertStringContainsString( '5 Zeilen entsprechen nicht mehr dem aktuellen Stand', $flash['message'] ?? '' );
		self::assertStringContainsString( '«Vorschlag neu berechnen»', $flash['message'] ?? '' );
		self::assertSame( $before, $this->snapshot(), 'weder abgeschlossen noch Zeilen geändert' );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function actions(): array {
		return array(
			'berechnen'       => array( Classification::COMPUTE, 'compute' ),
			'übernehmen'      => array( Classification::ACCEPT, 'accept' ),
			'alle übernehmen' => array( Classification::ACCEPT_OPEN, 'accept_open' ),
			'übersteuern'     => array( Classification::OVERRIDE, 'override' ),
			'abschliessen'    => array( Classification::CLOSE, 'close' ),
			'wieder öffnen'   => array( Classification::REOPEN, 'reopen' ),
		);
	}

	#[DataProvider( 'actions' )]
	public function test_every_action_needs_its_nonce( string $action, string $method ): void {
		$fields = $this->prepare( $method );
		$before = $this->snapshot();
		wp_set_current_user( $this->tk );
		// Gültige Nonce einer anderen Aktion: Ohne die Prüfung ginge die Aktion durch.
		$this->post( $action, $fields, wp_create_nonce( Classification::COMPUTE === $action ? Classification::CLOSE : Classification::COMPUTE ) );

		try {
			Actions::$method();
			self::fail( 'Abbruch erwartet.' );
		} catch ( \WPDieException ) {
			self::assertSame( $before, $this->snapshot() );
		}
	}

	#[DataProvider( 'actions' )]
	public function test_every_action_needs_the_tk_capability( string $action, string $method ): void {
		$fields = $this->prepare( $method );
		$before = $this->snapshot();
		// TK-Chef: angemeldet, mit Funktion in der aktiven Saison, aber ohne ttl_tk.
		$this->sign_in( 'chef' );
		$this->post( $action, $fields );

		try {
			Actions::$method();
			self::fail( 'Abbruch erwartet.' );
		} catch ( \WPDieException $e ) {
			self::assertSame( 'Dazu fehlt dir die Berechtigung.', $e->getMessage() );
		}
		self::assertSame( $before, $this->snapshot() );
		self::assertNull( Flash::take( Tk::FLASH, get_current_user_id() ) );
	}

	#[DataProvider( 'actions' )]
	public function test_every_action_works_for_the_tk( string $action, string $method ): void {
		$fields = $this->prepare( $method );
		$before = $this->snapshot();
		wp_set_current_user( $this->tk );
		$this->post( $action, $fields );

		$this->redirect_of( array( Actions::class, $method ) );

		self::assertNotSame( $before, $this->snapshot(), 'mit Nonce und ttl_tk geht dieselbe Anfrage durch' );
		self::assertSame( 'success', Flash::take( Tk::FLASH, $this->tk )['type'] ?? null );
	}

	public function test_accept_action_decides_one_row_and_returns_to_it_with_the_filter(): void {
		$this->compute();
		wp_set_current_user( $this->tk );
		$id = $this->proposal( 'muster' );
		$this->post(
			Classification::ACCEPT,
			array(
				'proposal' => (string) $id,
				'proposed' => '8',
				'club'     => (string) $this->id['club_seefeld'],
				'open'     => '1',
			)
		);

		$target = $this->redirect_of( array( Actions::class, 'accept' ) );

		self::assertSame( Tk::page_url( Classification::PAGE ) . '&club=' . $this->id['club_seefeld'] . '&open=1#ttl-proposal-' . $id, $target );
		$row = $this->db->get_row( "SELECT decided, decided_by, note FROM {$this->db->table( 'classification_proposal' )} WHERE id = %d", array( $id ) );
		self::assertSame(
			array(
				'decided'    => '8',
				'decided_by' => (string) $this->tk,
				'note'       => null,
			),
			$row
		);
		self::assertSame( 'Muster Hans: Vorschlag C8 übernommen.', Flash::take( Tk::FLASH, $this->tk )['message'] ?? null );
		self::assertSame( 1, $this->decided_rows() );
	}

	public function test_accept_action_reports_a_proposal_that_changed_meanwhile(): void {
		$this->compute();
		wp_set_current_user( $this->tk );
		$id = $this->proposal( 'muster' );
		$this->post(
			Classification::ACCEPT,
			array(
				'proposal' => (string) $id,
				'proposed' => '7',
			)
		);

		$this->redirect_of( array( Actions::class, 'accept' ) );

		self::assertSame( 0, $this->decided_rows() );
		$flash = Flash::take( Tk::FLASH, $this->tk );
		self::assertSame( 'error', $flash['type'] ?? null );
		self::assertStringContainsString( 'neu berechnet', $flash['message'] ?? '' );
	}

	public function test_override_action_needs_a_note_and_stores_the_text_as_typed(): void {
		$this->compute();
		wp_set_current_user( $this->tk );
		$id      = $this->proposal( 'vorlage' );
		$version = DataVersion::get();
		$this->post(
			Classification::OVERRIDE,
			array(
				'proposal' => (string) $id,
				'value'    => '7',
				'note'     => "  \n ",
			)
		);

		$this->redirect_of( array( Actions::class, 'override' ) );

		self::assertSame( 0, $this->decided_rows() );
		$flash = Flash::take( Tk::FLASH, $this->tk );
		self::assertSame( 'error', $flash['type'] ?? null );
		self::assertStringContainsString( 'Bemerkung', $flash['message'] ?? '' );
		self::assertSame( $version, DataVersion::get(), 'ohne Änderung keine neue Cache-Version' );

		$note = 'Rekurs gutgeheissen: "bleibt C7", gilt ab \'27';
		$this->post(
			Classification::OVERRIDE,
			array(
				'proposal' => (string) $id,
				'value'    => '7',
				'note'     => $note,
			)
		);

		$target = $this->redirect_of( array( Actions::class, 'override' ) );

		self::assertStringEndsWith( '#ttl-proposal-' . $id, $target );
		self::assertSame(
			array(
				'decided' => '7',
				'note'    => $note,
			),
			$this->db->get_row( "SELECT decided, note FROM {$this->db->table( 'classification_proposal' )} WHERE id = %d", array( $id ) )
		);
		self::assertSame( 'Vorlage Sara: C7 entschieden (Vorschlag C6).', Flash::take( Tk::FLASH, $this->tk )['message'] ?? null );
		self::assertGreaterThan( $version, DataVersion::get() );
	}

	public function test_override_action_rejects_a_value_outside_the_scale(): void {
		$this->compute();
		wp_set_current_user( $this->tk );
		$this->post(
			Classification::OVERRIDE,
			array(
				'proposal' => (string) $this->proposal( 'vorlage' ),
				'value'    => '23',
				'note'     => 'Zu hoch.',
			)
		);

		$this->redirect_of( array( Actions::class, 'override' ) );

		self::assertSame( 0, $this->decided_rows() );
		self::assertSame( 'error', Flash::take( Tk::FLASH, $this->tk )['type'] ?? null );
	}

	public function test_accept_open_action_takes_the_filter_from_the_form(): void {
		$this->compute();
		wp_set_current_user( $this->tk );
		$this->post( Classification::ACCEPT_OPEN, array( 'club' => (string) $this->id['club_wollishofen'] ) );

		$target = $this->redirect_of( array( Actions::class, 'accept_open' ) );

		self::assertSame( Tk::page_url( Classification::PAGE ) . '&club=' . $this->id['club_wollishofen'], $target );
		self::assertSame(
			array( $this->id['vorlage'], $this->id['entwurf'], $this->id['probe'] ),
			array_map( 'intval', $this->db->get_col( "SELECT player_id FROM {$this->db->table( 'classification_proposal' )} WHERE decided IS NOT NULL AND decided = proposed ORDER BY player_id" ) )
		);
		self::assertSame( 'Vorschlag für 3 Zeilen übernommen.', Flash::take( Tk::FLASH, $this->tk )['message'] ?? null );

		$this->post( Classification::ACCEPT_OPEN, array( 'club' => (string) $this->id['club_wollishofen'] ) );
		$this->redirect_of( array( Actions::class, 'accept_open' ) );
		self::assertSame( 'info', Flash::take( Tk::FLASH, $this->tk )['type'] ?? null, 'nichts mehr offen' );
	}

	public function test_close_action_is_refused_with_open_rows_and_reopen_needs_a_note(): void {
		$this->compute();
		wp_set_current_user( $this->tk );
		Classification::service()->accept_open( $this->actor(), new ProposalFilter( $this->id['club_seefeld'] ) );

		$this->post( Classification::CLOSE );
		$this->redirect_of( array( Actions::class, 'close' ) );
		$flash = Flash::take( Tk::FLASH, $this->tk );
		self::assertSame( 'error', $flash['type'] ?? null );
		self::assertStringContainsString( '3 Zeilen sind ohne Entscheid', $flash['message'] ?? '' );
		self::assertNull( $this->closed_at() );

		Classification::service()->accept_open( $this->actor(), new ProposalFilter() );
		$this->post( Classification::CLOSE );
		$this->redirect_of( array( Actions::class, 'close' ) );
		self::assertSame( 'success', Flash::take( Tk::FLASH, $this->tk )['type'] ?? null );
		self::assertNotNull( $this->closed_at() );

		$this->post( Classification::REOPEN, array( 'note' => '' ) );
		$this->redirect_of( array( Actions::class, 'reopen' ) );
		self::assertSame( 'error', Flash::take( Tk::FLASH, $this->tk )['type'] ?? null );
		self::assertNotNull( $this->closed_at(), 'ohne Bemerkung bleibt sie abgeschlossen' );

		$this->post( Classification::REOPEN, array( 'note' => 'Rekurs Vorlage.' ) );
		$this->redirect_of( array( Actions::class, 'reopen' ) );
		self::assertSame( 'success', Flash::take( Tk::FLASH, $this->tk )['type'] ?? null );
		self::assertNull( $this->closed_at() );
		self::assertSame( 'Rekurs Vorlage.', $this->db->get_var( "SELECT note FROM {$this->db->table( 'audit_log' )} WHERE action = 'classification_reopened'" ) );
	}

	public function test_export_lists_the_session_as_csv(): void {
		$this->compute();
		$service = Classification::service();
		$service->accept( $this->actor(), $this->proposal( 'muster' ) );
		$service->override( $this->actor(), $this->proposal( 'vorlage' ), 7, 'Lange verletzt; bleibt C7.' );

		$header = "\xEF\xBB\xBFLizenznummer;Name;Club;Bisherige Klassierung;Wertungszahl;Spiele;Vorschlag;Entscheid;Bemerkung\r\n";
		self::assertSame(
			$header
			. "90003;Ablage Beat;TTC Seefeld;D5;520;1;D5;;\r\n"
			. "90002;Beispiel Anna;TTC Seefeld;C10;1160;2;C10;;\r\n"
			. "90001;Muster Hans;TTC Seefeld;C6;812.5;3;C8;C8;\r\n"
			. "90005;Entwurf Petra;TTC Wollishofen;D4;380;1;D4;;\r\n"
			. "90006;Probe Lukas;TTC Wollishofen;C9;;0;C9;;\r\n"
			. "90004;Vorlage Sara;TTC Wollishofen;C7;640;3;C6;C7;\"Lange verletzt; bleibt C7.\"\r\n",
			Exports::csv( Classification::EXPORT )
		);

		self::assertSame(
			$header . "90003;Ablage Beat;TTC Seefeld;D5;520;1;D5;;\r\n90002;Beispiel Anna;TTC Seefeld;C10;1160;2;C10;;\r\n",
			Exports::csv(
				Classification::EXPORT,
				array(
					'club' => (string) $this->id['club_seefeld'],
					'open' => '1',
				)
			)
		);
		self::assertSame( 2, substr_count( (string) Exports::csv( Classification::EXPORT, array( 'deviating' => '1' ) ), "\r\n" ) - 1 );
		self::assertSame( $header, Exports::csv( Classification::EXPORT, array( 'season' => (string) $this->id['old_season'] ) ), 'andere Saison: keine Zeilen der aktiven' );
		self::assertSame( $header, Exports::csv( Classification::EXPORT, array( 'season' => 'aktiv' ) ), 'ungültige Saison: leer statt aktive Saison' );
		self::assertSame( 7, substr_count( (string) Exports::csv( Classification::EXPORT, array( 'season' => (string) $this->id['season'] ) ), "\r\n" ) );
	}

	public function test_export_download_needs_the_tk_capability(): void {
		$this->compute();
		$this->sign_in( 'chef' );
		$this->request( Exports::url( Classification::EXPORT ) );
		try {
			Exports::requested();
			self::fail( 'Abbruch erwartet.' );
		} catch ( \WPDieException $e ) {
			self::assertSame( 'Dazu fehlt dir die Berechtigung.', $e->getMessage() );
		}

		wp_set_current_user( $this->tk );
		$this->request( Exports::url( Classification::EXPORT, array( 'club' => (string) $this->id['club_wollishofen'] ) ) );
		$file = Exports::requested();

		self::assertSame( 'ttleague-classification-' . wp_date( 'Y-m-d' ) . '.csv', $file['filename'] );
		self::assertStringContainsString( 'Vorlage Sara;TTC Wollishofen', $file['csv'] );
		self::assertStringNotContainsString( 'Muster Hans', $file['csv'], 'Filter der Seite gilt auch im Export' );
	}

	/**
	 * Die Sperre der Neuberechnung aus Sicht eines anderen Prozesses (eigene Verbindung), z. B.
	 * des Laufs von WP-Cron. Wird am Ende des Tests freigegeben.
	 */
	private function other_process(): RecalcLock {
		global $wpdb;
		if ( null === $this->other ) {
			$host        = (array) $wpdb->parse_db_host( DB_HOST );
			$dsn         = 'mysql:host=' . $host[0] . ( empty( $host[1] ) ? '' : ';port=' . $host[1] ) . ';dbname=' . DB_NAME . ';charset=utf8mb4';
			$this->other = new RecalcLock( PdoDb::connect( $dsn, DB_USER, DB_PASSWORD, $wpdb->prefix ), RecalcLock::name( DB_NAME, $wpdb->prefix ) );
		}
		return $this->other;
	}

	/**
	 * Vorschlag als TK berechnen, ohne Formular.
	 */
	private function compute(): void {
		Classification::service()->compute( $this->actor() );
	}

	private function actor(): Actor {
		return new Actor( $this->tk, null, true, 'TK' );
	}

	/**
	 * Stand, in dem die Aktion gelingen kann, und ihre Formularfelder.
	 *
	 * @return array<string,string>
	 */
	private function prepare( string $method ): array {
		$service = Classification::service();
		if ( 'compute' === $method ) {
			return array();
		}
		$this->compute();
		if ( in_array( $method, array( 'close', 'reopen' ), true ) ) {
			$service->accept_open( $this->actor(), new ProposalFilter() );
		}
		if ( 'reopen' === $method ) {
			$service->close( $this->actor() );
		}
		return match ( $method ) {
			'accept'   => array(
				'proposal' => (string) $this->proposal( 'muster' ),
				'proposed' => '8',
			),
			'override' => array(
				'proposal' => (string) $this->proposal( 'muster' ),
				'value'    => '7',
				'note'     => 'Bleibt tiefer.',
			),
			'reopen'   => array( 'note' => 'Rekurs.' ),
			default    => array(),
		};
	}

	/**
	 * Alles, was eine Aktion ändern könnte.
	 *
	 * @return array{0:list<array<string,string|null>>,1:string|null,2:int}
	 */
	private function snapshot(): array {
		return array(
			$this->db->get_results( "SELECT * FROM {$this->db->table( 'classification_proposal' )} ORDER BY id" ),
			$this->closed_at(),
			(int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'audit_log' )}" ),
		);
	}

	private function sign_in( string $who ): void {
		if ( 'guest' === $who ) {
			wp_set_current_user( 0 );
			return;
		}
		$person = null;
		if ( 'chef' === $who ) {
			$person = $this->scenario->seeder->person_of( $this->id['muster'] );
			$this->scenario->seeder->role( $this->id['season'], $this->id['club_seefeld'], $person, 'tk_chef' );
		}
		$user = $this->account( 'subscriber', $who . '@example.invalid', $person );
		wp_set_current_user( $user );
		if ( 'chef' === $who ) {
			self::assertTrue( current_user_can( Capabilities::MANAGE_CLUB, $this->id['club_seefeld'] ), 'TK-Chef des Clubs' );
		}
		self::assertFalse( current_user_can( Capabilities::TK ) );
	}

	/**
	 * Formularfelder als POST-Anfrage ablegen.
	 *
	 * @param array<string,string> $fields Felder.
	 * @param string|null          $nonce  Nonce; ohne Angabe die gültige der Aktion.
	 */
	private function post( string $action, array $fields = array(), ?string $nonce = null ): void {
		$_POST    = wp_slash(
			$fields + array(
				'action'   => $action,
				'_wpnonce' => $nonce ?? wp_create_nonce( $action ),
			)
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Der Test setzt die Formulardaten, der Nonce ist enthalten.
	}

	/**
	 * Legt die Parameter einer Adresse als GET-Anfrage ab.
	 */
	private function request( string $url ): void {
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$_GET     = $query;
		$_REQUEST = $query;
	}

	private function render(): string {
		ob_start();
		Page::render();
		return (string) ob_get_clean();
	}

	private function proposal( string $key ): int {
		return (int) $this->db->get_var( "SELECT id FROM {$this->db->table( 'classification_proposal' )} WHERE player_id = %d", array( $this->id[ $key ] ) );
	}

	private function count_rows(): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'classification_proposal' )}" );
	}

	private function decided_rows(): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'classification_proposal' )} WHERE decided IS NOT NULL" );
	}

	private function closed_at(): ?string {
		return $this->db->get_var( "SELECT classification_closed_at FROM {$this->db->table( 'season' )} WHERE id = %d", array( $this->id['season'] ) );
	}

	/**
	 * Textinhalt der Zellen jeder Tabellenzeile.
	 *
	 * @return list<list<string>>
	 */
	private function table( string $html ): array {
		preg_match_all( '/<tr id="ttl-proposal-\d+">(.*?)<\/tr>/s', $html, $rows );
		return array_map( array( $this, 'cell_texts' ), $rows[1] );
	}

	/**
	 * Textinhalt der Zellen der Zeile eines Spielers.
	 *
	 * @return list<string>
	 */
	private function cells( string $html, string $key ): array {
		self::assertSame( 1, preg_match( '/<tr id="ttl-proposal-' . $this->proposal( $key ) . '">(.*?)<\/tr>/s', $html, $row ), 'Zeile für ' . $key );
		return $this->cell_texts( $row[1] );
	}

	/**
	 * @return list<string>
	 */
	private function cell_texts( string $row ): array {
		preg_match_all( '/<td[^>]*>(.*?)<\/td>/s', $row, $cells );
		return array_map( static fn( string $cell ): string => trim( wp_strip_all_tags( $cell ) ), $cells[1] );
	}
}
