<?php
/**
 * Formularaktionen der Klassierung im Backend der TK (admin-post.php).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Classification;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\Outcome;
use TTLeague\Domain\Rating\ClassificationScale;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\Actor;
use TTLeague\Results\Results;
use TTLeague\Tk\Tk;

/**
 * Jede Aktion prüft Nonce und ttl_tk, ruft den ProposalService auf, legt die Rückmeldung ab
 * (Flash) und leitet auf die Seite mit dem bisherigen Filter zurück. Die fachlichen Regeln
 * prüft der Dienst.
 */
final class Actions {

	public static function compute(): void {
		check_admin_referer( Classification::COMPUTE );
		self::require_tk();
		self::run(
			'',
			static function ( ProposalService $service, Actor $actor ): Outcome {
				$result = $service->compute( $actor );
				/* translators: 1: Anzahl Spieler, 2: neue Zeilen, 3: aktualisierte Zeilen */
				$message = sprintf( __( 'Vorschlag berechnet: %1$d Spieler, davon %2$d neu und %3$d aktualisiert.', 'ttleague' ), $result['players'], $result['created'], $result['updated'] );
				$details = array();
				if ( $result['refreshed'] ) {
					$details[] = __( 'Zuvor wurde die ausstehende Neuberechnung der Wertungszahl ausgeführt.', 'ttleague' );
				}
				if ( $result['removed'] > 0 ) {
					/* translators: %d: Anzahl Zeilen */
					$details[] = sprintf( _n( '%d offene Zeile eines nicht mehr gemeldeten Spielers entfernt.', '%d offene Zeilen nicht mehr gemeldeter Spieler entfernt.', $result['removed'], 'ttleague' ), $result['removed'] );
				}
				if ( $result['reset'] > 0 ) {
					/* translators: %d: Anzahl Entscheide */
					$details[] = sprintf( _n( '%d übernommener Entscheid zurückgesetzt, weil sich der Vorschlag geändert hat. Die Zeile ist wieder offen (Filter «noch offen»).', '%d übernommene Entscheide zurückgesetzt, weil sich der Vorschlag geändert hat. Die Zeilen sind wieder offen (Filter «noch offen»).', $result['reset'], 'ttleague' ), $result['reset'] );
					return Outcome::warning( $message, $details );
				}
				return Outcome::success( $message, $details );
			}
		);
	}

	public static function accept(): void {
		check_admin_referer( Classification::ACCEPT );
		self::require_tk();
		$id       = isset( $_POST['proposal'] ) ? absint( $_POST['proposal'] ) : 0;
		$expected = isset( $_POST['proposed'] ) ? absint( $_POST['proposed'] ) : null;
		self::run(
			self::anchor( $id ),
			static function ( ProposalService $service, Actor $actor ) use ( $id, $expected ): Outcome {
				$row = $service->accept( $actor, $id, $expected );
				/* translators: 1: Name, 2: Klassierung */
				return Outcome::success( sprintf( __( '%1$s: Vorschlag %2$s übernommen.', 'ttleague' ), $row['name'], ClassificationScale::label( $row['decided'] ) ) );
			}
		);
	}

	public static function accept_open(): void {
		check_admin_referer( Classification::ACCEPT_OPEN );
		self::require_tk();
		$filter = self::filter();
		self::run(
			'',
			static function ( ProposalService $service, Actor $actor ) use ( $filter ): Outcome {
				$count = $service->accept_open( $actor, $filter );
				if ( 0 === $count ) {
					return Outcome::info( __( 'Zu diesem Filter gibt es keine Zeilen ohne Entscheid.', 'ttleague' ) );
				}
				/* translators: %d: Anzahl Zeilen */
				return Outcome::success( sprintf( _n( 'Vorschlag für %d Zeile übernommen.', 'Vorschlag für %d Zeilen übernommen.', $count, 'ttleague' ), $count ) );
			}
		);
	}

	public static function override(): void {
		check_admin_referer( Classification::OVERRIDE );
		self::require_tk();
		$id    = isset( $_POST['proposal'] ) ? absint( $_POST['proposal'] ) : 0;
		$value = isset( $_POST['value'] ) ? absint( $_POST['value'] ) : 0;
		$note  = sanitize_textarea_field( wp_unslash( (string) ( $_POST['note'] ?? '' ) ) );
		self::run(
			self::anchor( $id ),
			static function ( ProposalService $service, Actor $actor ) use ( $id, $value, $note ): Outcome {
				$row = $service->override( $actor, $id, $value, $note );
				/* translators: 1: Name, 2: entschiedene Klassierung, 3: vorgeschlagene Klassierung */
				return Outcome::success( sprintf( __( '%1$s: %2$s entschieden (Vorschlag %3$s).', 'ttleague' ), $row['name'], ClassificationScale::label( $row['decided'] ), ClassificationScale::label( $row['proposed'] ) ) );
			}
		);
	}

	public static function close(): void {
		check_admin_referer( Classification::CLOSE );
		self::require_tk();
		self::run(
			'',
			static function ( ProposalService $service, Actor $actor ): Outcome {
				$service->close( $actor );
				return Outcome::success( __( 'Klassierung abgeschlossen. Die Werte sind gesperrt und gelten für die nächste Meldung.', 'ttleague' ) );
			}
		);
	}

	public static function reopen(): void {
		check_admin_referer( Classification::REOPEN );
		self::require_tk();
		$note = sanitize_textarea_field( wp_unslash( (string) ( $_POST['note'] ?? '' ) ) );
		self::run(
			'',
			static function ( ProposalService $service, Actor $actor ) use ( $note ): Outcome {
				$service->reopen( $actor, $note );
				return Outcome::success( __( 'Klassierung wieder geöffnet. Entscheide lassen sich ändern.', 'ttleague' ) );
			}
		);
	}

	private static function require_tk(): void {
		if ( ! current_user_can( Capabilities::TK ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Filter der Seite aus den versteckten Feldern des Formulars. Erst nach der Nonce-Prüfung aufrufen.
	 */
	private static function filter(): ProposalFilter {
		$args = array();
		foreach ( array( 'club', 'deviating', 'open' ) as $key ) {
			$args[ $key ] = sanitize_key( wp_unslash( (string) ( $_POST[ $key ] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce in der aufrufenden Aktion geprüft.
		}
		return ProposalFilter::from_array( $args );
	}

	private static function anchor( int $proposal_id ): string {
		return $proposal_id > 0 ? Page::row_id( $proposal_id ) : '';
	}

	/**
	 * Dienst aufrufen, Rückmeldung ablegen und zur Seite zurückleiten.
	 *
	 * @param string                                  $anchor Sprungmarke der Zeile oder leer.
	 * @param callable(ProposalService,Actor):Outcome $work   Aufruf des Dienstes mit Erfolgsmeldung.
	 * @return never
	 */
	private static function run( string $anchor, callable $work ): never {
		try {
			$outcome = $work( Classification::service(), Results::current_actor() );
		} catch ( ClassificationException $e ) {
			$outcome = Outcome::error( $e->getMessage() );
		}
		Flash::set( Tk::FLASH, get_current_user_id(), $outcome->type, $outcome->message, $outcome->details );
		wp_safe_redirect( Tk::page_url( Classification::PAGE, self::filter()->to_args() ) . ( '' === $anchor ? '' : '#' . $anchor ) );
		exit;
	}
}
