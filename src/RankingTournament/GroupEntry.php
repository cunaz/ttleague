<?php
/**
 * Gruppenzettel einer Ranglistenturnier-Gruppe nachtragen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Access\AccessPolicy;
use TTLeague\Domain\Match\SetPoints;
use TTLeague\Domain\Ranking\RlPlayerStatus;
use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Results\Actor;

/**
 * Nachtragen darf der Gruppenchef, ersatzweise der TK-Chef seines Clubs oder der TK-Chef des
 * Platzclubs (AccessPolicy::can_enter_rl_group, nur aktive Saison) und die TK. Möglich ist es,
 * solange die Runde freigegeben und nicht abgeschlossen ist; eine Bestätigung durch die
 * Mitspielenden gibt es nicht.
 *
 * Gespeichert werden der Status jeder Person, die Partien «jeder gegen jeden» (game mit
 * rl_group_id) und Rang und Punkte nach GroupRanking. Partien einer Person, die forfait gegeben
 * hat, bleiben gespeichert, zählen aber nicht (counts_for_result = 0). Für abgemeldete und
 * disqualifizierte Personen gibt es keine Partien.
 *
 * Gleichzeitige Änderungen: entry() liefert «version». Wird sie beim Speichern mitgeschickt und
 * hat sich die Gruppe inzwischen geändert, antwortet der Dienst mit 409. Jedes Speichern sperrt
 * zudem Saison und Gruppe, liest neu und bricht ab, wenn sich der Stand seit der Prüfung
 * geändert hat.
 */
final class GroupEntry {

	/** Status, die sich auf dem Gruppenzettel wählen lassen. */
	public const STATUSES = array( 'played', 'absent', 'disqualified', 'forfeit' );

	private Repository $repo;

	/** @var callable(int):?string */
	private $user_names;

	/**
	 * @param callable(int):?string|null $user_names Anzeigename eines Kontos ohne verknüpfte Person.
	 */
	public function __construct(
		private readonly Db $db,
		private readonly AccessPolicy $policy,
		private readonly Clock $clock,
		private readonly AuditLog $audit,
		private readonly Events $events,
		?callable $user_names = null,
	) {
		$this->repo       = new Repository( $db );
		$this->user_names = $user_names ?? static fn(): ?string => null;
	}

	/**
	 * Darf die Person den Gruppenzettel sehen und, im passenden Status, nachtragen?
	 */
	public function may_enter( Actor $actor, int $group_id ): bool {
		return $actor->is_tk || ( null !== $actor->person_id && $this->policy->can_enter_rl_group( $actor->person_id, $group_id ) );
	}

