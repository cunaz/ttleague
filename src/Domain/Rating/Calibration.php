<?php
/**
 * Kalibrierung der Wertungszahl an den Klassierungsentscheiden der Vergangenheit.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Rating;

use TTLeague\Domain\Rules\SeasonRules;

/**
 * Für jede Parameterkombination wird die Wertungszahl über alle Spiele berechnet. Gemessen wird:
 * - wie gut der Klassierungsvorschlag am Saisonende die tatsächliche Klassierung der Folgesaison trifft,
 * - wie gut die Erwartungswerte die Spielausgänge vorhersagen (Log-Loss, tiefer ist besser).
 * Als Vergleich dient «keine Änderung» (Vorschlag = bisherige Klassierung).
 */
final class Calibration {

	/**
	 * @param list<RatingGame>                $games          Spiele, chronologisch.
	 * @param array<int,array<int,int>>       $classes        Saison => Spieler => Klassierung.
	 * @param list<int>                       $season_order   Saison-IDs in zeitlicher Reihenfolge.
	 */
	public function __construct(
		private readonly array $games,
		private readonly array $classes,
		private readonly array $season_order,
	) {}

	/**
	 * @param list<float> $k_values       K-Faktoren.
	 * @param list<float> $scales         Skalen der Erwartungswertformel.
	 * @param list<float> $doubles        Gewichte der Doppel.
	 * @param list<bool>  $reanchor       Neuverankerung pro Saison.
	 * @return list<array<string,float|int|bool>> Ergebnisse, bestes zuerst.
	 */
	public function grid( array $k_values, array $scales, array $doubles, array $reanchor ): array {
		$rows = array();
		foreach ( $k_values as $k ) {
			foreach ( $scales as $scale ) {
				foreach ( $doubles as $weight ) {
					foreach ( $reanchor as $anchor ) {
						$rows[] = $this->evaluate( $k, $scale, $weight, $anchor );
					}
				}
			}
		}
		usort(
			$rows,
			static fn( $a, $b ) => array( $a['mae'], -$a['exact'], $a['log_loss'] ) <=> array( $b['mae'], -$b['exact'], $b['log_loss'] )
		);
		return $rows;
	}

	/**
	 * @return array<string,float|int|bool>
	 */
	public function evaluate( float $k, float $scale, float $doubles_weight, bool $reanchor ): array {
		$rules  = new SeasonRules( rating_k: $k, rating_scale: $scale, rating_doubles_weight: $doubles_weight );
		$engine = new RatingEngine( $rules, $reanchor );
		$result = $engine->run( $this->games, fn( int $player, int $season ) => $this->classes[ $season ][ $player ] ?? null, false );
		$elo    = new EloRating( $rules );

		$stats = $this->proposal_stats( $result, $elo );
		return array(
			'k'           => $k,
			'scale'       => $scale,
			'doubles'     => $doubles_weight,
			'reanchor'    => $reanchor,
			'n'           => $stats['n'],
			'exact'       => $stats['exact'],
			'within1'     => $stats['within1'],
			'mae'         => $stats['mae'],
			'changes_hit' => $stats['changes_hit'],
			'false_moves' => $stats['false_moves'],
			'log_loss'    => self::log_loss( $result->predictions ),
			'accuracy'    => self::accuracy( $result->predictions ),
		);
	}

	/**
	 * Vergleichswert: Klassierung bleibt unverändert. Gezählt werden dieselben Fälle wie bei
	 * den Vorschlägen, also Personen mit mindestens einem gewerteten Spiel in der Saison.
	 *
	 * @return array{n:int,exact:float,within1:float,mae:float}
	 */
	public function baseline(): array {
		$played  = ( new RatingEngine( new SeasonRules() ) )->run( $this->games, fn( int $player, int $season ) => $this->classes[ $season ][ $player ] ?? null, false )->season_end;
		$n       = 0;
		$exact   = 0;
		$within1 = 0;
		$error   = 0;
		foreach ( $this->pairs() as [ $player, $season, $next ] ) {
			if ( ! isset( $played[ $season ][ $player ] ) ) {
				continue;
			}
			$current = $this->classes[ $season ][ $player ];
			$actual  = $this->classes[ $next ][ $player ];
			++$n;
			$diff     = abs( $current - $actual );
			$exact   += 0 === $diff ? 1 : 0;
			$within1 += $diff <= 1 ? 1 : 0;
			$error   += $diff;
		}
		return array(
			'n'       => $n,
			'exact'   => $n > 0 ? (float) $exact / $n : 0.0,
			'within1' => $n > 0 ? (float) $within1 / $n : 0.0,
			'mae'     => $n > 0 ? (float) $error / $n : 0.0,
		);
	}

