<?php
/**
 * Reglementarische Spielfolge (Art. 14.1 und Art. 22).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Match;

/**
 * Liefert die fixe Reihenfolge der Spiele. Platzclub: A, B, C; Gastclub: X, Y, Z.
 * Im Cup: Platzclub A, B; Gastclub Y, Z.
 */
final class GameOrder {

	public const DOUBLE = 'double';
	public const SINGLE = 'single';

	/**
	 * Zehn Spiele der Meisterschaftsbegegnung.
	 *
	 * @return list<array{sequence:int,type:string,home:?string,away:?string}>
	 */
	public static function team_match(): array {
		return array(
			self::single( 1, 'A', 'X' ),
			self::single( 2, 'B', 'Y' ),
			self::single( 3, 'C', 'Z' ),
			self::single( 4, 'B', 'X' ),
			self::single( 5, 'A', 'Z' ),
			self::single( 6, 'C', 'Y' ),
			self::double( 7 ),
			self::single( 8, 'B', 'Z' ),
			self::single( 9, 'C', 'X' ),
			self::single( 10, 'A', 'Y' ),
		);
	}

	/**
	 * Fünf Spiele der Cup-Begegnung.
	 *
	 * @return list<array{sequence:int,type:string,home:?string,away:?string}>
	 */
	public static function cup(): array {
		return array(
			self::single( 1, 'A', 'Y' ),
			self::single( 2, 'B', 'Z' ),
			self::double( 3 ),
			self::single( 4, 'A', 'Z' ),
			self::single( 5, 'B', 'Y' ),
		);
	}

	/**
	 * Spiele, an denen ein bestimmter Slot beteiligt ist (Einzel), z. B. zur Forfait-Wertung bei 2er-Teams.
	 *
	 * @return list<int> Sequenznummern.
	 */
	public static function singles_for_slot( string $slot, bool $cup = false ): array {
		$result = array();
		foreach ( $cup ? self::cup() : self::team_match() as $game ) {
			if ( self::SINGLE === $game['type'] && ( $game['home'] === $slot || $game['away'] === $slot ) ) {
				$result[] = $game['sequence'];
			}
		}
		return $result;
	}

	/**
	 * @return array{sequence:int,type:string,home:?string,away:?string}
	 */
	private static function single( int $sequence, string $home, string $away ): array {
		return array(
			'sequence' => $sequence,
			'type'     => self::SINGLE,
			'home'     => $home,
			'away'     => $away,
		);
	}

	/**
	 * @return array{sequence:int,type:string,home:?string,away:?string}
	 */
	private static function double( int $sequence ): array {
		return array(
			'sequence' => $sequence,
			'type'     => self::DOUBLE,
			'home'     => null,
			'away'     => null,
		);
	}
}
