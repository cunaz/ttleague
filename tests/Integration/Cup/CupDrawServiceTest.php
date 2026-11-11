<?php
/**
 * Auslosung gegen MariaDB: Vorschlag, Setzung, Änderungen der TK, Freigabe.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Cup;

use TTLeague\Access\AccessPolicy;
use TTLeague\Cup\CupEvents;
use TTLeague\Domain\Cup\Draw;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Postponement\PostponementService;
use TTLeague\Tests\Integration\Postponement\RecordingPostponementEvents;

/**
 * Daten siehe CupTestCase. Die ersten Mannschaften der Clubs haben absteigende Punktesummen:
 * Seefeld 20, Wollishofen 19, Oerlikon 18 und so weiter.
 */
final class CupDrawServiceTest extends CupTestCase {

	/**
	 * Plätze des Vorschlags als flache Liste.
	 *
	 * @param array<string,mixed> $overview Stand der Auslosung.
	 * @return list<int|null>
	 */
	private static function positions( array $overview ): array {
		$positions = array();
		foreach ( $overview['proposal']['pairings'] as $pairing ) {
			$positions[] = $pairing['home'];
			$positions[] = $pairing['away'];
		}
		return $positions;
	}

	public function test_the_proposal_is_the_pure_draw_with_its_seed_in_the_audit_log(): void {
		$ids = $this->teams( 6 );

		self::expect_error( fn() => $this->draw->propose( $this->cup_a, $this->chef['seefeld'], 5 ), 403 );
		$overview = $this->draw->propose( $this->cup_a, $this->tk, 2026 );

		$expected = Draw::draw(
			array_map(
				static fn( array $entry ): array => array(
					'id'            => $entry['id'],
					'club_id'       => $entry['club_id'],
					'points_total'  => $entry['points_total'],
					'registered_at' => $entry['registered_at'],
					'seed'          => null,
				),
				$this->repo->entries( $this->cup_a )
			),
			2026
		);
		self::assertSame( $expected['positions'], self::positions( $overview ) );
		self::assertSame( array( 2026, 8, false, false ), array( $overview['proposal']['seed'], $overview['proposal']['size'], $overview['proposal']['changed'], $overview['proposal']['stale'] ) );
		self::assertSame( array_values( $ids ), array_column( $overview['entries'], 'id' ), 'Setzung nach Punktesumme' );
		self::assertSame( range( 1, 6 ), array_column( $overview['entries'], 'rank' ) );
		self::assertFalse( $overview['drawn'] );

		$audit = $this->audit( 'competition', $this->cup_a );
		self::assertSame( array( 'cup_draw_proposed', $this->tk->user_id, 2026 ), array( $audit[0]['action'], $audit[0]['user_id'], $audit[0]['after']['seed'] ) );
		self::assertSame( $expected['positions'], $audit[0]['after']['positions'] );
		self::assertSame( CupEvents::DRAW_PROPOSED, $this->events->actions()[6] );
		self::assertSame( array(), $this->repo->ties( $this->cup_a ), 'ein Vorschlag schreibt kein Tableau' );

		// Ohne Vorgabe wählt der Dienst einen Startwert (im Test fest 4711) und hält ihn fest.
		self::assertSame( 4711, $this->draw->propose( $this->cup_a, $this->tk )['proposal']['seed'] );
		self::assertSame( 4711, $this->audit( 'competition', $this->cup_a )[1]['after']['seed'] );
	}

	public function test_a_draw_needs_two_teams(): void {
		$this->team( 'seefeld' );

		self::expect_error( fn() => $this->draw->propose( $this->cup_a, $this->tk, 1 ), 409 );
		self::expect_error( fn() => $this->draw->release( $this->cup_a, $this->tk ), 409 );
	}

