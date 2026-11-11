<?php
/**
 * Formularaktionen des Blocks ttleague/event-registration (admin-post.php).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\LoginFlow;
use TTLeague\Accounts\Outcome;
use TTLeague\Domain\Events\RegistrationRules as Rules;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\Results;

/**
 * An- und Abmeldung von der Website: Nonce pro Anlass, Rechte über die Capabilities (für sich
 * selbst die verknüpfte Person, für andere ttl_manage_club für einen Club der Person oder
 * ttl_tk), danach der RegistrationService mit der fachlichen Prüfung. Die Rückmeldung liegt im
 * Flash des Anlasses, zurück geht es zur Seite mit dem Block.
 */
final class BlockActions {

	public const REGISTER   = 'ttleague_event_register';
	public const UNREGISTER = 'ttleague_event_unregister';

	public static function register(): void {
		[ $event, $person, $own ] = self::target( self::REGISTER );
		$note                     = isset( $_POST['note'] ) && is_string( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in target() geprüft.
		try {
			$result = Events::registrations()->register( $event, $person, Results::current_actor(), $note );
		} catch ( EventException $e ) {
			self::back( $event, Outcome::error( $e->getMessage() ) );
		}
		$name = self::name( $person );
		if ( Rules::WAITLIST === $result['status'] ) {
			$outcome = Outcome::warning(
				$own
					? __( 'Die Höchstzahl ist erreicht: Du stehst auf der Warteliste. Wird ein Platz frei, rückst du nach.', 'ttleague' )
					/* translators: %s: Name */
					: sprintf( __( 'Die Höchstzahl ist erreicht: %s steht auf der Warteliste.', 'ttleague' ), $name )
			);
		} else {
			/* translators: %s: Name */
			$outcome = Outcome::success( $own ? __( 'Du bist angemeldet. Die Bestätigung kommt per E-Mail.', 'ttleague' ) : sprintf( __( '%s ist angemeldet.', 'ttleague' ), $name ) );
		}
		self::back( $event, $outcome );
	}

	public static function unregister(): void {
		[ $event, $person, $own ] = self::target( self::UNREGISTER );
		try {
			Events::registrations()->unregister( $event, $person, Results::current_actor() );
		} catch ( EventException $e ) {
			self::back( $event, Outcome::error( $e->getMessage() ) );
		}
		/* translators: %s: Name */
		self::back( $event, Outcome::success( $own ? __( 'Du bist abgemeldet.', 'ttleague' ) : sprintf( __( '%s ist abgemeldet.', 'ttleague' ), self::name( $person ) ) ) );
	}

	/**
	 * Nicht angemeldete Besucher: zum Login und danach zurück zur Seite mit dem Block.
	 */
	public static function login_first(): void {
		$redirect = LoginFlow::safe_redirect( isset( $_POST['redirect_to'] ) && is_string( $_POST['redirect_to'] ) ? (string) wp_unslash( $_POST['redirect_to'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nur Weiterleitung; safe_redirect() lässt nur eigene Adressen zu.
		wp_safe_redirect( LoginFlow::page_url( '' === $redirect ? array() : array( 'redirect_to' => $redirect ) ) );
		exit;
	}

	/**
	 * Nonce und Rechte prüfen.
	 *
	 * @return array{0:int,1:int,2:bool} Anlass, Person und ob die Person für sich selbst handelt.
	 */
	private static function target( string $action ): array {
		$event  = isset( $_POST['event'] ) ? absint( $_POST['event'] ) : 0;
		$person = isset( $_POST['person'] ) ? absint( $_POST['person'] ) : 0;
		check_admin_referer( $action . '_' . $event );
		$user = get_current_user_id();
		$own  = $user > 0 ? Capabilities::policy()->person_for_user( $user ) : null;
		if ( $user > 0 && 0 === $person ) {
			self::back( $event, Outcome::error( __( 'Bitte wähle eine Person.', 'ttleague' ) ) );
		}
		if ( $user <= 0 || ! ( $person === $own || self::may_manage( $event, $person ) ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
		return array( $event, $person, $person === $own );
	}

	/**
	 * Für andere: die TK, oder ttl_manage_club für einen Club, für den die Person in der Saison
	 * des Anlasses gemeldet ist.
	 */
	private static function may_manage( int $event_id, int $person_id ): bool {
		if ( current_user_can( Capabilities::TK ) ) {
			return true;
		}
		$event = Events::repository()->find( $event_id );
		if ( null === $event ) {
			return false;
		}
		$season = Events::registrations()->season_for( $event );
		foreach ( null === $season ? array() : Events::repository()->club_ids( $person_id, $season ) as $club_id ) {
			if ( current_user_can( Capabilities::MANAGE_CLUB, $club_id ) ) {
				return true;
			}
		}
		return false;
	}

	private static function name( int $person_id ): string {
		$person = Events::repository()->person( $person_id );
		return null === $person ? '' : $person['name'];
	}

	/**
	 * Rückmeldung ablegen und zur Seite mit dem Block zurückleiten.
	 */
	private static function back( int $event_id, Outcome $outcome ): never {
		Flash::set( RegistrationView::FLASH . $event_id, get_current_user_id(), $outcome->type, $outcome->message, $outcome->details );
		$redirect = LoginFlow::safe_redirect( isset( $_POST['redirect_to'] ) && is_string( $_POST['redirect_to'] ) ? (string) wp_unslash( $_POST['redirect_to'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce in target() geprüft; safe_redirect() lässt nur eigene Adressen zu.
		if ( '' === $redirect ) {
			$event    = Events::repository()->find( $event_id );
			$redirect = ( null === $event ? null : ( new WordPressLinks() )->event( $event ) ) ?? LoginFlow::my_area_url();
		}
		wp_safe_redirect( strtok( $redirect, '#' ) . '#ttl-anlass-' . $event_id );
		exit;
	}
}
