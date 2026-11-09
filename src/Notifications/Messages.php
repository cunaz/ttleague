<?php
/**
 * Texte der Benachrichtigungen (Schweizer Schreibweise) für E-Mail und Push.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

use TTLeague\Frontend\Support\Format;
use TTLeague\Results\FixtureContext;

/**
 * Baut die Benachrichtigungen. Jede verweist auf die Erfassungsseite der Begegnung und auf
 * «Mein Bereich» für die Einstellungen. Zeiten der Begegnung sind Ortszeit der Website.
 *
 * Arten (Protokoll) und ihre Ereignistypen (Einstellungen):
 * match_reminder (match_reminder), result_missing und result_missing_tk und forfeit_proposal
 * (result_missing), confirm_request (confirm_request), confirm_reminder und confirm_overdue
 * (confirm_reminder), result_disputed (result_disputed), result_decided (result_decided),
 * tk_digest (tk_digest).
 */
final class Messages {

	public const MATCH_REMINDER    = 'match_reminder';
	public const RESULT_MISSING    = 'result_missing';
	public const RESULT_MISSING_TK = 'result_missing_tk';
	public const FORFEIT_PROPOSAL  = 'forfeit_proposal';
	public const CONFIRM_REQUEST   = 'confirm_request';
	public const CONFIRM_REMINDER  = 'confirm_reminder';
	public const CONFIRM_OVERDUE   = 'confirm_overdue';
	public const RESULT_DISPUTED   = 'result_disputed';
	public const RESULT_DECIDED    = 'result_decided';
	public const TK_DIGEST         = 'tk_digest';

	/** Höchstlänge des Textes einer Push-Nachricht. */
	private const PUSH_BODY_LENGTH = 180;

	public function __construct( private readonly Links $links ) {}

	/**
	 * «Bitte bestätigen» an die Gastseite, nach einer Änderung als geändertes Resultat.
	 *
	 * @param int         $event_id Verlaufseintrag entered oder edited (Bezug).
	 * @param bool        $edited   Änderung eines provisorischen Resultats.
	 * @param string|null $by       Name der Person, die erfasst hat.
	 */
	public function confirm_request( FixtureContext $ctx, int $event_id, bool $edited, ?string $by ): Notification {
		$title = self::title( $ctx );
		$score = self::score( $ctx );
		$who   = self::name_or( $by, $ctx->home['name'] );
		if ( $edited ) {
			/* translators: %s: Begegnung */
			$subject = sprintf( __( 'Geändertes Resultat bestätigen: %s', 'ttleague' ), $title );
			/* translators: 1: Name oder Team, 2: Begegnung mit Wettbewerb und Termin, 3: Resultat */
			$first = sprintf( __( '%1$s hat das Resultat der Begegnung %2$s geändert. Neues Resultat: %3$s.', 'ttleague' ), $who, self::describe( $ctx ), $score );
			/* translators: %s: Resultat */
			$push_title = sprintf( __( 'Resultat geändert: %s', 'ttleague' ), $title );
			/* translators: %s: Resultat */
			$push_body = sprintf( __( 'Neues Resultat %s. Bitte prüfen und bestätigen.', 'ttleague' ), $score );
		} else {
			/* translators: %s: Begegnung */
			$subject = sprintf( __( 'Bitte bestätigen: %s', 'ttleague' ), $title );
			/* translators: 1: Name oder Team, 2: Begegnung mit Wettbewerb und Termin, 3: Resultat */
			$first      = sprintf( __( '%1$s hat das Resultat der Begegnung %2$s erfasst: %3$s.', 'ttleague' ), $who, self::describe( $ctx ), $score );
			$push_title = $subject;
			/* translators: %s: Resultat */
			$push_body = sprintf( __( 'Resultat %s erfasst. Bitte prüfen und bestätigen.', 'ttleague' ), $score );
		}
		$paragraphs = array(
			$first,
			/* translators: %d: Tage */
			sprintf( _n( 'Bitte prüfe das Resultat und bestätige es. Stimmt etwas nicht, erhebe innert %d Tag Einsprache.', 'Bitte prüfe das Resultat und bestätige es. Stimmt etwas nicht, erhebe innert %d Tagen Einsprache.', $ctx->rules->objection_days, 'ttleague' ), $ctx->rules->objection_days ),
		);
		return $this->build( $ctx, EventTypes::CONFIRM_REQUEST, self::CONFIRM_REQUEST, 'fixture_event', $event_id, '', $subject, $paragraphs, __( 'Resultat prüfen', 'ttleague' ), $push_title, $push_body, 'high' );
	}

