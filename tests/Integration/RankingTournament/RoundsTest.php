<?php
/**
 * Integrationstests: Runden einteilen, Gruppen ändern, freigeben, abschliessen, Final.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\RankingTournament;

use TTLeague\RankingTournament\Rounds;
use TTLeague\RankingTournament\Standings;

final class RoundsTest extends RlTestCase {

	public function test_new_round_is_planned_and_numbered(): void {
		$first = $this->round( '2026-09-21' );
		$row   = $this->repo()->round( $first );

		// Der Spaltenstandard ist «closed» (Altdaten); der Dienst setzt «planned».
		self::assertSame( 'planned', $row['status'] );
		self::assertSame( 1, $row['round_no'] );
		self::assertSame( array( '2026-09-21', '2026-09-24' ), array( $row['date_from'], $row['date_to'] ) );
		self::assertSame( 2, $this->repo()->round( $this->round( '2026-10-19' ) )['round_no'] );
		self::assertSame( 'created', $this->audit( 'rl_round', $first )[0]['action'] );

		$rounds = $this->rounds();
		$this->assert_fails( 403, fn() => $rounds->create( $this->chef(), $this->id['competition'], '2026-11-16', '2026-11-19' ) );
		$this->assert_fails( 404, fn() => $rounds->create( $this->tk, $this->id['competition'] + 9, '2026-11-16', '2026-11-19' ) );
		$this->assert_fails( 422, fn() => $rounds->create( $this->tk, $this->id['competition'], '2026-11-19', '2026-11-16' ) );
		$this->assert_fails( 422, fn() => $rounds->create( $this->tk, $this->id['competition'], '16.11.2026', '2026-11-19' ) );
		// Die nächste Runde beginnt nach dem Ende der vorherigen (22. Oktober): der Tag selbst geht nicht.
		$this->assert_fails( 422, fn() => $rounds->create( $this->tk, $this->id['competition'], '2026-10-22', '2026-10-25' ) );
		$final = $rounds->create( $this->tk, $this->id['competition'], '2026-10-23', '2026-10-25', true );
		self::assertTrue( $this->repo()->round( $final )['is_final'] );
		// Die Finalrunde ist die letzte Runde: danach weder eine reguläre noch eine zweite Finalrunde.
		$this->assert_fails( 409, fn() => $rounds->create( $this->tk, $this->id['competition'], '2026-12-07', '2026-12-10' ) );
		$this->assert_fails( 409, fn() => $rounds->create( $this->tk, $this->id['competition'], '2026-12-07', '2026-12-10', true ) );
		self::assertCount( 3, $this->repo()->rounds( $this->id['competition'] ) );
		// Auch nachträglich wird keine frühere Runde zur Finalrunde.
		$this->assert_fails( 422, fn() => $rounds->reschedule( $this->tk, $first, '2026-09-21', '2026-09-24', true ) );
		self::assertSame( array( false, false, true ), array_column( $this->repo()->rounds( $this->id['competition'] ), 'is_final' ) );
	}

	public function test_four_regular_rounds_count_the_same_in_service_and_public_view(): void {
		// Regulär ist jede Runde vor der Finalrunde, auch eine vierte. In der vierten Runde gewinnt
		// die Person an Position 4 alles: Wer sie nicht zählt, erhält eine andere Rangliste.
		$this->seed( array( 'rl_finalists' => 2 ) );
		$this->register( array( 's1', 's2', 'w1', 'w2' ) );
		$rounds  = $this->rounds();
		$members = array();
		foreach ( array( '2026-09-21', '2026-10-19', '2026-11-16', '2026-12-14' ) as $number => $monday ) {
			$round = $this->round( $monday );
			self::assertSame( $number + 1, $this->repo()->round( $round )['round_no'] );
			$rounds->propose( $this->tk, $round );
			$rounds->publish( $this->tk, $round );
			$group = $this->repo()->groups( $round )[0];
			$sheet = $this->sheet( $group['id'] );
			if ( 3 === $number ) {
				$members = array_column( $group['members'], 'player_id' );
				foreach ( \TTLeague\RankingTournament\Sheet::order( $members ) as $i => $game ) {
					// Die erstgenannte Person hat die kleinere Position und gewinnt, ausser gegen Position 4.
					$wins                           = $game['b'] !== $members[3];
					$sheet['games'][ $i ]['sets_a'] = $wins ? 3 : 0;
					$sheet['games'][ $i ]['sets_b'] = $wins ? 0 : 3;
				}
			}
			$this->entry()->save( $group['id'], $sheet, $this->tk );
			$rounds->close( $this->tk, $round );
		}

		$service = ( new Standings( $this->repo() ) )->overall( $this->repo()->competition( $this->id['competition'] ) )[1];
		$model   = new \TTLeague\ReadModel\ReadModel( $this->db, new \TTLeague\ReadModel\Cache\NullCache() );
		$public  = $model->ranking->get( $this->id['season'] );
		self::assertSame( array( 1, 2, 3, 4 ), $public['regular_rounds'] );
		self::assertSame( array( 1, 2, 3, 4 ), array_keys( $service[0]['rounds'] ), 'Der Dienst zählt die vierte Runde.' );
		$columns = static fn( array $rows ): array => array_map(
			static fn( array $row ): array => array(
				'player'    => $row['player_id'] ?? $row['player']['id'],
				'rank'      => $row['rank'],
				'points'    => (float) $row['points'],
				'rounds'    => array_map( 'floatval', $row['rounds'] ),
				'sets'      => $row['sets_won'] . ':' . $row['sets_lost'],
				'qualified' => $row['qualified'],
			),
			$rows
		);
		self::assertSame( $columns( $service ), $columns( $public['overall'][1] ), 'Dienst (Export, Finalqualifikation) und öffentliche Rangliste stimmen überein.' );
		// Die Person an Position 4 hat mit 3 + 3 + 3 + 6 = 15 Punkten die an Position 3 (4 + 4 + 4 + 3) eingeholt.
		$points = array_column( $service, 'points', 'player_id' );
		self::assertSame( 15.0, $points[ $members[3] ] );
		self::assertSame( 15.0, $points[ $members[2] ] );

		// Auch die Ansicht zeigt vier Runden, und die Finalrunde nimmt die Besten aus allen vier.
		$html = ( new \TTLeague\Frontend\Views\RankingView( $model, new \TTLeague\Frontend\Support\Urls( 'https://liga.example/' ) ) )->render( array() );
		self::assertStringContainsString( 'Gesamtrangliste nach 4 Runden', $html );
		self::assertStringContainsString( '>R4<', $html );
		$final = $this->round( '2027-01-18', true );
		$rounds->propose( $this->tk, $final );
		$finalists = array_column( $this->repo()->groups( $final )[0]['members'], 'player_id' );
		sort( $finalists );
		$expected = array_column( array_slice( $service, 0, 2 ), 'player_id' );
		sort( $expected );
		self::assertSame( $expected, $finalists );
	}

	public function test_proposal_is_saved_as_planned_groups(): void {
		$this->register( $this->everyone() );
		$this->tables( 6 );
		$round  = $this->round( '2026-09-21' );
		$result = $this->rounds()->propose( $this->tk, $round );

		// Je Kategorie acht Angemeldete: zwei Vierergruppen.
		self::assertSame( 6, $result['groups'] );
		self::assertSame( array(), $result['unassigned'] );
		self::assertSame( 0, $result['repeats'] );
		$groups = $this->repo()->groups( $round );
		self::assertSame( array( 1, 1, 2, 2, 3, 3 ), array_column( $groups, 'category' ) );
		self::assertSame( array( 1, 2, 1, 2, 1, 2 ), array_column( $groups, 'group_no' ) );
		$slots = array();
		foreach ( $groups as $group ) {
			self::assertCount( 4, $group['members'] );
			self::assertSame( array( 'scheduled' ), array_values( array_unique( array_column( $group['members'], 'status' ) ) ) );
			self::assertNull( $group['members'][0]['rank_no'] );
			self::assertNull( $group['members'][0]['points'] );
			self::assertNull( $group['entered_at'] );
			// Abend am Spieltag des Lokals, Beginn zur Öffnungszeit; das Lokal gehört dem Platzclub.
			self::assertSame( '19:15:00', substr( (string) $group['start_at'], 11 ) );
			$weekday = (int) ( new \DateTimeImmutable( (string) $group['start_at'] ) )->format( 'N' );
			$key     = array_search(
				$group['host_club_id'],
				array(
					1 => $this->id['club_s'],
					2 => $this->id['club_w'],
					3 => $this->id['club_o'],
					4 => $this->id['club_a'],
				),
				true
			);
			self::assertSame( $weekday, $key );
			self::assertSame( $this->id[ 'venue_' . array_keys( self::CLUBS )[ $weekday - 1 ] ], $group['venue_id'] );
			$slots[] = $group['venue_id'];
			// Gruppenchef: die erstgenannte Person des Platzclubs.
			$hosts = array_values( array_filter( $group['members'], static fn( array $m ): bool => $m['club_id'] === $group['host_club_id'] ) );
			self::assertSame( $hosts[0]['player_id'], $group['chief_player_id'] );
		}
		self::assertSame( 'planned', $this->repo()->round( $round )['status'] );
		self::assertSame( array(), $this->events->callups, 'Vor der Freigabe geht kein Aufgebot hinaus.' );
		self::assertSame( array(), $this->rounds()->unassigned( $round ) );

		// Ein zweiter Lauf ersetzt den Vorschlag und kommt zum selben Ergebnis.
		$members = array_map( static fn( array $g ): array => array_column( $g['members'], 'player_id' ), $groups );
		$this->rounds()->propose( $this->tk, $round );
		$again = $this->repo()->groups( $round );
		self::assertCount( 6, $again );
		self::assertSame( $members, array_map( static fn( array $g ): array => array_column( $g['members'], 'player_id' ), $again ) );
		self::assertSame( array( 'created', 'proposed', 'proposed' ), array_column( $this->audit( 'rl_round', $round ), 'action' ) );
		$this->assert_fails( 403, fn() => $this->rounds()->propose( $this->chef(), $round ) );
	}

	public function test_start_time_of_the_tournament_overrides_the_venue(): void {
		$this->registrations()->configure(
			$this->tk,
			$this->id['season'],
			array(
				'categories' => array(
					array(
						'no'  => 1,
						'min' => 1,
					),
				),
				'start_time' => '19:45',
			)
		);
		$this->register( array( 's1', 's2', 's3', 's4' ) );
		$round = $this->round( '2026-09-21' );
		$this->rounds()->propose( $this->tk, $round );

		self::assertSame( '2026-09-21 19:45:00', $this->repo()->groups( $round )[0]['start_at'] );
	}

	public function test_blocked_dates_and_closed_venues_shape_the_proposal(): void {
		// Kategorie 1: je zwei aus Seefeld (Montag) und Wollishofen (Dienstag), eine Gruppe.
		$this->register( array( 's1', 's2', 'w1', 'w2', 'o1' ) );
		$round = $this->round( '2026-09-21' );
		// Seefeld ist in der Rundenwoche gesperrt, Oerlikon bis zum Dienstag davor (also offen).
		$this->db->insert(
			'venue_closure',
			array(
				'venue_id'  => $this->id['venue_s'],
				'date_from' => '2026-09-21',
				'date_to'   => '2026-09-21',
			)
		);
		$this->db->insert(
			'venue_closure',
			array(
				'venue_id'  => $this->id['venue_o'],
				'date_from' => '2026-09-14',
				'date_to'   => '2026-09-22',
			)
		);
		// s1 kann am Dienstag nicht, o1 an keinem Abend der Woche.
		$this->registrations()->set_blocked_dates( $this->actor( 's1' ), $this->player['s1'], $round, array( '2026-09-22' ) );
		$this->registrations()->set_blocked_dates( $this->actor( 'o1' ), $this->player['o1'], $round, array( '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24' ) );

		$result = $this->rounds()->propose( $this->tk, $round );
		$groups = $this->repo()->groups( $round );
		self::assertCount( 1, $groups );
		// Möglich sind Wollishofen am Dienstag und Oerlikon am Mittwoch. Am Mittwoch können alle vier.
		self::assertSame( $this->id['venue_o'], $groups[0]['venue_id'] );
		self::assertSame( '2026-09-23 19:15:00', $groups[0]['start_at'] );
		self::assertSame( array( $this->player['o1'] ), array_column( $result['unassigned'], 'player_id' ) );
		self::assertSame( 'blocked', $result['unassigned'][0]['reason'] );
		self::assertSame( array( $this->player['o1'] ), array_column( $this->rounds()->unassigned( $round ), 'player_id' ) );
		$members = array_column( $groups[0]['members'], 'player_id' );
		sort( $members );
		self::assertSame( array( $this->player['s1'], $this->player['s2'], $this->player['w1'], $this->player['w2'] ), $members );
		// Niemand vom Platzclub Oerlikon spielt mit: Gruppenchef ist die erste Person der Gruppe.
		self::assertSame( $groups[0]['members'][0]['player_id'], $groups[0]['chief_player_id'] );
	}

	public function test_next_round_only_after_the_previous_is_closed(): void {
		$this->register( array( 's1', 's2', 'w1', 'w2', 'o1', 'o2', 'a1', 'a2' ) );
		$first  = $this->round( '2026-09-21' );
		$second = $this->round( '2026-10-19' );
		$rounds = $this->rounds();

		$this->assert_fails( 409, fn() => $rounds->propose( $this->tk, $second ) );
		$rounds->propose( $this->tk, $first );
		$this->assert_fails( 409, fn() => $rounds->propose( $this->tk, $second ) );
		$rounds->publish( $this->tk, $first );
		$this->assert_fails( 409, fn() => $rounds->propose( $this->tk, $second ) );
		$this->assert_fails( 409, fn() => $rounds->propose( $this->tk, $first ), );
		self::assertSame( array(), $this->repo()->groups( $second ) );

		foreach ( $this->repo()->groups( $first ) as $group ) {
			$this->entry()->save( $group['id'], $this->sheet( $group['id'] ), $this->tk );
		}
		$rounds->close( $this->tk, $first );
		$result = $rounds->propose( $this->tk, $second );
		self::assertSame( 2, $result['groups'] );

		// Zwei Vierergruppen: Aus jeder alten Gruppe bleiben je zwei zusammen (vier wiederholte Paarungen statt zwölf).
		self::assertSame( 4, $result['repeats'] );
		// Der Platzclub wechselt: In der zweiten Runde sind die beiden anderen Clubs an der Reihe.
		$hosts = static fn( array $groups ): array => array_column( $groups, 'host_club_id' );
		self::assertSame( array(), array_intersect( $hosts( $this->repo()->groups( $first ) ), $hosts( $this->repo()->groups( $second ) ) ) );
	}

	public function test_tk_changes_a_planned_group(): void {
		$this->register( $this->everyone() );
		$this->tables( 6 );
		$round  = $this->round( '2026-09-21' );
		$rounds = $this->rounds();
		$rounds->propose( $this->tk, $round );
		[ $one, $two ] = $this->repo()->groups( $round );
		$moved         = $two['members'][1]['player_id'];
		$other         = $one['host_club_id'] === $this->id['club_a'] ? 'o' : 'a';

		$rounds->save_group(
			$this->tk,
			$round,
			$one['id'],
			array(
				'venue_id'        => $this->id[ 'venue_' . $other ],
				'host_club_id'    => $this->id[ 'club_' . $other ],
				'start_at'        => '2026-09-24T20:00',
				'chief_player_id' => $one['members'][2]['player_id'],
				'note'            => 'Halle erst ab 20 Uhr frei',
				'members'         => array_merge( array_column( $one['members'], 'player_id' ), array( $moved ) ),
			)
		);
		$saved = $this->repo()->group( $one['id'] );
		self::assertSame( $this->id[ 'venue_' . $other ], $saved['venue_id'] );
		self::assertSame( '2026-09-24 20:00:00', $saved['start_at'] );
		self::assertSame( $one['members'][2]['player_id'], $saved['chief_player_id'] );
		self::assertSame( 'Halle erst ab 20 Uhr frei', $saved['note'] );
		self::assertCount( 5, $saved['members'] );
		self::assertSame( $moved, $saved['members'][4]['player_id'], 'Neue Mitglieder stehen am Schluss des Gruppenzettels.' );
		self::assertNotContains( $moved, array_column( $this->repo()->group( $two['id'] )['members'], 'player_id' ), 'Wer wechselt, verlässt die alte Gruppe.' );
		self::assertSame( array(), $this->events->callups, 'In der Planung geht kein Aufgebot hinaus.' );
		$audit = $this->audit( 'rl_group', $one['id'] );
		self::assertSame( 'changed', $audit[0]['action'] );
		self::assertSame( 900, $audit[0]['user'] );

		// Verlässt der Gruppenchef die Gruppe, folgt die erstgenannte Person des Platzclubs, sonst die erste.
		$rest = array_values( array_diff( array_column( $saved['members'], 'player_id' ), array( $saved['chief_player_id'] ) ) );
		$rounds->save_group( $this->tk, $round, $one['id'], array( 'members' => $rest ) );
		$after = $this->repo()->group( $one['id'] );
		$hosts = array_values( array_filter( $after['members'], static fn( array $m ): bool => $m['club_id'] === $after['host_club_id'] ) );
		self::assertSame( $hosts[0]['player_id'] ?? $after['members'][0]['player_id'], $after['chief_player_id'] );

		// Ungültige Änderungen.
		$third = $this->repo()->groups( $round )[2];
		$this->assert_fails( 403, fn() => $rounds->save_group( $this->chef(), $round, $one['id'], array( 'note' => 'x' ) ) );
		$this->assert_fails( 422, fn() => $rounds->save_group( $this->tk, $round, $one['id'], array( 'members' => array_merge( $rest, array( $third['members'][0]['player_id'] ) ) ) ) );
		$this->assert_fails( 422, fn() => $rounds->save_group( $this->tk, $round, $one['id'], array( 'chief_player_id' => $third['members'][0]['player_id'] ) ) );
		$this->assert_fails(
			422,
			fn() => $rounds->save_group(
				$this->tk,
				$round,
				$one['id'],
				array(
					'venue_id'     => $this->id['venue_s'],
					'host_club_id' => $this->id['club_w'],
				)
			)
		);
		$this->assert_fails( 422, fn() => $rounds->save_group( $this->tk, $round, $one['id'], array( 'start_at' => 'bald' ) ) );
		$this->assert_fails( 404, fn() => $rounds->save_group( $this->tk, $round, 99999, array( 'note' => 'x' ) ) );
		self::assertSame( $rest, array_column( $this->repo()->group( $one['id'] )['members'], 'player_id' ) );
	}

	public function test_tk_adds_and_deletes_groups(): void {
		$this->register( array( 's1', 's2', 'w1', 'w2', 'o1', 'o2' ) );
		$round  = $this->round( '2026-09-21' );
		$rounds = $this->rounds();

		// Von Hand: neue Gruppe mit zwei Personen, ohne Lokal.
		$id    = $rounds->save_group(
			$this->tk,
			$round,
			null,
			array(
				'category' => 1,
				'members'  => array( $this->player['s1'], $this->player['w1'] ),
			)
		);
		$group = $this->repo()->group( $id );
		self::assertSame( array( 1, 1 ), array( $group['category'], $group['group_no'] ) );
		self::assertSame( array( 'scheduled', 'scheduled' ), array_column( $group['members'], 'status' ) );
		self::assertSame( $this->player['s1'], $group['chief_player_id'] );
		self::assertSame( 2, $this->repo()->group( $rounds->save_group( $this->tk, $round, null, array( 'category' => 1 ) ) )['group_no'] );
		self::assertCount( 4, $rounds->unassigned( $round ) );
		$this->assert_fails( 422, fn() => $rounds->save_group( $this->tk, $round, null, array( 'members' => array( $this->player['s2'] ) ) ) );
		// Nicht angemeldet.
		$this->assert_fails( 422, fn() => $rounds->save_group( $this->tk, $round, $id, array( 'members' => array( $this->player['a1'] ) ) ) );

		$rounds->delete_group( $this->tk, $id );
		self::assertNull( $this->repo()->group( $id ) );
		self::assertCount( 6, $rounds->unassigned( $round ) );
		$this->assert_fails( 404, fn() => $rounds->delete_group( $this->tk, $id ) );
	}

	public function test_publishing_sends_the_callup(): void {
		$this->register( array( 's1', 's2', 'w1', 'w2', 's3', 'w3', 'o3', 'a3' ) );
		$round  = $this->round( '2026-09-21' );
		$rounds = $this->rounds();

		$this->assert_fails( 409, fn() => $rounds->publish( $this->tk, $round ) );
		$rounds->propose( $this->tk, $round );
		$this->assert_fails( 403, fn() => $rounds->publish( $this->chef(), $round ) );
		$groups = $this->repo()->groups( $round );

		// Eine Gruppe ohne Termin oder mit einer einzigen Person lässt sich nicht freigeben.
		$rounds->save_group( $this->tk, $round, $groups[0]['id'], array( 'start_at' => null ) );
		$error = $this->assert_fails( 422, fn() => $rounds->publish( $this->tk, $round ) );
		self::assertSame( array( 'group.' . $groups[0]['id'] ), array_column( $error->errors, 'field' ) );
		$rounds->save_group( $this->tk, $round, $groups[0]['id'], array( 'start_at' => '2026-09-21 19:15' ) );
		$single = $rounds->save_group(
			$this->tk,
			$round,
			null,
			array(
				'category' => 2,
				'members'  => array( $this->player['s3'] ),
				'start_at' => '2026-09-22 19:15',
			)
		);
		$this->assert_fails( 422, fn() => $rounds->publish( $this->tk, $round ) );
		$rounds->delete_group( $this->tk, $single );
		$rounds->save_group( $this->tk, $round, $groups[1]['id'], array( 'members' => array( $this->player['s3'], $this->player['w3'], $this->player['o3'], $this->player['a3'] ) ) );
		self::assertSame( 'planned', $this->repo()->round( $round )['status'] );

		$rounds->publish( $this->tk, $round );
		self::assertSame( 'published', $this->repo()->round( $round )['status'] );
		self::assertSame(
			array(
				array(
					'round'   => $round,
					'groups'  => array( $groups[0]['id'], $groups[1]['id'] ),
					'user'    => 900,
					'changed' => false,
				),
			),
			$this->events->callups
		);
		self::assertSame( 'published', $this->audit( 'rl_round', $round )[2]['action'] );
		$this->assert_fails( 409, fn() => $rounds->publish( $this->tk, $round ) );
	}

	public function test_changes_after_publishing_call_up_only_the_changed_groups(): void {
		$this->register( $this->everyone() );
		$this->tables( 6 );
		$round  = $this->round( '2026-09-21' );
		$rounds = $this->rounds();
		$rounds->propose( $this->tk, $round );
		$rounds->publish( $this->tk, $round );
		[ $one, $two, $three ] = $this->repo()->groups( $round );
		$this->events->callups = array();

		// Speichern ohne Änderung löst nichts aus.
		$rounds->save_group(
			$this->tk,
			$round,
			$one['id'],
			array(
				'start_at' => $one['start_at'],
				'members'  => array_column( $one['members'], 'player_id' ),
			)
		);
		self::assertSame( array(), $this->events->callups );

		// Neue Zeit: nur diese Gruppe.
		$rounds->save_group( $this->tk, $round, $one['id'], array( 'start_at' => '2026-09-24 20:00' ) );
		self::assertSame( array( array( $one['id'] ) ), array_column( $this->events->callups, 'groups' ) );
		self::assertTrue( $this->events->callups[0]['changed'] );

		// Wechsel einer Person: beide betroffenen Gruppen, die dritte nicht.
		$this->events->callups = array();
		$moved                 = $two['members'][0]['player_id'];
		$rounds->save_group( $this->tk, $round, $one['id'], array( 'members' => array_merge( array_column( $one['members'], 'player_id' ), array( $moved ) ) ) );
		self::assertSame( array( array( $one['id'], $two['id'] ) ), array_column( $this->events->callups, 'groups' ) );
		self::assertNotContains( $three['id'], $this->events->callups[0]['groups'] );
		self::assertSame( 'scheduled', array_column( $this->repo()->group( $one['id'] )['members'], 'status', 'player_id' )[ $moved ] );

		// Sind Partien erfasst, bleiben die Mitglieder; Zeit und Lokal lassen sich weiter ändern.
		$this->entry()->save( $two['id'], $this->sheet( $two['id'] ), $this->tk );
		$rest = array_column( $this->repo()->group( $two['id'] )['members'], 'player_id' );
		self::assertCount( 3, $rest );
		$this->assert_fails( 409, fn() => $rounds->save_group( $this->tk, $round, $two['id'], array( 'members' => array_slice( $rest, 1 ) ) ) );
		$this->assert_fails( 409, fn() => $rounds->save_group( $this->tk, $round, $one['id'], array( 'members' => array_merge( array_column( $this->repo()->group( $one['id'] )['members'], 'player_id' ), array( $rest[0] ) ) ) ) );
		$this->assert_fails( 409, fn() => $rounds->delete_group( $this->tk, $two['id'] ) );
		$rounds->save_group( $this->tk, $round, $two['id'], array( 'note' => 'Nachgetragen am Freitag' ) );
		self::assertSame( $rest, array_column( $this->repo()->group( $two['id'] )['members'], 'player_id' ) );
		self::assertCount( 4, $this->repo()->group( $three['id'] )['members'] );
	}

	public function test_closing_fixes_rank_and_points(): void {
		$this->register( array( 's1', 's2', 'w1', 'w2', 's3', 's4', 'w3', 'w4' ) );
		$round  = $this->round( '2026-09-21' );
		$rounds = $this->rounds();
		$rounds->propose( $this->tk, $round );
		$this->assert_fails( 409, fn() => $rounds->close( $this->tk, $round ) );
		$rounds->publish( $this->tk, $round );
		[ $one, $two ] = $this->repo()->groups( $round );

		// Erst eine Gruppe erfasst: Abschluss nur ausdrücklich ohne Resultat der anderen.
		$this->entry()->save( $one['id'], $this->sheet( $one['id'] ), $this->tk );
		$this->assert_fails( 403, fn() => $rounds->close( $this->chef(), $round ) );
		$error = $this->assert_fails( 409, fn() => $rounds->close( $this->tk, $round ) );
		self::assertStringContainsString( 'Kategorie 2, Gruppe 1', $error->getMessage() );
		self::assertSame( 'published', $this->repo()->round( $round )['status'] );

		$this->clock->advance( '+3 days' );
		$rounds->close( $this->tk, $round, array(), true );
		$row = $this->repo()->round( $round );
		self::assertSame( 'closed', $row['status'] );
		self::assertSame( 900, $row['closed_by'] );
		self::assertSame( '2026-09-04 10:00:00', $row['closed_at'] );
		// Die Person an Position 1 gewinnt alles: Ränge 1 bis 4 mit 6, 5, 4 und 3 Punkten.
		$closed = $this->repo()->group( $one['id'] );
		self::assertSame( array( 1, 2, 3, 4 ), array_column( $closed['members'], 'rank_no' ) );
		self::assertSame( array( 6.0, 5.0, 4.0, 3.0 ), array_column( $closed['members'], 'points' ) );
		// Die Gruppe ohne Gruppenzettel bleibt ohne Rang und Punkte.
		$open = $this->repo()->group( $two['id'] );
		self::assertSame( array( null, null, null, null ), array_column( $open['members'], 'points' ) );
		self::assertSame( array( 'scheduled' ), array_values( array_unique( array_column( $open['members'], 'status' ) ) ) );
		$audit = $this->audit( 'rl_round', $round );
		self::assertSame( 'closed', end( $audit )['action'] );
		self::assertSame( array( $two['id'] ), end( $audit )['after']['without_result'] );

		// Nach dem Abschluss ist alles fest.
		$this->assert_fails( 409, fn() => $rounds->close( $this->tk, $round ) );
		$this->assert_fails( 409, fn() => $this->entry()->save( $two['id'], $this->sheet( $two['id'] ), $this->tk ) );
		$this->assert_fails( 409, fn() => $rounds->save_group( $this->tk, $round, $two['id'], array( 'note' => 'zu spät' ) ) );
		$this->assert_fails( 409, fn() => $rounds->propose( $this->tk, $round ) );
		$this->assert_fails( 409, fn() => $rounds->delete( $this->tk, $round ) );
	}

	public function test_tie_is_shared_until_the_tk_draws_lots(): void {
		$this->seed( array( 'rl_finalists' => 2 ) );
		$this->register( array( 's1', 's2', 'w1', 'w2' ) );
		$round  = $this->round( '2026-09-21' );
		$rounds = $this->rounds();
		$rounds->propose( $this->tk, $round );
		$rounds->publish( $this->tk, $round );
		$group             = $this->repo()->groups( $round )[0];
		[ $a, $b, $c, $d ] = array_column( $group['members'], 'player_id' );

		// A schlägt alle; B schlägt C, C schlägt D, D schlägt B, alles 3:0: ein Kreis ohne Entscheid.
		$this->entry()->save( $group['id'], $this->circle( $group['id'] ), $this->tk );
		$saved = array_column( $this->repo()->group( $group['id'] )['members'], null, 'player_id' );
		self::assertSame( array( 1, 2, 2, 2 ), array( $saved[ $a ]['rank_no'], $saved[ $b ]['rank_no'], $saved[ $c ]['rank_no'], $saved[ $d ]['rank_no'] ) );
		self::assertSame( array( 6.0, 4.0, 4.0, 4.0 ), array( $saved[ $a ]['points'], $saved[ $b ]['points'], $saved[ $c ]['points'], $saved[ $d ]['points'] ), 'Geteilte Punkte der Plätze 2 bis 4.' );
		$closing = $rounds->closing( $round );
		self::assertTrue( $closing[0]['entered'] );
		self::assertSame( array( false, true, true, true ), array_column( $closing[0]['rows'], 'shared' ) );

		// Ungültige Angaben von Hand schreiben nichts fest.
		$this->assert_fails( 422, fn() => $rounds->close( $this->tk, $round, array( $group['id'] => array( $b => array( 'rank' => '5' ) ) ) ) );
		$this->assert_fails( 422, fn() => $rounds->close( $this->tk, $round, array( $group['id'] => array( $b => array( 'points' => '4,25' ) ) ) ) );
		self::assertSame( 'published', $this->repo()->round( $round )['status'] );

		// Das Los entscheidet: D vor C vor B. Leere Angaben bleiben beim berechneten Wert.
		$rounds->close(
			$this->tk,
			$round,
			array(
				$group['id'] => array(
					$a => array(
						'rank'   => '',
						'points' => '',
					),
					$d => array(
						'rank'   => '2',
						'points' => '5',
					),
					$c => array(
						'rank'   => 3,
						'points' => '4,0',
					),
					$b => array(
						'rank'   => '4',
						'points' => '3.0',
					),
				),
			)
		);
		$closed = array_column( $this->repo()->group( $group['id'] )['members'], null, 'player_id' );
		self::assertSame( array( 1, 4, 3, 2 ), array( $closed[ $a ]['rank_no'], $closed[ $b ]['rank_no'], $closed[ $c ]['rank_no'], $closed[ $d ]['rank_no'] ) );
		self::assertSame( array( 6.0, 3.0, 4.0, 5.0 ), array( $closed[ $a ]['points'], $closed[ $b ]['points'], $closed[ $c ]['points'], $closed[ $d ]['points'] ) );

		// Im Protokoll stehen die berechneten und die gesetzten Werte der geänderten Personen (C behält 4 Punkte, wechselt aber den Rang).
		$manual = array_values( array_filter( $this->audit( 'rl_group', $group['id'] ), static fn( array $row ): bool => 'rank_manual' === $row['action'] ) );
		self::assertCount( 1, $manual );
		self::assertSame( 900, $manual[0]['user'] );
		self::assertEquals(
			array(
				$b => array(
					'rank'   => 2,
					'points' => 4,
				),
				$c => array(
					'rank'   => 2,
					'points' => 4,
				),
				$d => array(
					'rank'   => 2,
					'points' => 4,
				),
			),
			$manual[0]['before']
		);
		self::assertEquals( 5, $manual[0]['after'][ $d ]['points'] );
		self::assertSame( 4, $manual[0]['after'][ $b ]['rank'] );
		self::assertArrayNotHasKey( $a, $manual[0]['after'] );
		self::assertSame( 1, $this->audit( 'rl_round', $round )[3]['after']['manual_groups'] );
	}

	public function test_final_takes_the_best_of_each_category(): void {
		$this->seed( array( 'rl_finalists' => 2 ) );
		$this->register( array( 's1', 's2', 'w1', 'w2', 's3', 's4', 'w3', 'w4' ) );
		$rounds = $this->rounds();
		$first  = $this->round( '2026-09-21' );
		$rounds->propose( $this->tk, $first );
		$rounds->publish( $this->tk, $first );
		$sheet = array();
		foreach ( $this->repo()->groups( $first ) as $group ) {
			$sheet[ $group['category'] ] = array_column( $group['members'], 'player_id' );
			$this->entry()->save( $group['id'], $this->sheet( $group['id'] ), $this->tk );
		}
		$final = $this->round( '2026-11-16', true );
		// Solange die Runde davor offen ist, gibt es keinen Final.
		$this->assert_fails( 409, fn() => $rounds->propose( $this->tk, $final ) );
		$rounds->close( $this->tk, $first );

		$standings = ( new Standings( $this->repo() ) )->overall( $this->repo()->competition( $this->id['competition'] ) );
		self::assertSame( $sheet[1], array_column( $standings[1], 'player_id' ), 'Rangliste in der Reihenfolge der Punkte 6, 5, 4, 3.' );
		self::assertSame( array( 6.0, 5.0, 4.0, 3.0 ), array_column( $standings[1], 'points' ) );
		self::assertSame( array( true, true, false, false ), array_column( $standings[1], 'qualified' ) );
		self::assertSame( array( 9, 0 ), array( $standings[1][0]['sets_won'], $standings[1][0]['sets_lost'] ) );

		// Die zweitbeste Person der Kategorie 1 meldet sich ab: Die dritte rückt nach.
		$this->registrations()->withdraw( $this->tk, $sheet[1][1] );
		$result = $rounds->propose( $this->tk, $final );
		self::assertSame( 2, $result['groups'] );
		self::assertSame( array(), $result['draw'] );
		$groups = $this->repo()->groups( $final );
		self::assertSame( array( 1, 2 ), array_column( $groups, 'category' ) );
		$members = static function ( array $group ): array {
			$ids = array_column( $group['members'], 'player_id' );
			sort( $ids );
			return $ids;
		};
		$expect  = static function ( array $ids ): array {
			sort( $ids );
			return $ids;
		};
		self::assertSame( $expect( array( $sheet[1][0], $sheet[1][2] ) ), $members( $groups[0] ) );
		self::assertSame( $expect( array( $sheet[2][0], $sheet[2][1] ) ), $members( $groups[1] ) );
		// Nicht qualifizierte gelten im Final nicht als «nicht zugeteilt».
		self::assertSame( array(), $rounds->unassigned( $final ) );
	}

	public function test_tie_at_the_final_limit_needs_a_draw(): void {
		$this->seed( array( 'rl_finalists' => 2 ) );
		$this->register( array( 's1', 's2', 'w1', 'w2' ) );
		$rounds = $this->rounds();
		$first  = $this->round( '2026-09-21' );
		$rounds->propose( $this->tk, $first );
		$rounds->publish( $this->tk, $first );
		$group = $this->repo()->groups( $first )[0];
		$this->entry()->save( $group['id'], $this->circle( $group['id'] ), $this->tk );
		$rounds->close( $this->tk, $first );
		$final = $this->round( '2026-11-16', true );

		// Drei Personen teilen Rang 2 mit gleich vielen Punkten und Sätzen: Das Los entscheidet über den zweiten Finalplatz.
		$result = $rounds->propose( $this->tk, $final );
		self::assertCount( 3, $result['draw'] );
		self::assertCount( 2, $this->repo()->groups( $final )[0]['members'] );
		self::assertContains( $group['members'][0]['player_id'], array_column( $this->repo()->groups( $final )[0]['members'], 'player_id' ) );
	}

	public function test_planned_round_can_be_moved_and_deleted(): void {
		$first  = $this->round( '2026-09-21' );
		$second = $this->round( '2026-10-19' );
		$rounds = $this->rounds();

		$rounds->reschedule( $this->tk, $second, '2026-10-26', '2026-10-29', false );
		self::assertSame( '2026-10-26', $this->repo()->round( $second )['date_from'] );
		$this->assert_fails( 422, fn() => $rounds->reschedule( $this->tk, $second, '2026-09-24', '2026-09-27', false ) );
		$this->assert_fails( 422, fn() => $rounds->reschedule( $this->tk, $first, '2026-09-21', '2026-10-26', false ) );
		$this->assert_fails( 422, fn() => $rounds->reschedule( $this->tk, $first, '2026-09-21', '2026-09-24', true ) );
		$this->assert_fails( 403, fn() => $rounds->reschedule( $this->chef(), $second, '2026-10-26', '2026-10-29', false ) );

		$this->assert_fails( 409, fn() => $rounds->delete( $this->tk, $first ) );
		$this->assert_fails( 403, fn() => $rounds->delete( $this->chef(), $second ) );
		$rounds->delete( $this->tk, $second );
		self::assertNull( $this->repo()->round( $second ) );
		self::assertSame( Rounds::PLANNED, $this->repo()->round( $first )['status'] );
	}

	public function test_venue_without_enough_tables_hosts_one_group(): void {
		$this->register( $this->everyone() );
		// Fünf Tische reichen für eine Sechsergruppe (drei Tische), nicht für zwei.
		$this->tables( 5 );
		$round  = $this->round( '2026-09-21' );
		$result = $this->rounds()->propose( $this->tk, $round );

		self::assertSame( 6, $result['groups'] );
		self::assertSame( 2, $result['without_slot'], 'Vier Lokale, vier Abende: Zwei Gruppen bleiben ohne Abend.' );
		$venues = array_filter( array_column( $this->repo()->groups( $round ), 'venue_id' ) );
		self::assertCount( 4, $venues );
		self::assertCount( 4, array_unique( $venues ) );
		$this->assert_fails( 422, fn() => $this->rounds()->publish( $this->tk, $round ) );
	}

	/**
	 * Tische aller Lokale.
	 */
	private function tables( int $count ): void {
		$this->db->query( "UPDATE {$this->db->table( 'venue' )} SET tables_count = %d", array( $count ) );
	}

	/**
	 * Gruppenzettel einer Vierergruppe mit einem Kreis: Position 1 schlägt alle, 2 schlägt 3,
	 * 3 schlägt 4, 4 schlägt 2, alles 3:0.
	 *
	 * @return array<string,mixed>
	 */
	private function circle( int $group_id ): array {
		$sheet   = $this->sheet( $group_id );
		$ids     = array_keys( $sheet['players'] );
		$winners = array(
			$ids[0] . '-' . $ids[1] => true,
			$ids[0] . '-' . $ids[2] => true,
			$ids[0] . '-' . $ids[3] => true,
			$ids[1] . '-' . $ids[2] => true,
			$ids[2] . '-' . $ids[3] => true,
			$ids[1] . '-' . $ids[3] => false,
		);
		foreach ( \TTLeague\RankingTournament\Sheet::order( $ids ) as $i => $game ) {
			$a_wins                         = $winners[ $game['a'] . '-' . $game['b'] ];
			$sheet['games'][ $i ]['sets_a'] = $a_wins ? 3 : 0;
			$sheet['games'][ $i ]['sets_b'] = $a_wins ? 0 : 3;
		}
		return $sheet;
	}
}
