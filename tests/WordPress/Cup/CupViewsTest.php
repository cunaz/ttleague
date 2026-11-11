<?php
/**
 * Darstellung des Mannschaftscups: «Mein Bereich», Backend, Übersicht der TK, Cup-Seite, Export.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Cup;

use TTLeague\Cup\AdminPage;
use TTLeague\Cup\Cup;
use TTLeague\Cup\Export;
use TTLeague\Cup\Forms;
use TTLeague\Export\Exports;
use TTLeague\Notifications\EventTypes;
use TTLeague\Results\Results;
use TTLeague\Tk\DashboardPage;
use TTLeague\Tk\Tk;

/**
 * Daten siehe CupWpTestCase.
 */
final class CupViewsTest extends CupWpTestCase {

	private function my_area( string $user ): string {
		wp_set_current_user( $this->users[ $user ] );
		$this->db->query( "UPDATE {$this->db->table( 'person' )} SET consent_at = '2026-01-01 00:00:00'" );
		return do_blocks( '<!-- wp:ttleague/my-area /-->' );
	}

	private function admin( int $club_id = 0 ): string {
		wp_set_current_user( $this->users['tk'] );
		return AdminPage::html( $this->cup, $club_id, Results::current_actor(), null );
	}

	public function test_a_tk_chef_sees_the_cup_section_with_the_form(): void {
		$this->teams( 1 );
		$club = $this->club['seefeld'];

		$html = $this->my_area( 'chef_seefeld' );

		self::assertStringContainsString( '<section class="ttl-section ttl-club ttl-cup-entries" id="ttl-cup-' . $club . '"', $html );
		self::assertStringContainsString( 'Cup: TTC Seefeld', $html );
		self::assertStringContainsString( 'Mannschaftscup A 2026/27', $html );
		self::assertStringContainsString( 'Summe der Klassierungen höchstens 18', $html );
		self::assertStringContainsString( 'Meldeschluss ', $html );
		self::assertStringContainsString( '<td class="ttl-cell--primary">Seefeld 1</td>', $html );
		self::assertStringContainsString( 'name="action" value="' . Forms::REGISTER . '"', $html );
		self::assertStringContainsString( 'name="action" value="' . Forms::WITHDRAW . '"', $html );
		// Gemeldete Spieler sind gesperrt, freie wählbar.
		self::assertStringContainsString( '<option value="' . $this->player['seefeld'][1] . '" disabled>Seefeld1 Cupspieler (B12), schon in Seefeld 1</option>', $html );
		self::assertStringContainsString( '<option value="' . $this->player['seefeld'][3] . '">Seefeld3 Cupspieler (D5)</option>', $html );
		self::assertStringNotContainsString( 'Oerlikon', $html, 'nur der eigene Club' );
	}

	public function test_the_section_is_only_for_tk_chefs_and_closes_after_the_deadline(): void {
		self::assertStringNotContainsString( 'ttl-cup-', $this->my_area( 'nobody' ) );

		$this->teams( 1 );
		$this->db->update( 'competition', array( 'format_json' => '{"registration_deadline":"' . $this->date( -1 ) . '"}' ), array( 'id' => $this->cup ) );
		$closed = $this->my_area( 'chef_seefeld' );

		self::assertStringContainsString( 'Der Meldeschluss ist vorbei. Wende dich an die TK.', $closed );
		self::assertStringNotContainsString( 'value="' . Forms::REGISTER . '"', $closed );
		self::assertStringNotContainsString( 'value="' . Forms::WITHDRAW . '"', $closed );
		self::assertStringContainsString( 'Seefeld 1', $closed, 'die eigene Meldung bleibt sichtbar' );
	}

	public function test_names_are_escaped_in_my_area_and_backend(): void {
		$ids = $this->teams( 2 );
		$this->db->update( 'cup_entry', array( 'name' => '<script>alert(1)</script>' ), array( 'id' => $ids[0] ) );
		$this->db->update( 'club', array( 'name' => 'TTC <b>Seefeld</b>' ), array( 'id' => $this->club['seefeld'] ) );

		foreach ( array( $this->my_area( 'chef_seefeld' ), $this->admin() ) as $html ) {
			self::assertStringNotContainsString( '<script>alert(1)</script>', $html );
			self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
			self::assertStringNotContainsString( '<b>Seefeld</b>', $html );
		}
	}