	/**
	 * Erinnerung an die Gastseite: Bestätigung ausstehend.
	 *
	 * @param int $event_id Letzte Erfassung oder Änderung (Bezug).
	 * @param int $days     Stufe in Tagen nach der Erfassung.
	 */
	public function confirm_reminder( FixtureContext $ctx, int $event_id, int $days ): Notification {
		$title = self::title( $ctx );
		/* translators: %s: Begegnung */
		$subject    = sprintf( __( 'Erinnerung: Resultat bestätigen, %s', 'ttleague' ), $title );
		$paragraphs = array(
			/* translators: 1: Resultat, 2: Begegnung mit Wettbewerb und Termin, 3: Tage */
			sprintf( _n( 'Das Resultat %1$s der Begegnung %2$s wartet seit %3$d Tag auf eure Bestätigung.', 'Das Resultat %1$s der Begegnung %2$s wartet seit %3$d Tagen auf eure Bestätigung.', $days, 'ttleague' ), self::score( $ctx ), self::describe( $ctx ), $days ),
			/* translators: %d: Tage */
			sprintf( _n( 'Bitte bestätige es oder erhebe Einsprache. Die Einsprachefrist endet %d Tag nach der Erfassung, danach bestätigt die TK.', 'Bitte bestätige es oder erhebe Einsprache. Die Einsprachefrist endet %d Tage nach der Erfassung, danach bestätigt die TK.', $ctx->rules->objection_days, 'ttleague' ), $ctx->rules->objection_days ),
		);
		/* translators: 1: Begegnung, 2: Resultat, 3: Tage */
		$push_body = sprintf( _n( '%1$s: %2$s wartet seit %3$d Tag auf eure Bestätigung.', '%1$s: %2$s wartet seit %3$d Tagen auf eure Bestätigung.', $days, 'ttleague' ), $title, self::score( $ctx ), $days );
		return $this->build( $ctx, EventTypes::CONFIRM_REMINDER, self::CONFIRM_REMINDER, 'fixture_event', $event_id, (string) $days, $subject, $paragraphs, __( 'Resultat prüfen', 'ttleague' ), __( 'Erinnerung: Resultat bestätigen', 'ttleague' ), $push_body );
	}

	/**
	 * An die TK: Bestätigung durch die TK nötig.
	 *
	 * @param int $event_id Letzte Erfassung oder Änderung (Bezug).
	 */
	public function confirm_overdue( FixtureContext $ctx, int $event_id ): Notification {
		$title = self::title( $ctx );
		$days  = $ctx->rules->objection_days;
		/* translators: %s: Begegnung */
		$subject    = sprintf( __( 'Bestätigung durch die TK nötig: %s', 'ttleague' ), $title );
		$paragraphs = array(
			/* translators: 1: Resultat, 2: Begegnung mit Wettbewerb und Termin, 3: Tage */
			sprintf( _n( 'Das Resultat %1$s der Begegnung %2$s ist seit %3$d Tag provisorisch. Die Gastmannschaft hat es weder bestätigt noch Einsprache erhoben.', 'Das Resultat %1$s der Begegnung %2$s ist seit %3$d Tagen provisorisch. Die Gastmannschaft hat es weder bestätigt noch Einsprache erhoben.', $days, 'ttleague' ), self::score( $ctx ), self::describe( $ctx ), $days ),
			__( 'Bitte prüfe und bestätige das Resultat.', 'ttleague' ),
		);
		/* translators: 1: Begegnung, 2: Resultat */
		$push_body = sprintf( __( '%1$s: %2$s ist noch nicht bestätigt.', 'ttleague' ), $title, self::score( $ctx ) );
		return $this->build( $ctx, EventTypes::CONFIRM_REMINDER, self::CONFIRM_OVERDUE, 'fixture_event', $event_id, '', $subject, $paragraphs, __( 'Resultat prüfen', 'ttleague' ), __( 'Bestätigung durch die TK nötig', 'ttleague' ), $push_body );
	}

