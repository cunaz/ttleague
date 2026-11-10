<?php
/**
 * Eine geplante Saison ist nicht öffentlich: Die Lesemodelle kennen sie nicht.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Frontend;

use TTLeague\ReadModel\Cache\NullCache;
use TTLeague\ReadModel\ReadModel;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Szenario aus dem Seeder (2025/26 archiviert, 2026/27 aktiv), dazu die geplante Saison 2027/28
 * mit einer Gruppe, zwei Teams, einer Begegnung im Spielplanentwurf und einer Meldung.
 */
final class PlannedSeasonTest extends IntegrationTestCase {

	private ReadModel $model;

	/** @var array<string,int> */
	private array $ids;

	private int $planned;

	private int $planned_fixture;

	private int $player;

	protected function setUp(): void {
		parent::setUp();
		$s                     = new Seeder( $this->db );
		$this->ids             = $s->scenario();
		$this->planned         = $s->season( '2027-28', 'planned' );
		$group                 = $s->competition( $this->planned, 'MM', 'Gruppe A', 'A', 1 );
		$home                  = $s->team( $this->planned, $this->ids['club_seefeld'], 1, 'Seefeld 1', $group );
		$away                  = $s->team( $this->planned, $this->ids['club_alt'], 1, 'Altstetten 1', $group );
		$this->planned_fixture = $s->fixture(
			$group,
			array(
				'home_team_id' => $home,
				'away_team_id' => $away,
				'scheduled_at' => '2027-09-14 20:00:00',
				'status'       => 'scheduled',
				'match_no'     => 1,
				'round'        => 1,
				'leg'          => 1,
			)
		);
		$this->player          = (int) $this->db->get_var(
			"SELECT ps.player_id FROM {$this->db->table( 'player_season' )} ps WHERE ps.season_id = %d ORDER BY ps.id LIMIT 1",
			array( $this->ids['season_new'] )
		);
		$s->register( $this->player, $this->planned, $this->ids['club_alt'], 14 );
		$this->model = new ReadModel( $this->db, new NullCache() );
	}

	public function test_season_list_and_lookup_do_not_know_the_planned_season(): void {
		self::assertSame( array( '2026-27', '2025-26' ), array_column( $this->model->seasons->all(), 'slug' ) );
		self::assertNull( $this->model->seasons->by_slug( '2027-28' ) );
		self::assertNull( $this->model->seasons->by_id( $this->planned ) );
		self::assertSame( '2026-27', $this->model->seasons->active()['slug'] ?? null );
	}

	public function test_without_active_season_the_newest_archived_one_is_shown_not_the_planned(): void {
		$this->db->update( 'season', array( 'status' => 'archived' ), array( 'id' => $this->ids['season_new'] ) );

		self::assertSame( '2026-27', $this->model->seasons->active()['slug'] ?? null );
	}

	public function test_only_a_planned_season_means_no_public_season(): void {
		$this->db->update( 'season', array( 'status' => 'planned' ), array( 'status' => 'active' ) );
		$this->db->update( 'season', array( 'status' => 'planned' ), array( 'status' => 'archived' ) );

		self::assertNull( $this->model->seasons->active() );
		self::assertSame( array(), $this->model->seasons->all() );
	}

	public function test_draft_fixture_has_no_public_match_report(): void {
		self::assertNull( $this->model->reports->get( $this->planned_fixture ) );

		$this->db->update( 'season', array( 'status' => 'archived' ), array( 'id' => $this->ids['season_new'] ) );
		$this->db->update( 'season', array( 'status' => 'active' ), array( 'id' => $this->planned ) );
		self::assertNotNull( ( new ReadModel( $this->db, new NullCache() ) )->reports->get( $this->planned_fixture ), 'nach der Freigabe öffentlich' );
	}

	public function test_upcoming_fixtures_across_seasons_skip_the_draft_schedule(): void {
		$upcoming = $this->model->fixtures->upcoming( 500, '2026-01-01 00:00:00' );

		self::assertNotContains( $this->planned_fixture, array_column( $upcoming, 'id' ) );
		self::assertNotContains( '2027-28', array_column( $upcoming, 'season_slug' ) );
	}

	public function test_player_profile_does_not_show_the_registration_for_the_planned_season(): void {
		$profile = $this->model->profiles->get( $this->player );

		self::assertNotContains( $this->planned, array_column( $profile['seasons'], 'season_id' ) );
		self::assertContains( $this->ids['season_new'], array_column( $profile['seasons'], 'season_id' ) );
	}

	public function test_player_profile_does_not_show_games_of_the_planned_season(): void {
		$other = (int) $this->db->get_var(
			"SELECT ps.player_id FROM {$this->db->table( 'player_season' )} ps WHERE ps.season_id = %d AND ps.player_id <> %d ORDER BY ps.id LIMIT 1",
			array( $this->ids['season_new'], $this->player )
		);
		$game  = $this->db->insert(
			'game',
			array(
				'fixture_id'   => $this->planned_fixture,
				'sequence'     => 1,
				'type'         => 'single',
				'a1_player_id' => $this->player,
				'b1_player_id' => $other,
				'sets_a'       => 3,
				'sets_b'       => 0,
				'status'       => 'played',
				'played_at'    => '2027-09-14 20:00:00',
			)
		);

		$games = $this->model->profiles->get( $this->player )['games'];

		self::assertNotSame( array(), $games, 'Spiele der aktiven Saison bleiben' );
		self::assertNotContains( $game, array_column( $games, 'id' ) );
		self::assertNotContains( $this->planned, array_column( $games, 'season_id' ) );
	}
}