	public function test_the_backend_page_is_listed_and_needs_the_tk(): void {
		self::assertContains( Cup::PAGE, array_column( Tk::pages(), 'slug' ) );
		self::assertSame( 'http://example.org/wp-admin/admin.php?page=ttleague-tk-cup&competition=' . $this->cup, AdminPage::url( $this->cup ) );

		wp_set_current_user( $this->users['chef_seefeld'] );
		$died = $this->die_of( array( AdminPage::class, 'render' ) );
		self::assertStringContainsString( 'Berechtigung', $died->getMessage() );

		wp_set_current_user( $this->users['tk'] );
		ob_start();
		AdminPage::render();
		self::assertStringContainsString( 'Spielbetrieb: Cup', (string) ob_get_clean() );
	}

	public function test_the_backend_shows_entries_the_quorum_hint_and_the_draw(): void {
		$ids  = $this->teams( 5 );
		$html = $this->admin( $this->club['oerlikon'] );

		self::assertStringContainsString( 'Der Cup findet ab 8 Meldungen statt (Art. 18.2). Bisher sind es 5.', $html );
		self::assertStringContainsString( 'Meldungen <span class="count">(5)</span>', $html );
		self::assertStringContainsString( 'name="points_limit" min="2" max="44" class="small-text" value="18"', $html );
		self::assertStringContainsString( 'name="seed[' . $ids[0] . ']"', $html );
		self::assertStringContainsString( 'Seefeld1 Cupspieler (B12), Seefeld2 Cupspieler (C6)', $html );
		self::assertStringContainsString( 'Auslosung vorschlagen', $html );
		self::assertStringContainsString( 'list=' . Export::NAME, $html, 'Link auf den CSV-Export' );
		// Meldung durch die TK für den gewählten Club.
		self::assertStringContainsString( '<option value="' . $this->player['oerlikon'][3] . '">Oerlikon3 Cupspieler (D5)</option>', $html );
		self::assertStringContainsString( 'name="back" value="' . Forms::BACK_ADMIN . '"', $html );
		self::assertStringNotContainsString( 'Paarungen speichern', $html );

		Cup::draw()->propose( $this->cup, Results::current_actor(), 9 );
		$proposed = $this->admin();
		self::assertStringContainsString( 'Vorschlag mit Startwert 9.', $proposed );
		self::assertSame( 8, substr_count( $proposed, '<select name="positions[]"' ), 'vier Paarungen mit je zwei Plätzen' );
		self::assertStringContainsString( 'Neu auslosen', $proposed );
		self::assertStringContainsString( 'Auslosung freigeben', $proposed );
		self::assertStringContainsString( '>Freilos</option>', $proposed );

		// Ändern sich die Meldungen, verlangt die Seite eine neue Auslosung.
		Cup::entries()->withdraw( $ids[4], Results::current_actor() );
		$stale = $this->admin();
		self::assertStringContainsString( 'Die Meldungen haben sich seit dem Vorschlag geändert. Bitte neu auslosen.', $stale );
		self::assertStringNotContainsString( 'Auslosung freigeben', $stale );
	}

