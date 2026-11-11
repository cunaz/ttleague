<?php
/**
 * Prüfung der Meldungen durch die TK: Neumeldungen und Nachmeldungen freigeben oder ablehnen,
 * Meldung freigeben oder zurückgeben.
 *
 * @package TTLeague
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldungen der Ausnahmen sind Klartext; escaped wird bei der Ausgabe (Flash).

namespace TTLeague\Registration;

use TTLeague\Domain\Registration\RegistrationRules as Rules;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Database\DbException;
use TTLeague\Results\Actor;
use TTLeague\Season\SeasonRepository;

/**
 * Alles verlangt die TK (Actor::is_tk); die WordPress-Schicht prüft zusätzlich ttl_tk. Jeder
 * Entscheid läuft in einer Transaktion, steht im audit_log und geht danach als Decision an
 * RegistrationEvents (Benachrichtigung registration_decided an die TK-Chefs des Clubs).
 *
 * Erst die Freigabe einer Neumeldung legt Person, Spieler (mit Lizenznummer) und die Meldung
 * für die Saison an. Passt eine bestehende Person, wählt die TK sie aus; dann entsteht keine
 * zweite Person, und hat sie schon eine Lizenz, auch kein zweiter Spieler. Die E-Mail-Adresse
 * einer bestehenden Person ändert die Freigabe nie.
 */
final class Review {

	private RegistrationRepository $repo;

	private SeasonRepository $seasons;

	public function __construct(
		private readonly Db $db,
		private readonly RegistrationAccess $access,
		private readonly ClubRegistration $registration,
		private readonly AuditLog $audit,
		private readonly RegistrationEvents $events,
	) {
		$this->repo    = new RegistrationRepository( $db );
		$this->seasons = new SeasonRepository( $db );
	}

	/**
	 * Stand je Club einer Saison.
	 *
	 * @return list<array{club_id:int,name:string,short_name:string,status:string|null,submitted_at:string|null,reviewed_at:string|null,note:string|null,players:int,provisional:int,teams:int,requests:int}>
	 * @throws RegistrationException Ohne TK-Recht.
	 */
	public function overview( Actor $actor, int $season_id ): array {
		self::require_tk( $actor );
		return $this->repo->overview( $season_id );
	}

	/**
	 * Was die TK zu prüfen hat: eingereichte Meldungen der geplanten Saison, offene Neumeldungen
	 * beider Saisons und provisorische Nachmeldungen der aktiven Saison.
	 *
	 * @return array{submitted:list<array<string,mixed>>,requests:list<array<string,mixed>>,players:list<array<string,mixed>>}
	 * @throws RegistrationException Ohne TK-Recht.
	 */
	public function pending( Actor $actor ): array {
		self::require_tk( $actor );
		$planned = $this->seasons->planned();
		$active  = $this->seasons->active();
		$pending = array(
			'submitted' => array(),
			'requests'  => array(),
			'players'   => array(),
		);
		if ( null !== $planned ) {
			foreach ( $this->repo->overview( (int) $planned['id'] ) as $row ) {
				if ( Rules::SUBMITTED === $row['status'] ) {
					$pending['submitted'][] = $row + array( 'season_id' => (int) $planned['id'] );
				}
			}
		}
		foreach ( array( $planned, $active ) as $season ) {
			if ( null !== $season ) {
				foreach ( $this->repo->open_requests( (int) $season['id'] ) as $request ) {
					$pending['requests'][] = $request + array( 'season' => (string) $season['name'] );
				}
			}
		}
		if ( null !== $active ) {
			foreach ( $this->repo->provisional_players( (int) $active['id'] ) as $player ) {
				$pending['players'][] = $player + array( 'season_id' => (int) $active['id'] );
			}
		}
		return $pending;
	}

