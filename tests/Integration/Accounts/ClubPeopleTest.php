<?php
/**
 * Integrationstests der Personenliste eines Clubs mit Kontostatus.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Accounts;

use TTLeague\Accounts\ClubPeople;
use TTLeague\Accounts\MagicLinks;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Erfundener Club Seefeld mit TK-Chefin, Captain und Spielern in allen Kontozuständen;
 * dazu Wollishofen und eine archivierte Saison, die nicht in die Liste gehören.
 */
final class ClubPeopleTest extends IntegrationTestCase {

	private FixedClock $clock;

	private FakeUsers $users;

	private ClubPeople $people;

	private Seeder $seed;

	/** @var array<string,int> */
	private array $id = array();

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->clock  = new FixedClock( '2026-11-20 11:00:00' );
		$this->users  = new FakeUsers();
		$this->people = new ClubPeople( $this->db, $this->users, $this->clock );
		$this->seed   = new Seeder( $this->db );

		$old    = $this->seed->season( '2025-26', 'archived' );
		$season = $this->seed->season( '2026-27', 'active' );
		$club   = $this->seed->club( 'Seefeld', 1 );
		$other  = $this->seed->club( 'Wollishofen', 2 );
		$this->seed->club( 'Oerlikon', 3 );
		$group = $this->seed->competition( $season, 'MM', 'Gruppe B1', 'B', 1 );
		$team  = $this->seed->team( $season, $club, 1, 'Seefeld 1', $group );

		$this->id = array(
			'season' => $season,
			'old'    => $old,
			'club'   => $club,
			'other'  => $other,
			'team'   => $team,
			'chef'   => $this->seed->person( 'Chef', 'Charlotte' ),
			'kurt'   => $this->player( 'Konto', 'Kurt', $season, $club ),
			'olga'   => $this->player( 'Ohne', 'Olga', $season, $club ),
			'paul'   => $this->player( 'Spieler', 'Paul', $season, $club ),
			'lena'   => $this->player( 'Login', 'Lena', $season, $club ),
			'hanna'  => $this->player( 'Heim', 'Hanna', $season, $club ),
			'gone'   => $this->player( 'Weg', 'Walter', $season, $club ),
			'former' => $this->player( 'Früher', 'Frida', $old, $club ),
			'fritz'  => $this->player( 'Fremd', 'Fritz', $season, $other ),
		);
		$this->seed->role( $season, $club, $this->id['chef'], 'tk_chef' );
		$this->seed->role( $season, $club, $this->id['hanna'], 'captain', $team );
		$this->seed->role( $old, $club, $this->id['former'], 'captain' );

		$this->contact( 'chef', 'chef@example.org', 201 );
		$this->contact( 'hanna', 'hanna@example.org', 202 );
		$this->contact( 'paul', 'paul@example.org' );
		$this->contact( 'kurt', 'kurt@example.org', 203 );
		$this->contact( 'lena', 'lena@example.org', 204 );
		$this->contact( 'gone', 'walter@example.org', 299 );
		$this->contact( 'olga', 'kein-mail' );

		$this->users->add( 201, 'chef@example.org', false, false, 1763000000 );
		$this->users->add( 202, 'hanna@example.org' );
		$this->users->add( 203, 'kurt@example.org' );
		$this->users->add( 204, 'lena@example.org', true );

