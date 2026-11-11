<?php
/**
 * Meldung der Cupmannschaften gegen MariaDB: Rechte, Regeln an ihren Grenzen, Rückzug.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Cup;

use TTLeague\Cup\CupEvents;
use TTLeague\Cup\Entries;
use TTLeague\Results\Actor;

/**
 * Daten siehe CupTestCase: Cup A mit Punktelimite 20 und Meldeschluss 10.11.2026, die Uhr steht
 * auf dem 2.11.2026.
 */
final class CupEntriesTest extends CupTestCase {

	public function test_the_tk_chef_registers_teams_and_the_system_numbers_them(): void {
		$chef = $this->chef['seefeld'];

		$first = $this->entries->register( $this->cup_a, $this->club['seefeld'], $chef, $this->player['seefeld'][1], $this->player['seefeld'][2] );
		$this->clock->advance( '+1 hour' );
		$second = $this->entries->register( $this->cup_a, $this->club['seefeld'], $chef, $this->player['seefeld'][3], $this->player['seefeld'][4] );
		$other  = $this->entries->register( $this->cup_a, $this->club['oerlikon'], $this->chef['oerlikon'], $this->player['oerlikon'][2], $this->player['oerlikon'][1] );

		self::assertSame( array( 'Seefeld 1', 1, 20, 'active' ), array( $first['name'], $first['number'], $first['points_total'], $first['status'] ), 'Klassierungen 14 und 6' );
		self::assertSame( array( 'Seefeld 2', 2, 8 ), array( $second['name'], $second['number'], $second['points_total'] ) );
		self::assertSame( array( 'Oerlikon 1', 1, 18 ), array( $other['name'], $other['number'], $other['points_total'] ), 'jeder Club zählt für sich' );
		self::assertNull( $first['seed'] );
		self::assertSame( array( $chef->user_id, '2026-11-02 08:00:00' ), array( $first['registered_by'], $first['registered_at'] ), 'Zeitpunkt in UTC' );
		self::assertSame( '2026-11-02 09:00:00', $second['registered_at'] );
		self::assertSame( array( 'Seefeld1 Cupspieler', 'Seefeld2 Cupspieler' ), array_column( $first['players'], 'name' ) );

		$audit = $this->audit( 'cup_entry', $first['id'] );
		self::assertSame( array( 'registered', $chef->user_id ), array( $audit[0]['action'], $audit[0]['user_id'] ) );
		self::assertSame( 20, $audit[0]['after']['points_total'] );
		self::assertSame( array( CupEvents::ENTRY_REGISTERED, CupEvents::ENTRY_REGISTERED, CupEvents::ENTRY_REGISTERED ), $this->events->actions() );
		self::assertSame( array( $this->cup_a, CupEvents::ENTRY_REGISTERED, $chef->user_id, array() ), $this->events->calls[0] );
	}

	public function test_the_points_limit_applies_at_its_boundary(): void {
		$this->format( $this->cup_a, array( 'points_limit' => 19 ) );

		// Seefeld: 14 + 6 = 20, einer zu viel. 14 + 5 = 19 ist genau die Grenze.
		$error = self::expect_error( fn() => $this->team( 'seefeld', 1, 2 ), 422 );
		self::assertSame( array( 'players' ), array_column( $error->errors, 'field' ) );
		self::assertStringContainsString( 'Summe der Klassierungen ist 20', $error->getMessage() );
		self::assertStringContainsString( 'höchstens 19', $error->getMessage() );
		self::assertSame( array(), $this->repo->entries( $this->cup_a ), 'nichts gespeichert' );

		self::assertSame( 19, $this->team( 'seefeld', 1, 3 )['points_total'] );
	}

	public function test_without_a_points_limit_any_pair_may_register(): void {
		$this->format( $this->cup_a, array() );

		self::assertSame( 20, $this->team( 'seefeld', 1, 2 )['points_total'] );
		self::assertNull( Entries::settings( (array) $this->repo->competition( $this->cup_a ) )['points_limit'] );
	}

