<?php
/**
 * Meldung der Cupmannschaften: melden, zurückziehen, Parameter der Konkurrenz.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cup;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldungen der Ausnahmen sind Klartext; escaped wird bei der Ausgabe (Flash).

use TTLeague\Access\AccessPolicy;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Results\Actor;
use TTLeague\Results\ResultException;
use TTLeague\Results\ResultInput;

/**
 * Regeln aus docs/cup.md (Art. 18 bis 20):
 *
 * - Der TK-Chef des Clubs in der Saison der Konkurrenz meldet und zieht zurück, bis und mit
 *   Meldeschluss (registration_deadline) und solange die Auslosung nicht freigegeben ist. Danach
 *   nur die TK. Nach der Auslosung gibt es keine neuen Meldungen mehr.
 * - Beide Spieler sind in der Saison für den Club gemeldet (player_season, Status active).
 * - Die Summe ihrer Klassierungen (points_total) übersteigt die Punktelimite der Kategorie nicht.
 * - Ein Spieler steht in höchstens einer Cupmannschaft der Saison, über alle Kategorien.
 * - Nummer und Name vergibt das System fortlaufend je Club und Kategorie.
 * - Rückzug vor der Auslosung löscht die Meldung; danach wird sie «withdrawn», und der Gegner
 *   kommt kampflos weiter (Bracket::entry_withdrawn()).
 *
 * Jeder Schreibvorgang läuft in einer Transaktion unter der Sperre der Saison, mit Eintrag im
 * audit_log; danach folgt das Ereignis (CupEvents). Fehler sind ResultException mit den Codes
 * der Erfassung.
 */
final class Entries {

	/** Ohne Angabe in format_json findet der Cup ab so vielen Meldungen statt (Art. 18.2). */
	public const DEFAULT_MIN_ENTRIES = 8;

	/** Höchste mögliche Punktelimite: zwei Spieler mit der höchsten Klassierung. */
	private const MAX_POINTS = 44;

	private CupRepository $repo;

	public function __construct(
		private readonly Db $db,
		private readonly AccessPolicy $policy,
		private readonly Clock $clock,
		private readonly \DateTimeZone $timezone,
		private readonly AuditLog $audit,
		private readonly CupEvents $events,
		private readonly ?Bracket $bracket = null,
	) {
		$this->repo = new CupRepository( $db );
	}

	/**
	 * Parameter einer Konkurrenz aus format_json mit Standardwerten.
	 *
	 * @param array{format:array<string,mixed>} $competition Konkurrenz.
	 * @return array{points_limit:int|null,registration_deadline:string|null,min_entries:int}
	 */
	public static function settings( array $competition ): array {
		$format   = $competition['format'];
		$limit    = $format['points_limit'] ?? null;
		$deadline = CupInput::date( $format['registration_deadline'] ?? null );
		$minimum  = (int) ( $format['min_entries'] ?? self::DEFAULT_MIN_ENTRIES );
		return array(
			'points_limit'          => is_numeric( $limit ) && (int) $limit > 0 ? (int) $limit : null,
			'registration_deadline' => $deadline,
			'min_entries'           => $minimum >= 2 ? $minimum : self::DEFAULT_MIN_ENTRIES,
		);
	}

