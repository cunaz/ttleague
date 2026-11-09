<?php
/**
 * Fachlicher Fehler der Resultaterfassung mit HTTP-Status und Feldfehlern.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

final class ResultException extends \RuntimeException {

	public const FORBIDDEN   = 'ttl_forbidden';
	public const NOT_FOUND   = 'ttl_not_found';
	public const CONFLICT    = 'ttl_conflict';
	public const INVALID     = 'ttl_invalid';
	public const UNAVAILABLE = 'ttl_unavailable';

	/**
	 * @param string                                   $error_code Maschinenlesbarer Code.
	 * @param string                                   $message    Meldung (deutsch).
	 * @param int                                      $status     HTTP-Status.
	 * @param list<array{field:string,message:string}> $errors     Feldfehler.
	 */
	public function __construct(
		public readonly string $error_code,
		string $message,
		public readonly int $status,
		public readonly array $errors = array(),
	) {
		parent::__construct( $message );
	}

	public static function forbidden( string $message ): self {
		return new self( self::FORBIDDEN, $message, 403 );
	}

	public static function not_found( string $message ): self {
		return new self( self::NOT_FOUND, $message, 404 );
	}

	public static function conflict( string $message ): self {
		return new self( self::CONFLICT, $message, 409 );
	}

	/**
	 * Vorübergehend nicht möglich (503), z. B. weil die Fotoablage nicht geschützt ist.
	 */
	public static function unavailable( string $message ): self {
		return new self( self::UNAVAILABLE, $message, 503 );
	}

	/**
	 * @param list<array{field:string,message:string}> $errors Feldfehler.
	 */
	public static function invalid( array $errors, ?string $message = null ): self {
		$message ??= 1 === count( $errors ) ? $errors[0]['message'] : sprintf( 'Die Eingabe enthält %d Fehler.', count( $errors ) );
		return new self( self::INVALID, $message, 422, $errors );
	}
}
