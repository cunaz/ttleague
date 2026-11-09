<?php
/**
 * Adressen für Tests.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Notifications;

use TTLeague\Notifications\Links;

final class FakeLinks implements Links {

	public function entry( int $fixture_id ): string {
		return 'https://liga.example.org/erfassen/?begegnung=' . $fixture_id;
	}

	public function my_area(): string {
		return 'https://liga.example.org/mein-bereich/';
	}
}