	public function test_the_tk_changes_the_seeding_and_the_proposal_expires(): void {
		$ids = $this->teams( 5 );
		$this->draw->propose( $this->cup_a, $this->tk, 3 );

		self::expect_error( fn() => $this->draw->set_seeds( $this->cup_a, $this->chef['seefeld'], array( $ids['affoltern'] ?? 0 => 1 ) ), 403 );
		$entries = $this->draw->set_seeds(
			$this->cup_a,
			$this->tk,
			array(
				$ids['bergwiese'] => '1',
				$ids['seefeld']   => '',
			)
		);

		self::assertSame(
			array( $ids['bergwiese'], $ids['seefeld'], $ids['wollishofen'], $ids['oerlikon'], $ids['altstetten'] ),
			array_column( $entries, 'id' ),
			'die gesetzte Nummer geht vor, die übrigen nach Punktesumme'
		);
		self::assertSame( 1, $this->repo->entry( $ids['bergwiese'] )['seed'] );
		self::assertNull( $this->draw->overview( $this->cup_a )['proposal'], 'neu auslosen' );
		self::assertSame( 'cup_seeded', $this->audit( 'competition', $this->cup_a )[1]['action'] );

		$twice = self::expect_error( fn() => $this->draw->set_seeds( $this->cup_a, $this->tk, array( $ids['oerlikon'] => '1' ) ), 422 );
		self::assertStringContainsString( 'nur einmal', $twice->getMessage() );
		$range = self::expect_error( fn() => $this->draw->set_seeds( $this->cup_a, $this->tk, array( $ids['oerlikon'] => '6' ) ), 422 );
		self::assertSame( array( 'seed.' . $ids['oerlikon'] ), array_column( $range->errors, 'field' ) );

		// Die gesetzte Nummer 1 erhält eines der drei Freilose.
		$overview = $this->draw->propose( $this->cup_a, $this->tk, 3 );
		$byes     = array_column( array_filter( $overview['proposal']['pairings'], static fn( array $p ): bool => null === $p['away'] ), 'home' );
		self::assertContains( $ids['bergwiese'], $byes );
		self::assertNotContains( $ids['altstetten'], $byes );
	}

	public function test_the_tk_changes_pairings_and_home_right(): void {
		$ids = $this->teams( 6 );
		self::expect_error( fn() => $this->draw->change( $this->cup_a, $this->tk, array_values( $ids ) ), 409 );
		$this->draw->propose( $this->cup_a, $this->tk, 1 );

		$wanted = array( $ids['oerlikon'], $ids['seefeld'], null, $ids['wollishofen'], $ids['altstetten'], null, $ids['lindenhof'], $ids['bergwiese'] );
		self::expect_error( fn() => $this->draw->change( $this->cup_a, $this->chef['seefeld'], $wanted ), 403 );
		$overview = $this->draw->change( $this->cup_a, $this->tk, array_map( static fn( ?int $id ): string => (string) $id, $wanted ) );

		self::assertSame(
			array( $ids['oerlikon'], $ids['seefeld'], $ids['wollishofen'], null, $ids['altstetten'], null, $ids['lindenhof'], $ids['bergwiese'] ),
			self::positions( $overview ),
			'ein Freilos steht hinten, sonst wie gewählt'
		);
		self::assertTrue( $overview['proposal']['changed'] );
		self::assertSame( 1, $overview['proposal']['seed'], 'der Startwert des Vorschlags bleibt' );
		self::assertSame( 'cup_draw_changed', $this->audit( 'competition', $this->cup_a )[1]['action'] );

		$duplicate = self::expect_error(
			fn() => $this->draw->change( $this->cup_a, $this->tk, array( $ids['oerlikon'], $ids['oerlikon'], $ids['wollishofen'], null, $ids['altstetten'], null, $ids['lindenhof'], $ids['bergwiese'] ) ),
			422
		);
		self::assertSame( array( 'positions', 'positions' ), array_column( $duplicate->errors, 'field' ) );
		$empty = self::expect_error(
			fn() => $this->draw->change( $this->cup_a, $this->tk, array( $ids['oerlikon'], $ids['seefeld'], $ids['wollishofen'], $ids['altstetten'], null, null, $ids['lindenhof'], $ids['bergwiese'] ) ),
			422
		);
		self::assertStringContainsString( 'mindestens eine Mannschaft', $empty->getMessage() );
		self::expect_error( fn() => $this->draw->change( $this->cup_a, $this->tk, array( $ids['oerlikon'], $ids['seefeld'] ) ), 409 );
	}

