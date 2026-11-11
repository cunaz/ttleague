<?php
/**
 * Nachrücken von der Warteliste.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

use TTLeague\Domain\Events\RegistrationRules;
use TTLeague\Infrastructure\AuditLog;

/**
 * Füllt freie Plätze eines Anlasses mit den ältesten Anmeldungen der Warteliste. Läuft in der
 * Transaktion des Aufrufers und unter der Sperre des Anlasses (EventRepository::lock()).
 */
final class Waitlist {

	public function __construct( private readonly EventRepository $repo, private readonly AuditLog $audit ) {}

	/**
	 * @param array<string,mixed> $event   Anlass, unter der Sperre gelesen.
	 * @param int|null            $user_id Konto, dessen Aktion den Platz frei gemacht hat.
	 * @return list<array{id:int,event_id:int,person_id:int,status:string,note:string|null,created_at:string}> Nachgerückte Anmeldungen (Stand vorher).
	 */
	public function fill( array $event, ?int $user_id ): array {
		$counts   = $this->repo->counts( $event['id'] );
		$number   = RegistrationRules::promotions( $event['max_participants'], $counts[ RegistrationRules::REGISTERED ], $counts[ RegistrationRules::WAITLIST ] );
		$promoted = array();
		foreach ( $this->repo->waiting( $event['id'], $number ) as $row ) {
			$this->repo->promote( $row['id'] );
			$this->audit->record( 'registration', $row['id'], 'promoted', $user_id, $row, array( 'status' => RegistrationRules::REGISTERED ) + $row );
			$promoted[] = $row;
		}
		return $promoted;
	}
}
