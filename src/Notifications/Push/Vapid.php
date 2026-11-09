<?php
/**
 * VAPID (RFC 8292): Identifikation des Servers gegenüber dem Push-Dienst mit einem JWT (ES256).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications\Push;

/**
 * Hält das Schlüsselpaar des Servers und signiert pro Push-Dienst (aud = Herkunft des Endpunkts)
 * ein JWT mit Ablauf (exp, höchstens 24 Stunden) und Kontakt (sub, mailto: oder https:).
 * Header: Authorization: vapid t=<JWT>, k=<öffentlicher Schlüssel>.
 */
final class Vapid {

	/** Gültigkeit des JWT in Sekunden (RFC 8292: höchstens 24 Stunden). */
	public const TTL = 43200;

	private function __construct(
		private readonly string $public_point,
		private readonly \OpenSSLAsymmetricKey $private_key,
	) {}

	/**
	 * Aus Rohschlüsseln; ohne öffentlichen Schlüssel wird er berechnet. Passen die beiden nicht
	 * zusammen, ist das Ergebnis null.
	 */
	public static function from_raw( string $private_raw, ?string $public_raw = null ): ?self {
		if ( ! P256::supported() ) {
			return null;
		}
		$key = P256::private_key( $private_raw );
		if ( null === $key ) {
			return null;
		}
		$derived = P256::point( $key );
		if ( null === $derived || ( null !== $public_raw && ! hash_equals( $derived, $public_raw ) ) ) {
			return null;
		}
		return new self( $derived, $key );
	}

	/**
	 * Aus Base64url-Text (Format von Konstanten und Option).
	 */
	public static function from_base64url( string $private_key, ?string $public_key = null ): ?self {
		$public_key  = null === $public_key ? '' : trim( $public_key );
		$private_raw = Base64Url::decode( $private_key );
		$public_raw  = '' === $public_key ? null : Base64Url::decode( $public_key );
		if ( null === $private_raw || ( '' !== $public_key && null === $public_raw ) ) {
			return null;
		}
		return self::from_raw( $private_raw, $public_raw );
	}

	/**
	 * Neues Schlüsselpaar als Base64url.
	 *
	 * @return array{public:string,private:string}|null
	 */
	public static function generate(): ?array {
		if ( ! P256::supported() ) {
			return null;
		}
		$pair = P256::generate();
		if ( null === $pair ) {
			return null;
		}
		return array(
			'public'  => Base64Url::encode( $pair['public'] ),
			'private' => Base64Url::encode( $pair['private'] ),
		);
	}

	/**
	 * Öffentlicher Schlüssel (applicationServerKey) als Base64url.
	 */
	public function public_key(): string {
		return Base64Url::encode( $this->public_point );
	}

	/**
	 * Wert des Authorization-Headers für einen Endpunkt.
	 */
	public function authorization( string $endpoint, string $subject, int $now ): string {
		$audience = self::audience( $endpoint );
		if ( null === $audience ) {
			throw new \InvalidArgumentException( 'Ungültiger Endpunkt.' );
		}
		return 'vapid t=' . $this->jwt( $audience, $subject, $now + self::TTL ) . ', k=' . $this->public_key();
	}

	/**
	 * JWT mit ES256 (Signatur r||s, Base64url).
	 */
	public function jwt( string $audience, string $subject, int $expires ): string {
		$header  = Base64Url::encode(
			self::json(
				array(
					'typ' => 'JWT',
					'alg' => 'ES256',
				)
			)
		);
		$claims  = Base64Url::encode(
			self::json(
				array(
					'aud' => $audience,
					'exp' => $expires,
					'sub' => $subject,
				)
			)
		);
		$input   = $header . '.' . $claims;
		$der     = '';
		$success = openssl_sign( $input, $der, $this->private_key, OPENSSL_ALGO_SHA256 );
		$raw     = $success ? P256::der_to_raw( (string) $der ) : null;
		if ( null === $raw ) {
			throw new \RuntimeException( 'JWT konnte nicht signiert werden.' );
		}
		return $input . '.' . Base64Url::encode( $raw );
	}

	/**
	 * Herkunft eines Endpunkts (Schema, Host, Port) als aud.
	 */
	public static function audience( string $endpoint ): ?string {
		$parts = parse_url( $endpoint ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- reine Klasse ohne WordPress.
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * @param array<string,mixed> $data Daten.
	 */
	private static function json( array $data ): string {
		return (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- reine Klasse ohne WordPress.
	}
}
