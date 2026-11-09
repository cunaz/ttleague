<?php
/**
 * Adressen der Website für Benachrichtigungen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

use TTLeague\Accounts\LoginFlow;
use TTLeague\Results\Results;

final class WordPressLinks implements Links {

	public function entry( int $fixture_id ): string {
		return Results::entry_url( $fixture_id );
	}

	public function my_area(): string {
		return LoginFlow::my_area_url();
	}
}
