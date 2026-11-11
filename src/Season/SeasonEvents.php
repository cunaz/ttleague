<?php
/**
 * Ereignisse des Saison-Assistenten nach dem Speichern.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Season;

/**
 * Die Dienste kennen WordPress nicht. Sie melden nach jedem gespeicherten Schreibvorgang, was
 * sich geändert hat. In WordPress erhöht WordPressSeasonEvents die Datenversion und plant nach
 * der Freigabe die Mitteilungen an Captains und TK-Chefs ein.
 */
interface SeasonEvents {

	/**
	 * Nach jedem gespeicherten Schreibvorgang an einer Saison (Regeln, Fenster, Gruppen, Spielplan).
	 */
	public function changed( int $season_id ): void;

	/**
	 * Nach der Freigabe: Die Saison ist jetzt aktiv.
	 *
	 * @param int      $season_id   Freigegebene Saison.
	 * @param int|null $archived_id Bisher aktive, jetzt archivierte Saison.
	 * @param int      $user_id     Konto, das freigegeben hat.
	 */
	public function published( int $season_id, ?int $archived_id, int $user_id ): void;

	/**
	 * Nach dem Rückzug einer Mannschaft: Resultate und Tabellen der Saison haben sich geändert.
	 */
	public function team_withdrawn( int $season_id, int $team_id ): void;

	/**
	 * Nach dem Abschluss: Die Saison ist archiviert.
	 */
	public function closed( int $season_id ): void;
}
