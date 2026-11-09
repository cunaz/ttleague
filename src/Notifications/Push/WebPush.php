<?php
/**
 * Versand einer Push-Nachricht an ein Abo (RFC 8030 mit RFC 8291 und RFC 8292).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications\Push;

use TTLeague\Infrastructure\Time\Clock;

/**
 * Inhalt als JSON {title, body, url, tag, icon}, verschlüsselt mit aes128gcm, Kopfzeilen TTL,
 * Urgency und Authorization (VAPID). Antwort 201 heisst angenommen; 404 und 410 heissen, dass
 * das Abo nicht mehr gilt.
 */
final class WebPush {

	/** Höchstlänge des JSON; Push-Dienste nehmen höchstens 4096 Byte inklusive Verschlüsselung an. */
	public const MAX_PAYLOAD = 3000;

	public const URGENCIES = array( 'very-low', 'low', 'normal', 'high' );

	public function __construct(
		private readonly Vapid $vapid,
		private readonly Transport $transport,
		private readonly Clock $clock,
		private readonly string $subject,
	) {}

	/**
	 * @param array<string,string> $message title, body, url, tag, icon.
	 * @return int HTTP-Status (0 bei einem Netzwerkfehler).
	 */
	public function send( Subscription $subscription, array $message, int $ttl = 86400, string $urgency = 'normal' ): int {
		$body = Encryption::encrypt( self::payload( $message ), $subscription->p256dh, $subscription->auth );
		if ( null === $body ) {
			throw new \RuntimeException( 'Push-Nachricht konnte nicht verschlüsselt werden.' );
		}
		return $this->transport->post(
			$subscription->endpoint,
			array(
				'Content-Type'     => 'application/octet-stream',
				'Content-Encoding' => 'aes128gcm',
				'TTL'              => (string) max( 0, $ttl ),
				'Urgency'          => in_array( $urgency, self::URGENCIES, true ) ? $urgency : 'normal',
				'Authorization'    => $this->vapid->authorization( $subscription->endpoint, $this->subject, $this->clock->now()->getTimestamp() ),
			),
			$body
		);
	}

	/**
	 * JSON der Nachricht; ein zu langer Text wird gekürzt.
	 *
	 * @param array<string,string> $message Felder.
	 */
	public static function payload( array $message ): string {
		$data = array();
		foreach ( array( 'title', 'body', 'url', 'tag', 'icon' ) as $field ) {
			if ( isset( $message[ $field ] ) && '' !== $message[ $field ] ) {
				$data[ $field ] = (string) $message[ $field ];
			}
		}
		$json = self::json( $data );
		$size = strlen( $json );
		while ( $size > self::MAX_PAYLOAD && isset( $data['body'] ) && mb_strlen( $data['body'] ) > 1 ) {
			$data['body'] = mb_substr( $data['body'], 0, max( 0, mb_strlen( $data['body'] ) - 51 ) ) . '…';
			$json         = self::json( $data );
			$size         = strlen( $json );
		}
		return $json;
	}

	/**
	 * @param array<string,string> $data Daten.
	 */
	private static function json( array $data ): string {
		return (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- reine Klasse ohne WordPress.
	}
}