	/**
	 * Punktelimite, Meldeschluss und Mindestzahl der Meldungen setzen (TK).
	 *
	 * @param array<string,mixed> $payload points_limit, registration_deadline, min_entries; leer entfernt den Wert.
	 * @return array{points_limit:int|null,registration_deadline:string|null,min_entries:int}
	 */
	public function configure( int $competition_id, Actor $actor, array $payload ): array {
		$this->require_tk( $actor );
		$competition = $this->competition( $competition_id );
		$errors      = array();

		$limit = null;
		if ( ! CupInput::blank( $payload['points_limit'] ?? null ) ) {
			$limit = filter_var( $payload['points_limit'], FILTER_VALIDATE_INT );
			if ( false === $limit || $limit < 2 || $limit > self::MAX_POINTS ) {
				/* translators: %d: höchster Wert */
				$errors[] = ResultInput::error( 'points_limit', sprintf( __( 'Die Punktelimite ist eine ganze Zahl von 2 bis %d oder leer (keine Grenze).', 'ttleague' ), self::MAX_POINTS ) );
			}
		}
		$deadline = null;
		if ( ! CupInput::blank( $payload['registration_deadline'] ?? null ) ) {
			$deadline = CupInput::date( $payload['registration_deadline'] );
			if ( null === $deadline ) {
				$errors[] = ResultInput::error( 'registration_deadline', __( 'Der Meldeschluss ist kein gültiges Datum.', 'ttleague' ) );
			}
		}
		$minimum = null;
		if ( ! CupInput::blank( $payload['min_entries'] ?? null ) ) {
			$minimum = filter_var( $payload['min_entries'], FILTER_VALIDATE_INT );
			if ( false === $minimum || $minimum < 2 || $minimum > 64 ) {
				$errors[] = ResultInput::error( 'min_entries', __( 'Die Mindestzahl der Meldungen ist eine ganze Zahl von 2 bis 64.', 'ttleague' ) );
			}
		}
		if ( array() !== $errors ) {
			throw ResultException::invalid( $errors );
		}

		$settings = $this->db->transaction(
			function () use ( $competition, $actor, $limit, $deadline, $minimum ): array {
				$this->repo->lock_season( $competition['season_id'] );
				$current = $this->competition( $competition['id'] );
				$format  = $current['format'];
				foreach ( array(
					'points_limit'          => $limit,
					'registration_deadline' => $deadline,
					'min_entries'           => $minimum,
				) as $key => $value ) {
					if ( null === $value ) {
						unset( $format[ $key ] );
					} else {
						$format[ $key ] = $value;
					}
				}
				$this->repo->save_format( $current['id'], $format );
				$after = self::settings( array( 'format' => $format ) );
				$this->audit->record( 'competition', $current['id'], 'cup_configured', $actor->user_id, self::settings( $current ), $after );
				return $after;
			}
		);
		$this->events->changed( $competition['id'], CupEvents::CONFIGURED, $actor->user_id );
		return $settings;
	}

