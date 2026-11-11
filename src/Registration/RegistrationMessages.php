<?php
/**
 * Texte der Benachrichtigungen der Meldung (Schweizer Schreibweise) für E-Mail und Push.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Registration;

use TTLeague\Frontend\Support\Format;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Links;
use TTLeague\Notifications\Notification;

/**
 * Zwei Arten: registration_decided (Entscheid der TK, Bezug ist der Eintrag im audit_log, jeder
 * Entscheid ergibt also eine Nachricht) und registration_reminder (Erinnerung vor Ablauf des
 * Meldefensters, Bezug ist der Meldestand des Clubs mit letztem Tag und Stufe; wird das
 * Fenster verlängert, gilt die Erinnerung neu). Beide verweisen auf die Meldung in «Mein Bereich».
 */
final class RegistrationMessages {

	public const DECIDED  = 'registration_decided';
	public const REMINDER = 'registration_reminder';

	public const REF_DECISION    = 'audit_log';
	public const REF_CLUB_SEASON = 'club_season';

	/** Höchstlänge des Textes einer Push-Nachricht. */
	private const PUSH_BODY_LENGTH = 180;

	public function __construct( private readonly Links $links ) {}

	/**
	 * Entscheid der TK an die TK-Chefs des Clubs.
	 */
	public function decided( Decision $decision ): Notification {
		$club   = $decision->club_name;
		$season = $decision->season_name;
		$name   = $decision->subject;
		switch ( $decision->kind ) {
			case Decision::REGISTRATION_APPROVED:
				/* translators: 1: Saison, 2: Club */
				$subject = sprintf( __( 'Meldung %1$s geprüft: %2$s', 'ttleague' ), $season, $club );
				/* translators: 1: Club, 2: Saison */
				$text = sprintf( __( 'Die TK hat die Meldung von %1$s für die Saison %2$s geprüft und freigegeben. Die gemeldeten Spielerinnen und Spieler sind damit für die Saison gemeldet.', 'ttleague' ), $club, $season );
				break;
			case Decision::REGISTRATION_RETURNED:
				/* translators: 1: Saison, 2: Club */
				$subject = sprintf( __( 'Meldung %1$s zurückgegeben: %2$s', 'ttleague' ), $season, $club );
				/* translators: 1: Club, 2: Saison */
				$text = sprintf( __( 'Die TK hat die Meldung von %1$s für die Saison %2$s zurückgegeben. Bitte ergänze sie und reiche sie erneut ein.', 'ttleague' ), $club, $season );
				break;
			case Decision::REQUEST_APPROVED:
				/* translators: %s: Name */
				$subject = sprintf( __( 'Neumeldung freigegeben: %s', 'ttleague' ), $name );
				/* translators: 1: Name, 2: Club, 3: Saison */
				$text = sprintf( __( 'Die TK hat %1$s für %2$s freigegeben (Saison %3$s) und die Lizenznummer vergeben.', 'ttleague' ), $name, $club, $season );
				break;
			case Decision::REQUEST_REJECTED:
				/* translators: %s: Name */
				$subject = sprintf( __( 'Neumeldung abgelehnt: %s', 'ttleague' ), $name );
				/* translators: 1: Name, 2: Club, 3: Saison */
				$text = sprintf( __( 'Die TK hat die Neumeldung von %1$s für %2$s abgelehnt (Saison %3$s).', 'ttleague' ), $name, $club, $season );
				break;
			case Decision::PLAYER_APPROVED:
				/* translators: %s: Name */
				$subject = sprintf( __( 'Meldung freigegeben: %s', 'ttleague' ), $name );
				/* translators: 1: Name, 2: Club, 3: Saison */
				$text = sprintf( __( 'Die TK hat die Meldung von %1$s für %2$s freigegeben (Saison %3$s). Ab jetzt ist die Spielberechtigung gegeben.', 'ttleague' ), $name, $club, $season );
				break;
			default:
				/* translators: %s: Name */
				$subject = sprintf( __( 'Meldung abgelehnt: %s', 'ttleague' ), $name );
				/* translators: 1: Name, 2: Club, 3: Saison */
				$text = sprintf( __( 'Die TK hat die Meldung von %1$s für %2$s abgelehnt (Saison %3$s).', 'ttleague' ), $name, $club, $season );
		}
		$paragraphs = array( $text );
		if ( null !== $decision->note && '' !== trim( $decision->note ) ) {
			/* translators: %s: Bemerkung */
			$paragraphs[] = sprintf( __( 'Bemerkung der TK: %s', 'ttleague' ), trim( $decision->note ) );
		}
		return $this->build( EventTypes::REGISTRATION_DECIDED, self::DECIDED, self::REF_DECISION, $decision->audit_id, '', $decision->club_id, $subject, $paragraphs );
	}

