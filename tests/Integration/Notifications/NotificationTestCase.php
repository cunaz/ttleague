<?php
/**
 * Gemeinsame Testdaten der Benachrichtigungen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Notifications;

use TTLeague\Access\AccessPolicy;
use TTLeague\Accounts\Preferences;
use TTLeague\Domain\Match\GameOrder;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Notifications\FixtureQueries;
use TTLeague\Notifications\Messages;
use TTLeague\Notifications\NotificationLog;
use TTLeague\Notifications\Notifier;
use TTLeague\Notifications\RecipientResolver;
use TTLeague\Notifications\ResultNotifications;
use TTLeague\Results\Actor;
use TTLeague\Results\ResultRepository;
use TTLeague\Results\ResultService;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Erfundene Clubs Seefeld (Teams 1 und 2) und Wollishofen (Team 1) in Gruppe B, dazu ein Cup.
 *
 * Personen mit Konto: Captain Seefeld 1 (101), Captain Wollishofen 1 (102), TK-Mitglied mit
 * Person (103), TK-Chef Seefeld (104), Captain Seefeld 2 (105), TK-Chefin Wollishofen (107),
 * Administrator mit Person (110). Ohne Person: TK-Konto 108, Administrator 109.
 * Ein zweiter Captain von Seefeld 1 hat keine gültige E-Mail-Adresse.
 *
 * Begegnungen: f1 Seefeld 1 – Wollishofen 1 am Dienstag, 10.11.2026, 20:00 (Ortszeit Zürich),
 * derby Seefeld 1 – Seefeld 2 (Clubduell), cup Seefeld 1 – Wollishofen 1 im Cup.
 */
abstract class NotificationTestCase extends IntegrationTestCase {

	protected FixedClock $clock;

	protected RecordingChannel $email;

	protected RecordingChannel $push;

	protected FakeTkDirectory $tk;

	protected Notifier $notifier;

	protected RecipientResolver $recipients;

	protected Messages $messages;

	protected ResultRepository $repo;

	/** @var array<string,int> */
	protected array $id = array();

	/** @var array<string,int> */
	protected array $person = array();

