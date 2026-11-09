<?php
/**
 * Push-Abos der Personen (push_subscription).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications\Push;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;

/**
 * Ein Endpunkt gehört genau einer Person (eindeutig über den SHA-256-Hash). Meldet sich auf
 * demselben Browser eine andere Person an und abonniert, geht das Abo an sie über. Pro Person
 * gelten höchstens $max Abos; beim Überschreiten fallen die ältesten weg.
 */
final class Subscriptions {

	public const MAX_PER_PERSON = 10;

	public function __construct(
		private readonly Db $db,
		private readonly Clock $clock,
		private readonly int $max = self::MAX_PER_PERSON,
	) {}

	public function save( int $person_id, Subscription $subscription ): void {
		$this->db->query(
			"INSERT INTO {$this->db->table( 'push_subscription' )} (person_id, endpoint_hash, endpoint, keys_json, created_at) VALUES (%d, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE person_id = VALUES(person_id), endpoint = VALUES(endpoint), keys_json = VALUES(keys_json), created_at = VALUES(created_at)",
			array( $person_id, self::hash( $subscription->endpoint ), $subscription->endpoint, $subscription->keys_json(), $this->clock->now()->format( 'Y-m-d H:i:s' ) )
		);
		$ids = array_map(
			'intval',
			$this->db->get_col(
				"SELECT id FROM {$this->db->table( 'push_subscription' )} WHERE person_id = %d ORDER BY created_at DESC, id DESC",
				array( $person_id )
			)
		);
		foreach ( array_slice( $ids, max( 1, $this->max ) ) as $old ) {
			$this->delete( $old );
		}
	}

	/**
	 * Abo einer Person über den Endpunkt entfernen (Abmelden auf dem Gerät).
	 */
	public function remove( int $person_id, string $endpoint ): bool {
		return $this->db->query(
			"DELETE FROM {$this->db->table( 'push_subscription' )} WHERE endpoint_hash = %s AND person_id = %d",
			array( self::hash( trim( $endpoint ) ), $person_id )
		) > 0;
	}

	public function delete( int $id ): void {
		$this->db->delete( 'push_subscription', array( 'id' => $id ) );
	}

	/**
	 * @return list<Subscription>
	 */
	public function for_person( int $person_id ): array {
		$subscriptions = array();
		foreach ( $this->db->get_results(
			"SELECT id, person_id, endpoint, keys_json FROM {$this->db->table( 'push_subscription' )} WHERE person_id = %d ORDER BY created_at DESC, id DESC",
			array( $person_id )
		) as $row ) {
			$subscription = Subscription::from_row( $row );
			if ( null === $subscription ) {
				$this->delete( (int) $row['id'] );
				continue;
			}
			$subscriptions[] = $subscription;
		}
		return $subscriptions;
	}

	public function count( int $person_id ): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'push_subscription' )} WHERE person_id = %d", array( $person_id ) );
	}

	public static function hash( string $endpoint ): string {
		return hash( 'sha256', $endpoint );
	}
}
