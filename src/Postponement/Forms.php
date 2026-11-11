<?php
/**
 * Formularaktionen der Verschiebung (admin-post.php): Antrag, Antwort, Entscheid der TK.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Postponement;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\LoginFlow;
use TTLeague\Accounts\Outcome;
use TTLeague\Domain\Match\PostponementRules as Rules;
use TTLeague\Frontend\Support\Format;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\ResultException;
use TTLeague\Results\Results;
use TTLeague\Tk\Tk;

/**
 * Jede Aktion prüft Nonce und Recht (ttl_reschedule_fixture für die Begegnung, beim Entscheid
 * ttl_tk), ruft den PostponementService auf, legt die Rückmeldung ab (Flash) und leitet
 * zurück. Die REST-API ruft denselben Dienst auf; die fachlichen Regeln stehen dort.
 */
final class Forms {

	public const REQUEST = 'ttleague_postponement_request';
	public const RESPOND = 'ttleague_postponement_respond';
	public const DECIDE  = 'ttleague_postponement_decide';

	/** Ziel nach der Aktion: Erfassungsseite, «Mein Bereich» oder Übersicht der TK. */
	public const BACK_ENTRY = 'entry';
	public const BACK_AREA  = 'area';
	public const BACK_TK    = 'tk';

	/** Bereich der Rückmeldungen auf der Website (Flash). */
	public const FLASH = 'postponement';

	public static function register(): void {
		add_action( 'admin_post_' . self::REQUEST, array( self::class, 'request' ) );
		add_action( 'admin_post_' . self::RESPOND, array( self::class, 'respond' ) );
		add_action( 'admin_post_' . self::DECIDE, array( self::class, 'decide' ) );
	}

	/**
	 * Antrag stellen (Verschiebung oder Terminvorschlag).
	 */
	public static function request(): void {
		$fixture = isset( $_POST['fixture'] ) ? absint( $_POST['fixture'] ) : 0;
		check_admin_referer( self::REQUEST . '_' . $fixture );
		self::require_cap( Capabilities::RESCHEDULE, $fixture );
		$back = self::back_target();
		try {
			$result = Postponements::service()->request(
				$fixture,
				Results::current_actor(),
				array(
					'new_date' => sanitize_text_field( wp_unslash( (string) ( $_POST['new_date'] ?? '' ) ) ),
					'reason'   => sanitize_textarea_field( wp_unslash( (string) ( $_POST['reason'] ?? '' ) ) ),
				)
			);
		} catch ( ResultException $e ) {
			self::back( $back, $fixture, self::failure( $e ) );
		}
		$new = Format::datetime( $result['new_date'] );
		if ( Rules::APPROVED === $result['status'] ) {
			/* translators: %s: Datum und Zeit */
			self::back( $back, $fixture, Outcome::success( sprintf( __( 'Der Termin ist festgelegt: %s. Beide Mannschaften erhalten eine Nachricht.', 'ttleague' ), $new ) ) );
		}
		$message = $result['needs_approval']
			/* translators: %s: Datum und Zeit */
			? sprintf( __( 'Der Antrag für den %s ist gestellt. Die Gegenseite erhält eine Nachricht; nach ihrer Zustimmung entscheidet die TK.', 'ttleague' ), $new )
			/* translators: %s: Datum und Zeit */
			: sprintf( __( 'Der Antrag für den %s ist gestellt. Die Gegenseite erhält eine Nachricht; mit ihrer Zustimmung gilt der neue Termin.', 'ttleague' ), $new );
		self::back( $back, $fixture, Outcome::success( $message ) );
	}

	/**
	 * Antwort der Gegenseite (zustimmen, ablehnen) oder Rückzug.
	 */
	public static function respond(): void {
		$id = isset( $_POST['postponement'] ) ? absint( $_POST['postponement'] ) : 0;
		check_admin_referer( self::RESPOND . '_' . $id );
		$back     = self::back_target();
		$service  = Postponements::service();
		$fixture  = self::fixture_of( $id );
		$response = sanitize_key( wp_unslash( (string) ( $_POST['response'] ?? '' ) ) );
		self::require_cap( Capabilities::RESCHEDULE, $fixture );
		try {
			$actor  = Results::current_actor();
			$result = match ( $response ) {
				'accept'   => $service->accept( $id, $actor ),
				'decline'  => $service->decline( $id, $actor, sanitize_textarea_field( wp_unslash( (string) ( $_POST['note'] ?? '' ) ) ) ),
				'withdraw' => $service->withdraw( $id, $actor ),
				default    => null,
			};
		} catch ( ResultException $e ) {
			self::back( $back, $fixture, self::failure( $e ) );
		}
		if ( null === $result ) {
			self::back( $back, $fixture, Outcome::error( __( 'Unbekannte Antwort.', 'ttleague' ) ) );
		}
		$message = match ( $result['status'] ) {
			/* translators: %s: Datum und Zeit */
			Rules::APPROVED  => sprintf( __( 'Du hast zugestimmt. Neuer Termin: %s.', 'ttleague' ), Format::datetime( $result['new_date'] ) ),
			Rules::ACCEPTED  => __( 'Du hast zugestimmt. Die TK muss den Termin noch bewilligen; bis dahin gilt der bisherige.', 'ttleague' ),
			Rules::DECLINED  => __( 'Du hast den Antrag abgelehnt. Die Gegenseite erhält eine Nachricht.', 'ttleague' ),
			default          => __( 'Der Antrag ist zurückgezogen.', 'ttleague' ),
		};
		self::back( $back, $fixture, Outcome::success( $message ) );
	}