	public function test_release_writes_the_bracket_and_the_fixtures_without_date(): void {
		$ids = $this->teams( 8 );
		$this->draw->propose( $this->cup_a, $this->tk, 77 );
		$positions = self::positions( $this->draw->overview( $this->cup_a ) );

		self::expect_error( fn() => $this->draw->release( $this->cup_a, $this->chef['seefeld'], '2026-11-30' ), 403 );
		self::expect_error( fn() => $this->draw->release( $this->cup_a, $this->tk, '2026-11-01' ), 422 );
		self::expect_error( fn() => $this->draw->release( $this->cup_a, $this->tk, '30.11.2026' ), 422 );
		$result = $this->draw->release( $this->cup_a, $this->tk, '2026-11-30' );

		self::assertSame( array( 77, 3, 7, '2026-11-30' ), array( $result['seed'], $result['rounds'], $result['ties'], $result['deadline'] ) );
		self::assertCount( 4, $result['fixtures'] );
		$ties = $this->repo->ties( $this->cup_a );
		self::assertSame( array( '1/1', '1/2', '1/3', '1/4', '2/1', '2/2', '3/1' ), array_map( static fn( array $tie ): string => $tie['round'] . '/' . $tie['slot'], $ties ) );
		foreach ( array_slice( $ties, 0, 4 ) as $i => $tie ) {
			self::assertSame( array( $positions[ 2 * $i ], $positions[ 2 * $i + 1 ] ), array( $tie['home'], $tie['away'] ) );
			self::assertSame( '2026-11-30', $tie['deadline'], 'Frist der ersten Runde' );
			$fixture = $this->fixture_row( $tie['fixture']['id'] );
			self::assertSame(
				array(
					'status'        => 'scheduled',
					'scheduled_at'  => null,
					'venue_id'      => null,
					'round'         => '1',
					'match_no'      => (string) ( 101 + $i ),
					'home_entry_id' => (string) $tie['home'],
					'away_entry_id' => (string) $tie['away'],
					'home_score'    => null,
					'away_score'    => null,
				),
				$fixture,
				'ohne Termin, die erstgenannte Mannschaft hat Heimrecht'
			);
		}
		foreach ( array_slice( $ties, 4 ) as $tie ) {
			self::assertSame( array( null, null, null, null ), array( $tie['home'], $tie['away'], $tie['fixture'], $tie['deadline'] ), 'spätere Runden sind leer' );
		}
		self::assertSame( range( 1, 8 ), array_map( fn( int $id ): ?int => $this->repo->entry( $id )['seed'], array_values( $ids ) ), 'die Setzung steht in cup_entry.seed' );

		$audit = $this->audit( 'competition', $this->cup_a );
		$last  = end( $audit );
		self::assertSame( array( 'cup_draw_released', $this->tk->user_id, 77, $positions, '2026-11-30' ), array( $last['action'], $last['user_id'], $last['after']['seed'], $last['after']['positions'], $last['after']['deadline'] ) );
		self::assertSame( array( $this->cup_a, CupEvents::DRAW_RELEASED, $this->tk->user_id, array() ), end( $this->events->calls ) );
		$overview = $this->draw->overview( $this->cup_a );
		self::assertTrue( $overview['drawn'] );
		self::assertNull( $overview['proposal'], 'der Vorschlag ist verbraucht' );

		// Danach ist die Auslosung fest.
		self::expect_error( fn() => $this->draw->release( $this->cup_a, $this->tk ), 409 );
		self::expect_error( fn() => $this->draw->propose( $this->cup_a, $this->tk, 1 ), 409 );
		self::expect_error( fn() => $this->draw->set_seeds( $this->cup_a, $this->tk, array( $ids['seefeld'] => 2 ) ), 409 );
		self::assertCount( 7, $this->repo->ties( $this->cup_a ) );
	}

