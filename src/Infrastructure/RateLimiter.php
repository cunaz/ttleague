<?php
/**
 * Einfaches Rate-Limit mit festen Zeitfenstern.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Database\DbException;
use TTLeague\Infrastructure\Database\WpdbDb;
use TTLeague\Infrastructure\Time\Clock;

/**
 * Zählt Versuche pro Schlüssel und festem Zeitfenster direkt in der Datenbank. Ein bedingtes
 * UPDATE vergibt jeden Platz atomar, unabhängig von WordPress-Transients oder Objekt-Caches.
 * Ohne bestätigten Schreibzugriff wird kein Versuch zugelassen.
 */
final class RateLimiter {

	public function __construct( private readonly Db $db, private readonly Clock $clock ) {}

	public static function wordpress( Clock $clock ): self {
		return new self( WpdbDb::from_global(), $clock );
	}

	/**
	 * Registriert einen Versuch und meldet, ob er erlaubt ist.
	 */
	public function hit( string $bucket, string $subject, int $limit, int $window_seconds ): bool {
		if ( $limit < 1 || $window_seconds < 1 ) {
			throw new \InvalidArgumentException( 'Limit und Zeitfenster müssen positiv sein.' );
		}
		$window = intdiv( $this->clock->now()->getTimestamp(), $window_seconds );
		$key    = hash( 'sha256', implode( '|', array( strlen( $bucket ), $bucket, strlen( $subject ), $subject, $window_seconds, $window ) ) );
		$table  = $this->db->table( 'rate_limit' );
		try {
			$this->db->query(
				"INSERT IGNORE INTO $table (bucket_key, attempts, expires_at) VALUES (%s, 0, %s)",
				array( $key, gmdate( 'Y-m-d H:i:s', ( $window + 1 ) * $window_seconds ) )
			);
			return 1 === $this->db->query(
				"UPDATE $table SET attempts = attempts + 1 WHERE bucket_key = %s AND attempts < %d",
				array( $key, $limit )
			);
		} catch ( DbException | \PDOException $e ) {
			return false;
		}
	}

	/**
	 * Abgelaufene Fenster einen Tag behalten, damit laufende Anfragen sie nicht neu anlegen.
	 */
	public function purge(): int {
		return $this->db->query(
			"DELETE FROM {$this->db->table( 'rate_limit' )} WHERE expires_at <= %s",
			array( $this->clock->now()->modify( '-1 day' )->format( 'Y-m-d H:i:s' ) )
		);
	}
}