	/**
	 * Vorschläge für die Freigabe einer Neumeldung: nächste freie Lizenznummer und bestehende
	 * Personen mit gleichem Namen oder gleicher E-Mail-Adresse.
	 *
	 * @return array{license_no:int,persons:list<array{person_id:int,name:string,email:string|null,player_id:int|null,license_no:int|null,same_email:bool}>}
	 * @throws RegistrationException Ohne TK-Recht oder wenn es die Neumeldung nicht gibt.
	 */
	public function suggestions( Actor $actor, int $request_id ): array {
		self::require_tk( $actor );
		$request = $this->request( $request_id );
		return array(
			'license_no' => $this->repo->next_license(),
			'persons'    => $this->repo->similar_persons( $request['last_name'], $request['first_name'], $request['email'] ),
		);
	}

	/**
	 * Neumeldung freigeben: legt Person und Spieler an (oder verwendet die gewählte bestehende
	 * Person) und meldet den Spieler für den Club, Status «active».
	 *
	 * @param array{license_no?:int,classification?:int|null,person_id?:int|null,email?:string|null,note?:string} $data Lizenznummer (Pflicht, ausser die Person hat schon eine), Klassierung (sonst die des Clubs), bestehende Person, abweichende E-Mail-Adresse.
	 * @return int ID des Spielers.
	 * @throws RegistrationException Ohne TK-Recht, nach einem Entscheid, bei vergebener
	 *                               Lizenznummer oder E-Mail-Adresse, oder wenn die Person schon
	 *                               für einen anderen Club gemeldet ist.
	 */
	public function approve_request( Actor $actor, int $request_id, array $data ): int {
		self::require_tk( $actor );
		$request = $this->request( $request_id );
		try {
			[ $player_id, $audit_id, $season ] = $this->db->transaction(
				function () use ( $actor, $request_id, $data ): array {
					$request = $this->open_request( $request_id );
					$season  = $this->open_season( $request['season_id'] );
					$errors  = array();

					$classification = isset( $data['classification'] ) ? (int) $data['classification'] : $request['classification'];
					if ( ! Rules::is_classification( $classification ) ) {
						$errors[] = self::error( 'classification', __( 'Die Klassierung ist ungültig.', 'ttleague' ) );
					}

					$person_id = isset( $data['person_id'] ) && (int) $data['person_id'] > 0 ? (int) $data['person_id'] : null;
					$player    = null;
					$email     = null;
					if ( null !== $person_id ) {
						if ( null === $this->repo->person( $person_id ) ) {
							$errors[] = self::error( 'person_id', __( 'Die gewählte Person gibt es nicht.', 'ttleague' ) );
						} else {
							$player = $this->repo->player_of_person( $person_id );
						}
					} else {
						$email = strtolower( trim( (string) ( array_key_exists( 'email', $data ) ? $data['email'] : $request['email'] ) ) );
						$email = '' === $email ? null : $email;
						if ( null !== $email && ! Rules::is_email( $email ) ) {
							$errors[] = self::error( 'email', __( 'Die E-Mail-Adresse ist ungültig.', 'ttleague' ) );
						} elseif ( null !== $email && null !== $this->repo->person_by_email( $email ) ) {
							$owner = $this->repo->person( (int) $this->repo->person_by_email( $email ) );
							/* translators: %s: Name */
							$errors[] = self::error( 'email', sprintf( __( 'Die E-Mail-Adresse gehört schon zu %s. Wähle diese Person aus oder gib die Neumeldung ohne E-Mail-Adresse frei.', 'ttleague' ), $owner['name'] ?? '' ) );
						}
					}

					$license = (int) ( $data['license_no'] ?? 0 );
					if ( null === $player ) {
						if ( $license <= 0 ) {
							$errors[] = self::error( 'license_no', __( 'Die Lizenznummer fehlt.', 'ttleague' ) );
						} elseif ( null !== $this->repo->player_by_license( $license ) ) {
							/* translators: 1: Lizenznummer, 2: nächste freie Lizenznummer */
							$errors[] = self::error( 'license_no', sprintf( __( 'Die Lizenznummer %1$d ist schon vergeben. Die nächste freie ist %2$d.', 'ttleague' ), $license, $this->repo->next_license() ) );
						}
					}
					if ( array() !== $errors ) {
						throw RegistrationException::invalid( $errors );
					}

					$now = $this->access->now();
					if ( null === $person_id ) {
						$person_id = $this->db->insert(
							'person',
							array(
								'last_name'  => $request['last_name'],
								'first_name' => $request['first_name'],
								'email'      => $email,
								'created_at' => $now,
								'updated_at' => $now,
							)
						);
					}
					if ( null === $player ) {
						$player_id = $this->db->insert(
							'player',
							array(
								'person_id'   => $person_id,
								'license_no'  => $license,
								'stt_license' => $request['stt_license'],
							)
						);
					} else {
						$player_id = $player['id'];
					}

					$this->repo->lock_player( $player_id );
					$existing = $this->repo->player_season( $request['season_id'], $player_id );
					$approval = array(
						'classification' => (int) $classification,
						'status'         => ClubRegistration::ACTIVE,
						'approved_by'    => $actor->user_id,
						'approved_at'    => $now,
					);
					if ( null !== $existing && Rules::other_club( $request['club_id'], $existing['club_id'] ) ) {
						/* translators: 1: Name, 2: Club */
						throw RegistrationException::conflict( sprintf( __( '%1$s ist in dieser Saison schon für %2$s gemeldet (Art. 27.2).', 'ttleague' ), $request['name'], $existing['club'] ) );
					}
					if ( null !== $existing ) {
						$this->db->update( 'player_season', $approval, array( 'id' => $existing['id'] ) );
					} else {
						$this->db->insert(
							'player_season',
							$approval + array(
								'player_id'     => $player_id,
								'season_id'     => $request['season_id'],
								'club_id'       => $request['club_id'],
								'registered_at' => $request['requested_at'],
								'registered_by' => $request['requested_by'],
							)
						);
					}

					$note   = trim( (string) ( $data['note'] ?? '' ) );
					$change = array(
						'status'     => 'approved',
						'player_id'  => $player_id,
						'decided_by' => $actor->user_id,
						'decided_at' => $now,
						'note'       => '' === $note ? null : $note,
					);
					$this->db->update( 'player_request', $change, array( 'id' => $request_id ) );
					$audit_id = $this->audit->record(
						'player_request',
						$request_id,
						'approved',
						$actor->user_id,
						$request,
						$change + array(
							'person_id'      => $person_id,
							'classification' => (int) $classification,
							'new_player'     => null === $player,
						)
					);
					return array( $player_id, $audit_id, $season );
				}
			);
		} catch ( DbException $e ) {
			// person.email und player.license_no sind eindeutig: gleichzeitig vergeben.
			throw RegistrationException::conflict( __( 'Die Freigabe liess sich nicht speichern: Lizenznummer oder E-Mail-Adresse wurden inzwischen vergeben. Bitte lade die Seite neu.', 'ttleague' ) );
		}
		$this->events->changed( $request['season_id'], $request['club_id'] );
		$this->events->decided( $this->decision( Decision::REQUEST_APPROVED, $season, $request['club_id'], $audit_id, $actor, $request['name'], trim( (string) ( $data['note'] ?? '' ) ) ) );
		return $player_id;
	}