	public function test_byes_move_on_at_once_and_meet_in_a_fixture(): void {
		$ids = $this->teams( 5 );

		// Seefeld, Wollishofen und Oerlikon haben ein Freilos; Altstetten spielt gegen Bergwiese.
		$result = $this->release( array( 'seefeld', null, 'altstetten', 'bergwiese', 'wollishofen', null, 'oerlikon', null ), $ids, null );

		self::assertCount( 2, $result['fixtures'], 'Altstetten – Bergwiese und Wollishofen – Oerlikon' );
		self::assertNull( $this->tie( 1, 1 )['fixture'], 'ein Freilos hat keine Begegnung' );
		self::assertSame( array( $ids['seefeld'], null ), array( $this->tie( 2, 1 )['home'], $this->tie( 2, 1 )['away'] ), 'Seefeld wartet auf den Sieger der zweiten Paarung' );
		self::assertNull( $this->tie( 2, 1 )['fixture'] );

		$semi = $this->tie( 2, 2 );
		self::assertSame( array( $ids['wollishofen'], $ids['oerlikon'] ), array( $semi['home'], $semi['away'] ), 'zwei Freilose treffen sich in der nächsten Runde' );
		self::assertNotNull( $semi['fixture'] );
		self::assertSame( array( '2', '202', null ), array( $this->fixture_row( $semi['fixture']['id'] )['round'], $this->fixture_row( $semi['fixture']['id'] )['match_no'], $this->fixture_row( $semi['fixture']['id'] )['scheduled_at'] ) );
		self::assertNull( $semi['deadline'], 'die Frist der nächsten Runde setzt die TK' );
		self::assertNull( $this->tie( 1, 2 )['deadline'], 'ohne Angabe keine Frist' );

		$byes = $this->db->get_col( "SELECT entity_id FROM {$this->db->table( 'audit_log' )} WHERE entity = 'cup_tie' AND action = 'bye' ORDER BY entity_id" );
		self::assertSame( array( (string) $this->tie( 1, 1 )['id'], (string) $this->tie( 1, 3 )['id'], (string) $this->tie( 1, 4 )['id'] ), $byes );
	}

	public function test_a_proposal_only_holds_for_its_entries(): void {
		$ids = $this->teams( 6 );
		$this->draw->propose( $this->cup_a, $this->tk, 1 );

		$this->entries->withdraw( $ids['lindenhof'], $this->tk );

		self::assertTrue( $this->draw->overview( $this->cup_a )['proposal']['stale'] );
		$error = self::expect_error( fn() => $this->draw->release( $this->cup_a, $this->tk ), 409 );
		self::assertStringContainsString( 'neu auslosen', $error->getMessage() );
		self::assertSame( array(), $this->repo->ties( $this->cup_a ) );

		$this->draw->propose( $this->cup_a, $this->tk, 1 );
		self::assertSame( 7, $this->draw->release( $this->cup_a, $this->tk )['ties'] );
	}

	public function test_the_draw_is_released_in_the_active_season_only(): void {
		$this->teams( 4 );
		$this->draw->propose( $this->cup_a, $this->tk, 1 );
		$this->db->update( 'season', array( 'status' => 'planned' ), array( 'id' => $this->season ) );

		$error = self::expect_error( fn() => $this->draw->release( $this->cup_a, $this->tk ), 409 );
		self::assertStringContainsString( 'aktiven Saison', $error->getMessage() );
		self::expect_error( fn() => $this->draw->overview( 99999 ), 404 );
	}

	public function test_fixtures_of_a_round_reach_the_date_proposal_of_the_postponement_module(): void {
		$ids = $this->teams( 8 );
		$this->release( array_keys( $ids ), $ids, '2026-11-30' );
		$fixture       = $this->fixture_of( 1, 1 );
		$postponements = new PostponementService(
			$this->db,
			new AccessPolicy( $this->db ),
			$this->clock,
			new \DateTimeZone( self::ZONE ),
			new AuditLog( $this->db ),
			new RecordingPostponementEvents()
		);
		$home          = $this->player_actor( 'seefeld', 1 );
		$away          = $this->player_actor( 'wollishofen', 2 );

		$mine = $postponements->mine( $home );
		self::assertSame( array( $fixture ), array_map( static fn( array $item ): int => $item['fixture']['id'], $mine['unscheduled'] ), 'unter «Ohne Termin» in «Mein Bereich»' );
		self::assertTrue( $mine['unscheduled'][0]['can']['request'], 'das Heimteam schlägt vor' );
		self::assertFalse( $postponements->mine( $away )['unscheduled'][0]['can']['request'] );
		self::assertSame( '2026-11-30', $mine['unscheduled'][0]['fixture']['deadline'] );

		// Termin am Tag der Frist: Die Zustimmung des Gegners genügt.
		$request = $postponements->request( $fixture, $home, array( 'new_date' => '2026-11-30 19:30' ) );
		self::assertSame( array( 'propose', false ), array( $request['kind'], $request['needs_approval'] ) );
		self::assertSame( 'approved', $postponements->accept( $request['id'], $away )['status'] );
		self::assertSame( '2026-11-30 19:30:00', $this->fixture_row( $fixture )['scheduled_at'] );
	}
}
