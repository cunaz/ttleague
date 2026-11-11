<?php
/**
 * Texte der Benachrichtigungen zu Verschiebung und Terminvorschlag.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Postponement;

use TTLeague\Domain\Match\PostponementRules as Rules;
use TTLeague\Frontend\Support\Format;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Links;
use TTLeague\Notifications\Messages;
use TTLeague\Notifications\Notification;
use TTLeague\Results\FixtureContext;

/**
 * Jede Nachricht bezieht sich auf den Antrag (ref_type «postponement») und geht pro Person und
 * Art einmal hinaus. Der Link führt auf die Erfassungsseite der Begegnung, wo der Antrag
 * zuoberst steht. Termine sind Ortszeit der Website.
 *
 * Arten (Protokoll) und ihre Ereignistypen (Einstellungen): postponement_request,
 * postponement_reminder, postponement_withdrawn, postponement_declined, postponement_accepted
 * und postponement_decided (postponement); postponement_tk_info und postponement_tk_approval
 * (postponement_tk).
 */
final class PostponementMessages {

	public const REQUEST     = 'postponement_request';
	public const REMINDER    = 'postponement_reminder';
	public const WITHDRAWN   = 'postponement_withdrawn';
	public const DECLINED    = 'postponement_declined';
	public const ACCEPTED    = 'postponement_accepted';
	public const DECIDED     = 'postponement_decided';
	public const TK_INFO     = 'postponement_tk_info';
	public const TK_APPROVAL = 'postponement_tk_approval';

	/** Bezug der Nachrichten im Versandprotokoll. */
	public const REF_TYPE = 'postponement';

	public function __construct( private readonly Links $links ) {}

	/**
	 * Antrag an die Gegenseite.
	 *
	 * @param array<string,mixed> $row            Antrag.
	 * @param string|null         $by             Name der Person, die den Antrag gestellt hat.
	 * @param bool                $needs_approval Nach der Zustimmung muss die TK bewilligen.
	 */
	public function request( array $row, FixtureContext $ctx, ?string $by, bool $needs_approval ): Notification {
		$title = Messages::title( $ctx );
		$who   = self::name_or( $by, __( 'Die Gegenseite', 'ttleague' ) );
		if ( self::is_proposal( $row ) ) {
			/* translators: %s: Begegnung */
			$subject = sprintf( __( 'Terminvorschlag: %s', 'ttleague' ), $title );
			/* translators: 1: Name, 2: Begegnung mit Wettbewerb, 3: Datum und Zeit */
			$first = sprintf( __( '%1$s schlägt für die Begegnung %2$s diesen Termin vor: %3$s.', 'ttleague' ), $who, self::describe( $ctx ), self::when( $row['new_date'] ) );
		} else {
			/* translators: %s: Begegnung */
			$subject = sprintf( __( 'Verschiebung beantragt: %s', 'ttleague' ), $title );
			/* translators: 1: Name, 2: Begegnung mit Wettbewerb, 3: bisheriger Termin, 4: neuer Termin */
			$first = sprintf( __( '%1$s möchte die Begegnung %2$s verschieben: von %3$s auf %4$s.', 'ttleague' ), $who, self::describe( $ctx ), self::when( $row['old_date'] ), self::when( $row['new_date'] ) );
		}
		$paragraphs   = self::with_reason( array( $first ), $row );
		$paragraphs[] = $needs_approval
			? __( 'Bitte stimme zu oder lehne ab. Nach deiner Zustimmung entscheidet die TK über die Bewilligung.', 'ttleague' )
			: __( 'Bitte stimme zu oder lehne ab. Mit deiner Zustimmung gilt der neue Termin.', 'ttleague' );
		/* translators: %s: Datum und Zeit */
		$push = sprintf( __( 'Neuer Termin: %s. Bitte zustimmen oder ablehnen.', 'ttleague' ), self::when( $row['new_date'] ) );
		return $this->build( $row, $ctx, EventTypes::POSTPONEMENT, self::REQUEST, $subject, $paragraphs, __( 'Antrag beantworten', 'ttleague' ), $push, 'high' );
	}

