<?php
/**
 * Gemeinsame Testdaten der Verschiebung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Postponement;

use TTLeague\Access\AccessPolicy;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Postponement\Parties;
use TTLeague\Postponement\PostponementEvents;
use TTLeague\Postponement\PostponementMessages;
use TTLeague\Postponement\PostponementNotifications;
use TTLeague\Postponement\PostponementRepository;
use TTLeague\Postponement\PostponementService;
use TTLeague\Results\Actor;
use TTLeague\Results\ResultException;
use TTLeague\Tests\Integration\Notifications\FakeLinks;
use TTLeague\Tests\Integration\Notifications\NotificationTestCase;

/**
 * Liga und Personen siehe NotificationTestCase: f1 Seefeld 1 – Wollishofen 1 am Dienstag,
 * 10.11.2026, 20:00, das Clubduell Seefeld 1 – Seefeld 2 am 17.11.2026, 20:00 und die
 * Cup-Begegnung am 12.11.2026, 19:30. Saison 1.8.2026 bis 31.7.2027.
 *
 * Die Uhr steht auf Montag, 2.11.2026, 09:00 Ortszeit (08:00 UTC), Zeitzone Europe/Zurich.
 */
abstract class PostponementTestCase extends NotificationTestCase {

	protected const ZONE = 'Europe/Zurich';

	protected RecordingPostponementEvents $events;

	protected PostponementService $postponements;

	protected function setUp(): void {
		parent::setUp();
		$this->clock         = new FixedClock( '2026-11-02 08:00:00' );
		$this->events        = new RecordingPostponementEvents();
		$this->postponements = $this->postponement_service( $this->events );
	}

	/**
	 * Dienst mit eigener Rechteprüfung (ohne Zwischenspeicher aus früheren Schritten).
	 */
	protected function postponement_service( ?PostponementEvents $events = null ): PostponementService {
		return new PostponementService(
			$this->db,
			new AccessPolicy( $this->db ),
			$this->clock,
			new \DateTimeZone( self::ZONE ),
			new AuditLog( $this->db ),
			$events ?? $this->events,
			static fn( int $id ): ?string => 108 === $id ? 'TK-Konto' : null
		);
	}

	/**
	 * Benachrichtigungen der Verschiebung über die aufzeichnenden Kanäle, Versandfenster 0 bis 24 Uhr.
	 *
	 * @param array<string,int> $hours Versandfenster.
	 */
	protected function postponement_notifications( array $hours = array(
		'from_hour'  => 0,
		'until_hour' => 24,
	), ?callable $on_error = null ): PostponementNotifications {
		return new PostponementNotifications(
			new PostponementRepository( $this->db ),
			$this->repo,
			new Parties( new AccessPolicy( $this->db ) ),
			$this->recipients,
			new \TTLeague\Notifications\Notifier( new \TTLeague\Accounts\Preferences( $this->db ), new \TTLeague\Notifications\NotificationLog( $this->db, $this->clock ), array( $this->push, $this->email ) ),
			new PostponementMessages( new FakeLinks() ),
			$this->clock,
			new \DateTimeZone( self::ZONE ),
			$hours,
			static fn( int $id ): ?string => 108 === $id ? 'TK-Konto' : null,
			$on_error
		);
	}

	/**
	 * Antrag der Heimseite (Captain Seefeld 1) für f1.
	 *
	 * @return array<string,mixed>
	 */
	protected function request_f1( string $new_date = '2026-11-17 20:00', string $actor = 'captain_h1' ): array {
		return $this->postponements->request(
			$this->id['f1'],
			$this->actor[ $actor ],
			array(
				'new_date' => $new_date,
				'reason'   => 'Die Halle ist an diesem Abend belegt.',
			)
		);
	}

	/**
	 * @return array<string,string|null>
	 */
	protected function fixture( string $key = 'f1' ): array {
		return (array) $this->db->get_row( "SELECT status, scheduled_at FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $this->id[ $key ] ) );
	}

	/**
	 * @return array<string,string|null>
	 */
	protected function postponement( int $id ): array {
		return (array) $this->db->get_row( "SELECT * FROM {$this->db->table( 'postponement' )} WHERE id = %d", array( $id ) );
	}

	/**
	 * Cup-Begegnung ohne Termin mit Frist der Runde (cup_tie); das Modul Cup legt sie später so an.
	 */
	protected function cup_without_date( ?string $deadline ): int {
		$row     = (array) $this->db->get_row( "SELECT competition_id, home_entry_id, away_entry_id FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $this->id['cup'] ) );
		$fixture = $this->db->insert(
			'fixture',
			array(
				'competition_id' => (int) $row['competition_id'],
				'home_entry_id'  => (int) $row['home_entry_id'],
				'away_entry_id'  => (int) $row['away_entry_id'],
				'scheduled_at'   => null,
				'status'         => 'scheduled',
			)
		);
		$this->cup_tie( $fixture, $deadline, 2 );
		return $fixture;
	}

	protected function cup_tie( int $fixture_id, ?string $deadline, int $round = 1 ): void {
		$row = (array) $this->db->get_row( "SELECT competition_id, home_entry_id, away_entry_id FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $fixture_id ) );
		$this->db->insert(
			'cup_tie',
			array(
				'competition_id' => (int) $row['competition_id'],
				'round'          => $round,
				'slot'           => 1,
				'fixture_id'     => $fixture_id,
				'home_entry_id'  => (int) $row['home_entry_id'],
				'away_entry_id'  => (int) $row['away_entry_id'],
				'deadline'       => $deadline,
			)
		);
	}

	/**
	 * Personen der Cup-Mannschaften als handelnde Personen (ohne Konto in den Stammdaten: Konto 201 ff.).
	 */
	protected function cup_actor( string $key ): Actor {
		$users = array(
			'cup_h1' => 201,
			'cup_h2' => 202,
			'cup_a1' => 203,
			'cup_a2' => 204,
		);
		$this->db->update( 'person', array( 'user_id' => $users[ $key ] ), array( 'id' => $this->person[ $key ] ) );
		return new Actor( $users[ $key ], $this->person[ $key ], false );
	}

	/**
	 * @param callable():mixed $call Aufruf, der scheitern soll.
	 */
	protected static function expect_error( callable $call, int $status ): ResultException {
		try {
			$call();
		} catch ( ResultException $e ) {
			self::assertSame( $status, $e->status, $e->getMessage() );
			return $e;
		}
		self::fail( 'Fehler ' . $status . ' erwartet.' );
	}
}
