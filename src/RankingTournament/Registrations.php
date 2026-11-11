<?php
/**
 * Anmeldung zum Ranglistenturnier, Kategorien, Sperrdaten und Abmeldung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Access\AccessPolicy;
use TTLeague\Domain\Ranking\Categories;
use TTLeague\Domain\Ranking\RlPlayerStatus;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Results\Actor;

/**
 * Wer handeln darf:
 * - für sich selbst jede Person mit einer Meldung in der Saison,
 * - für Spielerinnen und Spieler seines Clubs der TK-Chef,
 * - für alle die TK.
 * Anmelden können Personen und TK-Chefs bis und mit dem Tag des Anmeldeschlusses (Ortszeit),
 * danach nur die TK. Abmelden und Sperrdaten melden bleiben möglich.
 *
 * Sperrdaten sind Zeilen in availability mit context «rl», eine pro gesperrtem Abend. Eine
 * Zeile mit context «rl_answer» über die ganze Rundenwoche hält fest, dass die Person ihre
 * Sperrdaten für diese Woche gemeldet hat, auch wenn sie an jedem Abend kann.
 */
final class Registrations {

	public const REGISTERED = 'registered';
	public const WITHDRAWN  = 'withdrawn';

	/** Kontext der gesperrten Abende in availability. */
	public const CONTEXT = 'rl';

	/** Kontext der Zeile «Sperrdaten gemeldet» für eine Rundenwoche. */
	public const CONTEXT_ANSWER = 'rl_answer';

	private Repository $repo;

	public function __construct(
		private readonly Db $db,
		private readonly AccessPolicy $policy,
		private readonly Clock $clock,
		private readonly \DateTimeZone $timezone,
		private readonly AuditLog $audit,
		private readonly Events $events,
	) {
		$this->repo = new Repository( $db );
	}

	public function repository(): Repository {
		return $this->repo;
	}

	/**
	 * Konkurrenz der aktiven Saison oder null.
	 *
	 * @return array<string,mixed>|null Siehe Repository::competition().
	 */
	public function active_competition(): ?array {
		$season = $this->repo->active_season_id();
		return null === $season ? null : $this->repo->competition_of_season( $season );
	}

