<?php
/**
 * Einmalige Rückmeldung nach einer Formularaktion (Umleitung, dann Anzeige).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure;

/**
 * Speichert pro Bereich und Konto eine Meldung für die nächste Seitenansicht (Transient,
 * fünf Minuten). So stehen keine Texte in der Adresse, und Neuladen zeigt sie nicht erneut.
 */
final class Flash {

	private const TTL = 300;

	/**
	 * @param string       $scope   Bereich, z. B. «tk» (Backend) oder «club» (Mein Bereich).
	 * @param string       $type    success, info, warning oder error.
	 * @param list<string> $details Weitere Zeilen.
	 */
	public static function set( string $scope, int $user_id, string $type, string $message, array $details = array() ): void {
		set_transient(
			self::key( $scope, $user_id ),
			array(
				'type'    => $type,
				'message' => $message,
				'details' => $details,
			),
			self::TTL
		);
	}

	/**
	 * Meldung lesen und entfernen.
	 *
	 * @return array{type:string,message:string,details:list<string>}|null
	 */
	public static function take( string $scope, int $user_id ): ?array {
		$key   = self::key( $scope, $user_id );
		$value = get_transient( $key );
		if ( ! is_array( $value ) || ! isset( $value['message'] ) ) {
			return null;
		}
		delete_transient( $key );
		return array(
			'type'    => (string) ( $value['type'] ?? 'info' ),
			'message' => (string) $value['message'],
			'details' => array_values( array_map( 'strval', (array) ( $value['details'] ?? array() ) ) ),
		);
	}

	private static function key( string $scope, int $user_id ): string {
		return 'ttl_flash_' . $scope . '_' . $user_id;
	}
}
