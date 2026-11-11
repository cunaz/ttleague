<?php
/**
 * Runden des Ranglistenturniers: anlegen, einteilen, Gruppen ändern, freigeben, abschliessen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Domain\Ranking\GroupProposal;
use TTLeague\Domain\Ranking\RlPlayerStatus;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Results\Actor;

/**
 * Alle Aktionen gehören der TK. Eine neue Runde ist «planned» (nur die TK sieht sie), nach der
 * Freigabe «published» (Aufgebot verschickt, Gruppenzettel offen), nach dem Abschluss «closed»
 * (Rang und Punkte festgeschrieben). Eingeteilt und freigegeben wird eine Runde erst, wenn alle
 * früheren abgeschlossen sind.
 *
 * Jede Aktion sperrt in ihrer Transaktion zuerst die Saison und dann die Runde und liest den
 * Status neu. So schliesst niemand eine Runde ab, während ein Gruppenzettel gespeichert wird.
 */
final class Rounds {

	public const PLANNED   = 'planned';
	public const PUBLISHED = 'published';
	public const CLOSED    = 'closed';

	/** Spielbeginn, wenn weder das Turnier (format_json start_time) noch das Lokal eine Zeit nennt. */
	public const DEFAULT_START_TIME = '19:30:00';

	private Repository $repo;

	public function __construct(
		private readonly Db $db,
		private readonly Clock $clock,
		private readonly AuditLog $audit,
		private readonly Events $events,
	) {
		$this->repo = new Repository( $db );
	}

	public function repository(): Repository {
		return $this->repo;
	}

	/**
	 * Neue Runde anlegen (Status «planned»).
	 *
	 * @return int ID der Runde.
	 */
	public function create( Actor $actor, int $competition_id, string $date_from, string $date_to, bool $is_final = false ): int {
		$this->require_tk( $actor );
		$competition = $this->repo->competition( $competition_id );
		if ( null === $competition ) {
			Fail::not_found( __( 'Dieses Ranglistenturnier gibt es nicht.', 'ttleague' ) );
		}
		$this->require_open_season( $competition );
		$this->check_week( $date_from, $date_to );
		$id = $this->db->transaction(
			function () use ( $actor, $competition, $date_from, $date_to, $is_final ): int {
				$this->db->get_var( "SELECT id FROM {$this->db->table( 'season' )} WHERE id = %d FOR UPDATE", array( $competition['season_id'] ) );
				$rounds = $this->repo->rounds( $competition['id'] );
				$last   = array() === $rounds ? null : $rounds[ count( $rounds ) - 1 ];
				if ( null !== $last && $last['is_final'] ) {
					Fail::conflict( __( 'Nach der Finalrunde gibt es keine weitere Runde.', 'ttleague' ) );
				}
				if ( null !== $last && null !== $last['date_to'] && $date_from <= $last['date_to'] ) {
					Fail::invalid( 'date_from', __( 'Die Runde beginnt nach dem Ende der vorherigen.', 'ttleague' ) );
				}
				$round_no = null === $last ? 1 : $last['round_no'] + 1;
				$id       = $this->db->insert(
					'rl_round',
					array(
						'competition_id' => $competition['id'],
						'round_no'       => $round_no,
						'date_from'      => $date_from,
						'date_to'        => $date_to,
						'is_final'       => $is_final ? 1 : 0,
						'status'         => self::PLANNED,
					)
				);
				$this->audit->record(
					'rl_round',
					$id,
					'created',
					$actor->user_id,
					null,
					array(
						'round_no'  => $round_no,
						'date_from' => $date_from,
						'date_to'   => $date_to,
						'is_final'  => $is_final,
					)
				);
				return $id;
			}
		);
		$this->events->changed( 'rl_round', $id );
		return $id;
	}

	/**
	 * Woche einer noch nicht freigegebenen Runde ändern. Ein bestehender Vorschlag bleibt; die TK
	 * teilt neu ein, wenn die Abende nicht mehr passen.
	 */
	public function reschedule( Actor $actor, int $round_id, string $date_from, string $date_to, bool $is_final ): void {
		$this->require_tk( $actor );
		[ $round, $competition ] = $this->round( $round_id );
		$this->check_week( $date_from, $date_to );
		$this->db->transaction(
			function () use ( $actor, $round, $competition, $date_from, $date_to, $is_final ): void {
				$current = $this->locked( $competition, $round['id'], array( self::PLANNED ) );
				foreach ( $this->repo->rounds( $competition['id'] ) as $other ) {
					if ( $other['round_no'] < $current['round_no'] && null !== $other['date_to'] && $date_from <= $other['date_to'] ) {
						Fail::invalid( 'date_from', __( 'Die Runde beginnt nach dem Ende der vorherigen.', 'ttleague' ) );
					}
					if ( $other['round_no'] > $current['round_no'] && ( $is_final || ( null !== $other['date_from'] && $date_to >= $other['date_from'] ) ) ) {
						Fail::invalid( 'date_to', __( 'Die Runde endet vor dem Beginn der nächsten; die Finalrunde ist die letzte.', 'ttleague' ) );
					}
				}
				$this->db->update(
					'rl_round',
					array(
						'date_from' => $date_from,
						'date_to'   => $date_to,
						'is_final'  => $is_final ? 1 : 0,
					),
					array( 'id' => $current['id'] )
				);
				$this->audit->record(
					'rl_round',
					$current['id'],
					'rescheduled',
					$actor->user_id,
					array(
						'date_from' => $current['date_from'],
						'date_to'   => $current['date_to'],
						'is_final'  => $current['is_final'],
					),
					array(
						'date_from' => $date_from,
						'date_to'   => $date_to,
						'is_final'  => $is_final,
					)
				);
			}
		);
		$this->events->changed( 'rl_round', $round_id );
	}