	public function test_the_backend_shows_the_bracket_with_its_actions_after_the_release(): void {
		$this->released();
		$this->play( $this->tie( 1, 1 )['fixture']['id'], 'home' );
		$this->play( $this->tie( 1, 2 )['fixture']['id'], 'home' );

		$html = $this->admin();

		self::assertStringContainsString( '<h2>Tableau</h2>', $html );
		self::assertStringContainsString( '<h3>Viertelfinal</h3>', $html );
		self::assertStringContainsString( '<h3>Halbfinal</h3>', $html );
		self::assertStringContainsString( '<h3>Final</h3>', $html );
		self::assertStringNotContainsString( 'Auslosung vorschlagen', $html );
		self::assertStringNotContainsString( 'name="seed[', $html, 'die Setzung ist fest' );
		self::assertStringContainsString( 'Seefeld 2 <abbr title="Heimrecht">H</abbr> <span aria-hidden="true">–</span> Wollishofen 2', $html );
		self::assertStringContainsString( 'bestätigt 3:0', $html );
		self::assertStringContainsString( 'value="' . Forms::SWAP_HOME . '"', $html );
		self::assertStringContainsString( 'value="' . Forms::SCHEDULE . '"', $html, 'der Halbfinal lässt sich ansetzen' );
		self::assertSame( 3, substr_count( $html, 'value="' . Forms::ROUND_DEADLINE . '"' ) );
		self::assertSame( 1, substr_count( $html, 'value="' . Forms::SCHEDULE . '"' ), 'nur die Paarung mit beiden Mannschaften' );
		self::assertSame( 2, substr_count( $html, 'value="' . Forms::SWAP_HOME . '"' ), 'nur offene Begegnungen vor dem Halbfinal' );
		self::assertStringContainsString( 'ohne Termin', $html );
		self::assertStringContainsString( wp_date( 'j.n.Y', time() + 20 * DAY_IN_SECONDS ), $html, 'Frist der ersten Runde' );
	}

	public function test_the_overview_of_the_tk_lists_overdue_fixtures_and_bracket_conflicts(): void {
		$ids = $this->released();
		wp_set_current_user( $this->users['tk'] );

		$sections = array_column( DashboardPage::module_sections(), null, 'id' );
		self::assertSame( 0, $sections[ AdminPage::SECTION_OVERDUE ]['count'] );
		self::assertSame( 'Cup: Frist abgelaufen', $sections[ AdminPage::SECTION_OVERDUE ]['title'] );
		self::assertStringContainsString( 'Keine Begegnung mit abgelaufener Frist.', $sections[ AdminPage::SECTION_OVERDUE ]['html'] );
		self::assertArrayNotHasKey( AdminPage::SECTION_CONFLICTS, $sections );

		// Frist der ersten Paarung ist gestern abgelaufen; die zweite hat ein Resultat.
		$this->db->query( "UPDATE {$this->db->table( 'cup_tie' )} SET deadline = '" . $this->date( -1 ) . "' WHERE competition_id = " . $this->cup . ' AND round = 1 AND slot IN (1, 2)' );
		$this->play( $this->tie( 1, 2 )['fixture']['id'], 'home' );
		wp_set_current_user( $this->users['tk'] );
		$section = array_column( DashboardPage::module_sections(), null, 'id' )[ AdminPage::SECTION_OVERDUE ];
		self::assertSame( 1, $section['count'] );
		self::assertStringContainsString( '<strong>Seefeld 1 – Wollishofen 1</strong>', $section['html'] );
		self::assertStringContainsString( 'Mannschaftscup A, Viertelfinal', $section['html'] );
		self::assertStringContainsString( 'begegnung=' . $this->tie( 1, 1 )['fixture']['id'], $section['html'], 'Link auf die Erfassungsseite für das Forfait' );
		self::assertStringContainsString( 'name="back" value="' . Forms::BACK_TK . '"', $section['html'] );
		self::assertStringNotContainsString( 'Oerlikon 1', $section['html'] );

		// Korrektur mit Siegerwechsel, nachdem der Halbfinal schon erfasst ist: Hinweis an die TK.
		$this->play( $this->tie( 1, 1 )['fixture']['id'], 'home' );
		$semi = $this->tie( 2, 1 )['fixture']['id'];
		$this->play( $semi, 'home' );
		$this->db->update(
			'fixture',
			array(
				'home_score' => 0,
				'away_score' => 3,
			),
			array( 'id' => $this->tie( 1, 1 )['fixture']['id'] )
		);
		Cup::on_result( $this->tie( 1, 1 )['fixture']['id'], $this->users['tk'] );
		self::assertSame( $ids[0], $this->tie( 2, 1 )['home'], 'das Tableau bleibt stehen' );

		wp_set_current_user( $this->users['tk'] );
		$conflicts = array_column( DashboardPage::module_sections(), null, 'id' )[ AdminPage::SECTION_CONFLICTS ];
		self::assertSame( array( 'Cup: Tableau prüfen', 1 ), array( $conflicts['title'], $conflicts['count'] ) );
		self::assertStringContainsString( 'Mannschaftscup A, Halbfinal, Paarung 1: Im Tableau steht Seefeld 1, nach dem Resultat der Vorrunde wäre es Wollishofen 1.', $conflicts['html'] );
		self::assertStringContainsString( 'begegnung=' . $semi, $conflicts['html'] );
		self::assertStringContainsString( 'Im Tableau steht Seefeld 1', $this->admin(), 'auch auf der Seite Cup' );

		// Ohne ttl_tk gibt es die Listen nicht.
		wp_set_current_user( $this->users['chef_seefeld'] );
		self::assertSame( array(), AdminPage::overview_sections( array() ) );
	}

