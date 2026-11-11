<?php
/**
 * Kontaktdaten der Captains und TK-Chefs (Contacts) gegen die Datenbank.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Accounts;

use TTLeague\Accounts\Contacts;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Erfundene Clubs Seefeld und Wollishofen. Seefeld 1 empfängt Wollishofen 1, Seefeld 1
 * empfängt Seefeld 2 (Clubduell), im Cup trifft Seefeld 1 auf Wollishofen 1.
 */
final class ContactsTest extends IntegrationTestCase {

	private Contacts $contacts;

	/** @var array<string,int> */
	private array $id = array();

	protected function setUp(): void {
		parent::setUp();
		$s    = new Seeder( $this->db );
		$old  = $s->season( '2025-26', 'archived' );
		$now  = $s->season( '2026-27', 'active' );
		$home = $s->club( 'Seefeld', 1 );
		$away = $s->club( 'Wollishofen', 2 );

		$group = $s->competition( $now, 'MM', 'Gruppe A', 'A', 1 );
		$cup   = $s->competition( $now, 'CUP', 'Cup A', 'A' );
		$oldg  = $s->competition( $old, 'MM', 'Gruppe A', 'A', 1 );

		$t_home  = $s->team( $now, $home, 1, 'Seefeld 1', $group );
		$t_home2 = $s->team( $now, $home, 2, 'Seefeld 2', $group );
		$t_away  = $s->team( $now, $away, 1, 'Wollishofen 1', $group );
		$t_old   = $s->team( $old, $home, 1, 'Seefeld 1', $oldg );
		$t_oldaw = $s->team( $old, $away, 1, 'Wollishofen 1', $oldg );

		$people = array(
			'captain_home'  => $this->person( $s, 'Heim', 'Hanna', 'hanna.heim@example.org', '079 555 01 01', true ),
			'captain_home2' => $this->person( $s, 'Zweit', 'Zora', 'zora.zweit@example.org', '079 555 01 02', false ),
			'captain_away'  => $this->person( $s, 'Gast', 'Gustav', null, '+41 (0)79 555 01 03', true ),
			'chef_home'     => $this->person( $s, 'Chef', 'Charlotte', 'charlotte.chef@example.org', null, true ),
			'chef_away'     => $this->person( $s, 'Leiter', 'Lukas', null, null, false ),
			'former'        => $this->person( $s, 'Ehemalig', 'Erika', 'erika.ehemalig@example.org', '079 555 01 04', true ),
			'old_captain'   => $this->person( $s, 'Vorbei', 'Vera', 'vera.vorbei@example.org', '079 555 01 05', true ),
		);
		$this->db->update( 'person', array( 'status' => 'inactive' ), array( 'id' => $people['former'] ) );

		$s->role( $now, $home, $people['captain_home'], 'captain', $t_home );
		$s->role( $now, $home, $people['captain_home2'], 'captain', $t_home2 );
		$s->role( $now, $away, $people['captain_away'], 'captain', $t_away );
		$s->role( $now, $home, $people['chef_home'], 'tk_chef' );
		$s->role( $now, $away, $people['chef_away'], 'tk_chef' );
		$s->role( $now, $away, $people['former'], 'captain', $t_away );
		$s->role( $old, $home, $people['old_captain'], 'captain', $t_old );

		$entries = array();
		foreach ( array(
			'home' => array( $home, 'Seefeld 1' ),
			'away' => array( $away, 'Wollishofen 1' ),
		) as $side => [ $club, $name ] ) {
			$entries[ $side ] = $this->db->insert(
				'cup_entry',
				array(
					'competition_id' => $cup,
					'club_id'        => $club,
					'number'         => 1,
					'name'           => $name,
					'player1_id'     => $s->player( 'Cupper', 'Anna' . $side ),
					'player2_id'     => $s->player( 'Cupper', 'Ben' . $side ),
				)
			);
		}

		$this->id       = $people + array(
			'season'    => $now,
			'old'       => $old,
			'club_home' => $home,
			'club_away' => $away,
			'team_home' => $t_home,
			'match'     => $s->fixture(
				$group,
				array(
					'home_team_id' => $t_home,
					'away_team_id' => $t_away,
				)
			),
			'derby'     => $s->fixture(
				$group,
				array(
					'home_team_id' => $t_home,
					'away_team_id' => $t_home2,
				)
			),
			'cup'       => $s->fixture(
				$cup,
				array(
					'home_entry_id' => $entries['home'],
					'away_entry_id' => $entries['away'],
				)
			),
			'archived'  => $s->fixture(
				$oldg,
				array(
					'home_team_id' => $t_old,
					'away_team_id' => $t_oldaw,
				)
			),
		);
		$this->contacts = new Contacts( $this->db );
	}