	/**
	 * Cupmannschaft melden.
	 *
	 * @return array<string,mixed> Die Meldung (CupRepository::entry()).
	 */
	public function register( int $competition_id, int $club_id, Actor $actor, int $player1_id, int $player2_id ): array {
		$competition = $this->competition( $competition_id );
		$this->require_role( $competition, $club_id, $actor );
		if ( null === $this->repo->club( $club_id ) ) {
			throw ResultException::not_found( __( 'Diesen Club gibt es nicht.', 'ttleague' ) );
		}

		$entry_id = $this->db->transaction(
			function () use ( $competition, $club_id, $actor, $player1_id, $player2_id ): int {
				$this->repo->lock_season( $competition['season_id'] );
				$current = $this->competition( $competition['id'] );
				$this->require_open( $current, $actor );

				$settings = self::settings( $current );
				$players  = $this->repo->club_players( $current['season_id'], $club_id );
				$errors   = array();
				foreach ( array(
					'player1' => $player1_id,
					'player2' => $player2_id,
				) as $field => $player_id ) {
					if ( $player_id <= 0 ) {
						$errors[] = ResultInput::error( $field, __( 'Bitte wähle zwei Spieler.', 'ttleague' ) );
					} elseif ( ! isset( $players[ $player_id ] ) ) {
						$errors[] = ResultInput::error( $field, __( 'Der Spieler ist in dieser Saison nicht für den Club gemeldet.', 'ttleague' ) );
					}
				}
				if ( array() === $errors && $player1_id === $player2_id ) {
					$errors[] = ResultInput::error( 'player2', __( 'Eine Cupmannschaft besteht aus zwei verschiedenen Spielern.', 'ttleague' ) );
				}
				if ( array() !== $errors ) {
					throw ResultException::invalid( $errors );
				}
				foreach ( $this->repo->entries_of_players( $current['season_id'], array( $player1_id, $player2_id ) ) as $player_id => $other ) {
					$errors[] = ResultInput::error(
						$player_id === $player1_id ? 'player1' : 'player2',
						/* translators: 1: Spielername, 2: Cupmannschaft, 3: Konkurrenz */
						sprintf( __( '%1$s ist schon in der Cupmannschaft %2$s (%3$s) gemeldet. Ein Spieler steht in höchstens einer Cupmannschaft der Saison (Art. 20.2).', 'ttleague' ), $players[ $player_id ]['name'], $other['name'], $other['competition'] )
					);
				}
				$points = $players[ $player1_id ]['classification'] + $players[ $player2_id ]['classification'];
				if ( null !== $settings['points_limit'] && $points > $settings['points_limit'] ) {
					/* translators: 1: Summe der Klassierungen, 2: Punktelimite */
					$errors[] = ResultInput::error( 'players', sprintf( __( 'Die Summe der Klassierungen ist %1$d. In dieser Kategorie sind höchstens %2$d erlaubt (Art. 19.2).', 'ttleague' ), $points, $settings['points_limit'] ) );
				}
				if ( array() !== $errors ) {
					throw ResultException::invalid( $errors );
				}

				$club     = (array) $this->repo->club( $club_id );
				$number   = $this->repo->next_number( $current['id'], $club_id );
				$entry_id = $this->db->insert(
					'cup_entry',
					array(
						'competition_id' => $current['id'],
						'club_id'        => $club_id,
						'number'         => $number,
						'name'           => $club['short_name'] . ' ' . $number,
						'player1_id'     => $player1_id,
						'player2_id'     => $player2_id,
						'points_total'   => $points,
						'seed'           => null,
						'status'         => CupRepository::ACTIVE,
						'registered_by'  => $actor->user_id,
						'registered_at'  => $this->now(),
					)
				);
				$this->audit->record( 'cup_entry', $entry_id, 'registered', $actor->user_id, null, self::snapshot( (array) $this->repo->entry( $entry_id ) ) );
				return $entry_id;
			}
		);
		$this->policy->reset();
		$this->events->changed( $competition['id'], CupEvents::ENTRY_REGISTERED, $actor->user_id );
		return (array) $this->repo->entry( $entry_id );
	}

	/**
	 * Cupmannschaft zurückziehen: vor der Auslosung wird die Meldung gelöscht, danach bleibt sie
	 * mit dem Status «withdrawn» im Tableau, und der Gegner kommt kampflos weiter.
	 *
	 * @return array{deleted:bool,entry:array<string,mixed>}
	 */
	public function withdraw( int $entry_id, Actor $actor ): array {
		$entry       = $this->entry( $entry_id );
		$competition = $this->competition( $entry['competition_id'] );
		$this->require_role( $competition, $entry['club_id'], $actor );

		$deleted = $this->db->transaction(
			function () use ( $entry_id, $competition, $actor ): bool {
				$this->repo->lock_season( $competition['season_id'] );
				$current = $this->entry( $entry_id );
				$drawn   = $this->repo->has_draw( $competition['id'] );
				if ( ! $drawn ) {
					$this->require_open( $this->competition( $competition['id'] ), $actor );
					$this->db->delete( 'cup_entry', array( 'id' => $entry_id ) );
					$this->audit->record( 'cup_entry', $entry_id, 'withdrawn', $actor->user_id, self::snapshot( $current ), null );
					return true;
				}
				if ( ! $actor->is_tk ) {
					throw ResultException::forbidden( __( 'Nach der Auslosung zieht nur die TK eine Cupmannschaft zurück.', 'ttleague' ) );
				}
				if ( 'archived' === $competition['season_status'] ) {
					throw ResultException::conflict( __( 'Die Saison ist abgeschlossen.', 'ttleague' ) );
				}
				if ( CupRepository::WITHDRAWN === $current['status'] ) {
					throw ResultException::conflict( __( 'Die Cupmannschaft ist schon zurückgezogen.', 'ttleague' ) );
				}
				$this->db->update( 'cup_entry', array( 'status' => CupRepository::WITHDRAWN ), array( 'id' => $entry_id ) );
				$this->audit->record( 'cup_entry', $entry_id, 'withdrawn', $actor->user_id, self::snapshot( $current ), self::snapshot( $this->entry( $entry_id ) ) );
				return false;
			}
		);
		$this->policy->reset();
		$this->events->changed( $competition['id'], CupEvents::ENTRY_WITHDRAWN, $actor->user_id );
		if ( ! $deleted && null !== $this->bracket ) {
			$this->bracket->entry_withdrawn( $entry_id, $actor );
		}
		return array(
			'deleted' => $deleted,
			'entry'   => $deleted ? $entry : $this->entry( $entry_id ),
		);
	}

