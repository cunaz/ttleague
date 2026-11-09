<?php
/**
 * Abschnitt «Mein Club» in «Mein Bereich» für TK-Chefs: Personen des Clubs mit E-Mail-Adresse
 * und Kontostatus, E-Mail setzen und einladen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

use TTLeague\Access\Capabilities;
use TTLeague\Frontend\Assets;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\Results;

/**
 * Erscheint nur, wenn die Person in der aktiven Saison TK-Chef eines Clubs ist. Die Formulare
 * gehen an admin-post.php (Nonce pro Person, Rechte ttl_manage_club und ttl_invite_person);
 * die Regeln prüft ClubAccounts.
 */
final class MyClub {

	public const EMAIL  = 'ttleague_club_email';
	public const INVITE = 'ttleague_club_invite';
	public const STYLE  = 'ttleague-club';

	/** Nach «Meine Spiele» (Standardpriorität 10). */
	public const PRIORITY = 20;

	public static function register(): void {
		add_action( 'ttleague_my_area_sections', array( self::class, 'render' ), self::PRIORITY );
		add_action( 'admin_post_' . self::EMAIL, array( self::class, 'handle_email' ) );
		add_action( 'admin_post_' . self::INVITE, array( self::class, 'handle_invite' ) );
		add_action( 'init', array( self::class, 'register_style' ) );
	}

	public static function register_style(): void {
		$file = 'assets/css/club.css';
		$path = TTLEAGUE_DIR . $file;
		wp_register_style( self::STYLE, TTLEAGUE_URL . $file, array( Assets::STYLE ), TTLEAGUE_VERSION . ( is_readable( $path ) ? '.' . (string) filemtime( $path ) : '' ) );
	}

	/**
	 * Abschnitt pro Club, für den die Person TK-Chef ist (Aktion ttleague_my_area_sections).
	 */
	public static function render( MyAreaContext $context ): void {
		$clubs = $context->roles['tk_chef_clubs'];
		if ( array() === $clubs || null === $context->season_id ) {
			return;
		}
		wp_enqueue_style( self::STYLE );
		$source = Services::club_people();
		$out    = '';
		foreach ( $clubs as $club_id ) {
			$out .= self::section(
				$club_id,
				(string) $source->club_name( $club_id ),
				$source->for_club( $context->season_id, $club_id ),
				Flash::take( self::scope( $club_id ), $context->user_id ),
				$context->is_tk
			);
		}
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Alle Teile werden beim Aufbau escaped.
	}

