<?php
/**
 * Formulare des Blocks: Nonce, Rechte, Rückmeldung, Weiterleitung und Benachrichtigungen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Events;

use TTLeague\Accounts\Services;
use TTLeague\Events\BlockActions;
use TTLeague\Events\Events;
use TTLeague\Events\RegistrationView;
use TTLeague\Infrastructure\Cache\DataVersion;
use TTLeague\Infrastructure\Flash;
use TTLeague\Notifications\EventTypes;

/**
 * Daten siehe EventsTestCase.
 */
final class BlockActionsTest extends EventsTestCase {

	private const PAGE = 'http://example.org/ausschreibung/';

	/**
	 * Schickt das Formular ab und liefert das Ziel der Weiterleitung.
	 *
	 * @param array<string,string> $fields Weitere Felder.
	 */
	private function submit( string $action, int $event, string $person_key, array $fields = array() ): string {
		$this->post(
			$action . '_' . $event,
			$fields + array(
				'event'       => (string) $event,
				'person'      => (string) $this->person[ $person_key ],
				'redirect_to' => self::PAGE,
			)
		);
		return $this->redirect_of( array( BlockActions::class, BlockActions::REGISTER === $action ? 'register' : 'unregister' ) );
	}

	/**
	 * @return array{type:string,message:string,details:list<string>}|null
	 */
	private function flash( int $event, string $user_key ): ?array {
		return Flash::take( RegistrationView::FLASH . $event, $this->user[ $user_key ] );
	}

	public function test_handlers_are_registered_and_guests_are_sent_to_the_login(): void {
		foreach ( array( BlockActions::REGISTER, BlockActions::UNREGISTER ) as $action ) {
			self::assertNotFalse( has_action( 'admin_post_' . $action ), $action );
			self::assertNotFalse( has_action( 'admin_post_nopriv_' . $action ), $action );
		}
		$_POST = array( 'redirect_to' => self::PAGE );

		$target = $this->redirect_of( array( BlockActions::class, 'login_first' ) );

		self::assertSame( 'http://example.org/anmelden/?redirect_to=' . rawurlencode( self::PAGE ), $target );
	}