		$links = new MagicLinks( $this->db, $this->clock );
		$links->create( $this->id['hanna'], MagicLinks::INVITE_TTL );
		$links->create( $this->id['lena'], MagicLinks::LOGIN_TTL );
		$used = $links->create( $this->id['paul'], MagicLinks::INVITE_TTL );
		$links->consume( $used );
		$this->clock->advance( '-8 days' );
		$links->create( $this->id['kurt'], MagicLinks::INVITE_TTL );
		$this->clock->advance( '+8 days' );
	}

	/**
	 * Spieler anlegen, für die Saison melden, Person liefern.
	 */
	private function player( string $last, string $first, int $season, int $club ): int {
		$player = $this->seed->player( $last, $first );
		$this->seed->register( $player, $season, $club, 10 );
		return $this->seed->person_of( $player );
	}

	private function contact( string $key, ?string $email, ?int $user_id = null ): void {
		$this->db->update(
			'person',
			array(
				'email'   => $email,
				'user_id' => $user_id,
			),
			array( 'id' => $this->id[ $key ] )
		);
	}

	/**
	 * @return array<string,array<string,mixed>> Name => Person.
	 */
	private function by_name(): array {
		return array_column( $this->people->for_club( $this->id['season'], $this->id['club'] ), null, 'name' );
	}

	public function test_lists_players_and_officials_of_the_active_season_once(): void {
		$list = $this->people->for_club( $this->id['season'], $this->id['club'] );

		self::assertSame(
			array( 'Charlotte Chef', 'Hanna Heim', 'Kurt Konto', 'Lena Login', 'Olga Ohne', 'Paul Spieler', 'Walter Weg' ),
			array_column( $list, 'name' ),
			'TK-Chef, dann Captains, dann Spieler nach Nachname'
		);
		$hanna = $list[1];
		self::assertSame(
			array(
				array(
					'role' => 'captain',
					'team' => 'Seefeld 1',
				),
			),
			$hanna['functions']
		);
		self::assertTrue( $hanna['player'] );
		self::assertFalse( $list[0]['player'] );
		self::assertSame(
			array(
				array(
					'role' => 'tk_chef',
					'team' => null,
				),
			),
			$list[0]['functions']
		);
	}

	public function test_account_status(): void {
		$people = $this->by_name();

		self::assertSame( ClubPeople::ACTIVE, $people['Charlotte Chef']['status'] );
		self::assertSame( 1763000000, $people['Charlotte Chef']['last_login'] );
		self::assertSame( ClubPeople::INVITED, $people['Hanna Heim']['status'] );
		self::assertSame( '2026-11-27 11:00:00', $people['Hanna Heim']['invited_until'] );
		self::assertSame( ClubPeople::NO_ACCOUNT, $people['Paul Spieler']['status'], 'verbrauchte Einladung' );
		self::assertSame( ClubPeople::ACCOUNT, $people['Kurt Konto']['status'], 'abgelaufene Einladung' );
		self::assertSame( ClubPeople::ACCOUNT, $people['Lena Login']['status'], 'ein Anmeldelink ist keine Einladung' );
		self::assertTrue( $people['Lena Login']['privileged'] );
		self::assertSame( ClubPeople::NO_EMAIL, $people['Olga Ohne']['status'], 'ungültige Adresse' );
		self::assertSame( ClubPeople::NO_ACCOUNT, $people['Walter Weg']['status'], 'verknüpftes Konto gibt es nicht mehr' );
		self::assertNull( $people['Walter Weg']['user_id'] );
		self::assertSame( 1, $this->users->lookups, 'ein Aufruf für alle Konten' );
	}

	public function test_active_wins_over_open_invitation(): void {
		( new MagicLinks( $this->db, $this->clock ) )->create( $this->id['chef'], MagicLinks::INVITE_TTL );
		$chef = $this->by_name()['Charlotte Chef'];

		self::assertSame( ClubPeople::ACTIVE, $chef['status'] );
		self::assertNotNull( $chef['invited_until'] );
	}

	public function test_consent_counts_as_login_before_the_timestamp_existed(): void {
		$this->db->update( 'person', array( 'consent_at' => '2026-11-02 18:00:00' ), array( 'id' => $this->id['kurt'] ) );
		$this->db->update( 'person', array( 'consent_at' => '2026-11-02 18:00:00' ), array( 'id' => $this->id['paul'] ) );
		$people = $this->by_name();

		self::assertSame( ClubPeople::ACTIVE, $people['Kurt Konto']['status'] );
		self::assertNull( $people['Kurt Konto']['last_login'] );
		self::assertSame( '2026-11-02 18:00:00', $people['Kurt Konto']['consent_at'] );
		self::assertSame( ClubPeople::NO_ACCOUNT, $people['Paul Spieler']['status'], 'ohne Konto nicht aktiv' );
	}

	public function test_clubs_of_the_active_season(): void {
		$clubs = $this->people->clubs( $this->id['season'] );

		self::assertSame( array( 'TTC Seefeld', 'TTC Wollishofen' ), array_column( $clubs, 'name' ) );
		self::assertSame( 'TTC Seefeld', $this->people->club_name( $this->id['club'] ) );
		self::assertSame( array(), $this->people->for_club( $this->id['season'], 999 ) );
	}
}
