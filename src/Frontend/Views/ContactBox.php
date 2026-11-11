<?php
/**
 * Kontaktdaten der Captains und TK-Chefs in den Ansichten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\Contacts;
use TTLeague\Accounts\Services;
use TTLeague\Frontend\Support\Html;

/**
 * Nur angemeldete Personen mit dem Recht ttl_view_contacts (Captain oder TK-Chef der aktiven
 * Saison, TK) sehen Kontaktdaten. Für alle anderen liefern die Methoden null bzw. nichts, und
 * die Ansichten bleiben unverändert, damit ihre Ausgabe zwischengespeichert werden kann. Die
 * Daten kommen bei jeder Anfrage aus Contacts, nie aus den gecachten Lesemodellen.
 */
final class ContactBox {

	/**
	 * Darf die aktuelle Person Kontaktdaten sehen?
	 */
	public static function allowed(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::VIEW_CONTACTS );
	}

	/**
	 * Kontaktdaten nach Person für die Funktionen der Clubs. Null ohne Recht und wenn es nichts
	 * zu zeigen gibt (Saison nicht aktiv, keine aktive Person mit Funktion): Die Ansicht bleibt
	 * dann wie für Gäste.
	 *
	 * @param list<int> $club_ids Clubs.
	 * @return array<int,array{email:string|null,phone:string|null}>|null
	 */
	public static function people( int $season_id, array $club_ids ): ?array {
		if ( ! self::allowed() ) {
			return null;
		}
		$people = ( new Contacts( Services::db() ) )->by_person( $season_id, $club_ids );
		return array() === $people ? null : $people;
	}

	/**
	 * E-Mail als mailto:- und Telefon als tel:-Link; leer, wenn beides fehlt.
	 *
	 * @param array{email?:string|null,phone?:string|null}|null $contact Kontaktdaten einer Person.
	 */
	public static function links( ?array $contact ): string {
		$items = array();
		$email = trim( (string) ( $contact['email'] ?? '' ) );
		if ( '' !== $email && false !== is_email( $email ) ) {
			$items[] = '<a class="ttl-contact__link" href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
		}
		$phone = trim( (string) ( $contact['phone'] ?? '' ) );
		$dial  = self::dial( $phone );
		if ( '' !== $dial ) {
			$items[] = '<a class="ttl-contact__link" href="' . esc_url( 'tel:' . $dial ) . '">' . esc_html( $phone ) . '</a>';
		}
		return array() === $items ? '' : '<span class="ttl-contact">' . implode( ' <span class="ttl-muted" aria-hidden="true">·</span> ', $items ) . '</span>';
	}

	/**
	 * Abschnitt «Kontakt» zu einer Begegnung: Captains und TK-Chefs beider Seiten. Leer ohne
	 * Recht, ausserhalb der aktiven Saison und wenn für keine Seite eine Funktion erfasst ist.
	 *
	 * @param int $level Ebene der Überschrift (Matchreport 2, Erfassungsseite 3).
	 */
	public static function fixture_section( int $fixture_id, int $level = 2 ): string {
		if ( $fixture_id <= 0 || ! self::allowed() ) {
			return '';
		}
		$sides = ( new Contacts( Services::db() ) )->for_fixture( $fixture_id );
		if ( null === $sides || ( array() === $sides['home']['people'] && array() === $sides['away']['people'] ) ) {
			return '';
		}
		$body = '<div class="ttl-contacts__sides">';
		foreach ( $sides as $side ) {
			$items = '';
			foreach ( $side['people'] as $person ) {
				$links  = self::links( $person );
				$items .= '<div class="ttl-facts__item"><dt>' . esc_html( 'tk_chef' === $person['role'] ? __( 'TK-Chef', 'ttleague' ) : __( 'Captain', 'ttleague' ) ) . '</dt>'
					. '<dd>' . esc_html( (string) $person['name'] ) . ( '' === $links ? '' : '<br>' . $links ) . '</dd></div>';
			}
			$body .= '<div class="ttl-contacts__side"><h' . ( $level + 1 ) . ' class="ttl-subtitle">' . esc_html( (string) $side['name'] ) . '</h' . ( $level + 1 ) . '>'
				. ( '' === $items ? '<p class="ttl-muted">' . esc_html__( 'Keine Funktionen erfasst.', 'ttleague' ) . '</p>' : '<dl class="ttl-facts ttl-facts--roles">' . $items . '</dl>' )
				. '</div>';
		}
		$body .= '</div><p class="ttl-footnote">' . esc_html( self::footnote() ) . '</p>';
		return Html::section( __( 'Kontakt', 'ttleague' ), $body, 'ttl-section--contacts ttl-contacts', 'ttl-kontakt', $level );
	}

	/**
	 * Fussnote für Personen, die Kontaktdaten sehen.
	 */
	public static function footnote(): string {
		return __( 'Kontaktdaten sind nur für angemeldete Funktionärinnen und Funktionäre sichtbar. Telefonnummern erscheinen, wenn die Person sie freigegeben hat.', 'ttleague' );
	}

	/**
	 * Nummer für den tel:-Link: nur Ziffern und ein führendes Pluszeichen, ohne «(0)» nach der
	 * Landesvorwahl. Leer, wenn keine wählbare Nummer bleibt.
	 */
	public static function dial( string $phone ): string {
		$phone = trim( $phone );
		$plus  = str_starts_with( $phone, '+' );
		if ( $plus ) {
			$phone = str_replace( '(0)', '', $phone );
		}
		$digits = (string) preg_replace( '/[^0-9]/', '', $phone );
		return strlen( $digits ) < 3 ? '' : ( $plus ? '+' : '' ) . $digits;
	}
}