	public function test_a_person_registers_herself_and_gets_the_confirmation(): void {
		$event   = $this->event();
		$version = DataVersion::get();
		wp_set_current_user( $this->user['nobody'] );

		$target = $this->submit( BlockActions::REGISTER, $event, 'nobody', array( 'note' => 'Komme "später"' ) );

		self::assertSame( self::PAGE . '#ttl-anlass-' . $event, $target );
		self::assertSame( array( 'nobody' => 'registered' ), $this->list_of( $event ) );
		self::assertSame( 'Komme "später"', $this->db->get_var( "SELECT note FROM {$this->db->table( 'registration' )} WHERE event_id = %d", array( $event ) ) );
		self::assertSame(
			array(
				'type'    => 'success',
				'message' => 'Du bist angemeldet. Die Bestätigung kommt per E-Mail.',
				'details' => array(),
			),
			$this->flash( $event, 'nobody' )
		);
		self::assertSame( array( 'nobody@example.invalid' => array( 'Anmeldung bestätigt: Plauschturnier' ) ), $this->subjects() );
		self::assertStringContainsString( 'Du bist für Plauschturnier (', $this->mails[0]->text );
		self::assertStringContainsString( 'Turnhalle Seefeld) angemeldet.', $this->mails[0]->text );
		self::assertSame( 'notification_event_registered', $this->mails[0]->type );
		self::assertGreaterThan( $version, DataVersion::get(), 'Datenversion erhöht' );
		self::assertSame( '1', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'audit_log' )} WHERE entity = 'registration' AND action = 'registered' AND user_id = %d", array( $this->user['nobody'] ) ) );
	}

	public function test_the_waitlist_is_reported_and_unregistering_promotes_with_a_message(): void {
		$event = $this->event( array( 'max_participants' => '1' ) );
		$this->register_all( $event, array( 'h1' ) );
		wp_set_current_user( $this->user['nobody'] );

		$this->submit( BlockActions::REGISTER, $event, 'nobody' );

		self::assertSame( 'waitlist', $this->list_of( $event )['nobody'] );
		self::assertSame( 'warning', $this->flash( $event, 'nobody' )['type'] );
		self::assertSame( array( 'nobody@example.invalid' => array( 'Warteliste: Plauschturnier' ) ), $this->subjects() );

		$this->mails = array();
		wp_set_current_user( $this->user['h1'] );
		$target = $this->submit( BlockActions::UNREGISTER, $event, 'h1' );

		self::assertSame( self::PAGE . '#ttl-anlass-' . $event, $target );
		self::assertSame( array( 'nobody' => 'registered' ), $this->list_of( $event ) );
		self::assertSame( 'Du bist abgemeldet.', $this->flash( $event, 'h1' )['message'] );
		self::assertSame(
			array(
				'h1@example.invalid'     => array( 'Abmeldung bestätigt: Plauschturnier' ),
				'nobody@example.invalid' => array( 'Platz frei: Plauschturnier' ),
			),
			$this->subjects()
		);
	}

	public function test_a_tk_chef_registers_a_player_of_her_club_who_is_told_by_whom(): void {
		$event = $this->event();
		wp_set_current_user( $this->user['chef'] );

		$this->submit( BlockActions::REGISTER, $event, 'h3' );

		self::assertSame( array( 'h3' => 'registered' ), $this->list_of( $event ) );
		self::assertSame( 'Spieler3 Seefelder ist angemeldet.', $this->flash( $event, 'chef' )['message'] );
		self::assertSame( array( 'h3@example.invalid' => array( 'Anmeldung bestätigt: Plauschturnier' ) ), $this->subjects(), 'die Bestätigung geht an den Spieler' );
		self::assertStringContainsString( 'Charlotte Chef hat dich für Plauschturnier', $this->mails[0]->text );

		$this->mails = array();
		$this->submit( BlockActions::UNREGISTER, $event, 'h3' );

		self::assertSame( array(), $this->list_of( $event ) );
		self::assertStringContainsString( 'Charlotte Chef hat dich von Plauschturnier', $this->mails[0]->text );
	}

	/**
	 * @return iterable<string,array{string,string}>
	 */
	public static function strangers(): iterable {
		yield 'TK-Chefin für einen Spieler eines anderen Clubs' => array( 'chef', 'a1' );
		yield 'TK-Chefin für eine Person ohne Meldung' => array( 'chef', 'nobody' );
		yield 'Captain für einen Spieler ihres Clubs' => array( 'captain', 'h1' );
		yield 'Spieler für einen Clubkollegen' => array( 'h1', 'h3' );
		yield 'Konto ohne Person' => array( 'unlinked', 'h1' );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'strangers' )]
	public function test_nobody_registers_or_unregisters_someone_else_without_the_right( string $user, string $person ): void {
		$event = $this->event();
		$this->register_all( $event, array( 'a3' ) );
		wp_set_current_user( $this->user[ $user ] );

		$this->post(
			BlockActions::REGISTER . '_' . $event,
			array(
				'event'  => (string) $event,
				'person' => (string) $this->person[ $person ],
			)
		);
		$register = $this->die_of( array( BlockActions::class, 'register' ) );
		$this->post(
			BlockActions::UNREGISTER . '_' . $event,
			array(
				'event'  => (string) $event,
				'person' => (string) $this->person['a3'],
			)
		);
		$unregister = $this->die_of( array( BlockActions::class, 'unregister' ) );

		self::assertSame( 'Dazu fehlt dir die Berechtigung.', $register->getMessage() );
		self::assertSame( 'Dazu fehlt dir die Berechtigung.', $unregister->getMessage() );
		self::assertSame( array( 'a3' => 'registered' ), $this->list_of( $event ) );
		self::assertSame( array(), $this->mails );
	}

	public function test_the_nonce_belongs_to_the_action_and_the_event(): void {
		$event = $this->event();
		$other = $this->event( array( 'title' => 'Anderer Anlass' ) );
		wp_set_current_user( $this->user['nobody'] );
		$fields = array(
			'event'  => (string) $event,
			'person' => (string) $this->person['nobody'],
		);

		$this->post( BlockActions::REGISTER . '_' . $other, $fields );
		$this->die_of( array( BlockActions::class, 'register' ) );
		$this->post( BlockActions::UNREGISTER . '_' . $event, $fields );
		$this->die_of( array( BlockActions::class, 'register' ) );
		$_POST    = $fields;
		$_REQUEST = $fields;
		$this->die_of( array( BlockActions::class, 'register' ) );

		self::assertSame( array(), $this->list_of( $event ) );
	}

	public function test_a_rejected_registration_is_explained_on_the_page(): void {
		$event = $this->event( array( 'deadline' => $this->local( -1 ) ) );
		wp_set_current_user( $this->user['nobody'] );

		$target = $this->submit( BlockActions::REGISTER, $event, 'nobody' );

		self::assertSame( self::PAGE . '#ttl-anlass-' . $event, $target );
		self::assertSame( array(), $this->list_of( $event ) );
		self::assertSame(
			array(
				'type'    => 'error',
				'message' => 'Der Anmeldeschluss ist vorbei. Änderungen nimmt die TK entgegen.',
				'details' => array(),
			),
			$this->flash( $event, 'nobody' )
		);
		self::assertSame( array(), $this->mails );
	}

	public function test_the_club_form_without_a_selection_asks_for_a_person(): void {
		$event = $this->event();
		wp_set_current_user( $this->user['chef'] );
		$this->post(
			BlockActions::REGISTER . '_' . $event,
			array(
				'event'       => (string) $event,
				'person'      => '',
				'redirect_to' => self::PAGE,
			)
		);

		$this->redirect_of( array( BlockActions::class, 'register' ) );

		self::assertSame( 'Bitte wähle eine Person.', $this->flash( $event, 'chef' )['message'] );
		self::assertSame( array(), $this->list_of( $event ) );
	}

	public function test_redirects_stay_on_this_site(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Ausschreibung',
				'post_status' => 'publish',
			)
		);
		$linked  = $this->event( array( 'post_id' => (string) $post_id ) );
		$plain   = $this->event( array( 'title' => 'Ohne Ausschreibung' ) );
		wp_set_current_user( $this->user['nobody'] );

		$with_post    = $this->submit( BlockActions::REGISTER, $linked, 'nobody', array( 'redirect_to' => 'https://boese.example.com/' ) );
		$without_post = $this->submit( BlockActions::REGISTER, $plain, 'nobody', array( 'redirect_to' => '//boese.example.com/' ) );
		$fragment     = $this->submit( BlockActions::UNREGISTER, $plain, 'nobody', array( 'redirect_to' => self::PAGE . '#oben' ) );

		self::assertSame( get_permalink( $post_id ) . '#ttl-anlass-' . $linked, $with_post, 'zur Ausschreibung' );
		self::assertSame( 'http://example.org/mein-bereich/#ttl-anlass-' . $plain, $without_post, 'sonst zu «Mein Bereich»' );
		self::assertSame( self::PAGE . '#ttl-anlass-' . $plain, $fragment );
	}

	public function test_a_failing_mail_does_not_undo_the_registration(): void {
		$event  = $this->event();
		$errors = array();
		add_action(
			'ttleague_notification_error',
			static function ( \Throwable $error, string $context ) use ( &$errors ): void {
				$errors[] = $context;
			},
			10,
			2
		);
		add_filter( 'pre_wp_mail', '__return_false' );
		wp_set_current_user( $this->user['nobody'] );

		$target = '';
		$this->error_log_of(
			function () use ( $event, &$target ): void {
				$target = $this->submit( BlockActions::REGISTER, $event, 'nobody' );
			}
		);

		self::assertSame( self::PAGE . '#ttl-anlass-' . $event, $target );
		self::assertSame( array( 'nobody' => 'registered' ), $this->list_of( $event ) );
		self::assertSame( 'success', $this->flash( $event, 'nobody' )['type'] );
		self::assertSame( array( 'email event_registered p' . $this->person['nobody'] ), $errors );
		self::assertSame( '0', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'notification_log' )}" ), 'Reservierung wieder frei' );
	}

	public function test_a_failing_listener_does_not_undo_the_registration(): void {
		$event = $this->event();
		add_action(
			'ttleague_event_registered',
			static function (): void {
				throw new \RuntimeException( 'Erweiterung kaputt' );
			},
			5
		);
		wp_set_current_user( $this->user['nobody'] );

		$target = '';
		$log    = $this->error_log_of(
			function () use ( $event, &$target ): void {
				$target = $this->submit( BlockActions::REGISTER, $event, 'nobody' );
			}
		);

		self::assertSame( self::PAGE . '#ttl-anlass-' . $event, $target );
		self::assertSame( array( 'nobody' => 'registered' ), $this->list_of( $event ) );
		self::assertSame( 'success', $this->flash( $event, 'nobody' )['type'] );
		self::assertStringContainsString( 'ttleague: Fehler in ttleague_event_registered: RuntimeException: Erweiterung kaputt', $log );
	}

	public function test_the_notification_type_can_be_switched_off_in_my_area(): void {
		$event = $this->event();
		self::assertSame( 'Anlässe: An- und Abmeldung, Nachrücken, Absage', EventTypes::labels()[ EventTypes::EVENT_REGISTRATION ] ?? null );
		Services::preferences()->set( $this->person['nobody'], EventTypes::EVENT_REGISTRATION, false, false );
		wp_set_current_user( $this->user['nobody'] );

		$this->submit( BlockActions::REGISTER, $event, 'nobody' );

		self::assertSame( array( 'nobody' => 'registered' ), $this->list_of( $event ) );
		self::assertSame( array(), $this->mails );
	}

	public function test_each_registration_is_confirmed_once_and_again_after_a_new_registration(): void {
		$event = $this->event();
		wp_set_current_user( $this->user['nobody'] );
		$this->submit( BlockActions::REGISTER, $event, 'nobody' );
		$registration = (int) $this->db->get_var( "SELECT id FROM {$this->db->table( 'registration' )} WHERE event_id = %d", array( $event ) );

		Events::on_registered( $event, $registration, $this->person['nobody'], 'registered', $this->user['nobody'] );
		self::assertCount( 1, $this->mails, 'dieselbe Anmeldung wird nicht doppelt bestätigt' );

		$this->submit( BlockActions::UNREGISTER, $event, 'nobody' );
		$this->submit( BlockActions::REGISTER, $event, 'nobody' );

		self::assertSame( array( 'nobody@example.invalid' => array( 'Anmeldung bestätigt: Plauschturnier', 'Abmeldung bestätigt: Plauschturnier', 'Anmeldung bestätigt: Plauschturnier' ) ), $this->subjects() );
	}
}
