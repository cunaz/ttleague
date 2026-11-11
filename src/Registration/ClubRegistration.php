<?php
/**
 * Meldung eines Clubs: Spieler, Neumeldungen, Mannschaften mit Stammspielern, Captains, Einreichen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldungen der Ausnahmen sind Klartext; escaped wird bei der Ausgabe (Flash).

namespace TTLeague\Registration;

use TTLeague\Domain\Registration\RegistrationRules as Rules;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Results\Actor;
use TTLeague\Season\SeasonRepository;

/**
 * Jeder Schreibvorgang läuft in einer Transaktion: Club sperren, Rechte und Stand neu lesen
 * (RegistrationAccess), schreiben, im audit_log festhalten. Danach meldet der Dienst die
 * Änderung (RegistrationEvents). Änderungen werden sofort gespeichert; was der Meldung noch
 * fehlt, liefert violations() und verhindert nur das Einreichen.
 *
 * Bestehende Spieler werden mit der Klassierung gemeldet, die das System kennt
 * (RegistrationRepository::carried_classification()); nur die TK kann sie übersteuern. Bis zur
 * Freigabe durch die TK sind sie provisorisch gemeldet. Neue Personen entstehen als
 * player_request; Person und Spieler legt erst die Freigabe an (Review).
 */
final class ClubRegistration {

	public const PROVISIONAL = 'provisional';
	public const ACTIVE      = 'active';

	/** Mehr Mannschaften meldet kein Club; die Grenze schützt nur vor Fehleingaben. */
	private const MAX_TEAMS = 30;

	private RegistrationRepository $repo;

	private SeasonRepository $seasons;

	public function __construct(
		private readonly Db $db,
		private readonly RegistrationAccess $access,
		private readonly AuditLog $audit,
		private readonly RegistrationEvents $events,
		private readonly int $stamm_size = Rules::STAMM_SIZE,
	) {
		$this->repo    = new RegistrationRepository( $db );
		$this->seasons = new SeasonRepository( $db );
	}

	/**
	 * Alles für die Ansicht der Meldung: Stand, Rechte, Spieler, Vorjahresspieler, Neumeldungen,
	 * Mannschaften, Lokale und offene Verstösse.
	 *
	 * @return array{context:RegistrationContext,players:list<array<string,mixed>>,candidates:list<array<string,mixed>>,requests:list<array<string,mixed>>,teams:list<array<string,mixed>>,venues:list<array<string,mixed>>,captain_candidates:list<array<string,mixed>>,violations:list<array{code:string,field:string,message:string}>}
	 * @throws RegistrationException Ohne Recht oder ohne Meldung.
	 */
	public function state( Actor $actor, int $season_id, int $club_id ): array {
		$ctx = $this->access->context( $actor, $season_id, $club_id );
		return array(
			'context'            => $ctx,
			'players'            => $this->repo->players( $season_id, $club_id ),
			'candidates'         => $this->repo->candidates( $ctx->season, $club_id ),
			'requests'           => $this->repo->requests( $season_id, $club_id ),
			'teams'              => $this->repo->teams( $season_id, $club_id ),
			'venues'             => $this->repo->venues( $club_id, $ctx->today ),
			'captain_candidates' => $this->repo->captain_candidates( $season_id, $club_id, $this->active_season_id() ),
			'violations'         => $this->violations( $season_id, $club_id ),
		);
	}

	/**
	 * Offene Verstösse der Meldung (RegistrationRules::teams()).
	 *
	 * @return list<array{code:string,field:string,message:string}>
	 */
	public function violations( int $season_id, int $club_id ): array {
		$names = array_column( $this->repo->players( $season_id, $club_id ), 'name', 'player_id' );
		$teams = $this->repo->teams( $season_id, $club_id );
		$all   = $names;
		foreach ( $teams as $team ) {
			foreach ( $team['players'] as $player_id ) {
				$all[ $player_id ] ??= $this->repo->player( $player_id )['name'] ?? '';
			}
		}
		return Rules::teams( $teams, array_keys( $names ), array_filter( $all ), $this->stamm_size );
	}

