<?php
/**
 * Öffentliche Cup-Seite: Tableau nach der Auslosung, unveränderte Ansicht ohne cup_tie.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Cup;

use TTLeague\Frontend\Support\Urls;
use TTLeague\Frontend\Views\CupView;
use TTLeague\ReadModel\Cache\NullCache;
use TTLeague\ReadModel\ReadModel;
use TTLeague\Tests\Integration\Frontend\Seeder;

/**
 * Daten siehe CupTestCase; für die importierte Saison das Szenario des Seeders (Cup A mit zwei
 * Meldungen und einer gespielten Begegnung, ohne cup_tie).
 */
final class CupTableauViewTest extends CupTestCase {

	/**
	 * @param array<string,mixed> $attrs Attribute der Ansicht.
	 */
	private function render( array $attrs = array() ): string {
		return ( new CupView( new ReadModel( $this->db, new NullCache() ), new Urls( 'https://liga.example/' ) ) )->render( $attrs );
	}

	/**
	 * Überschriften der dritten Ebene in ihrer Reihenfolge.
	 *
	 * @return list<string>
	 */
	private static function subtitles( string $html ): array {
		preg_match_all( '~<h3 class="ttl-subtitle">([^<]*)</h3>~', $html, $matches );
		return $matches[1];
	}

	public function test_a_season_without_cup_tie_looks_as_before(): void {
		$this->truncate_all();
		$ids = ( new Seeder( $this->db ) )->scenario();

		$html = $this->render();

		self::assertSame( array( 'Meldeliste', 'Resultate' ), self::subtitles( $html ), 'Meldeliste und Resultate, kein Tableau' );
		self::assertStringNotContainsString( 'ttl-bracket', $html );
		self::assertStringNotContainsString( 'Tableau', $html );
		self::assertStringNotContainsString( 'zurückgezogen', $html );
		self::assertStringContainsString( '<caption class="screen-reader-text">Resultate Mannschaftscup A</caption>', $html );
		self::assertStringContainsString( 'Seefeld 1 <span aria-hidden="true">–</span><span class="screen-reader-text">gegen</span> Bergwiese 1', $html );
		self::assertStringContainsString( '<a href="https://liga.example/begegnung/' . $ids['cup_fixture'] . '/" aria-label="Matchreport 3:1"><strong>3:1</strong></a>', $html );
		self::assertSame( 1, substr_count( $html, '<section' ), 'ein Abschnitt je Kategorie, keine Runden' );

		$model = ( new ReadModel( $this->db, new NullCache() ) )->cup->season( $ids['season_new'] );
		self::assertSame( array( array(), 0 ), array( $model[0]['ties'], $model[0]['rounds'] ) );
		self::assertSame( array( 'active', 'active' ), array_column( $model[0]['entries'], 'status' ) );
	}

	public function test_entries_without_a_draw_show_the_list_only(): void {
		$this->teams( 3 );

		$html = $this->render( array( 'category' => 'a' ) );

		self::assertSame( array( 'Meldeliste', 'Resultate' ), self::subtitles( $html ) );
		self::assertStringContainsString( 'Noch keine Resultate.', $html );
		self::assertStringNotContainsString( 'ttl-bracket', $html );
		self::assertStringContainsString( 'Seefeld1 Cupspieler', $html );
	}

	public function test_the_bracket_shows_rounds_deadlines_results_and_the_match_report(): void {
		$ids = $this->eight();
		$this->play( $this->fixture_of( 1, 1 ), 'away' );
		$this->play( $this->fixture_of( 1, 2 ), 'home' );
		$f13 = $this->fixture_of( 1, 3 );
		$this->results->submit( $f13, $this->payload( $f13, 'home' ), $this->tk );
		$this->bracket->set_tie_deadline( $this->tie( 1, 4 )['id'], '2026-12-07', $this->tk );
		$venue = $this->seeder->venue( $this->club['affoltern'], 'Sporthalle <Affoltern>' );
		$this->bracket->schedule(
			$this->tie( 2, 1 )['id'],
			$this->tk,
			array(
				'scheduled_at' => '2026-12-12 14:00',
				'venue_id'     => $venue,
			)
		);
		$this->entries->withdraw( $ids['enge'], $this->tk );

		$html = $this->render( array( 'category' => 'a' ) );

		self::assertSame( array( 'Tableau', 'Meldeliste' ), self::subtitles( $html ), 'das Tableau ersetzt die Liste der Resultate' );
		self::assertStringContainsString( '<div class="ttl-bracket" role="group" aria-label="Tableau Mannschaftscup A">', $html );
		preg_match_all( '~<h4 class="ttl-bracket__title" id="([^"]+)">([^<]+)</h4>~', $html, $titles );
		self::assertSame( array( 'Viertelfinal', 'Halbfinal', 'Final' ), $titles[2], 'Runden als Spalten in ihrer Reihenfolge' );
		self::assertSame( array( 'ttl-cup-a-runde-1', 'ttl-cup-a-runde-2', 'ttl-cup-a-runde-3' ), $titles[1] );
		self::assertSame( 7, substr_count( $html, '<li class="ttl-bracket__tie">' ) );

		// Gespielte Begegnung: Resultat je Mannschaft, Sieger hervorgehoben, Link zum Matchreport.
		self::assertStringContainsString(
			'<li class="ttl-bracket__tie"><div class="ttl-bracket__team"><span class="ttl-bracket__name">Seefeld 1</span><span class="ttl-bracket__score">0</span></div>'
			. '<div class="ttl-bracket__team ttl-bracket__team--winner"><span class="ttl-bracket__name"><strong>Wollishofen 1</strong></span><span class="ttl-bracket__score">3</span></div>'
			. '<p class="ttl-bracket__meta"><a href="https://liga.example/begegnung/' . $this->fixture_of( 1, 1 ) . '/" aria-label="Matchreport 0:3">Matchreport</a></p></li>',
			$html
		);
		// Provisorisch: Resultat mit Vermerk, noch kein Sieger.
		self::assertStringContainsString( '<span class="ttl-bracket__name">Bergwiese 1</span><span class="ttl-bracket__score">3</span>', $html );
		self::assertStringContainsString( 'aria-label="Matchreport 3:0">Matchreport</a> <span class="ttl-badge ttl-badge--muted">provisorisch</span>', $html );

		// Fristen: die verlängerte Paarung weicht ab, darum steht die Frist bei jeder offenen Paarung.
		self::assertStringNotContainsString( 'ttl-bracket__deadline', $html );
		self::assertStringContainsString( 'forfait', strtolower( $html ), 'Rückzug: forfait gewertet' );
		self::assertStringContainsString( 'Enge 1 <span class="ttl-badge ttl-badge--muted">zurückgezogen</span>', $html );

		// Halbfinal: angesetzt mit Ort, ohne Heimrecht; die zweite Paarung wartet auf Wiedikon und einen Gegner.
		self::assertStringContainsString(
			'<div class="ttl-bracket__team"><span class="ttl-bracket__name">Wollishofen 1</span></div><div class="ttl-bracket__team"><span class="ttl-bracket__name">Oerlikon 1</span></div>'
			. '<p class="ttl-bracket__meta"><time datetime="2026-12-12T14:00">Sa, 12.12.2026, 14:00</time>, Sporthalle &lt;Affoltern&gt;</p>',
			$html
		);
		self::assertStringContainsString( '<span class="ttl-bracket__name">offen</span>', $html );
		self::assertStringContainsString( '<span class="ttl-bracket__name">Wiedikon 1</span>', $html );
	}

