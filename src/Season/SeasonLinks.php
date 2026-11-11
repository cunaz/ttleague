<?php
/**
 * Adressen in der Mitteilung zur Freigabe der Saison (in Tests ohne WordPress ersetzbar).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Season;

interface SeasonLinks {

	/**
	 * Übersicht der Meisterschaft einer Saison.
	 */
	public function schedule( string $season_slug ): string;

	/**
	 * Öffentliche Seite einer Begegnung.
	 */
	public function fixture( int $fixture_id ): string;

	/**
	 * Kalender-Abo einer Mannschaft.
	 */
	public function team_calendar( string $season_slug, string $team_slug ): string;

	/**
	 * Kalender-Abo eines Clubs.
	 */
	public function club_calendar( string $club_slug ): string;

	/**
	 * «Mein Bereich» mit den Einstellungen.
	 */
	public function my_area(): string;
}