	/**
	 * Erinnerung an die Gegenseite: Der Antrag ist seit $days Tagen unbeantwortet.
	 *
	 * @param array<string,mixed> $row  Antrag.
	 * @param int                 $days Tage seit dem Antrag.
	 * @param int                 $tk   Nach so vielen Tagen erscheint der Antrag bei der TK.
	 */
	public function reminder( array $row, FixtureContext $ctx, int $days, int $tk ): Notification {
		$title = Messages::title( $ctx );
		if ( self::is_proposal( $row ) ) {
			/* translators: %s: Begegnung */
			$subject = sprintf( __( 'Erinnerung: Terminvorschlag beantworten, %s', 'ttleague' ), $title );
			/* translators: 1: Datum und Zeit, 2: Begegnung mit Wettbewerb, 3: Tage */
			$first = sprintf( _n( 'Der Terminvorschlag %1$s für die Begegnung %2$s wartet seit %3$d Tag auf eure Antwort.', 'Der Terminvorschlag %1$s für die Begegnung %2$s wartet seit %3$d Tagen auf eure Antwort.', $days, 'ttleague' ), self::when( $row['new_date'] ), self::describe( $ctx ), $days );
		} else {
			/* translators: %s: Begegnung */
			$subject = sprintf( __( 'Erinnerung: Verschiebung beantworten, %s', 'ttleague' ), $title );
			/* translators: 1: Begegnung mit Wettbewerb, 2: bisheriger Termin, 3: neuer Termin, 4: Tage */
			$first = sprintf( _n( 'Der Antrag, die Begegnung %1$s von %2$s auf %3$s zu verschieben, wartet seit %4$d Tag auf eure Antwort.', 'Der Antrag, die Begegnung %1$s von %2$s auf %3$s zu verschieben, wartet seit %4$d Tagen auf eure Antwort.', $days, 'ttleague' ), self::describe( $ctx ), self::when( $row['old_date'] ), self::when( $row['new_date'] ), $days );
		}
		$paragraphs = array(
			$first,
			/* translators: %d: Tage */
			sprintf( _n( 'Bitte stimme zu oder lehne ab. Nach %d Tag ohne Antwort kann die TK an eurer Stelle entscheiden.', 'Bitte stimme zu oder lehne ab. Nach %d Tagen ohne Antwort kann die TK an eurer Stelle entscheiden.', $tk, 'ttleague' ), $tk ),
		);
		/* translators: 1: Begegnung, 2: Datum und Zeit */
		$push = sprintf( __( '%1$s: Der Antrag für den %2$s wartet auf eure Antwort.', 'ttleague' ), $title, self::when( $row['new_date'] ) );
		return $this->build( $row, $ctx, EventTypes::POSTPONEMENT, self::REMINDER, $subject, $paragraphs, __( 'Antrag beantworten', 'ttleague' ), $push );
	}

	/**
	 * Rückzug: an die Gegenseite, die den Antrag erhalten hat.
	 *
	 * @param array<string,mixed> $row Antrag.
	 * @param string|null         $by  Name der Person, die zurückgezogen hat.
	 */
	public function withdrawn( array $row, FixtureContext $ctx, ?string $by ): Notification {
		$title = Messages::title( $ctx );
		$who   = self::name_or( $by, __( 'Die Gegenseite', 'ttleague' ) );
		/* translators: %s: Begegnung */
		$subject = sprintf( __( 'Antrag zurückgezogen: %s', 'ttleague' ), $title );
		if ( self::is_proposal( $row ) ) {
			/* translators: 1: Name, 2: Datum und Zeit, 3: Begegnung mit Wettbewerb */
			$first = sprintf( __( '%1$s hat den Terminvorschlag %2$s für die Begegnung %3$s zurückgezogen. Es ist noch kein Termin vereinbart.', 'ttleague' ), $who, self::when( $row['new_date'] ), self::describe( $ctx ) );
		} else {
			/* translators: 1: Name, 2: Begegnung mit Wettbewerb, 3: neuer Termin, 4: bisheriger Termin */
			$first = sprintf( __( '%1$s hat den Antrag zurückgezogen, die Begegnung %2$s auf %3$s zu verschieben. Es bleibt beim Termin %4$s.', 'ttleague' ), $who, self::describe( $ctx ), self::when( $row['new_date'] ), self::when( $row['old_date'] ) );
		}
		return $this->build( $row, $ctx, EventTypes::POSTPONEMENT, self::WITHDRAWN, $subject, array( $first ), __( 'Begegnung öffnen', 'ttleague' ), __( 'Der Antrag ist zurückgezogen.', 'ttleague' ) );
	}

