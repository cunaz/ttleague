<?php
/**
 * Integrationstests: Gruppenzettel nachtragen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\RankingTournament;

use TTLeague\RankingTournament\GroupEntry;
use TTLeague\RankingTournament\Sheet;
use TTLeague\Results\Actor;
use TTLeague\Tests\Integration\Results\TriggerClock;

/**
 * Eine freigegebene Runde (21. bis 24. September 2026) mit einer Gruppe der Kategorie 1:
 * s1, w1, o1 und a1 in dieser Reihenfolge, Platzclub Wollishofen am Dienstag um 19:15,
 * Gruppenchef o1 (Oerlikon). Die Uhr steht auf Mittwoch, 23. September, 9 Uhr Ortszeit.
 */
final class GroupEntryTest extends RlTestCase {

	private int $round = 0;

	private int $group = 0;

	/** @var list<int> Spieler in der Reihenfolge des Gruppenzettels. */
	private array $members = array();

	protected function setUp(): void {
		parent::setUp();
		$this->prepare();
	}

	/**
	 * @param array<string,mixed> $rules Saisonregeln.
	 * @param list<string>        $keys  Mitglieder der Gruppe.
	 */
	private function prepare( array $rules = array(), array $keys = array( 's1', 'w1', 'o1', 'a1' ) ): void {
		$this->seed( $rules );
		$this->register( $keys );
		$this->round   = $this->round( '2026-09-21' );
		$this->members = array_map( fn( string $key ): int => $this->player[ $key ], $keys );
		$this->group   = $this->rounds()->save_group(
			$this->tk,
			$this->round,
			null,
			array(
				'category'        => 1,
				'venue_id'        => $this->id['venue_w'],
				'start_at'        => '2026-09-22 19:15',
				'members'         => $this->members,
				'chief_player_id' => $this->player['o1'],
			)
		);
		$this->rounds()->publish( $this->tk, $this->round );
		$this->clock->advance( '+22 days' );
		$this->clock->advance( '-3 hours' );
		$this->events->changed = array();
		$this->events->callups = array();
	}

	public function test_entry_delivers_players_order_and_rights(): void {
		$entry = $this->entry()->entry( $this->group, $this->actor( 'o1' ) );

		self::assertSame( 'published', $entry['group']['status'] );
		self::assertSame( array( 1, 1 ), array( $entry['group']['category'], $entry['group']['group_no'] ) );
		self::assertSame( 'Wollishofen', $entry['group']['host']['short'] );
		self::assertSame( 'Halle Wollishofen', $entry['group']['venue']['name'] );
		self::assertSame( '2026-09-22 19:15:00', $entry['group']['start_at'] );
		self::assertTrue( $entry['can']['enter'] );
		self::assertSame( $this->members, array_column( $entry['players'], 'player_id' ) );
		self::assertSame( array( 1, 2, 3, 4 ), array_column( $entry['players'], 'position' ) );
		self::assertSame( array( false, false, true, false ), array_column( $entry['players'], 'is_chief' ) );
		self::assertSame( array( 'scheduled' ), array_values( array_unique( array_column( $entry['players'], 'status' ) ) ) );
		// Vier Personen: sechs Partien, jede Paarung einmal.
		self::assertSame( Sheet::order( $this->members ), $entry['order'] );
		self::assertCount( 6, $entry['order'] );
		self::assertSame( array(), $entry['games'] );
		self::assertSame( array(), $entry['ranking'] );
		self::assertNull( $entry['entered_at'] );
		self::assertSame(
			array(
				'sets_to_win'        => 3,
				'set_points_enabled' => false,
			),
			$entry['rules']
		);
		self::assertNull( $entry['next_round'] );
		self::assertSame( 16, strlen( $entry['version'] ) );
	}