	/**
	 * Alles für das Formular: Personen, Spielfolge, erfasster Stand, Rangliste, Rechte.
	 *
	 * @return array<string,mixed>
	 */
	public function entry( int $group_id, Actor $actor ): array {
		$group = $this->group( $group_id, $actor );
		$rules = SeasonRules::from_array( $group['rules'] );
		$ids   = array_column( $group['members'], 'player_id' );
		$order = Sheet::order( $ids );
		$saved = array();
		foreach ( $group['games'] as $game ) {
			$saved[ self::pair( $game['a'], $game['b'] ) ] = $game;
		}
		$games = array();
		foreach ( $order as $game ) {
			$stored = $saved[ self::pair( $game['a'], $game['b'] ) ] ?? null;
			if ( null === $stored ) {
				continue;
			}
			// Die gespeicherte Partie kann die Personen in anderer Reihenfolge nennen.
			$swap    = $stored['a'] !== $game['a'];
			$games[] = array(
				'sequence'   => $game['sequence'],
				'a'          => $game['a'],
				'b'          => $game['b'],
				'sets_a'     => $swap ? $stored['sets_b'] : $stored['sets_a'],
				'sets_b'     => $swap ? $stored['sets_a'] : $stored['sets_b'],
				'counts'     => $stored['counts'],
				'set_points' => $swap ? array_map( static fn( array $set ): array => array( $set[1], $set[0] ), $stored['set_points'] ) : $stored['set_points'],
			);
		}
		$players = array();
		foreach ( $group['members'] as $position => $member ) {
			$players[] = array(
				'player_id'      => $member['player_id'],
				'position'       => $position + 1,
				'name'           => $member['name'],
				'club'           => $member['club_short'],
				'classification' => $member['classification'],
				'status'         => $member['status'],
				'is_chief'       => $member['player_id'] === $group['chief_player_id'],
			);
		}

		return array(
			'group'      => array(
				'id'           => $group['id'],
				'round_id'     => $group['round_id'],
				'round_no'     => $group['round_no'],
				'is_final'     => $group['is_final'],
				'category'     => $group['category'],
				'group_no'     => $group['group_no'],
				'status'       => $group['round_status'],
				'status_label' => Rounds::status_label( $group['round_status'] ),
				'start_at'     => $group['start_at'],
				'host'         => $group['host'],
				'venue'        => $group['venue'],
				'note'         => $group['note'],
			),
			'can'        => array( 'enter' => $this->open( $group ) ),
			'rules'      => array(
				'sets_to_win'        => $rules->sets_to_win,
				'set_points_enabled' => $rules->set_points_enabled,
			),
			'players'    => $players,
			'order'      => $order,
			'games'      => $games,
			'ranking'    => self::ranking_rows( $rules, $group ),
			'entered_by' => null === $group['entered_by'] ? null : ( $this->repo->user_name( $group['entered_by'] ) ?? ( $this->user_names )( $group['entered_by'] ) ),
			'entered_at' => null === $group['entered_at'] ? null : str_replace( ' ', 'T', $group['entered_at'] ) . 'Z',
			'next_round' => $this->next_round( $group ),
			'version'    => self::version( $group ),
		);
	}

	/**
	 * Gruppenzettel speichern oder ändern.
	 *
	 * @param array<string,mixed> $payload players (Spieler => Status oder Liste aus player_id und status),
	 *                                     games (Liste aus sequence, sets_a, sets_b, set_points),
	 *                                     next_blocked (Spieler => gesperrte Abende der nächsten Runde), version.
	 * @return array{ranking:list<array<string,mixed>>,entered_at:string,version:string}
	 */
	public function save( int $group_id, array $payload, Actor $actor ): array {
		$group = $this->group( $group_id, $actor );
		$this->require_open( $group );
		$version = is_string( $payload['version'] ?? null ) && '' !== $payload['version'] ? $payload['version'] : null;
		if ( null !== $version && ! hash_equals( self::version( $group ), $version ) ) {
			Fail::conflict( __( 'Die Gruppe wurde inzwischen geändert. Bitte lade die Seite neu.', 'ttleague' ) );
		}
		$rules = SeasonRules::from_array( $group['rules'] );
		$input = $this->validate( $group, $rules, $payload );
		$now   = $this->clock->now()->format( 'Y-m-d H:i:s' );

		$this->write(
			$group,
			function ( array $current ) use ( $actor, $rules, $input, $now ): void {
				$before = self::snapshot( $current );
				$this->repo->delete_games( $current['id'] );
				foreach ( $input['games'] as $game ) {
					$game_id = $this->db->insert(
						'game',
						array(
							'fixture_id'        => null,
							'rl_group_id'       => $current['id'],
							'sequence'          => $game['sequence'],
							'type'              => 'single',
							'a1_player_id'      => $game['a'],
							'b1_player_id'      => $game['b'],
							'sets_a'            => $game['sets_a'],
							'sets_b'            => $game['sets_b'],
							'status'            => 'played',
							'counts_for_result' => $game['counts'] ? 1 : 0,
							'played_at'         => $current['start_at'],
						)
					);
					foreach ( $game['set_points'] as $i => $set ) {
						$this->db->insert(
							'game_set',
							array(
								'game_id'  => $game_id,
								'set_no'   => $i + 1,
								'points_a' => $set[0],
								'points_b' => $set[1],
							)
						);
					}
				}
				$rows = Sheet::ranking( $rules, $input['statuses'], $input['games'] );
				foreach ( $input['statuses'] as $player_id => $status ) {
					$row    = $rows[ $player_id ] ?? null;
					$ranked = null !== $row && $row->status->is_ranked();
					$this->db->update(
						'rl_group_player',
						array(
							'status'  => $status,
							'rank_no' => $ranked ? $row->rank : null,
							'points'  => $ranked ? $row->round_points : null,
						),
						array(
							'rl_group_id' => $current['id'],
							'player_id'   => $player_id,
						)
					);
				}
				$this->db->update(
					'rl_group',
					array(
						'entered_by' => $actor->user_id,
						'entered_at' => $now,
					),
					array( 'id' => $current['id'] )
				);
				$this->store_next_blocked( $actor, $current, $input['next_blocked'] );
				$after = $this->repo->group( $current['id'] );
				$this->audit->record( 'rl_group', $current['id'], null === $current['entered_at'] ? 'entry_saved' : 'entry_edited', $actor->user_id, $before, null === $after ? null : self::snapshot( $after ) );
			}
		);
		$this->events->changed( 'rl_group', $group_id, true );

		$saved = $this->repo->group( $group_id );
		if ( null === $saved ) {
			Fail::not_found( __( 'Diese Gruppe gibt es nicht.', 'ttleague' ) );
		}
		return array(
			'ranking'    => self::ranking_rows( $rules, $saved ),
			'entered_at' => str_replace( ' ', 'T', (string) $saved['entered_at'] ) . 'Z',
			'version'    => self::version( $saved ),
		);
	}

