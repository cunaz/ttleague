<?php
/**
 * Datenbankzugriffe für Anlässe und Anmeldungen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

use TTLeague\Domain\Events\RegistrationRules;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Notifications\NotificationLog;

/**
 * Ein Anlass ist ein Array
 * array{id:int,season_id:int|null,type:string,title:string,starts_at:string,ends_at:string|null,
 *   location:string|null,deadline:string|null,max_participants:int|null,post_id:int|null,
 *   status:string,created_by:int|null}.
 * Termine sind Ortszeit der Website, registration.created_at ist UTC.
 */
final class EventRepository {

	private const COLUMNS = 'id, season_id, type, title, starts_at, ends_at, location, deadline, max_participants, post_id, status, created_by';

	public function __construct( private readonly Db $db ) {}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		$row = $this->db->get_row( 'SELECT ' . self::COLUMNS . " FROM {$this->db->table( 'event' )} WHERE id = %d", array( $id ) );
		return null === $row ? null : self::event( $row );
	}

	/**
	 * Sperrt die Zeile des Anlasses bis zum Ende der laufenden Transaktion (SELECT … FOR UPDATE)
	 * und liefert den gesperrt gelesenen Stand. Alle An- und Abmeldungen und alle Änderungen
	 * eines Anlasses reihen sich so hintereinander. Muss die erste Abfrage der Transaktion sein:
	 * Erst danach gelesene Anmeldungen zeigen den Stand nach allen Transaktionen, die die Sperre
	 * vorher hielten.
	 *
	 * @return array<string,mixed>|null
	 */
	public function lock( int $id ): ?array {
		$row = $this->db->get_row( 'SELECT ' . self::COLUMNS . " FROM {$this->db->table( 'event' )} WHERE id = %d FOR UPDATE", array( $id ) );
		return null === $row ? null : self::event( $row );
	}

	/**
	 * Alle Anlässe mit Anzahl Teilnehmenden und Warteliste, die neuesten zuerst.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function all(): array {
		$registration = $this->db->table( 'registration' );
		$rows         = $this->db->get_results(
			'SELECT ' . self::COLUMNS . ",
				( SELECT COUNT(*) FROM {$registration} r WHERE r.event_id = e.id AND r.status = %s ) AS registered,
				( SELECT COUNT(*) FROM {$registration} r WHERE r.event_id = e.id AND r.status = %s ) AS waitlist
			FROM {$this->db->table( 'event' )} e
			ORDER BY e.starts_at DESC, e.id DESC",
			array( RegistrationRules::REGISTERED, RegistrationRules::WAITLIST )
		);
		return array_map(
			static fn( array $row ): array => self::event( $row ) + array(
				'registered' => (int) $row['registered'],
				'waitlist'   => (int) $row['waitlist'],
			),
			$rows
		);
	}

	/**
	 * @param array<string,int|string|null> $data Spaltenwerte.
	 */
	public function insert( array $data ): int {
		return $this->db->insert( 'event', $data );
	}

	/**
	 * @param array<string,int|string|null> $data Spaltenwerte.
	 */
	public function update( int $id, array $data ): void {
		$this->db->update( 'event', $data, array( 'id' => $id ) );
	}

	public function season_exists( int $season_id ): bool {
		return null !== $this->db->get_var( "SELECT id FROM {$this->db->table( 'season' )} WHERE id = %d", array( $season_id ) );
	}

	/**
	 * Anzahl Teilnehmende und Länge der Warteliste.
	 *
	 * @return array{registered:int,waitlist:int}
	 */
	public function counts( int $event_id ): array {
		$counts = array(
			RegistrationRules::REGISTERED => 0,
			RegistrationRules::WAITLIST   => 0,
		);
		foreach ( $this->db->get_results(
			"SELECT status, COUNT(*) AS n FROM {$this->db->table( 'registration' )} WHERE event_id = %d GROUP BY status",
			array( $event_id )
		) as $row ) {
			if ( isset( $counts[ (string) $row['status'] ] ) ) {
				$counts[ (string) $row['status'] ] = (int) $row['n'];
			}
		}
		return $counts;
	}

	/**
	 * Anmeldung einer Person für einen Anlass.
	 *
	 * @return array{id:int,event_id:int,person_id:int,status:string,note:string|null,created_at:string}|null
	 */
	public function registration( int $event_id, int $person_id ): ?array {
		$row = $this->db->get_row(
			"SELECT id, event_id, person_id, status, note, created_at FROM {$this->db->table( 'registration' )} WHERE event_id = %d AND person_id = %d",
			array( $event_id, $person_id )
		);
		return null === $row ? null : self::registration_row( $row );
	}

	/**
	 * Die ältesten Anmeldungen der Warteliste: nach Zeitpunkt der Anmeldung, dann nach ID.
	 *
	 * @return list<array{id:int,event_id:int,person_id:int,status:string,note:string|null,created_at:string}>
	 */
	public function waiting( int $event_id, int $limit ): array {
		if ( $limit <= 0 ) {
			return array();
		}
		return array_map(
			array( self::class, 'registration_row' ),
			$this->db->get_results(
				"SELECT id, event_id, person_id, status, note, created_at FROM {$this->db->table( 'registration' )}
				WHERE event_id = %d AND status = %s ORDER BY created_at, id LIMIT %d",
				array( $event_id, RegistrationRules::WAITLIST, $limit )
			)
		);
	}

	/**
	 * @param array<string,int|string|null> $data Spaltenwerte.
	 */
	public function insert_registration( array $data ): int {
		return $this->db->insert( 'registration', $data );
	}

	public function delete_registration( int $id ): void {
		$this->db->delete( 'registration', array( 'id' => $id ) );
	}

	public function promote( int $id ): void {
		$this->db->update( 'registration', array( 'status' => RegistrationRules::REGISTERED ), array( 'id' => $id ) );
	}

	/**
	 * Teilnehmerliste: zuerst die Teilnehmenden, dann die Warteliste, je in der Reihenfolge der
	 * Anmeldung. Der Club ist der, für den die Person in der Saison gemeldet ist, sonst der ihrer Funktion.
	 *
	 * @param int|null $season_id Saison für die Clubzuordnung.
	 * @return list<array{id:int,person_id:int,status:string,note:string|null,created_at:string,first_name:string,last_name:string,name:string,club:string|null}>
	 */
	public function registrations( int $event_id, ?int $season_id ): array {
		$season = (int) $season_id;
		$rows   = $this->db->get_results(
			"SELECT r.id, r.person_id, r.status, r.note, r.created_at, pe.first_name, pe.last_name,
				COALESCE(
					( SELECT c.short_name FROM {$this->db->table( 'player' )} p
						JOIN {$this->db->table( 'player_season' )} ps ON ps.player_id = p.id
						JOIN {$this->db->table( 'club' )} c ON c.id = ps.club_id
						WHERE p.person_id = r.person_id AND ps.season_id = %d ORDER BY ps.id LIMIT 1 ),
					( SELECT c.short_name FROM {$this->db->table( 'club_role' )} cr
						JOIN {$this->db->table( 'club' )} c ON c.id = cr.club_id
						WHERE cr.person_id = r.person_id AND cr.season_id = %d ORDER BY cr.id LIMIT 1 )
				) AS club
			FROM {$this->db->table( 'registration' )} r
			JOIN {$this->db->table( 'person' )} pe ON pe.id = r.person_id
			WHERE r.event_id = %d
			ORDER BY r.status = %s, r.created_at, r.id",
			array( $season, $season, $event_id, RegistrationRules::WAITLIST )
		);
		return array_map(
			static fn( array $row ): array => array(
				'id'         => (int) $row['id'],
				'person_id'  => (int) $row['person_id'],
				'status'     => (string) $row['status'],
				'note'       => null === $row['note'] || '' === $row['note'] ? null : (string) $row['note'],
				'created_at' => (string) $row['created_at'],
				'first_name' => (string) $row['first_name'],
				'last_name'  => (string) $row['last_name'],
				'name'       => self::name( $row ),
				'club'       => null === $row['club'] ? null : (string) $row['club'],
			),
			$rows
		);
	}

	/**
	 * Personen aller Anmeldungen eines Anlasses (Teilnehmende und Warteliste).
	 *
	 * @return list<int>
	 */
	public function registered_people( int $event_id ): array {
		return array_map(
			'intval',
			$this->db->get_col( "SELECT person_id FROM {$this->db->table( 'registration' )} WHERE event_id = %d ORDER BY created_at, id", array( $event_id ) )
		);
	}

	/**
	 * Angemeldete Personen (Teilnehmende und Warteliste) ohne bestätigt versendete Nachricht
	 * dieser Art zum Anlass, nach Person-ID geordnet und erst ab der Person nach $after_person.
	 * Offene Reservierungen im Versandprotokoll zählen nicht als versendet.
	 *
	 * @param string $kind Art der Nachricht im Versandprotokoll (notification_log.event_type).
	 * @return list<int>
	 */
	public function people_without_notice( int $event_id, string $kind, string $ref_type, int $after_person = 0 ): array {
		return array_map(
			'intval',
			$this->db->get_col(
				"SELECT r.person_id FROM {$this->db->table( 'registration' )} r
				WHERE r.event_id = %d AND r.person_id > %d
					AND NOT EXISTS (
						SELECT 1 FROM {$this->db->table( 'notification_log' )} l
						WHERE l.person_id = r.person_id AND l.event_type = %s AND l.ref_type = %s AND l.ref_id = %d AND l.status = %s
					)
				ORDER BY r.person_id",
				array( $event_id, $after_person, $kind, $ref_type, $event_id, NotificationLog::SENT )
			)
		);
	}

	/**
	 * Konto, das den Anlass abgesagt hat (aus dem Änderungsprotokoll), oder null.
	 */
	public function cancelled_by( int $event_id ): ?int {
		$user = $this->db->get_var(
			"SELECT user_id FROM {$this->db->table( 'audit_log' )} WHERE entity = 'event' AND entity_id = %d AND action = 'cancelled' ORDER BY id DESC LIMIT 1",
			array( $event_id )
		);
		return null === $user ? null : (int) $user;
	}

	/**
	 * Abgesagte Anlässe mit ausstehenden Nachrichten dieser Art, deren Absage (Änderungsprotokoll,
	 * UTC) nicht vor $since liegt; die älteste Absage zuerst. Ausstehend heisst: Eine angemeldete,
	 * aktive Person, die nicht das absagende Konto ist, hat noch keine bestätigt versendete
	 * Nachricht. Wer keinen Kanal hat (keine Adresse, abgewählt), zählt hier mit: Das zeigt erst
	 * der Versandschritt, der für solche Personen nichts versendet.
	 *
	 * @param string $since Zeitpunkt in UTC «Y-m-d H:i:s».
	 * @param string $kind  Art der Nachricht im Versandprotokoll (notification_log.event_type).
	 * @return list<int>
	 */
	public function cancelled_with_pending_notices( string $since, string $kind, string $ref_type ): array {
		return array_map(
			'intval',
			$this->db->get_col(
				"SELECT e.id FROM {$this->db->table( 'event' )} e
				JOIN {$this->db->table( 'audit_log' )} a ON a.entity = 'event' AND a.entity_id = e.id AND a.action = 'cancelled'
				WHERE e.status = %s AND a.created_at >= %s
					AND EXISTS (
						SELECT 1 FROM {$this->db->table( 'registration' )} r
						JOIN {$this->db->table( 'person' )} pe ON pe.id = r.person_id
						WHERE r.event_id = e.id AND pe.status = 'active'
							AND ( pe.user_id IS NULL OR a.user_id IS NULL OR pe.user_id <> a.user_id )
							AND NOT EXISTS (
								SELECT 1 FROM {$this->db->table( 'notification_log' )} l
								WHERE l.person_id = r.person_id AND l.event_type = %s AND l.ref_type = %s AND l.ref_id = e.id AND l.status = %s
							)
					)
				GROUP BY e.id
				ORDER BY MIN( a.created_at ), e.id",
				array( RegistrationRules::CANCELLED, $since, $kind, $ref_type, NotificationLog::SENT )
			)
		);
	}

	/**
	 * @return array{id:int,name:string,status:string,user_id:int|null}|null
	 */
	public function person( int $person_id ): ?array {
		$row = $this->db->get_row( "SELECT id, first_name, last_name, status, user_id FROM {$this->db->table( 'person' )} WHERE id = %d", array( $person_id ) );
		if ( null === $row ) {
			return null;
		}
		return array(
			'id'      => (int) $row['id'],
			'name'    => self::name( $row ),
			'status'  => (string) $row['status'],
			'user_id' => null === $row['user_id'] ? null : (int) $row['user_id'],
		);
	}

	/**
	 * Name der Person eines Kontos, sonst null.
	 */
	public function person_name_for_user( int $user_id ): ?string {
		if ( $user_id <= 0 ) {
			return null;
		}
		$rows = $this->db->get_results( "SELECT first_name, last_name FROM {$this->db->table( 'person' )} WHERE user_id = %d ORDER BY id LIMIT 2", array( $user_id ) );
		return 1 === count( $rows ) ? self::name( $rows[0] ) : null;
	}

	/**
	 * Clubs, für die die Person in der Saison als Spieler gemeldet ist.
	 *
	 * @return list<int>
	 */
	public function club_ids( int $person_id, int $season_id ): array {
		return array_values(
			array_unique(
				array_map(
					'intval',
					$this->db->get_col(
						"SELECT ps.club_id FROM {$this->db->table( 'player_season' )} ps
						JOIN {$this->db->table( 'player' )} p ON p.id = ps.player_id
						WHERE ps.season_id = %d AND p.person_id = %d",
						array( $season_id, $person_id )
					)
				)
			)
		);
	}

	/**
	 * Aktive Personen, die in der Saison für einen der Clubs gemeldet sind, mit dem Stand ihrer
	 * Anmeldung zum Anlass (registered, waitlist oder null).
	 *
	 * @param list<int> $club_ids Clubs.
	 * @return list<array{person_id:int,name:string,club_id:int,club:string,registration:string|null}>
	 */
	public function club_players( array $club_ids, int $season_id, int $event_id ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $club_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$rows   = $this->db->get_results(
			"SELECT pe.id AS person_id, pe.first_name, pe.last_name, c.id AS club_id, c.short_name AS club, r.status AS registration
			FROM {$this->db->table( 'player_season' )} ps
			JOIN {$this->db->table( 'player' )} p ON p.id = ps.player_id
			JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			JOIN {$this->db->table( 'club' )} c ON c.id = ps.club_id
			LEFT JOIN {$this->db->table( 'registration' )} r ON r.event_id = %d AND r.person_id = pe.id
			WHERE ps.season_id = %d AND pe.status = 'active' AND ps.club_id IN (" . implode( ',', $ids ) . ')
			ORDER BY pe.last_name, pe.first_name, pe.id', // Nur Ganzzahlen.
			array( $event_id, $season_id )
		);
		$people = array();
		foreach ( $rows as $row ) {
			$people[ (int) $row['person_id'] ] ??= array(
				'person_id'    => (int) $row['person_id'],
				'name'         => self::name( $row ),
				'club_id'      => (int) $row['club_id'],
				'club'         => (string) $row['club'],
				'registration' => null === $row['registration'] ? null : (string) $row['registration'],
			);
		}
		return array_values( $people );
	}

	/**
	 * Aktive Personen, die die TK von Hand anmelden kann und die noch nicht angemeldet sind: mit
	 * Saison die gemeldeten Spieler und Funktionsträger dieser Saison, ohne Saison alle.
	 *
	 * @return list<array{person_id:int,name:string,club:string|null}>
	 */
	public function candidates( int $event_id, ?int $season_id ): array {
		$person = $this->db->table( 'person' );
		$taken  = "pe.status = 'active' AND pe.id NOT IN ( SELECT person_id FROM {$this->db->table( 'registration' )} WHERE event_id = %d )";
		if ( null === $season_id ) {
			$rows = $this->db->get_results(
				"SELECT pe.id AS person_id, pe.first_name, pe.last_name, NULL AS club FROM {$person} pe WHERE {$taken} ORDER BY pe.last_name, pe.first_name, pe.id",
				array( $event_id )
			);
		} else {
			$rows = $this->db->get_results(
				"SELECT pe.id AS person_id, pe.first_name, pe.last_name, MIN( c.short_name ) AS club
				FROM {$person} pe
				JOIN (
					SELECT p.person_id, ps.club_id FROM {$this->db->table( 'player_season' )} ps
						JOIN {$this->db->table( 'player' )} p ON p.id = ps.player_id WHERE ps.season_id = %d
					UNION
					SELECT cr.person_id, cr.club_id FROM {$this->db->table( 'club_role' )} cr WHERE cr.season_id = %d
				) m ON m.person_id = pe.id
				JOIN {$this->db->table( 'club' )} c ON c.id = m.club_id
				WHERE {$taken}
				GROUP BY pe.id, pe.first_name, pe.last_name
				ORDER BY pe.last_name, pe.first_name, pe.id",
				array( $season_id, $season_id, $event_id )
			);
		}
		return array_map(
			static fn( array $row ): array => array(
				'person_id' => (int) $row['person_id'],
				'name'      => self::name( $row ),
				'club'      => null === $row['club'] ? null : (string) $row['club'],
			),
			$rows
		);
	}

	/**
	 * @param array<string,string|null> $row Zeile aus event.
	 * @return array<string,mixed>
	 */
	private static function event( array $row ): array {
		$int  = static fn( ?string $value ): ?int => null === $value ? null : (int) $value;
		$text = static fn( ?string $value ): ?string => null === $value || '' === $value ? null : $value;
		return array(
			'id'               => (int) $row['id'],
			'season_id'        => $int( $row['season_id'] ),
			'type'             => (string) $row['type'],
			'title'            => (string) $row['title'],
			'starts_at'        => (string) $row['starts_at'],
			'ends_at'          => $text( $row['ends_at'] ),
			'location'         => $text( $row['location'] ),
			'deadline'         => $text( $row['deadline'] ),
			'max_participants' => $int( $row['max_participants'] ),
			'post_id'          => $int( $row['post_id'] ),
			'status'           => (string) $row['status'],
			'created_by'       => $int( $row['created_by'] ),
		);
	}

	/**
	 * @param array<string,string|null> $row Zeile aus registration.
	 * @return array{id:int,event_id:int,person_id:int,status:string,note:string|null,created_at:string}
	 */
	private static function registration_row( array $row ): array {
		return array(
			'id'         => (int) $row['id'],
			'event_id'   => (int) $row['event_id'],
			'person_id'  => (int) $row['person_id'],
			'status'     => (string) $row['status'],
			'note'       => null === $row['note'] || '' === $row['note'] ? null : (string) $row['note'],
			'created_at' => (string) $row['created_at'],
		);
	}

	/**
	 * @param array<string,string|null> $row Zeile mit first_name und last_name.
	 */
	private static function name( array $row ): string {
		return trim( trim( (string) $row['first_name'] ) . ' ' . trim( (string) $row['last_name'] ) );
	}
}