	/**
	 * Bestehenden Spieler für den Club melden (Vorjahresspieler bestätigen, Spieler eines anderen
	 * Clubs übernehmen, in der aktiven Saison nachmelden).
	 *
	 * @param int|null $classification Nur für die TK: abweichende Klassierung.
	 * @return int ID der Zeile in player_season.
	 * @throws RegistrationException Ohne Recht, ausserhalb der Frist, wenn der Spieler schon
	 *                               gemeldet ist (Art. 27.2) oder keine Klassierung bekannt ist.
	 */
	public function confirm_player( Actor $actor, int $season_id, int $club_id, int $player_id, ?int $classification = null ): int {
		return $this->write(
			$actor,
			$season_id,
			$club_id,
			function ( RegistrationContext $ctx ) use ( $actor, $player_id, $classification ): int {
				$ctx->require( RegistrationAccess::PLAYERS );
				$player = $this->repo->player( $player_id );
				if ( null === $player ) {
					throw RegistrationException::not_found( __( 'Diesen Spieler gibt es nicht.', 'ttleague' ) );
				}
				return $this->register( $ctx, $actor, $player, $classification );
			}
		);
	}

	/**
	 * Bestehenden Spieler über die Lizenznummer melden.
	 *
	 * @return int ID der Zeile in player_season.
	 * @throws RegistrationException Wie confirm_player(); auch bei unbekannter Lizenznummer.
	 */
	public function register_license( Actor $actor, int $season_id, int $club_id, int $license_no, ?int $classification = null ): int {
		return $this->write(
			$actor,
			$season_id,
			$club_id,
			function ( RegistrationContext $ctx ) use ( $actor, $license_no, $classification ): int {
				$ctx->require( RegistrationAccess::PLAYERS );
				$player = $license_no > 0 ? $this->repo->player_by_license( $license_no ) : null;
				if ( null === $player ) {
					throw RegistrationException::field( 'license_no', __( 'Zu dieser Lizenznummer gibt es keinen Spieler. Neue Spielerinnen und Spieler meldest du mit Namen.', 'ttleague' ) );
				}
				return $this->register( $ctx, $actor, $player, $classification );
			}
		);
	}

	/**
	 * Meldung eines Spielers zurückziehen. In der geplanten Saison jeden Spieler des Clubs, in
	 * der aktiven nur eine Nachmeldung, die die TK noch nicht freigegeben hat.
	 *
	 * @throws RegistrationException Ohne Recht oder wenn der Spieler nicht (mehr) so gemeldet ist.
	 */
	public function withdraw_player( Actor $actor, int $season_id, int $club_id, int $player_id ): void {
		$this->write(
			$actor,
			$season_id,
			$club_id,
			function ( RegistrationContext $ctx ) use ( $actor, $player_id ): void {
				$ctx->require( RegistrationAccess::PLAYERS );
				$row = $this->repo->player_season( $ctx->season_id(), $player_id );
				if ( null === $row || $row['club_id'] !== $ctx->club_id() ) {
					throw RegistrationException::not_found( __( 'Dieser Spieler ist für den Club nicht gemeldet.', 'ttleague' ) );
				}
				if ( ! $ctx->is_planned() && self::PROVISIONAL !== $row['status'] ) {
					throw RegistrationException::conflict( __( 'In der laufenden Saison lässt sich nur eine Nachmeldung zurückziehen, die die TK noch nicht freigegeben hat.', 'ttleague' ) );
				}
				$this->db->delete( 'player_season', array( 'id' => $row['id'] ) );
				$this->audit->record( 'player_season', $row['id'], 'withdrawn', $actor->user_id, $row );
			}
		);
	}

