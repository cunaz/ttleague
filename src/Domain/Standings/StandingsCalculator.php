<?php
/**
 * Tabellenrechner nach Art. 14.4 des Wettspielreglements.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Standings;

/**
 * Rangfolge: Summe der gewonnenen Spiele. Bei Gleichstand zählen die Begegnungen der
 * punktgleichen Teams untereinander: Verhältnis Siege zu Niederlagen, dann Sätze, dann Punkte.
 * Bleibt es gleich, folgen dieselben Verhältnisse über alle Begegnungen. Danach ist die
 * Rangfolge offen (Entscheidungsspiel, Art. 15) und wird als geteilter Rang markiert.
 */
final class StandingsCalculator {

	private const CRITERIA = array( 'h2h_games', 'h2h_sets', 'h2h_points', 'games', 'sets', 'points' );

	/**
	 * @param list<int|string>   $teams     Alle Teams der Gruppe, auch ohne Begegnung.
	 * @param list<MatchSummary> $matches   Gewertete Begegnungen.
	 * @param list<int|string>   $withdrawn Zurückgezogene Teams (Art. 13.3): Sie stehen am Tabellenende,
	 *                                      hinter allen anderen, unter sich nach denselben Kriterien.
	 * @return list<StandingsRow> Sortiert, mit Rang.
	 */
	public function calculate( array $teams, array $matches, array $withdrawn = array() ): array {
		/** @var array<int|string,StandingsRow> $rows */
		$rows = array();
		foreach ( $teams as $team ) {
			$rows[ $team ] = new StandingsRow( $team );
		}
		foreach ( $matches as $match ) {
			foreach ( array( $match->home_team, $match->away_team ) as $team ) {
				if ( ! isset( $rows[ $team ] ) ) {
					$rows[ $team ] = new StandingsRow( $team );
				}
				$rows[ $team ]->add( $match );
			}
		}

		$ordered = array();
		foreach ( array( false, true ) as $is_withdrawn ) {
			$by_points = array();
			foreach ( $rows as $row ) {
				if ( in_array( $row->team, $withdrawn, true ) === $is_withdrawn ) {
					$by_points[ $row->points() ][] = $row;
				}
			}
			krsort( $by_points, SORT_NUMERIC );
			foreach ( $by_points as $group ) {
				foreach ( $this->split( $group, $matches ) as $sub_group ) {
					$ordered[] = $sub_group;
				}
			}
		}

		$flat     = array();
		$position = 0;
		foreach ( $ordered as $sub_group ) {
			$rank = $position + 1;
			foreach ( $sub_group as $row ) {
				$row->rank = $rank;
				if ( count( $sub_group ) > 1 ) {
					$row->tiebreak_note = 'Rangfolge nicht eindeutig, Entscheidungsspiel nach Art. 15.';
				}
				$flat[] = $row;
				++$position;
			}
		}
		return $flat;
	}

	/**
	 * Löst eine Gruppe punktgleicher Teams mit den Kriterien der Reihe nach auf. Sobald ein
	 * Kriterium trennt, wird jede Untergruppe wieder ab dem ersten Kriterium behandelt
	 * (der Direktvergleich bezieht sich dann nur noch auf die Untergruppe).
	 *
	 * @param list<StandingsRow> $group   Punktgleiche Teams.
	 * @param list<MatchSummary> $matches Alle Begegnungen.
	 * @return list<list<StandingsRow>> Untergruppen in Rangfolge; mehrere Teams in einer Untergruppe sind nicht trennbar.
	 */
	private function split( array $group, array $matches ): array {
		if ( count( $group ) < 2 ) {
			return array( $group );
		}
		$ids = array_map( static fn( StandingsRow $r ) => $r->team, $group );

		foreach ( self::CRITERIA as $criterion ) {
			$cmp = fn( StandingsRow $a, StandingsRow $b ): int => $this->compare( $criterion, $a, $b, $ids, $matches );

			$sorted = $group;
			usort( $sorted, static fn( StandingsRow $a, StandingsRow $b ) => $cmp( $b, $a ) );

			$buckets = array( array( $sorted[0] ) );
			$count   = count( $sorted );
			for ( $j = 1; $j < $count; $j++ ) {
				if ( 0 === $cmp( $sorted[ $j - 1 ], $sorted[ $j ] ) ) {
					$buckets[ count( $buckets ) - 1 ][] = $sorted[ $j ];
				} else {
					$buckets[] = array( $sorted[ $j ] );
				}
			}

			if ( count( $buckets ) > 1 ) {
				$result = array();
				foreach ( $buckets as $bucket ) {
					foreach ( $this->split( $bucket, $matches ) as $sub ) {
						$result[] = $sub;
					}
				}
				return $result;
			}
		}

		usort( $group, static fn( StandingsRow $a, StandingsRow $b ) => strcmp( (string) $a->team, (string) $b->team ) );
		return array( $group );
	}

