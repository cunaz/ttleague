<?php
/**
 * Wertungsereignisse eines Spielers nach Begegnung oder Turniergruppe.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

final class RatingEncounters {

	/**
	 * @param list<array<string,mixed>> $history Chronologische Wertungsereignisse.
	 * @param list<array<string,mixed>> $games   Öffentlich sichtbare Spiele des Spielers.
	 * @return list<array<string,mixed>> Je Abschnitt die Gesamtänderung und die zugehörigen Partien in Wertungsreihenfolge.
	 */
	public static function group( array $history, array $games ): array {
		$by_id    = array_column( $games, null, 'id' );
		$points   = array();
		$previous = null;
		foreach ( $history as $event ) {
			$game = $by_id[ $event['game_id'] ] ?? null;
			if ( null === $game ) {
				// Keine Brücke über eine im Profil fehlende Wertung schlagen.
				$previous = null;
				continue;
			}
			$source = 'RL' === $game['kind'] ? ( $game['rl_group_id'] ?? null ) : $game['fixture_id'];
			$key    = $source > 0 ? array( $game['season_slug'], $game['kind'], $source ) : null;
			$last   = array_key_last( $points );
			if ( null === $key || $key !== $previous || $points[ $last ]['after'] !== $event['before'] ) {
				$points[] = array(
					'season'      => $game['season_slug'],
					'season_name' => $game['season_name'],
					'before'      => $event['before'],
					'delta'       => 0.0,
					'games'       => array(),
				);
				$last     = array_key_last( $points );
			}
			$points[ $last ]['at']      = $event['at'];
			$points[ $last ]['after']   = $event['after'];
			$points[ $last ]['delta']  += $event['delta'];
			$points[ $last ]['games'][] = $game + $event;
			$previous                   = $key;
		}
		return $points;
	}
}
