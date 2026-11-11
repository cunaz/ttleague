<?php
/**
 * Formularaktionen der Seite «Spielbetrieb › Anlässe» (admin-post.php).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\Outcome;
use TTLeague\Domain\Events\RegistrationRules as Rules;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\Results;
use TTLeague\Tk\Tk;

/**
 * Jede Aktion prüft Nonce und ttl_tk, ruft den Dienst auf, legt die Rückmeldung ab (Flash)
 * und leitet zurück. Die fachlichen Regeln prüfen EventService und RegistrationService.
 */
final class AdminActions {

	public const SAVE       = 'ttleague_event_save';
	public const STATUS     = 'ttleague_event_status';
	public const REGISTER   = 'ttleague_event_tk_register';
	public const UNREGISTER = 'ttleague_event_tk_unregister';

	/** Abgewiesene Eingabe eines Formulars, damit sie nach der Umleitung nicht verloren ist. */
	private const DRAFT     = 'ttl_event_form_';
	private const DRAFT_TTL = 300;

	private const FIELDS = array( 'season_id', 'type', 'title', 'starts_at', 'ends_at', 'location', 'deadline', 'max_participants', 'post' );

	/**
	 * Anlass anlegen (event 0) oder ändern.
	 */
	public static function save(): void {
		$event = isset( $_POST['event'] ) ? absint( $_POST['event'] ) : 0;
		check_admin_referer( self::SAVE . '_' . $event );
		self::require_tk();

		$values = array();
		foreach ( self::FIELDS as $field ) {
			$values[ $field ] = isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		$form = 0 === $event ? AdminPage::new_url() : AdminPage::url( $event, 'ttl-aendern' );

		$post_id = self::post_id( $values['post'] );
		if ( null === $post_id ) {
			self::keep_draft( $event, $values );
			self::back( $form, Outcome::error( __( 'Die Ausschreibung wurde nicht gefunden. Gib die Adresse oder die ID eines Beitrags oder einer Seite an.', 'ttleague' ) ) );
		}
		$input = array( 'post_id' => 0 === $post_id ? '' : (string) $post_id ) + $values;
		unset( $input['post'] );

		$service = Events::events();
		try {
			if ( 0 === $event ) {
				$id = $service->create( $input, Results::current_actor() );
				/* translators: %s: Titel des Anlasses */
				self::back( AdminPage::url( $id ), Outcome::success( sprintf( __( 'Anlass «%s» angelegt.', 'ttleague' ), $values['title'] ) ) );
			}
			$result = $service->update( $event, $input, Results::current_actor() );
		} catch ( EventException $e ) {
			self::keep_draft( $event, $values );
			self::back( 404 === $e->status ? AdminPage::url() : $form, self::failure( $e ) );
		}
		self::back( AdminPage::url( $event ), Outcome::success( __( 'Änderungen gespeichert.', 'ttleague' ), self::promoted( $result['promoted'] ) ) );
	}

	/**
	 * Anmeldung schliessen oder wieder öffnen, Anlass absagen.
	 */
	public static function status(): void {
		$event = isset( $_POST['event'] ) ? absint( $_POST['event'] ) : 0;
		check_admin_referer( self::STATUS . '_' . $event );
		self::require_tk();
		$decision = isset( $_POST['decision'] ) && is_string( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$url      = AdminPage::url( $event );
		if ( 'cancel' === $decision && empty( $_POST['confirm'] ) ) {
			self::back( AdminPage::url( $event, 'ttl-status' ), Outcome::error( __( 'Bitte bestätige die Absage mit dem Häkchen.', 'ttleague' ) ) );
		}

		$service = Events::events();
		$actor   = Results::current_actor();
		try {
			switch ( $decision ) {
				case 'close':
					$service->close( $event, $actor );
					$outcome = Outcome::success( __( 'Anmeldung geschlossen. Änderungen nimmt jetzt nur noch die TK vor.', 'ttleague' ) );
					break;
				case 'reopen':
					$service->reopen( $event, $actor );
					$outcome = Outcome::success( __( 'Anmeldung wieder geöffnet.', 'ttleague' ) );
					break;
				case 'cancel':
					$service->cancel( $event, $actor );
					$people = count( Events::repository()->registered_people( $event ) );
					/* translators: %d: Anzahl Personen */
					$outcome = Outcome::success( sprintf( _n( 'Anlass abgesagt. %d angemeldete Person erhält eine Mitteilung.', 'Anlass abgesagt. %d angemeldete Personen erhalten eine Mitteilung.', $people, 'ttleague' ), $people ) );
					break;
				default:
					$outcome = Outcome::error( __( 'Unbekannte Aktion.', 'ttleague' ) );
			}
		} catch ( EventException $e ) {
			self::back( 404 === $e->status ? AdminPage::url() : $url, self::failure( $e ) );
		}
		self::back( $url, $outcome );
	}

	/**
	 * Person von Hand anmelden.
	 */
	public static function register(): void {
		$event = isset( $_POST['event'] ) ? absint( $_POST['event'] ) : 0;
		check_admin_referer( self::REGISTER . '_' . $event );
		self::require_tk();
		$person = isset( $_POST['person'] ) ? absint( $_POST['person'] ) : 0;
		$note   = isset( $_POST['note'] ) && is_string( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		if ( 0 === $person ) {
			self::back( AdminPage::url( $event, 'ttl-anmelden' ), Outcome::error( __( 'Bitte wähle eine Person.', 'ttleague' ) ) );
		}
		try {
			$result = Events::registrations()->register( $event, $person, Results::current_actor(), $note );
		} catch ( EventException $e ) {
			self::back( 404 === $e->status && null === Events::repository()->find( $event ) ? AdminPage::url() : AdminPage::url( $event, 'ttl-anmelden' ), self::failure( $e ) );
		}
		$name = self::name( $person );
		self::back(
			AdminPage::url( $event, 'ttl-person-' . $person ),
			Rules::WAITLIST === $result['status']
				/* translators: %s: Name */
				? Outcome::warning( sprintf( __( 'Die Höchstzahl ist erreicht: %s steht auf der Warteliste.', 'ttleague' ), $name ) )
				/* translators: %s: Name */
				: Outcome::success( sprintf( __( '%s ist angemeldet.', 'ttleague' ), $name ) )
		);
	}

	/**
	 * Person von Hand abmelden.
	 */
	public static function unregister(): void {
		$event  = isset( $_POST['event'] ) ? absint( $_POST['event'] ) : 0;
		$person = isset( $_POST['person'] ) ? absint( $_POST['person'] ) : 0;
		check_admin_referer( self::UNREGISTER . '_' . $event . '_' . $person );
		self::require_tk();
		try {
			$result = Events::registrations()->unregister( $event, $person, Results::current_actor() );
		} catch ( EventException $e ) {
			self::back( 404 === $e->status ? AdminPage::url() : AdminPage::url( $event, 'ttl-teilnehmende' ), self::failure( $e ) );
		}
		/* translators: %s: Name */
		self::back( AdminPage::url( $event, 'ttl-teilnehmende' ), Outcome::success( sprintf( __( '%s ist abgemeldet.', 'ttleague' ), self::name( $person ) ), self::promoted( $result['promoted'] ) ) );
	}

	/**
	 * Abgewiesene Eingabe des Formulars für diesen Anlass (0: neuer Anlass); danach verworfen.
	 *
	 * @return array<string,string>|null
	 */
	public static function take_draft( int $user_id, int $event_id ): ?array {
		$draft = get_transient( self::DRAFT . $user_id );
		if ( ! is_array( $draft ) || ! is_array( $draft['values'] ?? null ) ) {
			return null;
		}
		delete_transient( self::DRAFT . $user_id );
		if ( (int) ( $draft['event'] ?? -1 ) !== $event_id ) {
			return null;
		}
		$values = array();
		foreach ( self::FIELDS as $field ) {
			$values[ $field ] = is_string( $draft['values'][ $field ] ?? null ) ? $draft['values'][ $field ] : '';
		}
		return $values;
	}

	/**
	 * Zeilen «Nachgerückt: …» für die Rückmeldung.
	 *
	 * @param list<int> $person_ids Nachgerückte Personen.
	 * @return list<string>
	 */
	public static function promoted( array $person_ids ): array {
		if ( array() === $person_ids ) {
			return array();
		}
		/* translators: %s: Namen */
		return array( sprintf( __( 'Von der Warteliste nachgerückt: %s', 'ttleague' ), implode( ', ', array_map( array( self::class, 'name' ), $person_ids ) ) ) );
	}

	/**
	 * ID des Beitrags der Ausschreibung: 0 ohne Angabe, null wenn es ihn nicht gibt.
	 *
	 * @param string $value Adresse oder ID.
	 */
	public static function post_id( string $value ): ?int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		$id   = 1 === preg_match( '/^\d{1,18}$/D', $value ) ? (int) $value : url_to_postid( esc_url_raw( $value ) );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || ! is_post_type_viewable( $post->post_type ) || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return null;
		}
		return (int) $post->ID;
	}

	private static function name( int $person_id ): string {
		$person = Events::repository()->person( $person_id );
		return null === $person ? '#' . $person_id : $person['name'];
	}

	/**
	 * @param array<string,string> $values Eingabe.
	 */
	private static function keep_draft( int $event_id, array $values ): void {
		set_transient(
			self::DRAFT . get_current_user_id(),
			array(
				'event'  => $event_id,
				'values' => $values,
			),
			self::DRAFT_TTL
		);
	}

	private static function failure( EventException $e ): Outcome {
		$details = count( $e->errors ) > 1 ? array_column( $e->errors, 'message' ) : array();
		return Outcome::error( $e->getMessage(), $details );
	}

	private static function require_tk(): void {
		if ( ! current_user_can( Capabilities::TK ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Rückmeldung ablegen und zurückleiten.
	 */
	private static function back( string $url, Outcome $outcome ): never {
		Flash::set( Tk::FLASH, get_current_user_id(), $outcome->type, $outcome->message, $outcome->details );
		wp_safe_redirect( $url );
		exit;
	}
}
