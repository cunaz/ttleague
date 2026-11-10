<?php
/**
 * Tests des Saisonkontexts der Schreibseite.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Season;

use TTLeague\Season\SeasonRepository;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

final class SeasonRepositoryTest extends IntegrationTestCase {

	public function test_active_and_planned_season_are_found_by_status(): void {
		$s = new Seeder( $this->db );
		$s->season( '2025-26', 'archived' );
		$active  = $s->season( '2026-27', 'active' );
		$planned = $s->season( '2027-28', 'planned' );
		$repo    = new SeasonRepository( $this->db );

		self::assertSame( $active, $repo->active()['id'] ?? null );
		self::assertSame( $planned, $repo->planned()['id'] ?? null );
		self::assertSame( 'planned', $repo->by_id( $planned )['status'] ?? null, 'auch geplante Saisons über die ID' );
		self::assertNull( $repo->by_id( 999 ) );
	}

	public function test_without_planned_season_there_is_none(): void {
		( new Seeder( $this->db ) )->season( '2026-27', 'active' );
		$repo = new SeasonRepository( $this->db );

		self::assertNull( $repo->planned() );
		self::assertNotNull( $repo->active() );
	}

	public function test_season_carries_registration_window_schedule_and_classification_state(): void {
		$id = ( new Seeder( $this->db ) )->season( '2027-28', 'planned', array( 'schedule_tables_per_match' => 3 ) );
		$this->db->update(
			'season',
			array(
				'registration_opens'       => '2027-05-01',
				'registration_closes'      => '2027-06-15',
				'late_registration_until'  => '2027-12-31',
				'schedule_json'            => '{"first_half":{"from":"2027-09-06","to":"2027-12-17"}}',
				'classification_closed_at' => '2027-04-30 20:15:00',
			),
			array( 'id' => $id )
		);
		$repo   = new SeasonRepository( $this->db );
		$season = $repo->planned();

		self::assertNotNull( $season );
		self::assertSame( '2027-05-01', $season['registration_opens'] );
		self::assertSame( '2027-06-15', $season['registration_closes'] );
		self::assertSame( '2027-12-31', $season['late_registration_until'] );
		self::assertSame( '2027-09-06', $season['schedule']['first_half']['from'] );
		self::assertSame( '2027-04-30 20:15:00', $season['classification_closed_at'] );
		self::assertSame( 3, $repo->rules( $season )->schedule_tables_per_match );
	}

	public function test_empty_fields_are_null_and_empty_schedule_is_an_array(): void {
		( new Seeder( $this->db ) )->season( '2026-27', 'active' );
		$season = ( new SeasonRepository( $this->db ) )->active();

		self::assertNotNull( $season );
		self::assertNull( $season['registration_opens'] );
		self::assertNull( $season['classification_closed_at'] );
		self::assertSame( array(), $season['schedule'] );
	}

	public function test_invalid_rules_are_not_replaced_by_defaults_on_the_write_side(): void {
		( new Seeder( $this->db ) )->season( '2026-27', 'active', array( 'rl_group_size' => 1 ) );
		$repo = new SeasonRepository( $this->db );

		$this->expectException( \InvalidArgumentException::class );
		$repo->rules( (array) $repo->active() );
	}

	public function test_imported_rounds_entries_and_participants_count_as_closed_and_active(): void {
		$s      = new Seeder( $this->db );
		$season = $s->season( '2026-27', 'active' );
		$rl     = $s->competition( $season, 'RL', 'Ranglistenturnier' );
		$cup    = $s->competition( $season, 'CUP', 'Cup A', 'A' );
		$club   = $s->club( 'Seefeld', 1 );
		$player = $s->player( 'Spieler', 'Paul' );

		// So schreibt der Import: ohne die Spalten der Phase 3.
		$round = $this->db->insert(
			'rl_round',
			array(
				'competition_id' => $rl,
				'round_no'       => 1,
			)
		);
		$part  = $this->db->insert(
			'rl_participant',
			array(
				'competition_id' => $rl,
				'player_id'      => $player,
				'category'       => 1,
			)
		);
		$entry = $this->db->insert(
			'cup_entry',
			array(
				'competition_id' => $cup,
				'club_id'        => $club,
				'number'         => 1,
				'name'           => 'Seefeld 1',
			)
		);

		self::assertSame( 'closed', $this->db->get_var( "SELECT status FROM {$this->db->table( 'rl_round' )} WHERE id = %d", array( $round ) ) );
		self::assertSame( 'registered', $this->db->get_var( "SELECT status FROM {$this->db->table( 'rl_participant' )} WHERE id = %d", array( $part ) ) );
		self::assertSame( 'active', $this->db->get_var( "SELECT status FROM {$this->db->table( 'cup_entry' )} WHERE id = %d", array( $entry ) ) );
	}
}
