<?php
/**
 * Vorschlag der Aufstellung aus der letzten erfassten Begegnung (ResultService::entry()).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Results;

use TTLeague\Access\AccessPolicy;
use TTLeague\Domain\Match\GameOrder;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Results\Actor;
use TTLeague\Results\EntryForm;
use TTLeague\Results\ResultService;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Erfundene Clubs Seefeld (Teams 1 und 2) und Wollishofen (Team 1) in einer Gruppe, dazu ein
 * Cup mit je einer Mannschaft. Begegnungen in der Reihenfolge ihrer Anspielzeit: early
 * (Wollishofen 1 – Seefeld 1), f1 (Seefeld 1 – Wollishofen 1), second (Seefeld 2 –
 * Wollishofen 1), derby (Seefeld 1 – Seefeld 2), f3 (Seefeld 1 – Wollishofen 1); im Cup cup1
 * (Seefeld – Wollishofen) und cup2 (Wollishofen – Seefeld).
 */
final class LineupSuggestionTest extends IntegrationTestCase {

	private const RESULTS = array( '3:1', '3:0', '3:2', '1:3', '3:1', '0:3', '3:2', '2:3', '1:3', '3:0' );

	private Seeder $seeder;

	private ResultService $service;

	/** @var array<string,int> */
	private array $id = array();

	/** @var array<string,int> Spieler-IDs nach Schlüssel (h1 bis h6, a1 bis a4). */
	private array $players = array();

	/** @var array<string,Actor> */
	private array $actor = array();

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$s      = new Seeder( $this->db );
		$season = $s->season( '2026-27' );
		$home   = $s->club( 'Seefeld', 1 );
		$away   = $s->club( 'Wollishofen', 2 );
		$group  = $s->competition( $season, 'MM', 'Gruppe B1', 'B', 1 );
		$cup    = $s->competition( $season, 'CUP', 'Cup A', 'A' );
		$h1     = $s->team( $season, $home, 1, 'Seefeld 1', $group );
		$h2     = $s->team( $season, $home, 2, 'Seefeld 2', $group );
		$a1     = $s->team( $season, $away, 1, 'Wollishofen 1', $group );