	/**
	 * Turnier der Saison anlegen oder seine Einstellungen ändern (TK).
	 *
	 * @param array{name?:string,categories?:mixed,registration_deadline?:string|null,start_time?:string|null} $settings Einstellungen.
	 * @return int ID der Konkurrenz.
	 */
	public function configure( Actor $actor, int $season_id, array $settings ): int {
		$this->require_tk( $actor );
		$season = $this->db->get_row( "SELECT id, status FROM {$this->db->table( 'season' )} WHERE id = %d", array( $season_id ) );
		if ( null === $season ) {
			Fail::not_found( __( 'Diese Saison gibt es nicht.', 'ttleague' ) );
		}
		if ( 'archived' === $season['status'] ) {
			Fail::conflict( __( 'Die Saison ist abgeschlossen.', 'ttleague' ) );
		}
		$errors = array();
		$name   = trim( (string) ( $settings['name'] ?? '' ) );
		if ( mb_strlen( $name ) > 80 ) {
			$errors[] = self::error( 'name', __( 'Der Name ist zu lang (höchstens 80 Zeichen).', 'ttleague' ) );
		}
		$raw        = $settings['categories'] ?? array();
		$categories = Categories::normalize( $raw );
		if ( ! is_array( $raw ) || count( $categories ) !== count( $raw ) ) {
			$errors[] = self::error( 'categories', __( 'Kategorien brauchen eine Nummer ab 1 und eine Klassierung als Grenze; jede Nummer kommt einmal vor.', 'ttleague' ) );
		}
		$deadline = $settings['registration_deadline'] ?? null;
		$deadline = '' === $deadline ? null : $deadline;
		if ( null !== $deadline && null === Repository::date_or_null( $deadline ) ) {
			$errors[] = self::error( 'registration_deadline', __( 'Der Anmeldeschluss ist kein gültiges Datum.', 'ttleague' ) );
		}
		$start = $settings['start_time'] ?? null;
		$start = '' === $start ? null : $start;
		if ( null !== $start && null === Repository::time_or_null( $start ) ) {
			$errors[] = self::error( 'start_time', __( 'Die Spielzeit ist keine gültige Uhrzeit.', 'ttleague' ) );
		}
		if ( array() !== $errors ) {
			Fail::invalid_fields( $errors );
		}

		$id = $this->db->transaction(
			function () use ( $actor, $season_id, $name, $categories, $deadline, $start ): int {
				$existing = $this->repo->competition_of_season( $season_id );
				$format   = null === $existing ? array() : $existing['format'];
				$before   = null === $existing ? null : array(
					'name'   => $existing['name'],
					'format' => $format,
				);
				usort( $categories, static fn( array $a, array $b ): int => $a['no'] <=> $b['no'] );
				$format['categories']            = $categories;
				$format['registration_deadline'] = $deadline;
				$format['start_time']            = null === $start ? null : substr( (string) Repository::time_or_null( $start ), 0, 5 );
				$json                            = (string) json_encode( $format, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- läuft auch ohne WordPress.
				$title                           = '' === $name ? ( null === $existing ? __( 'Ranglistenturnier', 'ttleague' ) : $existing['name'] ) : $name;
				if ( null === $existing ) {
					$id = $this->db->insert(
						'competition',
						array(
							'season_id'   => $season_id,
							'type'        => 'RL',
							'name'        => $title,
							'format_json' => $json,
						)
					);
				} else {
					$id = $existing['id'];
					$this->db->update(
						'competition',
						array(
							'name'        => $title,
							'format_json' => $json,
						),
						array( 'id' => $id )
					);
				}
				$this->audit->record(
					'rl_competition',
					$id,
					null === $existing ? 'created' : 'configured',
					$actor->user_id,
					$before,
					array(
						'name'   => $title,
						'format' => $format,
					)
				);
				return $id;
			}
		);
		$this->events->changed( 'rl_competition', $id );
		return $id;
	}

	/**
	 * Ist die Anmeldung für Personen und TK-Chefs offen? Der Tag des Anmeldeschlusses zählt
	 * noch dazu (Ortszeit); ohne Anmeldeschluss ist sie offen.
	 *
	 * @param array{deadline:string|null,season_status:string} $competition Konkurrenz.
	 */
	public function registration_open( array $competition ): bool {
		if ( 'active' !== $competition['season_status'] ) {
			return false;
		}
		return null === $competition['deadline'] || $this->today() <= $competition['deadline'];
	}

	/**
	 * Person zum Turnier anmelden. Die Kategorie folgt aus der Klassierung; nur die TK kann
	 * eine andere mitgeben. Wer schon angemeldet ist, bleibt es unverändert.
	 *
	 * @return array{id:int,category:int,status:string}
	 */
	public function register( Actor $actor, int $player_id, ?int $category = null ): array {
		[ $competition, $player ] = $this->target( $actor, $player_id );
		if ( ! $actor->is_tk && ! $this->registration_open( $competition ) ) {
			Fail::conflict( __( 'Die Anmeldefrist ist abgelaufen. Nachmeldungen nimmt die TK entgegen.', 'ttleague' ) );
		}
		if ( 'active' !== $player['status'] ) {
			Fail::conflict( __( 'Die Person ist in dieser Saison nicht als aktiv gemeldet.', 'ttleague' ) );
		}
		if ( null !== $category && ! $actor->is_tk ) {
			$category = null;
		}
		if ( null !== $category ) {
			$this->check_category( $competition, $category );
		}

		$result = $this->db->transaction(
			function () use ( $actor, $competition, $player, $category ): array {
				$existing = $this->repo->participant( $competition['id'], $player['player_id'] );
				if ( null !== $existing && self::REGISTERED === $existing['status'] ) {
					return array(
						'id'       => $existing['id'],
						'category' => $existing['category'],
						'status'   => self::REGISTERED,
						'changed'  => false,
					);
				}
				$computed = Categories::for_classification( $player['classification'], $competition['categories'] );
				$now      = $this->now();
				if ( null === $existing ) {
					$assigned = $category ?? $computed;
					$id       = $this->db->insert(
						'rl_participant',
						array(
							'competition_id' => $competition['id'],
							'player_id'      => $player['player_id'],
							'category'       => $assigned,
							'status'         => self::REGISTERED,
							'registered_by'  => $actor->user_id,
							'registered_at'  => $now,
						)
					);
				} else {
					// Wieder anmelden: Eine von der TK gesetzte Kategorie bleibt.
					$assigned = $category ?? ( Categories::NONE === $existing['category'] ? $computed : $existing['category'] );
					$id       = $existing['id'];
					$this->db->update(
						'rl_participant',
						array(
							'category'      => $assigned,
							'status'        => self::REGISTERED,
							'registered_by' => $actor->user_id,
							'registered_at' => $now,
						),
						array( 'id' => $id )
					);
				}
				$this->audit->record(
					'rl_participant',
					$id,
					'registered',
					$actor->user_id,
					null === $existing ? null : array(
						'status'   => $existing['status'],
						'category' => $existing['category'],
					),
					array(
						'status'   => self::REGISTERED,
						'category' => $assigned,
						'player'   => $player['player_id'],
					)
				);
				return array(
					'id'       => $id,
					'category' => $assigned,
					'status'   => self::REGISTERED,
					'changed'  => true,
				);
			}
		);
		if ( $result['changed'] ) {
			$this->events->changed( 'rl_participant', $result['id'] );
		}
		unset( $result['changed'] );
		return $result;
	}

	/**
	 * Vom ganzen Turnier abmelden. In einer eingeteilten, noch nicht freigegebenen Runde fällt
	 * die Person aus ihrer Gruppe; in einer freigegebenen Runde gilt sie als abgemeldet.
	 */
	public function withdraw( Actor $actor, int $player_id ): void {
		[ $competition, $player ] = $this->target( $actor, $player_id );
		$participant_id           = $this->db->transaction(
			function () use ( $actor, $competition, $player ): ?int {
				$existing = $this->repo->participant( $competition['id'], $player['player_id'] );
				if ( null === $existing ) {
					Fail::conflict( __( 'Die Person ist nicht zum Ranglistenturnier angemeldet.', 'ttleague' ) );
				}
				if ( self::WITHDRAWN === $existing['status'] ) {
					return null;
				}
				$this->db->update( 'rl_participant', array( 'status' => self::WITHDRAWN ), array( 'id' => $existing['id'] ) );
				$left = array();
				foreach ( $this->repo->rounds( $competition['id'] ) as $round ) {
					if ( Rounds::CLOSED === $round['status'] ) {
						continue;
					}
					foreach ( $this->memberships( $round['id'], $player['player_id'] ) as $membership ) {
						if ( Rounds::PLANNED === $round['status'] ) {
							$this->db->delete( 'rl_group_player', array( 'id' => $membership['id'] ) );
							$this->db->query(
								"UPDATE {$this->db->table( 'rl_group' )} SET chief_player_id = NULL WHERE id = %d AND chief_player_id = %d",
								array( $membership['group_id'], $player['player_id'] )
							);
							$left[] = $membership['group_id'];
						} elseif ( RlPlayerStatus::Scheduled->value === $membership['status'] ) {
							$this->db->update( 'rl_group_player', array( 'status' => RlPlayerStatus::Absent->value ), array( 'id' => $membership['id'] ) );
							$left[] = $membership['group_id'];
						}
					}
				}
				$this->audit->record(
					'rl_participant',
					$existing['id'],
					'withdrawn',
					$actor->user_id,
					array( 'status' => $existing['status'] ),
					array(
						'status' => self::WITHDRAWN,
						'groups' => $left,
					)
				);
				return $existing['id'];
			}
		);
		if ( null !== $participant_id ) {
			$this->events->changed( 'rl_participant', $participant_id );
		}
	}

	/**
	 * Kategorie einer Teilnahme setzen (TK).
	 */
	public function set_category( Actor $actor, int $player_id, int $category ): void {
		$this->require_tk( $actor );
		[ $competition, $player ] = $this->target( $actor, $player_id );
		$this->check_category( $competition, $category );
		$participant_id = $this->db->transaction(
			function () use ( $actor, $competition, $player, $category ): ?int {
				$existing = $this->repo->participant( $competition['id'], $player['player_id'] );
				if ( null === $existing ) {
					Fail::conflict( __( 'Die Person ist nicht zum Ranglistenturnier angemeldet.', 'ttleague' ) );
				}
				if ( $existing['category'] === $category ) {
					return null;
				}
				$this->db->update( 'rl_participant', array( 'category' => $category ), array( 'id' => $existing['id'] ) );
				$this->audit->record( 'rl_participant', $existing['id'], 'category', $actor->user_id, array( 'category' => $existing['category'] ), array( 'category' => $category ) );
				return $existing['id'];
			}
		);
		if ( null !== $participant_id ) {
			$this->events->changed( 'rl_participant', $participant_id );
		}
	}

	/**
	 * Abende der Rundenwoche, an denen sich sperren lässt: jeder Tag von date_from bis date_to.
	 *
	 * @param array{date_from:string|null,date_to:string|null} $round Runde.
	 * @return list<string> Tage J-M-T.
	 */
	public static function evenings( array $round ): array {
		$from = null === $round['date_from'] ? false : \DateTimeImmutable::createFromFormat( '!Y-m-d', $round['date_from'] );
		$to   = null === $round['date_to'] ? false : \DateTimeImmutable::createFromFormat( '!Y-m-d', $round['date_to'] );
		if ( false === $from || false === $to ) {
			return array();
		}
		$days = array();
		for ( $day = $from, $i = 0; $day <= $to && $i < 31; $day = $day->modify( '+1 day' ), $i++ ) {
			$days[] = $day->format( 'Y-m-d' );
		}
		return $days;
	}

	/**
	 * Sperrdaten einer angemeldeten Person für eine Runde melden. Die Liste ersetzt die bisherige;
	 * eine leere Liste heisst «an jedem Abend frei». Möglich, bis die Runde freigegeben ist.
	 *
	 * @param list<string> $dates Gesperrte Abende (J-M-T) innerhalb der Rundenwoche.
	 * @return list<string> Gespeicherte Abende.
	 */
	public function set_blocked_dates( Actor $actor, int $player_id, int $round_id, array $dates ): array {
		[ $competition, $player ] = $this->target( $actor, $player_id );
		$round                    = $this->repo->round( $round_id );
		if ( null === $round || $round['competition_id'] !== $competition['id'] ) {
			Fail::not_found( __( 'Diese Runde gibt es nicht.', 'ttleague' ) );
		}
		$dates = $this->blocked_input( $round, $dates );
		$saved = $this->db->transaction(
			function () use ( $actor, $competition, $player, $round, $dates ): int {
				$this->repo->lock_round( $competition['season_id'], $round['id'] );
				$current = $this->repo->round( $round['id'] );
				if ( null === $current || Rounds::PLANNED !== $current['status'] ) {
					Fail::conflict( __( 'Die Runde ist freigegeben. Sperrdaten lassen sich nicht mehr ändern; melde dich bei Bedarf für die Runde ab.', 'ttleague' ) );
				}
				$participant = $this->repo->participant( $competition['id'], $player['player_id'] );
				if ( null === $participant || self::REGISTERED !== $participant['status'] ) {
					Fail::conflict( __( 'Sperrdaten gibt es nur für Angemeldete.', 'ttleague' ) );
				}
				$this->store_blocked_dates( $actor, $participant['id'], $player['person_id'], $round, $dates );
				return $participant['id'];
			}
		);
		$this->events->changed( 'rl_participant', $saved );
		return $dates;
	}

	/**
	 * Gesperrte Abende prüfen: gültige Tage innerhalb der Rundenwoche, ohne Doppel, aufsteigend.
	 *
	 * @param array{date_from:string|null,date_to:string|null} $round Runde.
	 * @param array<int|string,mixed>                          $dates Eingabe.
	 * @return list<string>
	 */
	public function blocked_input( array $round, array $dates, string $field = 'dates' ): array {
		$allowed = self::evenings( $round );
		$clean   = array();
		foreach ( $dates as $date ) {
			if ( ! is_string( $date ) || ! in_array( $date, $allowed, true ) ) {
				Fail::invalid( $field, __( 'Sperrdaten müssen Abende der Rundenwoche sein.', 'ttleague' ) );
			}
			$clean[ $date ] = true;
		}
		ksort( $clean );
		return array_keys( $clean );
	}

	/**
	 * Sperrdaten speichern und protokollieren (in einer laufenden Transaktion, Rechte geprüft).
	 *
	 * @param array{id:int,date_from:string|null,date_to:string|null} $round Runde.
	 * @param list<string>                                            $dates Geprüfte Abende.
	 */
	public function store_blocked_dates( Actor $actor, int $participant_id, int $person_id, array $round, array $dates ): void {
		$from     = (string) $round['date_from'];
		$to       = (string) $round['date_to'];
		$before   = $this->repo->blocked_dates( array( $person_id ), $from, $to )[ $person_id ] ?? array();
		$answered = array() !== $this->repo->answered( array( $person_id ), $from, $to );
		if ( $answered && $before === $dates ) {
			return;
		}
		$this->repo->replace_blocked_dates( $person_id, $from, $to, $dates );
		$this->audit->record(
			'rl_participant',
			$participant_id,
			'blocked_dates',
			$actor->user_id,
			array(
				'round' => $round['id'],
				'dates' => $before,
			),
			array(
				'round' => $round['id'],
				'dates' => $dates,
			)
		);
	}

	/**
	 * Von einer freigegebenen Runde abmelden: Die Person bleibt in der Gruppe, ihr Status ist
	 * «abgemeldet», ihre Partien zählen nicht.
	 */
	public function withdraw_from_round( Actor $actor, int $player_id, int $round_id ): void {
		[ $competition, $player ] = $this->target( $actor, $player_id );
		$round                    = $this->repo->round( $round_id );
		if ( null === $round || $round['competition_id'] !== $competition['id'] ) {
			Fail::not_found( __( 'Diese Runde gibt es nicht.', 'ttleague' ) );
		}
		$group_id = $this->db->transaction(
			function () use ( $actor, $competition, $player, $round ): int {
				$this->repo->lock_round( $competition['season_id'], $round['id'] );
				$current = $this->repo->round( $round['id'] );
				if ( null === $current || Rounds::PUBLISHED !== $current['status'] ) {
					Fail::conflict( __( 'Abmelden kannst du dich von einer freigegebenen, noch nicht abgeschlossenen Runde.', 'ttleague' ) );
				}
				$memberships = $this->memberships( $round['id'], $player['player_id'] );
				if ( array() === $memberships ) {
					Fail::conflict( __( 'Die Person ist in dieser Runde nicht aufgeboten.', 'ttleague' ) );
				}
				$membership = $memberships[0];
				if ( RlPlayerStatus::Scheduled->value !== $membership['status'] ) {
					Fail::conflict( __( 'Für diese Gruppe ist der Gruppenzettel schon erfasst oder die Person ist bereits abgemeldet.', 'ttleague' ) );
				}
				$this->db->update( 'rl_group_player', array( 'status' => RlPlayerStatus::Absent->value ), array( 'id' => $membership['id'] ) );
				$this->audit->record(
					'rl_group',
					$membership['group_id'],
					'player_absent',
					$actor->user_id,
					array(
						'player' => $player['player_id'],
						'status' => $membership['status'],
					),
					array(
						'player' => $player['player_id'],
						'status' => RlPlayerStatus::Absent->value,
					)
				);
				return $membership['group_id'];
			}
		);
		$this->events->changed( 'rl_group', $group_id );
	}

	/**
	 * Darf die Person für den Spieler handeln? Liefert «tk», «self» oder «club», sonst null.
	 *
	 * @param array{person_id:int,club_id:int} $player Spieler mit Meldung.
	 */
	public function relation( Actor $actor, array $player, int $season_id ): ?string {
		if ( $actor->is_tk ) {
			return 'tk';
		}
		if ( null === $actor->person_id ) {
			return null;
		}
		if ( $actor->person_id === $player['person_id'] ) {
			return 'self';
		}
		return in_array( $player['club_id'], $this->policy->roles( $actor->person_id, $season_id )['tk_chef_clubs'], true ) ? 'club' : null;
	}

	/**
	 * Konkurrenz der aktiven Saison und Spieler mit Meldung, nach Prüfung des Rechts.
	 *
	 * @return array{0:array<string,mixed>,1:array{player_id:int,person_id:int,club_id:int,club_short:string,classification:int,status:string,name:string}}
	 */
	private function target( Actor $actor, int $player_id ): array {
		$competition = $this->active_competition();
		if ( null === $competition ) {
			Fail::not_found( __( 'In der laufenden Saison gibt es kein Ranglistenturnier.', 'ttleague' ) );
		}
		$player = $this->repo->player( $player_id, $competition['season_id'] );
		if ( null === $player ) {
			// Ohne Recht kein Hinweis darauf, wer gemeldet ist.
			if ( ! $actor->is_tk ) {
				Fail::forbidden( __( 'Du kannst nur dich selbst oder Personen deines Clubs anmelden.', 'ttleague' ) );
			}
			Fail::not_found( __( 'Diese Person ist in der laufenden Saison nicht gemeldet.', 'ttleague' ) );
		}
		if ( null === $this->relation( $actor, $player, $competition['season_id'] ) ) {
			Fail::forbidden( __( 'Du kannst nur dich selbst oder Personen deines Clubs anmelden.', 'ttleague' ) );
		}
		return array( $competition, $player );
	}

	/**
	 * Zeilen der Person in den Gruppen einer Runde.
	 *
	 * @return list<array{id:int,group_id:int,status:string}>
	 */
	private function memberships( int $round_id, int $player_id ): array {
		$rows = array();
		foreach ( $this->db->get_results(
			"SELECT gp.id, gp.rl_group_id, gp.status FROM {$this->db->table( 'rl_group_player' )} gp
			JOIN {$this->db->table( 'rl_group' )} g ON g.id = gp.rl_group_id
			WHERE g.rl_round_id = %d AND gp.player_id = %d ORDER BY gp.id",
			array( $round_id, $player_id )
		) as $row ) {
			$rows[] = array(
				'id'       => (int) $row['id'],
				'group_id' => (int) $row['rl_group_id'],
				'status'   => (string) $row['status'],
			);
		}
		return $rows;
	}

	/**
	 * @param array{categories:list<array{no:int,min:int}>} $competition Konkurrenz.
	 */
	private function check_category( array $competition, int $category ): void {
		$numbers = Categories::numbers( $competition['categories'] );
		// Ohne hinterlegte Kategorien vergibt die TK die Nummern frei.
		if ( $category < 1 || $category > 255 || ( array() !== $numbers && ! in_array( $category, $numbers, true ) ) ) {
			Fail::invalid( 'category', __( 'Diese Kategorie gibt es nicht.', 'ttleague' ) );
		}
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

	/**
	 * Heutiger Tag in Ortszeit.
	 */
	private function today(): string {
		return $this->clock->now()->setTimezone( $this->timezone )->format( 'Y-m-d' );
	}

	private function now(): string {
		return $this->clock->now()->format( 'Y-m-d H:i:s' );
	}
}
