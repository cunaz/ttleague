<?php
/**
 * Ein Push-Abo eines Browsers (PushSubscription): Endpunkt und Schlüssel.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications\Push;

final class Subscription {

	/**
	 * @param string $endpoint  Adresse beim Push-Dienst.
	 * @param string $p256dh    Öffentlicher Schlüssel des Browsers (65 Byte).
	 * @param string $auth      Authentisierungsgeheimnis (16 Byte).
	 * @param int    $id        ID in push_subscription (0 = nicht gespeichert).
	 * @param int    $person_id Person.
	 */
	public function __construct(
		public readonly string $endpoint,
		public readonly string $p256dh,
		public readonly string $auth,
		public readonly int $id = 0,
		public readonly int $person_id = 0,
	) {}

	/**
	 * Schlüssel für keys_json.
	 */
	public function keys_json(): string {
		return (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- reine Klasse ohne WordPress.
			array(
				'p256dh' => Base64Url::encode( $this->p256dh ),
				'auth'   => Base64Url::encode( $this->auth ),
			)
		);
	}

	/**
	 * Aus einer Zeile von push_subscription; null bei beschädigten Schlüsseln.
	 *
	 * @param array<string,string|null> $row Zeile.
	 */
	public static function from_row( array $row ): ?self {
		$keys   = json_decode( (string) ( $row['keys_json'] ?? '' ), true );
		$p256dh = is_array( $keys ) && is_string( $keys['p256dh'] ?? null ) ? Base64Url::decode( $keys['p256dh'] ) : null;
		$auth   = is_array( $keys ) && is_string( $keys['auth'] ?? null ) ? Base64Url::decode( $keys['auth'] ) : null;
		if ( null === $p256dh || null === $auth ) {
			return null;
		}
		return new self( (string) $row['endpoint'], $p256dh, $auth, (int) $row['id'], (int) $row['person_id'] );
	}
}
