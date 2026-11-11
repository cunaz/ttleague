<?php
/**
 * Kategorien und Gruppen der geplanten Saison: Konkurrenzen anlegen, Mannschaften zuteilen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldungen der Ausnahmen sind Klartext; escaped wird bei der Ausgabe (Flash).

namespace TTLeague\Season;

use TTLeague\Domain\Season\GroupingRules;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\ReadModel\Cache\NullCache;
use TTLeague\ReadModel\Championship;
use TTLeague\Registration\RegistrationRepository;
use TTLeague\Results\Actor;

/**
 * Schritt 4 des Assistenten (docs/saison.md). Das System schlägt die Einteilung aus dem Vorjahr
 * vor und zeigt dessen Schlussrangliste; Auf- und Abstieg setzt die TK von Hand (Art. 11.5).
 * Die Hinweise (GroupingRules::hints()) sind weich.
 *
 * Alles verlangt die TK und gilt nur für die geplante Saison. Jeder Schreibvorgang läuft in
 * einer Transaktion unter der Sperre der Saisons, steht im audit_log (competition, team) und
 * meldet danach SeasonEvents::changed(). Eine Mannschaft mit Begegnungen im Spielplanentwurf
 * wechselt die Gruppe nicht: Zuerst wird der Entwurf ihrer Gruppe gelöscht.
 */
final class Grouping {

	public const MM  = 'MM';
	public const CUP = 'CUP';
	public const RL  = 'RL';

	private SeasonGuard $guard;

	private RegistrationRepository $registrations;

	public function __construct(
		private readonly Db $db,
		private readonly AuditLog $audit,
		private readonly SeasonEvents $events,
	) {
		$this->guard         = new SeasonGuard( $db );
		$this->registrations = new RegistrationRepository( $db );
	}

	/**
	 * Stand der Einteilung einer Saison mit Vorschlag, Hinweisen und der Schlussrangliste des Vorjahrs.
	 *
	 * @return array{
	 *   groups:list<array{id:int,name:string,category:string|null,group_no:int,teams:int,fixtures:int}>,
	 *   teams:list<array{id:int,name:string,number:int,club_id:int,club:string,competition_id:int|null,fixtures:int,previous:array{group:string,rank:int|null,withdrawn:bool}|null,proposal:array{category:string,group_no:int,competition_id:int|null}|null}>,
	 *   hints:list<array{code:string,message:string,group_id:int|null,team_id:int|null,club_id:int|null}>,
	 *   previous:array{name:string,groups:list<array{name:string,category:string|null,group_no:int,rows:list<array{rank:int,team:string,played:int,points:int,withdrawn:bool}>}>}|null,
	 *   other:list<array{id:int,type:string,name:string,category:string|null}>,
	 *   copyable:list<array{type:string,name:string,category:string|null}>
	 * }
	 * @throws SeasonException Wenn es die Saison nicht gibt.
	 */
	public function state( int $season_id ): array {
		$season   = $this->guard->season( $season_id );
		$groups   = $this->groups( $season_id );
		$teams    = $this->teams( $season_id );
		$previous = $this->registrations->previous_season( $season );

		$before    = array();
		$standings = null;
		$copyable  = array();
		if ( null !== $previous ) {
			$data      = ( new Championship( $this->db, new NullCache() ) )->season( $previous['id'] );
			$standings = array(
				'name'   => $previous['name'],
				'groups' => array(),
			);
			foreach ( $data['groups'] as $group ) {
				$rows = array();
				foreach ( $group['standings'] as $row ) {
					$team = $data['teams'][ $row['team_id'] ] ?? null;
					if ( null === $team ) {
						continue;
					}
					$rows[]   = array(
						'rank'      => (int) $row['rank'],
						'team'      => (string) $team['name'],
						'played'    => (int) $row['played'],
						'points'    => (int) $row['points'],
						'withdrawn' => (bool) $row['withdrawn'],
					);
					$before[] = array(
						'club_id'   => (int) $team['club_id'],
						'number'    => (int) $team['number'],
						'category'  => $group['category'],
						'group_no'  => (int) $group['group_no'],
						'group'     => (string) $group['name'],
						'rank'      => (int) $row['rank'],
						'withdrawn' => (bool) $row['withdrawn'],
					);
				}
				$standings['groups'][] = array(
					'name'     => (string) $group['name'],
					'category' => $group['category'],
					'group_no' => (int) $group['group_no'],
					'rows'     => $rows,
				);
			}
			$copyable = $this->copyable( $previous['id'], $season_id );
		}

		$proposal  = GroupingRules::proposal( $teams, $before );
		$by_key    = array();
		$group_ids = array();
		foreach ( $before as $row ) {
			$by_key[ $row['club_id'] . ':' . $row['number'] ] = $row;
		}
		foreach ( $groups as $group ) {
			$group_ids[ $group['category'] . ':' . $group['group_no'] ] = $group['id'];
		}
		foreach ( $teams as &$team ) {
			$old              = $by_key[ $team['club_id'] . ':' . $team['number'] ] ?? null;
			$team['previous'] = null === $old ? null : array(
				'group'     => $old['group'],
				'rank'      => $old['rank'],
				'withdrawn' => $old['withdrawn'],
			);
			$suggested        = $proposal[ $team['id'] ] ?? null;
			$team['proposal'] = null === $suggested ? null : $suggested + array(
				'competition_id' => $group_ids[ $suggested['category'] . ':' . $suggested['group_no'] ] ?? null,
			);
		}
		unset( $team );

		$clubs = array();
		foreach ( $teams as $team ) {
			$clubs[ $team['club_id'] ] = $team['club'];
		}
		return array(
			'groups'   => $groups,
			'teams'    => $teams,
			'hints'    => GroupingRules::hints( $groups, $teams, $clubs ),
			'previous' => $standings,
			'other'    => $this->other( $season_id ),
			'copyable' => $copyable,
		);
	}

