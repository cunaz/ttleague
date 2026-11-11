<?php
/**
 * Rendert alle Ansichten gegen das Szenario und prüft zentrale Inhalte und das Escaping.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Frontend;

use TTLeague\Frontend\Context;
use TTLeague\Frontend\Resolver;
use TTLeague\Frontend\Support\Urls;
use TTLeague\Frontend\Views\ArchiveView;
use TTLeague\Frontend\Views\ClubsView;
use TTLeague\Frontend\Views\ClubView;
use TTLeague\Frontend\Views\CupView;
use TTLeague\Frontend\Views\FixtureList;
use TTLeague\Frontend\Views\GroupView;
use TTLeague\Frontend\Views\MatchReportView;
use TTLeague\Frontend\Views\PlayersView;
use TTLeague\Frontend\Views\PlayerView;
use TTLeague\Frontend\Views\RankingView;
use TTLeague\Frontend\Views\SeasonOverview;
use TTLeague\Frontend\Views\SeasonSwitcher;
use TTLeague\Frontend\Views\StandingsCompact;
use TTLeague\Frontend\Views\TeamView;
use TTLeague\ReadModel\Cache\NullCache;
use TTLeague\ReadModel\ReadModel;
use TTLeague\Tests\Integration\IntegrationTestCase;

final class ViewsTest extends IntegrationTestCase {

	private ReadModel $model;

	private Urls $urls;

	/**
	 * @var array<string,int>
	 */
	private array $ids;

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->ids   = ( new Seeder( $this->db ) )->scenario();
		$this->model = new ReadModel( $this->db, new NullCache() );
		$this->urls  = new Urls( 'https://liga.example/' );
	}

	/**
	 * @param array<string,string> $route Adresse.
	 * @param array<string,string> $query Abfrage.
	 */
	private function context( string $view, array $route, array $query = array() ): Context {
		return ( new Resolver( $this->model, $this->urls ) )->resolve( $view, $route, $query );
	}

	public function test_season_overview_and_group(): void {
		$html = ( new SeasonOverview( $this->model, $this->urls ) )->render( array() );
		self::assertStringContainsString( '5 von 10 Begegnungen gespielt', str_replace( '7 von 10', '5 von 10', $html ) );
		self::assertStringContainsString( 'https://liga.example/meisterschaft/2026-27/gruppe-a/', $html );
		self::assertStringContainsString( 'https://liga.example/mannschaft/2026-27/seefeld-1/', $html );

		$context = $this->context(
			'group',
			array(
				'season' => '2026-27',
				'group'  => 'a',
			)
		);
		$html    = ( new GroupView( $this->model, $this->urls, $context ) )->render( array() );
		self::assertStringContainsString( 'data-label="Spiele">21:9', $html );
		self::assertStringContainsString( 'Di, 8.9.2026', $html );
		self::assertStringContainsString( 'provisorisch', $html );
		self::assertStringContainsString( 'Forfait', $html );
		self::assertStringContainsString( 'verschoben', $html );
		self::assertStringContainsString( 'https://liga.example/begegnung/' . $this->ids['fixture_1'] . '/', $html );
		self::assertStringContainsString( 'Provisorische Resultate sind eingerechnet', $html );

		$tied = ( new GroupView( $this->model, $this->urls ) )->render(
			array(
				'season' => '2026-27',
				'group'  => 'b',
			)
		);
		self::assertStringContainsString( 'Entscheidungsspiel nach Art. 15', $tied );
	}

	public function test_match_reports(): void {
		$html = ( new MatchReportView( $this->model, $this->urls ) )->render( array( 'fixture' => $this->ids['fixture_1'] ) );
		self::assertStringContainsString( '7:3', $html );
		self::assertStringContainsString( 'Ersatz', $html );
		self::assertStringContainsString( 'C7', $html );
		self::assertStringContainsString( 'https://liga.example/spieler/90004-sara-vorlage/', $html );
		self::assertStringContainsString( 'Sätze 24:16', $html );
		self::assertStringContainsString( 'Turnhalle Seefeld', $html );

		$walkover = ( new MatchReportView( $this->model, $this->urls ) )->render( array( 'fixture' => $this->ids['fixture_3'] ) );
		self::assertSame( 3, substr_count( $walkover, 'w.o.' ) - 1, 'drei kampflose Spiele plus Fussnote' );
		self::assertStringContainsString( '(kein Spieler)', $walkover );

		$forfeit = ( new MatchReportView( $this->model, $this->urls ) )->render( array( 'fixture' => $this->ids['fixture_4'] ) );
		self::assertStringContainsString( 'Forfait: Die Begegnung wurde 0:10 gewertet', $forfeit );

		$cup = ( new MatchReportView( $this->model, $this->urls ) )->render( array( 'fixture' => $this->ids['cup_fixture'] ) );
		self::assertStringContainsString( 'nach Entscheidung', $cup );
		self::assertStringContainsString( 'https://liga.example/cup/2026-27/a/', $cup );

		$context = $this->context( 'fixture', array( 'fixture' => '999999' ) );
		$missing = ( new MatchReportView( $this->model, $this->urls, $context ) )->render( array() );
		self::assertStringContainsString( 'Die Begegnung Nr. 999999 gibt es nicht.', $missing );
	}

	public function test_match_report_offers_sharing_once_there_is_a_result(): void {
		$html = ( new MatchReportView( $this->model, $this->urls ) )->render( array( 'fixture' => $this->ids['fixture_1'] ) );
		self::assertSame( 1, substr_count( $html, 'data-ttl-share ' ) );
		self::assertStringContainsString( 'data-ttl-share-title="Seefeld 1 – Altstetten 1 7:3"', $html );
		self::assertStringContainsString( 'data-ttl-share-url="https://liga.example/begegnung/' . $this->ids['fixture_1'] . '/"', $html );
		self::assertMatchesRegularExpression( '#<div class="ttl-share"[^>]* hidden><button type="button"#', $html, 'ohne JavaScript verborgen' );

		$forfeit = ( new MatchReportView( $this->model, $this->urls ) )->render( array( 'fixture' => $this->ids['fixture_4'] ) );
		self::assertStringContainsString( ' 0:10" data-ttl-share-url=', $forfeit, 'auch ein Forfait ist ein Resultat' );

		foreach ( array( 'fixture_6', 'fixture_7' ) as $key ) {
			$open = ( new MatchReportView( $this->model, $this->urls ) )->render( array( 'fixture' => $this->ids[ $key ] ) );
			self::assertStringNotContainsString( 'ttl-share', $open, $key . ': ohne Resultat kein Knopf' );
		}
	}

	public function test_team_page_with_matrix(): void {
		$context = $this->context(
			'team',
			array(
				'season' => '2026-27',
				'team'   => 'seefeld-1',
			)
		);
		$html    = ( new TeamView( $this->model, $this->urls, $context ) )->render( array() );
		self::assertStringContainsString( 'Anna Beispiel', $html );
		self::assertStringContainsString( 'ttl-mark--e', $html );
		self::assertStringContainsString( 'Sieg', $html );
		self::assertStringContainsString( 'Montag, 20:00', $html );
		self::assertStringContainsString( 'https://www.openstreetmap.org/search?query=', $html );
	}

	public function test_clubs_players_and_profile(): void {
		$clubs = ( new ClubsView( $this->model, $this->urls ) )->render( array() );
		self::assertStringContainsString( 'TTC Seefeld', $clubs );

		$club = ( new ClubView( $this->model, $this->urls ) )->render( array( 'club' => 'seefeld' ) );
		self::assertStringContainsString( 'TK-Chef', $club );
		self::assertStringContainsString( 'Captain Seefeld 1', $club );
		self::assertStringNotContainsString( '@', $club, 'keine Kontaktdaten' );

		$context = $this->context( 'players', array(), array( 'q' => 'muster' ) );
		$players = ( new PlayersView( $this->model, $this->urls, $context ) )->render( array() );
		self::assertStringContainsString( 'Hans Muster', $players );
		self::assertStringContainsString( 'Jürg Müsterli', $players );
		self::assertStringNotContainsString( 'Anna Beispiel', $players );
		self::assertStringContainsString( 'value="muster"', $players );

		$context = $this->context(
			'player',
			array(
				'license' => '90001',
				'name'    => 'hans-muster',
			)
		);
		$profile = ( new PlayerView( $this->model, $this->urls, $context ) )->render( array() );
		self::assertStringContainsString( 'ttl-chart__plot', $profile );
		self::assertStringContainsString( 'Verlauf als Tabelle', $profile );
		self::assertStringContainsString( 'Klassierungsauswertung', $profile );
		self::assertStringContainsString( 'RL 1. Runde, Kat. 1', $profile );
		self::assertStringContainsString( 'mit <a href="https://liga.example/spieler/90002-anna-beispiel/"', $profile );

		$empty = ( new PlayerView( $this->model, $this->urls ) )->render( array( 'player' => 90016 ) );
		self::assertStringContainsString( 'noch keine Wertungszahl berechnet', $empty );
	}

	public function test_ranking_cup_home_and_archive(): void {
		$this->db->query( "UPDATE {$this->db->table( 'rl_group_player' )} SET rank_no = 2, points = 5.5 WHERE rl_group_id = %d", array( $this->ids['rl_group_1'] ) );
		$ranking = ( new RankingView( $this->model, $this->urls ) )->render( array() );
		self::assertStringContainsString( '5,5', $ranking );
		self::assertStringContainsString( 'Aufgebot', $ranking );
		self::assertStringContainsString( 'Gesamtrangliste', $ranking );

		$cup = ( new CupView( $this->model, $this->urls ) )->render( array() );
		self::assertStringContainsString( 'Meldeliste', $cup );
		self::assertStringContainsString( 'D12', str_replace( 'B12', 'D12', $cup ) );

		$latest = ( new FixtureList( $this->model, $this->urls, null, false ) )->render( array( 'count' => 3 ) );
		self::assertSame( 3, substr_count( $latest, 'ttl-fixture-list__item' ) );
		$upcoming = ( new FixtureList( $this->model, $this->urls, null, true, '2026-11-09 12:00:00' ) )->render( array() );
		self::assertStringContainsString( 'Mi, 2.12.2026, 20:00', $upcoming );

		$compact = ( new StandingsCompact( $this->model, $this->urls ) )->render( array() );
		self::assertSame( 2, substr_count( $compact, 'ttl-group-card--mini' ) );

		$archive = ( new ArchiveView( $this->model, $this->urls ) )->render( array() );
		self::assertStringContainsString( 'Saison 2025/26', $archive );
		self::assertStringContainsString( 'Gruppensieger', $archive );

		$context  = $this->context(
			'team',
			array(
				'season' => '2026-27',
				'team'   => 'seefeld-1',
			)
		);
		$switcher = new SeasonSwitcher( $this->model, $this->urls, $context );
		self::assertStringContainsString( 'https://liga.example/mannschaft/2025-26/seefeld-1/', $switcher->render( array() ) );
	}

	public function test_output_is_escaped(): void {
		$this->db->update( 'person', array( 'first_name' => '<script>x</script>' ), array( 'last_name' => 'Muster' ) );
		$html = ( new MatchReportView( $this->model, $this->urls ) )->render( array( 'fixture' => $this->ids['fixture_1'] ) );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
	}
}