	/**
	 * Für «Mein Bereich» und das Backend: die Cup-Konkurrenzen der aktiven und der geplanten
	 * Saison, in denen die Person für den Club melden kann, mit den Meldungen des Clubs und den
	 * wählbaren Spielern.
	 *
	 * @return list<array{competition:array<string,mixed>,settings:array{points_limit:int|null,registration_deadline:string|null,min_entries:int},drawn:bool,open:bool,closed_reason:string|null,entries:list<array<string,mixed>>,players:list<array{player_id:int,name:string,classification:int,entry:string|null}>}>
	 */
	public function for_club( int $club_id, Actor $actor ): array {
		$blocks = array();
		foreach ( array( 'active', 'planned' ) as $status ) {
			foreach ( $this->repo->seasons( $status ) as $season_id ) {
				if ( ! $actor->is_tk && ! $this->is_chef( $actor, $season_id, $club_id ) ) {
					continue;
				}
				$competitions = $this->repo->competitions( $season_id );
				if ( array() === $competitions ) {
					continue;
				}
				$players = $this->repo->club_players( $season_id, $club_id );
				$taken   = $this->repo->entries_of_players( $season_id, array_keys( $players ) );
				$list    = array();
				foreach ( $players as $player ) {
					$list[] = array(
						'player_id'      => $player['player_id'],
						'name'           => $player['name'],
						'classification' => $player['classification'],
						'entry'          => isset( $taken[ $player['player_id'] ] ) ? $taken[ $player['player_id'] ]['name'] : null,
					);
				}
				foreach ( $competitions as $competition ) {
					$drawn  = $this->repo->has_draw( $competition['id'] );
					$reason = $this->closed_reason( $competition, $actor, $drawn );
					$own    = array_values( array_filter( $this->repo->entries( $competition['id'] ), static fn( array $entry ): bool => $entry['club_id'] === $club_id ) );
					foreach ( $own as $i => $entry ) {
						// Nach der Auslosung zieht nur die TK zurück, im Backend.
						$own[ $i ]['can_withdraw'] = ! $drawn && null === $reason;
					}
					$blocks[] = array(
						'competition'   => $competition,
						'settings'      => self::settings( $competition ),
						'drawn'         => $drawn,
						'open'          => null === $reason,
						'closed_reason' => $reason,
						'entries'       => $own,
						'players'       => $list,
					);
				}
			}
		}
		return $blocks;
	}

	/**
	 * Anzahl gültiger Meldungen und ob der Cup damit stattfindet (Art. 18.2).
	 *
	 * @return array{count:int,minimum:int,enough:bool}
	 */
	public function quorum( int $competition_id ): array {
		$competition = $this->competition( $competition_id );
		$count       = count( array_filter( $this->repo->entries( $competition_id ), static fn( array $entry ): bool => CupRepository::ACTIVE === $entry['status'] ) );
		$minimum     = self::settings( $competition )['min_entries'];
		return array(
			'count'   => $count,
			'minimum' => $minimum,
			'enough'  => $count >= $minimum,
		);
	}

	/**
	 * Heutiges Datum in der Zeitzone der Website.
	 */
	public function today(): string {
		return $this->clock->now()->setTimezone( $this->timezone )->format( 'Y-m-d' );
	}

