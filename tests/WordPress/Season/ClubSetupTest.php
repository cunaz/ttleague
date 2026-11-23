<?php
/**
 * Erste Clubmeldung über die Formulare auf einer leeren Installation.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Season;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\Blocks;
use TTLeague\Accounts\LoginFlow;
use TTLeague\Accounts\Services;
use TTLeague\Domain\Season\SeasonDates;
use TTLeague\Infrastructure\Flash;
use TTLeague\Registration\Actions;
use TTLeague\Season\SeasonActions;
use TTLeague\Season\SeasonPage;
use TTLeague\Season\SeasonRepository;
use TTLeague\Tests\WordPress\TestCase;
use TTLeague\Tk\Tk;

final class ClubSetupTest extends TestCase {

	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * @param array<string,string|int> $fields Formulardaten.
	 */
	private function submit( string $action, string $nonce, array $fields ): void {
		$_POST    = wp_slash( $fields + array( '_wpnonce' => wp_create_nonce( $nonce ) ) );
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Der Test schickt den Nonce mit.
		$this->redirect_of( static fn() => do_action( 'admin_post_' . $action ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- admin-post.php von WordPress.
	}

	private function count_rows( string $table ): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( $table )}" );
	}

	public function test_first_club_registers_a_team_via_invitation_without_seed_data_or_licenses(): void {
		wp_set_current_user( $this->account( 'ttl_tk', 'tk@example.invalid' ) );
		self::assertSame( 0, $this->count_rows( 'club' ) );
		self::assertSame( 0, $this->count_rows( 'person' ) );
		$today = current_datetime()->format( 'Y-m-d' );
		$this->submit( SeasonActions::CREATE, SeasonActions::CREATE . '_0', SeasonDates::upcoming( $today ) );
		$season = ( new SeasonRepository( $this->db ) )->planned()['id'];
		$this->submit(
			SeasonActions::WINDOW,
			SeasonActions::WINDOW . '_' . $season,
			array(
				'season'              => $season,
				'registration_opens'  => $today,
				'registration_closes' => $today,
			)
		);
		self::assertStringContainsString( SeasonActions::CLUB_CREATE, SeasonPage::html( SeasonPage::CREATE, 0, null ) );
		$club_fields = array(
			'season'     => $season,
			'number'     => '1',
			'name'       => 'TTC Neuland',
			'short_name' => 'Neuland',
			'slug'       => 'neuland',
		);
		$this->submit( SeasonActions::CLUB_CREATE, SeasonActions::CLUB_CREATE . '_' . $season, $club_fields );
		$this->submit( SeasonActions::CLUB_CREATE, SeasonActions::CLUB_CREATE . '_' . $season, $club_fields );
		self::assertSame( 1, $this->count_rows( 'club' ) );
		self::assertSame( 1, $this->count_rows( 'club_season' ) );
		$club   = (int) $this->db->get_var( "SELECT id FROM {$this->db->table( 'club' )}" );
		$fields = array(
			'season'     => $season,
			'club'       => $club,
			'first_name' => 'Kim',
			'last_name'  => 'Neu',
			'email'      => 'kim@example.invalid',
		);
		self::assertStringContainsString( SeasonActions::OFFICER_CREATE, SeasonPage::html( SeasonPage::CREATE, 0, null ) );
		$this->submit( SeasonActions::OFFICER_CREATE, SeasonActions::OFFICER_CREATE . '_' . $season, array( 'email' => 'tk@example.invalid' ) + $fields );
		self::assertSame( 0, $this->count_rows( 'person' ), 'Eine vergebene Kontoadresse erzeugt keine automatische Zuordnung.' );
		$this->submit( SeasonActions::OFFICER_CREATE, SeasonActions::OFFICER_CREATE . '_' . $season, $fields );
		$this->submit( SeasonActions::OFFICER_CREATE, SeasonActions::OFFICER_CREATE . '_' . $season, $fields );
		self::assertSame( 1, $this->count_rows( 'person' ) );
		self::assertSame( 1, $this->count_rows( 'club_role' ) );
		self::assertSame( 0, $this->count_rows( 'player' ) );
		$person = (int) $this->db->get_var( "SELECT id FROM {$this->db->table( 'person' )}" );
		$target = array(
			'season' => $season,
			'club'   => $club,
			'person' => $person,
		);

		add_filter( 'pre_wp_mail', '__return_false' );
		try {
			$this->submit( SeasonActions::OFFICER_INVITE, SeasonActions::OFFICER_INVITE . '_' . $season, $target );
		} finally {
			remove_filter( 'pre_wp_mail', '__return_false' );
		}
		self::assertSame( 'error', Flash::take( Tk::FLASH, get_current_user_id() )['type'] );
		self::assertSame( 0, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'login_token' )} WHERE used_at IS NULL" ) );

		$this->submit( SeasonActions::OFFICER_INVITE, SeasonActions::OFFICER_INVITE . '_' . $season, $target );
		self::assertSame( 'success', Flash::take( Tk::FLASH, get_current_user_id() )['type'] );
		$mail = end( $this->mails );
		self::assertSame( 'kim@example.invalid', $mail->to );
		self::assertSame( 1, preg_match( '/anmeldung=([A-Za-z0-9_-]{43})/', $mail->text, $match ) );
		self::assertSame( $person, Services::links()->peek( $match[1] ) );

		wp_set_current_user( 0 );
		$_SERVER['HTTP_ORIGIN'] = home_url();
		$this->submit( LoginFlow::LOGIN, LoginFlow::LOGIN, array( 'token' => $match[1] ) );
		self::assertFalse( current_user_can( Capabilities::TK ) );
		self::assertTrue( current_user_can( Capabilities::MANAGE_CLUB, $club ) );
		self::assertStringContainsString( 'einverstanden', Blocks::render_my_area() );
		$this->submit( LoginFlow::CONSENT, LoginFlow::CONSENT, array( 'einverstanden' => '1' ) );
		self::assertStringContainsString( 'Mannschaft hinzufügen', Blocks::render_my_area() );
		$this->submit( Actions::TEAM_ADD, Actions::TEAM_ADD . '_' . $club, $target );
		self::assertSame( 1, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'team' )} WHERE season_id = %d AND club_id = %d", array( $season, $club ) ) );
	}
}
