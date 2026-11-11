<?php
/**
 * Personenbezogene Daten einer Person: Auskunft, Löschung und Anonymisierung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;

/**
 * Grundlage der Datenschutz-Werkzeuge (Privacy, wp ttleague account anonymize), ohne
 * WordPress-Abhängigkeit.
 *
 * - export(): was über eine Person gespeichert ist (Profil, Funktionen, Meldungen,
 *   Einstellungen, Anzahl Push-Abos).
 * - erase(): entfernt, was der Spielbetrieb nicht braucht (Telefon, Einstellungen, Push-Abos,
 *   Anmelde-Tokens). Name, Meldungen und Resultate bleiben.
 * - anonymize(): ersetzt zusätzlich den Namen durch einen Platzhalter («Ehemaliges Mitglied 3»),
 *   löscht E-Mail und Einwilligung, löst das WordPress-Konto und setzt die Person auf inaktiv.
 *   Lizenznummer, Meldungen und Resultate bleiben, damit Ranglisten und Matchreports stimmen.
 *
 * Nach anonymize() muss die WordPress-Schicht die Datenversion erhöhen (Privacy::anonymize),
 * damit die Lesemodelle den Platzhalter zeigen.
 */
final class PersonalData {

	/** Nachname anonymisierter Personen, gefolgt von einer laufenden Nummer; der Vorname ist leer. */
	public const PLACEHOLDER = 'Ehemaliges Mitglied';

	public const STATUS_INACTIVE = 'inactive';

	public function __construct( private readonly Db $db, private readonly AuditLog $audit ) {}

	/**
	 * Die Person zu einer E-Mail-Adresse: die Person mit dieser Adresse (person.email ist
	 * eindeutig, Gross-/Kleinschreibung egal) oder die Person, die mit dem Konto verknüpft ist.
	 * Führen Adresse und Konto zu verschiedenen Personen (oder das Konto zu mehreren), ist die
	 * Zuordnung mehrdeutig und es gibt keine Person: lieber nichts exportieren oder löschen als
	 * die Daten einer anderen Person (siehe is_ambiguous()).
	 *
	 * @param int|null $user_id WordPress-Konto mit dieser Adresse, falls es eines gibt.
	 * @return list<int> Höchstens eine Person.
	 */
	public function person_ids( string $email, ?int $user_id = null ): array {
		$ids = $this->candidates( $email, $user_id );
		return 1 === count( $ids ) ? $ids : array();
	}

	/**
	 * Führen Adresse und Konto zu verschiedenen Personen? Dann muss die TK die Zuordnung
	 * klären, bevor Daten exportiert oder gelöscht werden.
	 *
	 * @param int|null $user_id WordPress-Konto mit dieser Adresse, falls es eines gibt.
	 */
	public function is_ambiguous( string $email, ?int $user_id = null ): bool {
		return count( $this->candidates( $email, $user_id ) ) > 1;
	}

	/**
	 * Führt $work für die Person der Anfrage aus und hält die Zuordnung dabei fest: Zuordnen
	 * und Verarbeiten laufen in einer Transaktion, die Zeilen der Kandidaten sind gesperrt.
	 * Eine gleichzeitig gelöste oder neue Verknüpfung kann so nicht dazu führen, dass die
	 * Daten einer anderen Person exportiert oder gelöscht werden. Ist die Zuordnung mehrdeutig
	 * oder gibt es keine Person, läuft $work nicht.
	 *
	 * @template T
	 * @param int|null         $user_id WordPress-Konto mit dieser Adresse, falls es eines gibt.
	 * @param callable(int): T $work    Verarbeitung der Person (Export oder Löschung).
	 * @return array{ambiguous:bool,person_id:int|null,result:T|null}
	 */
	public function for_request( string $email, ?int $user_id, callable $work ): array {
		return $this->db->transaction(
			function () use ( $email, $user_id, $work ): array {
				$ids = $this->candidates( $email, $user_id, true );
				if ( 1 !== count( $ids ) ) {
					return array(
						'ambiguous' => count( $ids ) > 1,
						'person_id' => null,
						'result'    => null,
					);
				}
				return array(
					'ambiguous' => false,
					'person_id' => $ids[0],
					'result'    => $work( $ids[0] ),
				);
			}
		);
	}

