<?php
declare(strict_types=1);

namespace TTLeague\Tests\Integration\Rating;

use TTLeague\Rating\RatingService;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

final class SinglesOnlyTest extends IntegrationTestCase {

	public function test_doubles_alone_cannot_create_or_reset_a_singles_rating(): void {
		( new Seeder( $this->db ) )->scenario();
		$this->db->update( 'game', array( 'status' => 'not_played' ), array( 'type' => 'single' ) );
		$service = new RatingService( $this->db );
		self::assertNotEmpty( $service->recalc()->events, 'Bei positivem Doppelgewicht zählen die gespielten Doppel.' );
		$this->db->update( 'season', array( 'rules_json' => '{"rating_doubles_weight":0}' ), array( 'status' => 'active' ) );
		$result = $service->recalc();
		self::assertSame( array(), $result->ratings );
		self::assertSame( array(), $result->events );
	}
}
