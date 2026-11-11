<?php
/**
 * Lokale und Hallensperren eines Clubs.
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
 * Lokale gehören zum Club, nicht zu einer Saison. Der TK-Chef (aktive oder geplante Saison) und
 * die TK ändern sie jederzeit; die Änderung gilt sofort und steht im audit_log, aus dem die
 * Übersicht der TK sie zeigt. Lokale werden nicht gelöscht (Begegnungen verweisen darauf),
 * sondern deaktiviert, sofern keine Mannschaft der aktiven oder geplanten Saison dort spielt.
 */
final class Venues {

	private const LENGTHS = array(
		'name'   => 120,
		'street' => 120,
		'zip'    => 10,
		'city'   => 80,
		'phone'  => 40,
	);

	private const REASON_LENGTH = 120;

	/** Obergrenze gegen Fehleingaben (Spalte tinyint). */
	private const MAX_TABLES = 99;

	private RegistrationRepository $repo;

	private SeasonRepository $seasons;

	public function __construct(
		private readonly Db $db,
		private readonly RegistrationAccess $access,
		private readonly AuditLog $audit,
		private readonly RegistrationEvents $events,
	) {
		$this->repo    = new RegistrationRepository( $db );
		$this->seasons = new SeasonRepository( $db );
	}

	/**
	 * Lokal anlegen ($venue_id null) oder ändern.
	 *
	 * @param array{name?:string,street?:string,zip?:string,city?:string,phone?:string,weekday?:int|null,open_time?:string,close_time?:string,tables_count?:int|null,active?:bool} $data Lokaldaten.
	 * @return int ID des Lokals.
	 * @throws RegistrationException Ohne Recht oder bei ungültigen Angaben.
	 */
	public function save( Actor $actor, int $club_id, ?int $venue_id, array $data ): int {
		$id = $this->db->transaction(
			function () use ( $actor, $club_id, $venue_id, $data ): int {
				$this->require_club( $actor, $club_id );
				$this->repo->lock_club( $club_id );
				$before = null;
				if ( null !== $venue_id ) {
					$before = $this->repo->venue( $venue_id );
					if ( null === $before || $before['club_id'] !== $club_id ) {
						throw RegistrationException::not_found( __( 'Dieses Lokal gibt es nicht.', 'ttleague' ) );
					}
				}
				$row    = self::row( $data );
				$errors = Rules::venue( $row );
				foreach ( self::LENGTHS as $field => $length ) {
					if ( mb_strlen( (string) $row[ $field ] ) > $length ) {
						$errors[] = array(
							'field'   => $field,
							/* translators: %d: Anzahl Zeichen */
							'message' => sprintf( __( 'Die Angabe ist zu lang (höchstens %d Zeichen).', 'ttleague' ), $length ),
						);
					}
				}
				if ( null !== $row['tables_count'] && $row['tables_count'] > self::MAX_TABLES ) {
					$errors[] = array(
						'field'   => 'tables_count',
						/* translators: %d: Anzahl */
						'message' => sprintf( __( 'Mehr als %d Tische lassen sich nicht erfassen.', 'ttleague' ), self::MAX_TABLES ),
					);
				}
				if ( null !== $venue_id && 0 === $row['active'] && $this->repo->venue_in_use( $venue_id, $this->current_seasons() ) ) {
					$errors[] = array(
						'field'   => 'active',
						'message' => __( 'In diesem Lokal spielt eine Mannschaft. Wähle für sie zuerst ein anderes Heimlokal.', 'ttleague' ),
					);
				}
				if ( array() !== $errors ) {
					throw RegistrationException::invalid( $errors );
				}
				if ( null === $venue_id ) {
					$venue_id = $this->db->insert( 'venue', $row + array( 'club_id' => $club_id ) );
					$this->audit->record( 'venue', $venue_id, 'created', $actor->user_id, null, $row + array( 'club_id' => $club_id ) );
					return $venue_id;
				}
				$this->db->update( 'venue', $row, array( 'id' => $venue_id ) );
				$this->audit->record( 'venue', $venue_id, 'updated', $actor->user_id, $before, $row + array( 'club_id' => $club_id ) );
				return $venue_id;
			}
		);
		$this->events->changed( null, $club_id );
		return $id;
	}