	/**
	 * Alle Personen, auf die Adresse oder Konto zeigen.
	 *
	 * @param bool $lock Zeilen bis zum Ende der Transaktion sperren (FOR UPDATE).
	 * @return list<int>
	 */
	private function candidates( string $email, ?int $user_id, bool $lock = false ): array {
		$email = trim( $email );
		$ids   = array();
		$for   = $lock ? ' FOR UPDATE' : '';
		if ( '' !== $email ) {
			foreach ( $this->db->get_col( "SELECT id FROM {$this->db->table( 'person' )} WHERE LOWER(email) = LOWER(%s) ORDER BY id{$for}", array( $email ) ) as $id ) {
				$ids[ (int) $id ] = true;
			}
		}
		if ( null !== $user_id && $user_id > 0 ) {
			foreach ( $this->db->get_col( "SELECT id FROM {$this->db->table( 'person' )} WHERE user_id = %d ORDER BY id{$for}", array( $user_id ) ) as $id ) {
				$ids[ (int) $id ] = true;
			}
		}
		return array_keys( $ids );
	}

	/**
	 * Gespeicherte Daten einer Person, oder null, wenn es sie nicht gibt.
	 *
	 * @return array{
	 *   profile:array{first_name:string,last_name:string,email:string|null,phone:string|null,phone_visible:bool,consent_at:string|null,status:string},
	 *   functions:list<array{season:string,role:string,club:string,team:string|null}>,
	 *   registrations:list<array{season:string,club:string,classification:int,license_no:int}>,
	 *   preferences:array<string,array{email:bool,push:bool}>,
	 *   push_subscriptions:int
	 * }|null
	 */
	public function export( int $person_id ): ?array {
		$person = $this->db->get_row( "SELECT * FROM {$this->db->table( 'person' )} WHERE id = %d", array( $person_id ) );
		if ( null === $person ) {
			return null;
		}
		$functions = array();
		foreach ( $this->db->get_results(
			"SELECT s.name AS season, cr.role, c.name AS club, t.name AS team
			FROM {$this->db->table( 'club_role' )} cr
			JOIN {$this->db->table( 'season' )} s ON s.id = cr.season_id
			JOIN {$this->db->table( 'club' )} c ON c.id = cr.club_id
			LEFT JOIN {$this->db->table( 'team' )} t ON t.id = cr.team_id
			WHERE cr.person_id = %d ORDER BY s.start_date DESC, cr.role DESC, t.name, cr.id",
			array( $person_id )
		) as $row ) {
			$functions[] = array(
				'season' => (string) $row['season'],
				'role'   => (string) $row['role'],
				'club'   => (string) $row['club'],
				'team'   => $row['team'],
			);
		}
		$registrations = array();
		foreach ( $this->db->get_results(
			"SELECT s.name AS season, c.name AS club, ps.classification, p.license_no
			FROM {$this->db->table( 'player' )} p
			JOIN {$this->db->table( 'player_season' )} ps ON ps.player_id = p.id
			JOIN {$this->db->table( 'season' )} s ON s.id = ps.season_id
			JOIN {$this->db->table( 'club' )} c ON c.id = ps.club_id
			WHERE p.person_id = %d ORDER BY s.start_date DESC, p.license_no",
			array( $person_id )
		) as $row ) {
			$registrations[] = array(
				'season'         => (string) $row['season'],
				'club'           => (string) $row['club'],
				'classification' => (int) $row['classification'],
				'license_no'     => (int) $row['license_no'],
			);
		}
		$preferences = array();
		foreach ( $this->db->get_results(
			"SELECT event_type, email, push FROM {$this->db->table( 'notification_pref' )} WHERE person_id = %d ORDER BY event_type",
			array( $person_id )
		) as $row ) {
			$preferences[ (string) $row['event_type'] ] = array(
				'email' => '1' === (string) $row['email'],
				'push'  => '1' === (string) $row['push'],
			);
		}
		return array(
			'profile'            => array(
				'first_name'    => (string) $person['first_name'],
				'last_name'     => (string) $person['last_name'],
				'email'         => self::text( $person['email'] ),
				'phone'         => self::text( $person['phone'] ),
				'phone_visible' => '1' === (string) $person['phone_visible'],
				'consent_at'    => self::text( $person['consent_at'] ),
				'status'        => (string) $person['status'],
			),
			'functions'          => $functions,
			'registrations'      => $registrations,
			'preferences'        => $preferences,
			'push_subscriptions' => $this->count( 'push_subscription', $person_id ),
		);
	}