	/**
	 * Meisterschaftsgruppe anlegen.
	 *
	 * @return int ID der Konkurrenz.
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der geplanten Saison, bei ungültigen
	 *                         Angaben oder wenn es die Gruppe schon gibt.
	 */
	public function create_group( Actor $actor, int $season_id, string $category, int $group_no, string $name = '' ): int {
		SeasonGuard::require_tk( $actor );
		$id = $this->db->transaction(
			function () use ( $actor, $season_id, $category, $group_no, $name ): int {
				$this->guard->lock();
				$this->guard->planned( $season_id );
				$group = GroupingRules::group( $category, $group_no, $name );
				SeasonGuard::reject( $group['errors'] );
				if ( null !== $this->group_id( $season_id, $group['category'], $group['group_no'] ) ) {
					/* translators: 1: Kategorie, 2: Gruppennummer */
					throw SeasonException::field( 'group_no', sprintf( __( 'Die Gruppe %2$d der Kategorie %1$s gibt es schon.', 'ttleague' ), $group['category'], $group['group_no'] ) );
				}
				$id = $this->insert_group( $season_id, $group['category'], $group['group_no'], $group['name'] );
				$this->resort( $season_id );
				$this->audit->record( 'competition', $id, 'created', $actor->user_id, null, array_diff_key( $group, array( 'errors' => true ) ) + array( 'season_id' => $season_id ) );
				return $id;
			}
		);
		$this->events->changed( $season_id );
		return $id;
	}

	/**
	 * Gruppe umbenennen.
	 *
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der geplanten Saison oder ohne gültigen Namen.
	 */
	public function rename_group( Actor $actor, int $competition_id, string $name ): void {
		SeasonGuard::require_tk( $actor );
		$season_id = $this->db->transaction(
			function () use ( $actor, $competition_id, $name ): int {
				$this->guard->lock();
				$group = $this->group( $competition_id );
				$name  = trim( $name );
				$error = GroupingRules::name_error( $name );
				if ( null !== $error ) {
					throw SeasonException::field( 'name', $error );
				}
				$this->db->update( 'competition', array( 'name' => $name ), array( 'id' => $competition_id ) );
				$this->audit->record( 'competition', $competition_id, 'renamed', $actor->user_id, array( 'name' => $group['name'] ), array( 'name' => $name ) );
				return $group['season_id'];
			}
		);
		$this->events->changed( $season_id );
	}

