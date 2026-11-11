<?php
/**
 * Fachlicher Fehler des Saison-Assistenten mit Art und Feldfehlern.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Season;

/**
 * Erwartete Ablehnungen der Dienste: fehlendes Recht, unbekannter Datensatz, Stand erlaubt die
 * Aktion nicht, ungültige Eingabe. Meldungen sind Klartext und werden erst bei der Ausgabe
 * escaped.
 */
final class SeasonException extends \RuntimeException {

	public const FORBIDDEN = 'ttl_forbidden';
	public const NOT_FOUND = 'ttl_not_found';
	public const CONFLICT  = 'ttl_conflict';
	public const INVALID   = 'ttl_invalid';

	/**
	 * @param string                                   $error_code Maschinenlesbarer Code.
	 * @param string                                   $message    Meldung (deutsch).
	 * @param list<array{field:string,message:string}> $errors     Feldfehler.
	 */
	public function __construct(
		public readonly string $error_code,
		string $message,
		public readonly array $errors = array(),
	) {
		parent::__construct( $message );
	}

	public static function forbidden( string $message ): self {
		return new self( self::FORBIDDEN, $message );
	}

	public static function not_found( string $message ): self {
		return new self( self::NOT_FOUND, $message );
	}

	public static function conflict( string $message ): self {
		return new self( self::CONFLICT, $message );
	}

	/**
	 * @param list<array<string,mixed>>              $errors  Feldfehler oder offene Punkte (mit «field» und «message»).
	 * @param string|null                              $message Zusammenfassung; fehlt sie, gilt der einzige Fehler.
	 */
	public static function invalid( array $errors, ?string $message = null ): self {
		$errors = array_values(
			array_map(
				static fn( array $error ): array => array(
					'field'   => (string) ( $error['field'] ?? '' ),
					'message' => (string) ( $error['message'] ?? '' ),
				),
				$errors
			)
		);
		if ( null === $message ) {
			$message = 1 === count( $errors ) ? $errors[0]['message'] : sprintf( 'Die Eingabe enthält %d Fehler.', count( $errors ) );
		}
		return new self( self::INVALID, $message, $errors );
	}

	/**
	 * Ein einzelner Fehler an einem Feld.
	 */
	public static function field( string $field, string $message ): self {
		return new self(
			self::INVALID,
			$message,
			array(
				array(
					'field'   => $field,
					'message' => $message,
				),
			)
		);
	}
}
