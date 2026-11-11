<?php
/**
 * Zeichnet die Ereignisse des Ranglistenturniers auf.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\RankingTournament;

use TTLeague\RankingTournament\Events;

final class RecordingEvents implements Events {

	/** @var list<array{0:string,1:int,2:bool}> */
	public array $changed = array();

	/** @var list<array{round:int,groups:list<int>,user:int,changed:bool}> */
	public array $callups = array();

	public function changed( string $area, int $id, bool $games = false ): void {
		$this->changed[] = array( $area, $id, $games );
	}

	public function callup( int $round_id, array $group_ids, int $user_id, bool $changed ): void {
		sort( $group_ids );
		$this->callups[] = array(
			'round'   => $round_id,
			'groups'  => $group_ids,
			'user'    => $user_id,
			'changed' => $changed,
		);
	}
}