	/**
	 * Erinnerung an die TK-Chefs, deren Meldung kurz vor Ablauf des Fensters noch offen ist.
	 *
	 * @param array{name:string,registration_closes:string|null} $season         Geplante Saison.
	 * @param array{id:int,name:string}                          $club           Club.
	 * @param int                                                $club_season_id Meldestand (Bezug).
	 * @param int                                                $step           Stufe in Tagen vor dem letzten Tag.
	 * @param int                                                $days_left      Tage bis zum letzten Tag.
	 */
	public function reminder( array $season, array $club, int $club_season_id, int $step, int $days_left ): Notification {
		$closes = (string) $season['registration_closes'];
		$date   = Format::date( $closes );
		if ( $days_left <= 0 ) {
			/* translators: %s: Saison */
			$subject = sprintf( __( 'Meldung %s: Das Meldefenster schliesst heute', 'ttleague' ), $season['name'] );
		} else {
			/* translators: 1: Saison, 2: Tage */
			$subject = sprintf( _n( 'Meldung %1$s: noch %2$d Tag', 'Meldung %1$s: noch %2$d Tage', $days_left, 'ttleague' ), $season['name'], $days_left );
		}
		$paragraphs = array(
			/* translators: 1: Club, 2: Saison, 3: Datum */
			sprintf( __( 'Die Meldung von %1$s für die Saison %2$s ist noch nicht eingereicht. Das Meldefenster schliesst am %3$s.', 'ttleague' ), $club['name'], $season['name'], $date ),
			__( 'Bitte prüfe Spieler, Mannschaften, Captains und Lokale und reiche die Meldung ein. Nach Ablauf des Fensters ändert nur noch die TK.', 'ttleague' ),
		);
		return $this->build( EventTypes::REGISTRATION_REMINDER, self::REMINDER, self::REF_CLUB_SEASON, $club_season_id, $closes . ':' . $step, $club['id'], $subject, $paragraphs );
	}

	/**
	 * Adresse der Meldung eines Clubs in «Mein Bereich».
	 */
	public function url( int $club_id ): string {
		return $this->links->my_area() . '#' . self::anchor( $club_id );
	}

	/**
	 * Anker des Abschnitts «Meldung» eines Clubs in «Mein Bereich».
	 */
	public static function anchor( int $club_id ): string {
		return 'ttl-meldung-' . $club_id;
	}

	/**
	 * @param list<string> $paragraphs Absätze.
	 */
	private function build( string $type, string $event, string $ref_type, int $ref_id, string $ref_key, int $club_id, string $subject, array $paragraphs ): Notification {
		$url = $this->url( $club_id );
		return new Notification(
			$type,
			$event,
			$ref_type,
			$ref_id,
			$ref_key,
			$subject,
			$paragraphs,
			array(),
			array(
				'url'   => $url,
				'label' => __( 'Meldung ansehen', 'ttleague' ),
			),
			$this->links->my_area(),
			array(
				'title' => self::shorten( $subject, 80 ),
				'body'  => self::shorten( $paragraphs[0] ),
				'url'   => $url,
				'tag'   => 'ttleague-registration-' . $club_id,
			)
		);
	}

	private static function shorten( string $text, int $length = self::PUSH_BODY_LENGTH ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return mb_strlen( $text ) <= $length ? $text : rtrim( mb_substr( $text, 0, $length - 1 ) ) . '…';
	}
}
