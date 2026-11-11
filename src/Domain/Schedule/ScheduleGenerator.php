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
 * spiegelt die Vorrunde mit getauschtem Heimrecht. Runde n jeder Halbserie fällt in deren
 * n-te Spielwoche, der Termin ist Spielwoche plus Heimspieltag des Heimteams zur Anspielzeit
 * im Heimlokal. Der Generator prüft keine Kollisionen, das macht der ScheduleChecker.
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
		foreach ( $input->groups as $group ) {
			$rounds  = $this->scheduler->double_round_robin( $group->team_ids() );
			$per_leg = $group->rounds_per_leg();
			$dated   = array_merge(
				$this->scheduler->assign_dates( array_slice( $rounds, 0, $per_leg ), $input->first_leg_weeks, $weekday ),
				$this->scheduler->assign_dates( array_slice( $rounds, $per_leg ), $input->second_leg_weeks, $weekday )
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
