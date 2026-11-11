<?php
/**
 * Fachlicher Fehler bei Anlässen und Anmeldungen, mit HTTP-Status und Feldfehlern.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

/**
 * Die Meldung ist Klartext für die Rückmeldung an die Person und wird erst bei der Ausgabe escaped.
 */
final class EventException extends \RuntimeException {

	public const FORBIDDEN = 'ttl_forbidden';
	public const NOT_FOUND = 'ttl_not_found';
	public const CONFLICT  = 'ttl_conflict';
	public const INVALID   = 'ttl_invalid';

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
	 * @param list<array{field:string,message:string}> $errors Feldfehler.
	 */
	public static function invalid( array $errors, string $message ): self {
		return new self( self::INVALID, $message, 422, $errors );
	}
}
