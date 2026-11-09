<?php
/**
 * Status eines Einzelspiels oder Doppels.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Match;

enum GameStatus: string {
	/** Regulär gespielt, Satzresultat liegt vor. */
	case Played = 'played';
	/** Kampflos (w.o.): eine Seite tritt nicht an, die andere erhält den Punkt. */
	case Walkover = 'walkover';
	/** Nicht gespielt: niemand erhält den Punkt (beide Teams zu zweit, Art. 14.4). */
	case NotPlayed = 'not_played';
}
