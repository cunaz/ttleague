<?php
/**
 * Erfundene Liga für die Tests des Saison-Assistenten (Integration und WordPress).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Season;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\Tests\Integration\Frontend\Seeder;

/**
 * Clubs Seefeld, Wollishofen, Oerlikon und Altstetten. league() legt die aktive Saison 2026/27
 * an: Gruppe A mit Seefeld 1, Wollishofen 1 und Oerlikon 1, Gruppe B mit Seefeld 2,
 * Altstetten 1 und Wollishofen 2. Jeder Club hat ein Lokal (Seefeld und Altstetten am Dienstag,
 * Wollishofen am Mittwoch, Oerlikon am Donnerstag), eine TK-Chefin oder einen TK-Chef und je
 * Mannschaft einen Captain. register() meldet dieselben Mannschaften für eine geplante Saison.
 */
final class SeasonLeague {

	/** Schlüssel => Club, Nummer, Kategorie, Anspielzeit. */
	public const TEAMS = array(
		'seefeld_1'     => array( 'seefeld', 1, 'A', '20:00:00' ),
		'wollishofen_1' => array( 'wollishofen', 1, 'A', '19:30:00' ),
		'oerlikon_1'    => array( 'oerlikon', 1, 'A', '20:00:00' ),
		'seefeld_2'     => array( 'seefeld', 2, 'B', '20:00:00' ),
		'altstetten_1'  => array( 'altstetten', 1, 'B', '19:45:00' ),
		'wollishofen_2' => array( 'wollishofen', 2, 'B', '19:30:00' ),
	);

	private Seeder $seed;

	public function __construct( private readonly Db $db ) {
		$this->seed = new Seeder( $db );
	}

	/**
	 * Aktive Saison 2026/27.
	 *
	 * @param int|null $tables Tische pro Lokal; null: unbekannt.
	 * @return array<string,int> IDs: active, club_<club>, venue_<club>, chef_<club>, group_a, group_b, <team>, captain_<team>.
	 */
	public function league( ?int $tables = 4 ): array {
		$s      = $this->seed;
		$season = $s->season( '2026-27', 'active', array( 'substitute_max' => 2 ) );
		$id     = array( 'active' => $season );
		$clubs  = array(
			'seefeld'     => array( 'Seefeld', 1, 2, 'Chef', 'Charlotte' ),
			'wollishofen' => array( 'Wollishofen', 2, 3, 'Wolli', 'Walter' ),
			'oerlikon'    => array( 'Oerlikon', 3, 4, 'Oerli', 'Olga' ),
			'altstetten'  => array( 'Altstetten', 4, 2, 'Alt', 'Alma' ),
		);
		foreach ( $clubs as $key => [ $name, $number, $weekday, $last, $first ] ) {
			$club                  = $s->club( $name, $number );
			$id[ 'club_' . $key ]  = $club;
			$id[ 'venue_' . $key ] = $s->venue(
				$club,
				'Halle ' . $name,
				array(
					'weekday'      => $weekday,
					'tables_count' => $tables,
				)
			);
			$id[ 'chef_' . $key ]  = $s->person( $last, $first );
			$s->role( $season, $club, $id[ 'chef_' . $key ], 'tk_chef' );
		}
		$id['group_a'] = $s->competition( $season, 'MM', 'Gruppe A', 'A', 1, 1 );
		$id['group_b'] = $s->competition( $season, 'MM', 'Gruppe B', 'B', 1, 2 );
		foreach ( self::TEAMS as $key => [ $club, $number, $category, $start ] ) {
			$team                    = $s->team(
				$season,
				$id[ 'club_' . $club ],
				$number,
				ucfirst( $club ) . ' ' . $number,
				$id[ 'group_' . strtolower( $category ) ],
				$id[ 'venue_' . $club ],
				$start
			);
			$id[ $key ]              = $team;
			$id[ 'captain_' . $key ] = $s->person( 'Captain', ucfirst( $club ) . $number );
			$s->role( $season, $id[ 'club_' . $club ], $id[ 'captain_' . $key ], 'captain', $team );
		}
		return $id;
	}

	/**
	 * Mannschaften der Liga für die geplante Saison melden, mit Heimlokal und Anspielzeit, und
	 * alle Meldungen der Saison auf «geprüft» setzen, wie es Clubs und TK über die Meldung täten.
	 *
	 * @param int               $season_id Geplante Saison.
	 * @param array<string,int> $id        IDs aus league().
	 * @param list<string>|null $teams     Schlüssel aus TEAMS; null: alle.
	 * @return array<string,int> IDs: next und next_<team>.
	 */
	public function register( int $season_id, array $id, ?array $teams = null ): array {
		$new = array( 'next' => $season_id );
		foreach ( self::TEAMS as $key => [ $club, $number, , $start ] ) {
			if ( null !== $teams && ! in_array( $key, $teams, true ) ) {
				continue;
			}
			$new[ 'next_' . $key ] = $this->seed->team( $season_id, $id[ 'club_' . $club ], $number, ucfirst( $club ) . ' ' . $number, null, $id[ 'venue_' . $club ], $start );
		}
		$this->db->update( 'club_season', array( 'status' => 'reviewed' ), array( 'season_id' => $season_id ) );
		return $new;
	}
}