	/**
	 * Leere Gruppe löschen: ohne Mannschaften und ohne Begegnungen.
	 *
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der geplanten Saison oder wenn die Gruppe nicht leer ist.
	 */
	public function delete_group( Actor $actor, int $competition_id ): void {
		SeasonGuard::require_tk( $actor );
		$season_id = $this->db->transaction(
			function () use ( $actor, $competition_id ): int {
				$this->guard->lock();
				$group = $this->group( $competition_id );
				if ( $group['teams'] > 0 || $group['fixtures'] > 0 ) {
					/* translators: %s: Gruppe */
					throw SeasonException::conflict( sprintf( __( '%s ist nicht leer. Teile zuerst ihre Mannschaften um und lösche ihren Spielplanentwurf.', 'ttleague' ), $group['name'] ) );
				}
				$this->db->delete( 'competition', array( 'id' => $competition_id ) );
				$this->resort( $group['season_id'] );
				$this->audit->record( 'competition', $competition_id, 'deleted', $actor->user_id, $group );
				return $group['season_id'];
			}
		);
		$this->events->changed( $season_id );
	}

	/**
	 * Eine Mannschaft einer Gruppe zuteilen oder aus ihrer Gruppe nehmen (null).
	 *
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der geplanten Saison, bei einer fremden
	 *                         Gruppe oder wenn die Mannschaft schon Begegnungen hat.
	 */
	public function assign( Actor $actor, int $team_id, ?int $competition_id ): void {
		SeasonGuard::require_tk( $actor );
		$team = $this->db->get_row( "SELECT season_id FROM {$this->db->table( 'team' )} WHERE id = %d", array( $team_id ) );
		if ( null === $team ) {
			throw SeasonException::not_found( __( 'Diese Mannschaft gibt es nicht.', 'ttleague' ) );
		}
		$this->assign_many( $actor, (int) $team['season_id'], array( $team_id => $competition_id ) );
	}

	/**
	 * Mehrere Mannschaften in einem Schritt zuteilen (Formular der Einteilung). Entweder gilt
	 * alles oder nichts.
	 *
	 * @param array<int,int|null> $assignments Mannschaft => Gruppe oder null (ohne Gruppe).
	 * @return int Anzahl der Mannschaften, deren Gruppe sich geändert hat.
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der geplanten Saison, bei einer
	 *                         Mannschaft oder Gruppe einer anderen Saison oder wenn eine
	 *                         Mannschaft, die wechseln soll, schon Begegnungen hat.
	 */
	public function assign_many( Actor $actor, int $season_id, array $assignments ): int {
		SeasonGuard::require_tk( $actor );
		$changed = $this->db->transaction(
			function () use ( $actor, $season_id, $assignments ): int {
				$this->guard->lock();
				$this->guard->planned( $season_id );
				$groups = array_column( $this->groups( $season_id ), null, 'id' );
				$teams  = array_column( $this->teams( $season_id ), null, 'id' );
				$count  = 0;
				foreach ( $assignments as $team_id => $competition_id ) {
					$team = $teams[ (int) $team_id ] ?? null;
					if ( null === $team ) {
						throw SeasonException::not_found( __( 'Diese Mannschaft gehört nicht zur geplanten Saison.', 'ttleague' ) );
					}
					$competition_id = null === $competition_id || (int) $competition_id <= 0 ? null : (int) $competition_id;
					if ( null !== $competition_id && ! isset( $groups[ $competition_id ] ) ) {
						throw SeasonException::not_found( __( 'Diese Gruppe gehört nicht zur geplanten Saison.', 'ttleague' ) );
					}
					if ( $team['competition_id'] === $competition_id ) {
						continue;
					}
					if ( $team['fixtures'] > 0 ) {
						/* translators: %s: Mannschaft */
						throw SeasonException::conflict( sprintf( __( '%s hat schon Begegnungen im Spielplanentwurf. Lösche zuerst den Entwurf ihrer Gruppe.', 'ttleague' ), $team['name'] ) );
					}
					$this->db->update( 'team', array( 'competition_id' => $competition_id ), array( 'id' => $team['id'] ) );
					$this->audit->record( 'team', $team['id'], 'assigned', $actor->user_id, array( 'competition_id' => $team['competition_id'] ), array( 'competition_id' => $competition_id ) );
					++$count;
				}
				return $count;
			}
		);
		if ( $changed > 0 ) {
			$this->events->changed( $season_id );
		}
		return $changed;
	}