		$players = array();
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $i => $key ) {
			$players[ $key ] = $s->player( 'Seefelder', 'Spieler' . ( $i + 1 ) );
			$s->register( $players[ $key ], $season, $home, 10 - $i );
		}
		foreach ( array( 'a1', 'a2', 'a3', 'a4' ) as $i => $key ) {
			$players[ $key ] = $s->player( 'Wollishofer', 'Spieler' . ( $i + 1 ) );
			$s->register( $players[ $key ], $season, $away, 10 - $i );
		}
		$s->roster( $h1, array( $players['h1'], $players['h2'], $players['h3'] ) );
		$s->roster( $h2, array( $players['h4'], $players['h5'], $players['h6'] ) );
		$s->roster( $a1, array( $players['a1'], $players['a2'], $players['a3'], $players['a4'] ) );

		$captain_h1 = $s->person( 'Heim', 'Hanna' );
		$captain_a1 = $s->person( 'Gast', 'Gustav' );
		$s->role( $season, $home, $captain_h1, 'captain', $h1 );
		$s->role( $season, $away, $captain_a1, 'captain', $a1 );
		$this->db->update( 'person', array( 'user_id' => 101 ), array( 'id' => $captain_h1 ) );
		$this->db->update( 'person', array( 'user_id' => 102 ), array( 'id' => $captain_a1 ) );
		$this->actor = array(
			'captain_h1' => new Actor( 101, $captain_h1, false ),
			'captain_a1' => new Actor( 102, $captain_a1, false ),
			'tk'         => new Actor( 103, null, true, 'TK' ),
		);

		$entries = array();
		foreach ( array(
			'home' => array( $home, 'Seefeld 1', 'h1', 'h2' ),
			'away' => array( $away, 'Wollishofen 1', 'a1', 'a2' ),
		) as $side => [ $club, $name, $first, $second ] ) {
			$entries[ $side ] = $this->db->insert(
				'cup_entry',
				array(
					'competition_id' => $cup,
					'club_id'        => $club,
					'number'         => 1,
					'name'           => $name,
					'player1_id'     => $players[ $first ],
					'player2_id'     => $players[ $second ],
				)
			);
		}

		$match = static fn( int $home_team, int $away_team, string $at ): array => array(
			'home_team_id' => $home_team,
			'away_team_id' => $away_team,
			'scheduled_at' => $at,
		);

		$this->id      = $players + array(
			'season' => $season,
			'early'  => $s->fixture( $group, $match( $a1, $h1, '2026-11-03 20:00:00' ) ),
			'f1'     => $s->fixture( $group, $match( $h1, $a1, '2026-11-10 20:00:00' ) ),
			'second' => $s->fixture( $group, $match( $h2, $a1, '2026-11-12 20:00:00' ) ),
			'derby'  => $s->fixture( $group, $match( $h1, $h2, '2026-11-17 20:00:00' ) ),
			'f3'     => $s->fixture( $group, $match( $h1, $a1, '2026-11-24 20:00:00' ) ),
			'cup1'   => $s->fixture(
				$cup,
				array(
					'home_entry_id' => $entries['home'],
					'away_entry_id' => $entries['away'],
					'scheduled_at'  => '2026-11-05 19:30:00',
				)
			),
			'cup2'   => $s->fixture(
				$cup,
				array(
					'home_entry_id' => $entries['away'],
					'away_entry_id' => $entries['home'],
					'scheduled_at'  => '2026-11-26 19:30:00',
				)
			),
		);
		$this->players = $players;
		$this->seeder  = $s;
		$this->service = new ResultService(
			$this->db,
			new AccessPolicy( $this->db ),
			new FixedClock( '2026-11-25 21:30:00' ),
			new \DateTimeZone( 'Europe/Zurich' ),
			new AuditLog( $this->db ),
			new RecordingEvents()
		);
	}

	/**
	 * Erfasst eine Meisterschaftsbegegnung als TK.
	 *
	 * @param list<string> $home Spielerschlüssel für die Slots A, B, C.
	 * @param list<string> $away Spielerschlüssel für die Slots X, Y, Z.
	 */
	private function play( string $fixture, array $home, array $away ): void {
		$lineup = array(
			'home' => array_combine( array( 'A', 'B', 'C' ), array_map( fn( string $k ): int => $this->id[ $k ], $home ) ),
			'away' => array_combine( array( 'X', 'Y', 'Z' ), array_map( fn( string $k ): int => $this->id[ $k ], $away ) ),
		);
		$games  = array();
		foreach ( GameOrder::team_match() as $i => $slot ) {
			[ $sets_home, $sets_away ] = array_map( 'intval', explode( ':', self::RESULTS[ $i ] ) );
			$game                      = array(
				'sequence'  => $slot['sequence'],
				'status'    => 'played',
				'sets_home' => $sets_home,
				'sets_away' => $sets_away,
			);
			if ( GameOrder::DOUBLE === $slot['type'] ) {
				$game['home_players'] = array( $lineup['home']['A'], $lineup['home']['B'] );
				$game['away_players'] = array( $lineup['away']['X'], $lineup['away']['Y'] );
			}
			$games[] = $game;
		}
		$this->service->submit(
			$this->id[ $fixture ],
			array(
				'lineup' => $lineup,
				'games'  => $games,
			),
			$this->actor['tk']
		);
	}

	/**
	 * Vorschlag einer Begegnung als Slot => Spielerschlüssel pro Seite.
	 *
	 * @return array{home:array<string,string>,away:array<string,string>}
	 */
	private function suggestion( string $fixture, string $actor = 'captain_h1' ): array {
		$entry = $this->service->entry( $this->id[ $fixture ], $this->actor[ $actor ] );
		$keys  = array_flip( $this->players );
		$named = array();
		foreach ( array( 'home', 'away' ) as $side ) {
			self::assertIsObject( $entry['suggestion'][ $side ], 'als JSON ein Objekt, auch leer' );
			$named[ $side ] = array_map( static fn( int $id ): string => (string) $keys[ $id ], (array) $entry['suggestion'][ $side ] );
		}
		return $named;
	}

	private const NONE = array(
		'home' => array(),
		'away' => array(),
	);

	public function test_first_match_of_a_team_has_no_suggestion(): void {
		self::assertSame( self::NONE, $this->suggestion( 'f1' ) );
		self::assertSame( self::NONE, $this->suggestion( 'early', 'tk' ) );
	}

	public function test_lineup_of_the_last_match_is_suggested_for_both_sides(): void {
		// Seefeld 1 spielte auswärts (X, Y, Z) mit h4 als Ersatz; jetzt ist es Heimteam (A, B, C).
		$this->play( 'early', array( 'a1', 'a2', 'a4' ), array( 'h1', 'h2', 'h4' ) );

		self::assertSame(
			array(
				'home' => array(
					'A' => 'h1',
					'B' => 'h2',
					'C' => 'h4',
				),
				'away' => array(
					'X' => 'a1',
					'Y' => 'a2',
					'Z' => 'a4',
				),
			),
			$this->suggestion( 'f1' )
		);

		// Das Formular beginnt mit dem Vorschlag; gespeichert ist nichts.
		$entry = $this->service->entry( $this->id['f1'], $this->actor['captain_h1'] );
		$data  = EntryForm::data( $entry );
		$form  = EntryForm::initial( $entry, $data );
		self::assertSame( (string) $this->id['h4'], $form['lineup']['home']['C'] );
		self::assertSame( (string) $this->id['a4'], $form['lineup']['away']['Z'] );
		self::assertTrue( EntryForm::view( $data, $form )['lineup']['home']['sug'] );
		self::assertSame( array(), (array) $entry['current']['lineup']['home'] );
		self::assertSame( '0', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'lineup' )} WHERE fixture_id = %d", array( $this->id['f1'] ) ) );
		self::assertSame( 'scheduled', $entry['fixture']['status'] );
	}

	public function test_last_means_the_latest_scheduled_match_with_a_lineup(): void {
		$this->play( 'f3', array( 'h1', 'h2', 'h3' ), array( 'a1', 'a2', 'a3' ) );
		// Später erfasst, aber früher gespielt: zählt nicht als «letzte».
		$this->play( 'early', array( 'a3', 'a2', 'a1' ), array( 'h3', 'h2', 'h1' ) );

		$expected_f3 = array(
			'A' => 'h1',
			'B' => 'h2',
			'C' => 'h3',
		);
		self::assertSame( $expected_f3, $this->suggestion( 'f1' )['home'], 'spätere Anspielzeit, auch für eine nachgetragene Begegnung' );
		self::assertSame(
			array(
				'X' => 'a1',
				'Y' => 'a2',
				'Z' => 'a3',
			),
			$this->suggestion( 'f1' )['away']
		);

		// Ein Forfait hat ein Resultat, aber keine Aufstellung: Es gilt die Begegnung davor.
		$this->service->decide(
			$this->id['f3'],
			$this->actor['tk'],
			array(
				'action'  => 'forfeit',
				'winner'  => 'home',
				'comment' => 'Gast nicht angetreten',
			)
		);
		self::assertSame(
			array(
				'A' => 'h3',
				'B' => 'h2',
				'C' => 'h1',
			),
			$this->suggestion( 'f1' )['home']
		);

		// Zurückgesetzt: kein Resultat mehr, also auch kein Vorschlag daraus.
		$this->service->decide( $this->id['early'], $this->actor['tk'], array( 'action' => 'reset' ) );
		self::assertSame( self::NONE, $this->suggestion( 'f1' ) );
	}

	public function test_only_selectable_players_are_suggested(): void {
		$this->play( 'f1', array( 'h1', 'h2', 'h4' ), array( 'a1', 'a2', 'a4' ) );
		// Altdaten mit einer Aufstellung, die heute nicht zulässig wäre: h1 ist Stammspieler von
		// Seefeld 1 und für Seefeld 2 gesperrt.
		$this->seeder->team_match(
			$this->id['second'],
			'2026-11-12 20:00:00',
			array(
				'A' => $this->id['h1'],
				'B' => $this->id['h5'],
				'C' => $this->id['h4'],
			),
			array(
				'X' => $this->id['a1'],
				'Y' => $this->id['a2'],
				'Z' => $this->id['a4'],
			),
			self::RESULTS
		);

		self::assertSame(
			array(
				'home' => array(
					'A' => 'h1',
					'B' => 'h2',
				),
				'away' => array( 'Y' => 'h5' ),
			),
			$this->suggestion( 'derby' ),
			'h1 ist für Seefeld 2 gesperrt; h4 stünde auf beiden Seiten und bleibt auf beiden weg'
		);

		// a4 ist nicht mehr für den Club gemeldet und fehlt unter den Kandidaten.
		$this->db->delete(
			'team_roster',
			array( 'player_id' => $this->id['a4'] )
		);
		$this->db->delete(
			'player_season',
			array(
				'player_id' => $this->id['a4'],
				'season_id' => $this->id['season'],
			)
		);
		self::assertSame(
			array(
				'X' => 'a1',
				'Y' => 'a2',
			),
			$this->suggestion( 'f3' )['away']
		);
	}

	public function test_no_suggestion_for_recorded_results_or_without_the_right_to_enter(): void {
		$this->play( 'early', array( 'a1', 'a2', 'a3' ), array( 'h1', 'h2', 'h3' ) );

		self::assertSame( self::NONE, $this->suggestion( 'f1', 'captain_a1' ), 'Gastseite erfasst nicht' );
		self::assertNotSame( self::NONE, $this->suggestion( 'f1', 'tk' ) );

		$this->play( 'f1', array( 'h3', 'h2', 'h1' ), array( 'a1', 'a2', 'a3' ) );
		self::assertSame( self::NONE, $this->suggestion( 'f1' ), 'erfasstes Resultat hat Vorrang' );
		$entry = $this->service->entry( $this->id['f1'], $this->actor['captain_h1'] );
		self::assertSame( (string) $this->id['h3'], EntryForm::initial( $entry, EntryForm::data( $entry ) )['lineup']['home']['A'] );

		$this->service->decide(
			$this->id['f3'],
			$this->actor['tk'],
			array(
				'action'  => 'forfeit',
				'winner'  => 'away',
				'comment' => 'Heim nicht angetreten',
			)
		);
		self::assertSame( self::NONE, $this->suggestion( 'f3', 'tk' ), 'Forfait ist ein Resultat' );
	}

	public function test_cup_uses_the_last_match_of_the_cup_entry(): void {
		self::assertSame( self::NONE, $this->suggestion( 'cup2', 'tk' ) );

		$this->service->submit(
			$this->id['cup1'],
			array(
				'lineup' => array(
					'home' => array(
						'A' => $this->id['h2'],
						'B' => $this->id['h1'],
					),
					'away' => array(
						'Y' => $this->id['a1'],
						'Z' => $this->id['a3'],
					),
				),
				'games'  => array(
					array(
						'sequence'  => 1,
						'status'    => 'played',
						'sets_home' => 3,
						'sets_away' => 0,
					),
					array(
						'sequence'  => 2,
						'status'    => 'played',
						'sets_home' => 3,
						'sets_away' => 1,
					),
					array(
						'sequence'  => 3,
						'status'    => 'played',
						'sets_home' => 3,
						'sets_away' => 2,
					),
				),
			),
			$this->actor['tk']
		);

		// Im Rückspiel sind die Seiten getauscht: A, B werden Y, Z und umgekehrt.
		self::assertSame(
			array(
				'home' => array(
					'A' => 'a1',
					'B' => 'a3',
				),
				'away' => array(
					'Y' => 'h2',
					'Z' => 'h1',
				),
			),
			$this->suggestion( 'cup2', 'tk' )
		);
		self::assertSame( self::NONE, $this->suggestion( 'f1' ), 'Cup und Meisterschaft sind getrennt' );
	}
}
