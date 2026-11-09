<?php
/**
 * Integrationstests der Lesemodelle gegen das Szenario aus dem Seeder.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Frontend;

use TTLeague\ReadModel\Cache\NullCache;
use TTLeague\ReadModel\Players;
use TTLeague\ReadModel\PlayerProfile;
use TTLeague\ReadModel\ReadModel;
use TTLeague\ReadModel\Teams;
use TTLeague\Tests\Integration\IntegrationTestCase;

final class ReadModelTest extends IntegrationTestCase {

	private ReadModel $model;

	/**
	 * @var array<string,int>
	 */
	private array $ids;

	protected function setUp(): void {
		parent::setUp();
		$this->ids   = ( new Seeder( $this->db ) )->scenario();
		$this->model = new ReadModel( $this->db, new NullCache() );
	}

	public function test_seasons_and_active_season(): void {
		$all = $this->model->seasons->all();
		self::assertSame( array( '2026-27', '2025-26' ), array_column( $all, 'slug' ) );
		self::assertSame( '2026-27', $this->model->seasons->active()['slug'] );
		self::assertSame( '2025/26', $this->model->seasons->by_slug( '2025-26' )['name'] );
		self::assertNull( $this->model->seasons->by_slug( '1999-00' ) );
		self::assertSame( 3, $this->model->seasons->rules( $all[0] )->substitute_max );
	}

	public function test_disputed_result_also_shows_the_provisional_footnote(): void {
		$this->db->update( 'fixture', array( 'status' => 'disputed' ), array( 'status' => 'provisional' ) );
		$group = $this->model->championship->group_by_slug( $this->ids['season_new'], 'a' );
		self::assertNotNull( $group );
		self::assertTrue( $group['has_provisional'], 'strittige Resultate sind ebenfalls vorläufig' );
	}

	public function test_group_standings_are_calculated_from_fixtures(): void {
		$group = $this->model->championship->group_by_slug( $this->ids['season_new'], 'a' );
		self::assertNotNull( $group );
		self::assertSame( 'Gruppe A', $group['name'] );
		self::assertSame( 5, $group['played'] );
		self::assertSame( 8, $group['total'] );
		self::assertTrue( $group['has_provisional'] );

		$teams = $this->model->championship->season( $this->ids['season_new'] )['teams'];
		$rows  = array();
		foreach ( $group['standings'] as $row ) {
			$rows[] = array(
				$row['rank'],
				$teams[ $row['team_id'] ]['name'],
				$row['played'],
				$row['wins'] . '-' . $row['draws'] . '-' . $row['losses'],
				$row['games_won'] . ':' . $row['games_lost'],
				$row['sets_won'] . ':' . $row['sets_lost'],
				$row['points'],
			);
		}
		self::assertSame(
			array(
				array( 1, 'Seefeld 1', 3, '2-0-1', '21:9', '42:38', 21 ),
				array( 2, 'Bergwiese 1', 3, '1-1-1', '15:15', '56:50', 15 ),
				array( 3, 'Altstetten 1', 2, '1-0-1', '9:11', '29:39', 9 ),
				array( 4, 'Lindenhof 1', 2, '0-1-1', '5:15', '19:19', 5 ),
			),
			$rows
		);
		self::assertFalse( $group['standings'][0]['shared'] );
		self::assertNull( $group['standings'][0]['tiebreak_note'] );
	}

	public function test_tied_teams_share_rank_with_tiebreak_note(): void {
		$group = $this->model->championship->group_by_slug( $this->ids['season_new'], 'b' );
		self::assertSame( array( 1, 1 ), array_column( $group['standings'], 'rank' ) );
		self::assertTrue( $group['standings'][0]['shared'] );
		self::assertStringContainsString( 'Art. 15', (string) $group['standings'][1]['tiebreak_note'] );
	}

	public function test_fixture_status_and_sets_in_schedule(): void {
		$fixtures = $this->model->championship->group_fixtures( $this->ids['season_new'], $this->ids['group_a'] );
		self::assertCount( 8, $fixtures );
		self::assertSame( '2026-09-08 20:00:00', $fixtures[0]['scheduled_at'] );
		self::assertSame( array( 24, 16 ), array( $fixtures[0]['sets_home'], $fixtures[0]['sets_away'] ) );
		$by_id = array_column( $fixtures, null, 'id' );
		self::assertSame( 'forfeit', $by_id[ $this->ids['fixture_4'] ]['status'] );
		self::assertTrue( $by_id[ $this->ids['fixture_4'] ]['counted'] );
		self::assertSame( 0, $by_id[ $this->ids['fixture_4'] ]['games'] );
		self::assertFalse( $by_id[ $this->ids['fixture_6'] ]['counted'] );
		self::assertSame( array( 13, 15 ), array( $by_id[ $this->ids['fixture_3'] ]['sets_home'], $by_id[ $this->ids['fixture_3'] ]['sets_away'] ) );
	}

	public function test_team_by_slug_is_per_season(): void {
		$new = $this->model->championship->team_by_slug( $this->ids['season_new'], 'seefeld-1' );
		$old = $this->model->championship->team_by_slug( $this->ids['season_old'], 'seefeld-1' );
		self::assertSame( $this->ids['team_see'], $new['id'] );
		self::assertSame( $this->ids['team_old_see'], $old['id'] );
		self::assertSame( 'seefeld', $new['club_slug'] );
		self::assertNull( $this->model->championship->team_by_slug( $this->ids['season_new'], 'gibt-es-nicht' ) );
	}

	public function test_match_report_lineup_games_and_totals(): void {
		$report = $this->model->reports->get( $this->ids['fixture_1'] );
		self::assertSame( 'MM', $report['kind'] );
		self::assertSame( 'Seefeld 1', $report['home']['name'] );
		self::assertSame( 'Turnhalle Seefeld', $report['venue']['name'] );
		self::assertSame( array( 7, 3 ), array( $report['home_score'], $report['away_score'] ) );
		self::assertSame( array( 'A', 'B', 'C' ), array_column( $report['lineup']['home'], 'slot' ) );
		self::assertTrue( $report['lineup']['home'][2]['is_substitute'] );
		self::assertSame( 7, $report['lineup']['home'][2]['classification'] );
		self::assertSame( 'Sara Vorlage', $report['lineup']['home'][2]['player']['name'] );
		self::assertCount( 10, $report['games'] );
		self::assertSame( 'double', $report['games'][6]['type'] );
		self::assertCount( 2, $report['games'][6]['a'] );
		self::assertSame( array( 'A', 'X' ), array( $report['games'][0]['slot_home'], $report['games'][0]['slot_away'] ) );
		self::assertSame( 24, $report['totals']['sets_home'] );
		self::assertSame( 16, $report['totals']['sets_away'] );
		self::assertSame( array( 7, 3 ), array( $report['totals']['wins_home'], $report['totals']['wins_away'] ) );
		self::assertNull( $report['totals']['points_home'] );
	}

	public function test_match_report_with_walkovers_and_missing_player(): void {
		$report = $this->model->reports->get( $this->ids['fixture_3'] );
		self::assertCount( 2, $report['lineup']['away'] );
		$walkovers = array_values( array_filter( $report['games'], static fn( array $g ): bool => 'walkover' === $g['status'] ) );
		self::assertCount( 3, $walkovers );
		self::assertSame( array(), $walkovers[0]['b'] );
		self::assertSame( 'a', $walkovers[0]['winner'] );
		self::assertSame( array( 13, 15 ), array( $report['totals']['sets_home'], $report['totals']['sets_away'] ) );
	}

	public function test_cup_report_marks_games_after_decision(): void {
		$report = $this->model->reports->get( $this->ids['cup_fixture'] );
		self::assertSame( 'CUP', $report['kind'] );
		self::assertSame( 'Mannschaftscup A', $report['competition']['name'] );
		self::assertSame( array( 3, 1 ), array( $report['home_score'], $report['away_score'] ) );
		self::assertCount( 5, $report['games'] );
		self::assertFalse( $report['games'][4]['counts_for_result'] );
		self::assertSame( array( 'A', 'Y' ), array( $report['games'][0]['slot_home'], $report['games'][0]['slot_away'] ) );
		self::assertNull( $this->model->reports->get( 999999 ) );
	}

	public function test_team_details_with_appearance_matrix(): void {
		$details = $this->model->teams->details( $this->ids['season_new'], $this->ids['team_see'] );
		self::assertSame( array( 'Hans Muster', 'Anna Beispiel', 'Lukas Probe' ), array_map( static fn( array $r ): string => $r['player']['name'], $details['roster'] ) );
		self::assertSame( 'Anna Beispiel', $details['captains'][0]['name'] );
		self::assertSame( 'Turnhalle Seefeld', $details['venue']['name'] );

		$matrix = $details['matrix'];
		self::assertSame( array( $this->ids['fixture_1'], $this->ids['fixture_5'] ), array_column( $matrix['fixtures'], 'id' ), 'Forfait ohne Aufstellung erscheint nicht' );
		$marks = array();
		foreach ( $matrix['rows'] as $row ) {
			$marks[ $row['player']['name'] ] = implode( '', $row['marks'] );
		}
		self::assertSame(
			array(
				'Hans Muster'   => 'SS',
				'Anna Beispiel' => 'SS',
				'Lukas Probe'   => '-S',
				'Sara Vorlage'  => 'E-',
			),
			$marks
		);
		self::assertFalse( $matrix['rows'][3]['is_roster'] );
		self::assertSame( 1, $matrix['rows'][3]['substitute'] );
	}

	public function test_matrix_is_a_pure_function(): void {
		$refs   = array(
			1 => array(
				'name'           => 'A',
				'classification' => 5,
			),
			2 => array(
				'name'           => 'B',
				'classification' => 4,
			),
		);
		$matrix = Teams::matrix(
			array( array( 'id' => 10 ), array( 'id' => 11 ), array( 'id' => 12 ) ),
			array(
				10 => array( 1 => false ),
				12 => array(
					1 => false,
					2 => true,
				),
			),
			array( 1 ),
			$refs
		);
		self::assertSame( array( 10, 12 ), array_column( $matrix['fixtures'], 'id' ) );
		self::assertSame( array( 'S', 'S' ), $matrix['rows'][0]['marks'] );
		self::assertSame( array( '-', 'E' ), $matrix['rows'][1]['marks'] );
	}

	public function test_clubs_list_and_details(): void {
		$list = $this->model->clubs->season_list( $this->ids['season_new'] );
		self::assertSame( array( 'Altstetten', 'Bergwiese', 'Lindenhof', 'Seefeld' ), array_map( static fn( array $c ): string => $c['club']['short_name'], $list ) );
		self::assertSame( array( 'Seefeld 1', 'Seefeld 2' ), array_column( $list[3]['teams'], 'name' ) );
		self::assertSame( 4, $list[3]['players'] );

		$details = $this->model->clubs->details( $this->ids['club_seefeld'], $this->ids['season_new'] );
		self::assertSame( 'Turnhalle Seefeld', $details['venues'][0]['name'] );
		self::assertSame( array( '20:00' ), $details['venues'][0]['start_times'] );
		self::assertSame( array( 'tk_chef', 'captain' ), array_column( $details['roles'], 'role' ) );
		self::assertSame( 'Hans Muster', $details['roles'][0]['name'] );
		self::assertCount( 4, $details['players'] );
		self::assertNull( $this->model->clubs->by_slug( 'unbekannt' ) );
	}

	public function test_player_list_filter_and_lookup(): void {
		$list = $this->model->players->season_list( $this->ids['season_new'] );
		self::assertCount( 16, $list );
		self::assertSame( 'Beat Ablage', $list[0]['player']['name'] );
		$found = Players::filter( $list, null, 'muest' );
		self::assertSame( array( 'Jürg Müsterli' ), array_map( static fn( array $r ): string => $r['player']['name'], $found ) );
		self::assertSame( 'juerg-muesterli', $found[0]['player']['slug'] );
		self::assertCount( 4, Players::filter( $list, $this->ids['club_seefeld'], '' ) );
		self::assertSame( 1105.0, Players::filter( $list, null, 'hans muster' )[0]['rating'] );

		$player = $this->model->players->by_license( 90001 );
		self::assertSame( $this->ids['player_muster'], $player['id'] );
		self::assertSame( 'hans-muster', $player['slug'] );
		self::assertNull( $this->model->players->by_license( 12 ) );
	}

	public function test_player_profile_statistics(): void {
		$profile = $this->model->profiles->get( $this->ids['player_muster'] );
		self::assertSame( array( '2026-27', '2025-26' ), array_column( $profile['seasons'], 'slug' ) );
		self::assertSame( array( 12, 11 ), array_column( $profile['seasons'], 'classification' ) );
		self::assertSame( 'Seefeld 1', $profile['seasons'][0]['teams'][0]['name'] );
		self::assertSame( 1105.0, $profile['rating']['rating'] );
		self::assertCount( 8, $profile['history'] );

		// Neueste zuerst: Cup (3 Spiele), Begegnung 5 (4), RL (3), Begegnung 1 (4), 25/26 (4).
		self::assertCount( 18, $profile['games'] );
		self::assertSame( array( 'CUP', 'MM', 'RL', 'MM', 'MM' ), array( $profile['games'][0]['kind'], $profile['games'][3]['kind'], $profile['games'][7]['kind'], $profile['games'][10]['kind'], $profile['games'][14]['kind'] ) );
		self::assertSame( 'Exempel', $profile['games'][7]['opponents'][0]['last_name'] );
		self::assertTrue( $profile['games'][7]['won'] );
		self::assertSame( 'Anna Beispiel', $profile['games'][1]['partner']['name'] );

		$balance = array();
		foreach ( PlayerProfile::balance( $profile['games'] ) as $row ) {
			$balance[ $row['season_slug'] . ' ' . $row['kind'] ] = $row['singles_won'] . ':' . $row['singles_lost'] . ' ' . $row['doubles_won'] . ':' . $row['doubles_lost'] . ' ' . $row['sets_won'] . ':' . $row['sets_lost'];
		}
		self::assertSame(
			array(
				'2026-27 MM'  => '4:2 1:1 20:13',
				'2026-27 CUP' => '1:1 1:0 7:5',
				'2026-27 RL'  => '2:1 0:0 7:4',
				'2025-26 MM'  => '2:1 0:1 7:9',
			),
			$balance
		);

		$analysis = array();
		foreach ( PlayerProfile::classification_analysis( $profile['games'] ) as $row ) {
			$analysis[ $row['classification'] ] = $row['won'] . ':' . $row['lost'];
		}
		self::assertSame(
			array(
				13 => '0:3',
				11 => '2:1',
				10 => '3:0',
				9  => '1:1',
				8  => '3:0',
			),
			$analysis
		);

		$season_games = array_values( array_filter( $profile['games'], static fn( array $g ): bool => '2025-26' === $g['season_slug'] ) );
		self::assertCount( 4, $season_games );
		self::assertSame( 11, $season_games[0]['opponents'][0]['classification'] );
	}

	public function test_ranking_tournament_groups_and_overall(): void {
		$rl = $this->model->ranking->get( $this->ids['season_new'] );
		self::assertSame( array( 1, 2 ), array_column( $rl['rounds'], 'round_no' ) );
		$group = $rl['rounds'][0]['categories'][1][0];
		self::assertSame( 'Bergwiese', $group['host']['short'] );
		self::assertCount( 6, $group['games'] );
		$ranked = array();
		foreach ( $group['players'] as $row ) {
			$ranked[] = $row['rank'] . ' ' . $row['player']['last_name'] . ' ' . $row['wins'] . ' ' . $row['sets_won'] . ':' . $row['sets_lost'] . ' ' . $row['points'];
		}
		self::assertSame( array( '1 Skizze 3 9:1 6', '2 Muster 2 7:4 5', '3 Exempel 1 4:8 4', '4 Akte 0 2:9 3' ), $ranked, 'berechnet mit GroupRanking, da keine Punkte gespeichert sind' );

		$upcoming = $rl['rounds'][1]['categories'][1][0];
		self::assertFalse( $upcoming['has_games'] );
		self::assertNull( $upcoming['players'][0]['rank'] );

		$overall = $rl['overall'][1];
		self::assertSame( array( 'Skizze', 'Muster', 'Exempel', 'Akte' ), array_map( static fn( array $r ): string => $r['player']['last_name'], $overall ) );
		self::assertSame( array( 6.0, 5.0, 4.0, 3.0 ), array_column( $overall, 'points' ) );
		self::assertTrue( $overall[3]['qualified'] );
		self::assertSame( array( 1 => 6.0 ), $overall[0]['rounds'] );
		self::assertNull( $this->model->ranking->get( $this->ids['season_old'] ) );
	}

	public function test_cup_entries_and_results(): void {
		$cup = $this->model->cup->season( $this->ids['season_new'] );
		self::assertCount( 1, $cup );
		self::assertSame( 'a', $cup[0]['category'] );
		self::assertSame( array( 1, 2 ), array_column( $cup[0]['entries'], 'seed' ) );
		self::assertSame( array( 'Hans Muster', 'Anna Beispiel' ), array_column( $cup[0]['entries'][0]['players'], 'name' ) );
		self::assertSame( 12, $cup[0]['entries'][0]['players'][0]['classification'] );
		self::assertSame( 'Seefeld 1', $cup[0]['fixtures'][0]['home']['name'] );
		self::assertSame( 5, $cup[0]['fixtures'][0]['games'] );
	}

	public function test_latest_and_upcoming_fixtures(): void {
		$latest = $this->model->fixtures->latest( 3 );
		self::assertSame( array( $this->ids['cup_fixture'], $this->ids['fixture_b2'], $this->ids['fixture_5'] ), array_column( $latest, 'id' ) );
		self::assertSame( 'CUP', $latest[0]['kind'] );

		$upcoming = $this->model->fixtures->upcoming( 5, '2026-11-09 18:00:00' );
		self::assertSame( array( $this->ids['fixture_7'], $this->ids['fixture_8'] ), array_column( $upcoming, 'id' ) );
		self::assertSame( 'Sporthalle Bergwiese', $upcoming[0]['venue'] );
		self::assertSame( 'Schulhaus Lindenhof', $upcoming[1]['venue'] );
	}
}
