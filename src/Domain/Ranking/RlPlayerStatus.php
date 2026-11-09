<?php
/**
 * Status einer Person in einer Ranglistenturnier-Gruppe.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Ranking;

enum RlPlayerStatus: string {
	/** Regulär gespielt. */
	case Played = 'played';
	/** Abgemeldet: Spiele werden nicht gewertet. */
	case Absent = 'absent';
	/** 15 Minuten zu spät: für die Runde disqualifiziert. */
	case Disqualified = 'disqualified';
	/** Forfait für ausstehende Partien: alle Spiele der Runde annulliert. */
	case Forfeit = 'forfeit';
	/** Aufgeboten für eine Gruppe, die noch nicht gespielt hat. */
	case Scheduled = 'scheduled';

	public function is_ranked(): bool {
		return self::Played === $this;
	}
}
