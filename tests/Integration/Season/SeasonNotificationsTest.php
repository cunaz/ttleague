<?php
/**
 * Integrationstests: Mitteilung «Spielplan freigegeben» an Captains und TK-Chefs in Stapeln.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Season;

use TTLeague\Accounts\Preferences;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Notifications\EmailChannel;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\NotificationLog;
use TTLeague\Notifications\Notifier;
use TTLeague\Notifications\RecipientResolver;
use TTLeague\Season\SeasonMessages;
use TTLeague\Season\SeasonNotifications;
use TTLeague\Tests\Integration\Notifications\RecordingChannel;

/**
 * Daten siehe SeasonTestCase. Die geplante Saison 2027/28 hat dieselben vier TK-Chefs wie die
 * aktive (vorgetragen) und je Mannschaft einen neuen Captain; alle haben eine E-Mail-Adresse.
 * Die Uhr steht auf dem 20. Juli 2027, dem Tag der Freigabe.
 */
final class SeasonNotificationsTest extends SeasonTestCase {

	private FixedClock $clock;

	private RecordingChannel $email;

	private SeasonNotifications $notifications;

	/** @var list<string> */
	private array $errors = array();

	/** @var array<string,int> Captains der geplanten Saison. */
	private array $captain = array();

	private int $next;

	protected function setUp(): void {
		parent::setUp();
		$this->league();
		$this->next = $this->scheduled();
		$this->db->update( 'season', array( 'classification_closed_at' => '2027-06-20 18:00:00' ), array( 'id' => $this->id['active'] ) );
		foreach ( array_keys( self::TEAMS ) as $key ) {
			$this->captain[ $key ] = $this->seed->person( 'Neucaptain', ucfirst( $key ) );
			$this->seed->role( $this->next, $this->id[ 'club_' . self::TEAMS[ $key ][0] ], $this->captain[ $key ], 'captain', $this->id[ 'next_' . $key ] );
		}
		$this->db->query( "UPDATE {$this->db->table( 'person' )} SET email = CONCAT( 'person', id, '@example.org' )" );

		$this->clock         = new FixedClock( '2027-07-20 10:00:00' );
		$this->email         = new RecordingChannel( 'email' );
		$this->errors        = array();
		$this->notifications = new SeasonNotifications(
			$this->db,
			new RecipientResolver( $this->db ),
			new Notifier( new Preferences( $this->db ), new NotificationLog( $this->db, $this->clock ), array( $this->email ) ),
			new SeasonMessages( new FakeSeasonLinks() ),
			function ( \Throwable $error, string $context ): void {
				$this->errors[] = $context . ': ' . $error->getMessage();
			}
		);
	}

	private function publish(): void {
		$this->lifecycle->publish( $this->tk, $this->next, '2027/28' );
		// Der Eintrag der Freigabe trägt die Zeit der Datenbank; für die Tests zählt die Testuhr.
		$this->db->update( 'audit_log', array( 'created_at' => '2027-07-20 10:00:00' ), array( 'action' => 'published' ) );
	}

	/**
	 * @return list<string> Empfänger als «p<Person>».
	 */
	private function expected(): array {
		$people = array( $this->id['chef_seefeld'], $this->id['chef_wollishofen'], $this->id['chef_oerlikon'], $this->id['chef_altstetten'] );
		$people = array_merge( $people, array_values( $this->captain ) );
		sort( $people );
		return array_map( static fn( int $id ): string => 'p' . $id, $people );
	}

	public function test_captains_and_club_officers_of_the_new_season_are_notified_once(): void {
		$this->publish();

		$result = $this->notifications->send_published( $this->next );

		self::assertSame(
			array(
				'sent'   => 10,
				'failed' => 0,
				'next'   => null,
			),
			$result
		);
		self::assertSame( $this->expected(), $this->email->recipients( SeasonMessages::PUBLISHED ) );
		self::assertNotContains( 'p' . $this->id['captain_seefeld_1'], $this->email->recipients( SeasonMessages::PUBLISHED ), 'Captains der Vorsaison ohne neue Funktion erhalten nichts.' );
		self::assertSame( array(), $this->notifications->pending( $this->next ) );

		$again = $this->notifications->send_published( $this->next );
		self::assertSame( 0, $again['sent'] );
		self::assertCount( 10, $this->email->sent, 'Ein zweiter Lauf versendet nichts doppelt.' );
	}

	public function test_nothing_is_sent_before_the_publication(): void {
		self::assertSame( 0, $this->notifications->send_published( $this->next )['sent'] );
		self::assertSame( array(), $this->email->sent );
		self::assertSame( array(), $this->notifications->to_catch_up( $this->clock->now() ) );
	}

