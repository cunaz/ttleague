<?php
/**
 * Empfänger der Ereignisse des Mannschaftscups (WordPress-Aktionen, in Tests aufgezeichnet).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cup;

interface CupEvents {

	public const ENTRY_REGISTERED = 'entry_registered';
	public const ENTRY_WITHDRAWN  = 'entry_withdrawn';
	public const CONFIGURED       = 'configured';
	public const SEEDED           = 'seeded';
	public const DRAW_PROPOSED    = 'draw_proposed';
	public const DRAW_CHANGED     = 'draw_changed';
	public const DRAW_RELEASED    = 'draw_released';
	public const ADVANCED         = 'advanced';
	public const DEADLINE         = 'deadline';
	public const SCHEDULED        = 'scheduled';
	public const HOME_SWAPPED     = 'home_swapped';

	/**
	 * Etwas am Cup hat sich geändert. Kommt erst nach dem Speichern.
	 *
	 * @param int       $competition_id Konkurrenz.
	 * @param string    $action         Eine der Konstanten dieser Schnittstelle.
	 * @param int       $user_id        Handelndes Konto (0: das System, etwa beim Nachrücken nach einem Resultat).
	 * @param list<int> $tie_ids        Paarungen, über die die Mannschaften eine Mitteilung erhalten:
	 *                                  neue Begegnungen (advanced), angesetzte Begegnungen (scheduled),
	 *                                  getauschtes Heimrecht (home_swapped). Die Auslosung
	 *                                  (draw_released) betrifft alle Paarungen der Konkurrenz.
	 */
	public function changed( int $competition_id, string $action, int $user_id, array $tie_ids = array() ): void;
}