	/**
	 * Ablehnung der Gegenseite: an den Antragsteller.
	 *
	 * @param array<string,mixed> $row Antrag.
	 * @param string|null         $by  Name der Person, die abgelehnt hat.
	 */
	public function declined( array $row, FixtureContext $ctx, ?string $by ): Notification {
		$title = Messages::title( $ctx );
		$who   = self::name_or( $by, __( 'Die Gegenseite', 'ttleague' ) );
		if ( self::is_proposal( $row ) ) {
			/* translators: %s: Begegnung */
			$subject = sprintf( __( 'Terminvorschlag abgelehnt: %s', 'ttleague' ), $title );
			/* translators: 1: Name, 2: Datum und Zeit, 3: Begegnung mit Wettbewerb */
			$first = sprintf( __( '%1$s hat den Terminvorschlag %2$s für die Begegnung %3$s abgelehnt. Bitte schlage einen anderen Termin vor.', 'ttleague' ), $who, self::when( $row['new_date'] ), self::describe( $ctx ) );
		} else {
			/* translators: %s: Begegnung */
			$subject = sprintf( __( 'Verschiebung abgelehnt: %s', 'ttleague' ), $title );
			/* translators: 1: Name, 2: Begegnung mit Wettbewerb, 3: neuer Termin, 4: bisheriger Termin */
			$first = sprintf( __( '%1$s hat den Antrag abgelehnt, die Begegnung %2$s auf %3$s zu verschieben. Es bleibt beim Termin %4$s.', 'ttleague' ), $who, self::describe( $ctx ), self::when( $row['new_date'] ), self::when( $row['old_date'] ) );
		}
		$paragraphs = self::with_note( array( $first ), $row );
		return $this->build( $row, $ctx, EventTypes::POSTPONEMENT, self::DECLINED, $subject, $paragraphs, __( 'Begegnung öffnen', 'ttleague' ), __( 'Die Gegenseite hat den Antrag abgelehnt.', 'ttleague' ) );
	}

	/**
	 * Zustimmung der Gegenseite: an den Antragsteller. Je nach Status gilt der Termin oder die
	 * TK muss noch bewilligen.
	 *
	 * @param array<string,mixed> $row Antrag (approved oder accepted).
	 * @param string|null         $by  Name der Person, die zugestimmt hat.
	 */
	public function accepted( array $row, FixtureContext $ctx, ?string $by ): Notification {
		$title = Messages::title( $ctx );
		$who   = self::name_or( $by, __( 'Die Gegenseite', 'ttleague' ) );
		if ( Rules::ACCEPTED === $row['status'] ) {
			/* translators: %s: Begegnung */
			$subject = sprintf( __( 'Zustimmung, Bewilligung der TK offen: %s', 'ttleague' ), $title );
			/* translators: 1: Name, 2: Begegnung mit Wettbewerb, 3: neuer Termin */
			$first = sprintf( __( '%1$s ist einverstanden, die Begegnung %2$s am %3$s zu spielen.', 'ttleague' ), $who, self::describe( $ctx ), self::when( $row['new_date'] ) );
			$next  = null === $row['old_date']
				? __( 'Die TK muss den Termin noch bewilligen.', 'ttleague' )
				/* translators: %s: bisheriger Termin */
				: sprintf( __( 'Die TK muss die Verschiebung noch bewilligen. Bis dahin gilt der bisherige Termin %s.', 'ttleague' ), self::when( $row['old_date'] ) );
			$push = __( 'Die Gegenseite hat zugestimmt. Die TK muss noch bewilligen.', 'ttleague' );
			return $this->build( $row, $ctx, EventTypes::POSTPONEMENT, self::ACCEPTED, $subject, array( $first, $next ), __( 'Begegnung öffnen', 'ttleague' ), $push );
		}
		/* translators: %s: Begegnung */
		$subject = self::is_proposal( $row ) ? sprintf( __( 'Termin vereinbart: %s', 'ttleague' ), $title ) : sprintf( __( 'Verschiebung vereinbart: %s', 'ttleague' ), $title );
		/* translators: 1: Name, 2: Begegnung mit Wettbewerb, 3: neuer Termin */
		$first = sprintf( __( '%1$s hat zugestimmt. Die Begegnung %2$s findet am %3$s statt.', 'ttleague' ), $who, self::describe( $ctx ), self::when( $row['new_date'] ) );
		/* translators: %s: Datum und Zeit */
		$push = sprintf( __( 'Die Gegenseite hat zugestimmt. Neuer Termin: %s.', 'ttleague' ), self::when( $row['new_date'] ) );
		return $this->build( $row, $ctx, EventTypes::POSTPONEMENT, self::ACCEPTED, $subject, self::with_old( array( $first ), $row ), __( 'Begegnung öffnen', 'ttleague' ), $push );
	}