	/**
	 * @param string             $criterion Eines aus CRITERIA.
	 * @param list<int|string>   $ids       Teams der punktgleichen Gruppe.
	 * @param list<MatchSummary> $matches   Alle Begegnungen.
	 */
	private function compare( string $criterion, StandingsRow $a, StandingsRow $b, array $ids, array $matches ): int {
		switch ( $criterion ) {
			case 'h2h_games':
			case 'h2h_sets':
			case 'h2h_points':
				$kind = substr( $criterion, 4 );
				$ta   = $this->head_to_head_totals( $a->team, $ids, $matches );
				$tb   = $this->head_to_head_totals( $b->team, $ids, $matches );
				if ( 'points' === $kind && ( ! $ta['points_known'] || ! $tb['points_known'] ) ) {
					return 0;
				}
				return Ratio::compare( $ta[ $kind . '_for' ], $ta[ $kind . '_against' ], $tb[ $kind . '_for' ], $tb[ $kind . '_against' ] );
			case 'games':
				return Ratio::compare( $a->games_won, $a->games_lost, $b->games_won, $b->games_lost );
			case 'sets':
				return Ratio::compare( $a->sets_won, $a->sets_lost, $b->sets_won, $b->sets_lost );
			case 'points':
				if ( ! $a->points_known || ! $b->points_known ) {
					return 0;
				}
				return Ratio::compare( $a->points_for, $a->points_against, $b->points_for, $b->points_against );
		}
		return 0;
	}

	/**
	 * Summen eines Teams aus den Begegnungen gegen die anderen Teams der Gruppe.
	 *
	 * @param int|string         $team    Team.
	 * @param list<int|string>   $ids     Teams der Gruppe.
	 * @param list<MatchSummary> $matches Begegnungen.
	 * @return array{games_for:int,games_against:int,sets_for:int,sets_against:int,points_for:int,points_against:int,points_known:bool}
	 */
	private function head_to_head_totals( int|string $team, array $ids, array $matches ): array {
		$t = array(
			'games_for'      => 0,
			'games_against'  => 0,
			'sets_for'       => 0,
			'sets_against'   => 0,
			'points_for'     => 0,
			'points_against' => 0,
			'points_known'   => true,
		);
		foreach ( $matches as $m ) {
			if ( ! $m->involves( $team ) ) {
				continue;
			}
			$opponent = $m->home_team === $team ? $m->away_team : $m->home_team;
			if ( ! in_array( $opponent, $ids, true ) ) {
				continue;
			}
			$is_home             = $m->home_team === $team;
			$t['games_for']     += $is_home ? $m->home_score : $m->away_score;
			$t['games_against'] += $is_home ? $m->away_score : $m->home_score;
			$t['sets_for']      += $is_home ? $m->home_sets : $m->away_sets;
			$t['sets_against']  += $is_home ? $m->away_sets : $m->home_sets;
			if ( null === $m->home_points || null === $m->away_points ) {
				$t['points_known'] = false;
			} else {
				$t['points_for']     += $is_home ? $m->home_points : $m->away_points;
				$t['points_against'] += $is_home ? $m->away_points : $m->home_points;
			}
		}
		return $t;
	}
}
