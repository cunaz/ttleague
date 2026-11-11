<?php
/**
 * Texte der Benachrichtigungen des Mannschaftscups.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cup;

use TTLeague\Domain\Cup\BracketRules;
use TTLeague\Frontend\Support\Format;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Links;
use TTLeague\Notifications\Notification;

/**
 * Jede Nachricht bezieht sich auf eine Paarung des Tableaus (ref_type «cup_tie»).
 *
 * | Art (Protokoll) | Einstellung  | Anlass                                              | Zusatz (ref_key)                         |
 * |-----------------|--------------|-----------------------------------------------------|------------------------------------------|
 * | cup_round       | cup_round    | Auslosung, neue Runde, angesetzt, Heimrecht geändert | Kennung aus Begegnung, Heimteam, Termin, Ort |
 * | cup_bye         | cup_round    | Freilos in der ersten Runde                         | leer                                     |
 * | cup_deadline    | cup_deadline | Frist der Runde läuft ab, kein Termin, kein Resultat | Frist (Datum)                            |
 *
 * So geht eine Mitteilung pro Person und Stand der Paarung einmal hinaus; ändert sich die
 * Begegnung (andere Mannschaft nach einer Korrektur, getauschtes Heimrecht, neuer Termin der
 * TK) oder wird die Frist verlängert, folgt eine neue.
 */
final class CupMessages {

	public const ROUND    = 'cup_round';
	public const BYE      = 'cup_bye';
	public const DEADLINE = 'cup_deadline';

	/** Bezug der Nachrichten im Versandprotokoll. */
	public const REF_TYPE = 'cup_tie';

	public function __construct( private readonly Links $links ) {}

	/**
	 * Neue oder geänderte Begegnung einer Runde, an beide Mannschaften.
	 *
	 * @param array{name:string}                                                                                            $competition Konkurrenz.
	 * @param array{id:int,round:int,deadline:string|null,fixture:array{id:int,scheduled_at:string|null,venue_id:int|null,home_entry:int|null,away_entry:int|null}} $tie Paarung mit Begegnung.
	 * @param int                                                                                                           $rounds      Anzahl Runden des Tableaus.
	 * @param array<int,string>                                                                                             $names       Meldung => Name.
	 * @param string|null                                                                                                   $venue       Ort (Lokal), falls angesetzt.
	 */
	public function round( array $competition, array $tie, int $rounds, array $names, ?string $venue ): Notification {
		$fixture = $tie['fixture'];
		$home    = (string) ( $names[ (int) $fixture['home_entry'] ] ?? '' );
		$away    = (string) ( $names[ (int) $fixture['away_entry'] ] ?? '' );
		$label   = Labels::round( $tie['round'], $rounds );
		$pairing = $home . ' – ' . $away;
		/* translators: 1: Konkurrenz, 2: Runde, 3: Begegnung */
		$subject    = sprintf( __( '%1$s, %2$s: %3$s', 'ttleague' ), $competition['name'], $label, $pairing );
		$paragraphs = array(
			1 === $tie['round']
				/* translators: 1: Konkurrenz, 2: Runde, 3: Begegnung */
				? sprintf( __( 'Die Auslosung für den %1$s ist freigegeben. %2$s: %3$s.', 'ttleague' ), $competition['name'], $label, $pairing )
				/* translators: 1: Konkurrenz, 2: Runde, 3: Begegnung */
				: sprintf( __( 'Im %1$s steht die nächste Begegnung fest. %2$s: %3$s.', 'ttleague' ), $competition['name'], $label, $pairing ),
		);
		$when       = Format::datetime( $fixture['scheduled_at'] );
		if ( BracketRules::is_neutral( $tie['round'], $rounds ) ) {
			$paragraphs[] = '' === $when
				? __( 'Ort und Termin setzt die TK an; ihr erhaltet dann eine Nachricht.', 'ttleague' )
				/* translators: 1: Datum und Zeit, 2: Ort mit Komma davor oder leer */
				: sprintf( __( 'Die Begegnung ist angesetzt: %1$s%2$s.', 'ttleague' ), $when, null === $venue ? '' : ', ' . $venue );
			$push = '' === $when ? __( 'Ort und Termin setzt die TK an.', 'ttleague' ) : $when . ( null === $venue ? '' : ', ' . $venue );
		} else {
			$paragraphs[] = '' === $when
				/* translators: %s: Heimteam */
				? sprintf( __( 'Heimrecht hat %s. Das Heimteam schlägt einen Termin vor, der Gegner stimmt zu.', 'ttleague' ), $home )
				/* translators: 1: Heimteam, 2: Datum und Zeit */
				: sprintf( __( 'Heimrecht hat %1$s. Termin: %2$s.', 'ttleague' ), $home, $when );
			/* translators: %s: Heimteam */
			$push = sprintf( __( 'Heimrecht hat %s.', 'ttleague' ), $home );
		}
		if ( null !== $tie['deadline'] && '' === $when ) {
			/* translators: %s: Datum */
			$paragraphs[] = sprintf( __( 'Die Runde ist bis am %s zu spielen.', 'ttleague' ), Format::date_plain( $tie['deadline'] ) );
		}
		$key = substr( md5( implode( '|', array( $fixture['id'], $fixture['home_entry'], (string) $fixture['scheduled_at'], (string) $fixture['venue_id'] ) ) ), 0, 16 );
		return $this->build( EventTypes::CUP_ROUND, self::ROUND, $tie['id'], $key, $subject, $paragraphs, $fixture['id'], __( 'Begegnung öffnen', 'ttleague' ), $push );
	}

