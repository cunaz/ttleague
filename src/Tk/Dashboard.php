<?php
/**
 * Offene Punkte der aktiven Saison für die Technische Kommission.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tk;

use TTLeague\Domain\Match\FixtureWorkflow as Flow;
use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Results\FixtureContext;

/**
 * Liest die Listen des TK-Dashboards: Einsprachen, fällige Bestätigungen, fehlende Resultate,
 * Forfait-Vorschläge, Hinweise und letzte Entscheide. Jede Liste ist eine einzige Abfrage.
 *
 * Fristen kommen aus den Saisonregeln. Spieltermine (scheduled_at) sind Ortszeit der Website,
 * Erfassungs- und Ereigniszeitpunkte (entered_at, created_at) UTC. Eine Begegnung ohne
 * Resultat steht entweder unter «fehlende Resultate» (ab entry_deadline_hours nach der
 * Anspielzeit) oder unter «Forfait-Vorschläge» (ab forfeit_after_days), nie in beiden.
 *
 * Jede Zeile enthält: id, type, competition, scheduled_at, home, away, status, home_score,
 * away_score, version (für Entscheide ohne Überraschung), since (UTC) und age_hours; dazu je
 * nach Liste reason, by, warnings, event, action, winner, comment.
 */
final class Dashboard {

	/** Hinweise aus so vielen Tagen. */
	public const WARNING_DAYS = 30;

	/** So viele letzte Entscheide. */
	public const DECISION_LIMIT = 20;

	private const OPEN = array( Flow::SCHEDULED, Flow::POSTPONED );

	/** @var callable(list<int>):array<int,string> */
	private $user_names;

	/**
	 * @param callable(list<int>):array<int,string>|null $user_names Anzeigenamen von Konten ohne Person, alle in einem Aufruf.
	 */
	public function __construct(
		private readonly Db $db,
		private readonly Clock $clock,
		private readonly \DateTimeZone $timezone,
		?callable $user_names = null,
	) {
		$this->user_names = $user_names ?? static fn(): array => array();
	}

	/**
	 * Aktive Saison mit ihren Regeln oder null.
	 *
	 * @return array{id:int,name:string,slug:string,rules:SeasonRules}|null
	 */
	public function season(): ?array {
		$row = $this->db->get_row( "SELECT id, name, slug, rules_json FROM {$this->db->table( 'season' )} WHERE status = 'active' ORDER BY start_date DESC LIMIT 1" );
		if ( null === $row ) {
			return null;
		}
		$rules = json_decode( (string) $row['rules_json'], true );
		return array(
			'id'    => (int) $row['id'],
			'name'  => (string) $row['name'],
			'slug'  => (string) $row['slug'],
			'rules' => SeasonRules::from_array( is_array( $rules ) ? $rules : array() ),
		);
	}

	/**
	 * Alle Listen der aktiven Saison.
	 *
	 * @return array{season:array{id:int,name:string,slug:string,rules:SeasonRules}|null,disputed:list<array<string,mixed>>,confirmation_due:list<array<string,mixed>>,missing:list<array<string,mixed>>,forfeit:list<array<string,mixed>>,warnings:list<array<string,mixed>>,decisions:list<array<string,mixed>>}
	 */
	public function overview(): array {
		$season = $this->season();
		if ( null === $season ) {
			return array(
				'season'           => null,
				'disputed'         => array(),
				'confirmation_due' => array(),
				'missing'          => array(),
				'forfeit'          => array(),
				'warnings'         => array(),
				'decisions'        => array(),
			);
		}
		return array(
			'season'           => $season,
			'disputed'         => $this->disputed( $season['id'] ),
			'confirmation_due' => $this->confirmation_due( $season['id'], $season['rules'] ),
			'missing'          => $this->missing_results( $season['id'], $season['rules'] ),
			'forfeit'          => $this->forfeit_proposals( $season['id'], $season['rules'] ),
			'warnings'         => $this->warnings( $season['id'] ),
			'decisions'        => $this->decisions( $season['id'] ),
		);
	}