	/**
	 * Hallensperre eintragen: Das Lokal ist von … bis … (beide Tage eingeschlossen) nicht verfügbar.
	 *
	 * @return int ID der Sperre.
	 * @throws RegistrationException Ohne Recht oder bei ungültigen Tagen.
	 */
	public function add_closure( Actor $actor, int $venue_id, string $date_from, string $date_to, string $reason = '' ): int {
		$venue = $this->repo->venue( $venue_id );
		if ( null === $venue ) {
			throw RegistrationException::not_found( __( 'Dieses Lokal gibt es nicht.', 'ttleague' ) );
		}
		$id = $this->db->transaction(
			function () use ( $actor, $venue, $date_from, $date_to, $reason ): int {
				$this->require_club( $actor, $venue['club_id'] );
				$errors = Rules::closure( trim( $date_from ), trim( $date_to ) );
				$reason = trim( $reason );
				if ( mb_strlen( $reason ) > self::REASON_LENGTH ) {
					$errors[] = array(
						'field'   => 'reason',
						/* translators: %d: Anzahl Zeichen */
						'message' => sprintf( __( 'Die Angabe ist zu lang (höchstens %d Zeichen).', 'ttleague' ), self::REASON_LENGTH ),
					);
				}
				if ( array() !== $errors ) {
					throw RegistrationException::invalid( $errors );
				}
				$row = array(
					'venue_id'  => $venue['id'],
					'date_from' => trim( $date_from ),
					'date_to'   => trim( $date_to ),
					'reason'    => '' === $reason ? null : $reason,
				);
				$id  = $this->db->insert( 'venue_closure', $row );
				$this->audit->record( 'venue_closure', $id, 'created', $actor->user_id, null, $row + array( 'club_id' => $venue['club_id'] ) );
				return $id;
			}
		);
		$this->events->changed( null, $venue['club_id'] );
		return $id;
	}

	/**
	 * Hallensperre entfernen.
	 *
	 * @throws RegistrationException Ohne Recht oder wenn es die Sperre nicht gibt.
	 */
	public function delete_closure( Actor $actor, int $closure_id ): void {
		$closure = $this->repo->closure( $closure_id );
		if ( null === $closure ) {
			throw RegistrationException::not_found( __( 'Diese Hallensperre gibt es nicht.', 'ttleague' ) );
		}
		$this->db->transaction(
			function () use ( $actor, $closure ): void {
				$this->require_club( $actor, $closure['club_id'] );
				$this->db->delete( 'venue_closure', array( 'id' => $closure['id'] ) );
				$this->audit->record( 'venue_closure', $closure['id'], 'deleted', $actor->user_id, $closure );
			}
		);
		$this->events->changed( null, $closure['club_id'] );
	}

	/**
	 * @throws RegistrationException Wenn die Person den Club nicht verwaltet.
	 */
	private function require_club( Actor $actor, int $club_id ): void {
		if ( ! $this->access->manages( $actor, $club_id ) ) {
			throw RegistrationException::forbidden( __( 'Du kannst die Lokale dieses Clubs nicht bearbeiten.', 'ttleague' ) );
		}
		if ( null === $this->repo->club( $club_id ) ) {
			throw RegistrationException::not_found( __( 'Diesen Club gibt es nicht.', 'ttleague' ) );
		}
	}

	/**
	 * Aktive und geplante Saison (IDs).
	 *
	 * @return list<int>
	 */
	private function current_seasons(): array {
		$ids = array();
		foreach ( array( $this->seasons->active(), $this->seasons->planned() ) as $season ) {
			if ( null !== $season ) {
				$ids[] = (int) $season['id'];
			}
		}
		return $ids;
	}

	/**
	 * Eingabe in Spalten der Tabelle venue.
	 *
	 * @param array<string,mixed> $data Eingabe.
	 * @return array{name:string,street:string|null,zip:string|null,city:string|null,phone:string|null,weekday:int|null,open_time:string|null,close_time:string|null,tables_count:int|null,active:int}
	 */
	private static function row( array $data ): array {
		$text = static function ( string $key ) use ( $data ): ?string {
			$value = trim( (string) ( $data[ $key ] ?? '' ) );
			return '' === $value ? null : $value;
		};
		$time = static function ( string $key ) use ( $data ): ?string {
			$value = trim( (string) ( $data[ $key ] ?? '' ) );
			if ( '' === $value ) {
				return null;
			}
			return Rules::is_time( $value ) ? substr( $value, 0, 5 ) . ':00' : $value;
		};
		$int  = static fn( string $key ): ?int => isset( $data[ $key ] ) && '' !== $data[ $key ] ? (int) $data[ $key ] : null;
		return array(
			'name'         => trim( (string) ( $data['name'] ?? '' ) ),
			'street'       => $text( 'street' ),
			'zip'          => $text( 'zip' ),
			'city'         => $text( 'city' ),
			'phone'        => $text( 'phone' ),
			'weekday'      => $int( 'weekday' ),
			'open_time'    => $time( 'open_time' ),
			'close_time'   => $time( 'close_time' ),
			'tables_count' => $int( 'tables_count' ),
			'active'       => ! array_key_exists( 'active', $data ) || ! empty( $data['active'] ) ? 1 : 0,
		);
	}
}