	/**
	 * Löscht Telefon, Benachrichtigungs-Einstellungen, Push-Abos und Anmelde-Tokens. Name,
	 * E-Mail, Meldungen und Resultate bleiben. Null, wenn es die Person nicht gibt.
	 *
	 * @return array{phone:bool,preferences:int,push_subscriptions:int,login_tokens:int}|null Was entfernt wurde.
	 */
	public function erase( int $person_id, ?int $actor_user_id = null, ?string $note = null ): ?array {
		return $this->db->transaction(
			function () use ( $person_id, $actor_user_id, $note ): ?array {
				$person = $this->locked( $person_id );
				if ( null === $person ) {
					return null;
				}
				$removed = array( 'phone' => null !== self::text( $person['phone'] ) || '1' === (string) $person['phone_visible'] ) + $this->remove_private( $person_id );
				if ( $removed['phone'] ) {
					$this->db->update(
						'person',
						array(
							'phone'         => null,
							'phone_visible' => 0,
							'updated_at'    => $this->db->now(),
						),
						array( 'id' => $person_id )
					);
				}
				if ( self::any( $removed ) ) {
					// Im Protokoll stehen nur Anzahl und Art, nie die gelöschten Werte.
					$this->audit->record( 'person', $person_id, 'privacy_erased', $actor_user_id, null, $removed, $note );
				}
				return $removed;
			}
		);
	}

	/**
	 * Anonymisiert eine Person auf deren Wunsch.
	 *
	 * @return array{name:string,user_id:int|null,email:bool,phone:bool,preferences:int,push_subscriptions:int,login_tokens:int}
	 *         Neuer Name, gelöstes Konto und was entfernt wurde.
	 * @throws \RuntimeException Wenn es die Person nicht gibt oder sie schon anonymisiert ist.
	 */
	public function anonymize( int $person_id, ?int $actor_user_id = null, ?string $note = null ): array {
		return $this->db->transaction(
			function () use ( $person_id, $actor_user_id, $note ): array {
				$person = $this->locked( $person_id );
				if ( null === $person ) {
					throw new \RuntimeException( 'Person nicht gefunden.' );
				}
				if ( self::is_anonymized( $person ) ) {
					throw new \RuntimeException( 'Die Person ist bereits anonymisiert.' );
				}
				$name    = $this->next_placeholder();
				$user_id = null === $person['user_id'] ? null : (int) $person['user_id'];
				$removed = array(
					'email' => null !== self::text( $person['email'] ),
					'phone' => null !== self::text( $person['phone'] ),
				) + $this->remove_private( $person_id );

				$this->db->update(
					'person',
					array(
						'first_name'    => '',
						'last_name'     => $name,
						'email'         => null,
						'phone'         => null,
						'phone_visible' => 0,
						'consent_at'    => null,
						'user_id'       => null,
						'status'        => self::STATUS_INACTIVE,
						'updated_at'    => $this->db->now(),
					),
					array( 'id' => $person_id )
				);
				// Frühere Einträge zu Adressänderungen enthalten die Adresse im Klartext.
				$this->db->query(
					"UPDATE {$this->db->table( 'audit_log' )} SET before_json = NULL, after_json = NULL WHERE entity = 'person' AND entity_id = %d AND action = 'email_changed'",
					array( $person_id )
				);
				// Ohne den bisherigen Namen und ohne Adressen: nur, was entfernt wurde.
				$this->audit->record(
					'person',
					$person_id,
					'anonymized',
					$actor_user_id,
					array(
						'status'  => (string) $person['status'],
						'user_id' => $user_id,
					) + $removed,
					array(
						'status'    => self::STATUS_INACTIVE,
						'last_name' => $name,
					),
					$note
				);
				return array(
					'name'    => $name,
					'user_id' => $user_id,
				) + $removed;
			}
		);
	}

