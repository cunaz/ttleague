<?php
/**
 * Integrationstests der Regeln für E-Mail-Adressen und Einladungen (TK und TK-Chefs).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Accounts;

use TTLeague\Access\AccessPolicy;
use TTLeague\Accounts\AccountService;
use TTLeague\Accounts\ClubAccounts;
use TTLeague\Accounts\ClubPeople;
use TTLeague\Accounts\Outcome;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Mail\Message;
use TTLeague\Infrastructure\RateLimiter;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Results\Actor;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Erfundene Clubs Seefeld (TK-Chefin Charlotte Chef) und Wollishofen. Konten: 301 TK-Chefin,
 * 302 Spieler, 303 Mitglied der TK, 304 Administrator, 305 Redaktionskonto ohne verknüpfte
 * Person, 310 TK ohne Person.
 */
final class ClubAccountsTest extends IntegrationTestCase {

	private FixedClock $clock;

	private FakeUsers $users;

	private ClubAccounts $service;

	/** @var list<Message> */
	private array $mails = array();

	/** @var list<array{0:int,1:int}> */
	private array $invited = array();

	private ?\RuntimeException $invite_error = null;

	/** @var array<string,int> */
	private array $id = array();

	private Actor $chef;

	private Actor $tk;

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$seed   = new Seeder( $this->db );
		$season = $seed->season( '2026-27', 'active' );
		$home   = $seed->club( 'Seefeld', 1 );
		$away   = $seed->club( 'Wollishofen', 2 );
		$group  = $seed->competition( $season, 'MM', 'Gruppe B1', 'B', 1 );
		$team   = $seed->team( $season, $home, 1, 'Seefeld 1', $group );

		$player = static function ( string $last, string $first, int $club ) use ( $seed, $season ): int {
			$id = $seed->player( $last, $first );
			$seed->register( $id, $season, $club, 10 );
			return $seed->person_of( $id );
		};

		$this->id = array(
			'club'  => $home,
			'away'  => $away,
			'chef'  => $seed->person( 'Chef', 'Charlotte' ),
			'hanna' => $seed->person( 'Heim', 'Hanna' ),
			'lars'  => $seed->person( 'Leer', 'Lars' ),
			'paul'  => $player( 'Spieler', 'Paul', $home ),
			'kurt'  => $player( 'Konto', 'Kurt', $home ),
			'rita'  => $player( 'Rechte', 'Rita', $home ),
			'arno'  => $player( 'Admin', 'Arno', $home ),
			'rolf'  => $player( 'Redaktion', 'Rolf', $home ),
			'olga'  => $player( 'Ohne', 'Olga', $home ),
			'fritz' => $player( 'Fremd', 'Fritz', $away ),
		);
		$seed->role( $season, $home, $this->id['chef'], 'tk_chef' );
		$seed->role( $season, $home, $this->id['hanna'], 'captain', $team );
		$seed->role( $season, $home, $this->id['lars'], 'captain', $team );

		$this->contact( 'chef', 'chef@example.org', 301 );
		$this->contact( 'hanna', 'hanna@example.org' );
		$this->contact( 'paul', 'paul@example.org' );
		$this->contact( 'kurt', 'kurt@example.org', 302 );
		$this->contact( 'rita', 'rita@example.org', 303 );
		$this->contact( 'arno', 'arno@example.org', 304 );
		$this->contact( 'rolf', 'rolf@example.org' );
		$this->contact( 'fritz', 'fritz@example.org' );

		$this->users = new FakeUsers();
		$this->users->add( 301, 'chef@example.org', false, false, 1763000000 );
		$this->users->add( 302, 'kurt@example.org' );
		$this->users->add( 303, 'rita@example.org', true );
		$this->users->add( 304, 'arno@example.org', true, true );
		$this->users->add( 305, 'rolf@example.org', true );
		$this->users->add( 310, 'tk@example.org', true );

