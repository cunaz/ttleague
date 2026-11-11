<?php
/**
 * Gleicht zwei Spielpläne ab, z. B. einen Entwurf mit dem tatsächlich gespielten Plan einer Saison.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Schedule;

final class ScheduleComparator {

	/**
	 * Ordnet Begegnungen über Heim, Gast und Halbserie zu und zählt, wie viele am selben Tag,
	 * in derselben Woche und am selben Wochentag liegen. Uhrzeit und Lokal zählen nicht. Beide
	 * Pläne müssen ihre Termine in derselben Zeitzone führen (Ortszeit).
	 *
	 * @param list<PlannedFixture> $plan      Zu beurteilender Plan.
	 * @param list<PlannedFixture> $reference Vergleichsplan.
	 * @throws \InvalidArgumentException Wenn eine Paarung in einem Plan mehrfach vorkommt.
	 */
	public function compare( array $plan, array $reference ): ScheduleComparison {
		$theirs       = self::by_pairing( $reference, 'Vergleichsplan' );
		$matched      = 0;
		$same_date    = 0;
		$same_week    = 0;
		$same_weekday = 0;
		$only_in_plan = array();
		foreach ( self::by_pairing( $plan, 'Plan' ) as $key => $fixture ) {
			$other = $theirs[ $key ] ?? null;
			if ( null === $other ) {
				$only_in_plan[] = $fixture;
				continue;
			}
			unset( $theirs[ $key ] );
			++$matched;
			$same_date    += $fixture->day() === $other->day() ? 1 : 0;
			$same_week    += $fixture->week() === $other->week() ? 1 : 0;
			$same_weekday += $fixture->starts_at->format( 'N' ) === $other->starts_at->format( 'N' ) ? 1 : 0;
		}
		return new ScheduleComparison( $matched, $same_date, $same_week, $same_weekday, $only_in_plan, array_values( $theirs ) );
	}

	/**
	 * @param list<PlannedFixture> $fixtures Plan.
	 * @param string               $label    Bezeichnung des Plans für die Fehlermeldung.
	 * @return array<string,PlannedFixture> Paarungsschlüssel => Begegnung, in der Reihenfolge des Plans.
	 */
	private static function by_pairing( array $fixtures, string $label ): array {
		$result = array();
		foreach ( $fixtures as $fixture ) {
			if ( ! $fixture instanceof PlannedFixture ) {
				throw new \InvalidArgumentException( 'Begegnungen im ' . $label . ' müssen PlannedFixture sein.' );
			}
			$key = $fixture->pairing_key();
			if ( isset( $result[ $key ] ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Paarung mehrfach im %s: %s gegen %s, Halbserie %d.', $label, $fixture->home_team, $fixture->away_team, $fixture->leg )
				);
			}
			$result[ $key ] = $fixture;
		}
		return $result;
	}
}
