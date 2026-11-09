<?php
/**
 * Kontext für Abschnitte im persönlichen Bereich (Aktion ttleague_my_area_sections).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

final class MyAreaContext {

	/**
	 * @param int                                                                   $user_id   WordPress-Benutzer.
	 * @param int                                                                   $person_id Person.
	 * @param array<string,string|null>                                             $person    Personendaten.
	 * @param int|null                                                              $season_id Aktive Saison.
	 * @param array{captain_teams:list<int>,tk_chef_clubs:list<int>,cup_entries:list<int>} $roles Funktionen in der aktiven Saison.
	 * @param bool                                                                  $is_tk     Mitglied der TK.
	 */
	public function __construct(
		public readonly int $user_id,
		public readonly int $person_id,
		public readonly array $person,
		public readonly ?int $season_id,
		public readonly array $roles,
		public readonly bool $is_tk,
	) {}
}