	/**
	 * Erinnerung vor dem Spiel an beide Seiten.
	 *
	 * @param array{name:string,street:string|null,zip:string|null,city:string|null,open_time:string|null}|null $venue Spiellokal.
	 */
	public function match_reminder( FixtureContext $ctx, ?array $venue ): Notification {
		$title = self::title( $ctx );
		$date  = Format::date( $ctx->scheduled_at );
		$time  = self::time( $ctx );
		/* translators: 1: Datum, 2: Begegnung */
		$subject = sprintf( __( 'Spiel am %1$s: %2$s', 'ttleague' ), $date, $title );
		if ( '' === $time ) {
			/* translators: 1: Datum, 2: Heimteam, 3: Gastteam, 4: Wettbewerb */
			$first = sprintf( __( 'Am %1$s spielt %2$s gegen %3$s (%4$s). Die Anspielzeit ist noch offen.', 'ttleague' ), $date, $ctx->home['name'], $ctx->away['name'], self::competition( $ctx ) );
		} else {
			/* translators: 1: Datum, 2: Zeit, 3: Heimteam, 4: Gastteam, 5: Wettbewerb */
			$first = sprintf( __( 'Am %1$s um %2$s spielt %3$s gegen %4$s (%5$s).', 'ttleague' ), $date, $time, $ctx->home['name'], $ctx->away['name'], self::competition( $ctx ) );
		}
		$paragraphs = array( $first );
		$place      = '';
		if ( null !== $venue && '' !== trim( $venue['name'] ) ) {
			$address = Format::address( $venue['street'], $venue['zip'], $venue['city'] );
			$place   = trim( $venue['name'] );
			/* translators: %s: Spiellokal */
			$line = sprintf( __( 'Spiellokal: %s', 'ttleague' ), '' === $address ? $place : $place . ', ' . $address );
			$open = Format::time( $venue['open_time'] );
			if ( '' !== $open ) {
				/* translators: %s: Zeit */
				$line .= "\n" . sprintf( __( 'Halle offen ab %s', 'ttleague' ), $open );
			}
			if ( '' !== $address ) {
				/* translators: %s: Adresse einer Karte */
				$line .= "\n" . sprintf( __( 'Karte: %s', 'ttleague' ), Format::map_url( $address ) );
			}
			$paragraphs[] = $line;
		}
		/* translators: %d: Stunden */
		$paragraphs[] = sprintf( _n( 'Nach dem Spiel erfasst die Heimmannschaft das Resultat innert %d Stunde, die Gastmannschaft bestätigt es.', 'Nach dem Spiel erfasst die Heimmannschaft das Resultat innert %d Stunden, die Gastmannschaft bestätigt es.', $ctx->rules->entry_deadline_hours, 'ttleague' ), $ctx->rules->entry_deadline_hours );

		/* translators: %s: Datum und Zeit */
		$push_title = sprintf( __( 'Spiel am %s', 'ttleague' ), '' === $time ? $date : $date . ', ' . $time );
		$push_body  = '' === $place ? $title : $title . ', ' . $place;
		return $this->build( $ctx, EventTypes::MATCH_REMINDER, self::MATCH_REMINDER, 'fixture', $ctx->id, self::schedule_key( $ctx ), $subject, $paragraphs, __( 'Begegnung öffnen', 'ttleague' ), $push_title, $push_body );
	}