	public function test_a_player_is_in_one_cup_team_of_the_season_across_categories(): void {
		$this->team( 'seefeld', 1, 2 );

		$same = self::expect_error( fn() => $this->team( 'seefeld', 2, 3 ), 422 );
		self::assertSame( array( 'player1' ), array_column( $same->errors, 'field' ) );
		self::assertStringContainsString( 'Seefeld2 Cupspieler ist schon in der Cupmannschaft Seefeld 1 (Mannschaftscup A) gemeldet', $same->getMessage() );

		// Auch in der anderen Kategorie (3 + 6 = 9 läge unter deren Limite von 10).
		$other = self::expect_error( fn() => $this->team( 'seefeld', 4, 2, $this->cup_b ), 422 );
		self::assertSame( array( 'player2' ), array_column( $other->errors, 'field' ) );

		self::assertSame( 'Seefeld 1', $this->team( 'seefeld', 3, 4, $this->cup_b )['name'], 'freie Spieler in der Kategorie B, eigene Nummerierung je Kategorie' );
	}

	public function test_both_players_must_be_registered_for_the_club_and_active(): void {
		$foreign = self::expect_error(
			fn() => $this->entries->register( $this->cup_a, $this->club['seefeld'], $this->tk, $this->player['seefeld'][1], $this->player['oerlikon'][2] ),
			422
		);
		self::assertSame( array( 'player2' ), array_column( $foreign->errors, 'field' ) );
		self::assertStringContainsString( 'nicht für den Club gemeldet', $foreign->getMessage() );

		// Provisorisch gemeldet (noch nicht freigegeben) zählt nicht.
		$this->db->update(
			'player_season',
			array( 'status' => 'provisional' ),
			array(
				'player_id' => $this->player['seefeld'][2],
				'season_id' => $this->season,
			)
		);
		$provisional = self::expect_error( fn() => $this->team( 'seefeld', 1, 2 ), 422 );
		self::assertSame( array( 'player2' ), array_column( $provisional->errors, 'field' ) );

		$twice = self::expect_error( fn() => $this->team( 'seefeld', 1, 1 ), 422 );
		self::assertStringContainsString( 'zwei verschiedenen Spielern', $twice->getMessage() );

		$none = self::expect_error( fn() => $this->entries->register( $this->cup_a, $this->club['seefeld'], $this->tk, 0, $this->player['seefeld'][2] ), 422 );
		self::assertSame( array( 'player1', 'player2' ), array_column( $none->errors, 'field' ) );
		self::assertSame( array(), $this->repo->entries( $this->cup_a ) );
	}

	public function test_only_the_tk_chef_of_the_club_in_the_season_registers(): void {
		$players = $this->player['seefeld'];

		$other = self::expect_error( fn() => $this->entries->register( $this->cup_a, $this->club['seefeld'], $this->chef['oerlikon'], $players[1], $players[2] ), 403 );
		self::assertStringContainsString( 'TK-Chef des Clubs', $other->getMessage() );
		self::expect_error( fn() => $this->entries->register( $this->cup_a, $this->club['seefeld'], $this->player_actor( 'seefeld', 1 ), $players[1], $players[2] ), 403 );
		self::expect_error( fn() => $this->entries->register( $this->cup_a, $this->club['seefeld'], new Actor( 77, null, false ), $players[1], $players[2] ), 403 );

		// TK-Chef nur in der Vorsaison: kein Recht in der Saison der Konkurrenz.
		$old    = $this->seeder->season( '2025-26', 'archived' );
		$former = $this->seeder->person( 'Vorgänger', 'Viktor' );
		$this->seeder->role( $old, $this->club['seefeld'], $former, 'tk_chef' );
		self::expect_error( fn() => $this->entries->register( $this->cup_a, $this->club['seefeld'], new Actor( 78, $former, false ), $players[1], $players[2] ), 403 );

		self::expect_error( fn() => $this->entries->register( 99999, $this->club['seefeld'], $this->tk, $players[1], $players[2] ), 404 );
		self::assertSame( array(), $this->repo->entries( $this->cup_a ) );
	}