	/**
	 * Noch nicht freigegebene Runde löschen, mit ihrem Vorschlag. Nur die letzte Runde.
	 */
	public function delete( Actor $actor, int $round_id ): void {
		$this->require_tk( $actor );
		[ $round, $competition ] = $this->round( $round_id );
		$this->db->transaction(
			function () use ( $actor, $round, $competition ): void {
				$current = $this->locked( $competition, $round['id'], array( self::PLANNED ) );
				$rounds  = $this->repo->rounds( $competition['id'] );
				if ( $rounds[ count( $rounds ) - 1 ]['id'] !== $current['id'] ) {
					Fail::conflict( __( 'Löschen lässt sich nur die letzte Runde.', 'ttleague' ) );
				}
				$this->repo->delete_groups( $current['id'] );
				$this->db->delete( 'rl_round', array( 'id' => $current['id'] ) );
				$this->audit->record( 'rl_round', $current['id'], 'deleted', $actor->user_id, $current, null );
			}
		);
		$this->events->changed( 'rl_round', $round_id );
	}

	/**
	 * Runde einteilen: Vorschlag rechnen und speichern. Ein früherer Vorschlag wird ersetzt.
	 *
	 * @return array{groups:int,unassigned:list<array{player_id:int,name:string,category:int,reason:string}>,repeats:int,draw:list<string>,without_slot:int}
	 */
	public function propose( Actor $actor, int $round_id ): array {
		$this->require_tk( $actor );
		[ $round, $competition ] = $this->round( $round_id );
		$this->require_open_season( $competition );
		if ( null === $round['date_from'] || null === $round['date_to'] ) {
			Fail::conflict( __( 'Die Runde hat noch keine Woche.', 'ttleague' ) );
		}
		$rules  = Repository::rules( $competition );
		$result = $this->db->transaction(
			function () use ( $actor, $round, $competition, $rules ): array {
				$current = $this->locked( $competition, $round['id'], array( self::PLANNED ) );
				$this->require_previous_closed( $competition, $current );

				$participants = $this->repo->participants( $competition['id'], $competition['season_id'], true );
				$by_player    = array_column( $participants, null, 'player_id' );
				$draw         = array();
				if ( $current['is_final'] ) {
					[ $categories, $draw ] = $this->finalists( $competition, $by_player, $rules->rl_finalists );
				} else {
					$categories = array();
					foreach ( $participants as $participant ) {
						if ( $participant['category'] > 0 ) {
							$categories[ $participant['category'] ][] = $participant['player_id'];
						}
					}
				}

				$blocked = $this->repo->blocked_dates( array_column( $participants, 'person_id' ), (string) $current['date_from'], (string) $current['date_to'] );
				$input   = array();
				$clubs   = array();
				foreach ( $categories as $category => $player_ids ) {
					foreach ( $player_ids as $player_id ) {
						$participant          = $by_player[ $player_id ];
						$input[ $category ][] = array(
							'id'      => $player_id,
							'club'    => $participant['club_id'],
							'blocked' => $blocked[ $participant['person_id'] ] ?? array(),
						);
						if ( null !== $participant['club_id'] ) {
							$clubs[] = $participant['club_id'];
						}
					}
				}
				// Eine Gruppe belegt halb so viele Tische, wie sie Personen hat. Ohne Angabe der Tische hat eine Gruppe Platz.
				$tables   = max( 1, intdiv( $rules->rl_group_size, 2 ) );
				$venues   = array_map(
					static fn( array $venue ): array => $venue + array( 'groups' => null === $venue['tables'] ? 1 : max( 1, intdiv( $venue['tables'], $tables ) ) ),
					$this->repo->venues( $clubs )
				);
				$slots    = GroupProposal::slots( (string) $current['date_from'], (string) $current['date_to'], $venues, $this->repo->closures( (string) $current['date_from'], (string) $current['date_to'] ) );
				$proposal = ( new GroupProposal( $rules ) )->propose(
					$input,
					$slots,
					$this->repo->pairings_before( $competition['id'], $current['round_no'] ),
					$current['is_final'],
					$this->repo->hosted_before( $competition['id'], $current['round_no'] )
				);

				$before = count( $this->repo->groups( $current['id'] ) );
				$this->repo->delete_groups( $current['id'] );
				$open_time    = array_column( $venues, 'open_time', 'id' );
				$without_slot = 0;
				foreach ( $proposal['groups'] as $group ) {
					$slot = $group['slot'];
					if ( null === $slot ) {
						++$without_slot;
					}
					$group_id = $this->db->insert(
						'rl_group',
						array(
							'rl_round_id'     => $current['id'],
							'category'        => $group['category'],
							'group_no'        => $group['group_no'],
							'host_club_id'    => $slot['club'] ?? null,
							'venue_id'        => $slot['venue'] ?? null,
							'start_at'        => null === $slot ? null : $slot['date'] . ' ' . ( $competition['start_time'] ?? Repository::time_or_null( $open_time[ $slot['venue'] ] ?? null ) ?? self::DEFAULT_START_TIME ),
							'chief_player_id' => $group['chief'],
						)
					);
					foreach ( $group['members'] as $player_id ) {
						$this->db->insert(
							'rl_group_player',
							array(
								'rl_group_id' => $group_id,
								'player_id'   => $player_id,
								'status'      => RlPlayerStatus::Scheduled->value,
							)
						);
					}
				}
				$unassigned = array();
				foreach ( $proposal['unassigned'] as $row ) {
					$unassigned[] = array(
						'player_id' => $row['id'],
						'name'      => $by_player[ $row['id'] ]['name'],
						'category'  => $row['category'],
						'reason'    => $row['reason'],
					);
				}
				$this->audit->record(
					'rl_round',
					$current['id'],
					'proposed',
					$actor->user_id,
					array( 'groups' => $before ),
					array(
						'groups'     => count( $proposal['groups'] ),
						'unassigned' => array_column( $proposal['unassigned'], 'id' ),
						'repeats'    => $proposal['repeats'],
					)
				);
				return array(
					'groups'       => count( $proposal['groups'] ),
					'unassigned'   => $unassigned,
					'repeats'      => $proposal['repeats'],
					'draw'         => $draw,
					'without_slot' => $without_slot,
				);
			}
		);
		$this->events->changed( 'rl_round', $round_id );
		return $result;
	}