	public function test_chief_saves_the_sheet(): void {
		$chief  = $this->actor( 'o1' );
		$result = $this->entry()->save( $this->group, $this->sheet( $this->group ), $chief );

		// Position 1 gewinnt alles 3:0, dann 2, 3, 4.
		self::assertSame( $this->members, array_column( $result['ranking'], 'player_id' ) );
		self::assertSame( array( 1, 2, 3, 4 ), array_column( $result['ranking'], 'rank' ) );
		self::assertSame( array( 6.0, 5.0, 4.0, 3.0 ), array_column( $result['ranking'], 'points' ) );
		self::assertSame( array( 3, 2, 1, 0 ), array_column( $result['ranking'], 'wins' ) );
		self::assertSame( '2026-09-23T07:00:00Z', $result['entered_at'] );

		$group = $this->repo()->group( $this->group );
		self::assertSame( 100 + $this->player['o1'], $group['entered_by'] );
		self::assertSame( '2026-09-23 07:00:00', $group['entered_at'] );
		self::assertSame( array( 'played', 'played', 'played', 'played' ), array_column( $group['members'], 'status' ) );
		self::assertSame( array( 1, 2, 3, 4 ), array_column( $group['members'], 'rank_no' ) );
		self::assertSame( array( 6.0, 5.0, 4.0, 3.0 ), array_column( $group['members'], 'points' ) );

		// Partien stehen in game mit rl_group_id, in der Reihenfolge der Spielfolge, gespielt am Abend der Gruppe.
		$rows = $this->db->get_results( "SELECT fixture_id, sequence, type, a1_player_id, b1_player_id, sets_a, sets_b, status, counts_for_result, played_at FROM {$this->db->table( 'game' )} WHERE rl_group_id = %d ORDER BY sequence", array( $this->group ) );
		self::assertCount( 6, $rows );
		foreach ( Sheet::order( $this->members ) as $i => $game ) {
			self::assertSame(
				array(
					'fixture_id'        => null,
					'sequence'          => (string) $game['sequence'],
					'type'              => 'single',
					'a1_player_id'      => (string) $game['a'],
					'b1_player_id'      => (string) $game['b'],
					'sets_a'            => '3',
					'sets_b'            => '0',
					'status'            => 'played',
					'counts_for_result' => '1',
					'played_at'         => '2026-09-22 19:15:00',
				),
				$rows[ $i ]
			);
		}
		// Nach dem Speichern: Daten geändert, mit Partien (Wertungszahl neu rechnen).
		self::assertSame( array( array( 'rl_group', $this->group, true ) ), $this->events->changed );
		$audit = $this->audit( 'rl_group', $this->group );
		self::assertSame( 'entry_saved', end( $audit )['action'] );
		self::assertSame( 100 + $this->player['o1'], end( $audit )['user'] );
		self::assertCount( 6, end( $audit )['after']['games'] );
		self::assertSame( array(), end( $audit )['before']['games'] );

		$entry = $this->entry()->entry( $this->group, $chief );
		self::assertSame( 'Spieler1 Oerlikoner', $entry['entered_by'] );
		self::assertCount( 6, $entry['games'] );
		self::assertSame( $result['version'], $entry['version'] );
		self::assertSame( $result['ranking'], $entry['ranking'] );
	}

	public function test_only_chief_his_tk_chefs_and_the_tk_may_enter(): void {
		$season        = $this->id['season'];
		$chef_of_chief = $this->seeder->person( 'Vorsteher', 'Otto' );
		$chef_of_host  = $this->seeder->person( 'Vorsteherin', 'Wanda' );
		$this->seeder->role( $season, $this->id['club_o'], $chef_of_chief, 'tk_chef' );
		$this->seeder->role( $season, $this->id['club_w'], $chef_of_host, 'tk_chef' );
		$this->policy->reset();
		$service = $this->entry();
		$sheet   = $this->sheet( $this->group );

		// Mitspielende ohne Funktion, die TK-Chefin eines dritten Clubs, Unbeteiligte und Konten ohne Person: 403.
		foreach ( array( $this->actor( 's1' ), $this->actor( 'w1' ), $this->chef(), $this->nobody(), new Actor( 950, null, false ) ) as $actor ) {
			$this->assert_fails( 403, fn() => $service->entry( $this->group, $actor ) );
			$this->assert_fails( 403, fn() => $service->save( $this->group, $sheet, $actor ) );
		}
		self::assertNull( $this->repo()->group( $this->group )['entered_at'] );
		$this->assert_fails( 404, fn() => $service->entry( $this->group + 50, $this->actor( 'o1' ) ) );

		// Gruppenchef, TK-Chef seines Clubs, TK-Chefin des Platzclubs, TK.
		foreach ( array( $this->actor( 'o1' ), new Actor( 951, $chef_of_chief, false ), new Actor( 952, $chef_of_host, false ), $this->tk ) as $actor ) {
			self::assertTrue( $service->entry( $this->group, $actor )['can']['enter'] );
			$service->save( $this->group, $sheet, $actor );
			self::assertSame( $actor->user_id, $this->repo()->group( $this->group )['entered_by'] );
		}
	}

