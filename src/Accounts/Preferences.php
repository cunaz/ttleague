<?php
/**
 * Benachrichtigungseinstellungen einer Person (notification_pref).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\Notifications\EventTypes;

final class Preferences {

	public function __construct( private readonly Db $db ) {}

	/**
	 * Einstellungen pro Ereignistyp; ohne Eintrag gilt E-Mail an, Push aus.
	 *
	 * @return array<string,array{email:bool,push:bool}>
	 */
	public function for_person( int $person_id ): array {
		$result = array();
		foreach ( array_keys( EventTypes::labels() ) as $type ) {
			$result[ $type ] = array(
				'email' => true,
				'push'  => false,
			);
		}
		foreach ( $this->db->get_results( "SELECT event_type, email, push FROM {$this->db->table( 'notification_pref' )} WHERE person_id = %d", array( $person_id ) ) as $row ) {
			$result[ (string) $row['event_type'] ] = array(
				'email' => '1' === $row['email'],
				'push'  => '1' === $row['push'],
			);
		}
		return $result;
	}

	public function set( int $person_id, string $event_type, bool $email, bool $push ): void {
		$this->db->query(
			"INSERT INTO {$this->db->table( 'notification_pref' )} (person_id, event_type, email, push) VALUES (%d, %s, %d, %d)
			ON DUPLICATE KEY UPDATE email = VALUES(email), push = VALUES(push)",
			array( $person_id, $event_type, $email ? 1 : 0, $push ? 1 : 0 )
		);
	}

	public function wants( int $person_id, string $event_type, string $channel ): bool {
		$prefs = $this->for_person( $person_id );
		return (bool) ( $prefs[ $event_type ][ $channel ] ?? 'email' === $channel );
	}
}