	/**
	 * Neue Person melden (player_request). Person und Spieler entstehen erst bei der Freigabe.
	 *
	 * @param array{last_name?:string,first_name?:string,email?:string,classification?:int|null,stt_license?:string} $data Angaben.
	 * @return int ID der Neumeldung.
	 * @throws RegistrationException Ohne Recht, ausserhalb der Frist oder bei ungültigen Angaben.
	 */
	public function request_player( Actor $actor, int $season_id, int $club_id, array $data ): int {
		$request = array(
			'last_name'      => trim( (string) ( $data['last_name'] ?? '' ) ),
			'first_name'     => trim( (string) ( $data['first_name'] ?? '' ) ),
			'email'          => strtolower( trim( (string) ( $data['email'] ?? '' ) ) ),
			'classification' => isset( $data['classification'] ) ? (int) $data['classification'] : null,
			'stt_license'    => trim( (string) ( $data['stt_license'] ?? '' ) ),
		);
		return $this->write(
			$actor,
			$season_id,
			$club_id,
			function ( RegistrationContext $ctx ) use ( $actor, $request ): int {
				$ctx->require( RegistrationAccess::PLAYERS );
				$errors = Rules::request( $request );
				if ( array() !== $errors ) {
					throw RegistrationException::invalid( $errors );
				}
				foreach ( $this->repo->requests( $ctx->season_id(), $ctx->club_id(), 'requested' ) as $open ) {
					if ( 0 === strcasecmp( $open['last_name'], $request['last_name'] ) && 0 === strcasecmp( $open['first_name'], $request['first_name'] ) ) {
						/* translators: %s: Name */
						throw RegistrationException::conflict( sprintf( __( '%s ist schon gemeldet und wartet auf die Freigabe durch die TK.', 'ttleague' ), $open['name'] ) );
					}
				}
				$row = array(
					'season_id'      => $ctx->season_id(),
					'club_id'        => $ctx->club_id(),
					'last_name'      => $request['last_name'],
					'first_name'     => $request['first_name'],
					'email'          => '' === $request['email'] ? null : $request['email'],
					'classification' => $request['classification'],
					'stt_license'    => '' === $request['stt_license'] ? null : $request['stt_license'],
					'status'         => 'requested',
					'requested_by'   => $actor->user_id,
					'requested_at'   => $this->access->now(),
				);
				$id  = $this->db->insert( 'player_request', $row );
				$this->audit->record( 'player_request', $id, 'requested', $actor->user_id, null, $row );
				return $id;
			}
		);
	}

	/**
	 * Offene Neumeldung zurückziehen.
	 *
	 * @throws RegistrationException Ohne Recht oder wenn die TK schon entschieden hat.
	 */
	public function cancel_request( Actor $actor, int $request_id ): void {
		$request = $this->repo->request( $request_id );
		if ( null === $request ) {
			throw RegistrationException::not_found( __( 'Diese Neumeldung gibt es nicht.', 'ttleague' ) );
		}
		$this->write(
			$actor,
			$request['season_id'],
			$request['club_id'],
			function ( RegistrationContext $ctx ) use ( $actor, $request_id ): void {
				$ctx->require( RegistrationAccess::PLAYERS );
				$current = $this->repo->request( $request_id );
				if ( null === $current || 'requested' !== $current['status'] ) {
					throw RegistrationException::conflict( __( 'Über diese Neumeldung hat die TK schon entschieden.', 'ttleague' ) );
				}
				$this->db->delete( 'player_request', array( 'id' => $request_id ) );
				$this->audit->record( 'player_request', $request_id, 'cancelled', $actor->user_id, $current );
			}
		);
	}

	/**
	 * Weitere Mannschaft melden: nächste Nummer, noch ohne Kategorie und Gruppe (die legt die TK fest).
	 *
	 * @return int ID der Mannschaft.
	 * @throws RegistrationException Ohne Recht; Mannschaften meldet ein Club nur für die geplante Saison.
	 */
	public function add_team( Actor $actor, int $season_id, int $club_id ): int {
		return $this->write(
			$actor,
			$season_id,
			$club_id,
			function ( RegistrationContext $ctx ) use ( $actor ): int {
				$ctx->require( RegistrationAccess::STRUCTURE );
				$numbers = array_column( $this->repo->teams( $ctx->season_id(), $ctx->club_id() ), 'number' );
				$number  = array() === $numbers ? 1 : max( $numbers ) + 1;
				if ( $number > self::MAX_TEAMS ) {
					throw RegistrationException::conflict( __( 'Mehr Mannschaften lassen sich nicht melden.', 'ttleague' ) );
				}
				$row = array(
					'season_id'      => $ctx->season_id(),
					'club_id'        => $ctx->club_id(),
					'number'         => $number,
					'name'           => $ctx->club['short_name'] . ' ' . $number,
					'competition_id' => null,
					'status'         => 'active',
				);
				$id  = $this->db->insert( 'team', $row );
				$this->audit->record( 'team', $id, 'created', $actor->user_id, null, $row );
				return $id;
			}
		);
	}

