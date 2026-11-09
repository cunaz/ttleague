<?php
/**
 * Berechnet die Wertungszahl chronologisch über alle Spiele.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Rating;

use TTLeague\Domain\Rules\SeasonRules;

/**
 * Elo-artige Wertungszahl. Startwert ist die offizielle Klassierung × Punkte pro Stufe.
 * Mit Neuverankerung (Standard) beginnt jede Saison wieder bei der offiziellen Klassierung
 * dieser Saison; die Wertungszahl zeigt dann, wie sich jemand innerhalb der Saison
 * gegenüber seiner Klassierung entwickelt. Ohne Neuverankerung läuft sie über alle Saisons weiter.
 */
final class RatingEngine {

	private EloRating $elo;

	public function __construct( private readonly SeasonRules $rules, private readonly bool $reanchor = true ) {
		$this->elo = new EloRating( $rules );
	}

	/**
	 * @param list<RatingGame>                $games          Spiele, chronologisch sortiert.
	 * @param callable(int,int):(int|null)    $classification Klassierung eines Spielers in einer Saison.
	 * @param bool                            $with_events    Einzelereignisse sammeln (für die Speicherung).
	 */
	public function run( array $games, callable $classification, bool $with_events = true ): RatingResult {
		$result      = new RatingResult();
		$anchor_from = array();

		foreach ( $games as $game ) {
			foreach ( array_merge( $game->side_a, $game->side_b ) as $player ) {
				$needs_start = ! isset( $result->ratings[ $player ] );
				$new_season  = $this->reanchor && ( $anchor_from[ $player ] ?? null ) !== $game->season_id;
				if ( $needs_start || $new_season ) {
					$class = $classification( $player, $game->season_id );
					if ( null !== $class ) {
						$result->ratings[ $player ] = $this->elo->initial_rating( $class );
					} elseif ( $needs_start ) {
						$result->ratings[ $player ] = $this->elo->initial_rating( 1 );
					}
					$anchor_from[ $player ] = $game->season_id;
				}
			}

			if ( $game->is_double() ) {
				$before_a = array( $result->ratings[ $game->side_a[0] ], $result->ratings[ $game->side_a[1] ] );
				$before_b = array( $result->ratings[ $game->side_b[0] ], $result->ratings[ $game->side_b[1] ] );
				$expected = $this->elo->expected( array_sum( $before_a ) / 2, array_sum( $before_b ) / 2 );
				if ( $this->rules->rating_doubles_weight <= 0.0 ) {
					$result->predictions[] = array( $expected, $game->a_won ? 1 : 0 );
					continue;
				}
				[ $after_a, $after_b ] = $this->elo->update_double( $before_a, $before_b, $game->a_won );
				$updates               = array(
					array( $game->side_a[0], $before_a[0], $after_a[0] ),
					array( $game->side_a[1], $before_a[1], $after_a[1] ),
					array( $game->side_b[0], $before_b[0], $after_b[0] ),
					array( $game->side_b[1], $before_b[1], $after_b[1] ),
				);
			} else {
				$a                     = $game->side_a[0];
				$b                     = $game->side_b[0];
				$expected              = $this->elo->expected( $result->ratings[ $a ], $result->ratings[ $b ] );
				[ $after_a, $after_b ] = $this->elo->update_single( $result->ratings[ $a ], $result->ratings[ $b ], $game->a_won );
				$updates               = array(
					array( $a, $result->ratings[ $a ], $after_a ),
					array( $b, $result->ratings[ $b ], $after_b ),
				);
			}
			$result->predictions[] = array( $expected, $game->a_won ? 1 : 0 );

			foreach ( $updates as [ $player, $before, $after ] ) {
				$result->ratings[ $player ]                        = $after;
				$result->games[ $player ]                          = ( $result->games[ $player ] ?? 0 ) + 1;
				$result->season_end[ $game->season_id ][ $player ] = $after;
				if ( $with_events ) {
					$result->events[] = new RatingEvent( $player, $game->id, $before, $after, $game->played_at );
				}
			}
		}
		return $result;
	}
}