	public function test_after_the_deadline_only_the_tk_registers(): void {
		// Am Tag des Meldeschlusses (10.11.2026, 23:30 Ortszeit) geht es noch.
		$this->clock = new \TTLeague\Infrastructure\Time\FixedClock( '2026-11-10 22:30:00' );
		$this->build_services();
		$chef = $this->chef['seefeld'];
		self::assertSame( 'Seefeld 1', $this->entries->register( $this->cup_a, $this->club['seefeld'], $chef, $this->player['seefeld'][1], $this->player['seefeld'][2] )['name'] );

		// Eine Stunde später ist der 11.11.: nur noch die TK.
		$this->clock->advance( '+1 hour' );
		$late = self::expect_error( fn() => $this->entries->register( $this->cup_a, $this->club['seefeld'], $chef, $this->player['seefeld'][3], $this->player['seefeld'][4] ), 409 );
		self::assertStringContainsString( 'Meldeschluss ist vorbei', $late->getMessage() );
		self::expect_error( fn() => $this->entries->withdraw( $this->repo->entries( $this->cup_a )[0]['id'], $chef ), 409 );

		self::assertSame( 'Seefeld 2', $this->team( 'seefeld', 3, 4 )['name'], 'die TK meldet nach' );

		// Ohne Meldeschluss (Cup B) meldet der TK-Chef weiter.
		self::assertSame( 'Oerlikon 1', $this->entries->register( $this->cup_b, $this->club['oerlikon'], $this->chef['oerlikon'], $this->player['oerlikon'][3], $this->player['oerlikon'][4] )['name'] );
	}

	public function test_withdrawing_before_the_draw_deletes_the_entry(): void {
		$entry = $this->team( 'seefeld', 1, 2 );
		$this->team( 'seefeld', 3, 4 );

		self::expect_error( fn() => $this->entries->withdraw( $entry['id'], $this->chef['oerlikon'] ), 403 );
		$result = $this->entries->withdraw( $entry['id'], $this->chef['seefeld'] );

		self::assertTrue( $result['deleted'] );
		self::assertSame( 'Seefeld 1', $result['entry']['name'] );
		self::assertSame( array( 'Seefeld 2' ), array_column( $this->repo->entries( $this->cup_a ), 'name' ) );
		$audit = $this->audit( 'cup_entry', $entry['id'] );
		self::assertSame( array( 'registered', 'withdrawn' ), array_column( $audit, 'action' ) );
		self::assertNull( $audit[1]['after'] );
		self::assertSame( CupEvents::ENTRY_WITHDRAWN, $this->events->actions()[2] );

		// Die Spieler sind wieder frei; die Nummerierung läuft weiter.
		self::assertSame( 'Seefeld 3', $this->team( 'seefeld', 1, 2 )['name'] );
		self::expect_error( fn() => $this->entries->withdraw( $entry['id'], $this->tk ), 404 );
	}

	public function test_after_the_draw_the_tk_withdraws_and_the_opponent_moves_on(): void {
		$ids     = $this->eight();
		$fixture = $this->fixture_of( 1, 1 );

		$chef = self::expect_error( fn() => $this->entries->withdraw( $ids['seefeld'], $this->chef['seefeld'] ), 403 );
		self::assertStringContainsString( 'nur die TK', $chef->getMessage() );

		$result = $this->entries->withdraw( $ids['seefeld'], $this->tk );

		self::assertFalse( $result['deleted'] );
		self::assertSame( 'withdrawn', $result['entry']['status'] );
		self::assertSame( 'withdrawn', $this->repo->entry( $ids['seefeld'] )['status'], 'die Meldung bleibt im Tableau' );
		$row = $this->fixture_row( $fixture );
		self::assertSame( array( 'forfeit', '0', '10' ), array( $row['status'], $row['home_score'], $row['away_score'] ), 'forfait für den Gegner' );
		self::assertSame( 'Rückzug von Seefeld 1.', $this->db->get_var( "SELECT comment FROM {$this->db->table( 'fixture_event' )} WHERE fixture_id = %d AND type = 'forfeit'", array( $fixture ) ) );
		self::assertSame( $ids['wollishofen'], $this->tie( 2, 1 )['home'], 'Wollishofen steht kampflos im Halbfinal' );

		self::expect_error( fn() => $this->entries->withdraw( $ids['seefeld'], $this->tk ), 409 );
		// Art. 20.2: Die Spieler der zurückgezogenen Mannschaft bleiben für die Saison gebunden.
		self::expect_error( fn() => $this->team( 'seefeld', 1, 3, $this->cup_b ), 422 );
	}

	public function test_no_new_entries_after_the_draw(): void {
		$this->eight();

		$error = self::expect_error( fn() => $this->team( 'affoltern' ), 409 );
		self::assertStringContainsString( 'Auslosung ist freigegeben', $error->getMessage() );
	}