	/**
	 * Mannschaft mit der höchsten Nummer wieder entfernen, samt Stammspielern und Captain.
	 *
	 * @throws RegistrationException Ohne Recht, bei einer anderen als der letzten Mannschaft, nach
	 *                               der Einteilung durch die TK (für Clubs) oder mit Begegnungen.
	 */
	public function remove_team( Actor $actor, int $team_id ): void {
		$team = $this->team( $team_id );
		$this->write(
			$actor,
			$team['season_id'],
			$team['club_id'],
			function ( RegistrationContext $ctx ) use ( $actor, $team_id ): void {
				$ctx->require( RegistrationAccess::STRUCTURE );
				$teams = $this->repo->teams( $ctx->season_id(), $ctx->club_id() );
				$last  = array() === $teams ? null : $teams[ count( $teams ) - 1 ];
				if ( null === $last || $last['id'] !== $team_id ) {
					throw RegistrationException::conflict( __( 'Entfernen lässt sich nur die Mannschaft mit der höchsten Nummer.', 'ttleague' ) );
				}
				if ( ! $actor->is_tk && null !== $last['competition_id'] ) {
					throw RegistrationException::conflict( __( 'Die TK hat diese Mannschaft schon eingeteilt. Bitte melde dich bei der TK.', 'ttleague' ) );
				}
				if ( $this->repo->team_has_fixtures( $team_id ) ) {
					throw RegistrationException::conflict( __( 'Für diese Mannschaft sind schon Begegnungen angesetzt.', 'ttleague' ) );
				}
				$this->db->delete( 'team_roster', array( 'team_id' => $team_id ) );
				$this->repo->replace_captain( $ctx->season_id(), $ctx->club_id(), $team_id, null );
				$this->db->delete( 'team', array( 'id' => $team_id ) );
				$this->audit->record( 'team', $team_id, 'deleted', $actor->user_id, $last );
			}
		);
		$this->access->forget_roles();
	}

