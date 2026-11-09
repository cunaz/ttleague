<?php
/**
 * Zeile einer Gruppenrangliste.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Ranking;

final class RlRankRow {

	public int $wins           = 0;
	public int $losses         = 0;
	public int $sets_won       = 0;
	public int $sets_lost      = 0;
	public int $points_for     = 0;
	public int $points_against = 0;
	public bool $points_known  = true;
	public ?int $rank          = null;
	public int $round_points   = 0;
	public bool $shared_rank   = false;

	public function __construct( public readonly int|string $player, public readonly RlPlayerStatus $status ) {}
}
