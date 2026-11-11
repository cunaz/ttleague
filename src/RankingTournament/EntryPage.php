<?php
/**
 * Seite zum Nachtragen des Gruppenzettels.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\LoginFlow;
use TTLeague\Accounts\Outcome;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\EntryBlock;
use TTLeague\Results\EntryMarkup;
use TTLeague\Results\ResultException;
use TTLeague\Results\Results;

/**
 * Der Gruppenzettel erscheint auf der Seite «Resultat erfassen» (Block ttleague/result-entry),
 * wenn die Adresse ?rl-gruppe={id} trägt. So gelten dieselben Kopfzeilen wie für die Erfassung
 * der Meisterschaft (noindex, keine Zwischenspeicherung), und es braucht keine weitere Seite.
 *
 * Das Formular ist serverseitig gerendert und geht ohne JavaScript an admin-post.php (Nonce pro
 * Gruppe, Recht ttl_enter_rl_group). Gespeichert wird über GroupEntry wie in der REST-API. Nach
 * einem Fehler bleiben die Eingaben erhalten (Transient, zehn Minuten, pro Konto und Gruppe).
 */
final class EntryPage {

	public const ACTION = 'ttleague_rl_entry';

	/** Sekunden, die abgelehnte Eingaben für die nächste Ansicht aufbewahrt werden. */
	private const DRAFT_TTL = 600;

