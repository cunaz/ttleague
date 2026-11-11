<?php
/**
 * Fachliche Ablehnung in der Klassierung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Classification;

/**
 * Die Meldung ist Klartext für die Rückmeldung im Backend und wird erst bei der Ausgabe escaped.
 */
final class ClassificationException extends \RuntimeException {

	/** Kein Recht (nur die TK). */
	public const FORBIDDEN = 'forbidden';

	/** Saison oder Zeile gibt es nicht. */
	public const NOT_FOUND = 'not_found';

	/** Der Stand erlaubt die Aktion nicht (abgeschlossen, offene Zeilen, inzwischen geändert). */
	public const CONFLICT = 'conflict';

	/** Eingabe ungültig (Bemerkung fehlt, Wert ausserhalb der Skala). */
	public const INVALID = 'invalid';

	/** Vorübergehend nicht möglich (die Neuberechnung der Wertungszahl ist gescheitert); die Ursache steht in getPrevious(). */
	public const UNAVAILABLE = 'unavailable';

	public function __construct( public readonly string $reason, string $message, ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
	}
}
