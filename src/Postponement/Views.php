<?php
/**
 * HTML der Verschiebung: Abschnitt der Erfassungsseite, «Mein Bereich», Übersicht der TK.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Postponement;

use TTLeague\Domain\Match\PostponementRules as Rules;
use TTLeague\Frontend\Support\Format;

/**
 * Serverseitig gerendert, ohne JavaScript: Jede Aktion ist ein Formular an admin-post.php mit
 * Nonce (Forms). Die Daten kommen aus dem PostponementService (overview, mine, for_tk); was
 * eine Person tun kann, steht dort unter «can». Alle Texte werden hier escaped.
 */
final class Views {

	/** Anker des Abschnitts auf der Erfassungsseite. */
	public const ENTRY_ANCHOR = 'ttl-termin';

	/** Anker des Abschnitts in «Mein Bereich». */
	public const AREA_ANCHOR = 'ttl-termine';

	/** Anker der Liste in der Übersicht der TK. */
	public const TK_ANCHOR = 'ttl-verschiebungen';

	/**
	 * Abschnitt «Termin» der Erfassungsseite: der offene Antrag mit den Aktionen der Person,
	 * sonst das Formular «Termin verschieben», darunter frühere Anträge. Leer, wenn es nichts
	 * zu zeigen gibt.
	 *
	 * @param array<string,mixed>                                         $overview PostponementService::overview().
	 * @param array{type:string,message:string,details:list<string>}|null $flash    Rückmeldung der letzten Aktion.
	 * @param \DateTimeImmutable                                          $now      Jetzt in Ortszeit (frühester neuer Termin).
	 * @param bool                                                        $is_tk    Die Person handelt als TK.
	 */
	public static function entry_section( array $overview, ?array $flash, \DateTimeImmutable $now, bool $is_tk ): string {
		$open = $overview['open'];
		$can  = $overview['can'];
		$body = '';
		if ( null !== $open ) {
			$body .= '<div class="ttl-notice ttl-postponement__open">'
				. self::summary( $open, $overview['fixture'] )
				. self::state( $open, $can )
				. self::respond_forms( $open, $can, Forms::BACK_ENTRY )
				. self::decide_forms( $open, $can, Forms::BACK_ENTRY, false )
				. '</div>';
		} elseif ( $can['request'] ) {
			$body .= self::request_form( $overview, $now, $is_tk );
		}
		$body .= self::history( $overview['history'], $now->getTimezone() );
		if ( '' === $body && null === $flash ) {
			return '';
		}
		return '<section class="ttl-section ttl-postponement" id="' . esc_attr( self::ENTRY_ANCHOR ) . '" aria-labelledby="' . esc_attr( self::ENTRY_ANCHOR . '-titel' ) . '">'
			. '<h3 class="ttl-section__title" id="' . esc_attr( self::ENTRY_ANCHOR . '-titel' ) . '">' . esc_html__( 'Termin', 'ttleague' ) . '</h3>'
			. self::notice( $flash ) . $body . '</section>';
	}