	public function test_an_imported_active_season_never_triggers_the_message(): void {
		self::assertSame( 0, $this->notifications->send_published( $this->id['active'] )['sent'] );
		self::assertSame( array(), $this->email->sent );
		self::assertSame( array(), $this->notifications->to_catch_up( $this->clock->now() ) );
	}

	public function test_a_captain_gets_the_schedule_of_the_team_and_its_calendar(): void {
		$this->publish();
		$this->notifications->send_published( $this->next );

		$sent    = array_column( $this->email->sent, 'notification', 'to' );
		$message = $sent[ 'p' . $this->captain['oerlikon_1'] ];

		self::assertSame( EventTypes::SEASON_PUBLISHED, $message->type );
		self::assertSame( 'season', $message->ref_type );
		self::assertSame( $this->next, $message->ref_id );
		self::assertSame( 'Saison 2027/28: Der Spielplan ist freigegeben', $message->subject );
		self::assertSame( array( 'Oerlikon 1, Gruppe A', 'Kalender abonnieren' ), array_column( $message->lists, 'heading' ) );
		$fixtures = array_column( $message->lists[0]['items'], 'text' );
		self::assertCount( 4, $fixtures );
		self::assertSame( 'Do, 16.9.2027, 20:00: Oerlikon 1 – Wollishofen 1 (Halle Oerlikon)', $fixtures[0] );
		self::assertSame( 'Di, 21.9.2027, 20:00: Seefeld 1 – Oerlikon 1 (Halle Seefeld)', $fixtures[1] );
		self::assertStringStartsWith( 'https://liga.example.org/begegnung/', $message->lists[0]['items'][0]['url'] );
		self::assertSame(
			array(
				array(
					'text' => 'Kalender von Oerlikon 1',
					'url'  => 'https://liga.example.org/kalender/team/2027-28/oerlikon-1.ics',
				),
			),
			$message->lists[1]['items']
		);
		self::assertSame( 'https://liga.example.org/meisterschaft/2027-28/', $message->action['url'] );
		self::assertSame( 'https://liga.example.org/mein-bereich/', $message->settings_url );
	}

	public function test_a_club_officer_gets_all_teams_of_the_club(): void {
		$this->publish();
		$this->notifications->send_published( $this->next );

		$sent    = array_column( $this->email->sent, 'notification', 'to' );
		$message = $sent[ 'p' . $this->id['chef_seefeld'] ];

		self::assertSame( array( 'Seefeld 1, Gruppe A', 'Seefeld 2, Gruppe B', 'Kalender abonnieren' ), array_column( $message->lists, 'heading' ) );
		self::assertSame(
			array( 'Kalender von Seefeld 1', 'Kalender von Seefeld 2', 'Kalender aller Mannschaften von TTC Seefeld' ),
			array_column( $message->lists[2]['items'], 'text' )
		);
		self::assertSame( 'https://liga.example.org/kalender/club/seefeld.ics', $message->lists[2]['items'][2]['url'] );
	}

	public function test_a_person_with_two_functions_gets_one_message_with_each_team_once(): void {
		$this->seed->role( $this->next, $this->id['club_seefeld'], $this->id['chef_seefeld'], 'captain', $this->id['next_seefeld_2'] );
		$this->publish();

		$this->notifications->send_published( $this->next );

		$to = array_keys( array_filter( $this->email->recipients( SeasonMessages::PUBLISHED ), fn( string $key ): bool => 'p' . $this->id['chef_seefeld'] === $key ) );
		self::assertCount( 1, $to );
		$message = array_column( $this->email->sent, 'notification', 'to' )[ 'p' . $this->id['chef_seefeld'] ];
		self::assertSame( array( 'Seefeld 1, Gruppe A', 'Seefeld 2, Gruppe B', 'Kalender abonnieren' ), array_column( $message->lists, 'heading' ) );
	}

	public function test_a_step_sends_one_batch_and_names_where_to_continue(): void {
		$this->publish();
		$expected = $this->expected();

		$first = $this->notifications->send_published( $this->next, 0, 4 );

		self::assertSame( 4, $first['sent'] );
		self::assertSame( (int) substr( $expected[3], 1 ), $first['next'] );
		self::assertSame( array_slice( $expected, 0, 4 ), $this->email->recipients( SeasonMessages::PUBLISHED ) );

		$second = $this->notifications->send_published( $this->next, (int) $first['next'], 4 );
		self::assertSame( 4, $second['sent'] );
		self::assertSame( (int) substr( $expected[7], 1 ), $second['next'] );

		$third = $this->notifications->send_published( $this->next, (int) $second['next'], 4 );
		self::assertSame( 2, $third['sent'] );
		self::assertNull( $third['next'], 'Der letzte Schritt plant nichts mehr ein.' );
		self::assertSame( $expected, $this->email->recipients( SeasonMessages::PUBLISHED ) );
	}