	/**
	 * Resultat fehlt: an die Heimseite.
	 *
	 * @param string $deadline_local Ende der Frist (Ortszeit).
	 * @param bool   $late           Die Frist ist schon abgelaufen (Nachricht nachgeholt).
	 */
	public function result_missing( FixtureContext $ctx, string $deadline_local, bool $late = false ): Notification {
		$title = self::title( $ctx );
		/* translators: %s: Begegnung */
		$subject    = sprintf( __( 'Resultat fehlt: %s', 'ttleague' ), $title );
		$hours      = $ctx->rules->entry_deadline_hours;
		$paragraphs = array(
			/* translators: %s: Begegnung mit Wettbewerb und Termin */
			sprintf( __( 'Für die Begegnung %s ist noch kein Resultat erfasst.', 'ttleague' ), self::describe( $ctx ) ),
			$late
				/* translators: %d: Stunden */
				? sprintf( _n( 'Die Frist von %d Stunde nach Anspielzeit ist abgelaufen. Bitte erfasse das Resultat so bald wie möglich.', 'Die Frist von %d Stunden nach Anspielzeit ist abgelaufen. Bitte erfasse das Resultat so bald wie möglich.', $hours, 'ttleague' ), $hours )
				/* translators: 1: Datum und Zeit, 2: Stunden */
				: sprintf( _n( 'Bitte erfasse es bis %1$s (%2$d Stunde nach Anspielzeit).', 'Bitte erfasse es bis %1$s (%2$d Stunden nach Anspielzeit).', $hours, 'ttleague' ), Format::datetime( $deadline_local ), $hours ),
		);
		$push_body  = $late
			/* translators: %s: Begegnung */
			? sprintf( __( '%s: Bitte erfasse das Resultat so bald wie möglich.', 'ttleague' ), $title )
			/* translators: 1: Begegnung, 2: Datum und Zeit */
			: sprintf( __( '%1$s: Bitte erfasse das Resultat bis %2$s.', 'ttleague' ), $title, Format::datetime( $deadline_local ) );
		return $this->build( $ctx, EventTypes::RESULT_MISSING, self::RESULT_MISSING, 'fixture', $ctx->id, self::schedule_key( $ctx ), $subject, $paragraphs, __( 'Resultat erfassen', 'ttleague' ), __( 'Resultat fehlt', 'ttleague' ), $push_body );
	}

	/**
	 * Resultat fehlt weiterhin: an die TK.
	 */
	public function result_missing_tk( FixtureContext $ctx ): Notification {
		$title = self::title( $ctx );
		$hours = $ctx->rules->entry_deadline_hours;
		/* translators: %s: Begegnung */
		$subject    = sprintf( __( 'Resultat fehlt weiterhin: %s', 'ttleague' ), $title );
		$paragraphs = array(
			/* translators: 1: Begegnung mit Wettbewerb und Termin, 2: Stunden */
			sprintf( _n( 'Für die Begegnung %1$s ist %2$d Stunde nach Anspielzeit noch kein Resultat erfasst.', 'Für die Begegnung %1$s ist %2$d Stunden nach Anspielzeit noch kein Resultat erfasst.', $hours, 'ttleague' ), self::describe( $ctx ), $hours ),
			/* translators: %s: Heimteam */
			sprintf( __( 'Erfassen muss es die Heimmannschaft %s.', 'ttleague' ), $ctx->home['name'] ),
		);
		/* translators: %s: Begegnung */
		$push_body = sprintf( __( '%s: noch kein Resultat erfasst.', 'ttleague' ), $title );
		return $this->build( $ctx, EventTypes::RESULT_MISSING, self::RESULT_MISSING_TK, 'fixture', $ctx->id, self::schedule_key( $ctx ), $subject, $paragraphs, __( 'Begegnung öffnen', 'ttleague' ), __( 'Resultat fehlt weiterhin', 'ttleague' ), $push_body );
	}

	/**
	 * Forfait-Vorschlag an die TK.
	 */
	public function forfeit_proposal( FixtureContext $ctx ): Notification {
		$title = self::title( $ctx );
		$days  = $ctx->rules->forfeit_after_days;
		/* translators: %s: Begegnung */
		$subject    = sprintf( __( 'Forfait-Vorschlag: %s', 'ttleague' ), $title );
		$paragraphs = array(
			/* translators: 1: Begegnung mit Wettbewerb und Termin, 2: Tage */
			sprintf( _n( 'Für die Begegnung %1$s liegt %2$d Tag nach Anspielzeit kein Resultat vor.', 'Für die Begegnung %1$s liegt %2$d Tage nach Anspielzeit kein Resultat vor.', $days, 'ttleague' ), self::describe( $ctx ), $days ),
			__( 'Vorschlag: Die TK wertet die Begegnung forfait. Entscheiden kannst du auf der Erfassungsseite.', 'ttleague' ),
		);
		/* translators: 1: Begegnung, 2: Tage */
		$push_body = sprintf( _n( '%1$s: seit %2$d Tag ohne Resultat.', '%1$s: seit %2$d Tagen ohne Resultat.', $days, 'ttleague' ), $title, $days );
		return $this->build( $ctx, EventTypes::RESULT_MISSING, self::FORFEIT_PROPOSAL, 'fixture', $ctx->id, self::schedule_key( $ctx ), $subject, $paragraphs, __( 'Entscheiden', 'ttleague' ), __( 'Forfait-Vorschlag', 'ttleague' ), $push_body );
	}

