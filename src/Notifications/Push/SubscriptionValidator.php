<?php
/**
 * Prüfung eines Push-Abos aus dem Browser ({endpoint, keys: {p256dh, auth}}).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications\Push;

/**
 * Endpunkt: höchstens 2048 Zeichen, https, ohne Benutzerangaben, Host eines bekannten
 * Push-Dienstes (Google, Mozilla, Apple, Microsoft). So sendet der Server nur an Push-Dienste
 * und nie an beliebige Adressen. Die Regel lässt sich ersetzen (WordPress-Filter
 * ttleague_push_endpoint_allowed), z. B. für einen lokalen Test-Endpunkt.
 *
 * Schlüssel: p256dh ein Punkt auf P-256 (65 Byte), auth 16 Byte, beide Base64url.
 */
final class SubscriptionValidator {

	public const MAX_ENDPOINT_LENGTH = 2048;

	/**
	 * Hosts der Push-Dienste; Unterdomänen gelten mit.
	 */
	public const PUSH_HOSTS = array(
		'fcm.googleapis.com',
		'push.services.mozilla.com',
		'push.apple.com',
		'notify.windows.com',
	);

	/** @var callable(string,bool):bool */
	private $allowed;

	/**
	 * @param callable(string,bool):bool|null $allowed Entscheid über den Endpunkt (Adresse, Standardentscheid).
	 */
	public function __construct( ?callable $allowed = null ) {
		$this->allowed = $allowed ?? static fn( string $endpoint, bool $standard ): bool => $standard;
	}

	/**
	 * @param mixed $data Rohdaten aus der Anfrage.
	 * @return array{subscription:Subscription|null,errors:list<array{field:string,message:string}>}
	 */
	public function validate( mixed $data ): array {
		$errors   = array();
		$data     = is_array( $data ) ? $data : array();
		$endpoint = is_string( $data['endpoint'] ?? null ) ? trim( $data['endpoint'] ) : '';
		$keys     = is_array( $data['keys'] ?? null ) ? $data['keys'] : array();

		if ( '' === $endpoint || strlen( $endpoint ) > self::MAX_ENDPOINT_LENGTH || null === self::parts( $endpoint ) ) {
			$errors[] = self::error( 'endpoint', __( 'Die Adresse des Push-Dienstes fehlt oder ist ungültig.', 'ttleague' ) );
		} elseif ( ! ( $this->allowed )( $endpoint, self::default_allowed( $endpoint ) ) ) {
			$errors[] = self::error( 'endpoint', __( 'Dieser Push-Dienst wird nicht unterstützt.', 'ttleague' ) );
		}

		$p256dh = is_string( $keys['p256dh'] ?? null ) ? Base64Url::decode( $keys['p256dh'] ) : null;
		if ( null === $p256dh || null === P256::public_key( $p256dh ) ) {
			$errors[] = self::error( 'keys.p256dh', __( 'Der Schlüssel p256dh ist ungültig.', 'ttleague' ) );
		}
		$auth = is_string( $keys['auth'] ?? null ) ? Base64Url::decode( $keys['auth'] ) : null;
		if ( null === $auth || 16 !== strlen( $auth ) ) {
			$errors[] = self::error( 'keys.auth', __( 'Der Schlüssel auth ist ungültig.', 'ttleague' ) );
		}

		return array(
			'subscription' => array() === $errors ? new Subscription( $endpoint, (string) $p256dh, (string) $auth ) : null,
			'errors'       => $errors,
		);
	}

	/**
	 * Standard: https und Host eines bekannten Push-Dienstes.
	 */
	public static function default_allowed( string $endpoint ): bool {
		$parts = self::parts( $endpoint );
		if ( null === $parts || 'https' !== $parts['scheme'] ) {
			return false;
		}
		foreach ( self::PUSH_HOSTS as $host ) {
			if ( $parts['host'] === $host || str_ends_with( $parts['host'], '.' . $host ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Schema und Host einer absoluten Adresse ohne Benutzerangaben.
	 *
	 * @return array{scheme:string,host:string}|null
	 */
	private static function parts( string $endpoint ): ?array {
		if ( 1 === preg_match( '/[\s\x00-\x1f\x7f]/', $endpoint ) ) {
			return null;
		}
		$parts = parse_url( $endpoint ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- reine Klasse ohne WordPress.
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		return array(
			'scheme' => strtolower( $parts['scheme'] ),
			'host'   => strtolower( rtrim( $parts['host'], '.' ) ),
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