	public function test_a_batch_that_fits_exactly_does_not_announce_a_next_step(): void {
		$this->publish();
		self::assertNull( $this->notifications->send_published( $this->next, 0, 10 )['next'] );
		self::assertCount( 10, $this->email->sent );
	}

	public function test_people_without_address_and_inactive_people_cost_no_delivery(): void {
		$this->db->update( 'person', array( 'email' => null ), array( 'id' => $this->id['chef_seefeld'] ) );
		$this->db->update( 'person', array( 'status' => 'inactive' ), array( 'id' => $this->id['chef_oerlikon'] ) );
		$this->publish();

		$result = $this->notifications->send_published( $this->next, 0, 8 );

		self::assertSame( 8, $result['sent'] );
		self::assertNull( $result['next'], 'Acht Zustellungen reichen für alle erreichbaren Empfänger.' );
		self::assertNotContains( 'p' . $this->id['chef_seefeld'], $this->email->recipients( SeasonMessages::PUBLISHED ) );
		self::assertNotContains( 'p' . $this->id['chef_oerlikon'], $this->email->recipients( SeasonMessages::PUBLISHED ) );
	}

	public function test_who_opted_out_gets_nothing(): void {
		$this->db->insert(
			'notification_pref',
			array(
				'person_id'  => $this->id['chef_seefeld'],
				'event_type' => EventTypes::SEASON_PUBLISHED,
				'email'      => 0,
				'push'       => 0,
			)
		);
		$this->publish();

		$this->notifications->send_published( $this->next );

		self::assertCount( 9, $this->email->sent );
		self::assertNotContains( 'p' . $this->id['chef_seefeld'], $this->email->recipients( SeasonMessages::PUBLISHED ) );
	}

	public function test_a_failed_delivery_is_counted_and_repeated_by_a_later_step(): void {
		$this->publish();
		$this->email->fail = true;

		$failed = $this->notifications->send_published( $this->next, 0, 3 );

		self::assertSame( 0, $failed['sent'] );
		self::assertSame( 3, $failed['failed'] );
		self::assertNotNull( $failed['next'], 'Auch ein gescheiterter Stapel bleibt begrenzt.' );
		self::assertCount( 10, $this->notifications->pending( $this->next ) );
		self::assertSame( array( $this->next ), $this->notifications->to_catch_up( $this->clock->now() ) );

		$this->email->fail = false;
		self::assertSame( 10, $this->notifications->send_published( $this->next )['sent'] );
		self::assertSame( array(), $this->notifications->to_catch_up( $this->clock->now() ) );
	}

	public function test_an_exception_for_one_recipient_does_not_stop_the_others(): void {
		$this->publish();
		$broken              = $this->id['chef_wollishofen'];
		$this->email->during = static function ( $recipient ) use ( $broken ): void {
			if ( $recipient->person_id === $broken ) {
				throw new \RuntimeException( 'Postfach voll' );
			}
		};

		$result = $this->notifications->send_published( $this->next );

		self::assertSame( 9, $result['sent'] );
		self::assertSame( 1, $result['failed'] );
		self::assertSame( array( $broken ), $this->notifications->pending( $this->next ) );
	}

	public function test_pending_messages_are_caught_up_for_seven_days(): void {
		$this->publish();
		$this->notifications->send_published( $this->next, 0, 4 );

		self::assertSame( array( $this->next ), $this->notifications->to_catch_up( new \DateTimeImmutable( '2027-07-27 10:00:00', new \DateTimeZone( 'UTC' ) ) ), 'Genau sieben Tage nach der Freigabe noch.' );
		self::assertSame( array(), $this->notifications->to_catch_up( new \DateTimeImmutable( '2027-07-27 10:00:01', new \DateTimeZone( 'UTC' ) ) ), 'Eine Sekunde später nicht mehr.' );
	}

	public function test_the_message_fits_into_an_e_mail_with_escaped_names(): void {
		$this->db->update( 'team', array( 'name' => 'Seefeld <1>' ), array( 'id' => $this->id['next_seefeld_1'] ) );
		$this->publish();
		$this->notifications->send_published( $this->next );
		$sent    = array_column( $this->email->sent, 'notification', 'to' );
		$message = EmailChannel::message(
			( new RecipientResolver( $this->db ) )->persons( array( $this->captain['seefeld_1'] ) )[0],
			$sent[ 'p' . $this->captain['seefeld_1'] ]
		);

		self::assertStringContainsString( 'Seefeld &lt;1&gt;', $message->html );
		self::assertStringNotContainsString( 'Seefeld <1>', $message->html );
		self::assertStringContainsString( 'Seefeld <1>', $message->text );
		self::assertStringContainsString( 'https://liga.example.org/kalender/team/2027-28/seefeld-1.ics', $message->text );
	}
}