	/**
	 * Entscheid der TK: bewilligen oder ablehnen.
	 */
	public static function decide(): void {
		$id = isset( $_POST['postponement'] ) ? absint( $_POST['postponement'] ) : 0;
		check_admin_referer( self::DECIDE . '_' . $id );
		self::require_cap( Capabilities::TK );
		$back    = self::back_target();
		$fixture = self::fixture_of( $id );
		try {
			$result = Postponements::service()->decide(
				$id,
				Results::current_actor(),
				array(
					'action'  => sanitize_key( wp_unslash( (string) ( $_POST['decision'] ?? '' ) ) ),
					'comment' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['comment'] ?? '' ) ) ),
				)
			);
		} catch ( ResultException $e ) {
			self::back( $back, $fixture, self::failure( $e ) );
		}
		$name    = $result['fixture']['home'] . ' – ' . $result['fixture']['away'];
		$message = Rules::APPROVED === $result['status']
			/* translators: 1: Begegnung, 2: Datum und Zeit */
			? sprintf( __( '%1$s: Termin gutgeheissen, neu am %2$s.', 'ttleague' ), $name, Format::datetime( $result['new_date'] ) )
			/* translators: %s: Begegnung */
			: sprintf( __( '%s: Antrag abgelehnt. Beide Mannschaften erhalten eine Nachricht.', 'ttleague' ), $name );
		self::back( $back, $fixture, Outcome::success( $message ) );
	}

	/**
	 * Begegnung des Antrags; ohne Antrag zurück mit einer Meldung.
	 */
	private static function fixture_of( int $postponement_id ): int {
		try {
			return Postponements::service()->fixture_of( $postponement_id );
		} catch ( ResultException $e ) {
			self::back( self::back_target(), 0, self::failure( $e ) );
		}
	}

	private static function failure( ResultException $e ): Outcome {
		return Outcome::error( $e->getMessage(), count( $e->errors ) > 1 ? array_column( $e->errors, 'message' ) : array() );
	}

	/**
	 * @param string $cap Capability.
	 * @param int    $id  Objekt (Begegnung) oder 0.
	 */
	private static function require_cap( string $cap, int $id = 0 ): void {
		$allowed = $id > 0 ? current_user_can( $cap, $id ) : current_user_can( $cap );
		if ( ! $allowed ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Wohin es nach der Aktion geht: nur eines der festen Ziele, nie eine Adresse aus der Anfrage.
	 */
	private static function back_target(): string {
		$back = sanitize_key( wp_unslash( (string) ( $_POST['back'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce in der aufrufenden Aktion geprüft.
		return in_array( $back, array( self::BACK_ENTRY, self::BACK_AREA, self::BACK_TK ), true ) ? $back : self::BACK_ENTRY;
	}

	/**
	 * Rückmeldung ablegen und zurückleiten.
	 *
	 * @return never
	 */
	private static function back( string $back, int $fixture_id, Outcome $outcome ): never {
		$user = get_current_user_id();
		if ( self::BACK_TK === $back && current_user_can( Capabilities::TK ) ) {
			Flash::set( Tk::FLASH, $user, $outcome->type, $outcome->message, $outcome->details );
			wp_safe_redirect( Tk::overview_url( Views::TK_ANCHOR ) );
			exit;
		}
		Flash::set( self::FLASH, $user, $outcome->type, $outcome->message, $outcome->details );
		$url = self::BACK_ENTRY === $back && $fixture_id > 0
			? Results::entry_url( $fixture_id ) . '#' . Views::ENTRY_ANCHOR
			: LoginFlow::my_area_url() . '#' . Views::AREA_ANCHOR;
		wp_safe_redirect( $url );
		exit;
	}
}