	/**
	 * @return array{n:int,exact:float,within1:float,mae:float,changes_hit:float,false_moves:float}
	 */
	private function proposal_stats( RatingResult $result, EloRating $elo ): array {
		$n       = 0;
		$exact   = 0;
		$within1 = 0;
		$error   = 0;
		$changes = 0;
		$hits    = 0;
		$stays   = 0;
		$false   = 0;
		foreach ( $this->pairs() as [ $player, $season, $next ] ) {
			if ( ! isset( $result->season_end[ $season ][ $player ] ) ) {
				continue;
			}
			$current  = $this->classes[ $season ][ $player ];
			$actual   = $this->classes[ $next ][ $player ];
			$proposed = $elo->classification_for( $result->season_end[ $season ][ $player ] );
			++$n;
			$diff     = abs( $proposed - $actual );
			$exact   += 0 === $diff ? 1 : 0;
			$within1 += $diff <= 1 ? 1 : 0;
			$error   += $diff;
			if ( $actual !== $current ) {
				++$changes;
				$hits += ( $actual <=> $current ) === ( $proposed <=> $current ) ? 1 : 0;
			} else {
				++$stays;
				$false += $proposed !== $current ? 1 : 0;
			}
		}
		return array(
			'n'           => $n,
			'exact'       => $n > 0 ? (float) $exact / $n : 0.0,
			'within1'     => $n > 0 ? (float) $within1 / $n : 0.0,
			'mae'         => $n > 0 ? (float) $error / $n : 0.0,
			'changes_hit' => $changes > 0 ? (float) $hits / $changes : 0.0,
			'false_moves' => $stays > 0 ? (float) $false / $stays : 0.0,
		);
	}

	/**
	 * Spieler, die in einer Saison und der Folgesaison klassiert sind.
	 *
	 * @return list<array{0:int,1:int,2:int}> Spieler, Saison, Folgesaison.
	 */
	private function pairs(): array {
		$pairs = array();
		$count = count( $this->season_order );
		for ( $i = 0; $i < $count - 1; $i++ ) {
			$season = $this->season_order[ $i ];
			$next   = $this->season_order[ $i + 1 ];
			foreach ( $this->classes[ $season ] ?? array() as $player => $class ) {
				if ( isset( $this->classes[ $next ][ $player ] ) ) {
					$pairs[] = array( (int) $player, $season, $next );
				}
			}
		}
		return $pairs;
	}

	/**
	 * @param list<array{0:float,1:int}> $predictions Erwartung und Ausgang.
	 */
	private static function log_loss( array $predictions ): float {
		if ( array() === $predictions ) {
			return 0.0;
		}
		$sum = 0.0;
		foreach ( $predictions as [ $p, $y ] ) {
			$p    = min( max( $p, 1e-6 ), 1 - 1e-6 );
			$sum += -( $y * log( $p ) + ( 1 - $y ) * log( 1 - $p ) );
		}
		return $sum / count( $predictions );
	}

	/**
	 * @param list<array{0:float,1:int}> $predictions Erwartung und Ausgang.
	 */
	private static function accuracy( array $predictions ): float {
		if ( array() === $predictions ) {
			return 0.0;
		}
		$hits = 0;
		foreach ( $predictions as [ $p, $y ] ) {
			$hits += ( $p >= 0.5 ? 1 : 0 ) === $y ? 1 : 0;
		}
		return (float) $hits / count( $predictions );
	}
}
