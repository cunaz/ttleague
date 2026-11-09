<?php
/**
 * Seite «Spielbetrieb › Konten und Einladungen»: Personen eines Clubs mit Kontostatus.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tk;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\AccountLabels;
use TTLeague\Accounts\ClubAccounts;
use TTLeague\Accounts\ClubPeople;
use TTLeague\Accounts\Services;
use TTLeague\Infrastructure\Flash;

/**
 * Pro Club (Auswahl oben) die gemeldeten Spieler und Funktionsträger der aktiven Saison mit
 * Funktion, E-Mail-Adresse und Kontostatus. Aktionen: E-Mail setzen oder ändern, einladen,
 * erneut einladen, alle Captains und TK-Chefs ohne Konto einladen.
 */
final class AccountsPage {

	public static function render(): void {
		if ( ! current_user_can( Capabilities::TK ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
		$season = Services::policy()->active_season_id();
		$source = Services::club_people();
		$clubs  = null === $season ? array() : $source->clubs( $season );
		$ids    = array_column( $clubs, 'id' );
		$club   = isset( $_GET['club'] ) ? absint( $_GET['club'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Auswahl zum Anzeigen, ändert nichts.
		if ( ! in_array( $club, $ids, true ) ) {
			$club = (int) ( $ids[0] ?? 0 );
		}
		$people = null === $season || 0 === $club ? array() : $source->for_club( $season, $club );
		echo self::html( $clubs, $club, $people, Flash::take( Tk::FLASH, get_current_user_id() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Alle Teile werden beim Aufbau escaped.
	}

	/**
	 * @param list<array{id:int,name:string,short_name:string}>          $clubs  Clubs der aktiven Saison.
	 * @param list<array<string,mixed>>                                   $people Personen aus ClubPeople::for_club().
	 * @param array{type:string,message:string,details:list<string>}|null $flash  Rückmeldung.
	 */
	public static function html( array $clubs, int $club_id, array $people, ?array $flash ): string {
		$out = '<div class="wrap ttl-tk"><h1>' . esc_html__( 'Spielbetrieb: Konten und Einladungen', 'ttleague' ) . '</h1>' . DashboardPage::notice( $flash );
		if ( array() === $clubs ) {
			return $out . '<p>' . esc_html__( 'In der aktiven Saison sind noch keine Clubs mit Spielern oder Funktionen erfasst.', 'ttleague' ) . '</p></div>';
		}

		$options = '';
		foreach ( $clubs as $club ) {
			$options .= '<option value="' . esc_attr( (string) $club['id'] ) . '"' . selected( $club['id'], $club_id, false ) . '>' . esc_html( $club['name'] ) . '</option>';
		}
		$out .= '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="ttl-tk-filter">'
			. '<input type="hidden" name="page" value="' . esc_attr( Tk::ACCOUNTS_PAGE ) . '">'
			. '<label for="ttl-club"><strong>' . esc_html__( 'Club', 'ttleague' ) . '</strong></label>'
			. '<select id="ttl-club" name="club">' . $options . '</select>'
			. '<button type="submit" class="button">' . esc_html__( 'Anzeigen', 'ttleague' ) . '</button>'
			. '</form>';

		if ( array() === $people ) {
			return $out . '<p>' . esc_html__( 'Für diesen Club sind keine Personen erfasst.', 'ttleague' ) . '</p></div>';
		}

		$pending = count(
			array_filter(
				$people,
				static fn( array $p ): bool => ClubPeople::is_official( $p ) && ClubPeople::NO_ACCOUNT === $p['status']
			)
		);
		$out    .= '<p>' . esc_html( AccountLabels::summary( $people ) ) . '</p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-filter">'
			. '<input type="hidden" name="action" value="' . esc_attr( Tk::INVITE_OFFICIALS ) . '">'
			. '<input type="hidden" name="club" value="' . esc_attr( (string) $club_id ) . '">'
			. wp_nonce_field( Tk::INVITE_OFFICIALS . '_' . $club_id, '_wpnonce', true, false )
			/* translators: %d: Anzahl */
			. '<button type="submit" class="button button-primary"' . disabled( 0, $pending, false ) . '>' . esc_html( sprintf( __( 'Alle Captains und TK-Chefs ohne Konto einladen (%d)', 'ttleague' ), $pending ) ) . '</button>'
			. '<span class="ttl-tk-muted">' . esc_html__( 'Personen ohne E-Mail-Adresse werden übersprungen.', 'ttleague' ) . '</span>'
			. '</form>';

		$out .= '<table class="widefat striped ttl-tk-table"><thead><tr>'
			. '<th scope="col" class="ttl-col-name column-primary">' . esc_html__( 'Name', 'ttleague' ) . '</th>'
			. '<th scope="col" class="ttl-col-functions">' . esc_html__( 'Funktion', 'ttleague' ) . '</th>'
			. '<th scope="col" class="ttl-col-email">' . esc_html__( 'E-Mail', 'ttleague' ) . '</th>'
			. '<th scope="col" class="ttl-col-account">' . esc_html__( 'Konto', 'ttleague' ) . '</th>'
			. '<th scope="col" class="ttl-col-invite">' . esc_html__( 'Einladung', 'ttleague' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $people as $person ) {
			$out .= self::row( $person, $club_id );
		}
		return $out . '</tbody></table></div>';
	}

	/**
	 * @param array<string,mixed> $person Person aus ClubPeople::for_club().
	 */
	private static function row( array $person, int $club_id ): string {
		$id     = (int) $person['person_id'];
		$detail = AccountLabels::status_detail( $person );
		return '<tr id="' . esc_attr( 'ttl-person-' . $id ) . '">'
			. '<td class="column-primary"><strong>' . esc_html( $person['name'] ) . '</strong></td>'
			. '<td>' . esc_html( AccountLabels::functions( $person ) ) . '</td>'
			. '<td>' . self::email_cell( $person, $club_id ) . '</td>'
			. '<td><span class="' . esc_attr( 'ttl-tk-status ttl-tk-status--' . $person['status'] ) . '">' . esc_html( AccountLabels::status( $person['status'] ) ) . '</span>'
			. ( '' === $detail ? '' : '<br><span class="ttl-tk-muted">' . esc_html( $detail ) . '</span>' ) . '</td>'
			. '<td>' . self::invite_cell( $person, $club_id ) . '</td>'
			. '</tr>';
	}

	/**
	 * @param array<string,mixed> $person Person.
	 */
	private static function email_cell( array $person, int $club_id ): string {
		$id = (int) $person['person_id'];
		if ( ! ClubAccounts::may_edit_email( true, $person ) ) {
			return esc_html( (string) $person['email'] ) . '<br><span class="ttl-tk-muted">' . esc_html__( 'Administratorkonto: Änderung in der Benutzerverwaltung.', 'ttleague' ) . '</span>';
		}
		$field = 'ttl-email-' . $id;
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-inline">'
			. '<input type="hidden" name="action" value="' . esc_attr( Tk::EMAIL ) . '">'
			. '<input type="hidden" name="person" value="' . esc_attr( (string) $id ) . '">'
			. '<input type="hidden" name="club" value="' . esc_attr( (string) $club_id ) . '">'
			. wp_nonce_field( Tk::EMAIL . '_' . $id, '_wpnonce', true, false )
			/* translators: %s: Name */
			. '<label class="screen-reader-text" for="' . esc_attr( $field ) . '">' . esc_html( sprintf( __( 'E-Mail-Adresse von %s', 'ttleague' ), $person['name'] ) ) . '</label>'
			. '<input type="email" id="' . esc_attr( $field ) . '" name="email" value="' . esc_attr( (string) $person['email'] ) . '" required autocomplete="off">'
			. '<button type="submit" class="button button-small">' . esc_html( null === $person['email'] ? __( 'Setzen', 'ttleague' ) : __( 'Ändern', 'ttleague' ) ) . '</button>'
			. '</form>';
	}

	/**
	 * @param array<string,mixed> $person Person.
	 */
	private static function invite_cell( array $person, int $club_id ): string {
		if ( ! $person['link_allowed'] ) {
			return '<span class="ttl-tk-muted">' . esc_html__( 'Administratorkonto: Anmeldung nur mit Passwort, keine Einladung per Link.', 'ttleague' ) . '</span>';
		}
		if ( ClubPeople::NO_EMAIL === $person['status'] ) {
			return '<span class="ttl-tk-muted">' . esc_html__( 'Zuerst E-Mail-Adresse hinterlegen.', 'ttleague' ) . '</span>';
		}
		$id = (int) $person['person_id'];
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( Tk::INVITE ) . '">'
			. '<input type="hidden" name="person" value="' . esc_attr( (string) $id ) . '">'
			. '<input type="hidden" name="club" value="' . esc_attr( (string) $club_id ) . '">'
			. wp_nonce_field( Tk::INVITE . '_' . $id, '_wpnonce', true, false )
			. '<button type="submit" class="button button-small">' . esc_html( AccountLabels::invite_button( $person ) ) . '</button>'
			. '</form>';
	}
}
