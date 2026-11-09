<?php
/**
 * Versand einer Benachrichtigung an Empfänger über die gewählten Kanäle, genau einmal.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

use TTLeague\Accounts\Preferences;

/**
 * Pro Empfänger und Kanal: Einstellung prüfen (Preferences, ohne Eintrag E-Mail an und Push aus;
 * Konten ohne Person immer diese Vorgabe), Erreichbarkeit prüfen, im Protokoll reservieren,
 * versenden, den Versand bestätigen oder bei Misserfolg die Reservierung freigeben.
 *
 * Rückfall: Wer für ein Ereignis nur Push gewählt hat, aber kein gültiges Abo besitzt oder bei
 * dem der Push-Versand scheitert, erhält eine E-Mail. So geht keine Frist verloren. Ist die
 * E-Mail zugestellt, folgt für dieselbe Nachricht kein Push mehr.
 */
final class Notifier {

	public const SENT      = 'sent';
	public const DUPLICATE = 'duplicate';
	public const FAILED    = 'failed';

	/** @var array<string,Channel> */
	private array $channels = array();

	/** @var callable(\Throwable,string):void */
	private $on_error;

	/**
	 * @param Preferences                            $preferences Einstellungen.
	 * @param NotificationLog                        $log         Protokoll.
	 * @param list<Channel>                          $channels    Kanäle.
	 * @param callable(\Throwable,string):void|null $on_error    Fehler eines Kanals melden.
	 */
	public function __construct(
		private readonly Preferences $preferences,
		private readonly NotificationLog $log,
		array $channels,
		?callable $on_error = null,
	) {
		foreach ( $channels as $channel ) {
			$this->channels[ $channel->name() ] = $channel;
		}
		$this->on_error = $on_error ?? static function (): void {};
	}

	/**
	 * Versenden; die handelnde Person (Konto oder Person) wird ausgelassen.
	 *
	 * @param Notification    $notification Benachrichtigung.
	 * @param list<Recipient> $recipients   Empfänger (Doppel werden zusammengefasst).
	 * @param int|null        $exclude_user   Konto der handelnden Person.
	 * @param int|null        $exclude_person Handelnde Person.
	 * @return list<array{recipient:string,channel:string,status:string}>
	 */
	public function send( Notification $notification, array $recipients, ?int $exclude_user = null, ?int $exclude_person = null ): array {
		$report = array();
		foreach ( RecipientResolver::unique( $recipients ) as $recipient ) {
			if ( $recipient->is( $exclude_user, $exclude_person ) ) {
				continue;
			}
			$wants    = $this->wants( $recipient, $notification->type );
			$push     = $this->channels[ Channel::PUSH ] ?? null;
			$email    = $this->channels[ Channel::EMAIL ] ?? null;
			$fallback = $wants[ Channel::PUSH ] && ! $wants[ Channel::EMAIL ];
			// Ist die E-Mail als Rückfall schon zugestellt, wäre ein späterer Push doppelt.
			$use_push = $wants[ Channel::PUSH ] && null !== $push && $push->reaches( $recipient )
				&& ! ( $fallback && $this->log->was_sent( $recipient, $notification, Channel::EMAIL ) );

			$pushed = false;
			if ( $use_push ) {
				$status   = $this->attempt( $push, $recipient, $notification );
				$pushed   = self::FAILED !== $status;
				$report[] = self::entry( $recipient, Channel::PUSH, $status );
			}
			if ( null !== $email && $email->reaches( $recipient ) && ( $wants[ Channel::EMAIL ] || ( $fallback && ! $pushed ) ) ) {
				$report[] = self::entry( $recipient, Channel::EMAIL, $this->attempt( $email, $recipient, $notification ) );
			}
		}
		return $report;
	}

	/**
	 * Gewünschte Kanäle für einen Ereignistyp.
	 *
	 * @return array{email:bool,push:bool}
	 */
	private function wants( Recipient $recipient, string $type ): array {
		$default = array(
			Channel::EMAIL => true,
			Channel::PUSH  => false,
		);
		if ( null === $recipient->person_id ) {
			return $default;
		}
		$prefs = $this->preferences->for_person( $recipient->person_id );
		return array(
			Channel::EMAIL => (bool) ( $prefs[ $type ][ Channel::EMAIL ] ?? $default[ Channel::EMAIL ] ),
			Channel::PUSH  => (bool) ( $prefs[ $type ][ Channel::PUSH ] ?? $default[ Channel::PUSH ] ),
		);
	}

	private function attempt( Channel $channel, Recipient $recipient, Notification $notification ): string {
		$token = $this->log->claim( $recipient, $notification, $channel->name() );
		if ( null === $token ) {
			return self::DUPLICATE;
		}
		try {
			$ok = $channel->deliver( $recipient, $notification );
		} catch ( \Throwable $e ) {
			( $this->on_error )( $e, $channel->name() . ' ' . $notification->event . ' ' . $recipient->key() );
			$ok = false;
		}
		if ( ! $ok ) {
			$this->log->release( $recipient, $notification, $channel->name(), $token );
			return self::FAILED;
		}
		if ( ! $this->log->confirm( $recipient, $notification, $channel->name(), $token ) ) {
			// Ein späterer Lauf hat die Reservierung übernommen, weil der Versand länger als die
			// Frist dauerte. Die Nachricht ist dann möglicherweise doppelt hinausgegangen.
			( $this->on_error )( new \RuntimeException( 'Reservierung übernommen, Nachricht möglicherweise doppelt versendet.' ), $channel->name() . ' ' . $notification->event . ' ' . $recipient->key() );
		}
		return self::SENT;
	}

	/**
	 * @return array{recipient:string,channel:string,status:string}
	 */
	private static function entry( Recipient $recipient, string $channel, string $status ): array {
		return array(
			'recipient' => $recipient->key(),
			'channel'   => $channel,
			'status'    => $status,
		);
	}
}
