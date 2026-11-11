<?php
/**
 * Provisorisch gemeldete Spieler der aktiven Saison erscheinen öffentlich nicht als gemeldet.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Registration;

use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\ReadModel\Cache\NullCache;
use TTLeague\ReadModel\ReadModel;
use TTLeague\Results\EligibilityLoader;

/**
 * Daten siehe RegistrationTestCase: Erika Ehemalig war nur 2025/26 gemeldet und wird in der
 * aktiven Saison 2026/27 nachgemeldet.
 */
final class PublicListsTest extends RegistrationTestCase {

	/**
	 * @return list<string>
	 */
	private function listed( ReadModel $model ): array {
		return array_map( static fn( array $row ): string => $row['player']['name'], $model->players->season_list( $this->id['now'] ) );
	}

	public function test_a_late_registration_is_public_only_after_the_approval(): void {
		$this->at( '2026-12-01 10:00:00' );
		$this->registration->confirm_player( $this->actor['chef'], $this->id['now'], $this->id['home'], $this->id['p_ehemalig'] );
		$model = new ReadModel( $this->db, new NullCache() );

		self::assertNotContains( 'Erika Ehemalig', $this->listed( $model ) );
		self::assertCount( 5, $this->listed( $model ) );
		$clubs = array_column( $model->clubs->season_list( $this->id['now'] ), 'players', null );
		self::assertSame( array( 4, 1 ), array_values( $clubs ), 'Seefeld 4, Wollishofen 1' );
		self::assertNotContains( 'Erika Ehemalig', array_map( static fn( array $row ): string => $row['player']['name'], $model->clubs->details( $this->id['home'], $this->id['now'] )['players'] ) );
		self::assertSame( array( '2025-26' ), array_column( $model->profiles->get( $this->id['p_ehemalig'] )['seasons'], 'slug' ) );
		$candidates = ( new EligibilityLoader( $this->db ) )->for_club( $this->id['now'], $this->id['home'], new SeasonRules(), 0 );
		self::assertNotContains( $this->id['p_ehemalig'], array_keys( $candidates['players'] ), 'die Erfassung lässt sie noch nicht spielen' );

		$this->review->approve_player( $this->actor['tk'], $this->id['now'], $this->id['home'], $this->id['p_ehemalig'] );

		$model = new ReadModel( $this->db, new NullCache() );
		self::assertContains( 'Erika Ehemalig', $this->listed( $model ) );
		self::assertSame( array( '2026-27', '2025-26' ), array_column( $model->profiles->get( $this->id['p_ehemalig'] )['seasons'], 'slug' ) );
	}
}
