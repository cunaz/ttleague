<?php
/**
 * Ranglistenturnier: Runden, Kategorien, Gruppen, Gesamtrangliste und Finalqualifikation.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\ReadModel;

use TTLeague\Domain\Ranking\FinalQualification;
use TTLeague\Domain\Ranking\GroupRanking;
use TTLeague\Domain\Ranking\RlGame;
use TTLeague\Domain\Ranking\RlPlayerStatus;
use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\ReadModel\Cache\Cache;
use TTLeague\ReadModel\Support\Names;
use TTLeague\ReadModel\Support\Sql;

/**
 * Siege und Sätze einer Gruppe rechnet GroupRanking aus den Partien. Rang und Punkte
 * kommen aus rl_group_player (offiziell; bei Gleichstand teilt die TK Punkte, z. B. 5.5);
 * fehlen sie, gelten die mit GroupRanking berechneten Werte. Die Gesamtrangliste addiert
 * die gespeicherten Punkte aller regulären Runden pro Kategorie (float) und ordnet mit
 * FinalQualification. Regulär ist jede Runde, die nicht die Finalrunde ist (is_final); feste
 * Rundennummern kennt das Lesemodell nicht. Dieselbe Regel gilt im Dienst
 * (RankingTournament\Standings) für Export und Finalqualifikation.
 *
 * «regular_rounds» nennt die Nummern der regulären Runden für die Spalten der Gesamtrangliste:
 * die vorhandenen, aufgefüllt bis zur Zahl der Runden laut Reglement (competition.format_json
 * «regular_rounds», Standard REGULAR_ROUNDS). So zeigt eine laufende Saison von Anfang an alle
 * Spalten.
 *
 * Runden mit Status «planned» sind ein Vorschlag der TK und fehlen hier ganz: weder die Runde
 * noch ihre Gruppen, Personen oder Partien erscheinen. Freigegebene Runden («published»)
 * zeigen das Aufgebot, abgeschlossene und importierte («closed») das Resultat.
 */
final class RankingTournament {

	/** Reguläre Runden laut Reglement, wenn competition.format_json nichts anderes sagt. */
	public const REGULAR_ROUNDS = 3;

	public function __construct( private readonly Db $db, private readonly Cache $cache, private readonly Seasons $seasons ) {}

