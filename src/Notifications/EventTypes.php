<?php
/**
 * Arten von Benachrichtigungen, die Personen einzeln an- und abwählen können.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

final class EventTypes {

	public const MATCH_REMINDER   = 'match_reminder';
	public const RESULT_MISSING   = 'result_missing';
	public const CONFIRM_REQUEST  = 'confirm_request';
	public const CONFIRM_REMINDER = 'confirm_reminder';
	public const RESULT_DISPUTED  = 'result_disputed';
	public const RESULT_DECIDED   = 'result_decided';
	public const TK_DIGEST        = 'tk_digest';

	/**
	 * Ereignistyp => Bezeichnung für die Einstellungen.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			self::MATCH_REMINDER   => __( 'Erinnerung zwei Tage vor einem Spiel', 'ttleague' ),
			self::RESULT_MISSING   => __( 'Resultat noch nicht erfasst', 'ttleague' ),
			self::CONFIRM_REQUEST  => __( 'Resultat zum Bestätigen', 'ttleague' ),
			self::CONFIRM_REMINDER => __( 'Erinnerung an ausstehende Bestätigung', 'ttleague' ),
			self::RESULT_DISPUTED  => __( 'Einsprache gegen ein Resultat', 'ttleague' ),
			self::RESULT_DECIDED   => __( 'Entscheid der TK', 'ttleague' ),
			self::TK_DIGEST        => __( 'Übersicht offener Punkte für die TK', 'ttleague' ),
		);
	}
}
