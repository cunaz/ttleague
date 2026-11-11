<?php
/**
 * Texte der Benachrichtigungen zu Anlässen (Schweizer Schreibweise) für E-Mail und Push.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

use TTLeague\Domain\Events\RegistrationRules as Rules;
use TTLeague\Frontend\Support\Format;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Notification;

/**
 * Alle Nachrichten gehören zum Ereignistyp event_registration (Einstellungen in «Mein Bereich»).
 * Arten im Versandprotokoll und ihr Bezug für die Dedupe:
 *
 * - event_registered: Anmeldung (ref_type registration, ref_key registered oder waitlist)
 * - event_unregistered: gelöschte Anmeldung (ref_type registration)
 * - event_promoted: nachgerückte Anmeldung (ref_type registration)
 * - event_cancelled: Anlass (ref_type event)
 *
 * Eine neue Anmeldung nach einer Abmeldung hat eine neue ID und wird wieder bestätigt.
 * Termine sind Ortszeit der Website.
 */
final class EventMessages {

	public const REGISTERED   = 'event_registered';
	public const UNREGISTERED = 'event_unregistered';
	public const PROMOTED     = 'event_promoted';
	public const CANCELLED    = 'event_cancelled';

	public const REF_REGISTRATION = 'registration';
	public const REF_EVENT        = 'event';

	/** Höchstlänge des Textes einer Push-Nachricht. */
	private const PUSH_BODY_LENGTH = 180;

	public function __construct( private readonly Links $links ) {}

	/**
	 * Bestätigung der Anmeldung, auch auf die Warteliste.
	 *
	 * @param array<string,mixed> $event  Anlass.
	 * @param string              $status registered oder waitlist.
	 * @param string|null         $by     Name der Person, die angemeldet hat; null bei der eigenen Anmeldung.
	 */
	public function registered( array $event, int $registration_id, string $status, ?string $by ): Notification {
		$what = self::describe( $event );
		if ( Rules::WAITLIST === $status ) {
			/* translators: %s: Titel des Anlasses */
			$subject = sprintf( __( 'Warteliste: %s', 'ttleague' ), $event['title'] );
			$first   = null === $by
				/* translators: %s: Anlass mit Termin und Ort */
				? sprintf( __( 'Die Höchstzahl ist erreicht. Du stehst auf der Warteliste für %s.', 'ttleague' ), $what )
				/* translators: 1: Name, 2: Anlass mit Termin und Ort */
				: sprintf( __( '%1$s hat dich für %2$s angemeldet. Die Höchstzahl ist erreicht, du stehst auf der Warteliste.', 'ttleague' ), $by, $what );
			$paragraphs = array( $first, __( 'Wird ein Platz frei, rückst du in der Reihenfolge der Anmeldung nach und erhältst eine Mitteilung.', 'ttleague' ) );
		} else {
			/* translators: %s: Titel des Anlasses */
			$subject = sprintf( __( 'Anmeldung bestätigt: %s', 'ttleague' ), $event['title'] );
			$first   = null === $by
				/* translators: %s: Anlass mit Termin und Ort */
				? sprintf( __( 'Du bist für %s angemeldet.', 'ttleague' ), $what )
				/* translators: 1: Name, 2: Anlass mit Termin und Ort */
				: sprintf( __( '%1$s hat dich für %2$s angemeldet.', 'ttleague' ), $by, $what );
			$paragraphs = array( $first );
		}
		$paragraphs[] = null === $event['deadline']
			? __( 'Kannst du nicht teilnehmen, melde dich bitte wieder ab.', 'ttleague' )
			/* translators: %s: Anmeldeschluss */
			: sprintf( __( 'Kannst du nicht teilnehmen, melde dich bitte wieder ab: bis zum Anmeldeschluss (%s) auf der Website, danach bei der TK.', 'ttleague' ), Format::datetime( $event['deadline'] ) );
		return $this->build( $event, self::REGISTERED, self::REF_REGISTRATION, $registration_id, $status, $subject, $paragraphs );
	}