	/**
	 * Einsprache: an die TK und die Heimseite.
	 *
	 * @param int         $event_id Verlaufseintrag disputed (Bezug).
	 * @param string|null $by       Name der Person, die Einsprache erhoben hat.
	 */
	public function result_disputed( FixtureContext $ctx, int $event_id, string $reason, ?string $by ): Notification {
		$title = self::title( $ctx );
		/* translators: %s: Begegnung */
		$subject    = sprintf( __( 'Einsprache: %s', 'ttleague' ), $title );
		$paragraphs = array(
			/* translators: 1: Name oder Team, 2: Resultat, 3: Begegnung mit Wettbewerb und Termin */
			sprintf( __( '%1$s hat gegen das Resultat %2$s der Begegnung %3$s Einsprache erhoben.', 'ttleague' ), self::name_or( $by, $ctx->away['name'] ), self::score( $ctx ), self::describe( $ctx ) ),
		);
		if ( '' !== trim( $reason ) ) {
			/* translators: %s: Begründung */
			$paragraphs[] = sprintf( __( 'Begründung: «%s»', 'ttleague' ), trim( $reason ) );
		}
		$paragraphs[] = __( 'Die TK entscheidet über die Einsprache.', 'ttleague' );
		/* translators: %s: Resultat */
		$push_body = sprintf( __( 'Gegen das Resultat %s wurde Einsprache erhoben.', 'ttleague' ), self::score( $ctx ) );
		return $this->build( $ctx, EventTypes::RESULT_DISPUTED, self::RESULT_DISPUTED, 'fixture_event', $event_id, '', $subject, $paragraphs, __( 'Begegnung öffnen', 'ttleague' ), $subject, $push_body, 'high' );
	}

	/**
	 * Entscheid oder Bestätigung der TK: an beide Seiten.
	 *
	 * @param int         $event_id Verlaufseintrag (Bezug).
	 * @param string      $action   confirm, finalize, correct, forfeit oder reset.
	 * @param string|null $comment  Bemerkung der TK.
	 * @param string|null $winner   Bei Forfait: home oder away.
	 */
	public function result_decided( FixtureContext $ctx, int $event_id, string $action, ?string $comment, ?string $winner ): Notification {
		$title    = self::title( $ctx );
		$score    = self::score( $ctx );
		$describe = self::describe( $ctx );
		switch ( $action ) {
			case 'confirm':
				/* translators: %s: Begegnung */
				$subject = sprintf( __( 'Resultat bestätigt: %s', 'ttleague' ), $title );
				/* translators: 1: Resultat, 2: Begegnung mit Wettbewerb und Termin */
				$first = sprintf( __( 'Die TK hat das Resultat %1$s der Begegnung %2$s bestätigt.', 'ttleague' ), $score, $describe );
				break;
			case 'finalize':
				/* translators: %s: Begegnung */
				$subject = sprintf( __( 'Resultat abgeschlossen: %s', 'ttleague' ), $title );
				/* translators: 1: Resultat, 2: Begegnung mit Wettbewerb und Termin */
				$first = sprintf( __( 'Die TK hat das Resultat %1$s der Begegnung %2$s abgeschlossen. Es ist jetzt definitiv.', 'ttleague' ), $score, $describe );
				break;
			case 'correct':
				/* translators: %s: Begegnung */
				$subject = sprintf( __( 'Resultat korrigiert: %s', 'ttleague' ), $title );
				/* translators: 1: Begegnung mit Wettbewerb und Termin, 2: Resultat */
				$first = sprintf( __( 'Die TK hat das Resultat der Begegnung %1$s korrigiert. Neues Resultat: %2$s.', 'ttleague' ), $describe, $score );
				break;
			case 'forfeit':
				$team = 'away' === $winner ? $ctx->away['name'] : $ctx->home['name'];
				/* translators: %s: Begegnung */
				$subject = sprintf( __( 'Forfait: %s', 'ttleague' ), $title );
				/* translators: 1: Begegnung mit Wettbewerb und Termin, 2: Resultat, 3: siegreiches Team */
				$first = sprintf( __( 'Die TK hat die Begegnung %1$s forfait gewertet: %2$s für %3$s.', 'ttleague' ), $describe, $score, $team );
				break;
			default:
				/* translators: %s: Begegnung */
				$subject = sprintf( __( 'Resultat zurückgesetzt: %s', 'ttleague' ), $title );
				/* translators: %s: Begegnung mit Wettbewerb und Termin */
				$first = sprintf( __( 'Die TK hat das Resultat der Begegnung %s zurückgesetzt. Die Begegnung ist wieder offen, die Heimmannschaft erfasst das Resultat neu.', 'ttleague' ), $describe );
				break;
		}
		$paragraphs = array( $first );
		if ( null !== $comment && '' !== trim( $comment ) ) {
			/* translators: %s: Bemerkung */
			$paragraphs[] = sprintf( __( 'Bemerkung der TK: «%s»', 'ttleague' ), trim( $comment ) );
		}
		return $this->build( $ctx, EventTypes::RESULT_DECIDED, self::RESULT_DECIDED, 'fixture_event', $event_id, $action, $subject, $paragraphs, __( 'Begegnung öffnen', 'ttleague' ), $subject, $first );
	}

