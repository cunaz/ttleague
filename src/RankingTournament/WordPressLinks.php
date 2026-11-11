<?php
/**
 * Adressen der Website für die Benachrichtigungen des Ranglistenturniers.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Accounts\LoginFlow;

final class WordPressLinks implements Links {

	public function my_area(): string {
		return LoginFlow::my_area_url();
	}
}
