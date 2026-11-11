<?php
/**
 * Eingaben für den Spielplan einer Saison.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Schedule;

/**
 * Gruppen, Spielwochen, Hallensperren und Tische je Lokal. Vor- und Rückrunde haben getrennte
 * Listen von Spielwochen: So beginnt die Rückrunde für alle Gruppen in derselben Woche, auch
 * wenn eine Gruppe mit sechs Teams weniger Runden hat als eine mit sieben. Jede Gruppe belegt
 * die ersten Wochen ihrer Halbserie; die übrigen bleiben Reserve für Verlegungen.
 */
final class ScheduleInput {

	/** @var list<ScheduleGroup> */
	public readonly array $groups;

	/** @var list<\DateTimeImmutable> */
	public readonly array $first_leg_weeks;

	/** @var list<\DateTimeImmutable> */
	public readonly array $second_leg_weeks;

	/** @var list<VenueClosure> */
	public readonly array $closures;

	/** @var array<int|string,int> Lokal-ID => Tische; Lokale mit unbekannter Tischzahl fehlen. */
	public readonly array $venue_tables;

	/** @var array<int|string,ScheduleTeam> */
	private readonly array $teams;

	/**
	 * @param list<ScheduleGroup>       $groups           Gruppen mit ihren Teams.
	 * @param list<\DateTimeImmutable>  $first_leg_weeks  Montage der Spielwochen der Vorrunde, aufsteigend.
	 * @param list<\DateTimeImmutable>  $second_leg_weeks Montage der Spielwochen der Rückrunde, aufsteigend, alle nach der Vorrunde.
	 * @param list<VenueClosure>        $closures         Hallensperren.
	 * @param array<int|string,int|null> $venue_tables    Lokal-ID => Anzahl Tische; null oder kein Eintrag: unbekannt.
	 */
	public function __construct(
		array $groups,
		array $first_leg_weeks,
		array $second_leg_weeks,
		array $closures = array(),
		array $venue_tables = array(),
	) {
		$teams     = array();
		$group_ids = array();
		foreach ( $groups as $group ) {
			if ( ! $group instanceof ScheduleGroup ) {
				throw new \InvalidArgumentException( 'Gruppen müssen ScheduleGroup sein.' );
			}
			if ( isset( $group_ids[ (string) $group->id ] ) ) {
				throw new \InvalidArgumentException( 'Gruppe mehrfach genannt: ' . $group->id );
			}
			$group_ids[ (string) $group->id ] = true;
			foreach ( $group->teams as $team ) {
				if ( isset( $teams[ (string) $team->id ] ) ) {
					throw new \InvalidArgumentException( 'Team in mehreren Gruppen: ' . $team->id );
				}
				$teams[ (string) $team->id ] = $team;
			}
		}

		$first  = self::checked_weeks( $first_leg_weeks, 'Vorrunde' );
		$second = self::checked_weeks( $second_leg_weeks, 'Rückrunde' );
		if ( array() !== $first && array() !== $second && $second[0]->format( 'Y-m-d' ) <= end( $first )->format( 'Y-m-d' ) ) {
			throw new \InvalidArgumentException( 'Die Rückrunde muss nach der letzten Spielwoche der Vorrunde beginnen.' );
		}

		foreach ( $closures as $closure ) {
			if ( ! $closure instanceof VenueClosure ) {
				throw new \InvalidArgumentException( 'Hallensperren müssen VenueClosure sein.' );
			}
		}

		$tables = array();
		foreach ( $venue_tables as $venue_id => $count ) {
			if ( null === $count ) {
				continue;
			}
			if ( ! is_int( $count ) || $count < 1 ) {
				throw new \InvalidArgumentException( 'Tische des Lokals ' . $venue_id . ': ganze Zahl ab 1 oder null für unbekannt.' );
			}
			$tables[ $venue_id ] = $count;
		}

		$this->groups           = array_values( $groups );
		$this->first_leg_weeks  = $first;
		$this->second_leg_weeks = $second;
		$this->closures         = array_values( $closures );
		$this->venue_tables     = $tables;
		$this->teams            = $teams;
	}

	/**
	 * Team zu einer ID, null wenn es in keiner Gruppe steht.
	 */
	public function team( int|string $id ): ?ScheduleTeam {
		return $this->teams[ (string) $id ] ?? null;
	}

	/**
	 * Anzahl Tische eines Lokals, null wenn unbekannt.
	 */
	public function tables( int|string $venue_id ): ?int {
		return $this->venue_tables[ (string) $venue_id ] ?? null;
	}

	/**
	 * Erste Sperre (in der Reihenfolge der Eingabe), die das Lokal am Tag des Termins betrifft.
	 */
	public function closure_on( int|string $venue_id, \DateTimeInterface $date ): ?VenueClosure {
		foreach ( $this->closures as $closure ) {
			if ( (string) $closure->venue_id === (string) $venue_id && $closure->covers( $date ) ) {
				return $closure;
			}
		}
		return null;
	}

	/**
	 * Spielwochen einer Halbserie.
	 *
	 * @param int $leg 1 Vorrunde, 2 Rückrunde.
	 * @return list<\DateTimeImmutable>
	 */
	public function weeks( int $leg ): array {
		return PlannedFixture::LEG_FIRST === $leg ? $this->first_leg_weeks : $this->second_leg_weeks;
	}

	/**
	 * @param array<mixed> $weeks Montage.
	 * @param string       $label Halbserie für die Fehlermeldung.
	 * @return list<\DateTimeImmutable>
	 */
	private static function checked_weeks( array $weeks, string $label ): array {
		$previous = null;
		foreach ( $weeks as $week ) {
			if ( ! $week instanceof \DateTimeImmutable ) {
				throw new \InvalidArgumentException( 'Spielwochen der ' . $label . ' müssen DateTimeImmutable sein.' );
			}
			$day = $week->format( 'Y-m-d' );
			if ( '1' !== $week->format( 'N' ) ) {
				throw new \InvalidArgumentException( 'Spielwoche der ' . $label . ' beginnt nicht an einem Montag: ' . $day );
			}
			if ( null !== $previous && $day <= $previous ) {
				throw new \InvalidArgumentException( 'Spielwochen der ' . $label . ' müssen aufsteigend und ohne Wiederholung sein: ' . $day . ' folgt auf ' . $previous );
			}
			$previous = $day;
		}
		return array_values( $weeks );
	}
}