	/**
	 * Tägliche Übersicht der offenen Punkte für die TK.
	 *
	 * @param string                                   $date_local Tag (Y-m-d, Ortszeit).
	 * @param array<string,list<FixtureContext>>       $open       Abschnitt (missing, forfeit, overdue, disputed) => Begegnungen.
	 */
	public function tk_digest( string $date_local, array $open ): Notification {
		$total = 0;
		$lists = array();
		foreach ( self::digest_sections( $open ) as $key => $heading ) {
			$items = array();
			foreach ( $open[ $key ] ?? array() as $ctx ) {
				$parts = array( self::when( $ctx ), self::title( $ctx ) . ' (' . $ctx->competition . ')' );
				if ( in_array( $key, array( 'overdue', 'disputed' ), true ) ) {
					$parts[] = self::score( $ctx );
				}
				$items[] = array(
					'text' => implode( ' · ', array_filter( $parts, static fn( string $p ): bool => '' !== $p ) ),
					'url'  => $this->links->entry( $ctx->id ),
				);
			}
			if ( array() !== $items ) {
				$total  += count( $items );
				$lists[] = array(
					'heading' => $heading . ' (' . count( $items ) . ')',
					'items'   => $items,
				);
			}
		}
		/* translators: 1: Anzahl, 2: Datum */
		$subject = sprintf( _n( 'TK: %1$d offener Punkt am %2$s', 'TK: %1$d offene Punkte am %2$s', $total, 'ttleague' ), $total, Format::date( $date_local ) );
		$day     = (int) str_replace( '-', '', substr( $date_local, 0, 10 ) );
		$summary = array();
		foreach ( $lists as $list ) {
			$summary[] = $list['heading'];
		}
		return new Notification(
			EventTypes::TK_DIGEST,
			self::TK_DIGEST,
			'day',
			$day,
			'',
			$subject,
			array( __( 'Übersicht der offenen Punkte im Spielbetrieb. Jede Zeile führt auf die Erfassungsseite der Begegnung.', 'ttleague' ) ),
			$lists,
			null,
			$this->links->my_area(),
			array(
				'title' => $subject,
				'body'  => self::shorten( implode( ', ', $summary ) ),
				'url'   => $this->links->my_area(),
				'tag'   => 'ttleague-tk-digest',
			),
			43200,
			'low'
		);
	}