	/**
	 * Trägt die Person den Platzhalter und keine persönlichen Angaben mehr?
	 *
	 * @param array<string,string|null> $person Zeile aus person.
	 */
	public static function is_anonymized( array $person ): bool {
		return self::STATUS_INACTIVE === (string) ( $person['status'] ?? '' )
			&& '' === (string) ( $person['first_name'] ?? '' )
			&& null !== self::number( (string) ( $person['last_name'] ?? '' ) )
			&& null === self::text( $person['email'] ?? null )
			&& null === ( $person['user_id'] ?? null );
	}

	/**
	 * Funktionen der Person in der aktiven Saison (z. B. für einen Hinweis vor dem Anonymisieren).
	 */
	public function active_functions( int $person_id ): int {
		return (int) $this->db->get_var(
			"SELECT COUNT(*) FROM {$this->db->table( 'club_role' )} cr JOIN {$this->db->table( 'season' )} s ON s.id = cr.season_id AND s.status = 'active' WHERE cr.person_id = %d",
			array( $person_id )
		);
	}

	/**
	 * Nächster freier Platzhalter: höchste vergebene Nummer plus eins. Die gelesenen Zeilen
	 * bleiben bis zum Ende der Transaktion gesperrt, damit zwei gleichzeitige Läufe nicht
	 * dieselbe Nummer vergeben.
	 */
	private function next_placeholder(): string {
		$highest = 0;
		foreach ( $this->db->get_col(
			"SELECT last_name FROM {$this->db->table( 'person' )} WHERE last_name LIKE %s FOR UPDATE",
			array( self::PLACEHOLDER . ' %' )
		) as $last_name ) {
			$highest = max( $highest, self::number( (string) $last_name ) ?? 0 );
		}
		return self::PLACEHOLDER . ' ' . ( $highest + 1 );
	}

	/**
	 * Laufende Nummer eines Platzhalters («Ehemaliges Mitglied 12» => 12), sonst null.
	 */
	private static function number( string $last_name ): ?int {
		return 1 === preg_match( '/^' . preg_quote( self::PLACEHOLDER, '/' ) . ' ([1-9][0-9]*)$/', $last_name, $match ) ? (int) $match[1] : null;
	}

	/**
	 * Einstellungen, Push-Abos und Anmelde-Tokens löschen.
	 *
	 * @return array{preferences:int,push_subscriptions:int,login_tokens:int}
	 */
	private function remove_private( int $person_id ): array {
		return array(
			'preferences'        => $this->db->delete( 'notification_pref', array( 'person_id' => $person_id ) ),
			'push_subscriptions' => $this->db->delete( 'push_subscription', array( 'person_id' => $person_id ) ),
			'login_tokens'       => $this->db->delete( 'login_token', array( 'person_id' => $person_id ) ),
		);
	}

	/**
	 * Person für die Dauer der Transaktion sperren.
	 *
	 * @return array<string,string|null>|null
	 */
	private function locked( int $person_id ): ?array {
		return $this->db->get_row( "SELECT * FROM {$this->db->table( 'person' )} WHERE id = %d FOR UPDATE", array( $person_id ) );
	}

	private function count( string $table, int $person_id ): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( $table )} WHERE person_id = %d", array( $person_id ) );
	}

	/**
	 * @param array<string,bool|int> $removed Was entfernt wurde.
	 */
	private static function any( array $removed ): bool {
		foreach ( $removed as $value ) {
			if ( true === $value || ( is_int( $value ) && $value > 0 ) ) {
				return true;
			}
		}
		return false;
	}

	private static function text( ?string $value ): ?string {
		return null === $value || '' === trim( $value ) ? null : $value;
	}
}