	/**
	 * Vorschlag aus dem Vorjahr übernehmen: Für jede Gruppe des Vorjahrs entsteht, falls sie
	 * fehlt, die Gruppe mit derselben Kategorie und Gruppennummer; jede Mannschaft ohne Gruppe,
	 * die es im Vorjahr gab (gleicher Club, gleiche Nummer), kommt in ihre bisherige Gruppe.
	 * Schon zugeteilte Mannschaften und neue Mannschaften bleiben, wie sie sind.
	 *
	 * @return array{groups:int,teams:int} Angelegte Gruppen und zugeteilte Mannschaften.
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der geplanten Saison oder ohne Vorsaison.
	 */
	public function apply_proposal( Actor $actor, int $season_id ): array {
		SeasonGuard::require_tk( $actor );
		$result = $this->db->transaction(
			function () use ( $actor, $season_id ): array {
				$this->guard->lock();
				$season   = $this->guard->planned( $season_id );
				$previous = $this->registrations->previous_season( $season );
				if ( null === $previous ) {
					throw SeasonException::conflict( __( 'Es gibt keine frühere Saison, aus der sich ein Vorschlag ableiten liesse.', 'ttleague' ) );
				}
				$created = 0;
				foreach ( $this->groups( $previous['id'] ) as $old ) {
					if ( null === $old['category'] || '' === $old['category'] || null !== $this->group_id( $season_id, $old['category'], $old['group_no'] ) ) {
						continue;
					}
					$id = $this->insert_group( $season_id, $old['category'], $old['group_no'], $old['name'] );
					$this->audit->record(
						'competition',
						$id,
						'created',
						$actor->user_id,
						null,
						array(
							'season_id'   => $season_id,
							'category'    => $old['category'],
							'group_no'    => $old['group_no'],
							'name'        => $old['name'],
							'copied_from' => $old['id'],
						)
					);
					++$created;
				}
				if ( $created > 0 ) {
					$this->resort( $season_id );
				}
				$assigned = 0;
				foreach ( $this->state( $season_id )['teams'] as $team ) {
					if ( null !== $team['competition_id'] || null === $team['proposal'] || null === $team['proposal']['competition_id'] ) {
						continue;
					}
					$this->db->update( 'team', array( 'competition_id' => $team['proposal']['competition_id'] ), array( 'id' => $team['id'] ) );
					$this->audit->record( 'team', $team['id'], 'assigned', $actor->user_id, array( 'competition_id' => null ), array( 'competition_id' => $team['proposal']['competition_id'] ), 'Vorschlag aus dem Vorjahr' );
					++$assigned;
				}
				return array(
					'groups' => $created,
					'teams'  => $assigned,
				);
			}
		);
		if ( $result['groups'] + $result['teams'] > 0 ) {
			$this->events->changed( $season_id );
		}
		return $result;
	}