	/**
	 * @return array{id:int,name:string,category:string,season_id:int,season_name:string,season_slug:string,season_status:string,season_start:string,season_end:string,format:array<string,mixed>}
	 * @throws ResultException 404, wenn es die Konkurrenz nicht gibt.
	 */
	private function competition( int $competition_id ): array {
		$competition = $this->repo->competition( $competition_id );
		if ( null === $competition ) {
			throw ResultException::not_found( __( 'Diese Cup-Konkurrenz gibt es nicht.', 'ttleague' ) );
		}
		return $competition;
	}

	/**
	 * @return array<string,mixed>
	 * @throws ResultException 404, wenn es die Meldung nicht gibt.
	 */
	private function entry( int $entry_id ): array {
		$entry = $this->repo->entry( $entry_id );
		if ( null === $entry ) {
			throw ResultException::not_found( __( 'Diese Cupmannschaft gibt es nicht.', 'ttleague' ) );
		}
		return $entry;
	}

	private function require_tk( Actor $actor ): void {
		if ( ! $actor->is_tk ) {
			throw ResultException::forbidden( __( 'Das kann nur die TK.', 'ttleague' ) );
		}
	}

	/**
	 * TK oder TK-Chef des Clubs in der Saison der Konkurrenz.
	 *
	 * @param array{season_id:int} $competition Konkurrenz.
	 */
	private function require_role( array $competition, int $club_id, Actor $actor ): void {
		if ( ! $actor->is_tk && ! $this->is_chef( $actor, $competition['season_id'], $club_id ) ) {
			throw ResultException::forbidden( __( 'Cupmannschaften meldet der TK-Chef des Clubs.', 'ttleague' ) );
		}
	}

	private function is_chef( Actor $actor, int $season_id, int $club_id ): bool {
		return null !== $actor->person_id && in_array( $club_id, $this->policy->roles( $actor->person_id, $season_id )['tk_chef_clubs'], true );
	}

	/**
	 * Sind Meldungen und Rückzüge (vor der Auslosung) für die Person jetzt möglich?
	 *
	 * @param array<string,mixed> $competition Konkurrenz.
	 * @throws ResultException 409 mit dem Grund.
	 */
	private function require_open( array $competition, Actor $actor ): void {
		$reason = $this->closed_reason( $competition, $actor, $this->repo->has_draw( $competition['id'] ) );
		if ( null !== $reason ) {
			throw ResultException::conflict( $reason );
		}
	}

	/**
	 * Grund, weshalb die Person jetzt nicht melden kann, oder null.
	 *
	 * @param array<string,mixed> $competition Konkurrenz.
	 */
	private function closed_reason( array $competition, Actor $actor, bool $drawn ): ?string {
		if ( 'archived' === $competition['season_status'] ) {
			return __( 'Die Saison ist abgeschlossen.', 'ttleague' );
		}
		if ( $drawn ) {
			return __( 'Die Auslosung ist freigegeben. Neue Meldungen sind nicht mehr möglich.', 'ttleague' );
		}
		$deadline = self::settings( $competition )['registration_deadline'];
		if ( ! $actor->is_tk && null !== $deadline && $this->today() > $deadline ) {
			return __( 'Der Meldeschluss ist vorbei. Wende dich an die TK.', 'ttleague' );
		}
		return null;
	}

	/**
	 * Zustand einer Meldung für das Änderungsprotokoll.
	 *
	 * @param array<string,mixed> $entry Meldung.
	 * @return array<string,mixed>
	 */
	private static function snapshot( array $entry ): array {
		return array(
			'competition_id' => $entry['competition_id'],
			'club_id'        => $entry['club_id'],
			'name'           => $entry['name'],
			'players'        => array_column( $entry['players'], 'player_id' ),
			'points_total'   => $entry['points_total'],
			'status'         => $entry['status'],
		);
	}

	private function now(): string {
		return $this->clock->now()->format( 'Y-m-d H:i:s' );
	}
}