	public static function register(): void {
		add_filter( 'pre_render_block', array( self::class, 'intercept' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle_guest' ) );
	}

	/**
	 * Filter pre_render_block: ersetzt den Block der Erfassungsseite, wenn eine Gruppe gewählt ist.
	 *
	 * @param string|null         $pre   Bisherige Ausgabe.
	 * @param array<string,mixed> $block Geparster Block.
	 */
	public static function intercept( $pre, $block ) {
		$group_id = self::requested_group();
		if ( null !== $pre || 0 === $group_id || 'ttleague/result-entry' !== ( $block['blockName'] ?? '' ) ) {
			return $pre;
		}
		wp_enqueue_style( EntryBlock::STYLE );
		return '<div class="wp-block-ttleague-result-entry ttl ttl-result-entry ttl-rl-entry">' . self::content( $group_id ) . '</div>';
	}

	/**
	 * Inhalt der Seite je nach Anmeldung und Recht.
	 */
	public static function content( int $group_id ): string {
		$url = RankingTournament::entry_url( $group_id );
		if ( ! is_user_logged_in() ) {
			return '<div class="ttl-entry ttl-entry--gate">'
				. '<p class="ttl-notice">' . esc_html__( 'Den Gruppenzettel trägt der Gruppenchef nach, ersatzweise der TK-Chef seines Clubs oder des Platzclubs. Bitte melde dich an.', 'ttleague' ) . '</p>'
				. '<p class="ttl-actions"><a class="ttl-button ttl-button--primary" href="' . esc_url( LoginFlow::page_url( array( 'redirect_to' => $url ) ) ) . '">' . esc_html__( 'Anmelden', 'ttleague' ) . '</a></p></div>';
		}
		$my_area = array( __( 'Zu meinem Bereich', 'ttleague' ) => LoginFlow::my_area_url() );
		try {
			$entry = RankingTournament::entry()->entry( $group_id, Results::current_actor() );
		} catch ( ResultException $e ) {
			return EntryMarkup::notice( $e->getMessage(), $my_area );
		} catch ( \InvalidArgumentException $e ) {
			return EntryMarkup::notice( __( 'Die Regeln der Saison sind ungültig. Bitte wende dich an die TK.', 'ttleague' ), $my_area );
		}

		$user  = get_current_user_id();
		$flash = Flash::take( self::scope( $group_id ), $user );
		$draft = get_transient( self::draft_key( $group_id, $user ) );
		$draft = is_array( $draft ) ? $draft : null;
		if ( null !== $draft ) {
			delete_transient( self::draft_key( $group_id, $user ) );
		}
		$out = '<div class="ttl-entry">' . SheetForm::head( $entry ) . self::notice( $flash );
		if ( $entry['can']['enter'] ) {
			$hidden = '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
				. '<input type="hidden" name="group" value="' . esc_attr( (string) $group_id ) . '">'
				. wp_nonce_field( self::ACTION . '_' . $group_id, '_wpnonce', true, false );
			if ( null !== $entry['entered_at'] ) {
				$out .= SheetForm::result( $entry );
				/* translators: %s: Name */
				$out .= '<p class="ttl-muted">' . esc_html( null === $entry['entered_by'] ? __( 'Der Gruppenzettel ist erfasst. Du kannst ihn ändern, bis die TK die Runde abschliesst.', 'ttleague' ) : sprintf( __( 'Erfasst von %s. Du kannst den Gruppenzettel ändern, bis die TK die Runde abschliesst.', 'ttleague' ), $entry['entered_by'] ) ) . '</p>';
			}
			$out .= SheetForm::form( $entry, admin_url( 'admin-post.php' ), $hidden, $draft );
		} else {
			$out .= '<p class="ttl-notice" role="status">' . esc_html(
				Rounds::CLOSED === $entry['group']['status']
					? __( 'Die Runde ist abgeschlossen. Der Gruppenzettel lässt sich nicht mehr ändern.', 'ttleague' )
					: __( 'Die Runde ist noch nicht freigegeben. Der Gruppenzettel lässt sich erst danach erfassen.', 'ttleague' )
			) . '</p>' . SheetForm::result( $entry );
		}
		return $out . '<p class="ttl-actions"><a href="' . esc_url( LoginFlow::my_area_url() ) . '">' . esc_html__( 'Zu meinem Bereich', 'ttleague' ) . '</a></p></div>';
	}

	/**
	 * Formular speichern: Nonce und Recht prüfen, Dienst aufrufen, zurück auf die Seite.
	 */
	public static function handle(): void {
		$group_id = isset( $_POST['group'] ) ? absint( $_POST['group'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $group_id );
		if ( ! current_user_can( Capabilities::ENTER_RL, $group_id ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
		$post = self::fields();
		$user = get_current_user_id();
		try {
			RankingTournament::entry()->save( $group_id, SheetForm::payload( $post ), Results::current_actor() );
			$outcome = Outcome::success( __( 'Gruppenzettel gespeichert. Rang und Punkte stehen unten zur Kontrolle.', 'ttleague' ) );
		} catch ( ResultException $e ) {
			// Nur bei ungültiger Eingabe bleiben die Felder stehen. Hat sich die Gruppe inzwischen
			// geändert (409), zeigt die Seite den neuen Stand.
			if ( 422 === $e->status ) {
				set_transient( self::draft_key( $group_id, $user ), $post, self::DRAFT_TTL );
			}
			$outcome = Outcome::error( $e->getMessage(), count( $e->errors ) > 1 ? array_column( $e->errors, 'message' ) : array() );
		} catch ( \InvalidArgumentException $e ) {
			$outcome = Outcome::error( __( 'Die Regeln der Saison sind ungültig. Bitte wende dich an die TK.', 'ttleague' ) );
		}
		Flash::set( self::scope( $group_id ), $user, $outcome->type, $outcome->message, $outcome->details );
		wp_safe_redirect( RankingTournament::entry_url( $group_id ) );
		exit;
	}

	/**
	 * Abgemeldet abgeschickt (z. B. abgelaufene Anmeldung): zur Anmeldung, danach zurück zur Gruppe.
	 */
	public static function handle_guest(): void {
		$group_id = isset( $_POST['group'] ) ? absint( $_POST['group'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- es wird nichts gespeichert.
		wp_safe_redirect( LoginFlow::page_url( array( 'redirect_to' => RankingTournament::entry_url( $group_id ) ) ) );
		exit;
	}

	/**
	 * Gruppe aus der Adresse (0 = keine).
	 */
	public static function requested_group(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur lesend; Rechte prüft GroupEntry.
		$raw = isset( $_GET[ RankingTournament::PARAM ] ) && is_string( $_GET[ RankingTournament::PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ RankingTournament::PARAM ] ) ) : '';
		return 1 === preg_match( '/^[1-9][0-9]{0,9}$/', $raw ) ? (int) $raw : 0;
	}

	/**
	 * Bereinigte Formularfelder (Nonce in handle() geprüft).
	 *
	 * @return array<string,mixed>
	 */
	private static function fields(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- in handle() geprüft.
		$clean = static function ( mixed $value ) use ( &$clean ): mixed {
			if ( is_array( $value ) ) {
				$result = array();
				foreach ( $value as $key => $item ) {
					$result[ absint( $key ) ] = $clean( $item );
				}
				return $result;
			}
			return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		};
		$post  = array();
		foreach ( array( 'status', 'result', 'points', 'next' ) as $key ) {
			$post[ $key ] = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? $clean( wp_unslash( $_POST[ $key ] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $clean bereinigt jeden Wert.
		}
		$post['version']   = isset( $_POST['version'] ) && is_string( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
		$post['next_take'] = isset( $_POST['next_take'] ) ? '1' : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return $post;
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

	private static function scope( int $group_id ): string {
		return 'rl_entry_' . $group_id;
	}

	private static function draft_key( int $group_id, int $user_id ): string {
		return 'ttl_rl_draft_' . $group_id . '_' . $user_id;
	}
}