	/**
	 * Freilos in der ersten Runde, an die Mannschaft.
	 *
	 * @param array{name:string}        $competition Konkurrenz.
	 * @param array{id:int,round:int}   $tie         Paarung.
	 * @param int                       $rounds      Anzahl Runden des Tableaus.
	 * @param string                    $team        Name der Mannschaft.
	 */
	public function bye( array $competition, array $tie, int $rounds, string $team ): Notification {
		/* translators: 1: Konkurrenz, 2: Cupmannschaft */
		$subject    = sprintf( __( '%1$s: Freilos für %2$s', 'ttleague' ), $competition['name'], $team );
		$paragraphs = array(
			/* translators: 1: Konkurrenz, 2: Cupmannschaft, 3: Runde */
			sprintf( __( 'Die Auslosung für den %1$s ist freigegeben. %2$s hat ein Freilos (%3$s) und steht in der nächsten Runde.', 'ttleague' ), $competition['name'], $team, Labels::round( $tie['round'], $rounds ) ),
			__( 'Sobald der Gegner feststeht, erhaltet ihr eine Nachricht.', 'ttleague' ),
		);
		return $this->build( EventTypes::CUP_ROUND, self::BYE, $tie['id'], '', $subject, $paragraphs, null, '', $paragraphs[0] );
	}

	/**
	 * Erinnerung vor Ablauf der Frist: Die Begegnung hat weder Termin noch Resultat.
	 *
	 * @param array{name:string}                                                                    $competition Konkurrenz.
	 * @param array{id:int,round:int,deadline:string,fixture:array{id:int,home_entry:int|null,away_entry:int|null}} $tie Paarung mit Begegnung und Frist.
	 * @param int                                                                                   $rounds      Anzahl Runden des Tableaus.
	 * @param array<int,string>                                                                     $names       Meldung => Name.
	 */
	public function deadline( array $competition, array $tie, int $rounds, array $names ): Notification {
		$fixture = $tie['fixture'];
		$pairing = ( $names[ (int) $fixture['home_entry'] ] ?? '' ) . ' – ' . ( $names[ (int) $fixture['away_entry'] ] ?? '' );
		$date    = Format::date_plain( $tie['deadline'] );
		/* translators: %s: Begegnung */
		$subject    = sprintf( __( 'Cup: Die Frist läuft ab, %s', 'ttleague' ), $pairing );
		$paragraphs = array(
			/* translators: 1: Begegnung, 2: Konkurrenz, 3: Runde, 4: Datum */
			sprintf( __( 'Die Begegnung %1$s (%2$s, %3$s) ist bis am %4$s zu spielen. Es ist noch kein Termin vereinbart.', 'ttleague' ), $pairing, $competition['name'], Labels::round( $tie['round'], $rounds ), $date ),
			__( 'Das Heimteam schlägt einen Termin vor, der Gegner stimmt zu. Nach Ablauf der Frist entscheidet die TK: Sie verlängert die Frist oder wertet forfait.', 'ttleague' ),
		);
		/* translators: 1: Begegnung, 2: Datum */
		$push = sprintf( __( '%1$s: bis am %2$s zu spielen, noch ohne Termin.', 'ttleague' ), $pairing, $date );
		return $this->build( EventTypes::CUP_DEADLINE, self::DEADLINE, $tie['id'], $tie['deadline'], $subject, $paragraphs, $fixture['id'], __( 'Termin vereinbaren', 'ttleague' ), $push );
	}

	/**
	 * @param list<string> $paragraphs Absätze.
	 * @param int|null     $fixture_id Begegnung für den Link auf die Erfassungsseite; ohne führt der Link in «Mein Bereich».
	 */
	private function build( string $type, string $event, int $tie_id, string $ref_key, string $subject, array $paragraphs, ?int $fixture_id, string $action_label, string $push_body ): Notification {
		$url = null === $fixture_id ? $this->links->my_area() : $this->links->entry( $fixture_id );
		return new Notification(
			$type,
			$event,
			self::REF_TYPE,
			$tie_id,
			$ref_key,
			$subject,
			$paragraphs,
			array(),
			null === $fixture_id ? null : array(
				'url'   => $url,
				'label' => $action_label,
			),
			$this->links->my_area(),
			array(
				'title' => self::shorten( $subject, 80 ),
				'body'  => self::shorten( $push_body, 180 ),
				'url'   => $url,
				'tag'   => null === $fixture_id ? 'ttleague-cup-tie-' . $tie_id : 'ttleague-fixture-' . $fixture_id,
			)
		);
	}

	private static function shorten( string $text, int $length ): string {
		return mb_strlen( $text ) <= $length ? $text : rtrim( mb_substr( $text, 0, $length - 1 ) ) . '…';
	}
}