	/**
	 * Entscheid der TK: an beide Seiten. Auch wenn die TK selbst verschoben hat.
	 *
	 * @param array<string,mixed> $row    Antrag (approved oder rejected).
	 * @param bool                $own    Die TK hat den Antrag selbst gestellt.
	 */
	public function decided( array $row, FixtureContext $ctx, bool $own ): Notification {
		$title    = Messages::title( $ctx );
		$describe = self::describe( $ctx );
		$new      = self::when( $row['new_date'] );
		$proposal = self::is_proposal( $row );
		if ( Rules::REJECTED === $row['status'] ) {
			/* translators: %s: Begegnung */
			$subject = $proposal ? sprintf( __( 'Termin von der TK abgelehnt: %s', 'ttleague' ), $title ) : sprintf( __( 'Verschiebung von der TK abgelehnt: %s', 'ttleague' ), $title );
			$first   = $proposal
				/* translators: 1: Datum und Zeit, 2: Begegnung mit Wettbewerb */
				? sprintf( __( 'Die TK hat den Termin %1$s für die Begegnung %2$s abgelehnt. Bitte vereinbart einen anderen Termin.', 'ttleague' ), $new, $describe )
				/* translators: 1: Begegnung mit Wettbewerb, 2: neuer Termin, 3: bisheriger Termin */
				: sprintf( __( 'Die TK hat den Antrag abgelehnt, die Begegnung %1$s auf %2$s zu verschieben. Es bleibt beim Termin %3$s.', 'ttleague' ), $describe, $new, self::when( $row['old_date'] ) );
			$paragraphs = self::with_tk_note( array( $first ), $row );
			return $this->build( $row, $ctx, EventTypes::POSTPONEMENT, self::DECIDED, $subject, $paragraphs, __( 'Begegnung öffnen', 'ttleague' ), __( 'Die TK hat den Antrag abgelehnt.', 'ttleague' ) );
		}
		if ( $own ) {
			/* translators: %s: Begegnung */
			$subject = $proposal ? sprintf( __( 'Termin festgelegt: %s', 'ttleague' ), $title ) : sprintf( __( 'Verschoben durch die TK: %s', 'ttleague' ), $title );
			$first   = $proposal
				/* translators: 1: Begegnung mit Wettbewerb, 2: Datum und Zeit */
				? sprintf( __( 'Die TK hat den Termin der Begegnung %1$s festgelegt: %2$s.', 'ttleague' ), $describe, $new )
				/* translators: 1: Begegnung mit Wettbewerb, 2: neuer Termin */
				: sprintf( __( 'Die TK hat die Begegnung %1$s verschoben. Neuer Termin: %2$s.', 'ttleague' ), $describe, $new );
			$paragraphs = self::with_reason( self::with_old( array( $first ), $row ), $row );
		} else {
			/* translators: %s: Begegnung */
			$subject = $proposal ? sprintf( __( 'Termin bewilligt: %s', 'ttleague' ), $title ) : sprintf( __( 'Verschiebung bewilligt: %s', 'ttleague' ), $title );
			$first   = $proposal
				/* translators: 1: Begegnung mit Wettbewerb, 2: Datum und Zeit */
				? sprintf( __( 'Die TK hat den Termin der Begegnung %1$s bewilligt: %2$s.', 'ttleague' ), $describe, $new )
				/* translators: 1: Begegnung mit Wettbewerb, 2: neuer Termin */
				: sprintf( __( 'Die TK hat die Verschiebung der Begegnung %1$s bewilligt. Neuer Termin: %2$s.', 'ttleague' ), $describe, $new );
			$paragraphs = self::with_tk_note( self::with_old( array( $first ), $row ), $row );
		}
		/* translators: %s: Datum und Zeit */
		$push = sprintf( __( 'Neuer Termin: %s.', 'ttleague' ), $new );
		return $this->build( $row, $ctx, EventTypes::POSTPONEMENT, self::DECIDED, $subject, $paragraphs, __( 'Begegnung öffnen', 'ttleague' ), $push );
	}