	/**
	 * Neumeldung ablehnen. Die Bemerkung ist Pflicht und geht an den TK-Chef.
	 *
	 * @throws RegistrationException Ohne TK-Recht, ohne Bemerkung oder nach einem Entscheid.
	 */
	public function reject_request( Actor $actor, int $request_id, string $note ): void {
		self::require_tk( $actor );
		$request               = $this->request( $request_id );
		$note                  = self::required_note( $note );
		[ $audit_id, $season ] = $this->db->transaction(
			function () use ( $actor, $request_id, $note ): array {
				$request = $this->open_request( $request_id );
				$season  = $this->open_season( $request['season_id'] );
				$change  = array(
					'status'     => 'rejected',
					'decided_by' => $actor->user_id,
					'decided_at' => $this->access->now(),
					'note'       => $note,
				);
				$this->db->update( 'player_request', $change, array( 'id' => $request_id ) );
				return array( $this->audit->record( 'player_request', $request_id, 'rejected', $actor->user_id, $request, $change, $note ), $season );
			}
		);
		$this->events->changed( $request['season_id'], $request['club_id'] );
		$this->events->decided( $this->decision( Decision::REQUEST_REJECTED, $season, $request['club_id'], $audit_id, $actor, $request['name'], $note ) );
	}

	/**
	 * Provisorisch gemeldeten Spieler freigeben (Nachmeldung in der aktiven Saison): ab jetzt spielberechtigt.
	 *
	 * @throws RegistrationException Ohne TK-Recht oder wenn der Spieler nicht provisorisch gemeldet ist.
	 */
	public function approve_player( Actor $actor, int $season_id, int $club_id, int $player_id ): void {
		self::require_tk( $actor );
		[ $audit_id, $season, $name ] = $this->db->transaction(
			function () use ( $actor, $season_id, $club_id, $player_id ): array {
				$this->repo->lock_club( $club_id );
				$season = $this->open_season( $season_id );
				$row    = $this->provisional( $season_id, $club_id, $player_id );
				$change = array(
					'status'      => ClubRegistration::ACTIVE,
					'approved_by' => $actor->user_id,
					'approved_at' => $this->access->now(),
				);
				$this->db->update( 'player_season', $change, array( 'id' => $row['id'] ) );
				return array(
					$this->audit->record( 'player_season', $row['id'], 'approved', $actor->user_id, $row, $change ),
					$season,
					(string) ( $this->repo->player( $player_id )['name'] ?? '' ),
				);
			}
		);
		$this->events->changed( $season_id, $club_id );
		$this->events->decided( $this->decision( Decision::PLAYER_APPROVED, $season, $club_id, $audit_id, $actor, $name ) );
	}