	public static function handle_email(): void {
		[ $club, $person ] = self::target( self::EMAIL );
		$email             = sanitize_text_field( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in target() geprüft.
		self::back( $club, Services::club_accounts()->change_email( Results::current_actor(), $person, $email ) );
	}

	public static function handle_invite(): void {
		[ $club, $person ] = self::target( self::INVITE );
		self::back( $club, Services::club_accounts()->invite( Results::current_actor(), $person ) );
	}

	/**
	 * @param list<array<string,mixed>>                                   $people Personen aus ClubPeople::for_club().
	 * @param array{type:string,message:string,details:list<string>}|null $flash  Rückmeldung.
	 */
	public static function section( int $club_id, string $club_name, array $people, ?array $flash, bool $by_tk = false ): string {
		$id  = 'ttl-mein-club-' . $club_id;
		$out = '<section class="ttl-section ttl-club" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id . '-titel' ) . '">'
			/* translators: %s: Clubname */
			. '<h3 class="ttl-section__title" id="' . esc_attr( $id . '-titel' ) . '">' . esc_html( sprintf( __( 'Mein Club: %s', 'ttleague' ), $club_name ) ) . '</h3>'
			. self::notice( $flash )
			. '<p class="ttl-muted">' . esc_html__( 'Hinterlege die E-Mail-Adressen deiner Spielerinnen, Spieler und Captains und lade sie ein. Mit dem Link aus der Einladung melden sie sich ohne Passwort an.', 'ttleague' ) . '</p>';
		if ( array() === $people ) {
			return $out . '<p>' . esc_html__( 'Für deinen Club sind in dieser Saison keine Personen erfasst.', 'ttleague' ) . '</p></section>';
		}
		$out .= '<p class="ttl-club__summary">' . esc_html( AccountLabels::summary( $people ) ) . '</p>'
			. '<table class="ttl-table ttl-table--cards ttl-club__table"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Name', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Funktion', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'E-Mail', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Konto', 'ttleague' ) . '</th>'
			. '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Aktionen', 'ttleague' ) . '</span></th>'
			. '</tr></thead><tbody>';
		foreach ( $people as $person ) {
			$out .= self::row( $club_id, $person, $by_tk );
		}
		return $out . '</tbody></table></section>';
	}

	/**
	 * @param array<string,mixed> $person Person.
	 */
	private static function row( int $club_id, array $person, bool $by_tk ): string {
		$functions = AccountLabels::functions( $person );
		$detail    = AccountLabels::status_detail( $person );
		$variant   = match ( $person['status'] ) {
			ClubPeople::ACTIVE   => 'win',
			ClubPeople::NO_EMAIL => 'warning',
			default              => 'muted',
		};
		return '<tr id="' . esc_attr( 'ttl-person-' . $person['person_id'] ) . '">'
			. '<th scope="row" class="ttl-cell--primary">' . esc_html( $person['name'] ) . '</th>'
			. '<td data-label="' . esc_attr__( 'Funktion', 'ttleague' ) . '"' . ( '' === $functions ? ' class="ttl-cell--empty"' : '' ) . '>' . esc_html( $functions ) . '</td>'
			. '<td data-label="' . esc_attr__( 'E-Mail', 'ttleague' ) . '" class="ttl-club__email' . ( null === $person['email'] ? ' ttl-cell--empty' : '' ) . '">' . ( null === $person['email'] ? '<span class="ttl-muted">–</span>' : esc_html( (string) $person['email'] ) ) . '</td>'
			. '<td data-label="' . esc_attr__( 'Konto', 'ttleague' ) . '"><span class="ttl-badge ttl-badge--' . esc_attr( $variant ) . '">' . esc_html( AccountLabels::status( $person['status'] ) ) . '</span>'
			. ( '' === $detail ? '' : ' <span class="ttl-muted ttl-club__detail">' . esc_html( $detail ) . '</span>' ) . '</td>'
			. '<td class="ttl-club__actions">' . self::email_form( $club_id, $person, $by_tk ) . self::invite_form( $club_id, $person ) . '</td>'
			. '</tr>';
	}

	/**
	 * @param array<string,mixed> $person Person.
	 */
	private static function email_form( int $club_id, array $person, bool $by_tk ): string {
		if ( ! ClubAccounts::may_edit_email( $by_tk, $person ) ) {
			return '<p class="ttl-muted ttl-club__hint">' . esc_html__( 'Konto mit erweiterten Rechten: Die E-Mail-Adresse ändert die TK.', 'ttleague' ) . '</p>';
		}
		$id    = (int) $person['person_id'];
		$field = 'ttl-club-email-' . $id;
		$label = null === $person['email'] ? __( 'E-Mail hinterlegen', 'ttleague' ) : __( 'E-Mail ändern', 'ttleague' );
		return '<details class="ttl-details ttl-club__edit"><summary>' . esc_html( $label ) . '</summary>'
			. '<form class="ttl-form ttl-club__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::EMAIL ) . '">'
			. '<input type="hidden" name="club" value="' . esc_attr( (string) $club_id ) . '">'
			. '<input type="hidden" name="person" value="' . esc_attr( (string) $id ) . '">'
			. wp_nonce_field( self::EMAIL . '_' . $id, '_wpnonce', true, false )
			/* translators: %s: Name */
			. '<p class="ttl-field"><label for="' . esc_attr( $field ) . '">' . esc_html( sprintf( __( 'E-Mail-Adresse von %s', 'ttleague' ), $person['name'] ) ) . '</label>'
			. '<input id="' . esc_attr( $field ) . '" type="email" name="email" value="' . esc_attr( (string) $person['email'] ) . '" required autocomplete="off" inputmode="email"></p>'
			. ( null === $person['email'] ? '' : '<p class="ttl-muted ttl-club__hint">' . esc_html__( 'Die bisherige Adresse erhält eine Mitteilung.', 'ttleague' ) . '</p>' )
			. '<button type="submit" class="ttl-button ttl-button--primary">' . esc_html__( 'Speichern', 'ttleague' ) . '</button>'
			. '</form></details>';
	}

	/**
	 * @param array<string,mixed> $person Person.
	 */
	private static function invite_form( int $club_id, array $person ): string {
		if ( ! $person['link_allowed'] ) {
			return '<p class="ttl-muted ttl-club__hint">' . esc_html__( 'Konto mit erweiterten Rechten: Anmeldung nur mit Passwort.', 'ttleague' ) . '</p>';
		}
		if ( ClubPeople::NO_EMAIL === $person['status'] ) {
			return '';
		}
		$id = (int) $person['person_id'];
		return '<form class="ttl-club__invite" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::INVITE ) . '">'
			. '<input type="hidden" name="club" value="' . esc_attr( (string) $club_id ) . '">'
			. '<input type="hidden" name="person" value="' . esc_attr( (string) $id ) . '">'
			. wp_nonce_field( self::INVITE . '_' . $id, '_wpnonce', true, false )
			. '<button type="submit" class="ttl-button ttl-button--secondary">' . esc_html( AccountLabels::invite_button( $person ) ) . '</button>'
			. '</form>';
	}

	/**
	 * @param array{type:string,message:string,details:list<string>}|null $flash Rückmeldung.
	 */
	private static function notice( ?array $flash ): string {
		if ( null === $flash ) {
			return '';
		}
		$variant = match ( $flash['type'] ) {
			Outcome::SUCCESS => 'success',
			Outcome::ERROR   => 'error',
			Outcome::WARNING => 'warning',
			default          => 'info',
		};
		$details = array() === $flash['details'] ? '' : '<ul>' . implode( '', array_map( static fn( string $d ): string => '<li>' . esc_html( $d ) . '</li>', $flash['details'] ) ) . '</ul>';
		return '<div class="ttl-notice ttl-notice--' . esc_attr( $variant ) . '" role="' . ( 'error' === $variant ? 'alert' : 'status' ) . '"><p>' . esc_html( $flash['message'] ) . '</p>' . $details . '</div>';
	}

	/**
	 * Nonce und Rechte prüfen: ttl_manage_club für den Club, ttl_invite_person für die Person.
	 *
	 * @return array{0:int,1:int} Club und Person.
	 */
	private static function target( string $action ): array {
		$club   = isset( $_POST['club'] ) ? absint( $_POST['club'] ) : 0;
		$person = isset( $_POST['person'] ) ? absint( $_POST['person'] ) : 0;
		check_admin_referer( $action . '_' . $person );
		if ( ! current_user_can( Capabilities::MANAGE_CLUB, $club ) || ! current_user_can( Capabilities::INVITE_PERSON, $person ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
		return array( $club, $person );
	}

	private static function back( int $club, Outcome $outcome ): never {
		Flash::set( self::scope( $club ), get_current_user_id(), $outcome->type, $outcome->message, $outcome->details );
		wp_safe_redirect( LoginFlow::my_area_url() . '#ttl-mein-club-' . $club );
		exit;
	}

	private static function scope( int $club_id ): string {
		return 'club' . $club_id;
	}
}
