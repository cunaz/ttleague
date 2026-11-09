<?php
/**
 * Tests der Kalenderadressen, Rewrite-Regeln und bedingten Anfragen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Calendar;

use PHPUnit\Framework\TestCase;
use TTLeague\Calendar\ConditionalGet;
use TTLeague\Calendar\FeedUrls;

final class FeedUrlsTest extends TestCase {

	public function test_feed_addresses(): void {
		$urls = new FeedUrls( 'https://example.org' );
		self::assertSame( 'https://example.org/kalender/team/2026-27/seefeld-1.ics', $urls->team( '2026-27', 'seefeld-1' ) );
		self::assertSame( 'https://example.org/kalender/club/seefeld.ics', $urls->club( 'seefeld' ) );
		self::assertSame( 'https://example.org/kalender/spieler/90001.ics', $urls->player( 90001 ) );
		self::assertSame( 'https://example.org/liga/kalender/club/seefeld.ics', ( new FeedUrls( 'https://example.org/liga/' ) )->club( 'seefeld' ) );
	}

	public function test_webcal_addresses(): void {
		self::assertSame( 'webcal://example.org/kalender/club/seefeld.ics', FeedUrls::webcal( 'https://example.org/kalender/club/seefeld.ics' ) );
		self::assertSame( 'webcal://localhost:8080/kalender/spieler/90001.ics', FeedUrls::webcal( 'http://localhost:8080/kalender/spieler/90001.ics' ) );
		self::assertSame( 'webcal://example.org/x.ics', FeedUrls::webcal( 'HTTPS://example.org/x.ics' ) );
	}

	/**
	 * Wendet die Regeln wie WordPress an: erste passende Regel gewinnt.
	 */
	private static function route( string $path ): ?string {
		foreach ( FeedUrls::rules( 'ttl_ical', 'ttl_ical_season', 'ttl_ical_key' ) as $regex => $query ) {
			if ( preg_match( '#' . $regex . '#', $path, $matches ) ) {
				return (string) preg_replace_callback( '/\$matches\[(\d+)\]/', static fn( array $m ): string => $matches[ (int) $m[1] ], $query );
			}
		}
		return null;
	}

	public function test_rewrite_rules(): void {
		self::assertSame( 'index.php?ttl_ical=team&ttl_ical_season=2026-27&ttl_ical_key=seefeld-1', self::route( 'kalender/team/2026-27/seefeld-1.ics' ) );
		self::assertSame( 'index.php?ttl_ical=team&ttl_ical_season=2026-27&ttl_ical_key=Seefeld-1', self::route( 'kalender/team/2026-27/Seefeld-1.ics' ) );
		self::assertSame( 'index.php?ttl_ical=club&ttl_ical_key=seefeld', self::route( 'kalender/club/seefeld.ics' ) );
		self::assertSame( 'index.php?ttl_ical=player&ttl_ical_key=90001', self::route( 'kalender/spieler/90001.ics' ) );
		self::assertSame( 'index.php?ttl_ical=unknown', self::route( 'kalender/spieler/hans.ics' ) );
		self::assertSame( 'index.php?ttl_ical=unknown', self::route( 'kalender/team/2026/seefeld-1.ics' ) );
		self::assertSame( 'index.php?ttl_ical=unknown', self::route( 'kalender/team/2026-27/seefeld 1.ics' ) );
		self::assertNull( self::route( 'kalender/team/2026-27/seefeld-1' ) );
		self::assertNull( self::route( 'mannschaft/2026-27/seefeld-1/' ) );
		self::assertNull( self::route( 'xkalender/club/seefeld.ics' ) );
	}

	public function test_etag_depends_on_all_parts(): void {
		$etag = ConditionalGet::etag( array( '1', 7, 'team', '2026-27', 'seefeld-1' ) );
		self::assertMatchesRegularExpression( '#^W/"[0-9a-f]{32}"$#', $etag );
		self::assertSame( $etag, ConditionalGet::etag( array( '1', 7, 'team', '2026-27', 'seefeld-1' ) ) );
		self::assertNotSame( $etag, ConditionalGet::etag( array( '1', 8, 'team', '2026-27', 'seefeld-1' ) ), 'neue Datenversion' );
		self::assertNotSame( $etag, ConditionalGet::etag( array( '1', 7, 'team', '2026-27', 'seefeld-2' ) ) );
		self::assertNotSame( ConditionalGet::etag( array( 'ab', 'c' ) ), ConditionalGet::etag( array( 'a', 'bc' ) ), 'Teile sind getrennt' );
	}

	public function test_if_none_match_uses_weak_comparison(): void {
		$etag = 'W/"abc123"';
		self::assertTrue( ConditionalGet::matches( 'W/"abc123"', $etag ) );
		self::assertTrue( ConditionalGet::matches( '"abc123"', $etag ), 'Proxy hat W/ entfernt' );
		self::assertTrue( ConditionalGet::matches( '"x", W/"abc123"', $etag ) );
		self::assertTrue( ConditionalGet::matches( '*', $etag ) );
		self::assertFalse( ConditionalGet::matches( '', $etag ) );
		self::assertFalse( ConditionalGet::matches( 'W/"abc124"', $etag ) );
		self::assertFalse( ConditionalGet::matches( 'abc123', $etag ) );
	}
}