	/**
	 * Provisorische Meldung eines Spielers ablehnen: Die Zeile wird entfernt, die Bemerkung ist Pflicht.
	 *
	 * @throws RegistrationException Ohne TK-Recht, ohne Bemerkung oder wenn der Spieler nicht provisorisch gemeldet ist.
	 */
	public function reject_player( Actor $actor, int $season_id, int $club_id, int $player_id, string $note ): void {
		self::require_tk( $actor );
		$note                         = self::required_note( $note );
		[ $audit_id, $season, $name ] = $this->db->transaction(
			function () use ( $actor, $season_id, $club_id, $player_id, $note ): array {
				$this->repo->lock_club( $club_id );
				$season = $this->open_season( $season_id );
				$row    = $this->provisional( $season_id, $club_id, $player_id );
				$this->db->delete( 'player_season', array( 'id' => $row['id'] ) );
				return array(
					$this->audit->record( 'player_season', $row['id'], 'rejected', $actor->user_id, $row, null, $note ),
					$season,
					(string) ( $this->repo->player( $player_id )['name'] ?? '' ),
				);
			}
		);
		$this->events->changed( $season_id, $club_id );
		$this->events->decided( $this->decision( Decision::PLAYER_REJECTED, $season, $club_id, $audit_id, $actor, $name, $note ) );
	}