	/**
	 * An die TK: zur Kenntnis (die Seiten haben sich geeinigt) oder zur Bewilligung.
	 *
	 * @param array<string,mixed> $row      Antrag (approved oder accepted).
	 * @param string|null         $deadline Frist der Cup-Runde (Datum) oder null.
	 */
	public function tk( array $row, FixtureContext $ctx, ?string $deadline ): Notification {
		$title    = Messages::title( $ctx );
		$describe = self::describe( $ctx );
		$new      = self::when( $row['new_date'] );
		$proposal = self::is_proposal( $row );
		if ( Rules::ACCEPTED !== $row['status'] ) {
			/* translators: %s: Begegnung */
			$subject = $proposal ? sprintf( __( 'Zur Kenntnis: Termin vereinbart, %s', 'ttleague' ), $title ) : sprintf( __( 'Zur Kenntnis: Verschiebung, %s', 'ttleague' ), $title );
			$first   = $proposal
				/* translators: 1: Begegnung mit Wettbewerb, 2: Datum und Zeit */
				? sprintf( __( 'Die Mannschaften haben den Termin der Begegnung %1$s vereinbart: %2$s.', 'ttleague' ), $describe, $new )
				/* translators: 1: Begegnung mit Wettbewerb, 2: bisheriger Termin, 3: neuer Termin */
				: sprintf( __( 'Die Mannschaften haben die Begegnung %1$s einvernehmlich verschoben: von %2$s auf %3$s.', 'ttleague' ), $describe, self::when( $row['old_date'] ), $new );
			$paragraphs   = self::with_reason( array( $first ), $row );
			$paragraphs[] = __( 'Eine Bewilligung ist nicht nötig.', 'ttleague' );
			/* translators: %s: Datum und Zeit */
			$push = sprintf( __( 'Neuer Termin: %s. Keine Bewilligung nötig.', 'ttleague' ), $new );
			return $this->build( $row, $ctx, EventTypes::POSTPONEMENT_TK, self::TK_INFO, $subject, $paragraphs, __( 'Begegnung öffnen', 'ttleague' ), $push, 'low' );
		}
		/* translators: %s: Begegnung */
		$subject = $proposal ? sprintf( __( 'Termin zur Bewilligung: %s', 'ttleague' ), $title ) : sprintf( __( 'Verschiebung zur Bewilligung: %s', 'ttleague' ), $title );
		$first   = $proposal
			/* translators: 1: Begegnung mit Wettbewerb, 2: Datum und Zeit */
			? sprintf( __( 'Die Mannschaften möchten die Begegnung %1$s am %2$s spielen. Beide Seiten sind einverstanden.', 'ttleague' ), $describe, $new )
			/* translators: 1: Begegnung mit Wettbewerb, 2: bisheriger Termin, 3: neuer Termin */
			: sprintf( __( 'Die Mannschaften möchten die Begegnung %1$s von %2$s auf %3$s verschieben. Beide Seiten sind einverstanden.', 'ttleague' ), $describe, self::when( $row['old_date'] ), $new );
		$paragraphs = self::with_reason( array( $first ), $row );
		$days       = $ctx->rules->postponement_approval_days;
		if ( null !== $deadline && substr( (string) $row['new_date'], 0, 10 ) > $deadline ) {
			/* translators: %s: Datum */
			$paragraphs[] = sprintf( __( 'Der Termin liegt nach der Frist der Runde (%s). Deshalb entscheidet die TK.', 'ttleague' ), Format::date_plain( $deadline ) );
		} else {
			/* translators: %d: Tage */
			$paragraphs[] = sprintf( _n( 'Der neue Termin liegt mehr als %d Tag nach dem bisherigen. Deshalb entscheidet die TK.', 'Der neue Termin liegt mehr als %d Tage nach dem bisherigen. Deshalb entscheidet die TK.', $days, 'ttleague' ), $days );
		}
		/* translators: %s: Datum und Zeit */
		$push = sprintf( __( 'Neuer Termin: %s. Bitte bewilligen oder ablehnen.', 'ttleague' ), $new );
		return $this->build( $row, $ctx, EventTypes::POSTPONEMENT_TK, self::TK_APPROVAL, $subject, $paragraphs, __( 'Entscheiden', 'ttleague' ), $push );
	}

