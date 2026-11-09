<?php
/**
 * Kleine erfundene Liga für die WordPress-Tests.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress;

use TTLeague\Domain\Match\GameOrder;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Tests\Integration\Frontend\Seeder;

/**
 * Aufgebaut mit dem Seeder der Integrationstests wie in ResultServiceTest: die Clubs Seefeld
 * (Heim) und Wollishofen (Gast) in einer Gruppe der aktiven Saison, je drei Stammspieler, die
 * Captains Hanna Heim und Gustav Gast, TK-Chefin Charlotte Chef (Seefeld) und Nina Niemand
 * ohne Funktion. Dazu ein Cup mit je einer Mannschaft und eine Begegnung der Vorsaison, in der
 * Hanna Heim ebenfalls Captain war. Die Personen haben noch kein Konto.
 */
final class League {

	/**
	 * IDs nach Schlüssel. Spieler: h1 bis h3 (Seefeld), a1 bis a3 (Wollishofen); Personen:
	 * captain_h1, captain_a1, chef_home, nobody und person_h1, person_h3, person_a1, person_a3
	 * (Personen der Spieler); season, club_home, club_away, team_h1, team_a1, entry_home,
	 * entry_away (Cup); Begegnungen f1 (Meisterschaft), cup und old_fixture (Vorsaison).
	 *
	 * @var array<string,int>
	 */
	public readonly array $id;

	/**
	 * @param string $scheduled_at Anspielzeit der Meisterschaftsbegegnung f1 (Ortszeit der Website).
	 */
	public function __construct( Db $db, string $scheduled_at ) {
		$s      = new Seeder( $db );
		$season = $s->season( '2026-27' );
		$home   = $s->club( 'Seefeld', 1 );
		$away   = $s->club( 'Wollishofen', 2 );
		$group  = $s->competition( $season, 'MM', 'Gruppe B1', 'B', 1 );
		$cup    = $s->competition( $season, 'CUP', 'Cup A', 'A' );
		$h1     = $s->team( $season, $home, 1, 'Seefeld 1', $group );
		$a1     = $s->team( $season, $away, 1, 'Wollishofen 1', $group );

		$players = array();
		foreach ( array( 'h1', 'h2', 'h3' ) as $i => $key ) {
			$players[ $key ] = $s->player( 'Seefelder', 'Spieler' . ( $i + 1 ) );
			$s->register( $players[ $key ], $season, $home, 10 - $i );
		}
		foreach ( array( 'a1', 'a2', 'a3' ) as $i => $key ) {
			$players[ $key ] = $s->player( 'Wollishofer', 'Spieler' . ( $i + 1 ) );
			$s->register( $players[ $key ], $season, $away, 10 - $i );
		}
		$s->roster( $h1, array( $players['h1'], $players['h2'], $players['h3'] ) );
		$s->roster( $a1, array( $players['a1'], $players['a2'], $players['a3'] ) );

		$people = array(
			'captain_h1' => $s->person( 'Heim', 'Hanna' ),
			'captain_a1' => $s->person( 'Gast', 'Gustav' ),
			'chef_home'  => $s->person( 'Chef', 'Charlotte' ),
			'nobody'     => $s->person( 'Niemand', 'Nina' ),
		);
		$s->role( $season, $home, $people['captain_h1'], 'captain', $h1 );
		$s->role( $season, $away, $people['captain_a1'], 'captain', $a1 );
		$s->role( $season, $home, $people['chef_home'], 'tk_chef' );

		$entries = array();
		foreach ( array(
			'entry_home' => array( $home, 'Seefeld 1', 'h1', 'h2' ),
			'entry_away' => array( $away, 'Wollishofen 1', 'a1', 'a2' ),
		) as $key => [ $club, $name, $first, $second ] ) {
			$entries[ $key ] = $db->insert(
				'cup_entry',
				array(
					'competition_id' => $cup,
					'club_id'        => $club,
					'number'         => 1,
					'name'           => $name,
					'player1_id'     => $players[ $first ],
					'player2_id'     => $players[ $second ],
				)
			);
		}

		// Vorsaison: Hanna Heim war Captain von Seefeld 1, die Begegnung ist nie erfasst worden.
		$old       = $s->season( '2025-26', 'archived' );
		$old_group = $s->competition( $old, 'MM', 'Gruppe B1', 'B', 1 );
		$old_h1    = $s->team( $old, $home, 1, 'Seefeld 1', $old_group );
		$old_a1    = $s->team( $old, $away, 1, 'Wollishofen 1', $old_group );
		$s->role( $old, $home, $people['captain_h1'], 'captain', $old_h1 );

		$this->id = $players + $people + $entries + array(
			'season'      => $season,
			'club_home'   => $home,
			'club_away'   => $away,
			'team_h1'     => $h1,
			'team_a1'     => $a1,
			'person_h1'   => $s->person_of( $players['h1'] ),
			'person_h3'   => $s->person_of( $players['h3'] ),
			'person_a1'   => $s->person_of( $players['a1'] ),
			'person_a3'   => $s->person_of( $players['a3'] ),
			'f1'          => $s->fixture(
				$group,
				array(
					'home_team_id' => $h1,
					'away_team_id' => $a1,
					'scheduled_at' => $scheduled_at,
				)
			),
			'cup'         => $s->fixture(
				$cup,
				array(
					'home_entry_id' => $entries['entry_home'],
					'away_entry_id' => $entries['entry_away'],
					'scheduled_at'  => $scheduled_at,
				)
			),
			'old_fixture' => $s->fixture(
				$old_group,
				array(
					'home_team_id' => $old_h1,
					'away_team_id' => $old_a1,
					'scheduled_at' => '2026-03-10 20:00:00',
				)
			),
		);
	}

	/**
	 * Erfassung von f1 in Standardaufstellung, Heim gewinnt 6:4 (Format wie POST result).
	 *
	 * @return array<string,mixed>
	 */
	public function six_four(): array {
		$home    = array(
			'A' => $this->id['h1'],
			'B' => $this->id['h2'],
			'C' => $this->id['h3'],
		);
		$away    = array(
			'X' => $this->id['a1'],
			'Y' => $this->id['a2'],
			'Z' => $this->id['a3'],
		);
		$results = array( '3:1', '3:0', '3:2', '1:3', '3:1', '0:3', '3:2', '2:3', '1:3', '3:0' );
		$games   = array();
		foreach ( GameOrder::team_match() as $i => $slot ) {
			[ $sets_home, $sets_away ] = array_map( 'intval', explode( ':', $results[ $i ] ) );
			$game                      = array(
				'sequence'  => $slot['sequence'],
				'status'    => 'played',
				'sets_home' => $sets_home,
				'sets_away' => $sets_away,
			);
			if ( GameOrder::DOUBLE === $slot['type'] ) {
				$game['home_players'] = array( $home['A'], $home['B'] );
				$game['away_players'] = array( $away['X'], $away['Y'] );
			}
			$games[] = $game;
		}
		return array(
			'lineup' => array(
				'home' => $home,
				'away' => $away,
			),
			'games'  => $games,
		);
	}
}