	/**
	 * Meldung der geplanten Saison freigeben: Stand «reviewed», alle Spieler des Clubs in dieser
	 * Saison werden «active». Provisorisch gemeldete erhalten dabei die Klassierung, die jetzt
	 * gilt (z. B. nach dem Abschluss der Klassierung der laufenden Saison).
	 *
	 * @throws RegistrationException Ohne TK-Recht, mit offenen Verstössen oder offenen Neumeldungen.
	 */
	public function approve( Actor $actor, int $season_id, int $club_id ): void {
		self::require_tk( $actor );
		[ $audit_id, $season ] = $this->db->transaction(
			function () use ( $actor, $season_id, $club_id ): array {
				$this->repo->lock_club( $club_id );
				[ $season, $club_season ] = $this->planned( $season_id, $club_id );
				if ( Rules::REVIEWED === $club_season['status'] ) {
					throw RegistrationException::conflict( __( 'Die Meldung ist schon geprüft.', 'ttleague' ) );
				}
				$violations = $this->registration->violations( $season_id, $club_id );
				if ( array() !== $violations ) {
					throw RegistrationException::invalid( $violations, __( 'Die Meldung hat noch offene Punkte und lässt sich so nicht freigeben.', 'ttleague' ) );
				}
				if ( array() !== $this->repo->requests( $season_id, $club_id, 'requested' ) ) {
					throw RegistrationException::conflict( __( 'Es gibt noch offene Neumeldungen. Gib sie zuerst frei oder lehne sie ab.', 'ttleague' ) );
				}
				$now       = $this->access->now();
				$activated = 0;
				foreach ( $this->repo->players( $season_id, $club_id ) as $player ) {
					if ( ClubRegistration::PROVISIONAL !== $player['status'] ) {
						continue;
					}
					$this->db->update(
						'player_season',
						array(
							'classification' => $this->repo->carried_classification( $player['player_id'], $season ) ?? $player['classification'],
							'status'         => ClubRegistration::ACTIVE,
							'approved_by'    => $actor->user_id,
							'approved_at'    => $now,
						),
						array(
							'season_id' => $season_id,
							'player_id' => $player['player_id'],
						)
					);
					++$activated;
				}
				$change = array(
					'status'      => Rules::REVIEWED,
					'reviewed_at' => $now,
					'reviewed_by' => $actor->user_id,
					'note'        => null,
				);
				$this->repo->update_club_season( $club_season['id'], $change );
				return array( $this->audit->record( 'club_season', $club_season['id'], 'reviewed', $actor->user_id, $club_season, $change + array( 'players_activated' => $activated ) ), $season );
			}
		);
		$this->events->changed( $season_id, $club_id );
		$this->events->decided( $this->decision( Decision::REGISTRATION_APPROVED, $season, $club_id, $audit_id, $actor ) );
	}

	/**
	 * Meldung mit Bemerkung an den Club zurückgeben: Stand wieder «open».
	 *
	 * @throws RegistrationException Ohne TK-Recht, ohne Bemerkung oder wenn die Meldung offen ist.
	 */
	public function return_to_club( Actor $actor, int $season_id, int $club_id, string $note ): void {
		self::require_tk( $actor );
		$note                  = self::required_note( $note );
		[ $audit_id, $season ] = $this->db->transaction(
			function () use ( $actor, $season_id, $club_id, $note ): array {
				$this->repo->lock_club( $club_id );
				[ $season, $club_season ] = $this->planned( $season_id, $club_id );
				if ( Rules::OPEN === $club_season['status'] ) {
					throw RegistrationException::conflict( __( 'Die Meldung ist offen; der Club kann sie bearbeiten.', 'ttleague' ) );
				}
				$change = array(
					'status' => Rules::OPEN,
					'note'   => $note,
				);
				$this->repo->update_club_season( $club_season['id'], $change );
				return array( $this->audit->record( 'club_season', $club_season['id'], 'returned', $actor->user_id, $club_season, $change, $note ), $season );
			}
		);
		$this->events->changed( $season_id, $club_id );
		$this->events->decided( $this->decision( Decision::REGISTRATION_RETURNED, $season, $club_id, $audit_id, $actor, '', $note ) );
	}

	/**
	 * @throws RegistrationException Ohne TK-Recht.
	 */
	private static function require_tk( Actor $actor ): void {
		if ( ! $actor->is_tk ) {
			throw RegistrationException::forbidden( __( 'Das darf nur die TK.', 'ttleague' ) );
		}
	}

	/**
	 * @throws RegistrationException Ohne Bemerkung.
	 */
	private static function required_note( string $note ): string {
		$note = trim( $note );
		if ( '' === $note ) {
			throw RegistrationException::field( 'note', __( 'Bitte begründe den Entscheid in der Bemerkung.', 'ttleague' ) );
		}
		return $note;
	}

