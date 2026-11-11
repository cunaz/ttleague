<?php
/**
 * Formularaktionen des Mannschaftscups (admin-post.php): Meldung in «Mein Bereich», TK im Backend.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cup;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\LoginFlow;
use TTLeague\Accounts\Outcome;
use TTLeague\Frontend\Support\Format;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\Actor;
use TTLeague\Results\ResultException;
use TTLeague\Results\Results;
use TTLeague\Tk\Tk;

/**
 * Jede Aktion prüft ihren Nonce (pro Aktion und Objekt) und das Recht: ttl_manage_club für den
 * Club bei Meldung und Rückzug, sonst ttl_tk. Danach ruft sie den Dienst auf, der Saison,
 * Meldeschluss und Stand der Auslosung selbst prüft, legt die Rückmeldung ab (Flash) und leitet
 * zurück: in «Mein Bereich», auf die Seite «Cup» oder in die Übersicht der TK. Das Ziel ist
 * immer eines der festen Ziele, nie eine Adresse aus der Anfrage.
 */
final class Forms {

	public const REGISTER       = 'ttleague_cup_register';
	public const WITHDRAW       = 'ttleague_cup_withdraw';
	public const CONFIGURE      = 'ttleague_cup_configure';
	public const SEEDS          = 'ttleague_cup_seeds';
	public const PROPOSE        = 'ttleague_cup_propose';
	public const CHANGE         = 'ttleague_cup_change';
	public const RELEASE        = 'ttleague_cup_release';
	public const ROUND_DEADLINE = 'ttleague_cup_round_deadline';
	public const TIE_DEADLINE   = 'ttleague_cup_tie_deadline';
	public const SCHEDULE       = 'ttleague_cup_schedule';
	public const SWAP_HOME      = 'ttleague_cup_swap_home';

	/** Ziel nach der Aktion: «Mein Bereich», Seite «Cup» im Backend oder Übersicht der TK. */
	public const BACK_AREA  = 'area';
	public const BACK_ADMIN = 'admin';
	public const BACK_TK    = 'tk';

	/** Aktion => Methode. */
	public const HANDLERS = array(
		self::REGISTER       => 'register_entry',
		self::WITHDRAW       => 'withdraw',
		self::CONFIGURE      => 'configure',
		self::SEEDS          => 'seeds',
		self::PROPOSE        => 'propose',
		self::CHANGE         => 'change',
		self::RELEASE        => 'release',
		self::ROUND_DEADLINE => 'round_deadline',
		self::TIE_DEADLINE   => 'tie_deadline',
		self::SCHEDULE       => 'schedule',
		self::SWAP_HOME      => 'swap_home',
	);

	public static function register(): void {
		foreach ( self::HANDLERS as $action => $method ) {
			add_action( 'admin_post_' . $action, array( self::class, $method ) );
		}
	}

	/**
	 * Bereich der Rückmeldungen eines Clubs in «Mein Bereich» (Flash).
	 */
	public static function flash_scope( int $club_id ): string {
		return 'cup_' . $club_id;
	}

	/**
	 * Cupmannschaft melden (TK-Chef in «Mein Bereich», TK im Backend).
	 */
	public static function register_entry(): void {
		$competition = self::int( 'competition' );
		$club        = self::int( 'club' );
		check_admin_referer( self::REGISTER . '_' . $competition . '_' . $club );
		self::require_cap( Capabilities::MANAGE_CLUB, $club );
		$first  = self::int( 'player1' );
		$second = self::int( 'player2' );
		self::finish(
			$competition,
			$club,
			static function ( Actor $actor ) use ( $competition, $club, $first, $second ): string {
				$entry = Cup::entries()->register( $competition, $club, $actor, $first, $second );
				/* translators: 1: Cupmannschaft, 2: Punktesumme */
				return sprintf( __( '%1$s ist gemeldet (Punktesumme %2$d).', 'ttleague' ), $entry['name'], (int) $entry['points_total'] );
			}
		);
	}

	/**
	 * Cupmannschaft zurückziehen.
	 */
	public static function withdraw(): void {
		$entry_id = self::int( 'entry' );
		check_admin_referer( self::WITHDRAW . '_' . $entry_id );
		$entry = Cup::repository()->entry( $entry_id );
		$club  = null === $entry ? 0 : (int) $entry['club_id'];
		// Ohne Meldung gibt es keinen Club: Dann darf nur die TK die Meldung «nicht gefunden» sehen.
		self::require_cap( $club > 0 ? Capabilities::MANAGE_CLUB : Capabilities::TK, $club );
		self::finish(
			null === $entry ? 0 : (int) $entry['competition_id'],
			$club,
			static function ( Actor $actor ) use ( $entry_id ): string {
				$result = Cup::entries()->withdraw( $entry_id, $actor );
				return $result['deleted']
					/* translators: %s: Cupmannschaft */
					? sprintf( __( 'Die Meldung von %s ist gelöscht.', 'ttleague' ), $result['entry']['name'] )
					/* translators: %s: Cupmannschaft */
					: sprintf( __( '%s ist zurückgezogen. Der Gegner kommt kampflos weiter.', 'ttleague' ), $result['entry']['name'] );
			}
		);
	}