	private function person( Seeder $s, string $last, string $first, ?string $email, ?string $phone, bool $visible ): int {
		$id = $s->person( $last, $first );
		$this->db->update(
			'person',
			array(
				'email'         => $email,
				'phone'         => $phone,
				'phone_visible' => $visible ? 1 : 0,
			),
			array( 'id' => $id )
		);
		return $id;
	}

	public function test_club_lists_officials_with_email_and_released_phone(): void {
		$rows = $this->contacts->for_clubs( $this->id['season'], array( $this->id['club_home'] ) );

		self::assertSame( array( 'Charlotte Chef', 'Hanna Heim', 'Zora Zweit' ), array_column( $rows, 'name' ), 'TK-Chef zuerst, dann nach Name' );
		self::assertSame( array( 'tk_chef', 'captain', 'captain' ), array_column( $rows, 'role' ) );
		self::assertSame( $this->id['team_home'], $rows[1]['team_id'] );
		self::assertNull( $rows[0]['team_id'] );

		$people = $this->contacts->by_person( $this->id['season'], array( $this->id['club_home'] ) );
		self::assertSame(
			array(
				'email' => 'hanna.heim@example.org',
				'phone' => '079 555 01 01',
			),
			$people[ $this->id['captain_home'] ]
		);
		self::assertSame(
			array(
				'email' => 'zora.zweit@example.org',
				'phone' => null,
			),
			$people[ $this->id['captain_home2'] ],
			'Telefon nicht freigegeben'
		);
		self::assertNull( $people[ $this->id['chef_home'] ]['phone'], 'keine Nummer hinterlegt' );
	}

	public function test_people_without_email_and_inactive_people(): void {
		$people = $this->contacts->by_person( $this->id['season'], array( $this->id['club_away'] ) );

		self::assertSame(
			array(
				'email' => null,
				'phone' => '+41 (0)79 555 01 03',
			),
			$people[ $this->id['captain_away'] ]
		);
		self::assertSame(
			array(
				'email' => null,
				'phone' => null,
			),
			$people[ $this->id['chef_away'] ]
		);
		self::assertArrayNotHasKey( $this->id['former'], $people, 'inaktive Person' );
	}

	public function test_only_the_active_season_has_contacts(): void {
		self::assertSame( array(), $this->contacts->for_clubs( $this->id['old'], array( $this->id['club_home'] ) ) );
		self::assertSame( array(), $this->contacts->for_clubs( $this->id['season'], array() ) );
		self::assertSame( array(), $this->contacts->for_clubs( $this->id['season'], array( 0 ) ) );
		self::assertNull( $this->contacts->for_fixture( $this->id['archived'] ) );
		self::assertNull( $this->contacts->for_fixture( 999999 ) );
	}

	public function test_fixture_shows_captains_of_the_teams_and_tk_chefs_of_the_clubs(): void {
		$sides = $this->contacts->for_fixture( $this->id['match'] );

		self::assertNotNull( $sides );
		self::assertSame( 'Seefeld 1', $sides['home']['name'] );
		self::assertSame( array( 'Charlotte Chef', 'Hanna Heim' ), array_column( $sides['home']['people'], 'name' ), 'ohne den Captain von Seefeld 2' );
		self::assertSame( 'Wollishofen 1', $sides['away']['name'] );
		self::assertSame( array( 'Lukas Leiter', 'Gustav Gast' ), array_column( $sides['away']['people'], 'name' ) );
		self::assertSame( 'hanna.heim@example.org', $sides['home']['people'][1]['email'] );
	}

	public function test_derby_and_cup(): void {
		$derby = $this->contacts->for_fixture( $this->id['derby'] );
		self::assertNotNull( $derby );
		self::assertSame( array( 'Charlotte Chef', 'Hanna Heim' ), array_column( $derby['home']['people'], 'name' ) );
		self::assertSame( array( 'Charlotte Chef', 'Zora Zweit' ), array_column( $derby['away']['people'], 'name' ), 'TK-Chef auf beiden Seiten' );

		$cup = $this->contacts->for_fixture( $this->id['cup'] );
		self::assertNotNull( $cup );
		self::assertSame( array( 'Charlotte Chef' ), array_column( $cup['home']['people'], 'name' ), 'Cup: nur TK-Chefs' );
		self::assertSame( array( 'Lukas Leiter' ), array_column( $cup['away']['people'], 'name' ) );
	}
}
