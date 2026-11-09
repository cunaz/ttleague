<?php
/**
 * Schlanke Abfragen für Benachrichtigungen und Fristen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

use TTLeague\Domain\Match\FixtureWorkflow as Flow;
use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Infrastructure\Database\Db;

/**
 * Nur Meisterschaft und Cup der aktiven Saison. Spieltermine (scheduled_at) sind Ortszeit,
 * entered_at und created_at UTC.
 */
final class FixtureQueries {

	/**
	 * @param Db                                                  $db      Datenbank.
	 * @param (\Closure(\InvalidArgumentException,int):void)|null $invalid Meldet eine Saison mit ungültigen Regeln (Fehler, Saison).
	 */
	public function __construct(
		private readonly Db $db,
		private readonly ?\Closure $invalid = null,
	) {}

	/**
	 * Aktive Saisons mit ihren Regeln. Eine Saison mit ungültigen Regeln wird gemeldet und
	 * übersprungen: Sie soll den Lauf für die übrigen Saisons nicht abbrechen.
	 *
	 * @return list<array{id:int,rules:SeasonRules}>
	 */
	public function active_seasons(): array {
		$seasons = array();
		foreach ( $this->db->get_results( "SELECT id, rules_json FROM {$this->db->table( 'season' )} WHERE status = 'active' ORDER BY start_date DESC, id DESC" ) as $row ) {
			$data = json_decode( (string) $row['rules_json'], true );
			try {
				$rules = SeasonRules::from_array( is_array( $data ) ? $data : array() );
			} catch ( \InvalidArgumentException $e ) {
				if ( null !== $this->invalid ) {
					( $this->invalid )( $e, (int) $row['id'] );
				}
				continue;
			}
			$seasons[] = array(
				'id'    => (int) $row['id'],
				'rules' => $rules,
			);
		}
		return $seasons;
	}

	/**
	 * Offene Begegnungen (angesetzt oder verschoben) mit Termin im Zeitraum (Ortszeit, Grenzen eingeschlossen).
	 *
	 * @return list<array{id:int,scheduled_at:string}>
	 */
	public function open_between( int $season_id, string $from_local, string $to_local ): array {
		return $this->scheduled( $season_id, 'f.scheduled_at >= %s AND f.scheduled_at <= %s', array( $from_local, $to_local ) );
	}

	/**
	 * Offene Begegnungen ohne Resultat mit Termin bis zum Zeitpunkt (Ortszeit).
	 *
	 * @return list<array{id:int,scheduled_at:string}>
	 */
	public function open_until( int $season_id, string $until_local ): array {
		return $this->scheduled( $season_id, 'f.scheduled_at <= %s', array( $until_local ) );
	}

	/**
	 * Provisorische Resultate mit Zeitpunkt der letzten Erfassung (UTC).
	 *
	 * @return list<array{id:int,entered_at:string}>
	 */
	public function provisional( int $season_id ): array {
		$rows = $this->db->get_results(
			"SELECT f.id, f.entered_at FROM {$this->db->table( 'fixture' )} f
			JOIN {$this->db->table( 'competition' )} c ON c.id = f.competition_id
			WHERE c.season_id = %d AND c.type IN ('MM', 'CUP') AND f.status = %s AND f.entered_at IS NOT NULL
			ORDER BY f.entered_at, f.id",
			array( $season_id, Flow::PROVISIONAL )
		);
		return array_map(
			static fn( array $row ): array => array(
				'id'         => (int) $row['id'],
				'entered_at' => (string) $row['entered_at'],
			),
			$rows
		);
	}

	/**
	 * Begegnungen mit offener Einsprache.
	 *
	 * @return list<int>
	 */
	public function disputed( int $season_id ): array {
		return array_map(
			'intval',
			$this->db->get_col(
				"SELECT f.id FROM {$this->db->table( 'fixture' )} f
				JOIN {$this->db->table( 'competition' )} c ON c.id = f.competition_id
				WHERE c.season_id = %d AND c.type IN ('MM', 'CUP') AND f.status = %s
				ORDER BY f.scheduled_at, f.id",
				array( $season_id, Flow::DISPUTED )
			)
		);
	}

	/**
	 * Letzter Verlaufseintrag eines der Typen.
	 *
	 * @param list<string> $types Typen, z. B. entered, edited.
	 * @return array{id:int,type:string,user_id:int|null,person_id:int|null,comment:string|null,data:array<string,mixed>,created_at:string}|null
	 */
	public function latest_event( int $fixture_id, array $types ): ?array {
		$types = array_values( array_filter( $types, static fn( string $t ): bool => 1 === preg_match( '/^[a-z_]+$/', $t ) ) );
		if ( array() === $types ) {
			return null;
		}
		$row = $this->db->get_row(
			"SELECT id, type, user_id, person_id, comment, data_json, created_at FROM {$this->db->table( 'fixture_event' )}
			WHERE fixture_id = %d AND type IN ('" . implode( "','", $types ) . "') ORDER BY id DESC LIMIT 1", // Nur geprüfte Kleinbuchstaben.
			array( $fixture_id )
		);
		if ( null === $row ) {
			return null;
		}
		$data = json_decode( (string) $row['data_json'], true );
		return array(
			'id'         => (int) $row['id'],
			'type'       => (string) $row['type'],
			'user_id'    => null === $row['user_id'] ? null : (int) $row['user_id'],
			'person_id'  => null === $row['person_id'] ? null : (int) $row['person_id'],
			'comment'    => $row['comment'],
			'data'       => is_array( $data ) ? $data : array(),
			'created_at' => (string) $row['created_at'],
		);
	}

	/**
	 * Spiellokal der Begegnung (eigenes oder das Heimlokal des Teams).
	 *
	 * @return array{name:string,street:string|null,zip:string|null,city:string|null,open_time:string|null}|null
	 */
	public function venue( int $fixture_id ): ?array {
		$row = $this->db->get_row(
			"SELECT v.name, v.street, v.zip, v.city, v.open_time FROM {$this->db->table( 'fixture' )} f
			LEFT JOIN {$this->db->table( 'team' )} ht ON ht.id = f.home_team_id
			JOIN {$this->db->table( 'venue' )} v ON v.id = COALESCE(f.venue_id, ht.home_venue_id)
			WHERE f.id = %d",
			array( $fixture_id )
		);
		if ( null === $row ) {
			return null;
		}
		return array(
			'name'      => (string) $row['name'],
			'street'    => $row['street'],
			'zip'       => $row['zip'],
			'city'      => $row['city'],
			'open_time' => $row['open_time'],
		);
	}

	/**
	 * @param string                      $where Zusätzliche Bedingung.
	 * @param list<int|float|string|null> $args  Werte der Bedingung.
	 * @return list<array{id:int,scheduled_at:string}>
	 */
	private function scheduled( int $season_id, string $where, array $args ): array {
		$rows = $this->db->get_results(
			"SELECT f.id, f.scheduled_at FROM {$this->db->table( 'fixture' )} f
			JOIN {$this->db->table( 'competition' )} c ON c.id = f.competition_id
			WHERE c.season_id = %d AND c.type IN ('MM', 'CUP') AND f.status IN (%s, %s) AND f.scheduled_at IS NOT NULL AND {$where}
			ORDER BY f.scheduled_at, f.id",
			array_merge( array( $season_id, Flow::SCHEDULED, Flow::POSTPONED ), $args )
		);
		return array_map(
			static fn( array $row ): array => array(
				'id'           => (int) $row['id'],
				'scheduled_at' => (string) $row['scheduled_at'],
			),
			$rows
		);
	}
}
