<?php
/**
 * Fachliche Fehler der Dienste des Ranglistenturniers.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Results\ResultException;

/**
 * Die Dienste melden Fehler mit der Ausnahme der Erfassung (HTTP-Status, Code, Feldfehler), damit
 * REST und Formulare sie gleich behandeln. Die Meldungen sind Klartext: Die REST-API liefert sie
 * als JSON, Formulare escapen sie bei der Ausgabe (Flash). Deshalb werden sie hier nicht escaped.
 */
final class Fail {

	/**
	 * 403: keine Berechtigung.
	 *
	 * @throws ResultException Immer.
	 */
	public static function forbidden( string $message ): never {
		throw ResultException::forbidden( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Klartext, siehe Klassenkommentar.
	}

	/**
	 * 404: gibt es nicht.
	 *
	 * @throws ResultException Immer.
	 */
	public static function not_found( string $message ): never {
		throw ResultException::not_found( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Klartext, siehe Klassenkommentar.
	}

	/**
	 * 409: Der Stand erlaubt die Aktion nicht.
	 *
	 * @throws ResultException Immer.
	 */
	public static function conflict( string $message ): never {
		throw ResultException::conflict( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Klartext, siehe Klassenkommentar.
	}

	/**
	 * 422: ungültige Eingabe mit einem Feldfehler.
	 *
	 * @throws ResultException Immer.
	 */
	public static function invalid( string $field, string $message ): never {
		self::invalid_fields( array( \TTLeague\Results\ResultInput::error( $field, $message ) ) );
	}

	/**
	 * 422: ungültige Eingabe mit mehreren Feldfehlern.
	 *
	 * @param list<array{field:string,message:string}> $errors Feldfehler.
	 * @throws ResultException Immer.
	 */
	public static function invalid_fields( array $errors ): never {
		throw ResultException::invalid( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Klartext, siehe Klassenkommentar.
	}
}