	/**
	 * Abschnitt in «Mein Bereich»: offene Anträge (zuerst die zu beantwortenden) und
	 * Begegnungen ohne Termin. Leer ohne Einträge.
	 *
	 * @param array{requests:list<array<string,mixed>>,unscheduled:list<array<string,mixed>>} $mine      PostponementService::mine().
	 * @param array{type:string,message:string,details:list<string>}|null                     $flash     Rückmeldung.
	 * @param callable(int):string                                                            $entry_url Adresse der Erfassungsseite.
	 */
	public static function my_area_section( array $mine, ?array $flash, callable $entry_url ): string {
		if ( array() === $mine['requests'] && array() === $mine['unscheduled'] && null === $flash ) {
			return '';
		}
		$out = '<section class="ttl-section ttl-postponement" id="' . esc_attr( self::AREA_ANCHOR ) . '" aria-labelledby="' . esc_attr( self::AREA_ANCHOR . '-titel' ) . '">'
			. '<h3 id="' . esc_attr( self::AREA_ANCHOR . '-titel' ) . '">' . esc_html__( 'Verschiebungen und Termine', 'ttleague' ) . '</h3>'
			. self::notice( $flash );
		if ( array() !== $mine['requests'] ) {
			$out .= '<ul class="ttl-my-games__list ttl-postponement__list">';
			foreach ( $mine['requests'] as $item ) {
				$fixture = $item['fixture'];
				$pairing = $fixture['home'] . ' – ' . $fixture['away'];
				$out    .= '<li class="ttl-postponement__item">'
					. '<span class="ttl-my-games__meta">' . esc_html( Format::kind_short( (string) $fixture['type'] ) . ' ' . $fixture['competition'] ) . '</span>'
					. '<span class="ttl-my-games__pairing">' . esc_html( $pairing ) . '</span>'
					. self::summary( $item['postponement'], $fixture )
					. self::state( $item['postponement'], $item['can'] )
					. self::respond_forms( $item['postponement'], $item['can'], Forms::BACK_AREA )
					. '<a href="' . esc_url( $entry_url( (int) $fixture['id'] ) . '#' . self::ENTRY_ANCHOR ) . '">' . esc_html__( 'Zur Begegnung', 'ttleague' )
					. '<span class="screen-reader-text">: ' . esc_html( $pairing ) . '</span></a>'
					. '</li>';
			}
			$out .= '</ul>';
		}
		if ( array() !== $mine['unscheduled'] ) {
			$out .= '<h4 class="ttl-my-games__title">' . esc_html__( 'Ohne Termin', 'ttleague' ) . ' <span class="ttl-badge ttl-badge--warning">' . esc_html( (string) count( $mine['unscheduled'] ) ) . '</span></h4>'
				. '<ul class="ttl-my-games__list">';
			foreach ( $mine['unscheduled'] as $item ) {
				$fixture = $item['fixture'];
				$pairing = $fixture['home'] . ' – ' . $fixture['away'];
				$meta    = array( Format::kind_short( (string) $fixture['type'] ) . ' ' . $fixture['competition'] );
				if ( null !== $fixture['deadline'] ) {
					/* translators: %s: Datum */
					$meta[] = sprintf( __( 'zu spielen bis %s', 'ttleague' ), Format::date_plain( $fixture['deadline'] ) );
				}
				$label = $item['can']['request'] ? __( 'Termin vorschlagen', 'ttleague' ) : __( 'Ansehen', 'ttleague' );
				$out  .= '<li class="ttl-my-games__item">'
					. '<span class="ttl-my-games__meta">' . esc_html( implode( ' · ', $meta ) ) . '</span>'
					. '<span class="ttl-my-games__pairing">' . esc_html( $pairing ) . '</span>'
					. '<a class="ttl-button ttl-my-games__action' . ( $item['can']['request'] ? '' : ' ttl-button--secondary' ) . '" href="' . esc_url( $entry_url( (int) $fixture['id'] ) . '#' . self::ENTRY_ANCHOR ) . '">'
					. esc_html( $label ) . '<span class="screen-reader-text">: ' . esc_html( $pairing ) . '</span></a>'
					. '</li>';
			}
			$out .= '</ul>';
			if ( array() !== array_filter( $mine['unscheduled'], static fn( array $item ): bool => ! $item['can']['request'] ) ) {
				$out .= '<p class="ttl-muted">' . esc_html__( 'Den Termin schlägt die Heimmannschaft vor.', 'ttleague' ) . '</p>';
			}
		}
		return $out . '</section>';
	}