	/**
	 * Cup-Konkurrenzen und das Ranglistenturnier aus der Vorsaison übernehmen: Typ, Name,
	 * Kategorie und Format ohne die Fristen (GroupingRules::carried_format()). Was es in der
	 * geplanten Saison schon gibt (gleicher Typ, gleiche Kategorie), bleibt unberührt.
	 *
	 * @return int Anzahl der angelegten Konkurrenzen.
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der geplanten Saison oder ohne Vorsaison.
	 */
	public function copy_competitions( Actor $actor, int $season_id ): int {
		SeasonGuard::require_tk( $actor );
		$created = $this->db->transaction(
			function () use ( $actor, $season_id ): int {
				$this->guard->lock();
				$season   = $this->guard->planned( $season_id );
				$previous = $this->registrations->previous_season( $season );
				if ( null === $previous ) {
					throw SeasonException::conflict( __( 'Es gibt keine frühere Saison, aus der sich Konkurrenzen übernehmen liessen.', 'ttleague' ) );
				}
				$count = 0;
				foreach ( $this->copyable( $previous['id'], $season_id ) as $old ) {
					$format = json_decode( (string) $old['format_json'], true );
					$id     = $this->db->insert(
						'competition',
						array(
							'season_id'   => $season_id,
							'type'        => $old['type'],
							'name'        => $old['name'],
							'category'    => $old['category'],
							'group_no'    => $old['group_no'],
							'sort_order'  => $old['sort_order'],
							'format_json' => is_array( $format ) ? (string) json_encode( GroupingRules::carried_format( $format ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null, // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- läuft auch ohne WordPress (Tests, Werkzeuge).
						)
					);
					$this->audit->record(
						'competition',
						$id,
						'created',
						$actor->user_id,
						null,
						array(
							'season_id'   => $season_id,
							'type'        => $old['type'],
							'name'        => $old['name'],
							'category'    => $old['category'],
							'copied_from' => $old['id'],
						)
					);
					++$count;
				}
				return $count;
			}
		);
		if ( $created > 0 ) {
			$this->events->changed( $season_id );
		}
		return $created;
	}

	/**
	 * Meisterschaftsgruppen einer Saison mit Anzahl Mannschaften und Begegnungen.
	 *
	 * @return list<array{id:int,season_id:int,name:string,category:string|null,group_no:int,teams:int,fixtures:int}>
	 */
	public function groups( int $season_id ): array {
		$rows = $this->db->get_results(
			"SELECT c.id, c.season_id, c.name, c.category, c.group_no,
				( SELECT COUNT(*) FROM {$this->db->table( 'team' )} t WHERE t.competition_id = c.id ) AS teams,
				( SELECT COUNT(*) FROM {$this->db->table( 'fixture' )} f WHERE f.competition_id = c.id ) AS fixtures
			FROM {$this->db->table( 'competition' )} c
			WHERE c.season_id = %d AND c.type = %s
			ORDER BY c.sort_order, c.category, c.group_no, c.id",
			array( $season_id, self::MM )
		);
		return array_map( array( self::class, 'group_row' ), $rows );
	}

	/**
	 * Mannschaften einer Saison mit Club und Anzahl Begegnungen, nach Club und Nummer.
	 *
	 * @return list<array{id:int,name:string,number:int,club_id:int,club:string,competition_id:int|null,fixtures:int}>
	 */
	public function teams( int $season_id ): array {
		$fixture = $this->db->table( 'fixture' );
		$rows    = $this->db->get_results(
			"SELECT t.id, t.name, t.number, t.club_id, t.competition_id, c.name AS club,
				( SELECT COUNT(*) FROM {$fixture} f WHERE f.home_team_id = t.id OR f.away_team_id = t.id ) AS fixtures
			FROM {$this->db->table( 'team' )} t
			JOIN {$this->db->table( 'club' )} c ON c.id = t.club_id
			WHERE t.season_id = %d
			ORDER BY c.name, t.number, t.id",
			array( $season_id )
		);
		return array_map(
			static fn( array $row ): array => array(
				'id'             => (int) $row['id'],
				'name'           => (string) $row['name'],
				'number'         => (int) $row['number'],
				'club_id'        => (int) $row['club_id'],
				'club'           => (string) $row['club'],
				'competition_id' => null === $row['competition_id'] ? null : (int) $row['competition_id'],
				'fixtures'       => (int) $row['fixtures'],
			),
			$rows
		);
	}

	/**
	 * Cup-Konkurrenzen und Ranglistenturnier einer Saison.
	 *
	 * @return list<array{id:int,type:string,name:string,category:string|null}>
	 */
	private function other( int $season_id ): array {
		$rows = $this->db->get_results(
			"SELECT id, type, name, category FROM {$this->db->table( 'competition' )} WHERE season_id = %d AND type IN ( %s, %s ) ORDER BY type, sort_order, category, id",
			array( $season_id, self::CUP, self::RL )
		);
		return array_map(
			static fn( array $row ): array => array(
				'id'       => (int) $row['id'],
				'type'     => (string) $row['type'],
				'name'     => (string) $row['name'],
				'category' => $row['category'],
			),
			$rows
		);
	}

	/**
	 * Cup-Konkurrenzen und Ranglistenturnier der Vorsaison, die es in der Saison noch nicht gibt
	 * (gleicher Typ, gleiche Kategorie).
	 *
	 * @return list<array{id:int,type:string,name:string,category:string|null,group_no:int|null,sort_order:int,format_json:string|null}>
	 */
	private function copyable( int $from_season, int $season_id ): array {
		$existing = array();
		foreach ( $this->other( $season_id ) as $row ) {
			$existing[ $row['type'] . ':' . (string) $row['category'] ] = true;
		}
		$rows = array();
		foreach ( $this->db->get_results(
			"SELECT id, type, name, category, group_no, sort_order, format_json FROM {$this->db->table( 'competition' )} WHERE season_id = %d AND type IN ( %s, %s ) ORDER BY type, sort_order, category, id",
			array( $from_season, self::CUP, self::RL )
		) as $row ) {
			$key = $row['type'] . ':' . (string) $row['category'];
			if ( isset( $existing[ $key ] ) ) {
				continue;
			}
			$existing[ $key ] = true;
			$rows[]           = array(
				'id'          => (int) $row['id'],
				'type'        => (string) $row['type'],
				'name'        => (string) $row['name'],
				'category'    => $row['category'],
				'group_no'    => null === $row['group_no'] ? null : (int) $row['group_no'],
				'sort_order'  => (int) $row['sort_order'],
				'format_json' => $row['format_json'],
			);
		}
		return $rows;
	}

	/**
	 * Gruppe der geplanten Saison, unter der Sperre gelesen.
	 *
	 * @return array{id:int,season_id:int,name:string,category:string|null,group_no:int,teams:int,fixtures:int}
	 * @throws SeasonException Wenn es die Gruppe nicht gibt oder ihre Saison nicht geplant ist.
	 */
	private function group( int $competition_id ): array {
		$season_id = $this->db->get_var( "SELECT season_id FROM {$this->db->table( 'competition' )} WHERE id = %d AND type = %s", array( $competition_id, self::MM ) );
		if ( null === $season_id ) {
			throw SeasonException::not_found( __( 'Diese Gruppe gibt es nicht.', 'ttleague' ) );
		}
		$this->guard->planned( (int) $season_id );
		foreach ( $this->groups( (int) $season_id ) as $group ) {
			if ( $group['id'] === $competition_id ) {
				return $group;
			}
		}
		throw SeasonException::not_found( __( 'Diese Gruppe gibt es nicht.', 'ttleague' ) );
	}

	private function group_id( int $season_id, string $category, int $group_no ): ?int {
		$id = $this->db->get_var(
			"SELECT id FROM {$this->db->table( 'competition' )} WHERE season_id = %d AND type = %s AND category = %s AND COALESCE( group_no, 1 ) = %d ORDER BY id LIMIT 1",
			array( $season_id, self::MM, $category, $group_no )
		);
		return null === $id ? null : (int) $id;
	}

	private function insert_group( int $season_id, string $category, int $group_no, string $name ): int {
		return $this->db->insert(
			'competition',
			array(
				'season_id'  => $season_id,
				'type'       => self::MM,
				'name'       => $name,
				'category'   => $category,
				'group_no'   => $group_no,
				'sort_order' => 0,
			)
		);
	}

	/**
	 * Reihenfolge der Gruppen neu setzen: nach Kategorie und Gruppennummer, ab 1.
	 */
	private function resort( int $season_id ): void {
		$ids = $this->db->get_col(
			"SELECT id FROM {$this->db->table( 'competition' )} WHERE season_id = %d AND type = %s ORDER BY category, group_no, id",
			array( $season_id, self::MM )
		);
		foreach ( $ids as $index => $id ) {
			$this->db->update( 'competition', array( 'sort_order' => $index + 1 ), array( 'id' => (int) $id ) );
		}
	}

	/**
	 * @param array<string,string|null> $row Zeile.
	 * @return array{id:int,season_id:int,name:string,category:string|null,group_no:int,teams:int,fixtures:int}
	 */
	private static function group_row( array $row ): array {
		return array(
			'id'        => (int) $row['id'],
			'season_id' => (int) $row['season_id'],
			'name'      => (string) $row['name'],
			'category'  => $row['category'],
			'group_no'  => max( 1, (int) $row['group_no'] ),
			'teams'     => (int) $row['teams'],
			'fixtures'  => (int) $row['fixtures'],
		);
	}
}