	/**
	 * Einsprachen mit Begründung (letzte Einsprache der Begegnung), älteste zuerst.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function disputed( int $season_id ): array {
		$rows  = $this->db->get_results(
			"SELECT {$this->columns()}, e.comment, e.created_at AS event_at, e.user_id AS event_user, pe.first_name, pe.last_name
			FROM {$this->db->table( 'fixture' )} f
			{$this->joins()}
			LEFT JOIN {$this->db->table( 'fixture_event' )} e ON e.id = (
				SELECT MAX(d.id) FROM {$this->db->table( 'fixture_event' )} d WHERE d.fixture_id = f.id AND d.type = 'disputed'
			)
			LEFT JOIN {$this->db->table( 'person' )} pe ON pe.id = e.person_id
			WHERE c.season_id = %d AND c.type IN ('MM', 'CUP') AND f.status = %s
			ORDER BY COALESCE(e.created_at, f.entered_at), f.id",
			array( $season_id, Flow::DISPUTED )
		);
		$names = $this->names( $rows );
		$items = array();
		foreach ( $rows as $row ) {
			$item           = $this->item( $row, $this->utc( $row['event_at'] ?? $row['entered_at'] ) );
			$item['reason'] = $row['comment'];
			$item['by']     = $this->actor_name( $row, $names );
			$items[]        = $item;
		}
		return $items;
	}

	/**
	 * Provisorische Resultate, die seit mehr als objection_days auf die Bestätigung warten.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function confirmation_due( int $season_id, SeasonRules $rules ): array {
		$rows = $this->db->get_results(
			"SELECT {$this->columns()}
			FROM {$this->db->table( 'fixture' )} f
			{$this->joins()}
			WHERE c.season_id = %d AND c.type IN ('MM', 'CUP') AND f.status = %s AND f.entered_at < %s
			ORDER BY f.entered_at, f.id",
			array( $season_id, Flow::PROVISIONAL, $this->utc_ago( $rules->objection_days . ' days' ) )
		);
		return array_map( fn( array $row ): array => $this->item( $row, $this->utc( $row['entered_at'] ) ), $rows );
	}

	/**
	 * Angesetzte oder verschobene Begegnungen, deren Frist zum Nachtragen (entry_deadline_hours
	 * nach Anspielzeit) abgelaufen ist; ohne die Forfait-Vorschläge.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function missing_results( int $season_id, SeasonRules $rules ): array {
		return $this->without_result(
			$season_id,
			'f.scheduled_at < %s AND f.scheduled_at >= %s',
			array( $this->local_hours_ago( $rules->entry_deadline_hours ), $this->local_days_ago( $rules->forfeit_after_days ) )
		);
	}

	/**
	 * Angesetzte oder verschobene Begegnungen ohne Resultat, forfeit_after_days nach Anspielzeit.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function forfeit_proposals( int $season_id, SeasonRules $rules ): array {
		return $this->without_result( $season_id, 'f.scheduled_at < %s', array( $this->local_days_ago( $rules->forfeit_after_days ) ) );
	}

	/**
	 * Ereignisse mit Hinweisen (data.warnings) der letzten Tage, neueste zuerst. Hinweise, die eine
	 * neuere Erfassung derselben Begegnung wiederholt, erscheinen nur einmal.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function warnings( int $season_id, int $days = self::WARNING_DAYS ): array {
		$rows  = $this->db->get_results(
			"SELECT {$this->columns()}, e.id AS event_id, e.type AS event_type, e.data_json, e.created_at AS event_at, e.user_id AS event_user, pe.first_name, pe.last_name
			FROM {$this->db->table( 'fixture_event' )} e
			JOIN {$this->db->table( 'fixture' )} f ON f.id = e.fixture_id
			{$this->joins()}
			LEFT JOIN {$this->db->table( 'person' )} pe ON pe.id = e.person_id
			WHERE c.season_id = %d AND c.type IN ('MM', 'CUP') AND e.created_at >= %s AND e.data_json LIKE %s
			ORDER BY e.created_at DESC, e.id DESC",
			array( $season_id, $this->utc_ago( $days . ' days' ), '%"warnings":["%' )
		);
		$names = $this->names( $rows );
		$seen  = array();
		$items = array();
		foreach ( $rows as $row ) {
			$data     = json_decode( (string) $row['data_json'], true );
			$warnings = array();
			foreach ( (array) ( is_array( $data ) ? ( $data['warnings'] ?? array() ) : array() ) as $warning ) {
				$key = $row['id'] . '|' . (string) $warning;
				if ( is_string( $warning ) && '' !== $warning && ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = true;
					$warnings[]   = $warning;
				}
			}
			if ( array() === $warnings ) {
				continue;
			}
			$item             = $this->item( $row, $this->utc( $row['event_at'] ) );
			$item['event']    = (string) $row['event_type'];
			$item['action']   = is_array( $data ) && isset( $data['action'] ) ? (string) $data['action'] : null;
			$item['warnings'] = $warnings;
			$item['by']       = $this->actor_name( $row, $names );
			$items[]          = $item;
		}
		return $items;
	}

	/**
	 * Letzte Entscheide der TK (bestätigen, abschliessen, korrigieren, Forfait, zurücksetzen).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function decisions( int $season_id, int $limit = self::DECISION_LIMIT ): array {
		$rows  = $this->db->get_results(
			"SELECT {$this->columns()}, e.id AS event_id, e.type AS event_type, e.comment, e.data_json, e.created_at AS event_at, e.user_id AS event_user, pe.first_name, pe.last_name
			FROM {$this->db->table( 'fixture_event' )} e
			JOIN {$this->db->table( 'fixture' )} f ON f.id = e.fixture_id
			{$this->joins()}
			LEFT JOIN {$this->db->table( 'person' )} pe ON pe.id = e.person_id
			WHERE c.season_id = %d AND c.type IN ('MM', 'CUP')
				AND ( e.type IN ('decided', 'forfeit', 'reset') OR ( e.type = 'confirmed' AND e.data_json LIKE %s ) )
			ORDER BY e.created_at DESC, e.id DESC
			LIMIT %d",
			array( $season_id, '%"by_tk":true%', $limit )
		);
		$names = $this->names( $rows );
		$items = array();
		foreach ( $rows as $row ) {
			$data            = json_decode( (string) $row['data_json'], true );
			$data            = is_array( $data ) ? $data : array();
			$item            = $this->item( $row, $this->utc( $row['event_at'] ) );
			$item['event']   = (string) $row['event_type'];
			$item['action']  = 'confirmed' === $row['event_type'] ? 'confirm' : (string) ( $data['action'] ?? $row['event_type'] );
			$item['winner']  = isset( $data['winner'] ) && in_array( $data['winner'], array( 'home', 'away' ), true ) ? (string) $data['winner'] : null;
			$item['comment'] = $row['comment'];
			$item['by']      = $this->actor_name( $row, $names );
			$items[]         = $item;
		}
		return $items;
	}

	/**
	 * Begegnungen ohne Resultat (angesetzt oder verschoben) mit weiterer Bedingung auf scheduled_at.
	 *
	 * @param list<string> $args Werte für die Bedingung.
	 * @return list<array<string,mixed>>
	 */
	private function without_result( int $season_id, string $condition, array $args ): array {
		$rows = $this->db->get_results(
			"SELECT {$this->columns()}
			FROM {$this->db->table( 'fixture' )} f
			{$this->joins()}
			WHERE c.season_id = %d AND c.type IN ('MM', 'CUP') AND f.status IN (%s, %s) AND f.scheduled_at IS NOT NULL AND {$condition}
			ORDER BY f.scheduled_at, f.id",
			array_merge( array( $season_id ), self::OPEN, $args )
		);
		return array_map( fn( array $row ): array => $this->item( $row, $this->local( $row['scheduled_at'] ) ), $rows );
	}