	/**
	 * Angemeldete, die in keiner Gruppe der Runde stehen (Final: qualifizierte Personen).
	 *
	 * @return list<array{player_id:int,name:string,category:int,club_short:string|null,classification:int|null}>
	 */
	public function unassigned( int $round_id ): array {
		[ $round, $competition ] = $this->round( $round_id );
		$participants            = $this->repo->participants( $competition['id'], $competition['season_id'], true );
		$assigned                = array();
		foreach ( $this->repo->groups( $round['id'] ) as $group ) {
			foreach ( $group['members'] as $member ) {
				$assigned[ $member['player_id'] ] = true;
			}
		}
		$allowed = null;
		if ( $round['is_final'] ) {
			$allowed = array();
			foreach ( $this->finalists( $competition, array_column( $participants, null, 'player_id' ), Repository::rules( $competition )->rl_finalists )[0] as $player_ids ) {
				$allowed = array_merge( $allowed, $player_ids );
			}
		}
		$rows = array();
		foreach ( $participants as $participant ) {
			if ( isset( $assigned[ $participant['player_id'] ] ) || ( null !== $allowed && ! in_array( $participant['player_id'], $allowed, true ) ) ) {
				continue;
			}
			$rows[] = array(
				'player_id'      => $participant['player_id'],
				'name'           => $participant['name'],
				'category'       => $participant['category'],
				'club_short'     => $participant['club_short'],
				'classification' => $participant['classification'],
			);
		}
		return $rows;
	}