	/**
	 * Abschnitte der Übersicht in ihrer Reihenfolge.
	 *
	 * @param array<string,list<FixtureContext>> $open Offene Punkte.
	 * @return array<string,string>
	 */
	private static function digest_sections( array $open ): array {
		$rules = null;
		foreach ( $open as $list ) {
			foreach ( $list as $ctx ) {
				$rules = $ctx->rules;
				break 2;
			}
		}
		$hours = null === $rules ? 48 : $rules->entry_deadline_hours;
		$days  = null === $rules ? 16 : $rules->forfeit_after_days;
		$wait  = null === $rules ? 7 : $rules->objection_days;
		return array(
			/* translators: %d: Stunden */
			'missing'  => sprintf( __( 'Resultat fehlt seit mehr als %d Stunden', 'ttleague' ), $hours ),
			/* translators: %d: Tage */
			'forfeit'  => sprintf( __( 'Forfait-Vorschlag, %d Tage ohne Resultat', 'ttleague' ), $days ),
			/* translators: %d: Tage */
			'overdue'  => sprintf( __( 'Bestätigung durch die TK nötig, seit %d Tagen provisorisch', 'ttleague' ), $wait ),
			'disputed' => __( 'Einsprachen', 'ttleague' ),
		);
	}

	/**
	 * @param list<string> $paragraphs Absätze.
	 */
	private function build( FixtureContext $ctx, string $type, string $event, string $ref_type, int $ref_id, string $ref_key, string $subject, array $paragraphs, string $action_label, string $push_title, string $push_body, string $urgency = 'normal' ): Notification {
		$url = $this->links->entry( $ctx->id );
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
				'label' => $action_label,
			),
			$this->links->my_area(),
			array(
				'title' => self::shorten( $push_title, 80 ),
				'body'  => self::shorten( $push_body ),
				'url'   => $url,
				'tag'   => 'ttleague-fixture-' . $ctx->id,
			),
			86400,
			$urgency
		);
	}

	/**
	 * «Seefeld 1 – Altstetten 1».
	 */
	public static function title( FixtureContext $ctx ): string {
		return $ctx->home['name'] . ' – ' . $ctx->away['name'];
	}

	/**
	 * «Seefeld 1 – Altstetten 1 (Meisterschaft, Gruppe A, Di, 10.11.2026, 20:00)».
	 */
	public static function describe( FixtureContext $ctx ): string {
		$parts = array_filter( array( self::competition( $ctx ), self::when( $ctx ) ), static fn( string $p ): bool => '' !== $p );
		return self::title( $ctx ) . ( array() === $parts ? '' : ' (' . implode( ', ', $parts ) . ')' );
	}

	/**
	 * Wettbewerb: «Meisterschaft, Gruppe A» oder «Mannschaftscup A».
	 */
	public static function competition( FixtureContext $ctx ): string {
		if ( $ctx->is_cup() ) {
			return false === stripos( $ctx->competition, 'cup' )
				/* translators: %s: Wettbewerb */
				? sprintf( __( 'Cup, %s', 'ttleague' ), $ctx->competition )
				: $ctx->competition;
		}
		/* translators: %s: Gruppe */
		return sprintf( __( 'Meisterschaft, %s', 'ttleague' ), $ctx->competition );
	}

	/**
	 * «Di, 10.11.2026, 20:00»; ohne Anspielzeit nur das Datum.
	 */
	public static function when( FixtureContext $ctx ): string {
		$time = self::time( $ctx );
		$date = Format::date( $ctx->scheduled_at );
		return '' === $time || '' === $date ? $date : $date . ', ' . $time;
	}

	/**
	 * Anspielzeit «20:00», leer wenn offen (00:00).
	 */
	private static function time( FixtureContext $ctx ): string {
		$time = Format::time( $ctx->scheduled_at );
		return '00:00' === $time ? '' : $time;
	}

	private static function score( FixtureContext $ctx ): string {
		return Format::score( $ctx->home_score, $ctx->away_score );
	}

	/**
	 * Zusatz für die Dedupe: Termin der Begegnung (nach einer Verschiebung gilt die Frist neu).
	 */
	public static function schedule_key( FixtureContext $ctx ): string {
		return null === $ctx->scheduled_at ? '' : substr( $ctx->scheduled_at, 0, 16 );
	}

	private static function name_or( ?string $name, string $fallback ): string {
		return null === $name || '' === trim( $name ) ? $fallback : trim( $name );
	}

	private static function shorten( string $text, int $length = self::PUSH_BODY_LENGTH ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return mb_strlen( $text ) <= $length ? $text : rtrim( mb_substr( $text, 0, $length - 1 ) ) . '…';
	}
}