	private function columns(): string {
		return "f.id, f.status, f.scheduled_at, f.home_score, f.away_score, f.entered_by, f.entered_at, f.confirmed_by, f.confirmed_at,
			c.type, c.name AS competition,
			COALESCE(ht.name, he.name, '') AS home_name, COALESCE(at.name, ae.name, '') AS away_name,
			(SELECT MAX(g.id) FROM {$this->db->table( 'game' )} g WHERE g.fixture_id = f.id) AS result_stamp";
	}

	private function joins(): string {
		return "JOIN {$this->db->table( 'competition' )} c ON c.id = f.competition_id
			LEFT JOIN {$this->db->table( 'team' )} ht ON ht.id = f.home_team_id
			LEFT JOIN {$this->db->table( 'team' )} at ON at.id = f.away_team_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} he ON he.id = f.home_entry_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} ae ON ae.id = f.away_entry_id";
	}

	/**
	 * Gemeinsame Felder einer Zeile.
	 *
	 * @param array<string,string|null> $row Datenbankzeile.
	 * @return array<string,mixed>
	 */
	private function item( array $row, ?\DateTimeImmutable $since ): array {
		$home_score = self::score( $row['home_score'] );
		$away_score = self::score( $row['away_score'] );
		$now        = $this->clock->now();
		return array(
			'id'           => (int) $row['id'],
			'type'         => (string) $row['type'],
			'competition'  => (string) $row['competition'],
			'scheduled_at' => $row['scheduled_at'],
			'home'         => (string) $row['home_name'],
			'away'         => (string) $row['away_name'],
			'status'       => (string) $row['status'],
			'home_score'   => $home_score,
			'away_score'   => $away_score,
			'version'      => FixtureContext::version_of(
				(string) $row['status'],
				$home_score,
				$away_score,
				$row['entered_at'],
				$row['confirmed_at'],
				self::account( $row['entered_by'] ),
				self::account( $row['confirmed_by'] ),
				(int) ( $row['result_stamp'] ?? 0 )
			),
			'since'        => $since?->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			'age_hours'    => null === $since ? null : max( 0, intdiv( $now->getTimestamp() - $since->getTimestamp(), 3600 ) ),
		);
	}

	/**
	 * Namen der Konten ohne Person in einem Aufruf.
	 *
	 * @param list<array<string,string|null>> $rows Zeilen mit event_user, first_name, last_name.
	 * @return array<int,string>
	 */
	private function names( array $rows ): array {
		$ids = array();
		foreach ( $rows as $row ) {
			if ( null === $row['first_name'] && null === $row['last_name'] && null !== $row['event_user'] ) {
				$ids[ (int) $row['event_user'] ] = true;
			}
		}
		return array() === $ids ? array() : ( $this->user_names )( array_keys( $ids ) );
	}

	/**
	 * @param array<string,string|null> $row   Zeile mit Person und Konto des Ereignisses.
	 * @param array<int,string>         $names Namen der Konten.
	 */
	private function actor_name( array $row, array $names ): ?string {
		if ( null !== $row['first_name'] || null !== $row['last_name'] ) {
			return trim( $row['first_name'] . ' ' . $row['last_name'] );
		}
		return null === $row['event_user'] ? null : ( $names[ (int) $row['event_user'] ] ?? null );
	}

	private function utc( ?string $value ): ?\DateTimeImmutable {
		return null === $value || '' === $value ? null : new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	private function local( ?string $value ): ?\DateTimeImmutable {
		return null === $value || '' === $value ? null : new \DateTimeImmutable( $value, $this->timezone );
	}

	/**
	 * UTC-Zeitpunkt vor einer Dauer, z. B. «7 days».
	 */
	private function utc_ago( string $duration ): string {
		return $this->clock->now()->modify( '-' . $duration )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Ortszeit vor so vielen Stunden verstrichener Zeit.
	 */
	private function local_hours_ago( int $hours ): string {
		return $this->clock->now()->modify( '-' . $hours . ' hours' )->setTimezone( $this->timezone )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Ortszeit vor so vielen Kalendertagen.
	 */
	private function local_days_ago( int $days ): string {
		return $this->clock->now()->setTimezone( $this->timezone )->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s' );
	}

	private static function score( ?string $value ): ?int {
		return null === $value || '' === $value ? null : (int) $value;
	}

	private static function account( ?string $value ): ?int {
		return null === $value || (int) $value <= 0 ? null : (int) $value;
	}
}