	/**
	 * Mannschaft speichern: Stammspieler, Heimlokal, Anspielzeit und, falls angegeben, Captain.
	 * In der geplanten Saison wird auch eine unvollständige Mannschaft gespeichert (Verstösse
	 * verhindern das Einreichen); in der aktiven Saison (nur TK) müssen die Stammspieler stimmen.
	 *
	 * @param array{players?:list<int>,home_venue_id?:int|null,start_time?:string|null,captain_person_id?:int|null} $data Angaben; ohne Schlüssel captain_person_id bleibt der Captain.
	 * @throws RegistrationException Ohne Recht oder bei ungültigen Angaben.
	 */
	public function save_team( Actor $actor, int $team_id, array $data ): void {
		$team = $this->team( $team_id );
		$this->write(
			$actor,
			$team['season_id'],
			$team['club_id'],
			function ( RegistrationContext $ctx ) use ( $actor, $team_id, $data ): void {
				$ctx->require( RegistrationAccess::TEAMS );
				$team   = $this->team( $team_id );
				$errors = array();

				$players    = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $data['players'] ?? array() ) ), static fn( int $id ): bool => $id > 0 ) ) );
				$registered = array_column( $this->repo->players( $ctx->season_id(), $ctx->club_id() ), 'player_id' );
				foreach ( $players as $player_id ) {
					if ( ! in_array( $player_id, $registered, true ) ) {
						$name = $this->repo->player( $player_id )['name'] ?? __( 'Ein gewählter Spieler', 'ttleague' );
						/* translators: %s: Name */
						$errors[] = self::error( 'team.' . $team_id . '.players', sprintf( __( '%s ist für den Club in dieser Saison nicht gemeldet.', 'ttleague' ), $name ) );
					}
				}
				if ( ! $ctx->is_planned() ) {
					$errors = array_merge( $errors, $this->roster_errors( $ctx, $team_id, $players ) );
				}

				$venue_id = isset( $data['home_venue_id'] ) && (int) $data['home_venue_id'] > 0 ? (int) $data['home_venue_id'] : null;
				if ( null !== $venue_id ) {
					$venue = $this->repo->venue( $venue_id );
					if ( null === $venue || $venue['club_id'] !== $ctx->club_id() || ! $venue['active'] ) {
						$errors[] = self::error( 'team.' . $team_id . '.venue', __( 'Das Heimlokal ist kein aktives Lokal des Clubs.', 'ttleague' ) );
					}
				}

				$start = trim( (string) ( $data['start_time'] ?? '' ) );
				if ( '' === $start ) {
					$start = null;
				} elseif ( ! Rules::is_time( $start ) ) {
					$errors[] = self::error( 'team.' . $team_id . '.start', __( 'Die Anspielzeit ist ungültig (HH:MM).', 'ttleague' ) );
				} else {
					$start = substr( $start, 0, 5 ) . ':00';
				}

				$set_captain = array_key_exists( 'captain_person_id', $data );
				$captain     = null;
				if ( $set_captain ) {
					try {
						$captain = $this->captain( $ctx, $actor, $team_id, null === $data['captain_person_id'] || (int) $data['captain_person_id'] <= 0 ? null : (int) $data['captain_person_id'] );
					} catch ( RegistrationException $e ) {
						$errors = array_merge( $errors, $e->errors );
					}
				}
				if ( array() !== $errors ) {
					throw RegistrationException::invalid( $errors );
				}

				$this->repo->replace_stamm( $team_id, $players );
				$this->db->update(
					'team',
					array(
						'home_venue_id'      => $venue_id,
						'default_start_time' => $start,
					),
					array( 'id' => $team_id )
				);
				if ( $set_captain ) {
					$this->repo->replace_captain( $ctx->season_id(), $ctx->club_id(), $team_id, $captain );
				}
				$this->audit->record( 'team', $team_id, 'updated', $actor->user_id, $team, $this->repo->team( $team_id ) );
			}
		);
		$this->access->forget_roles();
	}

	/**
	 * Captain einer Mannschaft setzen (genau einer). Gilt sofort, auch in der aktiven Saison.
	 *
	 * @param int|null $person_id Person oder null (kein Captain; in der aktiven Saison nur die TK).
	 * @throws RegistrationException Ohne Recht, wenn die Person nicht zum Club gehört oder keine
	 *                               E-Mail-Adresse hat.
	 */
	public function set_captain( Actor $actor, int $team_id, ?int $person_id ): void {
		$team = $this->team( $team_id );
		$this->write(
			$actor,
			$team['season_id'],
			$team['club_id'],
			function ( RegistrationContext $ctx ) use ( $actor, $team_id, $person_id ): void {
				$ctx->require( RegistrationAccess::CAPTAIN );
				$team    = $this->team( $team_id );
				$captain = $this->captain( $ctx, $actor, $team_id, $person_id );
				$this->repo->replace_captain( $ctx->season_id(), $ctx->club_id(), $team_id, $captain );
				$this->audit->record( 'team', $team_id, 'captain', $actor->user_id, array( 'captain' => $team['captain'] ), array( 'captain_person_id' => $captain ) );
			}
		);
		$this->access->forget_roles();
	}

	/**
	 * Meldung der geplanten Saison einreichen. Geht nur aus dem Stand «offen» und ohne Verstoss.
	 *
	 * @throws RegistrationException Ohne Recht, ausserhalb des Fensters, schon eingereicht oder mit Verstössen.
	 */
	public function submit( Actor $actor, int $season_id, int $club_id ): void {
		$this->write(
			$actor,
			$season_id,
			$club_id,
			function ( RegistrationContext $ctx ) use ( $actor ): void {
				if ( ! $ctx->is_planned() || null === $ctx->club_season ) {
					throw RegistrationException::conflict( __( 'Eingereicht wird nur die Meldung der geplanten Saison.', 'ttleague' ) );
				}
				if ( Rules::OPEN !== $ctx->status() ) {
					throw RegistrationException::conflict( __( 'Die Meldung ist schon eingereicht.', 'ttleague' ) );
				}
				$ctx->require( RegistrationAccess::SUBMIT );
				$violations = $this->violations( $ctx->season_id(), $ctx->club_id() );
				if ( ! Rules::may_submit( (string) $ctx->status(), $violations ) ) {
					throw RegistrationException::invalid( $violations, __( 'Die Meldung lässt sich noch nicht einreichen. Bitte behebe zuerst die offenen Punkte.', 'ttleague' ) );
				}
				$change = array(
					'status'       => Rules::SUBMITTED,
					'submitted_at' => $this->access->now(),
					'submitted_by' => $actor->user_id,
				);
				$this->repo->update_club_season( (int) $ctx->club_season['id'], $change );
				$this->audit->record( 'club_season', (int) $ctx->club_season['id'], 'submitted', $actor->user_id, $ctx->club_season, $change );
			}
		);
	}

	/**
	 * Schreibvorgang an der Meldung eines Clubs: Transaktion, Sperre, aktueller Stand, danach Ereignis.
	 *
	 * @template T
	 * @param callable(RegistrationContext):T $work Prüfungen und Schreibvorgänge.
	 * @return T
	 */
	private function write( Actor $actor, int $season_id, int $club_id, callable $work ): mixed {
		$result = $this->db->transaction(
			function () use ( $actor, $season_id, $club_id, $work ): mixed {
				$this->repo->lock_club( $club_id );
				return $work( $this->access->context( $actor, $season_id, $club_id ) );
			}
		);
		$this->events->changed( $season_id, $club_id );
		return $result;
	}

	/**
	 * Spieler für den Club der Meldung eintragen.
	 *
	 * @param array{id:int,name:string} $player Spieler.
	 */
	private function register( RegistrationContext $ctx, Actor $actor, array $player, ?int $classification ): int {
		$this->repo->lock_player( $player['id'] );
		$existing = $this->repo->player_season( $ctx->season_id(), $player['id'] );
		if ( null !== $existing ) {
			if ( Rules::other_club( $ctx->club_id(), $existing['club_id'] ) ) {
				/* translators: 1: Name, 2: Club */
				throw RegistrationException::conflict( sprintf( __( '%1$s ist in dieser Saison schon für %2$s gemeldet. Ein Spieler ist pro Saison nur für einen Club gemeldet (Art. 27.2).', 'ttleague' ), $player['name'], $existing['club'] ) );
			}
			/* translators: %s: Name */
			throw RegistrationException::conflict( sprintf( __( '%s ist schon gemeldet.', 'ttleague' ), $player['name'] ) );
		}
		$value = $actor->is_tk && null !== $classification ? $classification : $this->repo->carried_classification( $player['id'], $ctx->season );
		if ( ! Rules::is_classification( $value ) ) {
			$message = $actor->is_tk
				/* translators: %s: Name */
				? __( 'Für %s ist keine gültige Klassierung bekannt. Bitte gib sie an.', 'ttleague' )
				/* translators: %s: Name */
				: __( 'Für %s ist keine Klassierung bekannt. Bitte melde dich bei der TK.', 'ttleague' );
			throw RegistrationException::field( 'classification', sprintf( $message, $player['name'] ) );
		}
		// Meldet die TK selbst in der laufenden Saison, in einer schon geprüften Meldung oder mit
		// eigener Klassierung, ist das die Freigabe. Provisorisch bleibt, was das System mit der
		// übernommenen Klassierung einträgt; diese führt die Freigabe der Meldung nach.
		$approved = $actor->is_tk && ( $ctx->is_active() || Rules::REVIEWED === $ctx->status() || null !== $classification );
		$now      = $this->access->now();
		$row      = array(
			'player_id'      => $player['id'],
			'season_id'      => $ctx->season_id(),
			'club_id'        => $ctx->club_id(),
			'classification' => $value,
			'status'         => $approved ? self::ACTIVE : self::PROVISIONAL,
			'registered_at'  => $now,
			'registered_by'  => $actor->user_id,
			'approved_by'    => $approved ? $actor->user_id : null,
			'approved_at'    => $approved ? $now : null,
		);
		$id       = $this->db->insert( 'player_season', $row );
		$this->audit->record( 'player_season', $id, 'registered', $actor->user_id, null, $row );
		return $id;
	}

	/**
	 * Stammspieler einer Mannschaft der aktiven Saison müssen sofort stimmen: genau die
	 * verlangte Zahl, niemand in zwei Mannschaften.
	 *
	 * @param list<int> $players Stammspieler.
	 * @return list<array{field:string,message:string}>
	 */
	private function roster_errors( RegistrationContext $ctx, int $team_id, array $players ): array {
		$errors = array();
		$field  = 'team.' . $team_id . '.players';
		if ( count( $players ) !== $this->stamm_size ) {
			/* translators: %d: Anzahl */
			$errors[] = self::error( $field, sprintf( __( 'Eine Mannschaft braucht genau %d Stammspieler.', 'ttleague' ), $this->stamm_size ) );
		}
		foreach ( $this->repo->teams( $ctx->season_id(), $ctx->club_id() ) as $other ) {
			if ( $other['id'] === $team_id ) {
				continue;
			}
			foreach ( array_intersect( $players, $other['players'] ) as $player_id ) {
				/* translators: 1: Name, 2: Mannschaft */
				$errors[] = self::error( $field, sprintf( __( '%1$s ist schon Stammspieler in %2$s (Art. 12.2).', 'ttleague' ), $this->repo->player( $player_id )['name'] ?? '', $other['name'] ) );
			}
		}
		return $errors;
	}

	/**
	 * Prüft die Person als Captain: Sie gehört zum Club (ausser die TK wählt) und hat eine E-Mail-Adresse.
	 *
	 * @throws RegistrationException Mit Feldfehler.
	 */
	private function captain( RegistrationContext $ctx, Actor $actor, int $team_id, ?int $person_id ): ?int {
		$field = 'team.' . $team_id . '.captain';
		if ( null === $person_id ) {
			if ( ! $ctx->is_planned() && ! $actor->is_tk ) {
				throw RegistrationException::field( $field, __( 'Eine Mannschaft der laufenden Saison braucht einen Captain.', 'ttleague' ) );
			}
			return null;
		}
		$person = $this->repo->person( $person_id );
		if ( null === $person ) {
			throw RegistrationException::field( $field, __( 'Diese Person gibt es nicht.', 'ttleague' ) );
		}
		if ( ! $actor->is_tk ) {
			$allowed = array_column( $this->repo->captain_candidates( $ctx->season_id(), $ctx->club_id(), $this->active_season_id() ), 'person_id' );
			if ( ! in_array( $person_id, $allowed, true ) ) {
				/* translators: %s: Name */
				throw RegistrationException::field( $field, sprintf( __( '%s gehört nicht zum Club. Captain kann sein, wer für den Club gemeldet ist oder eine Funktion im Club hat.', 'ttleague' ), $person['name'] ) );
			}
		}
		if ( ! Rules::is_email( $person['email'] ) ) {
			/* translators: %s: Name */
			throw RegistrationException::field( $field, sprintf( __( '%s hat keine E-Mail-Adresse. Ein Captain braucht eine, damit er sich anmelden kann.', 'ttleague' ), $person['name'] ) );
		}
		return $person_id;
	}

	/**
	 * @return array{id:int,season_id:int,club_id:int,number:int,name:string,competition_id:int|null,home_venue_id:int|null,start_time:string|null,status:string,players:list<int>,captain:array{person_id:int,name:string,email:string|null}|null}
	 * @throws RegistrationException Wenn es die Mannschaft nicht gibt.
	 */
	private function team( int $team_id ): array {
		$team = $this->repo->team( $team_id );
		if ( null === $team ) {
			throw RegistrationException::not_found( __( 'Diese Mannschaft gibt es nicht.', 'ttleague' ) );
		}
		return $team;
	}

	private function active_season_id(): ?int {
		$active = $this->seasons->active();
		return null === $active ? null : (int) $active['id'];
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