	/**
	 * Gruppe anlegen oder ändern: Platzclub, Lokal, Zeit, Gruppenchef, Bemerkung, Mitglieder.
	 * Wer in einer anderen Gruppe der Runde steht, wechselt in diese. In einer freigegebenen
	 * Runde erhalten die Mitglieder der geänderten Gruppen ein neues Aufgebot.
	 *
	 * @param int|null                                                                                                                   $group_id Bestehende Gruppe oder null für eine neue.
	 * @param array{category?:int,host_club_id?:int|null,venue_id?:int|null,start_at?:string|null,chief_player_id?:int|null,note?:string|null,members?:list<int>} $data Daten; fehlende Angaben bleiben bei einer bestehenden Gruppe unverändert.
	 * @return int ID der Gruppe.
	 */
	public function save_group( Actor $actor, int $round_id, ?int $group_id, array $data ): int {
		$this->require_tk( $actor );
		[ $round, $competition ] = $this->round( $round_id );
		$errors                  = array();
		$start_at                = null;
		if ( array_key_exists( 'start_at', $data ) && null !== $data['start_at'] && '' !== $data['start_at'] ) {
			$start_at = self::datetime( (string) $data['start_at'] );
			if ( null === $start_at ) {
				$errors[] = self::error( 'start_at', __( 'Datum und Zeit sind ungültig.', 'ttleague' ) );
			}
		}
		$note = array_key_exists( 'note', $data ) ? trim( (string) $data['note'] ) : null;
		if ( null !== $note && mb_strlen( $note ) > 500 ) {
			$errors[] = self::error( 'note', __( 'Die Bemerkung ist zu lang (höchstens 500 Zeichen).', 'ttleague' ) );
		}
		if ( array() !== $errors ) {
			Fail::invalid_fields( $errors );
		}

		[ $id, $affected ] = $this->db->transaction(
			function () use ( $actor, $round, $competition, $group_id, $data, $start_at, $note ): array {
				$current = $this->locked( $competition, $round['id'], array( self::PLANNED, self::PUBLISHED ) );
				$group   = null === $group_id ? null : $this->repo->group( $group_id );
				if ( null !== $group_id && ( null === $group || $group['round_id'] !== $current['id'] ) ) {
					Fail::not_found( __( 'Diese Gruppe gibt es nicht.', 'ttleague' ) );
				}
				$category = null === $group ? (int) ( $data['category'] ?? 0 ) : $group['category'];
				if ( $category < 1 || $category > 255 ) {
					Fail::invalid( 'category', __( 'Die Gruppe braucht eine Kategorie.', 'ttleague' ) );
				}

				// Lokal und Platzclub: Das Lokal gehört dem Platzclub.
				$venue_id = array_key_exists( 'venue_id', $data ) ? ( null === $data['venue_id'] ? null : (int) $data['venue_id'] ) : ( $group['venue_id'] ?? null );
				$host_id  = array_key_exists( 'host_club_id', $data ) ? ( null === $data['host_club_id'] ? null : (int) $data['host_club_id'] ) : ( $group['host_club_id'] ?? null );
				$venue_id = 0 === $venue_id ? null : $venue_id;
				$host_id  = 0 === $host_id ? null : $host_id;
				if ( null !== $venue_id ) {
					$venue = $this->repo->venue( $venue_id );
					if ( null === $venue ) {
						Fail::invalid( 'venue_id', __( 'Dieses Lokal gibt es nicht.', 'ttleague' ) );
					}
					$host_id ??= $venue['club_id'];
					if ( $host_id !== $venue['club_id'] ) {
						Fail::invalid( 'venue_id', __( 'Das Lokal gehört nicht zum Platzclub.', 'ttleague' ) );
					}
				}
				if ( null !== $host_id && ! $this->repo->club_exists( $host_id ) ) {
					Fail::invalid( 'host_club_id', __( 'Diesen Club gibt es nicht.', 'ttleague' ) );
				}

				// Mitglieder: Angemeldete der Kategorie, jede Person einmal.
				$old_members = null === $group ? array() : array_column( $group['members'], 'player_id' );
				$members     = array_key_exists( 'members', $data ) ? array_values( array_unique( array_map( 'intval', (array) $data['members'] ) ) ) : $old_members;
				$by_player   = array_column( $this->repo->participants( $competition['id'], $competition['season_id'], true ), null, 'player_id' );
				foreach ( $members as $player_id ) {
					if ( in_array( $player_id, $old_members, true ) ) {
						continue;
					}
					if ( ! isset( $by_player[ $player_id ] ) ) {
						Fail::invalid( 'members', __( 'In eine Gruppe gehören nur Angemeldete.', 'ttleague' ) );
					}
					if ( $by_player[ $player_id ]['category'] !== $category ) {
						/* translators: 1: Name, 2: Kategorie der Gruppe */
						Fail::invalid( 'members', sprintf( __( '%1$s ist nicht in der Kategorie %2$d angemeldet. Ändere zuerst die Kategorie der Anmeldung.', 'ttleague' ), $by_player[ $player_id ]['name'], $category ) );
					}
				}
				$added   = array_values( array_diff( $members, $old_members ) );
				$removed = array_values( array_diff( $old_members, $members ) );
				if ( null !== $group && array() !== $group['games'] && ( array() !== $added || array() !== $removed ) ) {
					Fail::conflict( __( 'Für diese Gruppe sind schon Partien erfasst. Setze zuerst den Gruppenzettel zurück.', 'ttleague' ) );
				}

				$before   = null === $group ? null : self::snapshot( $group );
				$affected = array();
				if ( null === $group ) {
					$group_no = 1 + (int) $this->db->get_var(
						"SELECT COALESCE(MAX(group_no), 0) FROM {$this->db->table( 'rl_group' )} WHERE rl_round_id = %d AND category = %d",
						array( $current['id'], $category )
					);
					$id       = $this->db->insert(
						'rl_group',
						array(
							'rl_round_id'  => $current['id'],
							'category'     => $category,
							'group_no'     => $group_no,
							'host_club_id' => $host_id,
							'venue_id'     => $venue_id,
							'start_at'     => $start_at,
							'note'         => '' === (string) $note ? null : $note,
						)
					);
				} else {
					$id     = $group['id'];
					$fields = array(
						'host_club_id' => $host_id,
						'venue_id'     => $venue_id,
					);
					if ( array_key_exists( 'start_at', $data ) ) {
						$fields['start_at'] = $start_at;
					}
					if ( null !== $note ) {
						$fields['note'] = '' === $note ? null : $note;
					}
					$this->db->update( 'rl_group', $fields, array( 'id' => $id ) );
				}

				// Wer aus einer anderen Gruppe der Runde kommt, verlässt sie.
				foreach ( $added as $player_id ) {
					foreach ( $this->repo->groups( $current['id'] ) as $other ) {
						if ( $other['id'] === $id || ! in_array( $player_id, array_column( $other['members'], 'player_id' ), true ) ) {
							continue;
						}
						if ( array() !== $other['games'] ) {
							/* translators: %s: Name */
							Fail::conflict( sprintf( __( 'Für die bisherige Gruppe von %s sind schon Partien erfasst.', 'ttleague' ), $by_player[ $player_id ]['name'] ) );
						}
						$this->db->delete(
							'rl_group_player',
							array(
								'rl_group_id' => $other['id'],
								'player_id'   => $player_id,
							)
						);
						$this->set_chief( $other['id'], null );
						$affected[] = $other['id'];
					}
				}
				foreach ( $removed as $player_id ) {
					$this->db->delete(
						'rl_group_player',
						array(
							'rl_group_id' => $id,
							'player_id'   => $player_id,
						)
					);
				}
				foreach ( $added as $player_id ) {
					$this->db->insert(
						'rl_group_player',
						array(
							'rl_group_id' => $id,
							'player_id'   => $player_id,
							'status'      => RlPlayerStatus::Scheduled->value,
						)
					);
				}
				// Ein ausdrücklich gewählter Gruppenchef gehört zur Gruppe; sonst bleibt der bisherige, solange er dabei ist.
				$chief = isset( $data['chief_player_id'] ) ? (int) $data['chief_player_id'] : 0;
				if ( 0 !== $chief && ! in_array( $chief, $members, true ) ) {
					Fail::invalid( 'chief_player_id', __( 'Der Gruppenchef gehört zur Gruppe.', 'ttleague' ) );
				}
				$this->set_chief( $id, 0 === $chief ? null : $chief );

				$saved = $this->repo->group( $id );
				$after = null === $saved ? null : self::snapshot( $saved );
				if ( $before !== $after ) {
					$this->audit->record( 'rl_group', $id, null === $before ? 'created' : 'changed', $actor->user_id, $before, $after );
					$affected[] = $id;
				}
				return array( $id, self::PUBLISHED === $current['status'] ? array_values( array_unique( $affected ) ) : array() );
			}
		);
		$this->events->changed( 'rl_group', $id );
		if ( array() !== $affected ) {
			$this->events->callup( $round_id, $affected, $actor->user_id, true );
		}
		return $id;
	}

