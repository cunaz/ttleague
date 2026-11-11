<?php
/**
 * Adressen der Website für die Mitteilung zur Freigabe der Saison.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Season;

use TTLeague\Accounts\LoginFlow;
use TTLeague\Calendar\FeedUrls;
use TTLeague\Frontend\Frontend;

final class WordPressSeasonLinks implements SeasonLinks {

	public function schedule( string $season_slug ): string {
		return Frontend::urls()->championship( $season_slug );
	}

	public function fixture( int $fixture_id ): string {
		return Frontend::urls()->fixture( $fixture_id );
	}

	public function team_calendar( string $season_slug, string $team_slug ): string {
		return ( new FeedUrls( Frontend::urls()->home() ) )->team( $season_slug, $team_slug );
	}

	public function club_calendar( string $club_slug ): string {
		return ( new FeedUrls( Frontend::urls()->home() ) )->club( $club_slug );
	}

	public function my_area(): string {
		return LoginFlow::my_area_url();
	}
}
