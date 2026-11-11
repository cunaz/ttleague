<?php
/**
 * Backend «Spielbetrieb → Ranglistenturnier»: Seite und Formularaktionen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\RankingTournament;

use TTLeague\RankingTournament\AdminActions;
use TTLeague\RankingTournament\AdminPage;
use TTLeague\RankingTournament\RankingTournament;

final class AdminTest extends RlTestCase {

	public function test_page_needs_the_tk_capability(): void {
		wp_set_current_user( $this->users['chef'] );
		self::assertSame( 403, $this->die_of( array( AdminPage::class, 'render' ) )->getCode() );

		wp_set_current_user( $this->users['tk'] );
		ob_start();
		AdminPage::render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'Spielbetrieb: Ranglistenturnier', $html );
		self::assertStringContainsString( 'Person anmelden', $html );
		self::assertStringContainsString( 'Noch niemand ist angemeldet.', $html );
		self::assertStringContainsString( 'value="1:9, 2:1"', $html, 'Kategorien in den Einstellungen.' );
		self::assertStringContainsString( 'list=rl-anmeldungen', $html );
	}

	public function test_actions_need_nonce_and_tk(): void {
		$player = $this->league->id['h1'];
		// Die TK-Chefin hat einen gültigen Nonce, aber nicht das Recht.
		wp_set_current_user( $this->users['chef'] );
		$this->fields( 'register', $player );
		self::assertSame( 403, $this->die_of( array( AdminActions::class, 'handle' ) )->getCode() );

		// Die TK mit dem Nonce eines anderen Vorgangs.
		wp_set_current_user( $this->users['tk'] );
		$this->post(
			AdminActions::nonce( 'withdraw', $player ),
			array(
				'do' => 'register',
				'id' => (string) $player,
			)
		);
		self::assertSame( 403, $this->die_of( array( AdminActions::class, 'handle' ) )->getCode() );
		self::assertNull( RankingTournament::repository()->participant( $this->competition, $player ) );
	}

	public function test_tk_registers_sets_category_and_withdraws(): void {
		wp_set_current_user( $this->users['tk'] );
		$player = $this->league->id['h3'];

		// Formular «Person anmelden»: Die Person kommt aus der Auswahl, die Kategorie ist frei wählbar.
		$location = $this->submit(
			'register',
			0,
			array(
				'player'   => (string) $player,
				'category' => '1',
			)
		);
		self::assertSame( AdminPage::url( 'anmeldungen' ), $location );
		self::assertSame( 1, RankingTournament::repository()->participant( $this->competition, $player )['category'] );

		$this->submit( 'category', $player, array( 'category' => '2' ) );
		self::assertSame( 2, RankingTournament::repository()->participant( $this->competition, $player )['category'] );
		$html = AdminPage::html( 'anmeldungen', 0, null );
		self::assertStringContainsString( 'Spieler3 Seefelder', $html );
		self::assertStringContainsString( 'Kategorie 2: 1', $html );

		// Ungültige Kategorie: Fehlermeldung, nichts geändert.
		$this->submit( 'category', $player, array( 'category' => '7' ) );
		self::assertSame( 2, RankingTournament::repository()->participant( $this->competition, $player )['category'] );
		$flash = \TTLeague\Infrastructure\Flash::take( \TTLeague\Tk\Tk::FLASH, $this->users['tk'] );
		self::assertSame( 'error', $flash['type'] );
		self::assertSame( 'Diese Kategorie gibt es nicht.', $flash['message'] );

		$this->submit( 'withdraw', $player );
		self::assertSame( 'withdrawn', RankingTournament::repository()->participant( $this->competition, $player )['status'] );
		self::assertStringContainsString( 'Wieder anmelden', AdminPage::html( 'anmeldungen', 0, null ) );
	}

	public function test_tk_configures_the_tournament(): void {
		wp_set_current_user( $this->users['tk'] );
		$this->submit(
			'configure',
			$this->league->id['season'],
			array(
				'name'                  => 'RL Region',
				'categories'            => '1:10, 2:5 3:1',
				'registration_deadline' => '2027-01-15',
				'start_time'            => '19:45',
			)
		);
		$competition = RankingTournament::repository()->competition( $this->competition );
		self::assertSame( 'RL Region', $competition['name'] );
		self::assertSame( array( 10, 5, 1 ), array_column( $competition['categories'], 'min' ) );
		self::assertSame( '2027-01-15', $competition['deadline'] );
		self::assertSame( '19:45:00', $competition['start_time'] );

		// Unlesbare Kategorien werden abgelehnt.
		$this->submit( 'configure', $this->league->id['season'], array( 'categories' => '1-10, zwei' ) );
		self::assertSame( array( 10, 5, 1 ), array_column( RankingTournament::repository()->competition( $this->competition )['categories'], 'min' ) );
		self::assertSame(
			array(
				array(
					'no'  => 1,
					'min' => 16,
				),
				array(
					'no'  => null,
					'min' => 3,
				),
				array(
					'no'  => null,
					'min' => null,
				),
			),
			AdminActions::categories( '1:16; x:3 9' )
		);
	}

	public function test_round_from_proposal_to_closing(): void {
		wp_set_current_user( $this->users['tk'] );
		$this->register( array( 'h1', 'h2', 'a1', 'a2', 'h3', 'a3' ) );
		$monday = $this->monday();

		$location = $this->submit(
			'round_create',
			$this->competition,
			array(
				'date_from' => $monday,
				'date_to'   => ( new \DateTimeImmutable( $monday ) )->modify( '+3 days' )->format( 'Y-m-d' ),
			)
		);
		$rounds   = RankingTournament::repository()->rounds( $this->competition );
		self::assertCount( 1, $rounds );
		$round = $rounds[0]['id'];
		self::assertSame( 'planned', $rounds[0]['status'] );
		self::assertSame( AdminPage::url( 'runden', $round ), $location );

		// Einteilen: Kategorie 1 mit vier, Kategorie 2 mit zwei Personen, je ein Lokal pro Abend.
		self::assertSame( AdminPage::url( 'runden', $round ), $this->submit( 'propose', $round, array( 'round' => (string) $round ) ) );
		$groups = RankingTournament::repository()->groups( $round );
		self::assertCount( 2, $groups );
		$html = AdminPage::html( 'runden', $round, \TTLeague\Infrastructure\Flash::take( \TTLeague\Tk\Tk::FLASH, $this->users['tk'] ) );
		self::assertStringContainsString( 'Vorschlag mit 2 Gruppen gespeichert', $html );
		self::assertStringContainsString( 'Kategorie 1, Gruppe 1', $html );
		self::assertStringContainsString( 'Freigeben und Aufgebot verschicken', $html );
		self::assertStringContainsString( 'Gruppe ändern', $html );
		self::assertStringNotContainsString( 'Runde abschliessen', $html );

		// Gruppe ändern: neue Zeit, anderer Gruppenchef, eine Person weniger.
		$one     = $groups[0];
		$members = array_column( $one['members'], 'player_id' );
		$this->submit(
			'group_save',
			$one['id'],
			array(
				'round'           => (string) $round,
				'venue_id'        => (string) $this->venue['away'],
				'start_at'        => $monday . 'T20:15',
				'chief_player_id' => (string) $members[1],
				'note'            => 'Eingang hinten',
				'members'         => array_map( 'strval', array_slice( $members, 0, 3 ) ),
			)
		);
		$saved = RankingTournament::repository()->group( $one['id'] );
		self::assertSame( array( $this->venue['away'], $this->league->id['club_away'], $monday . ' 20:15:00', $members[1], 'Eingang hinten' ), array( $saved['venue_id'], $saved['host_club_id'], $saved['start_at'], $saved['chief_player_id'], $saved['note'] ) );
		self::assertCount( 3, $saved['members'] );
		$html = AdminPage::html( 'runden', $round, null );
		self::assertStringContainsString( 'Nicht zugeteilt:', $html );
		// Wieder hinzufügen über die Auswahl «Hinzufügen».
		$this->submit(
			'group_save',
			$one['id'],
			array(
				'round'    => (string) $round,
				'venue_id' => (string) $this->venue['away'],
				'start_at' => $monday . 'T20:15',
				'members'  => array_map( 'strval', array_slice( $members, 0, 3 ) ),
				'add'      => array( (string) $members[3] ),
			)
		);
		self::assertCount( 4, RankingTournament::repository()->group( $one['id'] )['members'] );

		// Freigeben: Die Anfrage der TK versendet nichts, sie merkt den Versand vor.
		$this->mails = array();
		$this->submit( 'publish', $round, array( 'round' => (string) $round ) );
		self::assertSame( 'published', RankingTournament::repository()->round( $round )['status'] );
		self::assertSame( array(), $this->mails );
		self::assertSame( array( array( $round, 0 ) ), $this->waiting_callup_events() );
		$flash = \TTLeague\Infrastructure\Flash::take( \TTLeague\Tk\Tk::FLASH, $this->users['tk'] );
		self::assertSame( 'Runde freigegeben. Die Gruppen sind öffentlich, die Aufgebote werden jetzt versendet.', $flash['message'] );
		// WP-Cron führt den Schritt aus: Aufgebot per E-Mail an alle sechs.
		$this->run_callup_events();
		self::assertCount( 6, $this->mails );
		self::assertStringContainsString( 'Aufgebot Ranglistenturnier: 1. Runde, Kategorie', $this->mails[0]->subject );
		self::assertSame( array(), $this->waiting_callup_events() );

		// Abschliessen geht erst mit Gruppenzetteln oder ausdrücklich ohne.
		$this->submit( 'close', $round, array( 'round' => (string) $round ) );
		self::assertSame( 'published', RankingTournament::repository()->round( $round )['status'] );
		self::assertSame( 'error', \TTLeague\Infrastructure\Flash::take( \TTLeague\Tk\Tk::FLASH, $this->users['tk'] )['type'] );
		RankingTournament::entry()->save( $one['id'], $this->sheet( $one['id'] ), $this->tk() );
		$html = AdminPage::html( 'runden', $round, null );
		self::assertStringContainsString( 'Runde abschliessen', $html );
		self::assertStringContainsString( 'name="manual[' . $one['id'] . '][' . $members[0] . '][rank]" value="1"', $html );
		self::assertStringContainsString( '1 Gruppe ohne Gruppenzettel bleibt ohne Resultat.', $html );
		self::assertStringContainsString( 'Gruppenzettel zurücksetzen', $html );

		// Von Hand: Die Zweitplatzierte erhält 5,5 Punkte (Los); die anderen Felder bleiben beim berechneten Wert.
		$second = RankingTournament::repository()->group( $one['id'] )['members'][1]['player_id'];
		$this->submit(
			'close',
			$round,
			array(
				'round'         => (string) $round,
				'allow_missing' => '1',
				'manual'        => array(
					(string) $one['id'] => array(
						(string) $second => array(
							'rank'   => '2',
							'points' => '5,5',
						),
					),
				),
			)
		);
		self::assertSame( 'closed', RankingTournament::repository()->round( $round )['status'] );
		$closed = array_column( RankingTournament::repository()->group( $one['id'] )['members'], 'points', 'player_id' );
		self::assertSame( 5.5, $closed[ $second ] );
		$manual = $this->db->get_row( "SELECT user_id, before_json, after_json FROM {$this->db->table( 'audit_log' )} WHERE entity = 'rl_group' AND entity_id = %d AND action = 'rank_manual'", array( $one['id'] ) );
		self::assertSame( (string) $this->users['tk'], $manual['user_id'] );
		self::assertStringContainsString( '"points":5.5', (string) $manual['after_json'] );
		$html = AdminPage::html( 'runden', $round, null );
		self::assertStringNotContainsString( 'Gruppe ändern', $html );
		self::assertStringContainsString( 'Gruppenzettel ansehen', $html );
		self::assertStringContainsString( 'abgeschlossen', AdminPage::html( 'runden', 0, null ) );
	}

	public function test_resetting_a_group_sheet_needs_a_confirmation(): void {
		wp_set_current_user( $this->users['tk'] );
		[ $round, $group ] = $this->published_group();
		RankingTournament::entry()->save( $group, $this->sheet( $group ), $this->tk() );

		// Das Formular verlangt das Kontrollkästchen, auch ohne JavaScript.
		$html = AdminPage::html( 'runden', $round, null );
		self::assertSame( 1, preg_match( '~<form[^>]*>(?:(?!</form>).)*name="do" value="group_reset"(?:(?!</form>).)*</form>~s', $html, $form ) );
		self::assertStringContainsString( '<input type="checkbox" id="ttl-rl-reset-' . $group . '" name="confirm" value="1" required>', $form[0] );
		self::assertStringContainsString( 'Alle erfassten Partien dieser Gruppe löschen', $form[0] );

		// Ohne das Feld setzt die Aktion nichts zurück.
		$this->submit( 'group_reset', $group, array( 'round' => (string) $round ) );
		$flash = \TTLeague\Infrastructure\Flash::take( \TTLeague\Tk\Tk::FLASH, $this->users['tk'] );
		self::assertSame( 'error', $flash['type'] );
		self::assertStringContainsString( 'Nicht zurückgesetzt', $flash['message'] );
		self::assertCount( 6, RankingTournament::repository()->group( $group )['games'] );
		self::assertNotNull( RankingTournament::repository()->group( $group )['entered_at'] );

		// Mit Bestätigung.
		$this->submit(
			'group_reset',
			$group,
			array(
				'round'   => (string) $round,
				'confirm' => '1',
			)
		);
		self::assertSame( 'success', \TTLeague\Infrastructure\Flash::take( \TTLeague\Tk\Tk::FLASH, $this->users['tk'] )['type'] );
		$after = RankingTournament::repository()->group( $group );
		self::assertSame( array(), $after['games'] );
		self::assertNull( $after['entered_at'] );
		self::assertSame( array( 'scheduled' ), array_values( array_unique( array_column( $after['members'], 'status' ) ) ) );
	}

	/**
	 * Formularfelder einer Aktion mit gültigem Nonce.
	 *
	 * @param array<string,mixed> $fields Weitere Felder.
	 */
	private function fields( string $task, int $id, array $fields = array() ): void {
		$this->post(
			AdminActions::nonce( $task, $id ),
			$fields + array(
				'do' => $task,
				'id' => (string) $id,
			)
		);
	}

	/**
	 * Schickt eine Aktion ab und liefert das Ziel der Weiterleitung.
	 *
	 * @param array<string,mixed> $fields Weitere Felder.
	 */
	private function submit( string $task, int $id, array $fields = array() ): string {
		$this->fields( $task, $id, $fields );
		return $this->redirect_of( array( AdminActions::class, 'handle' ) );
	}
}
