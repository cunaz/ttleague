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

	// Typen der Module des Saisonbetriebs (docs/saisonbetrieb.md). Das Modul meldet Typ und
	// Bezeichnung über den Filter «ttleague_notification_types» an; ohne Modul erscheint der Typ nicht.
	public const REGISTRATION_REMINDER = 'registration_reminder';
	public const REGISTRATION_DECIDED  = 'registration_decided';
	public const SEASON_PUBLISHED      = 'season_published';
	public const POSTPONEMENT          = 'postponement';
	public const POSTPONEMENT_TK       = 'postponement_tk';
	public const RL_CALLUP             = 'rl_callup';
	public const RL_REMINDER           = 'rl_reminder';
	public const CUP_ROUND             = 'cup_round';
	public const CUP_DEADLINE          = 'cup_deadline';
	public const EVENT_REGISTRATION    = 'event_registration';

	/**
	 * Ereignistyp => Bezeichnung für die Einstellungen: die festen Typen der Erfassung, danach
	 * die der Module. Ein Modul kann keinen festen Typ überschreiben; der Schlüssel passt in
	 * notification_pref.event_type (höchstens 32 Zeichen, Kleinbuchstaben, Ziffern, Unterstrich).
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		$labels = self::core();
		// Ohne WordPress (Tests der Dienste) gibt es nur die festen Typen.
		/**
		 * Ereignistypen der Module.
		 *
		 * @param array<string,string> $types Typ => Bezeichnung.
		 */
		$extra = function_exists( 'apply_filters' ) ? apply_filters( 'ttleague_notification_types', array() ) : array();
		foreach ( is_array( $extra ) ? $extra : array() as $type => $label ) {
			if ( is_string( $type ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,31}$/D', $type ) && is_string( $label ) && '' !== $label && ! isset( $labels[ $type ] ) ) {
				$labels[ $type ] = $label;
			}
		}
		return $labels;
	}

	/**
	 * @return array<string,string>
	 */
	private static function core(): array {
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