	public function test_open_fixtures_show_home_right_the_common_deadline_and_byes(): void {
		$ids = $this->teams( 5 );
		$this->release( array( 'seefeld', null, 'altstetten', 'bergwiese', 'wollishofen', null, 'oerlikon', null ), $ids );

		$html = $this->render( array( 'category' => 'a' ) );

		self::assertStringContainsString( '<p class="ttl-bracket__deadline">zu spielen bis 30.11.2026</p>', $html, 'gemeinsame Frist der Runde' );
		self::assertSame( 1, substr_count( $html, 'zu spielen bis' ), 'einmal bei der Runde statt bei jeder Paarung' );
		self::assertSame( 3, substr_count( $html, '<div class="ttl-bracket__team ttl-bracket__team--empty"><span class="ttl-bracket__name">Freilos</span></div>' ) );
		self::assertStringContainsString(
			'<div class="ttl-bracket__team"><span class="ttl-bracket__name">Altstetten 1</span><abbr class="ttl-bracket__home" title="Heimrecht">H</abbr></div>'
			. '<div class="ttl-bracket__team"><span class="ttl-bracket__name">Bergwiese 1</span></div>'
			. '<p class="ttl-bracket__meta"><span class="ttl-muted">Termin offen</span></p>',
			$html
		);
		// Die beiden Freilose der unteren Hälfte stehen schon im Halbfinal (ohne Heimrecht).
		self::assertStringContainsString(
			'<div class="ttl-bracket__team"><span class="ttl-bracket__name">Wollishofen 1</span></div><div class="ttl-bracket__team"><span class="ttl-bracket__name">Oerlikon 1</span></div>',
			$html
		);
		self::assertStringContainsString( '<span class="ttl-bracket__name">offen</span>', $html );

		// Tauscht die TK das Heimrecht, wandert die Markierung; die Reihenfolge im Tableau bleibt.
		$this->bracket->swap_home( $this->tie( 1, 2 )['id'], $this->tk );
		self::assertStringContainsString(
			'<span class="ttl-bracket__name">Altstetten 1</span></div><div class="ttl-bracket__team"><span class="ttl-bracket__name">Bergwiese 1</span><abbr class="ttl-bracket__home" title="Heimrecht">H</abbr></div>',
			$this->render( array( 'category' => 'a' ) )
		);
	}

	public function test_the_read_model_lists_ties_by_round_and_slot(): void {
		$this->eight();
		$this->play( $this->fixture_of( 1, 2 ), 'home' );

		$cup = ( new ReadModel( $this->db, new NullCache() ) )->cup->season( $this->season );

		self::assertSame( array( 'Mannschaftscup A', 'Mannschaftscup B' ), array_column( $cup, 'name' ) );
		self::assertSame( array( 3, 0 ), array_column( $cup, 'rounds' ) );
		self::assertSame( array( '1/1', '1/2', '1/3', '1/4', '2/1', '2/2', '3/1' ), array_map( static fn( array $tie ): string => $tie['round'] . '/' . $tie['slot'], $cup[0]['ties'] ) );
		self::assertSame( array( 'Oerlikon 1', 'Altstetten 1', '2026-11-30' ), array( $cup[0]['ties'][1]['home']['name'], $cup[0]['ties'][1]['away']['name'], $cup[0]['ties'][1]['deadline'] ) );
		self::assertSame( array( 'confirmed', 3, 0 ), array( $cup[0]['ties'][1]['fixture']['status'], $cup[0]['ties'][1]['fixture']['home_score'], $cup[0]['ties'][1]['fixture']['away_score'] ) );
		self::assertSame( array( null, 'Oerlikon 1', null ), array( $cup[0]['ties'][4]['home'], $cup[0]['ties'][4]['away']['name'], $cup[0]['ties'][4]['fixture'] ) );
	}
}