	/**
	 * Gruppe ohne erfasste Partien löschen; ihre Mitglieder sind danach nicht zugeteilt.
	 */
	public function delete_group( Actor $actor, int $group_id ): void {
		$this->require_tk( $actor );
		$group = $this->repo->group( $group_id );
		if ( null === $group ) {
			Fail::not_found( __( 'Diese Gruppe gibt es nicht.', 'ttleague' ) );
		}
		[ , $competition ] = $this->round( $group['round_id'] );
		$this->db->transaction(
			function () use ( $actor, $group, $competition ): void {
				$this->locked( $competition, $group['round_id'], array( self::PLANNED, self::PUBLISHED ) );
				$current = $this->repo->group( $group['id'] );
				if ( null === $current ) {
					Fail::not_found( __( 'Diese Gruppe gibt es nicht.', 'ttleague' ) );
				}
				if ( array() !== $current['games'] || null !== $current['entered_at'] ) {
					Fail::conflict( __( 'Für diese Gruppe ist schon ein Gruppenzettel erfasst. Setze ihn zuerst zurück.', 'ttleague' ) );
				}
				$this->repo->delete_group( $current['id'] );
				$this->audit->record( 'rl_group', $current['id'], 'deleted', $actor->user_id, self::snapshot( $current ), null );
			}
		);
		$this->events->changed( 'rl_group', $group_id );
	}

	/**
	 * Runde freigeben: Die Gruppen werden öffentlich, die Aufgebotenen erhalten ihr Aufgebot.
	 */
	public function publish( Actor $actor, int $round_id ): void {
		$this->require_tk( $actor );
		[ $round, $competition ] = $this->round( $round_id );
		$this->require_open_season( $competition );
		$group_ids = $this->db->transaction(
			function () use ( $actor, $round, $competition ): array {
				$current = $this->locked( $competition, $round['id'], array( self::PLANNED ) );
				$this->require_previous_closed( $competition, $current );
				$groups = $this->repo->groups( $current['id'] );
				if ( array() === $groups ) {
					Fail::conflict( __( 'Die Runde hat noch keine Gruppen. Teile sie zuerst ein.', 'ttleague' ) );
				}
				$errors = array();
				foreach ( $groups as $group ) {
					$label = self::group_label( $group );
					if ( count( $group['members'] ) < 2 ) {
						/* translators: %s: Gruppe */
						$errors[] = self::error( 'group.' . $group['id'], sprintf( __( '%s hat weniger als zwei Personen.', 'ttleague' ), $label ) );
					}
					if ( null === $group['start_at'] ) {
						/* translators: %s: Gruppe */
						$errors[] = self::error( 'group.' . $group['id'], sprintf( __( '%s hat noch keinen Termin.', 'ttleague' ), $label ) );
					}
				}
				if ( array() !== $errors ) {
					Fail::invalid_fields( $errors );
				}
				$this->db->update( 'rl_round', array( 'status' => self::PUBLISHED ), array( 'id' => $current['id'] ) );
				$this->audit->record( 'rl_round', $current['id'], 'published', $actor->user_id, array( 'status' => self::PLANNED ), array( 'status' => self::PUBLISHED ) );
				return array_column( $groups, 'id' );
			}
		);
		$this->events->changed( 'rl_round', $round_id );
		$this->events->callup( $round_id, $group_ids, $actor->user_id, false );
	}