	/** @var array<string,Actor> */
	protected array $actor = array();

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->seed( array() );
	}

	/**
	 * @param array<string,mixed> $rules Saisonregeln.
	 */
	protected function seed( array $rules ): void {
		$this->truncate_all();
		$s      = new Seeder( $this->db );
		$season = $s->season( '2026-27', 'active', $rules );
		$home   = $s->club( 'Seefeld', 1 );
		$away   = $s->club( 'Wollishofen', 2 );
		$group  = $s->competition( $season, 'MM', 'Gruppe B', 'B', 1 );
		$cup    = $s->competition( $season, 'CUP', 'Mannschaftscup A', 'A' );
		$venue  = $s->venue( $home, 'Turnhalle Seefeld' );
		$h1     = $s->team( $season, $home, 1, 'Seefeld 1', $group, $venue );
		$h2     = $s->team( $season, $home, 2, 'Seefeld 2', $group );
		$a1     = $s->team( $season, $away, 1, 'Wollishofen 1', $group );

		$players = array();
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $i => $key ) {
			$players[ $key ] = $s->player( 'Seefelder', 'Spieler' . ( $i + 1 ) );
			$s->register( $players[ $key ], $season, $home, 10 - $i );
		}
		foreach ( array( 'a1', 'a2', 'a3' ) as $i => $key ) {
			$players[ $key ] = $s->player( 'Wollishofer', 'Spieler' . ( $i + 1 ) );
			$s->register( $players[ $key ], $season, $away, 10 - $i );
		}
		$s->roster( $h1, array( $players['h1'], $players['h2'], $players['h3'] ) );
		$s->roster( $h2, array( $players['h4'], $players['h5'], $players['h6'] ) );
		$s->roster( $a1, array( $players['a1'], $players['a2'], $players['a3'] ) );

		$this->person = array(
			'captain_h1'  => $s->person( 'Heim', 'Hanna' ),
			'no_email'    => $s->person( 'Ohnemail', 'Olga' ),
			'captain_a1'  => $s->person( 'Gast', 'Gustav' ),
			'tk'          => $s->person( 'Kommission', 'Tamara' ),
			'chef_home'   => $s->person( 'Chef', 'Charlotte' ),
			'captain_h2'  => $s->person( 'Zweit', 'Zora' ),
			'chef_away'   => $s->person( 'Gastchef', 'Wilma' ),
			'admin'       => $s->person( 'Verwalter', 'Anton' ),
			'cup_h1'      => $s->person_of( $players['h1'] ),
			'cup_h2'      => $s->person_of( $players['h2'] ),
			'cup_a1'      => $s->person_of( $players['a1'] ),
			'cup_a2'      => $s->person_of( $players['a2'] ),
			'inactive'    => $s->person( 'Ehemalig', 'Erika' ),
			'unconnected' => $s->person( 'Niemand', 'Nina' ),
		);
		$users        = array(
			'captain_h1' => 101,
			'captain_a1' => 102,
			'tk'         => 103,
			'chef_home'  => 104,
			'captain_h2' => 105,
			'chef_away'  => 107,
			'admin'      => 110,
		);
		foreach ( $this->person as $key => $person_id ) {
			$data = array( 'email' => 'no_email' === $key ? 'keine-adresse' : str_replace( '_', '.', $key ) . '@example.org' );
			if ( isset( $users[ $key ] ) ) {
				$data['user_id'] = $users[ $key ];
			}
			if ( 'inactive' === $key ) {
				$data['status'] = 'inactive';
			}
			$this->db->update( 'person', $data, array( 'id' => $person_id ) );
		}
		$s->role( $season, $home, $this->person['captain_h1'], 'captain', $h1 );
		$s->role( $season, $home, $this->person['no_email'], 'captain', $h1 );
		$s->role( $season, $home, $this->person['inactive'], 'captain', $h1 );
		$s->role( $season, $home, $this->person['captain_h2'], 'captain', $h2 );
		$s->role( $season, $away, $this->person['captain_a1'], 'captain', $a1 );
		$s->role( $season, $home, $this->person['chef_home'], 'tk_chef' );
		$s->role( $season, $away, $this->person['chef_away'], 'tk_chef' );

		$entry_home = $this->db->insert(
			'cup_entry',
			array(
				'competition_id' => $cup,
				'club_id'        => $home,
				'number'         => 1,
				'name'           => 'Seefeld 1',
				'player1_id'     => $players['h1'],
				'player2_id'     => $players['h2'],
			)
		);
		$entry_away = $this->db->insert(
			'cup_entry',
			array(
				'competition_id' => $cup,
				'club_id'        => $away,
				'number'         => 1,
				'name'           => 'Wollishofen 1',
				'player1_id'     => $players['a1'],
				'player2_id'     => $players['a2'],
			)
		);

		$this->id = $players + array(
			'season' => $season,
			'f1'     => $s->fixture(
				$group,
				array(
					'home_team_id' => $h1,
					'away_team_id' => $a1,
					'scheduled_at' => '2026-11-10 20:00:00',
				)
			),
			'derby'  => $s->fixture(
				$group,
				array(
					'home_team_id' => $h1,
					'away_team_id' => $h2,
					'scheduled_at' => '2026-11-17 20:00:00',
				)
			),
			'cup'    => $s->fixture(
				$cup,
				array(
					'home_entry_id' => $entry_home,
					'away_entry_id' => $entry_away,
					'scheduled_at'  => '2026-11-12 19:30:00',
				)
			),
		);

		$this->actor = array();
		foreach ( $users as $key => $user_id ) {
			$this->actor[ $key ] = new Actor( $user_id, $this->person[ $key ], in_array( $key, array( 'tk', 'admin' ), true ) );
		}
		$this->actor['tk_account'] = new Actor( 108, null, true, 'TK-Konto' );

		$this->tk = new FakeTkDirectory(
			array(
				array(
					'user_id' => 103,
					'email'   => 'konto.103@example.org',
					'name'    => 'Tamara Kommission',
					'tk_role' => true,
				),
				array(
					'user_id' => 108,
					'email'   => 'tk.konto@example.org',
					'name'    => 'TK-Konto',
					'tk_role' => true,
				),
				array(
					'user_id' => 109,
					'email'   => 'webmaster@example.org',
					'name'    => 'Website',
					'tk_role' => false,
				),
				array(
					'user_id' => 110,
					'email'   => 'konto.110@example.org',
					'name'    => 'Anton Verwalter',
					'tk_role' => false,
				),
			)
		);

		$this->clock      = new FixedClock( '2026-11-10 21:30:00' );
		$this->email      = new RecordingChannel( 'email' );
		$this->push       = new RecordingChannel( 'push' );
		$this->recipients = new RecipientResolver( $this->db, $this->tk );
		$this->messages   = new Messages( new FakeLinks() );
		$this->repo       = new ResultRepository( $this->db );
		$this->notifier   = new Notifier( new Preferences( $this->db ), new NotificationLog( $this->db, $this->clock ), array( $this->push, $this->email ) );
	}

	/**
	 * Erfassung mit Sofortmeldungen (ResultNotifications als Ereignisempfänger).
	 */
	protected function service(): ResultService {
		return new ResultService(
			$this->db,
			new AccessPolicy( $this->db ),
			$this->clock,
			new \DateTimeZone( 'Europe/Zurich' ),
			new AuditLog( $this->db ),
			new ResultNotifications( $this->repo, new FixtureQueries( $this->db ), $this->recipients, $this->notifier, $this->messages, static fn( int $id ): ?string => 108 === $id ? 'TK-Konto' : null )
		);
	}

	/**
	 * Seefeld 1 gegen Wollishofen 1, Heim gewinnt 6:4.
	 *
	 * @return array<string,mixed>
	 */
	protected function six_four(): array {
		return $this->team_payload( array( '3:1', '3:0', '3:2', '1:3', '3:1', '0:3', '3:2', '2:3', '1:3', '3:0' ) );
	}

	/**
	 * Seefeld 1 gegen Wollishofen 1, Heim gewinnt 7:3.
	 *
	 * @return array<string,mixed>
	 */
	protected function seven_three(): array {
		return $this->team_payload( array( '3:1', '3:0', '3:2', '3:1', '3:1', '0:3', '3:2', '2:3', '1:3', '3:0' ) );
	}

	/**
	 * @param list<string> $results Zehn Resultate «3:1».
	 * @return array<string,mixed>
	 */
	private function team_payload( array $results ): array {
		$home  = array(
			'A' => $this->id['h1'],
			'B' => $this->id['h2'],
			'C' => $this->id['h3'],
		);
		$away  = array(
			'X' => $this->id['a1'],
			'Y' => $this->id['a2'],
			'Z' => $this->id['a3'],
		);
		$games = array();
		foreach ( GameOrder::team_match() as $i => $slot ) {
			[ $h, $a ] = array_map( 'intval', explode( ':', $results[ $i ] ) );
			$game      = array(
				'sequence'  => $slot['sequence'],
				'status'    => 'played',
				'sets_home' => $h,
				'sets_away' => $a,
			);
			if ( GameOrder::DOUBLE === $slot['type'] ) {
				$game['home_players'] = array( $this->id['h1'], $this->id['h2'] );
				$game['away_players'] = array( $this->id['a1'], $this->id['a2'] );
			}
			$games[] = $game;
		}
		return array(
			'lineup' => array(
				'home' => $home,
				'away' => $away,
			),
			'games'  => $games,
		);
	}

	/**
	 * Schlüssel eines Empfängers (Person).
	 */
	protected function p( string $key ): string {
		return 'p' . $this->person[ $key ];
	}
}