	/**
	 * Gruppenzettel zurücksetzen (TK): Partien löschen, alle wieder «aufgeboten».
	 */
	public function reset( int $group_id, Actor $actor ): void {
		if ( ! $actor->is_tk ) {
			Fail::forbidden( __( 'Das kann nur die TK.', 'ttleague' ) );
		}
		$group = $this->group( $group_id, $actor );
		$this->require_open( $group );
		$this->write(
			$group,
			function ( array $current ) use ( $actor ): void {
				$before = self::snapshot( $current );
				$this->repo->delete_games( $current['id'] );
				$this->db->update(
					'rl_group_player',
					array(
						'status'  => RlPlayerStatus::Scheduled->value,
						'rank_no' => null,
						'points'  => null,
					),
					array( 'rl_group_id' => $current['id'] )
				);
				$this->db->update(
					'rl_group',
					array(
						'entered_by' => null,
						'entered_at' => null,
					),
					array( 'id' => $current['id'] )
				);
				$after = $this->repo->group( $current['id'] );
				$this->audit->record( 'rl_group', $current['id'], 'entry_reset', $actor->user_id, $before, null === $after ? null : self::snapshot( $after ) );
			}
		);
		$this->events->changed( 'rl_group', $group_id, true );
	}

	/**
	 * Kennung des Stands einer Gruppe: ändert sich mit jeder Erfassung, mit dem Status der Runde
	 * und mit den Mitgliedern.
	 *
	 * @param array<string,mixed> $group Gruppe aus Repository::group().
	 */
	public static function version( array $group ): string {
		$members = array();
		foreach ( $group['members'] as $member ) {
			$members[] = $member['player_id'] . ':' . $member['status'];
		}
		$stamp = 0;
		foreach ( $group['games'] as $game ) {
			$stamp = max( $stamp, $game['id'] );
		}
		return substr(
			hash(
				'sha256',
				implode( '|', array( $group['round_status'], (string) $group['entered_at'], (string) $group['entered_by'], (string) $group['start_at'], (string) $group['chief_player_id'], implode( ',', $members ), (string) $stamp, (string) count( $group['games'] ) ) )
			),
			0,
			16
		);
	}

	public static function status_label( string $status ): string {
		return match ( $status ) {
			'played'       => __( 'gespielt', 'ttleague' ),
			'absent'       => __( 'abgemeldet', 'ttleague' ),
			'disqualified' => __( 'disqualifiziert', 'ttleague' ),
			'forfeit'      => __( 'forfait', 'ttleague' ),
			'scheduled'    => __( 'aufgeboten', 'ttleague' ),
			default        => $status,
		};
	}

