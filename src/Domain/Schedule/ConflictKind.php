<?php
/**
 * Art einer Kollision im Spielplan.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Schedule;

enum ConflictKind: string {
	/** Das Lokal ist am Termin gesperrt. */
	case VenueClosed = 'venue_closed';
	/** Die Begegnungen eines Abends brauchen mehr Tische, als das Lokal hat. */
	case VenueCapacity = 'venue_capacity';
	/** Dieselbe Mannschaft hat am selben Abend mehrere Begegnungen. */
	case TeamDoubleBooked = 'team_double_booked';
	/** Mehrere Mannschaften desselben Clubs spielen am selben Abend; Ersatzspieler fehlen dann. */
	case ClubSameEvening = 'club_same_evening';
	/** Die Tischzahl des Lokals ist unbekannt, die Kapazität wurde nicht geprüft. */
	case TablesUnknown = 'tables_unknown';

	/**
	 * Harte Kollisionen machen den Termin unmöglich; alle anderen sind Hinweise an die TK.
	 */
	public function is_hard(): bool {
		return in_array( $this, array( self::VenueClosed, self::VenueCapacity, self::TeamDoubleBooked ), true );
	}
}