	/**
	 * @return array{competition:array{id:int,name:string},rounds:list<array<string,mixed>>,regular_rounds:list<int>,overall:array<int,list<array<string,mixed>>>,finalists:int}|null
	 */
	public function get( int $season_id ): ?array {
		return $this->cache->remember(
			// v3: mit regular_rounds und «entered» je Gruppe; ältere Einträge ohne diese Schlüssel dürfen nicht mehr gelten.
			'rl_season_v3',
			array( $season_id ),
			fn(): ?array => $this->load( $season_id )
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function load( int $season_id ): ?array {
		$competition = $this->db->get_row(
			"SELECT id, name, format_json FROM {$this->db->table( 'competition' )} WHERE season_id = %d AND type = 'RL' ORDER BY id LIMIT 1",
			array( $season_id )
		);
		if ( null === $competition ) {
			return null;
		}
		$competition_id = (int) $competition['id'];
		$season         = $this->seasons->by_id( $season_id );
		$rules          = null === $season ? new SeasonRules() : $this->seasons->rules( $season );

		$rounds = array();
		foreach ( $this->db->get_results(
			"SELECT id, round_no, date_from, date_to, is_final, status FROM {$this->db->table( 'rl_round' )} WHERE competition_id = %d AND status <> 'planned' ORDER BY round_no",
			array( $competition_id )
		) as $row ) {
			$rounds[ (int) $row['id'] ] = array(
				'id'         => (int) $row['id'],
				'round_no'   => (int) $row['round_no'],
				'is_final'   => '1' === (string) $row['is_final'],
				'status'     => (string) $row['status'],
				'date_from'  => $row['date_from'],
				'date_to'    => $row['date_to'],
				'categories' => array(),
			);
		}

		$groups = array();
		foreach ( $this->db->get_results(
			"SELECT rg.id, rg.rl_round_id, rg.category, rg.group_no, rg.start_at, rg.note, rg.chief_player_id, rg.entered_at,
				c.short_name AS host_short, c.slug AS host_slug, v.name AS venue_name, v.street, v.zip, v.city
			FROM {$this->db->table( 'rl_group' )} rg
			JOIN {$this->db->table( 'rl_round' )} rr ON rr.id = rg.rl_round_id
			LEFT JOIN {$this->db->table( 'club' )} c ON c.id = rg.host_club_id
			LEFT JOIN {$this->db->table( 'venue' )} v ON v.id = rg.venue_id
			WHERE rr.competition_id = %d AND rr.status <> 'planned'
			ORDER BY rr.round_no, rg.category, rg.group_no",
			array( $competition_id )
		) as $row ) {
			$groups[ (int) $row['id'] ] = array(
				'id'        => (int) $row['id'],
				'round_id'  => (int) $row['rl_round_id'],
				'category'  => (int) $row['category'],
				'group_no'  => (int) $row['group_no'],
				'start_at'  => $row['start_at'],
				'note'      => $row['note'],
				'chief_id'  => Sql::int_or_null( $row['chief_player_id'] ),
				'chief'     => null,
				'host'      => null === $row['host_short'] ? null : array(
					'short' => (string) $row['host_short'],
					'slug'  => (string) $row['host_slug'],
				),
				'venue'     => null === $row['venue_name'] ? null : array(
					'name'   => (string) $row['venue_name'],
					'street' => $row['street'],
					'zip'    => $row['zip'],
					'city'   => $row['city'],
				),
				'players'   => array(),
				'games'     => array(),
				'has_games' => false,
				// Erfasst ist eine Gruppe mit Gruppenzettel oder mit Partien (ältere Daten ohne Zeitpunkt).
				'entered'   => null !== $row['entered_at'],
			);
		}

		$members = array();
		foreach ( $this->db->get_results(
			"SELECT gp.rl_group_id, gp.player_id, gp.status, gp.rank_no, gp.points, p.license_no, pe.first_name, pe.last_name,
				ps.classification, c.short_name AS club_short, c.slug AS club_slug
			FROM {$this->db->table( 'rl_group_player' )} gp
			JOIN {$this->db->table( 'rl_group' )} rg ON rg.id = gp.rl_group_id
			JOIN {$this->db->table( 'rl_round' )} rr ON rr.id = rg.rl_round_id
			JOIN {$this->db->table( 'player' )} p ON p.id = gp.player_id
			JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			LEFT JOIN {$this->db->table( 'player_season' )} ps ON ps.player_id = gp.player_id AND ps.season_id = %d
			LEFT JOIN {$this->db->table( 'club' )} c ON c.id = ps.club_id
			WHERE rr.competition_id = %d AND rr.status <> 'planned'
			ORDER BY gp.rl_group_id, gp.rank_no IS NULL, gp.rank_no, pe.last_name",
			array( $season_id, $competition_id )
		) as $row ) {
			$members[ (int) $row['rl_group_id'] ][ (int) $row['player_id'] ] = array(
				'player'         => Names::player( $row ),
				'classification' => Sql::int_or_null( $row['classification'] ),
				'club_short'     => $row['club_short'],
				'club_slug'      => $row['club_slug'],
				'status'         => (string) $row['status'],
				'stored_rank'    => Sql::int_or_null( $row['rank_no'] ),
				'stored_points'  => null === $row['points'] ? null : (float) $row['points'],
			);
		}

		$games = array();
		foreach ( $this->db->get_results(
			"SELECT g.rl_group_id, g.sequence, g.a1_player_id, g.b1_player_id, g.sets_a, g.sets_b, g.status
			FROM {$this->db->table( 'game' )} g
			JOIN {$this->db->table( 'rl_group' )} rg ON rg.id = g.rl_group_id
			JOIN {$this->db->table( 'rl_round' )} rr ON rr.id = rg.rl_round_id
			WHERE rr.competition_id = %d AND rr.status <> 'planned'
			ORDER BY g.rl_group_id, g.sequence, g.id",
			array( $competition_id )
		) as $row ) {
			$games[ (int) $row['rl_group_id'] ][] = $row;
		}

		$ranking = new GroupRanking( $rules );
		foreach ( $groups as $id => &$group ) {
			$group_members    = $members[ $id ] ?? array();
			$group_games      = $games[ $id ] ?? array();
			$group['players'] = $this->rank_group( $ranking, $group_members, $group_games );
			foreach ( $group_games as $g ) {
				$a = Sql::int_or_null( $g['a1_player_id'] );
				$b = Sql::int_or_null( $g['b1_player_id'] );
				if ( null === $a || null === $b || ! isset( $group_members[ $a ], $group_members[ $b ] ) ) {
					continue;
				}
				$sets_a           = (int) $g['sets_a'];
				$sets_b           = (int) $g['sets_b'];
				$group['games'][] = array(
					'a'      => $group_members[ $a ]['player'],
					'b'      => $group_members[ $b ]['player'],
					'sets_a' => $sets_a,
					'sets_b' => $sets_b,
					'status' => (string) $g['status'],
					'winner' => $sets_a === $sets_b ? null : ( $sets_a > $sets_b ? 'a' : 'b' ),
				);
			}
			$group['has_games'] = array() !== $group['games'];
			$group['entered']   = $group['entered'] || $group['has_games'];
			if ( null !== $group['chief_id'] && isset( $group_members[ $group['chief_id'] ] ) ) {
				$group['chief'] = $group_members[ $group['chief_id'] ]['player'];
			}
			if ( isset( $rounds[ $group['round_id'] ] ) ) {
				$rounds[ $group['round_id'] ]['categories'][ $group['category'] ][] = $group;
			}
		}
		unset( $group );

		foreach ( $rounds as &$round ) {
			ksort( $round['categories'] );
		}
		unset( $round );

		return array(
			'competition'    => array(
				'id'   => $competition_id,
				'name' => (string) $competition['name'],
			),
			'rounds'         => array_values( $rounds ),
			'regular_rounds' => self::regular_rounds( array_values( $rounds ), json_decode( (string) $competition['format_json'], true ) ),
			'overall'        => $this->overall( array_values( $rounds ), $rules ),
			'finalists'      => $rules->rl_finalists,
		);
	}

	/**
	 * Nummern der regulären Runden für die Spalten der Gesamtrangliste: von 1 bis zur höchsten
	 * vorhandenen regulären Runde, ohne die Nummer der Finalrunde. Solange keine Finalrunde
	 * öffentlich ist, mindestens bis zur Zahl der Runden laut Reglement.
	 *
	 * @param list<array<string,mixed>> $rounds Öffentliche Runden.
	 * @param mixed                     $format Inhalt von competition.format_json.
	 * @return list<int>
	 */
	private static function regular_rounds( array $rounds, mixed $format ): array {
		$planned = is_array( $format ) && is_numeric( $format['regular_rounds'] ?? null ) && (int) $format['regular_rounds'] >= 1 ? (int) $format['regular_rounds'] : self::REGULAR_ROUNDS;
		$highest = 0;
		$final   = null;
		foreach ( $rounds as $round ) {
			if ( $round['is_final'] ) {
				$final = $round['round_no'];
			} else {
				$highest = max( $highest, $round['round_no'] );
			}
		}
		$numbers = array();
		// Mit einer Finalrunde ist die Reihe der regulären Runden abgeschlossen: keine Spalte für
		// eine Runde, die es laut Reglement gäbe, die aber nie stattfand.
		$last = null === $final ? max( $planned, $highest ) : $highest;
		for ( $number = 1; $number <= $last; $number++ ) {
			if ( $number !== $final ) {
				$numbers[] = $number;
			}
		}
		return $numbers;
	}

	/**
	 * Rangiert eine Gruppe mit GroupRanking; gespeicherte Ränge und Punkte haben Vorrang.
	 *
	 * @param array<int,array<string,mixed>> $members Spieler der Gruppe.
	 * @param list<array<string,string|null>> $games  Partien.
	 * @return list<array<string,mixed>>
	 */
	private function rank_group( GroupRanking $ranking, array $members, array $games ): array {
		if ( array() === $members ) {
			return array();
		}
		$statuses = array();
		foreach ( $members as $player_id => $member ) {
			$statuses[ $player_id ] = RlPlayerStatus::tryFrom( $member['status'] ) ?? RlPlayerStatus::Played;
		}
		$rl_games = array();
		foreach ( $games as $g ) {
			$a      = Sql::int_or_null( $g['a1_player_id'] );
			$b      = Sql::int_or_null( $g['b1_player_id'] );
			$sets_a = (int) $g['sets_a'];
			$sets_b = (int) $g['sets_b'];
			if ( null === $a || null === $b || $a === $b || $sets_a === $sets_b || ! isset( $members[ $a ], $members[ $b ] ) ) {
				continue;
			}
			$rl_games[] = new RlGame( $a, $b, $sets_a, $sets_b );
		}

		$computed = array();
		try {
			foreach ( $ranking->rank( $statuses, $rl_games ) as $row ) {
				$computed[ (int) $row->player ] = $row;
			}
		} catch ( \InvalidArgumentException ) {
			$computed = array();
		}

		$has_games = array() !== $rl_games;
		$players   = array();
		foreach ( $members as $player_id => $member ) {
			$row       = $computed[ $player_id ] ?? null;
			$players[] = array(
				'player'         => $member['player'],
				'classification' => $member['classification'],
				'club_short'     => $member['club_short'],
				'club_slug'      => $member['club_slug'],
				'status'         => $member['status'],
				'rank'           => $member['stored_rank'] ?? ( $has_games && null !== $row ? $row->rank : null ),
				'shared'         => null !== $row && $row->shared_rank,
				'points'         => $member['stored_points'] ?? ( $has_games && null !== $row && $row->status->is_ranked() ? (float) $row->round_points : null ),
				'wins'           => null === $row ? 0 : $row->wins,
				'losses'         => null === $row ? 0 : $row->losses,
				'sets_won'       => null === $row ? 0 : $row->sets_won,
				'sets_lost'      => null === $row ? 0 : $row->sets_lost,
			);
		}
		usort(
			$players,
			static function ( array $a, array $b ): int {
				$a_none = null === $a['rank'];
				$b_none = null === $b['rank'];
				if ( $a_none !== $b_none ) {
					return $a_none ? 1 : -1;
				}
				if ( ! $a_none && $a['rank'] !== $b['rank'] ) {
					return $a['rank'] <=> $b['rank'];
				}
				return strcmp( $a['player']['last_name'] . ' ' . $a['player']['first_name'], $b['player']['last_name'] . ' ' . $b['player']['first_name'] );
			}
		);
		return $players;
	}

	/**
	 * Gesamtrangliste pro Kategorie über alle regulären Runden (jede Runde ausser der Finalrunde).
	 *
	 * @param list<array<string,mixed>> $rounds Runden mit Gruppen.
	 * @return array<int,list<array<string,mixed>>>
	 */
	private function overall( array $rounds, SeasonRules $rules ): array {
		$totals  = array();
		$players = array();
		$detail  = array();
		foreach ( $rounds as $round ) {
			if ( $round['is_final'] ) {
				continue;
			}
			foreach ( $round['categories'] as $category => $groups ) {
				foreach ( $groups as $group ) {
					// Auch ein Gruppenzettel ohne Partien zählt, etwa wenn nur eine Person angetreten ist.
					if ( ! $group['entered'] ) {
						continue;
					}
					foreach ( $group['players'] as $member ) {
						$id = $member['player']['id'];
						if ( ! isset( $totals[ $category ][ $id ] ) ) {
							$totals[ $category ][ $id ]  = array(
								'points'    => 0.0,
								'sets_won'  => 0,
								'sets_lost' => 0,
							);
							$players[ $category ][ $id ] = $member;
							$detail[ $category ][ $id ]  = array();
						}
						$points                                   = (float) ( $member['points'] ?? 0.0 );
						$totals[ $category ][ $id ]['points']    += $points;
						$totals[ $category ][ $id ]['sets_won']  += $member['sets_won'];
						$totals[ $category ][ $id ]['sets_lost'] += $member['sets_lost'];
						$detail[ $category ][ $id ][ $round['round_no'] ] = 'played' === $member['status'] ? $points : null;
					}
				}
			}
		}

		$qualification = new FinalQualification( $rules );
		$overall       = array();
		ksort( $totals );
		foreach ( $totals as $category => $category_totals ) {
			foreach ( $qualification->order( $category_totals ) as $row ) {
				$id                     = (int) $row['player'];
				$overall[ $category ][] = array(
					'player'         => $players[ $category ][ $id ]['player'],
					'classification' => $players[ $category ][ $id ]['classification'],
					'club_short'     => $players[ $category ][ $id ]['club_short'],
					'club_slug'      => $players[ $category ][ $id ]['club_slug'],
					'rank'           => $row['rank'],
					'points'         => $row['points'],
					'needs_draw'     => $row['needs_draw'],
					'qualified'      => $row['qualified'],
					'rounds'         => $detail[ $category ][ $id ],
					'sets_won'       => $category_totals[ $id ]['sets_won'],
					'sets_lost'      => $category_totals[ $id ]['sets_lost'],
				);
			}
		}
		return $overall;
	}
}
