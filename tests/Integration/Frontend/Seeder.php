<?php
/**
 * Testdaten für die Lesemodelle: erfundene Clubs, Personen und Resultate.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Frontend;

use TTLeague\Domain\Match\GameOrder;
use TTLeague\Infrastructure\Database\Db;

/**
 * Baut kleine, realistische Saisons auf. Alle Namen und Lizenznummern (ab 90001) sind erfunden.
 * scenario() legt die Daten an, gegen die die Integrationstests rechnen; die erwarteten Werte
 * stehen in den Kommentaren der Methode.
 */
final class Seeder {

	private int $next_license = 90001;

	public function __construct( private readonly Db $db ) {}

	public function season( string $slug, string $status = 'active', array $rules = array() ): int {
		$start = (int) substr( $slug, 0, 4 );
		return $this->db->insert(
			'season',
			array(
				'name'       => $start . '/' . substr( $slug, 5, 2 ),
				'slug'       => $slug,
				'start_date' => $start . '-08-01',
				'end_date'   => ( $start + 1 ) . '-07-31',
				'status'     => $status,
				'rules_json' => (string) json_encode( $rules ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Tests laufen ohne WordPress.
				'created_at' => '2026-01-01 00:00:00',
				'updated_at' => '2026-01-01 00:00:00',
			)
		);
	}

	public function club( string $short_name, int $number ): int {
		return $this->db->insert(
			'club',
			array(
				'number'     => $number,
				'name'       => 'TTC ' . $short_name,
				'short_name' => $short_name,
				'slug'       => strtolower( str_replace( array( 'ü', ' ' ), array( 'ue', '-' ), $short_name ) ),
				'active'     => 1,
			)
		);
	}

	/**
	 * @param array<string,int|string|null> $data Weitere Felder.
	 */
	public function venue( int $club_id, string $name, array $data = array() ): int {
		return $this->db->insert(
			'venue',
			$data + array(
				'club_id'    => $club_id,
				'name'       => $name,
				'street'     => 'Musterstrasse 1',
				'zip'        => '8000',
				'city'       => 'Musterstadt',
				'weekday'    => 1,
				'open_time'  => '19:30:00',
				'close_time' => '22:00:00',
				'active'     => 1,
			)
		);
	}

	public function person( string $last_name, string $first_name ): int {
		return $this->db->insert(
			'person',
			array(
				'last_name'  => $last_name,
				'first_name' => $first_name,
				'created_at' => '2026-01-01 00:00:00',
				'updated_at' => '2026-01-01 00:00:00',
			)
		);
	}

	/**
	 * Legt Person und Lizenz an und liefert die Spieler-ID.
	 */
	public function player( string $last_name, string $first_name ): int {
		$person = $this->person( $last_name, $first_name );
		return $this->db->insert(
			'player',
			array(
				'person_id'  => $person,
				'license_no' => $this->next_license++,
			)
		);
	}

	public function person_of( int $player_id ): int {
		return (int) $this->db->get_var( "SELECT person_id FROM {$this->db->table( 'player' )} WHERE id = %d", array( $player_id ) );
	}

	public function register( int $player_id, int $season_id, int $club_id, int $classification ): void {
		$this->db->insert(
			'player_season',
			array(
				'player_id'      => $player_id,
				'season_id'      => $season_id,
				'club_id'        => $club_id,
				'classification' => $classification,
				'status'         => 'active',
			)
		);
	}

	public function competition( int $season_id, string $type, string $name, ?string $category = null, ?int $group_no = null, int $sort_order = 0 ): int {
		return $this->db->insert(
			'competition',
			array(
				'season_id'  => $season_id,
				'type'       => $type,
				'name'       => $name,
				'category'   => $category,
				'group_no'   => $group_no,
				'sort_order' => $sort_order,
			)
		);
	}

	public function team( int $season_id, int $club_id, int $number, string $name, ?int $competition_id, ?int $venue_id = null, string $start = '20:00:00' ): int {
		return $this->db->insert(
			'team',
			array(
				'season_id'          => $season_id,
				'club_id'            => $club_id,
				'number'             => $number,
				'name'               => $name,
				'competition_id'     => $competition_id,
				'home_venue_id'      => $venue_id,
				'default_start_time' => $start,
				'status'             => 'active',
			)
		);
	}

	/**
	 * @param list<int> $player_ids Stammspieler.
	 */
	public function roster( int $team_id, array $player_ids, string $kind = 'stamm' ): void {
		foreach ( $player_ids as $player_id ) {
			$this->db->insert(
				'team_roster',
				array(
					'team_id'   => $team_id,
					'player_id' => $player_id,
					'kind'      => $kind,
				)
			);
		}
	}

	public function role( int $season_id, int $club_id, int $person_id, string $role, ?int $team_id = null ): void {
		$this->db->insert(
			'club_role',
			array(
				'season_id' => $season_id,
				'club_id'   => $club_id,
				'person_id' => $person_id,
				'role'      => $role,
				'team_id'   => $team_id,
			)
		);
	}

	/**
	 * @param array<string,int|string|null> $data Felder der Begegnung.
	 */
	public function fixture( int $competition_id, array $data ): int {
		return $this->db->insert( 'fixture', $data + array( 'competition_id' => $competition_id ) );
	}

	/**
	 * Aufstellung und zehn Spiele einer Meisterschaftsbegegnung in reglementarischer Folge.
	 * Ergebnisse als «3:1» (Sätze Heim:Gast), «wo:h» / «wo:a» (kampflos für Heim/Gast) oder «np».
	 * Fehlt ein Slot in der Aufstellung, bleibt die Spielerseite leer. Das Doppel (Spiel 7)
	 * bestreiten A+B gegen X+Y, sofern nicht anders angegeben.
	 *
	 * @param array<string,int>        $home    Slot (A, B, C) => Spieler-ID.
	 * @param array<string,int>        $away    Slot (X, Y, Z) => Spieler-ID.
	 * @param list<string>             $results Zehn Resultate.
	 * @param list<int>                $subs    Spieler-IDs, die als Ersatz spielen.
	 * @param array{0:list<string>,1:list<string>}|null $double Doppelpaarung (Slots).
	 * @return list<int> Spiel-IDs in Spielfolge.
	 */
	public function team_match( int $fixture_id, string $played_at, array $home, array $away, array $results, array $subs = array(), ?array $double = null ): array {
		$this->lineup( $fixture_id, $home, $away, $subs );
		$double = $double ?? array( array( 'A', 'B' ), array( 'X', 'Y' ) );
		$ids    = array();
		$wins   = array( 0, 0 );
		foreach ( GameOrder::team_match() as $i => $slot ) {
			if ( GameOrder::DOUBLE === $slot['type'] ) {
				$a = array( $home[ $double[0][0] ] ?? null, $home[ $double[0][1] ] ?? null );
				$b = array( $away[ $double[1][0] ] ?? null, $away[ $double[1][1] ] ?? null );
			} else {
				$a = array( $home[ $slot['home'] ] ?? null, null );
				$b = array( $away[ $slot['away'] ] ?? null, null );
			}
			$ids[] = $this->game( $fixture_id, null, $slot['sequence'], $slot['type'], $a, $b, $results[ $i ], $played_at, true, $wins );
		}
		$this->db->update(
			'fixture',
			array(
				'home_score' => $wins[0],
				'away_score' => $wins[1],
			),
			array( 'id' => $fixture_id )
		);
		return $ids;
	}

	/**
	 * Cup-Begegnung mit fünf Spielen; nach dem dritten Sieg zählen weitere Spiele nicht.
	 *
	 * @param array<string,int> $home    Slot (A, B) => Spieler-ID.
	 * @param array<string,int> $away    Slot (Y, Z) => Spieler-ID.
	 * @param list<string>      $results Fünf Resultate.
	 * @return list<int>
	 */
	public function cup_match( int $fixture_id, string $played_at, array $home, array $away, array $results ): array {
		$this->lineup( $fixture_id, $home, $away, array() );
		$ids  = array();
		$wins = array( 0, 0 );
		foreach ( GameOrder::cup() as $i => $slot ) {
			if ( GameOrder::DOUBLE === $slot['type'] ) {
				$a = array( $home['A'] ?? null, $home['B'] ?? null );
				$b = array( $away['Y'] ?? null, $away['Z'] ?? null );
			} else {
				$a = array( $home[ $slot['home'] ] ?? null, null );
				$b = array( $away[ $slot['away'] ] ?? null, null );
			}
			$counts = $wins[0] < 3 && $wins[1] < 3;
			$ids[]  = $this->game( $fixture_id, null, $slot['sequence'], $slot['type'], $a, $b, $results[ $i ], $played_at, $counts, $wins );
		}
		$this->db->update(
			'fixture',
			array(
				'home_score' => $wins[0],
				'away_score' => $wins[1],
			),
			array( 'id' => $fixture_id )
		);
		return $ids;
	}

	/**
	 * Partie im Ranglistenturnier («3:1» aus Sicht von A).
	 */
	public function rl_game( int $group_id, int $sequence, int $player_a, int $player_b, string $result, string $played_at ): int {
		$wins = array( 0, 0 );
		return $this->game( 0, $group_id, $sequence, GameOrder::SINGLE, array( $player_a, null ), array( $player_b, null ), $result, $played_at, true, $wins );
	}

	/**
	 * Wertungsverlauf: Startwert und Änderungen pro Spiel.
	 *
	 * @param list<array{0:int,1:float,2:string}> $events Spiel-ID, Differenz, Zeitpunkt.
	 */
	public function rating( int $player_id, float $start, array $events ): void {
		$value = $start;
		foreach ( $events as $event ) {
			$this->db->insert(
				'rating_event',
				array(
					'player_id'     => $player_id,
					'game_id'       => $event[0],
					'rating_before' => $value,
					'rating_after'  => $value + $event[1],
					'delta'         => $event[1],
					'played_at'     => $event[2],
				)
			);
			$value += $event[1];
		}
		$this->db->insert(
			'player_rating',
			array(
				'player_id'   => $player_id,
				'rating'      => $value,
				'games_count' => count( $events ),
				'updated_at'  => '2026-11-01 00:00:00',
			)
		);
	}

	/**
	 * Testszenario mit zwei Saisons. Erwartete Werte (Gruppe A 2026/27, gezählt werden die
	 * Begegnungen 1 bis 5):
	 *
	 * | Rang | Team         | Beg. | S-U-N | Spiele | Sätze | Punkte |
	 * |------|--------------|------|-------|--------|-------|--------|
	 * | 1    | Seefeld 1    | 3    | 2-0-1 | 21:9   | 42:38 | 21     |
	 * | 2    | Bergwiese 1  | 3    | 1-1-1 | 15:15  | 56:50 | 15     |
	 * | 3    | Altstetten 1 | 2    | 1-0-1 | 9:11   | 29:39 | 9      |
	 * | 4    | Lindenhof 1  | 2    | 0-1-1 | 5:15   | 19:19 | 5      |
	 *
	 * Gruppe B: Seefeld 2 und Altstetten 2 trennen sich zweimal 5:5 ohne Einzelresultate,
	 * beide stehen auf Rang 1 (geteilt).
	 *
	 * @return array<string,int> Wichtige IDs nach Name.
	 */
	public function scenario(): array {
		$ids = array();

		$old = $this->season( '2025-26', 'archived', array( 'substitute_max' => 3 ) );
		$new = $this->season( '2026-27', 'active', array( 'substitute_max' => 3 ) );

		$seefeld    = $this->club( 'Seefeld', 901 );
		$altstetten = $this->club( 'Altstetten', 902 );
		$bergwiese  = $this->club( 'Bergwiese', 903 );
		$lindenhof  = $this->club( 'Lindenhof', 904 );

		$v_seefeld    = $this->venue( $seefeld, 'Turnhalle Seefeld', array( 'weekday' => 1 ) );
		$v_altstetten = $this->venue( $altstetten, 'Halle Altstetten', array( 'weekday' => 2 ) );
		$v_bergwiese  = $this->venue( $bergwiese, 'Sporthalle Bergwiese', array( 'weekday' => 3 ) );
		$v_lindenhof  = $this->venue( $lindenhof, 'Schulhaus Lindenhof', array( 'weekday' => 4 ) );

		$players = array(
			'muster'    => array( 'Muster', 'Hans', $seefeld, 12, 11 ),
			'beispiel'  => array( 'Beispiel', 'Anna', $seefeld, 10, 10 ),
			'probe'     => array( 'Probe', 'Lukas', $seefeld, 9, 9 ),
			'vorlage'   => array( 'Vorlage', 'Sara', $seefeld, 7, null ),
			'exempel'   => array( 'Exempel', 'Marco', $altstetten, 11, 11 ),
			'modell'    => array( 'Modell', 'Nina', $altstetten, 10, 10 ),
			'schema'    => array( 'Schema', 'Reto', $altstetten, 8, 8 ),
			'entwurf'   => array( 'Entwurf', 'Petra', $altstetten, 6, null ),
			'skizze'    => array( 'Skizze', 'Urs', $bergwiese, 13, null ),
			'kopie'     => array( 'Kopie', 'Lea', $bergwiese, 9, null ),
			'ablage'    => array( 'Ablage', 'Beat', $bergwiese, 8, null ),
			'notiz'     => array( 'Notiz', 'Carla', $bergwiese, 5, null ),
			'akte'      => array( 'Akte', 'Simon', $lindenhof, 10, null ),
			'tabelle'   => array( 'Tabelle', 'Mia', $lindenhof, 9, null ),
			'formular'  => array( 'Formular', 'Jonas', $lindenhof, 8, null ),
			'muesterli' => array( 'Müsterli', 'Jürg', $lindenhof, 4, null ),
		);
		$p       = array();
		foreach ( $players as $key => $data ) {
			$p[ $key ] = $this->player( $data[0], $data[1] );
			$this->register( $p[ $key ], $new, $data[2], $data[3] );
			if ( null !== $data[4] ) {
				$this->register( $p[ $key ], $old, $data[2], $data[4] );
			}
			$ids[ 'player_' . $key ] = $p[ $key ];
		}

		// Saison 2026/27.
		$group_a = $this->competition( $new, 'MM', 'Gruppe A', 'A', 1, 1 );
		$group_b = $this->competition( $new, 'MM', 'Gruppe B', 'B', 1, 2 );
		$t_see   = $this->team( $new, $seefeld, 1, 'Seefeld 1', $group_a, $v_seefeld );
		$t_alt   = $this->team( $new, $altstetten, 1, 'Altstetten 1', $group_a, $v_altstetten, '19:30:00' );
		$t_berg  = $this->team( $new, $bergwiese, 1, 'Bergwiese 1', $group_a, $v_bergwiese );
		$t_lind  = $this->team( $new, $lindenhof, 1, 'Lindenhof 1', $group_a, $v_lindenhof );
		$t_see2  = $this->team( $new, $seefeld, 2, 'Seefeld 2', $group_b, $v_seefeld );
		$t_alt2  = $this->team( $new, $altstetten, 2, 'Altstetten 2', $group_b, $v_altstetten );
		$this->roster( $t_see, array( $p['muster'], $p['beispiel'], $p['probe'] ) );
		$this->roster( $t_alt, array( $p['exempel'], $p['modell'], $p['schema'] ) );
		$this->roster( $t_berg, array( $p['skizze'], $p['kopie'], $p['ablage'] ) );
		$this->roster( $t_lind, array( $p['akte'], $p['tabelle'], $p['formular'] ) );

		$this->role( $new, $seefeld, $this->person_of( $p['muster'] ), 'tk_chef' );
		$this->role( $new, $seefeld, $this->person_of( $p['beispiel'] ), 'captain', $t_see );
		$this->role( $new, $altstetten, $this->person( 'Kontakt', 'Karl' ), 'captain', $t_alt );

		$ids += array(
			'season_old'   => $old,
			'season_new'   => $new,
			'club_seefeld' => $seefeld,
			'club_alt'     => $altstetten,
			'group_a'      => $group_a,
			'group_b'      => $group_b,
			'team_see'     => $t_see,
			'team_alt'     => $t_alt,
			'team_berg'    => $t_berg,
			'team_lind'    => $t_lind,
		);

		$base = static fn( int $home, int $away, string $at, string $status, int $no, int $round ): array => array(
			'home_team_id' => $home,
			'away_team_id' => $away,
			'scheduled_at' => $at,
			'status'       => $status,
			'match_no'     => $no,
			'round'        => $round,
			'leg'          => 1,
		);

		// 1: Seefeld 1 – Altstetten 1, 7:3; Vorlage spielt als Ersatz auf C.
		$f1               = $this->fixture( $group_a, $base( $t_see, $t_alt, '2026-09-08 20:00:00', 'final', 1, 1 ) );
		$f1_games         = $this->team_match(
			$f1,
			'2026-09-08 20:00:00',
			array(
				'A' => $p['muster'],
				'B' => $p['beispiel'],
				'C' => $p['vorlage'],
			),
			array(
				'X' => $p['exempel'],
				'Y' => $p['modell'],
				'Z' => $p['schema'],
			),
			array( '3:1', '3:2', '1:3', '0:3', '3:0', '3:1', '3:2', '3:1', '2:3', '3:0' ),
			array( $p['vorlage'] )
		);
		$ids['fixture_1'] = $f1;

		// 2: Bergwiese 1 – Lindenhof 1, 5:5.
		$f2 = $this->fixture( $group_a, $base( $t_berg, $t_lind, '2026-09-09 20:00:00', 'final', 2, 1 ) );
		$this->team_match(
			$f2,
			'2026-09-09 20:00:00',
			array(
				'A' => $p['skizze'],
				'B' => $p['kopie'],
				'C' => $p['ablage'],
			),
			array(
				'X' => $p['akte'],
				'Y' => $p['tabelle'],
				'Z' => $p['formular'],
			),
			array( '3:0', '0:3', '3:1', '1:3', '3:2', '2:3', '3:1', '0:3', '3:0', '1:3' )
		);

		// 3: Altstetten 1 – Bergwiese 1, 6:4; Bergwiese nur zu zweit (Z fehlt: drei Spiele kampflos).
		$f3 = $this->fixture( $group_a, $base( $t_alt, $t_berg, '2026-09-15 19:30:00', 'final', 3, 2 ) );
		$this->team_match(
			$f3,
			'2026-09-15 19:30:00',
			array(
				'A' => $p['exempel'],
				'B' => $p['modell'],
				'C' => $p['schema'],
			),
			array(
				'X' => $p['skizze'],
				'Y' => $p['kopie'],
			),
			array( '1:3', '3:2', 'wo:h', '0:3', 'wo:h', '3:1', '2:3', 'wo:h', '1:3', '3:0' )
		);
		$ids['fixture_3'] = $f3;

		// 4: Lindenhof 1 – Seefeld 1, Forfait 0:10.
		$ids['fixture_4'] = $this->fixture(
			$group_a,
			$base( $t_lind, $t_see, '2026-09-17 20:00:00', 'forfeit', 4, 2 ) + array(
				'home_score' => 0,
				'away_score' => 10,
			)
		);

		// 5: Seefeld 1 – Bergwiese 1, 4:6, provisorisch.
		$f5               = $this->fixture( $group_a, $base( $t_see, $t_berg, '2026-10-05 20:00:00', 'provisional', 5, 3 ) );
		$f5_games         = $this->team_match(
			$f5,
			'2026-10-05 20:00:00',
			array(
				'A' => $p['muster'],
				'B' => $p['beispiel'],
				'C' => $p['probe'],
			),
			array(
				'X' => $p['skizze'],
				'Y' => $p['kopie'],
				'Z' => $p['ablage'],
			),
			array( '2:3', '3:1', '3:2', '1:3', '3:1', '0:3', '1:3', '3:0', '0:3', '2:3' )
		);
		$ids['fixture_5'] = $f5;

		// 6: verschoben, 7 und 8: angesetzt.
		$ids['fixture_6'] = $this->fixture( $group_a, $base( $t_alt, $t_lind, '2026-10-06 19:30:00', 'postponed', 6, 3 ) );
		$ids['fixture_7'] = $this->fixture( $group_a, $base( $t_berg, $t_see, '2026-12-02 20:00:00', 'scheduled', 7, 4 ) + array( 'venue_id' => $v_bergwiese ) );
		$ids['fixture_8'] = $this->fixture( $group_a, $base( $t_lind, $t_alt, '2026-12-03 20:00:00', 'scheduled', 8, 4 ) );

		// Gruppe B: zweimal 5:5 ohne Einzelresultate.
		$this->fixture(
			$group_b,
			$base( $t_see2, $t_alt2, '2026-09-10 20:00:00', 'final', 1, 1 ) + array(
				'home_score' => 5,
				'away_score' => 5,
			)
		);
		$ids['fixture_b2'] = $this->fixture(
			$group_b,
			$base( $t_alt2, $t_see2, '2026-10-10 20:00:00', 'final', 2, 2 ) + array(
				'home_score' => 5,
				'away_score' => 5,
			)
		);

		// Cup A 2026/27: Seefeld 1 – Bergwiese 1, 3:1 (Spiel 5 nach der Entscheidung).
		$cup_a              = $this->competition( $new, 'CUP', 'Mannschaftscup A', 'A', null, 1 );
		$e_see              = $this->db->insert(
			'cup_entry',
			array(
				'competition_id' => $cup_a,
				'club_id'        => $seefeld,
				'number'         => 1,
				'name'           => 'Seefeld 1',
				'player1_id'     => $p['muster'],
				'player2_id'     => $p['beispiel'],
				'points_total'   => 22,
				'seed'           => 1,
			)
		);
		$e_berg             = $this->db->insert(
			'cup_entry',
			array(
				'competition_id' => $cup_a,
				'club_id'        => $bergwiese,
				'number'         => 1,
				'name'           => 'Bergwiese 1',
				'player1_id'     => $p['skizze'],
				'player2_id'     => $p['kopie'],
				'points_total'   => 22,
				'seed'           => 2,
			)
		);
		$cup_fix            = $this->fixture(
			$cup_a,
			array(
				'home_entry_id' => $e_see,
				'away_entry_id' => $e_berg,
				'scheduled_at'  => '2026-10-20 20:00:00',
				'status'        => 'final',
				'round'         => 1,
				'match_no'      => 301,
				'venue_id'      => $v_seefeld,
			)
		);
		$cup_games          = $this->cup_match(
			$cup_fix,
			'2026-10-20 20:00:00',
			array(
				'A' => $p['muster'],
				'B' => $p['beispiel'],
			),
			array(
				'Y' => $p['skizze'],
				'Z' => $p['kopie'],
			),
			array( '1:3', '3:1', '3:2', '3:0', '0:3' )
		);
		$ids['cup_a']       = $cup_a;
		$ids['cup_fixture'] = $cup_fix;

		// Ranglistenturnier 2026/27: Runde 1 gespielt, Runde 2 angesetzt.
		$rl      = $this->competition( $new, 'RL', 'Ranglistenturnier' );
		$round_1 = $this->db->insert(
			'rl_round',
			array(
				'competition_id' => $rl,
				'round_no'       => 1,
				'date_from'      => '2026-09-21',
				'date_to'        => '2026-09-24',
			)
		);
		$round_2 = $this->db->insert(
			'rl_round',
			array(
				'competition_id' => $rl,
				'round_no'       => 2,
				'date_from'      => '2026-11-16',
				'date_to'        => '2026-11-19',
			)
		);
		$group_1 = $this->db->insert(
			'rl_group',
			array(
				'rl_round_id'  => $round_1,
				'category'     => 1,
				'group_no'     => 1,
				'host_club_id' => $bergwiese,
				'venue_id'     => $v_bergwiese,
				'start_at'     => '2026-09-23 19:15:00',
				'note'         => 'Mittwoch, 23. September, 19:15 bei Bergwiese',
			)
		);
		$group_2 = $this->db->insert(
			'rl_group',
			array(
				'rl_round_id'  => $round_2,
				'category'     => 1,
				'group_no'     => 1,
				'host_club_id' => $seefeld,
				'start_at'     => '2026-11-18 19:15:00',
				'note'         => 'Mittwoch, 18. November, 19:15 bei Seefeld',
			)
		);
		foreach ( array( 'muster', 'exempel', 'skizze', 'akte' ) as $key ) {
			$this->db->insert(
				'rl_group_player',
				array(
					'rl_group_id' => $group_1,
					'player_id'   => $p[ $key ],
					'status'      => 'played',
				)
			);
			$this->db->insert(
				'rl_group_player',
				array(
					'rl_group_id' => $group_2,
					'player_id'   => $p[ $key ],
					'status'      => 'played',
				)
			);
			$this->db->insert(
				'rl_participant',
				array(
					'competition_id' => $rl,
					'player_id'      => $p[ $key ],
					'category'       => 1,
				)
			);
		}
		$at                = '2026-09-23 19:15:00';
		$rl_muster         = array(
			$this->rl_game( $group_1, 1, $p['muster'], $p['exempel'], '3:1', $at ),
			$this->rl_game( $group_1, 2, $p['skizze'], $p['akte'], '3:0', $at ),
			$this->rl_game( $group_1, 3, $p['muster'], $p['skizze'], '1:3', $at ),
			$this->rl_game( $group_1, 4, $p['exempel'], $p['akte'], '3:2', $at ),
			$this->rl_game( $group_1, 5, $p['muster'], $p['akte'], '3:0', $at ),
			$this->rl_game( $group_1, 6, $p['exempel'], $p['skizze'], '0:3', $at ),
		);
		$ids['rl_group_1'] = $group_1;

		// Saison 2025/26: eine Begegnung Seefeld 1 – Altstetten 1, 6:4.
		$old_group = $this->competition( $old, 'MM', 'Gruppe A', 'A', 1, 1 );
		$old_see   = $this->team( $old, $seefeld, 1, 'Seefeld 1', $old_group, $v_seefeld );
		$old_alt   = $this->team( $old, $altstetten, 1, 'Altstetten 1', $old_group, $v_altstetten );
		$this->roster( $old_see, array( $p['muster'], $p['beispiel'], $p['probe'] ) );
		$this->roster( $old_alt, array( $p['exempel'], $p['modell'], $p['schema'] ) );
		$old_fix             = $this->fixture( $old_group, $base( $old_see, $old_alt, '2026-03-10 20:00:00', 'final', 1, 1 ) );
		$old_games           = $this->team_match(
			$old_fix,
			'2026-03-10 20:00:00',
			array(
				'A' => $p['muster'],
				'B' => $p['beispiel'],
				'C' => $p['probe'],
			),
			array(
				'X' => $p['exempel'],
				'Y' => $p['modell'],
				'Z' => $p['schema'],
			),
			array( '0:3', '3:1', '3:0', '1:3', '3:2', '3:1', '1:3', '2:3', '3:0', '3:1' )
		);
		$ids['team_old_see'] = $old_see;
		$ids['fixture_old']  = $old_fix;

		// Wertungsverlauf für Muster Hans (Einzel und Doppel der Meisterschaft).
		$this->rating(
			$p['muster'],
			1100.0,
			array(
				array( $old_games[0], -8.5, '2026-03-10 20:00:00' ),
				array( $old_games[4], 6.0, '2026-03-10 20:00:00' ),
				array( $old_games[9], 7.25, '2026-03-10 20:00:00' ),
				array( $f1_games[0], 9.0, '2026-09-08 20:00:00' ),
				array( $f1_games[6], 3.0, '2026-09-08 20:00:00' ),
				array( $rl_muster[0], 8.0, '2026-09-23 19:15:00' ),
				array( $f5_games[0], -10.0, '2026-10-05 20:00:00' ),
				array( $cup_games[0], -9.75, '2026-10-20 20:00:00' ),
			)
		);

		return $ids;
	}

	/**
	 * Legt die Aufstellung an.
	 *
	 * @param array<string,int> $home Slot => Spieler.
	 * @param array<string,int> $away Slot => Spieler.
	 * @param list<int>         $subs Ersatzspieler.
	 */
	private function lineup( int $fixture_id, array $home, array $away, array $subs ): void {
		foreach ( array(
			'home' => $home,
			'away' => $away,
		) as $side => $slots ) {
			foreach ( $slots as $slot => $player_id ) {
				$this->db->insert(
					'lineup',
					array(
						'fixture_id'    => $fixture_id,
						'side'          => $side,
						'slot'          => $slot,
						'player_id'     => $player_id,
						'is_substitute' => in_array( $player_id, $subs, true ) ? 1 : 0,
					)
				);
			}
		}
	}

	/**
	 * Legt ein Spiel an und zählt den Sieg in $wins (Heim, Gast), falls es für das Resultat zählt.
	 *
	 * @param array{0:?int,1:?int} $side_a Spieler A1, A2.
	 * @param array{0:?int,1:?int} $side_b Spieler B1, B2.
	 * @param array{0:int,1:int}   $wins   Siege (Referenz).
	 */
	private function game( int $fixture_id, ?int $group_id, int $sequence, string $type, array $side_a, array $side_b, string $result, string $played_at, bool $counts, array &$wins ): int {
		$status = 'played';
		if ( 'np' === $result ) {
			$status = 'not_played';
			$sets   = array( 0, 0 );
		} elseif ( str_starts_with( $result, 'wo:' ) ) {
			$status = 'walkover';
			$sets   = 'wo:h' === $result ? array( 3, 0 ) : array( 0, 3 );
		} else {
			$sets = array_map( 'intval', explode( ':', $result ) );
		}
		if ( $counts && $sets[0] !== $sets[1] ) {
			++$wins[ $sets[0] > $sets[1] ? 0 : 1 ];
		}
		return $this->db->insert(
			'game',
			array(
				'fixture_id'        => null === $group_id ? $fixture_id : null,
				'rl_group_id'       => $group_id,
				'sequence'          => $sequence,
				'type'              => $type,
				'a1_player_id'      => $side_a[0],
				'a2_player_id'      => $side_a[1],
				'b1_player_id'      => $side_b[0],
				'b2_player_id'      => $side_b[1],
				'sets_a'            => $sets[0],
				'sets_b'            => $sets[1],
				'status'            => $status,
				'counts_for_result' => $counts ? 1 : 0,
				'played_at'         => $played_at,
			)
		);
	}
}
