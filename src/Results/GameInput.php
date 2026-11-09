<?php
/**
 * Ein Spiel in der Eingabe einer Resultaterfassung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

final class GameInput {

	/**
	 * @param int                      $sequence     Spielnummer.
	 * @param string                   $status       played, walkover oder not_played.
	 * @param int|null                 $sets_home    Gewonnene Sätze Heim (bei played).
	 * @param int|null                 $sets_away    Gewonnene Sätze Gast.
	 * @param string|null              $winner       home oder away (bei walkover).
	 * @param list<int>                $home_players Doppel Heim.
	 * @param list<int>                $away_players Doppel Gast.
	 * @param list<array{0:int,1:int}> $set_points   Punkte pro Satz.
	 */
	public function __construct(
		public readonly int $sequence,
		public readonly string $status,
		public readonly ?int $sets_home = null,
		public readonly ?int $sets_away = null,
		public readonly ?string $winner = null,
		public readonly array $home_players = array(),
		public readonly array $away_players = array(),
		public readonly array $set_points = array(),
	) {}

	/**
	 * @param array<string,mixed>                $game   Rohdaten.
	 * @param array{field:string,message:string}[] $errors Fehlerliste (wird ergänzt).
	 */
	public static function from_array( int $sequence, array $game, string $field, array &$errors ): ?self {
		$status = is_string( $game['status'] ?? null ) ? $game['status'] : 'played';
		if ( ! in_array( $status, ResultInput::STATUSES, true ) ) {
			$errors[] = ResultInput::error( $field . '.status', __( 'Unbekannter Spielstatus.', 'ttleague' ) );
			return null;
		}
		$count  = count( $errors );
		$sets_h = null;
		$sets_a = null;
		$winner = null;
		if ( 'played' === $status ) {
			$sets_h = self::sets( $game['sets_home'] ?? null );
			$sets_a = self::sets( $game['sets_away'] ?? null );
			if ( null === $sets_h || null === $sets_a ) {
				$errors[] = ResultInput::error( $field, __( 'Satzresultat fehlt.', 'ttleague' ) );
			}
		} elseif ( 'walkover' === $status ) {
			$winner = $game['winner'] ?? null;
			if ( ! in_array( $winner, array( 'home', 'away' ), true ) ) {
				$errors[] = ResultInput::error( $field . '.winner', __( 'Bei einem kampflosen Spiel fehlt die Siegerseite.', 'ttleague' ) );
				$winner   = null;
			}
		}
		$home_players = self::players( $game['home_players'] ?? array(), $field . '.home_players', $errors );
		$away_players = self::players( $game['away_players'] ?? array(), $field . '.away_players', $errors );

		$set_points = array();
		$raw_points = $game['set_points'] ?? array();
		if ( 'played' === $status && is_array( $raw_points ) && array() !== $raw_points ) {
			foreach ( array_values( $raw_points ) as $i => $set ) {
				if ( ! is_array( $set ) || 2 !== count( $set ) || ! is_int( $set[0] ?? null ) || ! is_int( $set[1] ?? null ) || $set[0] < 0 || $set[1] < 0 || $set[0] > 99 || $set[1] > 99 ) {
					/* translators: %d: Satznummer */
					$errors[] = ResultInput::error( $field . '.set_points', sprintf( __( 'Satz %d: ungültige Punkte.', 'ttleague' ), $i + 1 ) );
					continue;
				}
				$set_points[] = array( $set[0], $set[1] );
			}
		} elseif ( null !== $raw_points && ! is_array( $raw_points ) ) {
			$errors[] = ResultInput::error( $field . '.set_points', __( 'Ungültige Satzpunkte.', 'ttleague' ) );
		}

		if ( count( $errors ) > $count ) {
			return null;
		}
		return new self( $sequence, $status, $sets_h, $sets_a, $winner, $home_players, $away_players, $set_points );
	}

	private static function sets( mixed $value ): ?int {
		if ( is_int( $value ) && $value >= 0 && $value <= 9 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[0-9]$/', $value ) ) {
			return (int) $value;
		}
		return null;
	}

	/**
	 * @param array{field:string,message:string}[] $errors Fehlerliste (wird ergänzt).
	 * @return list<int>
	 */
	private static function players( mixed $raw, string $field, array &$errors ): array {
		if ( null === $raw || array() === $raw ) {
			return array();
		}
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( $raw ) > 2 ) {
			$errors[] = ResultInput::error( $field, __( 'Ungültige Doppelpaarung.', 'ttleague' ) );
			return array();
		}
		$ids = array();
		foreach ( $raw as $value ) {
			$id = ResultInput::int( $value );
			if ( null === $id ) {
				$errors[] = ResultInput::error( $field, __( 'Ungültige Doppelpaarung.', 'ttleague' ) );
				return array();
			}
			$ids[] = $id;
		}
		return $ids;
	}
}
