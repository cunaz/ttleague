<?php
/**
 * Testdaten der Klassierung: erfundene Spieler mit Wertungszahl und gewerteten Spielen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Classification;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\Tests\Integration\Frontend\Seeder;

/**
 * Aktive Saison 2026/27 mit classification_min_games = 3 und archivierte Saison 2025/26, Clubs
 * Seefeld und Wollishofen. Gewertete Spiele (rating_event) der aktiven Saison: ein Einzel und
 * ein Doppel der Meisterschaft, ein Einzel im Cup, ein Einzel im Ranglistenturnier; dazu drei
 * Einzel der Vorsaison.
 *
 * | Spieler  | Club        | Klass. | Wertungszahl | Spiele 2026/27 | Vorschlag |
 * |----------|-------------|--------|--------------|----------------|-----------|
 * | ablage   | Seefeld     | 5      | 520          | 1              | 5 (zu wenige Spiele) |
 * | beispiel | Seefeld     | 10     | 1160         | 2 (+3 Vorsaison) | 10 (eines unter der Grenze) |
 * | muster   | Seefeld     | 6      | 812.5        | 3              | 8 (genau die Grenze) |
 * | entwurf  | Wollishofen | 4      | 380          | 1              | 4 (zu wenige Spiele) |
 * | probe    | Wollishofen | 9      | –            | 0              | 9 (ohne Wertungszahl) |
 * | vorlage  | Wollishofen | 7      | 640          | 3              | 6 |
 *
 * «kopie» war nur in der Vorsaison gemeldet (Wertungszahl 790) und erhält keine Zeile.
 */
final class Scenario {

	/** Schlüssel der gemeldeten Spieler in der Reihenfolge der Tabelle (Club, Name). */
	public const PLAYERS = array( 'ablage', 'beispiel', 'muster', 'entwurf', 'probe', 'vorlage' );

	/**
	 * IDs nach Schlüssel: season, old_season, club_seefeld, club_wollishofen und die Spieler.
	 *
	 * @var array<string,int>
	 */
	public readonly array $id;

	public readonly Seeder $seeder;

	/**
	 * @param array<string,mixed> $rules Regeln der aktiven Saison.
	 */
	public function __construct( private readonly Db $db, array $rules = array( 'classification_min_games' => 3 ) ) {
		$s            = new Seeder( $db );
		$this->seeder = $s;
		$old          = $s->season( '2025-26', 'archived' );
		$season       = $s->season( '2026-27', 'active', $rules );
		$seefeld      = $s->club( 'Seefeld', 1 );
		$wollishofen  = $s->club( 'Wollishofen', 2 );

		$p = array();
		foreach ( array(
			'muster'   => array( 'Muster', 'Hans', $seefeld, 6 ),
			'beispiel' => array( 'Beispiel', 'Anna', $seefeld, 10 ),
			'ablage'   => array( 'Ablage', 'Beat', $seefeld, 5 ),
			'vorlage'  => array( 'Vorlage', 'Sara', $wollishofen, 7 ),
			'entwurf'  => array( 'Entwurf', 'Petra', $wollishofen, 4 ),
			'probe'    => array( 'Probe', 'Lukas', $wollishofen, 9 ),
		) as $key => [ $last, $first, $club, $classification ] ) {
			$p[ $key ] = $s->player( $last, $first );
			$s->register( $p[ $key ], $season, $club, $classification );
		}
		$p['kopie'] = $s->player( 'Kopie', 'Lea' );
		$s->register( $p['kopie'], $old, $wollishofen, 8 );
		$s->register( $p['beispiel'], $old, $seefeld, 10 );

		$at     = '2026-10-06 20:00:00';
		$group  = $s->competition( $season, 'MM', 'Gruppe C1', 'C', 1 );
		$mm     = $s->fixture( $group, array( 'status' => 'final' ) );
		$single = $this->game( $mm, null, 1, array( $p['muster'], null ), array( $p['vorlage'], null ), $at );
		$double = $this->game( $mm, null, 7, array( $p['muster'], $p['beispiel'] ), array( $p['vorlage'], $p['entwurf'] ), $at );

		$cup      = $s->fixture( $s->competition( $season, 'CUP', 'Mannschaftscup C', 'C' ), array( 'status' => 'final' ) );
		$cup_game = $this->game( $cup, null, 1, array( $p['ablage'], null ), array( $p['vorlage'], null ), $at );

		$round   = $db->insert(
			'rl_round',
			array(
				'competition_id' => $s->competition( $season, 'RL', 'Ranglistenturnier' ),
				'round_no'       => 1,
			)
		);
		$rl      = $db->insert(
			'rl_group',
			array(
				'rl_round_id' => $round,
				'category'    => 1,
				'group_no'    => 1,
			)
		);
		$rl_game = $this->game( null, $rl, 1, array( $p['muster'], null ), array( $p['beispiel'], null ), $at );

		$before    = '2026-03-10 20:00:00';
		$old_mm    = $s->fixture( $s->competition( $old, 'MM', 'Gruppe C1', 'C', 1 ), array( 'status' => 'final' ) );
		$old_games = array();
		foreach ( array( 1, 2, 3 ) as $sequence ) {
			$old_games[] = $this->game( $old_mm, null, $sequence, array( $p['beispiel'], null ), array( $p['kopie'], null ), $before );
		}

		$s->rating( $p['muster'], 800.0, array( array( $single, 5.0, $at ), array( $double, 2.5, $at ), array( $rl_game, 5.0, $at ) ) );
		$s->rating( $p['vorlage'], 660.0, array( array( $single, -5.0, $at ), array( $double, -2.5, $at ), array( $cup_game, -12.5, $at ) ) );
		$s->rating(
			$p['beispiel'],
			1150.0,
			array(
				array( $old_games[0], 4.0, $before ),
				array( $old_games[1], 4.0, $before ),
				array( $old_games[2], 4.5, $before ),
				array( $double, 2.5, $at ),
				array( $rl_game, -5.0, $at ),
			)
		);
		$s->rating( $p['entwurf'], 382.5, array( array( $double, -2.5, $at ) ) );
		$s->rating( $p['ablage'], 507.5, array( array( $cup_game, 12.5, $at ) ) );
		$s->rating( $p['kopie'], 802.5, array( array( $old_games[0], -4.0, $before ), array( $old_games[1], -4.0, $before ), array( $old_games[2], -4.5, $before ) ) );

		$this->id = $p + array(
			'season'           => $season,
			'old_season'       => $old,
			'club_seefeld'     => $seefeld,
			'club_wollishofen' => $wollishofen,
		);
	}

	/**
	 * Gespieltes Einzel oder Doppel einer Begegnung oder einer Gruppe des Ranglistenturniers.
	 *
	 * @param array{0:int,1:int|null} $side_a Spieler der Seite A.
	 * @param array{0:int,1:int|null} $side_b Spieler der Seite B.
	 */
	public function game( ?int $fixture_id, ?int $group_id, int $sequence, array $side_a, array $side_b, string $played_at ): int {
		return $this->db->insert(
			'game',
			array(
				'fixture_id'   => $fixture_id,
				'rl_group_id'  => $group_id,
				'sequence'     => $sequence,
				'type'         => null === $side_a[1] ? 'single' : 'double',
				'a1_player_id' => $side_a[0],
				'a2_player_id' => $side_a[1],
				'b1_player_id' => $side_b[0],
				'b2_player_id' => $side_b[1],
				'sets_a'       => 3,
				'sets_b'       => 1,
				'status'       => 'played',
				'played_at'    => $played_at,
			)
		);
	}
}
