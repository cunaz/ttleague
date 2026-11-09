<?php
/**
 * Ergebnis einer Aktion auf Konten (E-Mail ändern, einladen) für die Rückmeldung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

/**
 * Erwartete Ablehnungen (fehlende Rechte, ungültige Adresse) sind keine Ausnahmen, sondern ein
 * Ergebnis mit Art und Meldung. Meldungen sind Klartext und werden erst bei der Ausgabe escaped.
 */
final class Outcome {

	public const SUCCESS = 'success';
	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	/**
	 * @param string       $type    Art (success, info, warning, error).
	 * @param string       $message Meldung.
	 * @param list<string> $details Weitere Zeilen, z. B. Namen bei Sammelaktionen.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $message,
		public readonly array $details = array(),
	) {}

	/**
	 * @param list<string> $details Weitere Zeilen.
	 */
	public static function success( string $message, array $details = array() ): self {
		return new self( self::SUCCESS, $message, $details );
	}

	/**
	 * @param list<string> $details Weitere Zeilen.
	 */
	public static function info( string $message, array $details = array() ): self {
		return new self( self::INFO, $message, $details );
	}

	/**
	 * @param list<string> $details Weitere Zeilen.
	 */
	public static function warning( string $message, array $details = array() ): self {
		return new self( self::WARNING, $message, $details );
	}

	/**
	 * @param list<string> $details Weitere Zeilen.
	 */
	public static function error( string $message, array $details = array() ): self {
		return new self( self::ERROR, $message, $details );
	}

	public function ok(): bool {
		return self::SUCCESS === $this->type;
	}
}
