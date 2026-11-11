<?php
/**
 * Empfänger der Ereignisse des Ranglistenturniers (WordPress-Schicht, in Tests aufgezeichnet).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

/**
 * Die Dienste rufen die Ereignisse erst nach dem Speichern auf.
 */
interface Events {

	/**
	 * Daten geändert: Cache-Version erhöhen, bei geänderten Partien die Wertungszahl neu rechnen.
	 *
	 * @param string $area  Bereich: rl_competition, rl_participant, rl_round oder rl_group.
	 * @param int    $id    ID im Bereich.
	 * @param bool   $games Partien wurden gespeichert oder gelöscht.
	 */
	public function changed( string $area, int $id, bool $games = false ): void;

	/**
	 * Aufgebot verschicken.
	 *
	 * @param int       $round_id  Runde.
	 * @param list<int> $group_ids Gruppen, deren Mitglieder ein Aufgebot erhalten.
	 * @param int       $user_id   Konto, das freigegeben oder geändert hat.
	 * @param bool      $changed   Änderung nach der Freigabe.
	 */
	public function callup( int $round_id, array $group_ids, int $user_id, bool $changed ): void;
}
