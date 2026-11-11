<?php
/**
 * Bezeichnungen im Mannschaftscup: Runden, Status einer Meldung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cup;

final class Labels {

	/**
	 * Name einer Runde: vom Final her gezählt «Final», «Halbfinal», «Viertelfinal»,
	 * «Achtelfinal», davor «1. Runde», «2. Runde».
	 *
	 * @param int $round  Runde ab 1.
	 * @param int $rounds Anzahl Runden des Tableaus.
	 */
	public static function round( int $round, int $rounds ): string {
		return match ( $rounds - $round ) {
			0       => __( 'Final', 'ttleague' ),
			1       => __( 'Halbfinal', 'ttleague' ),
			2       => __( 'Viertelfinal', 'ttleague' ),
			3       => __( 'Achtelfinal', 'ttleague' ),
			/* translators: %d: Nummer der Runde */
			default => sprintf( __( '%d. Runde', 'ttleague' ), $round ),
		};
	}

	public static function entry_status( string $status ): string {
		return CupRepository::WITHDRAWN === $status ? __( 'zurückgezogen', 'ttleague' ) : __( 'gemeldet', 'ttleague' );
	}
}