	/**
	 * Für den Abschluss: je Gruppe die berechnete Rangliste.
	 *
	 * @return list<array{group:array<string,mixed>,entered:bool,rows:list<array{player_id:int,name:string,status:string,rank:int|null,points:float|null,shared:bool,wins:int,losses:int,sets_won:int,sets_lost:int}>}>
	 */
	public function closing( int $round_id ): array {
		[ $round, $competition ] = $this->round( $round_id );
		$rules                   = Repository::rules( $competition );
		$result                  = array();
		foreach ( $this->repo->groups( $round['id'] ) as $group ) {
			$rows = array();
			foreach ( Sheet::ranking( $rules, array_column( $group['members'], 'status', 'player_id' ), $group['games'] ) as $player_id => $row ) {
				$member = $group['members'][ array_search( $player_id, array_column( $group['members'], 'player_id' ), true ) ];
				$rows[] = array(
					'player_id' => $player_id,
					'name'      => $member['name'],
					'status'    => $member['status'],
					'rank'      => $row->rank,
					'points'    => $row->status->is_ranked() ? $row->round_points : null,
					'shared'    => $row->shared_rank,
					'wins'      => $row->wins,
					'losses'    => $row->losses,
					'sets_won'  => $row->sets_won,
					'sets_lost' => $row->sets_lost,
				);
			}
			$result[] = array(
				'group'   => $group,
				'entered' => null !== $group['entered_at'],
				'rows'    => $rows,
			);
		}
		return $result;
	}

	/**
	 * Runde abschliessen: Rang und Punkte festschreiben. Es gelten die berechneten Werte, ausser
	 * die TK setzt Rang oder Punkte von Hand (Los bei unauflösbarem Gleichstand); das steht dann
	 * im audit_log.
	 *
	 * @param array<int,array<int,array{rank?:mixed,points?:mixed}>> $manual        Gruppe => Spieler => von Hand gesetzte Werte; leere Angaben gelten als «berechnet».
	 * @param bool                                                   $allow_missing Gruppen ohne Gruppenzettel bleiben ausdrücklich ohne Resultat.
	 */
	public function close( Actor $actor, int $round_id, array $manual = array(), bool $allow_missing = false ): void {
		$this->require_tk( $actor );
		[ $round, $competition ] = $this->round( $round_id );
		$rules                   = Repository::rules( $competition );
		$this->db->transaction(
			function () use ( $actor, $round, $competition, $rules, $manual, $allow_missing ): void {
				$current = $this->locked( $competition, $round['id'], array( self::PUBLISHED ) );
				$groups  = $this->repo->groups( $current['id'] );
				$missing = array_values( array_filter( $groups, static fn( array $group ): bool => null === $group['entered_at'] ) );
				if ( array() !== $missing && ! $allow_missing ) {
					Fail::conflict(
						/* translators: %s: Liste der Gruppen */
						sprintf( __( 'Für diese Gruppen fehlt der Gruppenzettel: %s. Trage sie nach oder schliesse sie ausdrücklich ohne Resultat ab.', 'ttleague' ), implode( ', ', array_map( array( self::class, 'group_label' ), $missing ) ) )
					);
				}
				$errors    = array();
				$overrides = 0;
				foreach ( $groups as $group ) {
					if ( null === $group['entered_at'] ) {
						continue;
					}
					$computed = array();
					$final    = array();
					foreach ( Sheet::ranking( $rules, array_column( $group['members'], 'status', 'player_id' ), $group['games'] ) as $player_id => $row ) {
						$ranked                 = $row->status->is_ranked();
						$computed[ $player_id ] = array(
							'rank'   => $ranked ? $row->rank : null,
							'points' => $ranked ? $row->round_points : null,
						);
						$final[ $player_id ]    = $computed[ $player_id ];
						if ( ! $ranked ) {
							continue;
						}
						$input = $manual[ $group['id'] ][ $player_id ] ?? array();
						$rank  = self::manual_rank( $input['rank'] ?? null, count( $group['members'] ) );
						$point = self::manual_points( $input['points'] ?? null );
						if ( false === $rank || false === $point ) {
							/* translators: 1: Gruppe, 2: Name */
							$errors[] = self::error( 'manual.' . $group['id'] . '.' . $player_id, sprintf( __( '%1$s, %2$s: Rang (ganze Zahl ab 1) oder Punkte (höchstens eine Dezimalstelle) sind ungültig.', 'ttleague' ), self::group_label( $group ), self::member_name( $group, $player_id ) ) );
							continue;
						}
						if ( null !== $rank ) {
							$final[ $player_id ]['rank'] = $rank;
						}
						if ( null !== $point ) {
							$final[ $player_id ]['points'] = $point;
						}
					}
					foreach ( $final as $player_id => $values ) {
						$this->db->update(
							'rl_group_player',
							array(
								'rank_no' => $values['rank'],
								'points'  => $values['points'],
							),
							array(
								'rl_group_id' => $group['id'],
								'player_id'   => $player_id,
							)
						);
					}
					$changed = array_filter( $final, static fn( array $values, int $player_id ): bool => $values['rank'] !== $computed[ $player_id ]['rank'] || abs( (float) $values['points'] - (float) $computed[ $player_id ]['points'] ) > 0.001, ARRAY_FILTER_USE_BOTH );
					if ( array() !== $changed ) {
						++$overrides;
						$this->audit->record(
							'rl_group',
							$group['id'],
							'rank_manual',
							$actor->user_id,
							array_intersect_key( $computed, $changed ),
							$changed,
							__( 'Rang oder Punkte beim Abschluss von Hand gesetzt.', 'ttleague' )
						);
					}
				}
				if ( array() !== $errors ) {
					Fail::invalid_fields( $errors );
				}
				$now = $this->clock->now()->format( 'Y-m-d H:i:s' );
				$this->db->update(
					'rl_round',
					array(
						'status'    => self::CLOSED,
						'closed_by' => $actor->user_id,
						'closed_at' => $now,
					),
					array( 'id' => $current['id'] )
				);
				$this->audit->record(
					'rl_round',
					$current['id'],
					'closed',
					$actor->user_id,
					array( 'status' => self::PUBLISHED ),
					array(
						'status'         => self::CLOSED,
						'without_result' => array_column( $missing, 'id' ),
						'manual_groups'  => $overrides,
					)
				);
			}
		);
		$this->events->changed( 'rl_round', $round_id );
	}