	public function test_the_public_page_shows_the_bracket_as_soon_as_the_draw_is_released(): void {
		$this->teams( 8 );
		wp_set_current_user( 0 );
		$before = do_blocks( '<!-- wp:ttleague/cup /-->' );
		self::assertStringContainsString( 'Meldeliste', $before );
		self::assertStringNotContainsString( 'ttl-bracket', $before );

		wp_set_current_user( $this->users['tk'] );
		$actor = Results::current_actor();
		Cup::draw()->propose( $this->cup, $actor, 1 );
		Cup::draw()->release( $this->cup, $actor, $this->date( 20 ) );
		wp_set_current_user( 0 );
		$after = do_blocks( '<!-- wp:ttleague/cup /-->' );

		self::assertStringContainsString( 'ttl-bracket__tie', $after, 'die Freigabe erneuert die Datenversion, der Cache zeigt das Tableau' );
		self::assertStringContainsString( 'Viertelfinal', $after );
		self::assertStringNotContainsString( 'admin-post.php', $after, 'keine Formulare auf der öffentlichen Seite' );
	}

	public function test_notification_types_and_the_csv_export_are_registered(): void {
		$labels = EventTypes::labels();
		self::assertSame( 'Cup: Auslosung, neue Runde, angesetzte Begegnung', $labels[ EventTypes::CUP_ROUND ] ?? null );
		self::assertSame( 'Cup: Erinnerung vor Ablauf der Rundenfrist', $labels[ EventTypes::CUP_DEADLINE ] ?? null );

		$ids = $this->teams( 2 );
		$this->db->update( 'cup_entry', array( 'name' => '=HYPERLINK("x")' ), array( 'id' => $ids[1] ) );
		self::assertArrayHasKey( Export::NAME, Exports::all() );
		$csv   = (string) Exports::csv( Export::NAME );
		$lines = explode( "\r\n", trim( $csv ) );

		self::assertStringEndsWith( 'Saison;Konkurrenz;Mannschaft;Club;Spieler 1;Klassierung 1;Spieler 2;Klassierung 2;Punktesumme;Setzung;Status;Gemeldet am (UTC)', $lines[0] );
		self::assertCount( 3, $lines );
		self::assertStringStartsWith( '2026/27;Mannschaftscup A;Seefeld 1;TTC Seefeld;Seefeld1 Cupspieler;B12;Seefeld2 Cupspieler;C6;18;;gemeldet;', $lines[1] );
		self::assertStringContainsString( 'Wollishofen1 Cupspieler;B11', $lines[2] );
		self::assertStringNotContainsString( ';=HYPERLINK', $lines[2], 'Formeln sind entschärft' );
		self::assertSame( array(), Export::rows( array( 'saison' => '1999-00' ) ) );
		self::assertSame( array(), Export::rows( array( 'konkurrenz' => '99999' ) ) );
	}
}