		$this->chef  = new Actor( 301, $this->id['chef'], false, 'Charlotte Chef' );
		$this->tk    = new Actor( 310, null, true, 'Tamara TK' );
		$this->clock = new FixedClock( '2026-11-20 11:00:00' );
		$this->build();
	}

	private function build(): void {
		$store         = array();
		$this->service = new ClubAccounts(
			new AccountService( $this->db ),
			new AccessPolicy( $this->db ),
			new ClubPeople( $this->db, $this->users, $this->clock ),
			$this->users,
			new AuditLog( $this->db ),
			new RateLimiter(
				$this->clock,
				static function ( string $key ) use ( &$store ) {
					return $store[ $key ] ?? false;
				},
				static function ( string $key, $value ) use ( &$store ): void {
					$store[ $key ] = $value;
				}
			),
			function ( int $person_id, int $user_id ): void {
				if ( null !== $this->invite_error ) {
					throw $this->invite_error;
				}
				$this->invited[] = array( $person_id, $user_id );
			},
			function ( Message $message ): bool {
				$this->mails[] = $message;
				return true;
			},
			'Liga Musterstadt'
		);
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

	private function email_of( string $key ): ?string {
		return $this->db->get_var( "SELECT email FROM {$this->db->table( 'person' )} WHERE id = %d", array( $this->id[ $key ] ) );
	}

	/**
	 * @return list<array<string,string|null>>
	 */
	private function audit( string $action ): array {
		return $this->db->get_results( "SELECT * FROM {$this->db->table( 'audit_log' )} WHERE action = %s ORDER BY id", array( $action ) );
	}

	public function test_chef_changes_email_of_own_member_and_previous_address_is_told(): void {
		$outcome = $this->service->change_email( $this->chef, $this->id['paul'], '  Paul.NEU@example.org ' );

		self::assertSame( Outcome::SUCCESS, $outcome->type, $outcome->message );
		self::assertStringContainsString( 'bisherige Adresse wurde benachrichtigt', $outcome->message );
		self::assertSame( 'paul.neu@example.org', $this->email_of( 'paul' ) );

		self::assertCount( 1, $this->mails );
		self::assertSame( 'paul@example.org', $this->mails[0]->to );
		self::assertSame( 'email_changed', $this->mails[0]->type );
		self::assertStringContainsString( 'Liga Musterstadt', $this->mails[0]->subject );
		self::assertStringContainsString( 'Charlotte Chef hat deine E-Mail-Adresse', $this->mails[0]->text );
		self::assertStringContainsString( 'pa…@example.org', $this->mails[0]->text );
		self::assertStringNotContainsString( 'paul.neu@', $this->mails[0]->text, 'neue Adresse nur gekürzt' );

		$audit = $this->audit( 'email_changed' );
		self::assertCount( 1, $audit );
		self::assertSame( array( 'person', (string) $this->id['paul'], '301' ), array( $audit[0]['entity'], $audit[0]['entity_id'], $audit[0]['user_id'] ) );
		self::assertSame( '{"email":"paul@example.org"}', $audit[0]['before_json'] );
		self::assertSame( '{"email":"paul.neu@example.org"}', $audit[0]['after_json'] );
	}

	public function test_first_address_needs_no_notification(): void {
		$outcome = $this->service->change_email( $this->chef, $this->id['olga'], 'olga@example.org' );

		self::assertSame( Outcome::SUCCESS, $outcome->type );
		self::assertSame( array(), $this->mails );
		self::assertSame( '{"email":null}', $this->audit( 'email_changed' )[0]['before_json'] );
	}

	public function test_chef_only_changes_members_of_own_club(): void {
		$outcome = $this->service->change_email( $this->chef, $this->id['fritz'], 'fritz.neu@example.org' );

		self::assertSame( Outcome::ERROR, $outcome->type );
		self::assertSame( 'Du kannst nur E-Mail-Adressen von Personen deines Clubs ändern.', $outcome->message );
		self::assertSame( 'fritz@example.org', $this->email_of( 'fritz' ) );
		self::assertSame( array(), $this->mails );
		self::assertSame( array(), $this->audit( 'email_changed' ) );

		$stranger = new Actor( 320, null, false, 'Fremd' );
		self::assertSame( Outcome::ERROR, $this->service->change_email( $stranger, $this->id['paul'], 'x@example.org' )->type, 'ohne Person keine Rechte' );
	}

	public function test_chef_never_changes_accounts_with_extended_rights(): void {
		foreach ( array( 'rita', 'arno', 'rolf' ) as $key ) {
			$outcome = $this->service->change_email( $this->chef, $this->id[ $key ], $key . '.neu@example.org' );
			self::assertSame( Outcome::ERROR, $outcome->type, $key );
			self::assertStringContainsString( 'erweiterten Rechten', $outcome->message, $key );
		}
		self::assertSame( 'rolf@example.org', $this->email_of( 'rolf' ), 'nicht verknüpftes Konto mit derselben Adresse zählt auch' );
		self::assertSame( array(), $this->users->updates );
	}

	public function test_tk_changes_all_but_administrators_and_updates_linked_account(): void {
		$outcome = $this->service->change_email( $this->tk, $this->id['rita'], 'rita.neu@example.org' );
		self::assertSame( Outcome::SUCCESS, $outcome->type, $outcome->message );
		self::assertSame( array( array( 303, 'rita.neu@example.org' ) ), $this->users->updates );
		self::assertSame( 'rita.neu@example.org', $this->email_of( 'rita' ) );
		self::assertStringContainsString( 'Tamara TK hat deine E-Mail-Adresse', $this->mails[0]->text );

		self::assertSame( Outcome::SUCCESS, $this->service->change_email( $this->tk, $this->id['fritz'], 'fritz.neu@example.org' )->type, 'TK: alle Clubs' );

		$outcome = $this->service->change_email( $this->tk, $this->id['arno'], 'arno.neu@example.org' );
		self::assertSame( Outcome::ERROR, $outcome->type );
		self::assertSame( 'Arno Admin hat ein Administratorkonto. Dessen E-Mail-Adresse lässt sich nur in der Benutzerverwaltung ändern.', $outcome->message );
		self::assertSame( 'arno@example.org', $this->email_of( 'arno' ) );
	}

	public function test_linked_account_of_member_gets_new_address(): void {
		self::assertSame( Outcome::SUCCESS, $this->service->change_email( $this->chef, $this->id['kurt'], 'kurt.neu@example.org' )->type );
		self::assertSame( array( array( 302, 'kurt.neu@example.org' ) ), $this->users->updates );
	}

	public function test_invalid_addresses_are_rejected(): void {
		foreach ( array( '', 'kein-mail', 'a@b' ) as $email ) {
			$outcome = $this->service->change_email( $this->chef, $this->id['paul'], $email );
			self::assertSame( Outcome::ERROR, $outcome->type, $email );
			self::assertSame( 'Die E-Mail-Adresse für Paul Spieler ist ungültig.', $outcome->message );
		}
		self::assertSame( 'paul@example.org', $this->email_of( 'paul' ) );
	}

	public function test_address_of_another_person_is_rejected_understandably(): void {
		$outcome = $this->service->change_email( $this->chef, $this->id['paul'], 'KURT@example.org' );

		self::assertSame( Outcome::ERROR, $outcome->type );
		self::assertSame( 'Die E-Mail-Adresse für Paul Spieler ist bereits bei einer anderen Person hinterlegt.', $outcome->message );
		self::assertSame( 'paul@example.org', $this->email_of( 'paul' ) );
	}

	public function test_address_of_another_account_is_rejected(): void {
		$outcome = $this->service->change_email( $this->tk, $this->id['paul'], 'tk@example.org' );

		self::assertSame( Outcome::ERROR, $outcome->type );
		self::assertSame( 'Die E-Mail-Adresse für Paul Spieler gehört bereits zu einem anderen Konto.', $outcome->message );
		self::assertSame( 'paul@example.org', $this->email_of( 'paul' ) );
	}

	public function test_unchanged_address_changes_nothing(): void {
		$outcome = $this->service->change_email( $this->chef, $this->id['paul'], 'PAUL@example.org' );

		self::assertSame( Outcome::INFO, $outcome->type );
		self::assertSame( array(), $this->mails );
		self::assertSame( array(), $this->audit( 'email_changed' ) );
	}

	public function test_refused_account_update_keeps_person_unchanged(): void {
		$this->users->fail_with = 'Diese E-Mail-Adresse wird bereits verwendet.';

		$outcome = $this->service->change_email( $this->chef, $this->id['kurt'], 'kurt.neu@example.org' );

		self::assertSame( Outcome::ERROR, $outcome->type );
		self::assertStringContainsString( 'Diese E-Mail-Adresse wird bereits verwendet.', $outcome->message );
		self::assertSame( 'kurt@example.org', $this->email_of( 'kurt' ) );
	}

	public function test_concurrent_assignment_is_reported_and_account_reset(): void {
		$this->users->on_update = function ( int $user_id, string $email ): void {
			unset( $user_id );
			if ( 'kurt.neu@example.org' === $email ) {
				$this->contact( 'olga', 'kurt.neu@example.org' );
			}
		};

		$outcome = $this->service->change_email( $this->chef, $this->id['kurt'], 'kurt.neu@example.org' );

		self::assertSame( Outcome::ERROR, $outcome->type );
		self::assertSame( 'Die E-Mail-Adresse für Kurt Konto ist bereits bei einer anderen Person hinterlegt.', $outcome->message );
		self::assertSame( 'kurt@example.org', $this->email_of( 'kurt' ) );
		self::assertSame( 'kurt@example.org', $this->users->users[302]['email'], 'Konto zurückgesetzt' );
		self::assertSame( array(), $this->audit( 'email_changed' ) );
	}

	/**
	 * Konto und Person können verschiedene Adressen haben (Altdaten) oder die Person hat keine.
	 * Scheitert das Speichern bei der Person, erhält das Konto seine eigene bisherige Adresse zurück.
	 */
	public function test_concurrent_assignment_resets_the_account_to_its_own_previous_address(): void {
		$concurrent                       = function ( int $user_id, string $email ): void {
			unset( $user_id );
			if ( 'kurt.neu@example.org' === $email ) {
				$this->contact( 'olga', 'kurt.neu@example.org' );
			}
		};
		$this->users->users[302]['email'] = 'kurt.alt@example.org';
		$this->users->on_update           = $concurrent;

		$outcome = $this->service->change_email( $this->chef, $this->id['kurt'], 'kurt.neu@example.org' );

		self::assertSame( Outcome::ERROR, $outcome->type );
		self::assertSame( 'kurt.alt@example.org', $this->users->users[302]['email'], 'bisherige Adresse des Kontos, nicht die der Person' );

		// Ohne Adresse bei der Person wird das Konto ebenfalls zurückgesetzt.
		$this->contact( 'olga', null );
		$this->contact( 'kurt', null, 302 );
		$this->users->users[302]['email'] = 'kurt@example.org';
		$outcome                          = $this->service->change_email( $this->chef, $this->id['kurt'], 'kurt.neu@example.org' );

		self::assertSame( Outcome::ERROR, $outcome->type );
		self::assertSame( 'kurt@example.org', $this->users->users[302]['email'] );
		self::assertNull( $this->email_of( 'kurt' ) );
	}

	public function test_failed_reset_of_the_account_is_reported(): void {
		$this->users->on_update = function ( int $user_id, string $email ): void {
			unset( $user_id );
			if ( 'kurt.neu@example.org' === $email ) {
				$this->contact( 'olga', 'kurt.neu@example.org' );
				// Das Zurücksetzen danach scheitert.
				$this->users->fail_with = 'Datenbank nicht erreichbar.';
			}
		};

		$outcome = $this->service->change_email( $this->chef, $this->id['kurt'], 'kurt.neu@example.org' );

		self::assertSame( Outcome::ERROR, $outcome->type );
		self::assertSame( 'Die Adresse von Kurt Konto liess sich nicht speichern, und das Konto liess sich nicht auf kurt@example.org zurücksetzen: Datenbank nicht erreichbar.. Bitte in der Benutzerverwaltung prüfen.', $outcome->message );
		self::assertSame( 'kurt@example.org', $this->email_of( 'kurt' ), 'Person unverändert' );
	}

	public function test_invitation_by_chef_and_tk(): void {
		$outcome = $this->service->invite( $this->chef, $this->id['paul'] );

		self::assertSame( Outcome::SUCCESS, $outcome->type, $outcome->message );
		self::assertSame( 'Einladung an Paul Spieler (paul@example.org) verschickt.', $outcome->message );
		self::assertSame( array( array( $this->id['paul'], 301 ) ), $this->invited );

		self::assertSame( Outcome::SUCCESS, $this->service->invite( $this->tk, $this->id['fritz'] )->type );
		self::assertSame( Outcome::ERROR, $this->service->invite( $this->chef, $this->id['fritz'] )->type, 'anderer Club' );
	}

	public function test_invitation_needs_valid_address(): void {
		$outcome = $this->service->invite( $this->tk, $this->id['olga'] );
		self::assertSame( Outcome::ERROR, $outcome->type );
		self::assertSame( 'Für Olga Ohne ist keine gültige E-Mail-Adresse hinterlegt.', $outcome->message );

		$this->contact( 'olga', 'kein-mail' );
		self::assertSame( Outcome::ERROR, $this->service->invite( $this->tk, $this->id['olga'] )->type );
		self::assertSame( array(), $this->invited );
	}

	public function test_administrators_get_a_hint_instead_of_a_link(): void {
		$outcome = $this->service->invite( $this->tk, $this->id['arno'] );

		self::assertSame( Outcome::INFO, $outcome->type );
		self::assertStringContainsString( 'meldet sich mit Passwort an', $outcome->message );
		self::assertSame( array(), $this->invited );
	}

	public function test_accounts_with_extended_rights_get_a_hint_instead_of_a_link(): void {
		// Rita hat erweiterte Rechte (z. B. TK oder Redaktion), aber kein Administratorkonto.
		$outcome = $this->service->invite( $this->tk, $this->id['rita'] );

		self::assertSame( Outcome::INFO, $outcome->type );
		self::assertSame( 'Rita Rechte hat ein Konto mit erweiterten Rechten und meldet sich mit Passwort an. Eine Einladung per Link gibt es dafür nicht.', $outcome->message );
		self::assertSame( array(), $this->invited );
	}

	public function test_failed_invitation_is_reported(): void {
		$this->invite_error = new \RuntimeException( 'Konto liess sich nicht anlegen.' );

		$outcome = $this->service->invite( $this->tk, $this->id['paul'] );

		self::assertSame( Outcome::ERROR, $outcome->type );
		self::assertSame( 'Die Einladung an Paul Spieler ist fehlgeschlagen: Konto liess sich nicht anlegen.', $outcome->message );
	}

	public function test_invitations_of_a_chef_are_rate_limited(): void {
		for ( $i = 0; $i < ClubAccounts::INVITE_LIMIT; $i++ ) {
			self::assertSame( Outcome::SUCCESS, $this->service->invite( $this->chef, $this->id['paul'] )->type );
		}
		$outcome = $this->service->invite( $this->chef, $this->id['paul'] );
		self::assertSame( Outcome::ERROR, $outcome->type );
		self::assertStringContainsString( 'bereits 50 Einladungen', $outcome->message );

		self::assertSame( Outcome::ERROR, $this->service->invite( $this->chef, $this->id['olga'] )->type, 'ohne Adresse: Fehler vor dem Zählen' );
		self::assertSame( Outcome::SUCCESS, $this->service->invite( $this->tk, $this->id['paul'] )->type, 'die TK ist nicht begrenzt' );

		$this->clock->advance( '+1 hour' );
		self::assertSame( Outcome::SUCCESS, $this->service->invite( $this->chef, $this->id['paul'] )->type );
		self::assertCount( ClubAccounts::INVITE_LIMIT + 2, $this->invited );
	}

	public function test_tk_invites_all_officials_without_account(): void {
		$outcome = $this->service->invite_officials( $this->tk, $this->id['club'] );

		self::assertSame( Outcome::WARNING, $outcome->type, $outcome->message );
		self::assertSame( '1 Einladung verschickt: Hanna Heim.', $outcome->message );
		self::assertSame( array( 'Lars Leer: keine E-Mail-Adresse' ), $outcome->details );
		self::assertSame( array( array( $this->id['hanna'], 310 ) ), $this->invited, 'TK-Chefin hat ein Konto, Spieler sind keine Funktionsträger' );

		self::assertSame( Outcome::ERROR, $this->service->invite_officials( $this->chef, $this->id['club'] )->type );
	}

	public function test_form_visibility_rules(): void {
		$plain = array(
			'privileged' => false,
			'admin'      => false,
		);
		$tk    = array(
			'privileged' => true,
			'admin'      => false,
		);
		$admin = array(
			'privileged' => true,
			'admin'      => true,
		);
		self::assertTrue( ClubAccounts::may_edit_email( false, $plain ) );
		self::assertFalse( ClubAccounts::may_edit_email( false, $tk ) );
		self::assertTrue( ClubAccounts::may_edit_email( true, $tk ) );
		self::assertFalse( ClubAccounts::may_edit_email( true, $admin ) );
		self::assertSame( 'pa…@example.org', ClubAccounts::mask( 'paul@example.org' ) );
		self::assertSame( 'a…@example.org', ClubAccounts::mask( 'ab@example.org' ) );
	}
}