	/**
	 * Punktelimite, Meldeschluss und Mindestzahl der Meldungen (TK).
	 */
	public static function configure(): void {
		$competition = self::tk_request( self::CONFIGURE, 'competition' );
		$payload     = array(
			'points_limit'          => self::text( 'points_limit' ),
			'registration_deadline' => self::text( 'registration_deadline' ),
			'min_entries'           => self::text( 'min_entries' ),
		);
		self::finish(
			$competition,
			0,
			static function ( Actor $actor ) use ( $competition, $payload ): string {
				Cup::entries()->configure( $competition, $actor, $payload );
				return __( 'Die Einstellungen sind gespeichert.', 'ttleague' );
			}
		);
	}

	/**
	 * Setznummern (TK).
	 */
	public static function seeds(): void {
		$competition = self::tk_request( self::SEEDS, 'competition' );
		$seeds       = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce in tk_request() geprüft; jeder Wert wird einzeln bereinigt.
		foreach ( (array) wp_unslash( $_POST['seed'] ?? array() ) as $entry_id => $value ) {
			$seeds[ absint( $entry_id ) ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}
		self::finish(
			$competition,
			0,
			static function ( Actor $actor ) use ( $competition, $seeds ): string {
				Cup::draw()->set_seeds( $competition, $actor, $seeds );
				return __( 'Die Setzung ist gespeichert. Ein bestehender Vorschlag der Auslosung ist verworfen.', 'ttleague' );
			}
		);
	}

	/**
	 * Auslosung vorschlagen (TK).
	 */
	public static function propose(): void {
		$competition = self::tk_request( self::PROPOSE, 'competition' );
		$seed        = self::text( 'seed' );
		self::finish(
			$competition,
			0,
			static function ( Actor $actor ) use ( $competition, $seed ): string {
				$overview = Cup::draw()->propose( $competition, $actor, '' === $seed ? null : absint( $seed ) );
				/* translators: %d: Startwert */
				return sprintf( __( 'Die erste Runde ist ausgelost (Startwert %d). Prüfe die Paarungen und gib sie frei.', 'ttleague' ), (int) $overview['proposal']['seed'] );
			}
		);
	}

	/**
	 * Paarungen und Heimrecht des Vorschlags ändern (TK).
	 */
	public static function change(): void {
		$competition = self::tk_request( self::CHANGE, 'competition' );
		$positions   = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce in tk_request() geprüft; jeder Wert wird zur Ganzzahl.
		foreach ( array_values( (array) wp_unslash( $_POST['positions'] ?? array() ) ) as $value ) {
			$positions[] = is_scalar( $value ) ? absint( $value ) : 0;
		}
		self::finish(
			$competition,
			0,
			static function ( Actor $actor ) use ( $competition, $positions ): string {
				Cup::draw()->change( $competition, $actor, $positions );
				return __( 'Die Paarungen sind gespeichert.', 'ttleague' );
			}
		);
	}

	/**
	 * Auslosung freigeben (TK).
	 */
	public static function release(): void {
		$competition = self::tk_request( self::RELEASE, 'competition' );
		$deadline    = self::text( 'deadline' );
		self::finish(
			$competition,
			0,
			static function ( Actor $actor ) use ( $competition, $deadline ): string {
				$result = Cup::draw()->release( $competition, $actor, $deadline );
				/* translators: %d: Anzahl Begegnungen */
				return sprintf( _n( 'Die Auslosung ist freigegeben: %d Begegnung angelegt. Die Mannschaften erhalten eine Nachricht.', 'Die Auslosung ist freigegeben: %d Begegnungen angelegt. Die Mannschaften erhalten eine Nachricht.', count( $result['fixtures'] ), 'ttleague' ), count( $result['fixtures'] ) );
			}
		);
	}

	/**
	 * Frist einer Runde setzen (TK).
	 */
	public static function round_deadline(): void {
		$competition = self::int( 'competition' );
		$round       = self::int( 'round' );
		check_admin_referer( self::ROUND_DEADLINE . '_' . $competition . '_' . $round );
		self::require_cap( Capabilities::TK );
		$deadline = self::text( 'deadline' );
		self::finish(
			$competition,
			0,
			static function ( Actor $actor ) use ( $competition, $round, $deadline ): string {
				$result = Cup::bracket()->set_round_deadline( $competition, $round, $deadline, $actor );
				/* translators: %s: Datum */
				return sprintf( __( 'Die Frist der Runde ist der %s.', 'ttleague' ), Format::date_plain( $result['deadline'] ) );
			}
		);
	}

	/**
	 * Frist einer Paarung setzen oder verlängern (TK).
	 */
	public static function tie_deadline(): void {
		$tie      = self::tk_request( self::TIE_DEADLINE, 'tie' );
		$deadline = self::text( 'deadline' );
		self::finish(
			self::competition_of_tie( $tie ),
			0,
			static function ( Actor $actor ) use ( $tie, $deadline ): string {
				$result = Cup::bracket()->set_tie_deadline( $tie, $deadline, $actor );
				/* translators: %s: Datum */
				return sprintf( __( 'Die Frist der Paarung ist der %s.', 'ttleague' ), Format::date_plain( $result['deadline'] ) );
			}
		);
	}

	/**
	 * Halbfinal oder Final ansetzen (TK).
	 */
	public static function schedule(): void {
		$tie     = self::tk_request( self::SCHEDULE, 'tie' );
		$payload = array(
			'scheduled_at' => self::text( 'scheduled_at' ),
			'venue_id'     => self::text( 'venue_id' ),
		);
		self::finish(
			self::competition_of_tie( $tie ),
			0,
			static function ( Actor $actor ) use ( $tie, $payload ): string {
				$result = Cup::bracket()->schedule( $tie, $actor, $payload );
				/* translators: %s: Datum und Zeit */
				return sprintf( __( 'Die Begegnung ist angesetzt: %s. Beide Mannschaften erhalten eine Nachricht.', 'ttleague' ), Format::datetime( $result['scheduled_at'] ) );
			}
		);
	}

	/**
	 * Heimrecht einer Begegnung tauschen (TK).
	 */
	public static function swap_home(): void {
		$tie = self::tk_request( self::SWAP_HOME, 'tie' );
		self::finish(
			self::competition_of_tie( $tie ),
			0,
			static function ( Actor $actor ) use ( $tie ): string {
				Cup::bracket()->swap_home( $tie, $actor );
				return __( 'Das Heimrecht ist getauscht. Beide Mannschaften erhalten eine Nachricht.', 'ttleague' );
			}
		);
	}

	/**
	 * Aktion der TK auf einem Objekt: Nonce (pro Aktion und Objekt) und ttl_tk prüfen.
	 *
	 * @return int ID des Objekts.
	 */
	private static function tk_request( string $action, string $field ): int {
		$id = self::int( $field );
		check_admin_referer( $action . '_' . $id );
		self::require_cap( Capabilities::TK );
		return $id;
	}

	private static function competition_of_tie( int $tie_id ): int {
		$tie = Cup::repository()->tie( $tie_id );
		return null === $tie ? 0 : $tie['competition_id'];
	}

	/**
	 * Dienst aufrufen, Rückmeldung ablegen, zurückleiten.
	 *
	 * @param int                   $competition_id Konkurrenz (Ziel im Backend).
	 * @param int                   $club_id        Club (Ziel in «Mein Bereich») oder 0.
	 * @param callable(Actor):string $work          Aufruf des Dienstes; liefert die Erfolgsmeldung.
	 * @return never
	 */
	private static function finish( int $competition_id, int $club_id, callable $work ): never {
		try {
			$outcome = Outcome::success( $work( Results::current_actor() ) );
		} catch ( ResultException $e ) {
			$outcome = Outcome::error( $e->getMessage(), count( $e->errors ) > 1 ? array_column( $e->errors, 'message' ) : array() );
		}
		// Rollen aus Cup-Meldungen und Seiten von Begegnungen können sich geändert haben.
		Capabilities::policy()->reset();

		$user = get_current_user_id();
		$back = sanitize_key( wp_unslash( (string) ( $_POST['back'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce in der aufrufenden Aktion geprüft.
		$tk   = current_user_can( Capabilities::TK );
		if ( self::BACK_TK === $back && $tk ) {
			Flash::set( Tk::FLASH, $user, $outcome->type, $outcome->message, $outcome->details );
			wp_safe_redirect( Tk::overview_url( AdminPage::SECTION_OVERDUE ) );
			exit;
		}
		if ( ( self::BACK_ADMIN === $back || 0 === $club_id ) && $tk ) {
			Flash::set( Tk::FLASH, $user, $outcome->type, $outcome->message, $outcome->details );
			wp_safe_redirect( AdminPage::url( $competition_id ) );
			exit;
		}
		Flash::set( self::flash_scope( $club_id ), $user, $outcome->type, $outcome->message, $outcome->details );
		wp_safe_redirect( LoginFlow::my_area_url() . '#' . MyAreaSection::anchor( $club_id ) );
		exit;
	}

	/**
	 * @param string $cap Capability.
	 * @param int    $id  Objekt (Club) oder 0.
	 */
	private static function require_cap( string $cap, int $id = 0 ): void {
		$allowed = $id > 0 ? current_user_can( $cap, $id ) : current_user_can( $cap );
		if ( ! $allowed ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
	}

	private static function int( string $field ): int {
		return isset( $_POST[ $field ] ) ? absint( $_POST[ $field ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce in der aufrufenden Aktion geprüft.
	}

	private static function text( string $field ): string {
		$value = $_POST[ $field ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce in der aufrufenden Aktion geprüft; unten bereinigt.
		return is_scalar( $value ) ? trim( sanitize_text_field( wp_unslash( (string) $value ) ) ) : '';
	}
}
