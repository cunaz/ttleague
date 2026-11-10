<?php
/**
 * Gruppe der Meisterschaft als Eingabe für den Spielplan.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Schedule;

final class ScheduleGroup {

	/** @var list<ScheduleTeam> */
	public readonly array $teams;

	/**
	 * Die Reihenfolge der Teams bestimmt die Paarungen (Kreisverfahren): Gleiche Reihenfolge
	 * ergibt denselben Plan.
	 *
	 * @param int|string         $id    Gruppen-ID.
	 * @param list<ScheduleTeam> $teams Teams der Gruppe, mindestens zwei.
	 */
	public function __construct( public readonly int|string $id, array $teams ) {
		$seen = array();
		foreach ( $teams as $team ) {
			if ( ! $team instanceof ScheduleTeam ) {
				throw new \InvalidArgumentException( 'Teams einer Gruppe müssen ScheduleTeam sein.' );
			}
			if ( isset( $seen[ (string) $team->id ] ) ) {
				throw new \InvalidArgumentException( 'Team mehrfach in der Gruppe ' . $id . ': ' . $team->id );
			}
			$seen[ (string) $team->id ] = true;
		}
		if ( count( $teams ) < 2 ) {
			throw new \InvalidArgumentException( 'Die Gruppe ' . $id . ' braucht mindestens zwei Teams.' );
		}
		$this->teams = array_values( $teams );
	}

	/**
	 * @return list<int|string>
	 */
	public function team_ids(): array {
		return array_map( static fn( ScheduleTeam $team ) => $team->id, $this->teams );
	}

	/**
	 * Runden pro Halbserie: bei ungerader Teamzahl hat pro Runde ein Team spielfrei.
	 */
	public function rounds_per_leg(): int {
		$count = count( $this->teams );
		return 0 === $count % 2 ? $count - 1 : $count;
	}
}