	/**
	 * Gruppe laden und das Recht prüfen. Gruppen einer Runde in Planung sieht nur die TK.
	 *
	 * @return array<string,mixed> Gruppe aus Repository::group().
	 */
	private function group( int $group_id, Actor $actor ): array {
		$group = $this->repo->group( $group_id );
		if ( null === $group || ( Rounds::PLANNED === $group['round_status'] && ! $actor->is_tk ) ) {
			Fail::not_found( __( 'Diese Gruppe gibt es nicht.', 'ttleague' ) );
		}
		if ( ! $this->may_enter( $actor, $group_id ) ) {
			Fail::forbidden( __( 'Den Gruppenzettel trägt der Gruppenchef nach, ersatzweise der TK-Chef seines Clubs oder des Platzclubs.', 'ttleague' ) );
		}
		return $group;
	}

	/**
	 * Lässt sich der Gruppenzettel jetzt speichern?
	 *
	 * @param array<string,mixed> $group Gruppe.
	 */
	private function open( array $group ): bool {
		return Rounds::PUBLISHED === $group['round_status'] && 'active' === $group['season_status'];
	}

	/**
	 * @param array<string,mixed> $group Gruppe.
	 */
	private function require_open( array $group ): void {
		if ( 'active' !== $group['season_status'] ) {
			Fail::conflict( __( 'Gruppenzettel lassen sich nur in der laufenden Saison erfassen.', 'ttleague' ) );
		}
		if ( Rounds::PUBLISHED !== $group['round_status'] ) {
			Fail::conflict(
				Rounds::CLOSED === $group['round_status']
					? __( 'Die Runde ist abgeschlossen. Der Gruppenzettel lässt sich nicht mehr ändern.', 'ttleague' )
					: __( 'Die Runde ist noch nicht freigegeben.', 'ttleague' )
			);
		}
	}