	public function test_an_archived_season_takes_no_entries(): void {
		$this->db->update( 'season', array( 'status' => 'archived' ), array( 'id' => $this->season ) );

		self::expect_error( fn() => $this->team( 'seefeld' ), 409 );
	}

	public function test_the_tk_sets_limit_deadline_and_minimum(): void {
		self::expect_error( fn() => $this->entries->configure( $this->cup_a, $this->chef['seefeld'], array( 'points_limit' => '18' ) ), 403 );

		$settings = $this->entries->configure(
			$this->cup_a,
			$this->tk,
			array(
				'points_limit'          => '18',
				'registration_deadline' => '2026-11-20',
				'min_entries'           => '6',
			)
		);
		self::assertSame(
			array(
				'points_limit'          => 18,
				'registration_deadline' => '2026-11-20',
				'min_entries'           => 6,
			),
			$settings
		);
		self::assertSame( $settings, Entries::settings( (array) $this->repo->competition( $this->cup_a ) ) );
		$audit = $this->audit( 'competition', $this->cup_a );
		self::assertSame( array( 'cup_configured', 20, 18 ), array( $audit[0]['action'], $audit[0]['before']['points_limit'], $audit[0]['after']['points_limit'] ) );

		$invalid = self::expect_error(
			fn() => $this->entries->configure(
				$this->cup_a,
				$this->tk,
				array(
					'points_limit'          => '45',
					'registration_deadline' => '2026-02-30',
					'min_entries'           => '1',
				)
			),
			422
		);
		self::assertSame( array( 'points_limit', 'registration_deadline', 'min_entries' ), array_column( $invalid->errors, 'field' ) );

		// Leere Felder entfernen die Werte: keine Grenze, kein Meldeschluss, Standard acht.
		self::assertSame(
			array(
				'points_limit'          => null,
				'registration_deadline' => null,
				'min_entries'           => 8,
			),
			$this->entries->configure(
				$this->cup_a,
				$this->tk,
				array(
					'points_limit'          => '',
					'registration_deadline' => '',
					'min_entries'           => '',
				)
			)
		);
	}

	public function test_the_cup_takes_place_from_eight_entries(): void {
		$this->teams( 7 );
		self::assertSame(
			array(
				'count'   => 7,
				'minimum' => 8,
				'enough'  => false,
			),
			$this->entries->quorum( $this->cup_a )
		);

		$this->team( 'enge' );
		self::assertTrue( $this->entries->quorum( $this->cup_a )['enough'], 'genau acht Meldungen' );
	}

	public function test_the_overview_of_a_club_shows_its_entries_and_free_players(): void {
		$this->team( 'seefeld', 1, 2 );
		$this->team( 'oerlikon', 1, 2 );

		$blocks = $this->entries->for_club( $this->club['seefeld'], $this->chef['seefeld'] );

		self::assertSame( array( 'Mannschaftscup A', 'Mannschaftscup B' ), array_map( static fn( array $block ): string => $block['competition']['name'], $blocks ) );
		self::assertTrue( $blocks[0]['open'] );
		self::assertSame( array( 'Seefeld 1' ), array_column( $blocks[0]['entries'], 'name' ), 'nur die Mannschaften des eigenen Clubs' );
		self::assertTrue( $blocks[0]['entries'][0]['can_withdraw'] );
		self::assertSame( array(), $blocks[1]['entries'] );
		self::assertSame(
			array( 'Seefeld 1', 'Seefeld 1', null, null ),
			array_column( $blocks[0]['players'], 'entry' ),
			'gemeldete Spieler sind als vergeben markiert'
		);

		self::assertSame( array(), $this->entries->for_club( $this->club['seefeld'], $this->chef['oerlikon'] ), 'fremder Club' );

		// Nach dem Meldeschluss bleibt die Übersicht lesbar, aber zu.
		$this->clock->advance( '+10 days' );
		$late = $this->entries->for_club( $this->club['seefeld'], $this->chef['seefeld'] );
		self::assertFalse( $late[0]['open'] );
		self::assertStringContainsString( 'Meldeschluss', (string) $late[0]['closed_reason'] );
		self::assertFalse( $late[0]['entries'][0]['can_withdraw'] );
		self::assertTrue( $late[1]['open'], 'Cup B hat keinen Meldeschluss' );
		self::assertTrue( $this->entries->for_club( $this->club['seefeld'], $this->tk )[0]['open'], 'die TK meldet weiter' );
	}
}
