<?php
/**
 * Resultat eines einzelnen Spiels (Einzel oder Doppel).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Match;

final class GameResult {

	/**
	 * @param int        $sequence    Position in der Spielfolge (1-basiert).
	 * @param int        $sets_home   Gewonnene Sätze Heim.
	 * @param int        $sets_away   Gewonnene Sätze Gast.
	 * @param GameStatus $status      Gespielt, w.o. oder nicht gespielt.
	 * @param bool       $is_double   Doppel.
	 * @param int|null   $points_home Summe der Satzpunkte Heim (optional).
	 * @param int|null   $points_away Summe der Satzpunkte Gast (optional).
	 * @param int        $sets_to_win Gewinnsätze.
	 */
	public function __construct(
		public readonly int $sequence,
		public readonly int $sets_home,
		public readonly int $sets_away,
		public readonly GameStatus $status = GameStatus::Played,
		public readonly bool $is_double = false,
		public readonly ?int $points_home = null,
		public readonly ?int $points_away = null,
		public readonly int $sets_to_win = 3,
	) {
		if ( $sets_home < 0 || $sets_away < 0 ) {
			throw new \InvalidArgumentException( 'Sätze dürfen nicht negativ sein.' );
		}
		if ( GameStatus::Played === $status ) {
			$max = max( $sets_home, $sets_away );
			$min = min( $sets_home, $sets_away );
			if ( $max !== $sets_to_win || $min >= $sets_to_win ) {
				throw new \InvalidArgumentException(
					sprintf( 'Ungültiges Satzresultat %d:%d bei %d Gewinnsätzen.', $sets_home, $sets_away, $sets_to_win )
				);
			}
		}
	}

	/**
	 * Kampfloses Spiel zugunsten einer Seite.
	 */
	public static function walkover( int $sequence, Side $winner, bool $is_double = false, int $sets_to_win = 3 ): self {
		return new self(
			$sequence,
			Side::Home === $winner ? $sets_to_win : 0,
			Side::Away === $winner ? $sets_to_win : 0,
			GameStatus::Walkover,
			$is_double,
			null,
			null,
			$sets_to_win
		);
	}

	/**
	 * Nicht gespieltes Spiel ohne Punkt für beide Seiten.
	 */
	public static function not_played( int $sequence, bool $is_double = false, int $sets_to_win = 3 ): self {
		return new self( $sequence, 0, 0, GameStatus::NotPlayed, $is_double, null, null, $sets_to_win );
	}

	/**
	 * Siegerseite, null bei nicht gespieltem Spiel.
	 */
	public function winner(): ?Side {
		if ( GameStatus::NotPlayed === $this->status ) {
			return null;
		}
		return $this->sets_home > $this->sets_away ? Side::Home : Side::Away;
	}

	public function is_win_for( Side $side ): bool {
		return $this->winner() === $side;
	}

	/**
	 * Zählt das Spiel für Satz- und Punkteverhältnis? (w.o. und nicht gespielt zählen nicht.)
	 */
	public function counts_for_ratios(): bool {
		return GameStatus::Played === $this->status;
	}
}