	/**
	 * Eingabe prüfen.
	 *
	 * @param array<string,mixed> $group   Gruppe.
	 * @param array<string,mixed> $payload Eingabe.
	 * @return array{statuses:array<int,string>,games:list<array{sequence:int,a:int,b:int,sets_a:int,sets_b:int,counts:bool,set_points:list<array{0:int,1:int}>}>,next_blocked:array<int,list<string>>}
	 */
	private function validate( array $group, SeasonRules $rules, array $payload ): array {
		$errors  = array();
		$members = array_column( $group['members'], null, 'player_id' );

		// Status je Person.
		$raw = $payload['players'] ?? array();
		$raw = is_array( $raw ) ? $raw : array();
		if ( array_is_list( $raw ) ) {
			$list = array();
			foreach ( $raw as $entry ) {
				if ( is_array( $entry ) && isset( $entry['player_id'] ) ) {
					$list[ (int) $entry['player_id'] ] = $entry['status'] ?? null;
				}
			}
			$raw = $list;
		}
		foreach ( array_keys( $raw ) as $player_id ) {
			if ( ! isset( $members[ (int) $player_id ] ) ) {
				$errors[] = self::error( 'players', __( 'Eine Person gehört nicht zu dieser Gruppe.', 'ttleague' ) );
				break;
			}
		}
		$statuses = array();
		foreach ( $members as $player_id => $member ) {
			$status = $raw[ $player_id ] ?? null;
			if ( ! is_string( $status ) || ! in_array( $status, self::STATUSES, true ) ) {
				/* translators: %s: Name */
				$errors[] = self::error( 'players.' . $player_id, sprintf( __( '%s: Bitte wähle, ob die Person gespielt hat, abgemeldet war, disqualifiziert wurde oder forfait gab.', 'ttleague' ), $member['name'] ) );
				$status   = RlPlayerStatus::Scheduled->value;
			}
			$statuses[ $player_id ] = $status;
		}

		// Partien nach Nummer der Spielfolge.
		$given = array();
		$raw   = $payload['games'] ?? array();
		foreach ( is_array( $raw ) ? $raw : array() as $entry ) {
			$sequence = is_array( $entry ) && is_numeric( $entry['sequence'] ?? null ) ? (int) $entry['sequence'] : 0;
			if ( $sequence < 1 || isset( $given[ $sequence ] ) ) {
				$errors[] = self::error( 'games', __( 'Jede Partie braucht ihre Nummer aus der Spielfolge, und jede Nummer kommt einmal vor.', 'ttleague' ) );
				continue;
			}
			$given[ $sequence ] = $entry;
		}
		$games = array();
		foreach ( Sheet::order( array_keys( $members ) ) as $game ) {
			$sequence = $game['sequence'];
			$entry    = $given[ $sequence ] ?? null;
			unset( $given[ $sequence ] );
			$field   = 'games.' . $sequence;
			$pairing = $members[ $game['a'] ]['name'] . ' – ' . $members[ $game['b'] ]['name'];
			$status  = array( $statuses[ $game['a'] ], $statuses[ $game['b'] ] );
			$played  = array( 'played', 'played' ) === $status;
			$none    = array() !== array_intersect( $status, array( 'absent', 'disqualified', RlPlayerStatus::Scheduled->value ) );
			$empty   = null === $entry || ( ( null === ( $entry['sets_a'] ?? null ) || '' === $entry['sets_a'] ) && ( null === ( $entry['sets_b'] ?? null ) || '' === $entry['sets_b'] ) );
			if ( $empty ) {
				if ( $played ) {
					/* translators: %s: Paarung */
					$errors[] = self::error( $field, sprintf( __( '%s: Das Resultat fehlt.', 'ttleague' ), $pairing ) );
				}
				continue;
			}
			if ( $none ) {
				/* translators: %s: Paarung */
				$errors[] = self::error( $field, sprintf( __( '%s: Für abgemeldete oder disqualifizierte Personen gibt es keine Partie. Entferne das Resultat.', 'ttleague' ), $pairing ) );
				continue;
			}
			$sets_a = self::sets( $entry['sets_a'] ?? null );
			$sets_b = self::sets( $entry['sets_b'] ?? null );
			if ( null === $sets_a || null === $sets_b || max( $sets_a, $sets_b ) !== $rules->sets_to_win || min( $sets_a, $sets_b ) >= $rules->sets_to_win ) {
				/* translators: 1: Paarung, 2: Gewinnsätze */
				$errors[] = self::error( $field, sprintf( __( '%1$s: Ungültiges Satzresultat. Gespielt wird auf %2$d Gewinnsätze.', 'ttleague' ), $pairing, $rules->sets_to_win ) );
				continue;
			}
			$set_points = array();
			$raw_points = $entry['set_points'] ?? array();
			if ( $rules->set_points_enabled && is_array( $raw_points ) && array() !== $raw_points ) {
				foreach ( $raw_points as $set ) {
					$a = is_array( $set ) ? self::sets( $set[0] ?? null, 99 ) : null;
					$b = is_array( $set ) ? self::sets( $set[1] ?? null, 99 ) : null;
					if ( null === $a || null === $b ) {
						$set_points = null;
						break;
					}
					$set_points[] = array( $a, $b );
				}
				$problems = null === $set_points ? array( __( 'Satzpunkte sind Zahlenpaare, z. B. 11:8.', 'ttleague' ) ) : SetPoints::validate( $set_points, $sets_a, $sets_b, $rules->sets_to_win );
				if ( array() !== $problems ) {
					$errors[] = self::error( $field . '.set_points', $pairing . ': ' . implode( ' ', $problems ) );
					continue;
				}
			}
			$games[] = array(
				'sequence'   => $sequence,
				'a'          => $game['a'],
				'b'          => $game['b'],
				'sets_a'     => $sets_a,
				'sets_b'     => $sets_b,
				'counts'     => $played,
				'set_points' => $set_points ?? array(),
			);
		}
		if ( array() !== $given ) {
			$errors[] = self::error( 'games', __( 'Eine Partie gehört nicht zur Spielfolge dieser Gruppe.', 'ttleague' ) );
		}

		// Sperrdaten der Anwesenden für die nächste Runde.
		$next_blocked = array();
		$raw          = $payload['next_blocked'] ?? null;
		if ( is_array( $raw ) && array() !== $raw ) {
			$next = $this->next_round( $group );
			foreach ( $raw as $player_id => $dates ) {
				$player_id = (int) $player_id;
				if ( null === $next || ! isset( $members[ $player_id ] ) || ! is_array( $dates ) || array() !== array_diff( array_map( 'strval', $dates ), $next['dates'] ) ) {
					$errors[] = self::error( 'next_blocked.' . $player_id, __( 'Sperrdaten müssen Abende der nächsten Runde sein.', 'ttleague' ) );
					continue;
				}
				$dates = array_values( array_unique( array_map( 'strval', $dates ) ) );
				sort( $dates );
				$next_blocked[ $player_id ] = $dates;
			}
		}

		if ( array() !== $errors ) {
			Fail::invalid_fields( $errors );
		}
		return array(
			'statuses'     => $statuses,
			'games'        => $games,
			'next_blocked' => $next_blocked,
		);
	}

