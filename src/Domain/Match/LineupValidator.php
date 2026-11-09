<?php
/**
 * Plausibilität einer Begegnung gegen die Aufstellung (Art. 14.2 und 14.4).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Match;

use TTLeague\Domain\Rules\SeasonRules;

/**
 * Prüft, ob die eingetragenen Spiele zur Aufstellung passen: Fehlt ein Spieler,
 * müssen seine Einzel kampflos verloren sein; fehlt auf beiden Seiten einer,
 * ist das direkte Duell der fehlenden Slots nicht gespielt.
 */
final class LineupValidator {

	/**
	 * @param array<string,int|null> $home_slots Slot => player_id oder null für fehlend (A, B, C).
	 * @param array<string,int|null> $away_slots Slot => player_id oder null (X, Y, Z).
	 * @param list<GameResult>        $games      Spiele.
	 * @return list<string> Fehlermeldungen, leer wenn gültig.
	 */
	public static function validate_team_match( array $home_slots, array $away_slots, array $games, SeasonRules $rules ): array {
		$errors = array();
		$by_seq = array();
		foreach ( $games as $game ) {
			$by_seq[ $game->sequence ] = $game;
		}

		$expected = count( GameOrder::team_match() );
		if ( count( $games ) !== $expected ) {
			$errors[] = sprintf( 'Es müssen genau %d Spiele eingetragen sein, vorhanden: %d.', $expected, count( $games ) );
		}

		$home_present = array_filter( $home_slots, static fn( $p ) => null !== $p );
		$away_present = array_filter( $away_slots, static fn( $p ) => null !== $p );
		if ( count( $home_present ) < 2 || count( $away_present ) < 2 ) {
			$errors[] = 'Eine Mannschaft braucht mindestens zwei Spieler (Art. 14.2).';
		}

		foreach ( GameOrder::team_match() as $slot_game ) {
			$seq  = $slot_game['sequence'];
			$game = $by_seq[ $seq ] ?? null;
			if ( null === $game || GameOrder::SINGLE !== $slot_game['type'] ) {
				continue;
			}
			$home_missing = ! array_key_exists( $slot_game['home'], $home_present );
			$away_missing = ! array_key_exists( $slot_game['away'], $away_present );

			if ( $home_missing && $away_missing && GameStatus::NotPlayed !== $game->status ) {
				$errors[] = sprintf( 'Spiel %d: beide Spieler fehlen, das Spiel gilt als nicht gespielt.', $seq );
			} elseif ( $home_missing && ! $away_missing && ! ( GameStatus::Walkover === $game->status && Side::Away === $game->winner() ) ) {
				$errors[] = sprintf( 'Spiel %d: Heimspieler %s fehlt, das Spiel ist kampflos für den Gast.', $seq, $slot_game['home'] );
			} elseif ( $away_missing && ! $home_missing && ! ( GameStatus::Walkover === $game->status && Side::Home === $game->winner() ) ) {
				$errors[] = sprintf( 'Spiel %d: Gastspieler %s fehlt, das Spiel ist kampflos für den Heimclub.', $seq, $slot_game['away'] );
			}
		}

		$result = TeamMatchResult::from_team_match( $games, $rules );
		if ( count( $home_present ) === 2 && $result->home_score > $rules->two_player_max_wins ) {
			$errors[] = sprintf( 'Ein 2er-Team kann höchstens %d Spiele gewinnen.', $rules->two_player_max_wins );
		}
		if ( count( $away_present ) === 2 && $result->away_score > $rules->two_player_max_wins ) {
			$errors[] = sprintf( 'Ein 2er-Team kann höchstens %d Spiele gewinnen.', $rules->two_player_max_wins );
		}

		return $errors;
	}
}
