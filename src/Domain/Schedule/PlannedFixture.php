<?php
/**
 * Datierte Begegnung eines Spielplans (Entwurf, von Hand geänderter oder bestehender Plan).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Schedule;

final class PlannedFixture {

	public const LEG_FIRST  = 1;
	public const LEG_SECOND = 2;

	/**
	 * @param int|string         $group_id  Gruppe.
	 * @param int|null           $round     Runde, über beide Halbserien fortlaufend ab 1; null wenn unbekannt (Altdaten).
	 * @param int                $leg       1 Hinspiel (Vorrunde), 2 Rückspiel (Rückrunde).
	 * @param int|string         $home_team Team-ID Heim.
	 * @param int|string         $away_team Team-ID Gast.
	 * @param \DateTimeImmutable $starts_at Datum mit Anspielzeit, Ortszeit.
	 * @param int|string         $venue_id  Lokal.
	 */
	public function __construct(
		public readonly int|string $group_id,
		public readonly ?int $round,
		public readonly int $leg,
		public readonly int|string $home_team,
		public readonly int|string $away_team,
		public readonly \DateTimeImmutable $starts_at,
		public readonly int|string $venue_id,
	) {
		if ( self::LEG_FIRST !== $leg && self::LEG_SECOND !== $leg ) {
			throw new \InvalidArgumentException( 'Halbserie muss 1 (Hinspiel) oder 2 (Rückspiel) sein.' );
		}
		if ( null !== $round && $round < 1 ) {
			throw new \InvalidArgumentException( 'Runde muss mindestens 1 sein.' );
		}
		if ( (string) $home_team === (string) $away_team ) {
			throw new \InvalidArgumentException( 'Heim und Gast dürfen nicht dasselbe Team sein.' );
		}
	}

	/**
	 * Kalendertag («Abend») der Begegnung als Y-m-d.
	 */
	public function day(): string {
		return $this->starts_at->format( 'Y-m-d' );
	}

	/**
	 * Montag der Woche der Begegnung als Y-m-d, auch über den Jahreswechsel.
	 */
	public function week(): string {
		return $this->starts_at->modify( '-' . ( (int) $this->starts_at->format( 'N' ) - 1 ) . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * Schlüssel der Paarung aus Halbserie, Heim und Gast: ordnet dieselbe Begegnung in zwei
	 * Plänen einander zu. Die Länge der Heim-ID macht den Schlüssel auch für IDs mit
	 * Trennzeichen eindeutig.
	 */
	public function pairing_key(): string {
		$home = (string) $this->home_team;
		return $this->leg . '|' . strlen( $home ) . '|' . $home . '|' . $this->away_team;
	}

	/**
	 * Dieselbe Begegnung an einem anderen Termin, auf Wunsch in einem anderen Lokal.
	 *
	 * @param \DateTimeImmutable $starts_at Neues Datum mit Anspielzeit.
	 * @param int|string|null    $venue_id  Neues Lokal, null: unverändert.
	 */
	public function moved_to( \DateTimeImmutable $starts_at, int|string|null $venue_id = null ): self {
		return new self( $this->group_id, $this->round, $this->leg, $this->home_team, $this->away_team, $starts_at, $venue_id ?? $this->venue_id );
	}
}