	/**
	 * Die nächste Runde, für die sich Sperrdaten melden lassen: die erste spätere Runde in Planung.
	 *
	 * @param array<string,mixed> $group Gruppe.
	 * @return array{id:int,round_no:int,is_final:bool,date_from:string,date_to:string,dates:list<string>,blocked:array<int,list<string>>,answered:list<int>}|null
	 */
	private function next_round( array $group ): ?array {
		foreach ( $this->repo->rounds( $group['competition_id'] ) as $round ) {
			if ( $round['round_no'] <= $group['round_no'] || Rounds::PLANNED !== $round['status'] || null === $round['date_from'] || null === $round['date_to'] ) {
				continue;
			}
			$persons  = array_column( $group['members'], 'person_id', 'player_id' );
			$dates    = $this->repo->blocked_dates( array_values( $persons ), $round['date_from'], $round['date_to'] );
			$answered = $this->repo->answered( array_values( $persons ), $round['date_from'], $round['date_to'] );
			$blocked  = array();
			$known    = array();
			foreach ( $persons as $player_id => $person_id ) {
				$blocked[ $player_id ] = $dates[ $person_id ] ?? array();
				if ( in_array( $person_id, $answered, true ) ) {
					$known[] = $player_id;
				}
			}
			return array(
				'id'        => $round['id'],
				'round_no'  => $round['round_no'],
				'is_final'  => $round['is_final'],
				'date_from' => $round['date_from'],
				'date_to'   => $round['date_to'],
				'dates'     => Registrations::evenings( $round ),
				'blocked'   => $blocked,
				'answered'  => $known,
			);
		}
		return null;
	}

	/**
	 * Sperrdaten der nächsten Runde speichern, für Personen, die noch zum Turnier angemeldet sind.
	 *
	 * @param array<string,mixed>      $group   Gruppe.
	 * @param array<int,list<string>> $blocked Spieler => Abende.
	 */
	private function store_next_blocked( Actor $actor, array $group, array $blocked ): void {
		if ( array() === $blocked ) {
			return;
		}
		$next = $this->next_round( $group );
		if ( null === $next ) {
			return;
		}
		$persons = array_column( $group['members'], 'person_id', 'player_id' );
		foreach ( $blocked as $player_id => $dates ) {
			$participant = $this->repo->participant( $group['competition_id'], $player_id );
			if ( null === $participant || Registrations::REGISTERED !== $participant['status'] ) {
				continue;
			}
			$person_id = $persons[ $player_id ];
			$from      = $next['date_from'];
			$to        = $next['date_to'];
			$before    = $this->repo->blocked_dates( array( $person_id ), $from, $to )[ $person_id ] ?? array();
			if ( $before === $dates && in_array( $player_id, $next['answered'], true ) ) {
				continue;
			}
			$this->repo->replace_blocked_dates( $person_id, $from, $to, $dates );
			$this->audit->record(
				'rl_participant',
				$participant['id'],
				'blocked_dates',
				$actor->user_id,
				array(
					'round' => $next['id'],
					'dates' => $before,
				),
				array(
					'round' => $next['id'],
					'dates' => $dates,
					'via'   => 'group_sheet',
				)
			);
		}
	}

