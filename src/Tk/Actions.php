<?php
/**
 * Formularaktionen im Backend der TK (admin-post.php): Entscheide, E-Mail-Adressen, Einladungen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tk;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\Outcome;
use TTLeague\Accounts\Services;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\ResultException;
use TTLeague\Results\Results;
use TTLeague\Results\ResultService;

/**
 * Jede Aktion prüft Nonce und ttl_tk, ruft den Dienst auf, legt die Rückmeldung ab (Flash)
 * und leitet zurück. Die fachlichen Regeln prüfen ResultService und ClubAccounts.
 */
final class Actions {

	/** Schnellaktionen des Dashboards (Entscheide nach docs/erfassung.md). */
	private const DECISIONS = array( 'confirm', 'finalize', 'forfeit' );

	public static function decide(): void {
		$fixture = isset( $_POST['fixture'] ) ? absint( $_POST['fixture'] ) : 0;
		check_admin_referer( Tk::DECIDE . '_' . $fixture );
		self::require_tk();

		$decision = sanitize_key( wp_unslash( (string) ( $_POST['decision'] ?? '' ) ) );
		$comment  = trim( sanitize_textarea_field( wp_unslash( (string) ( $_POST['comment'] ?? '' ) ) ) );
		$winner   = sanitize_key( wp_unslash( (string) ( $_POST['winner'] ?? '' ) ) );
		$version  = sanitize_text_field( wp_unslash( (string) ( $_POST['version'] ?? '' ) ) );

		if ( ! in_array( $decision, self::DECISIONS, true ) ) {
			self::back( Tk::overview_url(), Outcome::error( __( 'Unbekannte Entscheidung.', 'ttleague' ) ) );
		}
		if ( 'forfeit' === $decision && '' === $comment ) {
			self::back( Tk::overview_url(), Outcome::error( __( 'Bitte begründe das Forfait in der Bemerkung.', 'ttleague' ) ) );
		}

		$service = Results::service();
		try {
			$ctx    = $service->context( $fixture );
			$result = $service->decide(
				$fixture,
				Results::current_actor(),
				array(
					'action'  => $decision,
					'winner'  => '' === $winner ? null : $winner,
					'comment' => '' === $comment ? null : $comment,
					'version' => $version,
				)
			);
		} catch ( ResultException $e ) {
			self::back( Tk::overview_url(), Outcome::error( $e->getMessage(), array_column( $e->errors, 'message' ) ) );
		}

		$name  = $ctx->home['name'] . ' – ' . $ctx->away['name'];
		$score = isset( $result['home_score'], $result['away_score'] ) ? ' (' . $result['home_score'] . ':' . $result['away_score'] . ')' : '';
		$label = match ( $decision ) {
			'confirm'  => __( 'bestätigt', 'ttleague' ),
			'finalize' => __( 'abgeschlossen', 'ttleague' ),
			/* translators: %s: Team */
			default    => sprintf( __( 'forfait für %s gewertet', 'ttleague' ), 'home' === $winner ? $ctx->home['name'] : $ctx->away['name'] ),
		};
		/* translators: 1: Begegnung, 2: Entscheid, 3: Resultat in Klammern oder leer, 4: Status */
		self::back( Tk::overview_url(), Outcome::success( sprintf( __( '%1$s: %2$s%3$s. Status jetzt «%4$s».', 'ttleague' ), $name, $label, $score, ResultService::status_label( (string) $result['status'] ) ) ) );
	}

	public static function email(): void {
		$person = isset( $_POST['person'] ) ? absint( $_POST['person'] ) : 0;
		check_admin_referer( Tk::EMAIL . '_' . $person );
		self::require_tk();
		$club  = isset( $_POST['club'] ) ? absint( $_POST['club'] ) : 0;
		$email = sanitize_text_field( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) );
		self::back( Tk::accounts_url( $club, 'ttl-person-' . $person ), Services::club_accounts()->change_email( Results::current_actor(), $person, $email ) );
	}

	public static function invite(): void {
		$person = isset( $_POST['person'] ) ? absint( $_POST['person'] ) : 0;
		check_admin_referer( Tk::INVITE . '_' . $person );
		self::require_tk();
		$club = isset( $_POST['club'] ) ? absint( $_POST['club'] ) : 0;
		self::back( Tk::accounts_url( $club, 'ttl-person-' . $person ), Services::club_accounts()->invite( Results::current_actor(), $person ) );
	}

	public static function invite_officials(): void {
		$club = isset( $_POST['club'] ) ? absint( $_POST['club'] ) : 0;
		check_admin_referer( Tk::INVITE_OFFICIALS . '_' . $club );
		self::require_tk();
		self::back( Tk::accounts_url( $club ), Services::club_accounts()->invite_officials( Results::current_actor(), $club ) );
	}

	private static function require_tk(): void {
		if ( ! current_user_can( Capabilities::TK ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Rückmeldung ablegen und zurückleiten.
	 *
	 * @return never
	 */
	private static function back( string $url, Outcome $outcome ): never {
		Flash::set( Tk::FLASH, get_current_user_id(), $outcome->type, $outcome->message, $outcome->details );
		wp_safe_redirect( $url );
		exit;
	}
}
