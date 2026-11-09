<?php
/**
 * Integrationstests der Auflösung von Adressen: Objekte, Titel, 404 und Weiterleitungen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Frontend;

use TTLeague\Frontend\Resolver;
use TTLeague\Frontend\Support\Urls;
use TTLeague\ReadModel\Cache\NullCache;
use TTLeague\ReadModel\ReadModel;
use TTLeague\Tests\Integration\IntegrationTestCase;

final class ResolverTest extends IntegrationTestCase {

	private Resolver $resolver;

	/**
	 * @var array<string,int>
	 */
	private array $ids;

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->ids      = ( new Seeder( $this->db ) )->scenario();
		$this->resolver = new Resolver( new ReadModel( $this->db, new NullCache() ), new Urls( 'https://liga.example/' ) );
	}

	public function test_listing_pages_default_to_active_season(): void {
		$context = $this->resolver->resolve( 'championship', array() );
		self::assertSame( '2026-27', $context->season['slug'] );
		self::assertSame( 'Meisterschaft 2026/27', $context->title );
		self::assertSame( 'https://liga.example/meisterschaft/2026-27/', $context->canonical );

		$context = $this->resolver->resolve( 'clubs', array(), array( 'saison' => '2025-26' ) );
		self::assertSame( 'Clubs 2025/26', $context->title );

		$context = $this->resolver->resolve( 'championship', array( 'season' => '1999-00' ) );
		self::assertTrue( $context->is_not_found() );
		self::assertStringContainsString( '1999-00', (string) $context->not_found );
	}

	public function test_group_found_missing_and_unknown(): void {
		$context = $this->resolver->resolve(
			'group',
			array(
				'season' => '2026-27',
				'group'  => 'a',
			)
		);
		self::assertSame( 'Gruppe A', $context->title );
		self::assertSame( 'Gruppe A – Meisterschaft 2026/27', $context->doc_title );
		self::assertSame( $this->ids['group_a'], $context->object( 'group' )['id'] );

		$missing = $this->resolver->resolve( 'group', array( 'season' => '2026-27' ) );
		self::assertSame( 'https://liga.example/meisterschaft/', $missing->redirect );
		self::assertSame( 302, $missing->redirect_status );

		$unknown = $this->resolver->resolve(
			'group',
			array(
				'season' => '2026-27',
				'group'  => 'z',
			)
		);
		self::assertTrue( $unknown->is_not_found() );
	}

	public function test_fixture_and_team(): void {
		$context = $this->resolver->resolve( 'fixture', array( 'fixture' => (string) $this->ids['fixture_1'] ) );
		self::assertSame( 'Seefeld 1 – Altstetten 1', $context->title );
		self::assertSame( 'Seefeld 1 – Altstetten 1 – Begegnung 2026/27', $context->doc_title );
		self::assertSame( '2026-27', $context->season['slug'] );

		$cup = $this->resolver->resolve( 'fixture', array( 'fixture' => (string) $this->ids['cup_fixture'] ) );
		self::assertStringContainsString( 'Cupbegegnung', (string) $cup->doc_title );

		self::assertTrue( $this->resolver->resolve( 'fixture', array( 'fixture' => '999999' ) )->is_not_found() );

		$team = $this->resolver->resolve(
			'team',
			array(
				'season' => '2025-26',
				'team'   => 'seefeld-1',
			)
		);
		self::assertSame( $this->ids['team_old_see'], $team->object( 'team' )['id'] );
		self::assertSame( 'Seefeld 1 – Mannschaft 2025/26', $team->doc_title );
		self::assertTrue(
			$this->resolver->resolve(
				'team',
				array(
					'season' => '2025-26',
					'team'   => 'bergwiese-1',
				)
			)->is_not_found()
		);
	}

	public function test_player_slug_redirects_and_unknown_license(): void {
		$ok = $this->resolver->resolve(
			'player',
			array(
				'license' => '90001',
				'name'    => 'hans-muster',
			)
		);
		self::assertSame( 'Hans Muster', $ok->title );
		self::assertSame( 'Hans Muster – Spieler', $ok->doc_title );
		self::assertNull( $ok->redirect );

		$wrong = $this->resolver->resolve(
			'player',
			array(
				'license' => '90001',
				'name'    => 'falsch',
			),
			array( 'saison' => '2025-26' )
		);
		self::assertSame( 'https://liga.example/spieler/90001-hans-muster/?saison=2025-26', $wrong->redirect );
		self::assertSame( 301, $wrong->redirect_status );

		$bare = $this->resolver->resolve( 'player', array( 'license' => '90016' ) );
		self::assertSame( 'https://liga.example/spieler/90016-juerg-muesterli/', $bare->redirect );

		self::assertTrue( $this->resolver->resolve( 'player', array( 'license' => '12' ) )->is_not_found() );
		self::assertSame( 'https://liga.example/spieler/', $this->resolver->resolve( 'player', array() )->redirect );
	}

	public function test_club_and_cup(): void {
		$club = $this->resolver->resolve( 'club', array( 'club' => 'seefeld' ) );
		self::assertSame( 'TTC Seefeld', $club->title );
		self::assertSame( 'https://liga.example/clubs/', $this->resolver->resolve( 'club', array( 'club' => 'club' ) )->redirect, 'Detailseite ohne Club' );
		self::assertTrue( $this->resolver->resolve( 'club', array( 'club' => 'unbekannt' ) )->is_not_found() );

		$cup = $this->resolver->resolve(
			'cup',
			array(
				'season'   => '2026-27',
				'category' => 'a',
			)
		);
		self::assertSame( 'Mannschaftscup A 2026/27', $cup->title );
		self::assertTrue(
			$this->resolver->resolve(
				'cup',
				array(
					'season'   => '2026-27',
					'category' => 'b',
				)
			)->is_not_found()
		);
	}
}