	/**
	 * Schreibt in einer Transaktion: Saison und Gruppe sperren, Gruppe neu lesen, bei geändertem
	 * Stand mit 409 abbrechen.
	 *
	 * @param array<string,mixed>                $group Geprüfter Stand.
	 * @param callable(array<string,mixed>):void $work  Schreibvorgänge mit dem gesperrt gelesenen Stand.
	 */
	private function write( array $group, callable $work ): void {
		$this->db->transaction(
			function () use ( $group, $work ): void {
				$this->repo->lock_group( $group['season_id'], $group['id'] );
				$current = $this->repo->group( $group['id'] );
				if ( null === $current || ! hash_equals( self::version( $group ), self::version( $current ) ) ) {
					Fail::conflict( __( 'Die Gruppe wurde inzwischen geändert. Bitte lade die Seite neu.', 'ttleague' ) );
				}
				$work( $current );
			}
		);
	}

	/**
	 * Rangliste der Gruppe für Anzeige und Antwort.
	 *
	 * @param array<string,mixed> $group Gruppe.
	 * @return list<array{player_id:int,name:string,status:string,rank:int|null,shared:bool,points:float|null,wins:int,losses:int,sets_won:int,sets_lost:int}>
	 */
	private static function ranking_rows( SeasonRules $rules, array $group ): array {
		if ( null === $group['entered_at'] ) {
			return array();
		}
		$members = array_column( $group['members'], null, 'player_id' );
		$rows    = array();
		foreach ( Sheet::ranking( $rules, array_column( $group['members'], 'status', 'player_id' ), $group['games'] ) as $player_id => $row ) {
			$ranked = $row->status->is_ranked();
			$rows[] = array(
				'player_id' => $player_id,
				'name'      => $members[ $player_id ]['name'],
				'status'    => $members[ $player_id ]['status'],
				'rank'      => $ranked ? $row->rank : null,
				'shared'    => $row->shared_rank,
				'points'    => $ranked ? $row->round_points : null,
				'wins'      => $row->wins,
				'losses'    => $row->losses,
				'sets_won'  => $row->sets_won,
				'sets_lost' => $row->sets_lost,
			);
		}
		return $rows;
	}

	/**
	 * Stand einer Gruppe für das Protokoll.
	 *
	 * @param array<string,mixed> $group Gruppe.
	 * @return array<string,mixed>
	 */
	private static function snapshot( array $group ): array {
		$players = array();
		foreach ( $group['members'] as $member ) {
			$players[ $member['player_id'] ] = array(
				'status' => $member['status'],
				'rank'   => $member['rank_no'],
				'points' => $member['points'],
			);
		}
		return array(
			'entered_by' => $group['entered_by'],
			'entered_at' => $group['entered_at'],
			'players'    => $players,
			'games'      => array_map(
				static fn( array $game ): array => array(
					'sequence' => $game['sequence'],
					'a'        => $game['a'],
					'b'        => $game['b'],
					'sets'     => $game['sets_a'] . ':' . $game['sets_b'],
					'counts'   => $game['counts'],
				),
				$group['games']
			),
		);
	}

	/**
	 * Ganze Zahl von 0 bis $max oder null.
	 */
	private static function sets( mixed $value, int $max = 9 ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 && $value <= $max ? $value : null;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[0-9]{1,2}$/D', $value ) && (int) $value <= $max ) {
			return (int) $value;
		}
		return null;
	}

	private static function pair( int $a, int $b ): string {
		return min( $a, $b ) . '|' . max( $a, $b );
	}

	/**
	 * @return array{field:string,message:string}
	 */
	private static function error( string $field, string $message ): array {
		return array(
			'field'   => $field,
			'message' => $message,
		);
	}
}