	/**
	 * Bestätigung der Abmeldung.
	 *
	 * @param array<string,mixed> $event Anlass.
	 * @param string|null         $by    Name der Person, die abgemeldet hat; null bei der eigenen Abmeldung.
	 */
	public function unregistered( array $event, int $registration_id, ?string $by ): Notification {
		$what = self::describe( $event );
		/* translators: %s: Titel des Anlasses */
		$subject = sprintf( __( 'Abmeldung bestätigt: %s', 'ttleague' ), $event['title'] );
		$first   = null === $by
			/* translators: %s: Anlass mit Termin und Ort */
			? sprintf( __( 'Du bist von %s abgemeldet.', 'ttleague' ), $what )
			/* translators: 1: Name, 2: Anlass mit Termin und Ort */
			: sprintf( __( '%1$s hat dich von %2$s abgemeldet.', 'ttleague' ), $by, $what );
		return $this->build( $event, self::UNREGISTERED, self::REF_REGISTRATION, $registration_id, '', $subject, array( $first ) );
	}

	/**
	 * Nachrücken von der Warteliste.
	 *
	 * @param array<string,mixed> $event Anlass.
	 */
	public function promoted( array $event, int $registration_id ): Notification {
		/* translators: %s: Titel des Anlasses */
		$subject    = sprintf( __( 'Platz frei: %s', 'ttleague' ), $event['title'] );
		$paragraphs = array(
			/* translators: %s: Anlass mit Termin und Ort */
			sprintf( __( 'Ein Platz ist frei geworden: Du bist von der Warteliste nachgerückt und jetzt für %s angemeldet.', 'ttleague' ), self::describe( $event ) ),
			__( 'Kannst du nicht teilnehmen, melde dich bitte ab, damit die nächste Person nachrücken kann.', 'ttleague' ),
		);
		return $this->build( $event, self::PROMOTED, self::REF_REGISTRATION, $registration_id, '', $subject, $paragraphs );
	}

	/**
	 * Absage an alle Teilnehmenden und die Warteliste.
	 *
	 * @param array<string,mixed> $event Anlass.
	 */
	public function cancelled( array $event ): Notification {
		/* translators: %s: Titel des Anlasses */
		$subject    = sprintf( __( 'Abgesagt: %s', 'ttleague' ), $event['title'] );
		$paragraphs = array(
			/* translators: %s: Anlass mit Termin und Ort */
			sprintf( __( 'Der Anlass %s ist abgesagt. Deine Anmeldung ist damit hinfällig.', 'ttleague' ), self::describe( $event ) ),
		);
		return $this->build( $event, self::CANCELLED, self::REF_EVENT, (int) $event['id'], '', $subject, $paragraphs, 'high' );
	}

	/**
	 * «Plauschturnier (Fr, 20.11.2026, 19:30, Turnhalle Seefeld)».
	 *
	 * @param array<string,mixed> $event Anlass.
	 */
	public static function describe( array $event ): string {
		$parts = array_filter(
			array( Format::datetime( $event['starts_at'] ), (string) ( $event['location'] ?? '' ) ),
			static fn( string $part ): bool => '' !== $part
		);
		return $event['title'] . ( array() === $parts ? '' : ' (' . implode( ', ', $parts ) . ')' );
	}

	/**
	 * @param array<string,mixed> $event      Anlass.
	 * @param list<string>        $paragraphs Absätze.
	 */
	private function build( array $event, string $kind, string $ref_type, int $ref_id, string $ref_key, string $subject, array $paragraphs, string $urgency = 'normal' ): Notification {
		$url = $this->links->event( $event );
		return new Notification(
			EventTypes::EVENT_REGISTRATION,
			$kind,
			$ref_type,
			$ref_id,
			$ref_key,
			$subject,
			$paragraphs,
			array(),
			null === $url ? null : array(
				'url'   => $url,
				'label' => __( 'Zum Anlass', 'ttleague' ),
			),
			$this->links->my_area(),
			array(
				'title' => self::shorten( $subject, 80 ),
				'body'  => self::shorten( $paragraphs[0] ),
				'url'   => $url ?? $this->links->my_area(),
				'tag'   => 'ttleague-event-' . (int) $event['id'],
			),
			86400,
			$urgency
		);
	}

	private static function shorten( string $text, int $length = self::PUSH_BODY_LENGTH ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return mb_strlen( $text ) <= $length ? $text : rtrim( mb_substr( $text, 0, $length - 1 ) ) . '…';
	}
}