	/**
	 * @return array<string,mixed>
	 * @throws RegistrationException Wenn es die Neumeldung nicht gibt.
	 */
	private function request( int $request_id ): array {
		$request = $this->repo->request( $request_id );
		if ( null === $request ) {
			throw RegistrationException::not_found( __( 'Diese Neumeldung gibt es nicht.', 'ttleague' ) );
		}
		return $request;
	}

	/**
	 * Neumeldung unter der Sperre des Clubs neu lesen; sie muss noch offen sein.
	 *
	 * @return array<string,mixed>
	 * @throws RegistrationException Nach einem Entscheid.
	 */
	private function open_request( int $request_id ): array {
		$request = $this->request( $request_id );
		$this->repo->lock_club( $request['club_id'] );
		$request = $this->request( $request_id );
		if ( 'requested' !== $request['status'] ) {
			throw RegistrationException::conflict( __( 'Über diese Neumeldung ist schon entschieden.', 'ttleague' ) );
		}
		return $request;
	}

	/**
	 * Geplante oder aktive Saison.
	 *
	 * @return array<string,mixed>
	 * @throws RegistrationException Bei einer unbekannten oder archivierten Saison.
	 */
	private function open_season( int $season_id ): array {
		$season = $this->seasons->by_id( $season_id );
		if ( null === $season ) {
			throw RegistrationException::not_found( __( 'Diese Saison gibt es nicht.', 'ttleague' ) );
		}
		if ( SeasonRepository::ARCHIVED === $season['status'] ) {
			throw RegistrationException::conflict( __( 'Die Saison ist abgeschlossen.', 'ttleague' ) );
		}
		return $season;
	}

	/**
	 * Geplante Saison und Meldestand des Clubs.
	 *
	 * @return array{0:array<string,mixed>,1:array<string,mixed>}
	 * @throws RegistrationException Wenn die Saison nicht geplant ist oder der Club nicht teilnimmt.
	 */
	private function planned( int $season_id, int $club_id ): array {
		$season      = $this->seasons->by_id( $season_id );
		$club_season = $this->repo->club_season( $season_id, $club_id );
		if ( null === $season || null === $club_season ) {
			throw RegistrationException::not_found( __( 'Diese Meldung gibt es nicht.', 'ttleague' ) );
		}
		if ( SeasonRepository::PLANNED !== $season['status'] ) {
			throw RegistrationException::conflict( __( 'Geprüft wird nur die Meldung der geplanten Saison.', 'ttleague' ) );
		}
		return array( $season, $club_season );
	}

	/**
	 * @return array<string,mixed>
	 * @throws RegistrationException Wenn der Spieler für den Club nicht provisorisch gemeldet ist.
	 */
	private function provisional( int $season_id, int $club_id, int $player_id ): array {
		$row = $this->repo->player_season( $season_id, $player_id );
		if ( null === $row || $row['club_id'] !== $club_id ) {
			throw RegistrationException::not_found( __( 'Dieser Spieler ist für den Club nicht gemeldet.', 'ttleague' ) );
		}
		if ( ClubRegistration::PROVISIONAL !== $row['status'] ) {
			throw RegistrationException::conflict( __( 'Dieser Spieler ist schon freigegeben.', 'ttleague' ) );
		}
		return $row;
	}

	/**
	 * @param array<string,mixed> $season Saison.
	 */
	private function decision( string $kind, array $season, int $club_id, int $audit_id, Actor $actor, string $subject = '', string $note = '' ): Decision {
		return new Decision(
			$kind,
			(int) $season['id'],
			(string) $season['name'],
			$club_id,
			(string) ( $this->repo->club( $club_id )['name'] ?? '' ),
			$audit_id,
			$actor->user_id,
			$actor->person_id,
			$subject,
			'' === $note ? null : $note
		);
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
