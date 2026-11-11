<?php
/**
 * Adressen der Mitteilung zur Freigabe für Tests.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Season;

use TTLeague\Season\SeasonLinks;

final class FakeSeasonLinks implements SeasonLinks {

	public function schedule( string $season_slug ): string {
		return 'https://liga.example.org/meisterschaft/' . $season_slug . '/';
	}

	public function fixture( int $fixture_id ): string {
		return 'https://liga.example.org/begegnung/' . $fixture_id . '/';
	}

	public function team_calendar( string $season_slug, string $team_slug ): string {
		return 'https://liga.example.org/kalender/team/' . $season_slug . '/' . $team_slug . '.ics';
	}

	public function club_calendar( string $club_slug ): string {
		return 'https://liga.example.org/kalender/club/' . $club_slug . '.ics';
	}

	public function my_area(): string {
		return 'https://liga.example.org/mein-bereich/';
	}
}
