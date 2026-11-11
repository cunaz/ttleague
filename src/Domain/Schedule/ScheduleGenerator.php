<?php
/**
 * Erzeugt den Entwurf eines Spielplans: Vor- und Rückrunde aller Gruppen auf Spielwochen verteilt.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Schedule;

/**
 * Baut auf dem RoundRobinScheduler auf: Paarungen nach dem Kreisverfahren, die Rückrunde
 * spiegelt die Vorrunde mit getauschtem Heimrecht. Hat eine Halbserie genau so viele
 * Spielwochen wie die Gruppe Runden, fällt Runde n in die n-te Spielwoche. Hat sie mehr,
 * verteilt der Generator die Runden gleichmässig über alle Spielwochen und versetzt die
 * Gruppen gegeneinander (weeks_for()): So spielen in der Regel seltener mehrere Mannschaften
 * eines Clubs am selben Abend. Der Termin ist Spielwoche plus Heimspieltag des Heimteams zur Anspielzeit im
 * Heimlokal. Der Generator prüft keine Kollisionen, das macht der ScheduleChecker.
 */
final class ScheduleGenerator {

	public function __construct( private readonly RoundRobinScheduler $scheduler = new RoundRobinScheduler() ) {}

	/**
	 * @param ScheduleInput $input Gruppen und Spielwochen.
	 * @return list<PlannedFixture> Alle Begegnungen, nach Gruppe (Reihenfolge der Eingabe) und Runde.
	 * @throws \InvalidArgumentException Wenn die Spielwochen für die Runden nicht reichen.
	 */
	public function generate( ScheduleInput $input ): array {
		$this->assert_enough_weeks( $input );

		$weekday  = static fn( int|string $team ): int => $input->team( $team )->home_weekday;
		$fixtures = array();
		foreach ( $input->groups as $position => $group ) {
			$rounds  = $this->scheduler->double_round_robin( $group->team_ids() );
			$per_leg = $group->rounds_per_leg();
			$dated   = array_merge(
				$this->scheduler->assign_dates( array_slice( $rounds, 0, $per_leg ), self::weeks_for( $input->first_leg_weeks, $per_leg, $position ), $weekday ),
				$this->scheduler->assign_dates( array_slice( $rounds, $per_leg ), self::weeks_for( $input->second_leg_weeks, $per_leg, $position ), $weekday )
			);
			foreach ( $dated as $index => $round ) {
				foreach ( $round as $pair ) {
					$home       = $input->team( $pair['home'] );
					$fixtures[] = new PlannedFixture(
						$group->id,
						$index + 1,
						$pair['leg'],
						$pair['home'],
						$pair['away'],
						$home->at_start_time( $pair['date'] ),
						$home->venue_id
					);
				}
			}
		}
		return $fixtures;
	}

	/**
	 * Spielwochen einer Gruppe in einer Halbserie: so viele wie Runden, gleichmässig über alle
	 * Spielwochen verteilt. Passen die Runden mehrfach in die Spielwochen, beginnt jede Gruppe
	 * um einen Bruchteil des Abstands später als die vorherige, damit die Gruppen in
	 * verschiedenen Wochen spielen. Mit genau so vielen Spielwochen wie Runden ändert sich nichts.
	 *
	 * @param list<\DateTimeImmutable> $weeks    Spielwochen der Halbserie, aufsteigend.
	 * @param int                      $rounds   Runden der Gruppe pro Halbserie.
	 * @param int                      $position Stelle der Gruppe in der Eingabe (ab 0).
	 * @return list<\DateTimeImmutable> Eine Spielwoche pro Runde, aufsteigend und ohne Wiederholung.
	 */
	public static function weeks_for( array $weeks, int $rounds, int $position ): array {
		$available = count( $weeks );
		if ( $rounds < 1 || $available <= $rounds ) {
			return $weeks;
		}
		// Phasen: wie oft die Runden (aufgerundet) in die Spielwochen passen.
		$phases = intdiv( $available + $rounds - 1, $rounds );
		$phase  = $position % $phases;
		$chosen = array();
		for ( $round = 0; $round < $rounds; $round++ ) {
			$chosen[] = $weeks[ intdiv( ( $round * $phases + $phase ) * $available, $rounds * $phases ) ];
		}
		return $chosen;
	}

	/**
	 * Nennt alle Gruppen und Halbserien mit zu wenigen Spielwochen in einer Meldung, damit die
	 * TK nicht pro Gruppe einzeln nachbessern muss.
	 *
	 * @throws \InvalidArgumentException Wenn Spielwochen fehlen.
	 */
	private function assert_enough_weeks( ScheduleInput $input ): void {
		$legs    = array(
			'Vorrunde'  => count( $input->first_leg_weeks ),
			'Rückrunde' => count( $input->second_leg_weeks ),
		);
		$missing = array();
		foreach ( $input->groups as $group ) {
			$needed = $group->rounds_per_leg();
			foreach ( $legs as $label => $available ) {
				if ( $available < $needed ) {
					$missing[] = sprintf( 'Gruppe %s braucht in der %s %d, vorhanden sind %d', $group->id, $label, $needed, $available );
				}
			}
		}
		if ( array() !== $missing ) {
			throw new \InvalidArgumentException( 'Zu wenige Spielwochen für die Anzahl Runden: ' . implode( '; ', $missing ) . '.' );
		}
	}
}
