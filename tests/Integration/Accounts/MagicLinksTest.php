<?php
/**
 * Tests der Einmal-Tokens für die Anmeldung per Link.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Accounts;

use TTLeague\Accounts\MagicLinks;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

final class MagicLinksTest extends IntegrationTestCase {

	private FixedClock $clock;

	private MagicLinks $links;

	private int $person;

	protected function setUp(): void {
		parent::setUp();
		$this->clock  = new FixedClock( '2026-11-10 12:00:00' );
		$this->links  = new MagicLinks( $this->db, $this->clock );
		$this->person = ( new Seeder( $this->db ) )->person( 'Muster', 'Hans' );
	}

	public function test_token_is_url_safe_and_only_its_hash_is_stored(): void {
		$token = $this->links->create( $this->person, MagicLinks::LOGIN_TTL, '192.0.2.10' );

		self::assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $token );
		$row = $this->db->get_row( "SELECT * FROM {$this->db->table( 'login_token' )}" );
		self::assertNotNull( $row );
		self::assertSame( hash( 'sha256', $token ), $row['token_hash'] );
		self::assertStringNotContainsString( $token, (string) json_encode( $row ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Tests laufen ohne WordPress.
		self::assertSame( '2026-11-10 12:15:00', $row['expires_at'] );
		self::assertSame( '192.0.2.10', $row['requested_ip'] );
	}

	public function test_peek_does_not_consume(): void {
		$token = $this->links->create( $this->person, MagicLinks::LOGIN_TTL );

		self::assertSame( $this->person, $this->links->peek( $token ) );
		self::assertSame( $this->person, $this->links->peek( $token ) );
		self::assertSame( $this->person, $this->links->consume( $token ) );
	}

	public function test_consume_works_only_once(): void {
		$token = $this->links->create( $this->person, MagicLinks::LOGIN_TTL );

		self::assertSame( $this->person, $this->links->consume( $token ) );
		self::assertNull( $this->links->consume( $token ) );
		self::assertNull( $this->links->peek( $token ) );
	}

	public function test_expired_token_is_rejected(): void {
		$token = $this->links->create( $this->person, MagicLinks::LOGIN_TTL );

		$this->clock->advance( '+14 minutes' );
		self::assertSame( $this->person, $this->links->peek( $token ) );

		$this->clock->advance( '+1 minute' );
		self::assertNull( $this->links->peek( $token ) );
		self::assertNull( $this->links->consume( $token ) );
	}

	public function test_invitation_token_lives_seven_days(): void {
		$token = $this->links->create( $this->person, MagicLinks::INVITE_TTL );

		$this->clock->advance( '+6 days 23 hours' );
		self::assertSame( $this->person, $this->links->peek( $token ) );
		$this->clock->advance( '+1 hour' );
		self::assertNull( $this->links->consume( $token ) );
	}

	public function test_malformed_or_unknown_tokens_are_rejected(): void {
		$this->links->create( $this->person, MagicLinks::LOGIN_TTL );

		self::assertNull( $this->links->peek( '' ) );
		self::assertNull( $this->links->peek( "abc' OR 1=1 --" ) );
		self::assertNull( $this->links->consume( str_repeat( 'A', 43 ) ) );
		self::assertNull( $this->links->consume( str_repeat( 'A', 44 ) ) );
	}

	public function test_purge_removes_old_tokens_only(): void {
		$used    = $this->links->create( $this->person, MagicLinks::LOGIN_TTL );
		$expired = $this->links->create( $this->person, MagicLinks::LOGIN_TTL );
		$this->links->consume( $used );
		$this->clock->advance( '+2 days' );
		$fresh = $this->links->create( $this->person, MagicLinks::LOGIN_TTL );

		self::assertSame( 2, $this->links->purge() );
		self::assertSame( $this->person, $this->links->peek( $fresh ) );
		self::assertNull( $this->links->peek( $expired ) );
	}
}
