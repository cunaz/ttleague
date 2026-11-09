<?php
/**
 * Tests des Adressbaus und der Rewrite-Regeln.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use TTLeague\Frontend\Routes;
use TTLeague\Frontend\Shortcode;
use TTLeague\Frontend\Support\Urls;
use TTLeague\ReadModel\Championship;
use TTLeague\ReadModel\Support\Slug;

final class UrlsTest extends TestCase {

	private Urls $urls;

	protected function setUp(): void {
		parent::setUp();
		$this->urls = new Urls( 'https://liga.example/' );
	}

	public function test_url_scheme(): void {
		self::assertSame( 'https://liga.example/meisterschaft/', $this->urls->championship() );
		self::assertSame( 'https://liga.example/meisterschaft/2024-25/', $this->urls->championship( '2024-25' ) );
		self::assertSame( 'https://liga.example/meisterschaft/2024-25/gruppe-a/', $this->urls->group( '2024-25', 'a' ) );
		self::assertSame( 'https://liga.example/meisterschaft/2024-25/gruppe-a-2/', $this->urls->group( '2024-25', 'a-2' ) );
		self::assertSame( 'https://liga.example/begegnung/123/', $this->urls->fixture( 123 ) );
		self::assertSame( 'https://liga.example/mannschaft/2024-25/seefeld-1/', $this->urls->team( '2024-25', 'seefeld-1' ) );
		self::assertSame( 'https://liga.example/clubs/', $this->urls->clubs() );
		self::assertSame( 'https://liga.example/clubs/?saison=2024-25', $this->urls->clubs( '2024-25' ) );
		self::assertSame( 'https://liga.example/clubs/seefeld/?saison=2024-25', $this->urls->club( 'seefeld', '2024-25' ) );
		self::assertSame( 'https://liga.example/spieler/', $this->urls->players() );
		self::assertSame( 'https://liga.example/spieler/?saison=2024-25&club=seefeld&q=hans%20m', $this->urls->players( '2024-25', 'seefeld', 'hans m' ) );
		self::assertSame( 'https://liga.example/spieler/90001-hans-muster/', $this->urls->player( 90001, 'hans-muster' ) );
		self::assertSame( 'https://liga.example/spieler/90001-hans-muster/?saison=2025-26', $this->urls->player( 90001, 'hans-muster', '2025-26' ) );
		self::assertSame( 'https://liga.example/ranglistenturnier/', $this->urls->ranking() );
		self::assertSame( 'https://liga.example/ranglistenturnier/2024-25/', $this->urls->ranking( '2024-25' ) );
		self::assertSame( 'https://liga.example/cup/', $this->urls->cup() );
		self::assertSame( 'https://liga.example/cup/2024-25/', $this->urls->cup( '2024-25' ) );
		self::assertSame( 'https://liga.example/cup/2024-25/a/', $this->urls->cup( '2024-25', 'A' ) );
		self::assertSame( 'https://liga.example/archiv/', $this->urls->archive() );
	}

	public function test_renamed_pages_and_subdirectory(): void {
		$urls = new Urls(
			'https://example.org/liga',
			array(
				'championship' => 'mm',
				'players'      => 'personen/spieler',
			)
		);
		self::assertSame( 'https://example.org/liga/mm/2026-27/gruppe-b/', $urls->group( '2026-27', 'b' ) );
		self::assertSame( 'https://example.org/liga/personen/spieler/90002-anna-beispiel/', $urls->player( 90002, 'anna-beispiel' ) );
		self::assertSame( 'https://example.org/liga/begegnung/7/', $urls->fixture( 7 ) );
	}

	public function test_segments_and_season_slugs(): void {
		self::assertSame( 'a', Urls::group_from_segment( 'gruppe-a' ) );
		self::assertSame( 'a-2', Urls::group_from_segment( 'gruppe-a-2' ) );
		self::assertNull( Urls::group_from_segment( 'gruppe-a/b' ) );
		self::assertTrue( Urls::is_season_slug( '2024-25' ) );
		self::assertFalse( Urls::is_season_slug( '2024/25' ) );
		self::assertSame( 'a', Championship::group_slug( 'A', 1, 5 ) );
		self::assertSame( 'a-2', Championship::group_slug( 'A', 2, 5 ) );
		self::assertSame( 'g5', Championship::group_slug( null, 1, 5 ) );
	}

	public function test_slugs_follow_german_convention(): void {
		self::assertSame( 'juerg-muesterli', Slug::make( 'Jürg Müsterli' ) );
		self::assertSame( 'seefeld-1', Slug::make( 'Seefeld 1' ) );
		self::assertSame( 'gaeste', Slug::make( 'Gäste' ) );
		self::assertSame( 'strasse-gross', Slug::make( 'Straße groß' ) );
		self::assertSame( 'cafe-creme', Slug::make( 'Café Crème' ) );
		self::assertSame( 'muster-partner', Slug::make( 'Muster & Partner' ) );
		self::assertSame( 'jurg-musterli', Slug::fold( 'Jürg Müsterli' ) );
	}

	public function test_rewrite_rules_map_urls_to_pages(): void {
		$ids   = array(
			'championship' => 10,
			'group'        => 11,
			'fixture'      => 12,
			'team'         => 13,
			'club'         => 15,
			'player'       => 17,
			'ranking'      => 18,
			'cup'          => 19,
		);
		$rules = Routes::rules( $ids, array() );
		$match = static function ( string $path ) use ( $rules ): ?string {
			foreach ( $rules as $regex => $query ) {
				if ( preg_match( '#' . $regex . '#', $path, $m ) ) {
					return (string) preg_replace_callback( '/\$matches\[(\d+)\]/', static fn( array $x ): string => $m[ (int) $x[1] ] ?? '', $query );
				}
			}
			return null;
		};
		self::assertSame( 'index.php?page_id=10&ttl_season=2024-25', $match( 'meisterschaft/2024-25/' ) );
		self::assertSame( 'index.php?page_id=11&ttl_season=2024-25&ttl_group=a-2', $match( 'meisterschaft/2024-25/gruppe-a-2/' ) );
		self::assertSame( 'index.php?page_id=12&ttl_fixture=123', $match( 'begegnung/123/' ) );
		self::assertSame( 'index.php?page_id=13&ttl_season=2026-27&ttl_team=seefeld-1', $match( 'mannschaft/2026-27/seefeld-1/' ) );
		self::assertSame( 'index.php?page_id=15&ttl_club=seefeld', $match( 'clubs/seefeld/' ) );
		self::assertSame( 'index.php?page_id=17&ttl_license=90001&ttl_name=hans-muster', $match( 'spieler/90001-hans-muster/' ) );
		self::assertSame( 'index.php?page_id=17&ttl_license=90001&ttl_name=', $match( 'spieler/90001' ) );
		self::assertSame( 'index.php?page_id=18&ttl_season=2024-25', $match( 'ranglistenturnier/2024-25/' ) );
		self::assertSame( 'index.php?page_id=19&ttl_season=2024-25&ttl_category=a', $match( 'cup/2024-25/a/' ) );
		self::assertNull( $match( 'meisterschaft/gruppe/' ), 'die Seite selbst bleibt erreichbar' );
		self::assertNull( $match( 'spieler/profil/' ) );
		self::assertSame( array(), Routes::rules( array(), array() ) );
	}

	public function test_shortcode_view_aliases(): void {
		self::assertSame( 'group', Shortcode::view_name( 'Gruppe' ) );
		self::assertSame( 'match-report', Shortcode::view_name( 'begegnung' ) );
		self::assertSame( 'ranking-tournament', Shortcode::view_name( 'ranking-tournament' ) );
		self::assertNull( Shortcode::view_name( 'unbekannt' ) );
	}
}
