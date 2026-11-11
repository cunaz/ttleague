<?php
/**
 * Ereignisse des Saison-Assistenten im Test: merkt sich, was gemeldet wurde.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Season;

use TTLeague\Season\SeasonEvents;

final class RecordingSeasonEvents implements SeasonEvents {

	/** @var list<int> */
	public array $changed = array();

	/** @var list<array{0:int,1:int|null,2:int}> Saison, archivierte Saison, Konto. */
	public array $published = array();

	/** @var list<array{0:int,1:int}> Saison und Mannschaft. */
	public array $withdrawn = array();

	/** @var list<int> */
	public array $closed = array();

	public function changed( int $season_id ): void {
		$this->changed[] = $season_id;
	}

	public function published( int $season_id, ?int $archived_id, int $user_id ): void {
		$this->published[] = array( $season_id, $archived_id, $user_id );
	}

	public function team_withdrawn( int $season_id, int $team_id ): void {
		$this->withdrawn[] = array( $season_id, $team_id );
	}

	public function closed( int $season_id ): void {
		$this->closed[] = $season_id;
	}
}