	/**
	 * @param array<string,mixed> $row        Antrag (Bezug).
	 * @param list<string>        $paragraphs Absätze.
	 */
	private function build( array $row, FixtureContext $ctx, string $type, string $event, string $subject, array $paragraphs, string $action_label, string $push_body, string $urgency = 'normal' ): Notification {
		$url = $this->links->entry( $ctx->id );
		return new Notification(
			$type,
			$event,
			self::REF_TYPE,
			(int) $row['id'],
			'',
			$subject,
			$paragraphs,
			array(),
			array(
				'url'   => $url,
				'label' => $action_label,
			),
			$this->links->my_area(),
			array(
				'title' => self::shorten( $subject, 80 ),
				'body'  => self::shorten( $push_body, 180 ),
				'url'   => $url,
				'tag'   => 'ttleague-fixture-' . $ctx->id,
			),
			86400,
			$urgency
		);
	}

	/**
	 * «Seefeld 1 – Wollishofen 1 (Meisterschaft, Gruppe B)»: ohne Termin, weil er sich gerade ändert.
	 */
	private static function describe( FixtureContext $ctx ): string {
		return Messages::title( $ctx ) . ' (' . Messages::competition( $ctx ) . ')';
	}

	/**
	 * «Di, 24.11.2026, 20:00».
	 */
	private static function when( ?string $local ): string {
		return Format::datetime( $local );
	}

	/**
	 * @param array<string,mixed> $row Antrag.
	 */
	private static function is_proposal( array $row ): bool {
		return Rules::PROPOSE === $row['kind'] || null === $row['old_date'];
	}

	/**
	 * Hängt den bisherigen Termin an, falls es einen gab.
	 *
	 * @param list<string>        $paragraphs Absätze.
	 * @param array<string,mixed> $row        Antrag.
	 * @return list<string>
	 */
	private static function with_old( array $paragraphs, array $row ): array {
		if ( null !== $row['old_date'] && '' !== $row['old_date'] ) {
			/* translators: %s: Datum und Zeit */
			$paragraphs[] = sprintf( __( 'Bisheriger Termin: %s.', 'ttleague' ), self::when( $row['old_date'] ) );
		}
		return $paragraphs;
	}

	/**
	 * Hängt die Begründung des Antrags an, falls vorhanden.
	 *
	 * @param list<string>        $paragraphs Absätze.
	 * @param array<string,mixed> $row        Antrag.
	 * @return list<string>
	 */
	private static function with_reason( array $paragraphs, array $row ): array {
		$text = trim( (string) $row['reason'] );
		if ( '' !== $text ) {
			/* translators: %s: Begründung */
			$paragraphs[] = sprintf( __( 'Begründung: «%s»', 'ttleague' ), $text );
		}
		return $paragraphs;
	}

	/**
	 * Hängt die Bemerkung der Gegenseite an, falls vorhanden.
	 *
	 * @param list<string>        $paragraphs Absätze.
	 * @param array<string,mixed> $row        Antrag.
	 * @return list<string>
	 */
	private static function with_note( array $paragraphs, array $row ): array {
		$text = trim( (string) $row['response_note'] );
		if ( '' !== $text ) {
			/* translators: %s: Bemerkung */
			$paragraphs[] = sprintf( __( 'Bemerkung: «%s»', 'ttleague' ), $text );
		}
		return $paragraphs;
	}

	/**
	 * Hängt die Bemerkung der TK zu ihrem Entscheid an, falls vorhanden.
	 *
	 * @param list<string>        $paragraphs Absätze.
	 * @param array<string,mixed> $row        Antrag.
	 * @return list<string>
	 */
	private static function with_tk_note( array $paragraphs, array $row ): array {
		$text = trim( (string) $row['response_note'] );
		if ( '' !== $text ) {
			/* translators: %s: Bemerkung */
			$paragraphs[] = sprintf( __( 'Bemerkung der TK: «%s»', 'ttleague' ), $text );
		}
		return $paragraphs;
	}

	private static function name_or( ?string $name, string $fallback ): string {
		return null === $name || '' === trim( $name ) ? $fallback : trim( $name );
	}

	private static function shorten( string $text, int $length ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return mb_strlen( $text ) <= $length ? $text : rtrim( mb_substr( $text, 0, $length - 1 ) ) . '…';
	}
}
