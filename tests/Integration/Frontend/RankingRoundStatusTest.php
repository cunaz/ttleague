<?php
/**
 * Runden des Ranglistenturniers in Planung sind nicht öffentlich.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Frontend;

use TTLeague\Calendar\Feeds;
use TTLeague\Frontend\Support\Urls;
use TTLeague\Frontend\Views\RankingView;
use TTLeague\ReadModel\Cache\NullCache;
use TTLeague\ReadModel\ReadModel;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Szenario aus dem Seeder: Runde 1 gespielt, Runde 2 angesetzt (18. November 2026 bei Seefeld),
 * beide wie importiert mit dem Spaltenstandard «closed». Die Tests setzen den Status der zweiten
 * Runde und prüfen Lesemodell, Ansicht und Kalender-Abo.
 */
final class RankingRoundStatusTest extends IntegrationTestCase {

	private ReadModel $model;

	private Feeds $feeds;

	/** @var array<string,int> */
	private array $ids;

	private int $round_2;

	private int $group_2;

	private int $muster;

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->ids     = ( new Seeder( $this->db ) )->scenario();
		$this->model   = new ReadModel( $this->db, new NullCache() );
		$this->feeds   = new Feeds( $this->db, new NullCache(), $this->model );
		$this->group_2 = $this->ids['rl_group_1'] + 1;
		$this->round_2 = (int) $this->db->get_var( "SELECT rl_round_id FROM {$this->db->table( 'rl_group' )} WHERE id = %d", array( $this->group_2 ) );
		$this->muster  = (int) $this->db->get_var(
			"SELECT p.id FROM {$this->db->table( 'player' )} p JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id WHERE pe.last_name = %s",
			array( 'Muster' )
		);
	}

	private function set_status( string $status, string $players = 'played' ): void {
		$this->db->update( 'rl_round', array( 'status' => $status ), array( 'id' => $this->round_2 ) );
		$this->db->update( 'rl_group_player', array( 'status' => $players ), array( 'rl_group_id' => $this->group_2 ) );
	}

	/**
	 * Gruppen des Ranglistenturniers im Kalender-Abo von Muster.
	 *
	 * @return list<int>
	 */
	private function feed_groups(): array {
		$license = (int) $this->db->get_var( "SELECT license_no FROM {$this->db->table( 'player' )} WHERE id = %d", array( $this->muster ) );
		$rows    = $this->feeds->rows( $this->feeds->player( $license ) )['rows'];
		return array_values( array_map( static fn( array $row ): int => $row['id'], array_filter( $rows, static fn( array $row ): bool => 'rl_group' === $row['type'] ) ) );
	}

	private function view(): string {
		return ( new RankingView( $this->model, new Urls( 'https://liga.example/' ) ) )->render( array() );
	}

	public function test_imported_rounds_look_as_before(): void {
		// Ohne gesetzten Status gilt der Spaltenstandard «closed»: beide Runden sind öffentlich.
		self::assertSame( 'closed', $this->db->get_var( "SELECT status FROM {$this->db->table( 'rl_round' )} WHERE id = %d", array( $this->round_2 ) ) );
		$rl = $this->model->ranking->get( $this->ids['season_new'] );
		self::assertSame( array( 1, 2 ), array_column( $rl['rounds'], 'round_no' ) );
		self::assertCount( 4, $rl['rounds'][1]['categories'][1][0]['players'] );
		self::assertSame( array( $this->ids['rl_group_1'], $this->group_2 ), $this->feed_groups() );
		$html = $this->view();
		self::assertStringContainsString( 'id="ttl-runde-2"', $html );
		self::assertStringContainsString( 'Aufgebot', $html );
		self::assertStringContainsString( '18.11.2026, 19:15', $html );
		self::assertStringContainsString( '16.–19.11.2026', $html );
		self::assertStringNotContainsString( 'abgemeldet', $html );
	}

	public function test_planned_round_appears_nowhere(): void {
		$before = $this->model->ranking->get( $this->ids['season_new'] );
		$this->set_status( 'planned', 'scheduled' );

		$rl = $this->model->ranking->get( $this->ids['season_new'] );
		self::assertSame( array( 1 ), array_column( $rl['rounds'], 'round_no' ), 'Weder die Runde noch ihre Gruppen.' );
		self::assertSame( $before['overall'], $rl['overall'] );
		self::assertSame( $before['rounds'][0], $rl['rounds'][0] );
		$html = $this->view();
		self::assertStringNotContainsString( 'id="ttl-runde-2"', $html );
		self::assertStringNotContainsString( '2. Runde</a>', $html );
		self::assertStringNotContainsString( '18.11.2026', $html );
		self::assertStringNotContainsString( '16.–19.11.2026', $html );
		self::assertStringContainsString( 'id="ttl-runde-1"', $html );
		// Im Kalender-Abo der Person steht nur die gespielte Gruppe.
		self::assertSame( array( $this->ids['rl_group_1'] ), $this->feed_groups() );
	}

	public function test_planned_round_hides_games_too(): void {
		// Auch wenn (regelwidrig) schon Partien an einer geplanten Gruppe hängen, erscheint nichts davon.
		$exempel = (int) $this->db->get_var( "SELECT player_id FROM {$this->db->table( 'rl_group_player' )} WHERE rl_group_id = %d AND player_id <> %d ORDER BY id LIMIT 1", array( $this->group_2, $this->muster ) );
		( new Seeder( $this->db ) )->rl_game( $this->group_2, 1, $this->muster, $exempel, '3:0', '2026-11-18 19:15:00' );
		$with_games = $this->model->ranking->get( $this->ids['season_new'] );
		self::assertTrue( $with_games['rounds'][1]['categories'][1][0]['has_games'] );

		$this->set_status( 'planned', 'scheduled' );
		$rl = $this->model->ranking->get( $this->ids['season_new'] );
		self::assertCount( 1, $rl['rounds'] );
		foreach ( $rl['overall'][1] as $row ) {
			self::assertSame( array( 1 ), array_keys( $row['rounds'] ), 'Die Gesamtrangliste zählt die geplante Runde nicht.' );
		}
	}

	public function test_published_round_shows_the_callup(): void {
		$this->set_status( 'published', 'scheduled' );
		$this->db->update(
			'rl_group_player',
			array( 'status' => 'absent' ),
			array(
				'rl_group_id' => $this->group_2,
				'player_id'   => $this->muster,
			)
		);

		$rl = $this->model->ranking->get( $this->ids['season_new'] );
		self::assertSame( array( 'closed', 'published' ), array_column( $rl['rounds'], 'status' ) );
		$group = $rl['rounds'][1]['categories'][1][0];
		self::assertFalse( $group['has_games'] );
		self::assertCount( 4, $group['players'] );
		self::assertSame( '2026-11-18 19:15:00', $group['start_at'] );
		self::assertSame( 'Seefeld', $group['host']['short'] );
		self::assertNull( $group['players'][0]['rank'] );
		self::assertNull( $group['players'][0]['points'] );

		$html = $this->view();
		self::assertStringContainsString( 'id="ttl-runde-2"', $html );
		self::assertStringContainsString( 'Aufgebot', $html );
		self::assertSame( 1, substr_count( $html, 'abgemeldet' ), 'Wer sich für die Runde abgemeldet hat, ist im Aufgebot markiert.' );
		// Die Gesamtrangliste zählt weiter nur gespielte Gruppen.
		self::assertSame( array( 1 => 6.0 ), $rl['overall'][1][0]['rounds'] );
		// Muster hat sich für die Runde abgemeldet: Der Termin steht nicht mehr in seinem Kalender.
		self::assertSame( array( $this->ids['rl_group_1'] ), $this->feed_groups() );
		$this->db->update(
			'rl_group_player',
			array( 'status' => 'scheduled' ),
			array(
				'rl_group_id' => $this->group_2,
				'player_id'   => $this->muster,
			)
		);
		self::assertSame( array( $this->ids['rl_group_1'], $this->group_2 ), $this->feed_groups(), 'Aufgeboten: Der Termin steht im Kalender-Abo.' );
		// In einer abgeschlossenen (importierten) Runde bleibt auch «abwesend» im Kalender wie bisher.
		$this->set_status( 'closed', 'absent' );
		self::assertSame( array( $this->ids['rl_group_1'], $this->group_2 ), $this->feed_groups() );
	}
}