	public function test_round_status_decides_whether_the_sheet_is_open(): void {
		// Zweite Runde in Planung mit einer Gruppe: Der Gruppenchef sieht sie nicht, die TK kann nicht erfassen.
		$this->entry()->save( $this->group, $this->sheet( $this->group ), $this->tk );
		$this->rounds()->close( $this->tk, $this->round );
		$second  = $this->round( '2026-10-19' );
		$planned = $this->rounds()->save_group(
			$this->tk,
			$second,
			null,
			array(
				'category'        => 1,
				'members'         => $this->members,
				'chief_player_id' => $this->player['o1'],
				'start_at'        => '2026-10-20 19:15',
			)
		);
		$service = $this->entry();
		$this->assert_fails( 404, fn() => $service->entry( $planned, $this->actor( 'o1' ) ) );
		$this->assert_fails( 404, fn() => $service->save( $planned, $this->sheet( $planned ), $this->actor( 'o1' ) ) );
		self::assertFalse( $service->entry( $planned, $this->tk )['can']['enter'] );
		$this->assert_fails( 409, fn() => $service->save( $planned, $this->sheet( $planned ), $this->tk ) );

		// Abgeschlossene Runde: lesen ja, ändern nein, auch nicht die TK.
		$closed = $service->entry( $this->group, $this->actor( 'o1' ) );
		self::assertFalse( $closed['can']['enter'] );
		self::assertSame( 'closed', $closed['group']['status'] );
		$this->assert_fails( 409, fn() => $service->save( $this->group, $this->sheet( $this->group ), $this->actor( 'o1' ) ) );
		$this->assert_fails( 409, fn() => $service->save( $this->group, $this->sheet( $this->group ), $this->tk ) );
		$this->assert_fails( 409, fn() => $service->reset( $this->group, $this->tk ) );
		self::assertSame( 0, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'game' )} WHERE rl_group_id = %d", array( $planned ) ) );
	}

	public function test_absent_and_disqualified_players_have_no_games(): void {
		[ $s1, $w1, $o1, $a1 ] = $this->members;
		$statuses              = array(
			$w1 => 'absent',
			$a1 => 'disqualified',
		);
		$sheet                 = $this->sheet( $this->group, $statuses );
		self::assertCount( 1, $sheet['games'], 'Nur s1 gegen o1 wird gespielt.' );
		$result = $this->entry()->save( $this->group, $sheet, $this->actor( 'o1' ) );

		$by_player = array_column( $result['ranking'], null, 'player_id' );
		self::assertSame( array( 1, 6.0 ), array( $by_player[ $s1 ]['rank'], $by_player[ $s1 ]['points'] ) );
		self::assertSame( array( 2, 5.0 ), array( $by_player[ $o1 ]['rank'], $by_player[ $o1 ]['points'] ) );
		self::assertSame( array( null, null ), array( $by_player[ $w1 ]['rank'], $by_player[ $w1 ]['points'] ) );
		self::assertSame( array( null, null ), array( $by_player[ $a1 ]['rank'], $by_player[ $a1 ]['points'] ) );
		$members = array_column( $this->repo()->group( $this->group )['members'], null, 'player_id' );
		self::assertSame( array( 'played', 'absent', 'played', 'disqualified' ), array( $members[ $s1 ]['status'], $members[ $w1 ]['status'], $members[ $o1 ]['status'], $members[ $a1 ]['status'] ) );
		self::assertNull( $members[ $w1 ]['rank_no'] );
		self::assertNull( $members[ $a1 ]['points'] );
		self::assertSame( 1, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'game' )} WHERE rl_group_id = %d", array( $this->group ) ) );

		// Ein Resultat für eine abgemeldete Person ist ein Widerspruch.
		$order = Sheet::order( $this->members );
		$with  = null;
		foreach ( $order as $game ) {
			if ( in_array( $w1, array( $game['a'], $game['b'] ), true ) ) {
				$with = $game['sequence'];
				break;
			}
		}
		$sheet['games'][] = array(
			'sequence' => $with,
			'sets_a'   => 3,
			'sets_b'   => 1,
		);
		$error            = $this->assert_fails( 422, fn() => $this->entry()->save( $this->group, $sheet, $this->actor( 'o1' ) ) );
		self::assertSame( array( 'games.' . $with ), array_column( $error->errors, 'field' ) );
	}

	public function test_forfeit_keeps_played_games_without_counting_them(): void {
		[ $s1, $w1, $o1, $a1 ] = $this->members;
		$sheet                 = $this->sheet( $this->group, array( $a1 => 'forfeit' ) );
		// a1 hat gegen s1 noch gespielt (und 3:1 gewonnen), dann aufgegeben.
		$played = null;
		foreach ( Sheet::order( $this->members ) as $game ) {
			if ( array( $s1, $a1 ) === array( $game['a'], $game['b'] ) ) {
				$played = $game['sequence'];
			}
		}
		$sheet['games'][] = array(
			'sequence' => $played,
			'sets_a'   => 1,
			'sets_b'   => 3,
		);
		$result           = $this->entry()->save( $this->group, $sheet, $this->actor( 'o1' ) );

		$by_player = array_column( $result['ranking'], null, 'player_id' );
		// Die Partie gegen a1 zählt nicht: s1 bleibt mit zwei Siegen Erster.
		self::assertSame( array( 1, 2, 0 ), array( $by_player[ $s1 ]['rank'], $by_player[ $s1 ]['wins'], $by_player[ $s1 ]['losses'] ) );
		self::assertSame( array( 6, 0 ), array( $by_player[ $s1 ]['sets_won'], $by_player[ $s1 ]['sets_lost'] ) );
		self::assertSame( array( 6.0, 5.0, 4.0 ), array( $by_player[ $s1 ]['points'], $by_player[ $w1 ]['points'], $by_player[ $o1 ]['points'] ) );
		self::assertNull( $by_player[ $a1 ]['rank'] );
		self::assertNull( $by_player[ $a1 ]['points'] );
		$rows = $this->db->get_results( "SELECT sequence, counts_for_result FROM {$this->db->table( 'game' )} WHERE rl_group_id = %d ORDER BY sequence", array( $this->group ) );
		self::assertCount( 4, $rows );
		self::assertSame( array( (string) $played => '0' ), array_filter( array_column( $rows, 'counts_for_result', 'sequence' ), static fn( string $counts ): bool => '0' === $counts ) );
	}

	public function test_tie_shares_rank_and_points(): void {
		[ $s1, $w1, $o1, $a1 ] = $this->members;
		// s1 schlägt alle; w1 schlägt o1, o1 schlägt a1, a1 schlägt w1 (alles 3:0).
		$wins  = array(
			$s1 . '-' . $w1 => true,
			$s1 . '-' . $o1 => true,
			$s1 . '-' . $a1 => true,
			$w1 . '-' . $o1 => true,
			$o1 . '-' . $a1 => true,
			$w1 . '-' . $a1 => false,
		);
		$sheet = $this->sheet( $this->group );
		foreach ( Sheet::order( $this->members ) as $i => $game ) {
			$sheet['games'][ $i ]['sets_a'] = $wins[ $game['a'] . '-' . $game['b'] ] ? 3 : 0;
			$sheet['games'][ $i ]['sets_b'] = $wins[ $game['a'] . '-' . $game['b'] ] ? 0 : 3;
		}
		$result = $this->entry()->save( $this->group, $sheet, $this->actor( 'o1' ) );

		$by_player = array_column( $result['ranking'], null, 'player_id' );
		self::assertSame( array( 1, false, 6.0 ), array( $by_player[ $s1 ]['rank'], $by_player[ $s1 ]['shared'], $by_player[ $s1 ]['points'] ) );
		foreach ( array( $w1, $o1, $a1 ) as $id ) {
			self::assertSame( array( 2, true, 4.0 ), array( $by_player[ $id ]['rank'], $by_player[ $id ]['shared'], $by_player[ $id ]['points'] ), 'Plätze 2 bis 4 teilen 5 + 4 + 3 Punkte.' );
		}
		self::assertSame( array( 1, 2, 2, 2 ), array_column( $this->repo()->group( $this->group )['members'], 'rank_no' ) );
		self::assertSame( array( 6.0, 4.0, 4.0, 4.0 ), array_column( $this->repo()->group( $this->group )['members'], 'points' ) );
	}

	public function test_seven_players_get_the_bonus_point(): void {
		$this->prepare( array(), array( 's1', 's2', 'w1', 'w2', 'o1', 'o2', 'a1' ) );
		$result = $this->entry()->save( $this->group, $this->sheet( $this->group ), $this->actor( 'o1' ) );

		self::assertCount( 21, $this->repo()->group( $this->group )['games'] );
		self::assertSame( array( 7.0, 6.0, 5.0, 4.0, 3.0, 2.0, 1.0 ), array_column( $result['ranking'], 'points' ) );

		// Meldet sich eine Person ab, spielen sechs: kein Zusatzpunkt mehr.
		$result = $this->entry()->save( $this->group, $this->sheet( $this->group, array( $this->player['a1'] => 'absent' ) ), $this->actor( 'o1' ) );
		self::assertSame( array( 6.0, 5.0, 4.0, 3.0, 2.0, 1.0, null ), array_column( $result['ranking'], 'points' ) );
	}

	public function test_invalid_sheets_are_rejected_with_field_errors(): void {
		$service = $this->entry();
		$chief   = $this->actor( 'o1' );
		$valid   = $this->sheet( $this->group );
		$fields  = function ( array $sheet ) use ( $service, $chief ): array {
			$error = $this->assert_fails( 422, fn() => $service->save( $this->group, $sheet, $chief ) );
			return array_column( $error->errors, 'field' );
		};

		// Status fehlt oder ist unbekannt («aufgeboten» ist kein Ergebnis): genau ein Fehler zur
		// Person, keiner zu ihren Partien, auch wenn Resultate mitkommen.
		$sheet = $valid;
		unset( $sheet['players'][ $this->members[1] ] );
		self::assertSame( array( 'players.' . $this->members[1] ), $fields( $sheet ) );
		$sheet                                 = $valid;
		$sheet['players'][ $this->members[0] ] = 'scheduled';
		self::assertSame( array( 'players.' . $this->members[0] ), $fields( $sheet ) );
		// Fehlen zwei Status und ein Resultat zwischen Personen mit Status, kommen genau diese drei Fehler.
		$sheet = $valid;
		unset( $sheet['players'][ $this->members[0] ], $sheet['players'][ $this->members[1] ] );
		$between = null;
		foreach ( Sheet::order( $this->members ) as $i => $game ) {
			if ( array( $this->members[2], $this->members[3] ) === array( $game['a'], $game['b'] ) ) {
				$between = $game['sequence'];
				unset( $sheet['games'][ $i ] );
			}
		}
		self::assertSame( array( 'players.' . $this->members[0], 'players.' . $this->members[1], 'games.' . $between ), $fields( $sheet ) );
		// Fremde Person.
		$sheet                                   = $valid;
		$sheet['players'][ $this->player['s2'] ] = 'played';
		self::assertSame( array( 'players' ), $fields( $sheet ) );

		// Eine Partie fehlt.
		$sheet = $valid;
		array_pop( $sheet['games'] );
		self::assertSame( array( 'games.6' ), $fields( $sheet ) );
		// Ungültige Satzresultate bei drei Gewinnsätzen: 3:3, 2:1, 4:0, -1.
		foreach ( array( array( 3, 3 ), array( 2, 1 ), array( 4, 0 ), array( 3, -1 ), array( '3', 'x' ) ) as $sets ) {
			$sheet                       = $valid;
			$sheet['games'][2]['sets_a'] = $sets[0];
			$sheet['games'][2]['sets_b'] = $sets[1];
			self::assertSame( array( 'games.3' ), $fields( $sheet ), implode( ':', $sets ) );
		}
		// Unbekannte oder doppelte Nummer.
		$sheet            = $valid;
		$sheet['games'][] = array(
			'sequence' => 7,
			'sets_a'   => 3,
			'sets_b'   => 0,
		);
		self::assertSame( array( 'games' ), $fields( $sheet ) );
		$sheet            = $valid;
		$sheet['games'][] = $sheet['games'][0];
		self::assertSame( array( 'games' ), $fields( $sheet ) );

		// Nichts gespeichert.
		self::assertNull( $this->repo()->group( $this->group )['entered_at'] );
		self::assertSame( array(), $this->repo()->group( $this->group )['games'] );
		self::assertSame( array(), $this->events->changed );
		// Gültige Grenzfälle: 3:2 und 0:3; Zahlen als Text (Formular).
		$sheet                       = $valid;
		$sheet['games'][0]['sets_a'] = '3';
		$sheet['games'][0]['sets_b'] = '2';
		$sheet['games'][1]['sets_a'] = 0;
		$sheet['games'][1]['sets_b'] = 3;
		$service->save( $this->group, $sheet, $chief );
		self::assertSame( array( 3, 2 ), array( $this->repo()->group( $this->group )['games'][0]['sets_a'], $this->repo()->group( $this->group )['games'][0]['sets_b'] ) );
	}

	public function test_set_points_only_when_the_season_allows_them(): void {
		$sheet                           = $this->sheet( $this->group );
		$sheet['games'][0]['set_points'] = array( array( 11, 8 ), array( 12, 10 ), array( 11, 0 ) );
		// Ohne set_points_enabled werden Satzpunkte ignoriert, auch ungültige.
		$sheet['games'][1]['set_points'] = array( array( 5, 5 ) );
		$this->entry()->save( $this->group, $sheet, $this->actor( 'o1' ) );
		self::assertSame( 0, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'game_set' )}" ) );

		$this->prepare( array( 'set_points_enabled' => true ) );
		$sheet                           = $this->sheet( $this->group );
		$sheet['games'][0]['set_points'] = array( array( 11, 8 ), array( 12, 10 ), array( 11, 0 ) );
		$this->entry()->save( $this->group, $sheet, $this->actor( 'o1' ) );
		$games = $this->repo()->group( $this->group )['games'];
		self::assertSame( array( array( 11, 8 ), array( 12, 10 ), array( 11, 0 ) ), $games[0]['set_points'] );
		self::assertSame( array(), $games[1]['set_points'] );
		self::assertTrue( $this->entry()->entry( $this->group, $this->tk )['rules']['set_points_enabled'] );

		// Sätze, die das Resultat nicht ergeben, und unfertige Sätze.
		foreach ( array( array( array( 11, 8 ), array( 11, 9 ) ), array( array( 11, 8 ), array( 11, 9 ), array( 10, 8 ) ), array( array( 8, 11 ), array( 8, 11 ), array( 8, 11 ) ), 'x' ) as $points ) {
			$sheet['games'][0]['set_points'] = is_array( $points ) ? $points : array( $points );
			$error                           = $this->assert_fails( 422, fn() => $this->entry()->save( $this->group, $sheet, $this->actor( 'o1' ) ) );
			self::assertSame( array( 'games.1.set_points' ), array_column( $error->errors, 'field' ) );
		}
		self::assertSame( array( array( 11, 8 ), array( 12, 10 ), array( 11, 0 ) ), $this->repo()->group( $this->group )['games'][0]['set_points'] );
	}

	public function test_sheet_can_be_changed_until_the_round_is_closed(): void {
		$service = $this->entry();
		$chief   = $this->actor( 'o1' );
		$service->save( $this->group, $this->sheet( $this->group ), $chief );
		$first = array_column( $this->repo()->group( $this->group )['games'], 'id' );

		$this->clock->advance( '+1 hour' );
		$sheet                       = $this->sheet( $this->group );
		$sheet['games'][0]['sets_a'] = 0;
		$sheet['games'][0]['sets_b'] = 3;
		$service->save( $this->group, $sheet, $chief );
		$group = $this->repo()->group( $this->group );
		self::assertSame( '2026-09-23 08:00:00', $group['entered_at'] );
		self::assertCount( 6, $group['games'] );
		self::assertSame( array(), array_intersect( $first, array_column( $group['games'], 'id' ) ), 'Die Partien werden neu geschrieben.' );
		self::assertSame( array( 0, 3 ), array( $group['games'][0]['sets_a'], $group['games'][0]['sets_b'] ) );
		$actions = array_column( $this->audit( 'rl_group', $this->group ), 'action' );
		self::assertSame( array( 'entry_saved', 'entry_edited' ), array_slice( $actions, -2 ) );
		$audit = $this->audit( 'rl_group', $this->group );
		self::assertSame( '3:0', end( $audit )['before']['games'][0]['sets'] );
		self::assertSame( '0:3', end( $audit )['after']['games'][0]['sets'] );

		// Die TK setzt zurück: wieder «aufgeboten», ohne Partien. Der Gruppenchef kann das nicht.
		$this->assert_fails( 403, fn() => $service->reset( $this->group, $chief ) );
		$service->reset( $this->group, $this->tk );
		$group = $this->repo()->group( $this->group );
		self::assertSame( array(), $group['games'] );
		self::assertNull( $group['entered_at'] );
		self::assertSame( array( 'scheduled' ), array_values( array_unique( array_column( $group['members'], 'status' ) ) ) );
		self::assertSame( array( null, null, null, null ), array_column( $group['members'], 'points' ) );
	}

	public function test_stale_version_is_a_conflict(): void {
		$service = $this->entry();
		$chief   = $this->actor( 'o1' );
		$version = $service->entry( $this->group, $chief )['version'];

		// Die TK erfasst zuerst; der Gruppenchef schickt den Stand, den er gesehen hat.
		$saved = $service->save( $this->group, $this->sheet( $this->group ) + array( 'version' => $version ), $this->tk );
		self::assertNotSame( $version, $saved['version'] );
		$sheet                       = $this->sheet( $this->group );
		$sheet['games'][0]['sets_a'] = 0;
		$sheet['games'][0]['sets_b'] = 3;
		$this->assert_fails( 409, fn() => $service->save( $this->group, $sheet + array( 'version' => $version ), $chief ) );
		self::assertSame( 3, $this->repo()->group( $this->group )['games'][0]['sets_a'] );
		self::assertSame( 900, $this->repo()->group( $this->group )['entered_by'] );

		// Mit dem aktuellen Stand geht es.
		$service->save( $this->group, $sheet + array( 'version' => $saved['version'] ), $chief );
		self::assertSame( 0, $this->repo()->group( $this->group )['games'][0]['sets_a'] );
		// Auch eine Änderung an der Gruppe (neue Zeit) macht den gesehenen Stand ungültig.
		$version = $service->entry( $this->group, $chief )['version'];
		$this->rounds()->save_group( $this->tk, $this->round, $this->group, array( 'start_at' => '2026-09-24 19:15' ) );
		$this->assert_fails( 409, fn() => $service->save( $this->group, $sheet + array( 'version' => $version ), $chief ) );
	}

	public function test_change_between_check_and_write_is_a_conflict(): void {
		// Auch ohne mitgeschickte Version: Schliesst die TK die Runde ab, während der Gruppenchef speichert, gilt nur der Abschluss.
		$this->entry()->save( $this->group, $this->sheet( $this->group ), $this->tk );
		$clock                       = new TriggerClock( $this->clock );
		$clock->before_next          = function (): void {
			$this->rounds()->close( $this->tk, $this->round );
		};
		$sheet                       = $this->sheet( $this->group );
		$sheet['games'][0]['sets_a'] = 0;
		$sheet['games'][0]['sets_b'] = 3;
		$this->assert_fails( 409, fn() => $this->entry( $clock )->save( $this->group, $sheet, $this->actor( 'o1' ) ) );

		self::assertSame( 'closed', $this->repo()->round( $this->round )['status'] );
		self::assertSame( 3, $this->repo()->group( $this->group )['games'][0]['sets_a'], 'Das abgeschlossene Resultat bleibt.' );
	}

	public function test_sheet_collects_blocked_dates_for_the_next_round(): void {
		$next             = $this->round( '2026-10-19' );
		$service          = $this->entry();
		[ $s1, $w1, $o1 ] = $this->members;
		$entry            = $service->entry( $this->group, $this->actor( 'o1' ) );
		self::assertSame( $next, $entry['next_round']['id'] );
		self::assertSame( array( '2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22' ), $entry['next_round']['dates'] );
		self::assertSame( array(), $entry['next_round']['answered'] );

		$sheet                 = $this->sheet( $this->group );
		$sheet['next_blocked'] = array(
			$s1 => array( '2026-10-20', '2026-10-19' ),
			$w1 => array(),
		);
		$service->save( $this->group, $sheet, $this->actor( 'o1' ) );
		$persons = array( $this->seeder->person_of( $s1 ), $this->seeder->person_of( $w1 ), $this->seeder->person_of( $o1 ) );
		self::assertSame( array( $persons[0] => array( '2026-10-19', '2026-10-20' ) ), $this->repo()->blocked_dates( $persons, '2026-10-19', '2026-10-22' ) );
		// Gemeldet haben s1 und w1 («kein Abend gesperrt»), o1 nicht.
		$answered = $this->repo()->answered( $persons, '2026-10-19', '2026-10-22' );
		sort( $answered );
		self::assertSame( array( $persons[0], $persons[1] ), $answered );
		$entry = $service->entry( $this->group, $this->actor( 'o1' ) );
		self::assertSame( array( '2026-10-19', '2026-10-20' ), $entry['next_round']['blocked'][ $s1 ] );
		self::assertSame( array( $s1, $w1 ), $entry['next_round']['answered'] );

		// Ein Abend ausserhalb der nächsten Runde oder eine fremde Person: 422.
		$sheet['next_blocked'] = array( $s1 => array( '2026-10-23' ) );
		$error                 = $this->assert_fails( 422, fn() => $service->save( $this->group, $sheet, $this->actor( 'o1' ) ) );
		self::assertSame( array( 'next_blocked.' . $s1 ), array_column( $error->errors, 'field' ) );
		$sheet['next_blocked'] = array( $this->player['s2'] => array( '2026-10-20' ) );
		$this->assert_fails( 422, fn() => $service->save( $this->group, $sheet, $this->actor( 'o1' ) ) );
	}

	public function test_blocked_dates_are_not_stored_for_a_next_round_moved_between_check_and_write(): void {
		$next   = $this->round( '2026-10-19' );
		[ $s1 ] = $this->members;
		$person = $this->seeder->person_of( $s1 );
		$clock  = new TriggerClock( $this->clock );
		// Zwischen Prüfung und Schreiben verschiebt die TK die nächste Runde um eine Woche.
		$clock->before_next    = function () use ( $next ): void {
			$this->rounds()->reschedule( $this->tk, $next, '2026-10-26', '2026-10-29', false );
		};
		$sheet                 = $this->sheet( $this->group );
		$sheet['next_blocked'] = array( $s1 => array( '2026-10-20' ) );

		$this->assert_fails( 409, fn() => $this->entry( $clock )->save( $this->group, $sheet, $this->actor( 'o1' ) ) );

		self::assertSame( array(), $this->repo()->blocked_dates( array( $person ), '2026-10-19', '2026-10-29' ), 'weder für die alte noch für die neue Woche gespeichert' );
		self::assertSame( array(), $this->repo()->answered( array( $person ), '2026-10-26', '2026-10-29' ), 'niemand gilt für die neue Woche als gemeldet' );
		self::assertNull( $this->repo()->group( $this->group )['entered_at'], 'auch der Gruppenzettel ist nicht gespeichert' );
	}

	public function test_status_labels(): void {
		foreach ( array_merge( GroupEntry::STATUSES, array( 'scheduled' ) ) as $status ) {
			self::assertNotSame( $status, GroupEntry::status_label( $status ) );
		}
	}
}