	/**
	 * Bezeichnung einer Gruppe, z. B. «Kategorie 1, Gruppe 2».
	 *
	 * @param array{category:int,group_no:int} $group Gruppe.
	 */
	public static function group_label( array $group ): string {
		/* translators: 1: Kategorie, 2: Gruppe */
		return sprintf( __( 'Kategorie %1$d, Gruppe %2$d', 'ttleague' ), $group['category'], $group['group_no'] );
	}

	/**
	 * Finalteilnehmer je Kategorie aus der Gesamtrangliste der abgeschlossenen Runden: die besten
	 * rl_finalists, die noch angemeldet sind. Läuft die Grenze durch einen Gleichstand, braucht es
	 * das Los; die Namen stehen im zweiten Wert.
	 *
	 * @param array<string,mixed>            $competition Konkurrenz.
	 * @param array<int,array<string,mixed>> $registered  Angemeldete nach Spieler.
	 * @return array{0:array<int,list<int>>,1:list<string>}
	 */
	private function finalists( array $competition, array $registered, int $count ): array {
		$categories = array();
		$draw       = array();
		foreach ( ( new Standings( $this->repo ) )->overall( $competition ) as $category => $rows ) {
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => isset( $registered[ $row['player_id'] ] ) ) );
			$last = $rows[ $count - 1 ] ?? null;
			$next = $rows[ $count ] ?? null;
			foreach ( array_slice( $rows, 0, $count ) as $row ) {
				$categories[ $category ][] = $row['player_id'];
			}
			// Gleichstand über die Grenze hinweg: gleicher Rang auf dem letzten Finalplatz und dem ersten danach.
			if ( null !== $last && null !== $next && $last['rank'] === $next['rank'] ) {
				foreach ( $rows as $row ) {
					if ( $row['rank'] === $last['rank'] ) {
						$draw[] = $row['name'];
					}
				}
			}
		}
		return array( $categories, $draw );
	}

	/**
	 * Gruppenchef setzen; ohne Angabe die erstgenannte Person des Platzclubs, sonst die erste
	 * der Gruppe. Ein gesetzter Chef bleibt, solange er zur Gruppe gehört.
	 */
	private function set_chief( int $group_id, ?int $chief ): void {
		$group = $this->repo->group( $group_id );
		if ( null === $group ) {
			return;
		}
		$members = array_column( $group['members'], 'player_id' );
		$chief ??= in_array( $group['chief_player_id'], $members, true ) ? $group['chief_player_id'] : null;
		if ( null === $chief ) {
			foreach ( $group['members'] as $member ) {
				if ( null !== $group['host_club_id'] && $member['club_id'] === $group['host_club_id'] ) {
					$chief = $member['player_id'];
					break;
				}
			}
			$chief ??= $members[0] ?? null;
		}
		$this->db->update( 'rl_group', array( 'chief_player_id' => $chief ), array( 'id' => $group_id ) );
	}

	/**
	 * Runde mit ihrer Konkurrenz.
	 *
	 * @return array{0:array<string,mixed>,1:array<string,mixed>}
	 */
	private function round( int $round_id ): array {
		$round       = $this->repo->round( $round_id );
		$competition = null === $round ? null : $this->repo->competition( $round['competition_id'] );
		if ( null === $round || null === $competition ) {
			Fail::not_found( __( 'Diese Runde gibt es nicht.', 'ttleague' ) );
		}
		return array( $round, $competition );
	}

	/**
	 * Saison und Runde sperren, Runde neu lesen und den Status prüfen (erste Abfrage der Transaktion).
	 *
	 * @param array<string,mixed> $competition Konkurrenz.
	 * @param list<string>        $allowed     Erlaubte Status.
	 * @return array<string,mixed> Runde.
	 */
	private function locked( array $competition, int $round_id, array $allowed ): array {
		$this->repo->lock_round( $competition['season_id'], $round_id );
		$round = $this->repo->round( $round_id );
		if ( null === $round ) {
			Fail::not_found( __( 'Diese Runde gibt es nicht.', 'ttleague' ) );
		}
		if ( ! in_array( $round['status'], $allowed, true ) ) {
			/* translators: %s: Status der Runde */
			Fail::conflict( sprintf( __( 'Im Status «%s» ist das nicht möglich.', 'ttleague' ), self::status_label( $round['status'] ) ) );
		}
		return $round;
	}

	/**
	 * @param array<string,mixed> $competition Konkurrenz.
	 * @param array<string,mixed> $round       Runde.
	 */
	private function require_previous_closed( array $competition, array $round ): void {
		foreach ( $this->repo->rounds( $competition['id'] ) as $other ) {
			if ( $other['round_no'] < $round['round_no'] && self::CLOSED !== $other['status'] ) {
				/* translators: %d: Nummer der Runde */
				Fail::conflict( sprintf( __( 'Schliesse zuerst die %d. Runde ab.', 'ttleague' ), $other['round_no'] ) );
			}
		}
	}

	/**
	 * @param array{season_status:string} $competition Konkurrenz.
	 */
	private function require_open_season( array $competition ): void {
		if ( 'archived' === $competition['season_status'] ) {
			Fail::conflict( __( 'Die Saison ist abgeschlossen.', 'ttleague' ) );
		}
	}

	private function check_week( string $date_from, string $date_to ): void {
		$errors = array();
		if ( null === Repository::date_or_null( $date_from ) ) {
			$errors[] = self::error( 'date_from', __( 'Der Beginn der Rundenwoche ist kein gültiges Datum.', 'ttleague' ) );
		}
		if ( null === Repository::date_or_null( $date_to ) ) {
			$errors[] = self::error( 'date_to', __( 'Das Ende der Rundenwoche ist kein gültiges Datum.', 'ttleague' ) );
		}
		if ( array() === $errors && $date_to < $date_from ) {
			$errors[] = self::error( 'date_to', __( 'Das Ende liegt vor dem Beginn.', 'ttleague' ) );
		}
		if ( array() === $errors && ( new \DateTimeImmutable( $date_from ) )->diff( new \DateTimeImmutable( $date_to ) )->days > 13 ) {
			$errors[] = self::error( 'date_to', __( 'Eine Runde dauert höchstens zwei Wochen.', 'ttleague' ) );
		}
		if ( array() !== $errors ) {
			Fail::invalid_fields( $errors );
		}
	}

	public static function status_label( string $status ): string {
		return match ( $status ) {
			self::PLANNED   => __( 'in Planung', 'ttleague' ),
			self::PUBLISHED => __( 'freigegeben', 'ttleague' ),
			self::CLOSED    => __( 'abgeschlossen', 'ttleague' ),
			default         => $status,
		};
	}

	/**
	 * Datum und Zeit aus einem Formular (J-M-T H:i, auch mit «T» oder Sekunden) als J-M-T H:i:s.
	 */
	public static function datetime( string $value ): ?string {
		$value = str_replace( 'T', ' ', trim( $value ) );
		foreach ( array( '!Y-m-d H:i:s', '!Y-m-d H:i' ) as $format ) {
			$time = \DateTimeImmutable::createFromFormat( $format, $value );
			if ( false !== $time && $time->format( substr( $format, 1 ) ) === $value ) {
				return $time->format( 'Y-m-d H:i:s' );
			}
		}
		return null;
	}

	/**
	 * Von Hand gesetzter Rang: null = berechnet, false = ungültig.
	 */
	private static function manual_rank( mixed $value, int $members ): int|null|false {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! is_scalar( $value ) || 1 !== preg_match( '/^[1-9][0-9]?$/D', (string) $value ) || (int) $value > $members ) {
			return false;
		}
		return (int) $value;
	}

	/**
	 * Von Hand gesetzte Punkte (Komma oder Punkt, höchstens eine Dezimalstelle): null = berechnet, false = ungültig.
	 */
	private static function manual_points( mixed $value ): float|null|false {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$text = is_scalar( $value ) ? str_replace( ',', '.', trim( (string) $value ) ) : '';
		if ( 1 !== preg_match( '/^[0-9]{1,2}(\.[0-9])?$/D', $text ) ) {
			return false;
		}
		return (float) $text;
	}

	/**
	 * @param array<string,mixed> $group Gruppe.
	 */
	private static function member_name( array $group, int $player_id ): string {
		foreach ( $group['members'] as $member ) {
			if ( $member['player_id'] === $player_id ) {
				return $member['name'];
			}
		}
		return (string) $player_id;
	}

	/**
	 * Stand einer Gruppe für das Protokoll und für den Vergleich «hat sich das Aufgebot geändert?».
	 *
	 * @param array<string,mixed> $group Gruppe.
	 * @return array<string,mixed>
	 */
	private static function snapshot( array $group ): array {
		return array(
			'category'        => $group['category'],
			'group_no'        => $group['group_no'],
			'host_club_id'    => $group['host_club_id'],
			'venue_id'        => $group['venue_id'],
			'start_at'        => $group['start_at'],
			'chief_player_id' => $group['chief_player_id'],
			'note'            => $group['note'],
			'members'         => array_column( $group['members'], 'status', 'player_id' ),
		);
	}

	private function require_tk( Actor $actor ): void {
		if ( ! $actor->is_tk ) {
			Fail::forbidden( __( 'Das kann nur die TK.', 'ttleague' ) );
		}
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
