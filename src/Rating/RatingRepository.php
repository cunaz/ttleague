<?php
/**
 * Lädt Spiele und Klassierungen für die Wertungszahl und speichert die Ergebnisse.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Rating;

use TTLeague\Domain\Rating\RatingGame;
use TTLeague\Domain\Rating\RatingResult;
use TTLeague\Infrastructure\Database\Db;

final class RatingRepository {

	/** Begegnungsstatus, deren Spiele in die Wertungszahl eingehen. */
	private const COUNTING_STATUS = array( 'provisional', 'confirmed', 'disputed', 'final' );

	public function __construct( private readonly Db $db ) {}

	/**
	 * Alle gespielten Einzel und Doppel aus Meisterschaft, Cup und Ranglistenturnier,
	 * chronologisch. Kampflose und nicht gespielte Spiele sowie Doppel mit unbekanntem
	 * Spieler zählen nicht.
	 *
	 * @return list<RatingGame>
	 */
	public function games(): array {
		$g      = $this->db->table( 'game' );
		$f      = $this->db->table( 'fixture' );
		$c      = $this->db->table( 'competition' );
		$rg     = $this->db->table( 'rl_group' );
		$rr     = $this->db->table( 'rl_round' );
		$status = "'" . implode( "','", self::COUNTING_STATUS ) . "'";

		$rows = $this->db->get_results(
			"SELECT g.id, COALESCE(g.played_at, f.scheduled_at) AS played_at, c.season_id, c.type AS competition,
				g.a1_player_id, g.a2_player_id, g.b1_player_id, g.b2_player_id, g.sets_a, g.sets_b, g.type
			FROM $g g JOIN $f f ON f.id = g.fixture_id JOIN $c c ON c.id = f.competition_id
			WHERE g.status = 'played' AND f.status IN ($status)
			UNION ALL
			SELECT g.id, COALESCE(g.played_at, CONCAT(r.date_from, ' 20:00:00')) AS played_at, c.season_id, 'RL' AS competition,
				g.a1_player_id, g.a2_player_id, g.b1_player_id, g.b2_player_id, g.sets_a, g.sets_b, g.type
			FROM $g g JOIN $rg rg ON rg.id = g.rl_group_id JOIN $rr r ON r.id = rg.rl_round_id JOIN $c c ON c.id = r.competition_id
			WHERE g.status = 'played'
			ORDER BY played_at, id"
		);

		$games = array();
		foreach ( $rows as $row ) {
			if ( null === $row['played_at'] || (int) $row['sets_a'] === (int) $row['sets_b'] ) {
				continue;
			}
			$double = 'double' === $row['type'];
			$a      = $double ? array( $row['a1_player_id'], $row['a2_player_id'] ) : array( $row['a1_player_id'] );
			$b      = $double ? array( $row['b1_player_id'], $row['b2_player_id'] ) : array( $row['b1_player_id'] );
			if ( in_array( null, $a, true ) || in_array( null, $b, true ) ) {
				continue;
			}
			$a = array_map( 'intval', $a );
			$b = array_map( 'intval', $b );
			if ( array() !== array_intersect( $a, $b ) || count( array_unique( $a ) ) !== count( $a ) || count( array_unique( $b ) ) !== count( $b ) ) {
				continue;
			}
			$games[] = new RatingGame(
				(int) $row['id'],
				(string) $row['played_at'],
				(int) $row['season_id'],
				$a,
				$b,
				(int) $row['sets_a'] > (int) $row['sets_b'],
				(string) $row['competition']
			);
		}
		return $games;
	}

	/**
	 * @return array<int,array<int,int>> Saison => Spieler => Klassierung.
	 */
	public function classifications(): array {
		$map = array();
		foreach ( $this->db->get_results( "SELECT season_id, player_id, classification FROM {$this->db->table( 'player_season' )}" ) as $row ) {
			$map[ (int) $row['season_id'] ][ (int) $row['player_id'] ] = (int) $row['classification'];
		}
		return $map;
	}

	/**
	 * @return list<int> Saison-IDs in zeitlicher Reihenfolge.
	 */
	public function season_order(): array {
		return array_map( 'intval', $this->db->get_col( "SELECT id FROM {$this->db->table( 'season' )} ORDER BY start_date" ) );
	}

	/**
	 * Ersetzt alle gespeicherten Wertungszahlen und Ereignisse.
	 */
	public function save( RatingResult $result ): void {
		$this->db->transaction(
			function ( Db $db ) use ( $result ): void {
				$db->query( "DELETE FROM {$db->table( 'rating_event' )}" );
				$db->query( "DELETE FROM {$db->table( 'player_rating' )}" );
				$now  = $db->now();
				$rows = array();
				foreach ( $result->ratings as $player => $rating ) {
					$rows[] = array(
						'player_id'   => (int) $player,
						'rating'      => round( $rating, 2 ),
						'games_count' => (int) ( $result->games[ $player ] ?? 0 ),
						'updated_at'  => $now,
					);
				}
				$db->insert_many( 'player_rating', $rows );
				$rows = array();
				foreach ( $result->events as $event ) {
					$rows[] = array(
						'player_id'     => $event->player_id,
						'game_id'       => $event->game_id,
						'rating_before' => round( $event->before, 2 ),
						'rating_after'  => round( $event->after, 2 ),
						'delta'         => round( $event->delta(), 2 ),
						'played_at'     => $event->played_at,
					);
				}
				$db->insert_many( 'rating_event', $rows );
			}
		);
	}
}
