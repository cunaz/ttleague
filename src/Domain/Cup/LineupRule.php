<?php
/**
 * Aufstellungsregel einer Cupmannschaft bei der Erfassung (Art. 20.1 und 20.2).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Cup;

/**
 * Reine Regel aus docs/cup.md:
 *
 * - Mindestens einer der eingesetzten Spieler gehört zur ursprünglichen Meldung der Mannschaft.
 * - Die Summe der Klassierungen der eingesetzten Spieler ist nicht höher als die Punktesumme
 *   der Meldung. Spielt die Mannschaft wie gemeldet, gilt die Meldung, auch wenn sich eine
 *   Klassierung seither geändert hat.
 * - Ein Ersatzspieler ist in keiner anderen Cupmannschaft der Saison gemeldet und hat für keine
 *   andere gespielt. Dass er für denselben Club gemeldet ist, prüft die Erfassung ohnehin.
 *
 * Fehlen Angaben der Meldung (Spieler oder Punktesumme, etwa bei importierten Saisons), entfällt
 * die betroffene Prüfung.
 */
final class LineupRule {

	/** Kein eingesetzter Spieler gehört zur ursprünglichen Meldung. */
	public const NO_ORIGINAL = 'no_original';

	/** Die Summe der Klassierungen übersteigt die Punktesumme der Meldung. */
	public const POINTS_EXCEEDED = 'points_exceeded';

	/**
	 * Verstösse der Aufstellung einer Seite.
	 *
	 * @param list<int>          $original     Spieler der ursprünglichen Meldung.
	 * @param int|null           $points_total Punktesumme der Meldung.
	 * @param array<int,int|null> $fielded     Eingesetzte Spieler: ID => Klassierung.
	 * @return list<string> NO_ORIGINAL, POINTS_EXCEEDED.
	 */
	public static function violations( array $original, ?int $points_total, array $fielded ): array {
		if ( array() === $fielded ) {
			return array();
		}
		$violations  = array();
		$substitutes = array_diff( array_keys( $fielded ), $original );
		if ( array() !== $original && array() === array_intersect( array_keys( $fielded ), $original ) ) {
			$violations[] = self::NO_ORIGINAL;
		}
		if ( null !== $points_total && array() !== $substitutes && self::points( $fielded ) > $points_total ) {
			$violations[] = self::POINTS_EXCEEDED;
		}
		return $violations;
	}

	/**
	 * Summe der Klassierungen der eingesetzten Spieler.
	 *
	 * @param array<int,int|null> $fielded Eingesetzte Spieler: ID => Klassierung.
	 */
	public static function points( array $fielded ): int {
		return (int) array_sum( array_map( 'intval', $fielded ) );
	}

	/**
	 * Name der anderen Cupmannschaft, die einen Ersatzspieler sperrt; null, wenn er spielen darf.
	 * Spieler der ursprünglichen Meldung sind nie gesperrt.
	 *
	 * @param list<int>         $original    Spieler der ursprünglichen Meldung.
	 * @param array<int,string> $other_teams Spieler => Name der anderen Cupmannschaft, in der er
	 *                                       gemeldet ist oder für die er gespielt hat.
	 */
	public static function blocked_by( int $player_id, array $original, array $other_teams ): ?string {
		if ( in_array( $player_id, $original, true ) ) {
			return null;
		}
		return $other_teams[ $player_id ] ?? null;
	}
}
