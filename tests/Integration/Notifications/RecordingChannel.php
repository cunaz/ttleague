<?php
/**
 * Testdouble für einen Versandkanal.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Notifications;

use TTLeague\Notifications\Channel;
use TTLeague\Notifications\Notification;
use TTLeague\Notifications\Recipient;

/**
 * Zeichnet Zustellungen auf. E-Mail erreicht alle mit gültiger Adresse, Push die Personen in
 * $push_people. Mit $fail scheitert jede Zustellung.
 */
final class RecordingChannel implements Channel {

	/** @var list<array{to:string,event:string,ref_type:string,ref_id:int,ref_key:string,subject:string,notification:Notification}> */
	public array $sent = array();

	public bool $fail = false;

	public ?\Throwable $throw = null;

	/**
	 * @param string    $name        email oder push.
	 * @param list<int> $push_people Personen mit Push-Abo.
	 */
	public function __construct( private readonly string $name, public array $push_people = array() ) {}

	public function name(): string {
		return $this->name;
	}

	public function reaches( Recipient $recipient ): bool {
		if ( self::EMAIL === $this->name ) {
			return null !== $recipient->email;
		}
		return null !== $recipient->person_id && in_array( $recipient->person_id, $this->push_people, true );
	}

	public function deliver( Recipient $recipient, Notification $notification ): bool {
		if ( null !== $this->throw ) {
			throw $this->throw;
		}
		if ( $this->fail ) {
			return false;
		}
		$this->sent[] = array(
			'to'           => $recipient->key(),
			'event'        => $notification->event,
			'ref_type'     => $notification->ref_type,
			'ref_id'       => $notification->ref_id,
			'ref_key'      => $notification->ref_key,
			'subject'      => $notification->subject,
			'notification' => $notification,
		);
		return true;
	}

	/**
	 * Empfänger einer Art von Nachricht in der Reihenfolge des Versands.
	 *
	 * @return list<string>
	 */
	public function recipients( string $event ): array {
		return array_values( array_map( static fn( array $s ): string => $s['to'], array_filter( $this->sent, static fn( array $s ): bool => $s['event'] === $event ) ) );
	}
}