	/**
	 * Liste «Verschiebungen» der TK-Übersicht (Backend): zu bewilligen und unbeantwortet, mit
	 * Formularen zum Entscheiden.
	 *
	 * @param array{to_approve:list<array<string,mixed>>,unanswered:list<array<string,mixed>>} $lists     PostponementService::for_tk().
	 * @param callable(int):string                                                             $entry_url Adresse der Erfassungsseite.
	 * @param \DateTimeZone                                                                    $timezone  Zeitzone der Website.
	 * @param string                                                                           $csv_url   Adresse des CSV-Exports aller Anträge oder leer.
	 */
	public static function tk_section( array $lists, callable $entry_url, \DateTimeZone $timezone, string $csv_url = '' ): string {
		$out = '<p class="description">' . esc_html(
			sprintf(
				/* translators: %d: Tage */
				_n(
					'Verschiebungen und Termine, die die TK bewilligen muss, und Anträge, die die Gegenseite seit %d Tag nicht beantwortet hat. Über alle anderen entscheidest du bei Bedarf auf der Erfassungsseite der Begegnung.',
					'Verschiebungen und Termine, die die TK bewilligen muss, und Anträge, die die Gegenseite seit %d Tagen nicht beantwortet hat. Über alle anderen entscheidest du bei Bedarf auf der Erfassungsseite der Begegnung.',
					PostponementService::UNANSWERED_DAYS,
					'ttleague'
				),
				PostponementService::UNANSWERED_DAYS
			)
		) . ( '' === $csv_url ? '' : ' <a href="' . esc_url( $csv_url ) . '">' . esc_html__( 'Alle Anträge der Saison als CSV', 'ttleague' ) . '</a>' ) . '</p>';
		if ( array() === $lists['to_approve'] && array() === $lists['unanswered'] ) {
			return $out . '<p><em>' . esc_html__( 'Keine offenen Verschiebungen.', 'ttleague' ) . '</em></p>';
		}
		$out .= '<table class="widefat striped ttl-tk-table"><thead><tr>'
			. '<th scope="col" class="ttl-col-fixture column-primary">' . esc_html__( 'Begegnung', 'ttleague' ) . '</th>'
			. '<th scope="col" class="ttl-col-date">' . esc_html__( 'Bisher', 'ttleague' ) . '</th>'
			. '<th scope="col" class="ttl-col-date">' . esc_html__( 'Neu', 'ttleague' ) . '</th>'
			. '<th scope="col" class="ttl-col-status">' . esc_html__( 'Stand', 'ttleague' ) . '</th>'
			. '<th scope="col" class="ttl-col-details">' . esc_html__( 'Antrag', 'ttleague' ) . '</th>'
			. '<th scope="col" class="ttl-col-action">' . esc_html__( 'Aktion', 'ttleague' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( array( 'to_approve', 'unanswered' ) as $key ) {
			foreach ( $lists[ $key ] as $item ) {
				$fixture = $item['fixture'];
				$request = $item['postponement'];
				$by      = array_filter( array( $request['requested_by'], self::side_name( $request['requester_side'], $fixture ) ) );
				$since   = self::local( $request['requested_at'], $timezone );
				$details = '<span class="ttl-tk-muted">' . esc_html( implode( ', ', $by ) . ( '' === $since ? '' : ( array() === $by ? '' : ' · ' ) . $since ) ) . '</span>';
				if ( null !== $request['reason'] && '' !== $request['reason'] ) {
					$details = '<p class="ttl-tk-reason">«' . esc_html( (string) $request['reason'] ) . '»</p>' . $details;
				}
				$out .= '<tr>'
					. '<td class="column-primary"><a href="' . esc_url( $entry_url( (int) $fixture['id'] ) . '#' . self::ENTRY_ANCHOR ) . '"><strong>' . esc_html( $fixture['home'] . ' – ' . $fixture['away'] ) . '</strong></a>'
					. '<br><span class="ttl-tk-muted">' . esc_html( (string) $fixture['competition'] ) . '</span></td>'
					. '<td>' . esc_html( null === $request['old_date'] ? __( 'noch kein Termin', 'ttleague' ) : Format::datetime( $request['old_date'] ) ) . '</td>'
					. '<td><strong>' . esc_html( Format::datetime( $request['new_date'] ) ) . '</strong></td>'
					. '<td>' . esc_html( 'to_approve' === $key ? __( 'zu bewilligen', 'ttleague' ) : __( 'unbeantwortet', 'ttleague' ) ) . '</td>'
					. '<td>' . $details . '</td>'
					. '<td>' . self::decide_forms( $request, $item['can'], Forms::BACK_TK, true ) . '</td>'
					. '</tr>';
			}
		}
		return $out . '</tbody></table>';
	}

	/**
	 * Link «Termin verschieben» für eine Begegnung (Matchreport, «Meine Spiele»).
	 *
	 * @param string $url       Adresse der Erfassungsseite.
	 * @param string $label     Beschriftung.
	 * @param string $css_class Zusätzliche Klasse.
	 */
	public static function link( string $url, string $label, string $css_class = '' ): string {
		return '<a class="' . esc_attr( trim( 'ttl-postponement__link ' . $css_class ) ) . '" href="' . esc_url( $url . '#' . self::ENTRY_ANCHOR ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Was beantragt ist: neuer und bisheriger Termin, wer, Begründung.
	 *
	 * @param array<string,mixed> $request Antrag.
	 * @param array<string,mixed> $fixture Begegnung.
	 */
	private static function summary( array $request, array $fixture ): string {
		$new = '<strong>' . esc_html( Format::datetime( $request['new_date'] ) ) . '</strong>';
		if ( Rules::PROPOSE === $request['kind'] || null === $request['old_date'] ) {
			/* translators: %s: Datum und Zeit */
			$text = sprintf( esc_html__( 'Terminvorschlag: %s.', 'ttleague' ), $new );
		} else {
			/* translators: 1: neuer Termin, 2: bisheriger Termin */
			$text = sprintf( esc_html__( 'Antrag auf Verschiebung: neuer Termin %1$s (bisher %2$s).', 'ttleague' ), $new, esc_html( Format::datetime( $request['old_date'] ) ) );
		}
		$by = array_filter( array( $request['requested_by'], self::side_name( $request['requester_side'], $fixture ) ) );
		if ( array() !== $by ) {
			/* translators: %s: Name und Mannschaft */
			$text .= ' <span class="ttl-muted">' . esc_html( sprintf( __( 'Gestellt von %s.', 'ttleague' ), implode( ', ', $by ) ) ) . '</span>';
		}
		$out = '<p class="ttl-postponement__summary">' . $text . '</p>';
		if ( null !== $request['reason'] && '' !== $request['reason'] ) {
			/* translators: %s: Begründung */
			$out .= '<p class="ttl-postponement__reason">' . esc_html( sprintf( __( 'Begründung: «%s»', 'ttleague' ), $request['reason'] ) ) . '</p>';
		}
		return $out;
	}

	/**
	 * Wo steht der Antrag, und was ist der nächste Schritt für diese Person?
	 *
	 * @param array<string,mixed> $request Antrag.
	 * @param array<string,bool>  $can     Aktionen der Person.
	 */
	private static function state( array $request, array $can ): string {
		if ( Rules::ACCEPTED === $request['status'] ) {
			$text = $can['approve']
				? __( 'Die Gegenseite hat zugestimmt. Bitte bewillige den Termin oder lehne ihn ab.', 'ttleague' )
				: __( 'Die Gegenseite hat zugestimmt. Die TK muss noch bewilligen; bis dahin gilt der bisherige Termin.', 'ttleague' );
		} elseif ( $can['accept'] ) {
			$text = $request['needs_approval']
				? __( 'Bitte stimme zu oder lehne ab. Nach deiner Zustimmung entscheidet die TK über die Bewilligung.', 'ttleague' )
				: __( 'Bitte stimme zu oder lehne ab. Mit deiner Zustimmung gilt der neue Termin.', 'ttleague' );
		} elseif ( $can['decline'] ) {
			$text = __( 'Der Antrag lässt sich nicht mehr annehmen (Termin vorbei oder Begegnung geändert). Bitte lehne ihn ab.', 'ttleague' );
		} else {
			$text = $request['needs_approval']
				? __( 'Wartet auf die Antwort der Gegenseite. Danach entscheidet die TK über die Bewilligung.', 'ttleague' )
				: __( 'Wartet auf die Antwort der Gegenseite.', 'ttleague' );
		}
		return '<p class="ttl-postponement__state">' . esc_html( $text ) . '</p>';
	}

	/**
	 * «Zustimmen», «Ablehnen» (mit freiwilliger Bemerkung) und «Zurückziehen», soweit erlaubt.
	 *
	 * @param array<string,mixed> $request Antrag.
	 * @param array<string,bool>  $can     Aktionen der Person.
	 */
	private static function respond_forms( array $request, array $can, string $back ): string {
		$id  = (int) $request['id'];
		$out = '';
		if ( $can['accept'] ) {
			$out .= self::form(
				Forms::RESPOND,
				$id,
				$back,
				'<input type="hidden" name="response" value="accept">'
				. '<button type="submit" class="ttl-button ttl-button--primary">' . esc_html__( 'Zustimmen', 'ttleague' ) . '</button>',
				'ttl-postponement__form'
			);
		}
		if ( $can['decline'] ) {
			$field = 'ttl-postponement-note-' . $back . '-' . $id;
			$out  .= '<details class="ttl-details ttl-postponement__decline"><summary>' . esc_html__( 'Ablehnen', 'ttleague' ) . '</summary>'
				. self::form(
					Forms::RESPOND,
					$id,
					$back,
					'<input type="hidden" name="response" value="decline">'
					. '<p class="ttl-field"><label for="' . esc_attr( $field ) . '">' . esc_html__( 'Bemerkung (freiwillig)', 'ttleague' ) . '</label>'
					. '<textarea id="' . esc_attr( $field ) . '" name="note" rows="2" maxlength="' . esc_attr( (string) PostponementService::TEXT_MAX ) . '"></textarea></p>'
					. '<button type="submit" class="ttl-button ttl-button--secondary">' . esc_html__( 'Antrag ablehnen', 'ttleague' ) . '</button>',
					'ttl-form ttl-postponement__form'
				)
				. '</details>';
		}
		if ( $can['withdraw'] ) {
			$out .= self::form(
				Forms::RESPOND,
				$id,
				$back,
				'<input type="hidden" name="response" value="withdraw">'
				. '<button type="submit" class="ttl-button ttl-button--secondary">' . esc_html__( 'Antrag zurückziehen', 'ttleague' ) . '</button>',
				'ttl-postponement__form'
			);
		}
		return $out;
	}

	/**
	 * Entscheid der TK: bewilligen (Bemerkung freiwillig) oder ablehnen (Bemerkung Pflicht).
	 *
	 * @param array<string,mixed> $request Antrag.
	 * @param array<string,bool>  $can     Aktionen der Person.
	 * @param bool                $backend Klassen des WordPress-Backends statt der Website.
	 */
	private static function decide_forms( array $request, array $can, string $back, bool $backend ): string {
		$id      = (int) $request['id'];
		$out     = '';
		$form    = $backend ? 'ttl-tk-form' : 'ttl-form ttl-postponement__form';
		$primary = $backend ? 'button button-primary button-small' : 'ttl-button ttl-button--primary';
		$second  = $backend ? 'button button-small' : 'ttl-button ttl-button--secondary';
		$summary = $backend ? ' class="button button-small"' : '';
		$details = $backend ? '' : ' class="ttl-details"';
		$field   = static fn( string $html ): string => $backend ? $html : '<p class="ttl-field">' . $html . '</p>';
		$max     = ' maxlength="' . esc_attr( (string) PostponementService::TEXT_MAX ) . '"';
		if ( $can['approve'] ) {
			$waiting = Rules::REQUESTED === $request['status'];
			$label   = $waiting ? __( 'An Stelle der Gegenseite gutheissen …', 'ttleague' ) : __( 'Bewilligen …', 'ttleague' );
			$comment = 'ttl-postponement-approve-' . $back . '-' . $id;
			$out    .= '<details' . $details . '><summary' . $summary . '>' . esc_html( $label ) . '</summary>'
				. self::form(
					Forms::DECIDE,
					$id,
					$back,
					'<input type="hidden" name="decision" value="approve">'
					. $field(
						'<label for="' . esc_attr( $comment ) . '">' . esc_html__( 'Bemerkung (freiwillig)', 'ttleague' ) . '</label>'
						. '<textarea id="' . esc_attr( $comment ) . '" name="comment" rows="2"' . $max . '></textarea>'
					)
					. '<button type="submit" class="' . esc_attr( $primary ) . '">' . esc_html( $waiting ? __( 'Termin gutheissen', 'ttleague' ) : __( 'Termin bewilligen', 'ttleague' ) ) . '</button>',
					$form
				)
				. '</details>';
		}
		if ( $can['reject'] ) {
			$comment = 'ttl-postponement-reject-' . $back . '-' . $id;
			$out    .= '<details' . $details . '><summary' . $summary . '>' . esc_html__( 'Ablehnen …', 'ttleague' ) . '</summary>'
				. self::form(
					Forms::DECIDE,
					$id,
					$back,
					'<input type="hidden" name="decision" value="reject">'
					. $field(
						'<label for="' . esc_attr( $comment ) . '">' . esc_html__( 'Bemerkung (Pflicht)', 'ttleague' ) . '</label>'
						. '<textarea id="' . esc_attr( $comment ) . '" name="comment" rows="2"' . $max . ' required></textarea>'
					)
					. '<button type="submit" class="' . esc_attr( $second ) . '">' . esc_html__( 'Antrag ablehnen', 'ttleague' ) . '</button>',
					$form
				)
				. '</details>';
		}
		return $out;
	}

	/**
	 * Formular «Termin verschieben» bzw. «Termin vorschlagen».
	 *
	 * @param array<string,mixed> $overview PostponementService::overview().
	 */
	private static function request_form( array $overview, \DateTimeImmutable $now, bool $is_tk ): string {
		$fixture  = $overview['fixture'];
		$proposal = Rules::PROPOSE === $overview['kind'];
		$id       = (int) $fixture['id'];
		$date     = 'ttl-postponement-date-' . $id;
		$reason   = 'ttl-postponement-reason-' . $id;
		$days     = (int) $overview['rules']['approval_days'];
		$min      = $now->modify( '+1 minute' )->format( 'Y-m-d\TH:i' );
		$start    = $overview['rules']['season_start'];
		if ( null !== $start && $start . 'T00:00' > $min ) {
			$min = $start . 'T00:00';
		}
		$max = null === $overview['rules']['season_end'] ? '' : ' max="' . esc_attr( $overview['rules']['season_end'] . 'T23:59' ) . '"';

		$hints = array();
		if ( $is_tk ) {
			$hints[] = __( 'Als TK legst du den Termin direkt fest. Beide Mannschaften erhalten eine Nachricht.', 'ttleague' );
		} elseif ( $proposal ) {
			$hints[] = __( 'Die Gegenseite erhält deinen Vorschlag und stimmt zu oder lehnt ab.', 'ttleague' );
		} else {
			/* translators: %d: Tage */
			$hints[] = sprintf( _n( 'Die Gegenseite muss zustimmen. Liegt der neue Termin mehr als %d Tag nach dem bisherigen, entscheidet danach die TK.', 'Die Gegenseite muss zustimmen. Liegt der neue Termin mehr als %d Tage nach dem bisherigen, entscheidet danach die TK.', $days, 'ttleague' ), $days );
		}
		if ( null !== $fixture['deadline'] ) {
			$hints[] = $is_tk
				/* translators: %s: Datum */
				? sprintf( __( 'Frist der Runde: %s.', 'ttleague' ), Format::date_plain( $fixture['deadline'] ) )
				/* translators: %s: Datum */
				: sprintf( __( 'Frist der Runde: %s. Ein Termin danach braucht die Bewilligung der TK.', 'ttleague' ), Format::date_plain( $fixture['deadline'] ) );
		}
		$label = $proposal ? __( 'Termin vorschlagen', 'ttleague' ) : __( 'Termin verschieben', 'ttleague' );
		return '<details class="ttl-details ttl-postponement__request"' . ( $proposal ? ' open' : '' ) . '><summary>' . esc_html( $label ) . '</summary>'
			. self::form(
				Forms::REQUEST,
				$id,
				Forms::BACK_ENTRY,
				'<p class="ttl-muted">' . esc_html( implode( ' ', $hints ) ) . '</p>'
				. '<p class="ttl-field"><label for="' . esc_attr( $date ) . '">' . esc_html( $proposal ? __( 'Termin (Datum und Zeit)', 'ttleague' ) : __( 'Neuer Termin (Datum und Zeit)', 'ttleague' ) ) . '</label>'
				. '<input id="' . esc_attr( $date ) . '" type="datetime-local" name="new_date" required min="' . esc_attr( $min ) . '"' . $max . '></p>'
				. '<p class="ttl-field"><label for="' . esc_attr( $reason ) . '">' . esc_html( $proposal ? __( 'Bemerkung (freiwillig)', 'ttleague' ) : __( 'Begründung', 'ttleague' ) ) . '</label>'
				. '<textarea id="' . esc_attr( $reason ) . '" name="reason" rows="3" maxlength="' . esc_attr( (string) PostponementService::TEXT_MAX ) . '"' . ( $proposal ? '' : ' required' ) . '></textarea></p>'
				. '<button type="submit" class="ttl-button ttl-button--primary">' . esc_html( $is_tk ? __( 'Termin festlegen', 'ttleague' ) : ( $proposal ? __( 'Vorschlag senden', 'ttleague' ) : __( 'Verschiebung beantragen', 'ttleague' ) ) ) . '</button>',
				'ttl-form ttl-postponement__form'
			)
			. '</details>';
	}

	/**
	 * Frühere Anträge der Begegnung, aufklappbar.
	 *
	 * @param list<array<string,mixed>> $history Abgeschlossene Anträge, neueste zuerst.
	 */
	private static function history( array $history, \DateTimeZone $timezone ): string {
		if ( array() === $history ) {
			return '';
		}
		$items = '';
		foreach ( $history as $request ) {
			$dates  = null === $request['old_date']
				? Format::datetime( $request['new_date'] )
				: Format::datetime( $request['old_date'] ) . ' → ' . Format::datetime( $request['new_date'] );
			$parts  = array_filter( array( self::local( $request['requested_at'], $timezone ), $request['requested_by'] ) );
			$items .= '<li><strong>' . esc_html( $dates ) . '</strong>: ' . esc_html( (string) $request['status_label'] )
				. ( array() === $parts ? '' : ' <span class="ttl-muted">(' . esc_html( implode( ', ', $parts ) ) . ')</span>' )
				. ( null === $request['response_note'] || '' === $request['response_note'] ? '' : '<br><span class="ttl-muted">«' . esc_html( (string) $request['response_note'] ) . '»</span>' )
				. '</li>';
		}
		return '<details class="ttl-details ttl-postponement__history"><summary>'
			/* translators: %d: Anzahl */
			. esc_html( sprintf( _n( 'Früherer Antrag (%d)', 'Frühere Anträge (%d)', count( $history ), 'ttleague' ), count( $history ) ) )
			. '</summary><ul>' . $items . '</ul></details>';
	}

	/**
	 * Formular an admin-post.php mit Nonce pro Begegnung bzw. Antrag.
	 *
	 * @param string $action      Forms::REQUEST, ::RESPOND oder ::DECIDE.
	 * @param int    $id          Begegnung (REQUEST) oder Antrag.
	 * @param string $back        Wohin es danach geht (Forms::BACK_*).
	 * @param string $fields_html Felder und Knopf, bereits escaped.
	 */
	private static function form( string $action, int $id, string $back, string $fields_html, string $css_class ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $css_class ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
			. '<input type="hidden" name="' . esc_attr( Forms::REQUEST === $action ? 'fixture' : 'postponement' ) . '" value="' . esc_attr( (string) $id ) . '">'
			. '<input type="hidden" name="back" value="' . esc_attr( $back ) . '">'
			// Ohne wp_nonce_field(): Mehrere Formulare auf einer Seite hätten sonst dieselbe ID.
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action . '_' . $id ) ) . '">'
			. $fields_html
			. '</form>';
	}

	/**
	 * @param array{type:string,message:string,details:list<string>}|null $flash Rückmeldung.
	 */
	private static function notice( ?array $flash ): string {
		if ( null === $flash ) {
			return '';
		}
		$variant = in_array( $flash['type'], array( 'success', 'error', 'warning' ), true ) ? $flash['type'] : 'info';
		$details = array() === $flash['details'] ? '' : '<ul>' . implode( '', array_map( static fn( string $d ): string => '<li>' . esc_html( $d ) . '</li>', $flash['details'] ) ) . '</ul>';
		return '<div class="ttl-notice ttl-notice--' . esc_attr( $variant ) . '" role="' . ( 'error' === $variant ? 'alert' : 'status' ) . '"><p>' . esc_html( $flash['message'] ) . '</p>' . $details . '</div>';
	}

	/**
	 * Name der Mannschaft einer Seite oder null.
	 *
	 * @param array<string,mixed> $fixture Begegnung.
	 */
	private static function side_name( ?string $side, array $fixture ): ?string {
		return null === $side ? null : (string) $fixture[ $side ];
	}

	/**
	 * Zeitpunkt in UTC (ISO) als Ortszeit «Di, 10.11.2026, 21:30».
	 */
	private static function local( ?string $iso, \DateTimeZone $timezone ): string {
		if ( null === $iso || '' === $iso ) {
			return '';
		}
		try {
			return Format::datetime( ( new \DateTimeImmutable( $iso ) )->setTimezone( $timezone )->format( 'Y-m-d H:i:s' ) );
		} catch ( \Exception ) {
			return '';
		}
	}
}
