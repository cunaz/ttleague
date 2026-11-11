<?php
/**
 * Formularaktionen des Saison-Assistenten (admin-post.php).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Season;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\Outcome;
use TTLeague\Accounts\Services;
use TTLeague\Domain\Season\LifecycleRules;
use TTLeague\Domain\Season\RulesInput;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\Actor;
use TTLeague\Results\Results;
use TTLeague\Tk\Tk;

/**
 * Jede Aktion prüft ihren Nonce (pro Aktion und Datensatz: Saison, Gruppe oder Begegnung) und
 * ttl_tk, ruft den Dienst auf, der die fachlichen Regeln selbst prüft, legt die Rückmeldung ab
 * (Flash) und leitet auf den Schritt der Seite «Saison» zurück. Formulare mit Fehlern am Feld
 * (Saison anlegen, Regeln, Meldefenster, Gruppe) merken sich Eingabe und Fehler für die nächste
 * Ansicht (recall()).
 */
final class SeasonActions {

	public const CREATE         = 'ttleague_season_create';
	public const DELETE         = 'ttleague_season_delete';
	public const RULES          = 'ttleague_season_rules';
	public const WINDOW         = 'ttleague_season_window';
	public const CLUB_ADD       = 'ttleague_season_club_add';
	public const CLUB_REMOVE    = 'ttleague_season_club_remove';
	public const GROUP_CREATE   = 'ttleague_season_group_create';
	public const GROUP_RENAME   = 'ttleague_season_group_rename';
	public const GROUP_DELETE   = 'ttleague_season_group_delete';
	public const ASSIGN         = 'ttleague_season_assign';
	public const PROPOSAL       = 'ttleague_season_proposal';
	public const COPY           = 'ttleague_season_copy';
	public const WEEKS          = 'ttleague_season_weeks';
	public const GENERATE       = 'ttleague_season_generate';
	public const CLEAR          = 'ttleague_season_clear';
	public const MOVE           = 'ttleague_season_move';
	public const SWAP           = 'ttleague_season_swap';
	public const SUGGESTION     = 'ttleague_season_suggestion';
	public const FIXTURE_ADD    = 'ttleague_season_fixture_add';
	public const FIXTURE_CANCEL = 'ttleague_season_fixture_cancel';
	public const PUBLISH        = 'ttleague_season_publish';
	public const WITHDRAW       = 'ttleague_season_withdraw';
	public const CLOSE          = 'ttleague_season_close';

	/** Aktion => Methode. */
	private const HANDLERS = array(
		self::CREATE         => 'create',
		self::DELETE         => 'delete',
		self::RULES          => 'rules',
		self::WINDOW         => 'window',
		self::CLUB_ADD       => 'club_add',
		self::CLUB_REMOVE    => 'club_remove',
		self::GROUP_CREATE   => 'group_create',
		self::GROUP_RENAME   => 'group_rename',
		self::GROUP_DELETE   => 'group_delete',
		self::ASSIGN         => 'assign',
		self::PROPOSAL       => 'proposal',
		self::COPY           => 'copy',
		self::WEEKS          => 'weeks',
		self::GENERATE       => 'generate',
		self::CLEAR          => 'clear',
		self::MOVE           => 'move',
		self::SWAP           => 'swap',
		self::SUGGESTION     => 'suggestion',
		self::FIXTURE_ADD    => 'fixture_add',
		self::FIXTURE_CANCEL => 'fixture_cancel',
		self::PUBLISH        => 'publish',
		self::WITHDRAW       => 'withdraw',
		self::CLOSE          => 'close',
	);

	/** Gemerkte Eingabe eines Formulars mit Fehlern gilt so viele Sekunden. */
	private const MEMORY = 300;

	public static function register(): void {
		foreach ( self::HANDLERS as $action => $method ) {
			add_action( 'admin_post_' . $action, array( self::class, $method ) );
		}
	}

	/**
	 * Nonce einer Aktion für einen Datensatz (Feld _wpnonce).
	 */
	public static function nonce( string $action, int $id ): string {
		return $action . '_' . $id;
	}

	/**
	 * Gemerkte Eingabe und Fehler eines Formulars lesen und entfernen.
	 *
	 * @return array{values:array<string,string>,errors:array<string,string>}
	 */
	public static function recall( string $form ): array {
		$key    = self::memory_key( $form );
		$memory = get_transient( $key );
		$empty  = array(
			'values' => array(),
			'errors' => array(),
		);
		if ( ! is_array( $memory ) ) {
			return $empty;
		}
		delete_transient( $key );
		return array(
			'values' => array_map( 'strval', array_filter( (array) ( $memory['values'] ?? array() ), 'is_scalar' ) ),
			'errors' => array_map( 'strval', array_filter( (array) ( $memory['errors'] ?? array() ), 'is_scalar' ) ),
		);
	}

	public static function create(): void {
		self::request( self::CREATE, 0 );
		$data = array(
			'name'       => self::text( 'name' ),
			'start_date' => self::text( 'start_date' ),
			'end_date'   => self::text( 'end_date' ),
		);
		self::finish(
			SeasonPage::url( SeasonPage::CREATE ),
			static function ( Actor $actor ) use ( $data ): string {
				$id     = SeasonAdmin::setup()->create( $actor, $data );
				$season = ( new SeasonRepository( Services::db() ) )->by_id( $id );
				/* translators: %s: Saison */
				return sprintf( __( 'Die Saison %s ist angelegt. Prüfe als Nächstes die Regeln und setze das Meldefenster.', 'ttleague' ), (string) ( $season['name'] ?? '' ) );
			},
			'create',
			$data
		);
	}

	public static function delete(): void {
		$season  = self::int( 'season' );
		$confirm = self::text( 'confirm' );
		self::request( self::DELETE, $season );
		self::finish(
			SeasonPage::url( SeasonPage::CREATE ),
			static function ( Actor $actor ) use ( $season, $confirm ): string {
				SeasonAdmin::setup()->delete( $actor, $season, $confirm );
				return __( 'Die geplante Saison ist gelöscht.', 'ttleague' );
			}
		);
	}

	public static function rules(): void {
		$season = self::int( 'season' );
		self::request( self::RULES, $season );
		$input = array();
		$raw   = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce in request() geprüft; jedes Feld wird unten bereinigt.
		foreach ( array_keys( RulesInput::FIELDS ) as $field ) {
			if ( isset( $raw[ $field ] ) && is_scalar( $raw[ $field ] ) ) {
				$input[ $field ] = trim( sanitize_text_field( (string) $raw[ $field ] ) );
			}
		}
		self::finish(
			SeasonPage::url( SeasonPage::RULES ),
			static function ( Actor $actor ) use ( $season, $input ): string {
				SeasonAdmin::setup()->save_rules( $actor, $season, $input );
				return __( 'Die Regeln sind gespeichert.', 'ttleague' );
			},
			'rules',
			$input
		);
	}

	public static function window(): void {
		$season = self::int( 'season' );
		self::request( self::WINDOW, $season );
		$dates = array(
			'registration_opens'      => self::text( 'registration_opens' ),
			'registration_closes'     => self::text( 'registration_closes' ),
			'late_registration_until' => self::text( 'late_registration_until' ),
		);
		self::finish(
			SeasonPage::url( self::int( 'step' ) === SeasonPage::CLOSE ? SeasonPage::CLOSE : SeasonPage::WINDOW ),
			static function ( Actor $actor ) use ( $season, $dates ): string {
				SeasonAdmin::setup()->save_window( $actor, $season, $dates );
				return __( 'Die Daten sind gespeichert.', 'ttleague' );
			},
			'window',
			$dates
		);
	}

	public static function club_add(): void {
		$season = self::int( 'season' );
		$club   = self::int( 'club' );
		self::request( self::CLUB_ADD, $season );
		self::finish(
			SeasonPage::url( SeasonPage::CREATE ),
			static function ( Actor $actor ) use ( $season, $club ): string {
				SeasonAdmin::setup()->add_club( $actor, $season, $club );
				return __( 'Der Club nimmt jetzt an der Saison teil und kann melden.', 'ttleague' );
			}
		);
	}

	public static function club_remove(): void {
		$season = self::int( 'season' );
		$club   = self::int( 'club' );
		self::request( self::CLUB_REMOVE, $season );
		self::finish(
			SeasonPage::url( SeasonPage::CREATE ),
			static function ( Actor $actor ) use ( $season, $club ): string {
				SeasonAdmin::setup()->remove_club( $actor, $season, $club );
				return __( 'Der Club nimmt an der Saison nicht mehr teil.', 'ttleague' );
			}
		);
	}

	public static function group_create(): void {
		$season = self::int( 'season' );
		self::request( self::GROUP_CREATE, $season );
		$data = array(
			'category' => self::text( 'category' ),
			'group_no' => self::text( 'group_no' ),
			'name'     => self::text( 'name' ),
		);
		self::finish(
			SeasonPage::url( SeasonPage::GROUPS ),
			static function ( Actor $actor ) use ( $season, $data ): string {
				SeasonAdmin::grouping()->create_group( $actor, $season, $data['category'], '' === $data['group_no'] ? 1 : (int) $data['group_no'], $data['name'] );
				return __( 'Die Gruppe ist angelegt.', 'ttleague' );
			},
			'group',
			$data
		);
	}

	public static function group_rename(): void {
		$group = self::int( 'group' );
		$name  = self::text( 'name' );
		self::request( self::GROUP_RENAME, $group );
		self::finish(
			SeasonPage::url( SeasonPage::GROUPS ),
			static function ( Actor $actor ) use ( $group, $name ): string {
				SeasonAdmin::grouping()->rename_group( $actor, $group, $name );
				return __( 'Die Gruppe ist umbenannt.', 'ttleague' );
			}
		);
	}

	public static function group_delete(): void {
		$group = self::int( 'group' );
		self::request( self::GROUP_DELETE, $group );
		self::finish(
			SeasonPage::url( SeasonPage::GROUPS ),
			static function ( Actor $actor ) use ( $group ): string {
				SeasonAdmin::grouping()->delete_group( $actor, $group );
				return __( 'Die Gruppe ist gelöscht.', 'ttleague' );
			}
		);
	}

	public static function assign(): void {
		$season = self::int( 'season' );
		self::request( self::ASSIGN, $season );
		$teams = array();
		foreach ( self::map( 'team' ) as $team_id => $group ) {
			if ( (int) $team_id > 0 ) {
				$teams[ (int) $team_id ] = absint( $group ) > 0 ? absint( $group ) : null;
			}
		}
		self::finish(
			SeasonPage::url( SeasonPage::GROUPS ),
			static function ( Actor $actor ) use ( $season, $teams ): string {
				$changed = SeasonAdmin::grouping()->assign_many( $actor, $season, $teams );
				/* translators: %d: Anzahl Mannschaften */
				return sprintf( __( 'Die Einteilung ist gespeichert. Geänderte Mannschaften: %d.', 'ttleague' ), $changed );
			}
		);
	}

	public static function proposal(): void {
		$season = self::int( 'season' );
		self::request( self::PROPOSAL, $season );
		self::finish(
			SeasonPage::url( SeasonPage::GROUPS ),
			static function ( Actor $actor ) use ( $season ): string {
				$result = SeasonAdmin::grouping()->apply_proposal( $actor, $season );
				/* translators: 1: Anzahl Gruppen, 2: Anzahl Mannschaften */
				return sprintf( __( 'Vorschlag übernommen: %1$d Gruppen angelegt, %2$d Mannschaften zugeteilt. Auf- und Abstieg setzt du von Hand.', 'ttleague' ), $result['groups'], $result['teams'] );
			}
		);
	}

	public static function copy(): void {
		$season = self::int( 'season' );
		self::request( self::COPY, $season );
		self::finish(
			SeasonPage::url( SeasonPage::GROUPS ),
			static function ( Actor $actor ) use ( $season ): string {
				/* translators: %d: Anzahl Konkurrenzen */
				return sprintf( __( 'Cup und Ranglistenturnier übernommen: %d Konkurrenzen angelegt.', 'ttleague' ), SeasonAdmin::grouping()->copy_competitions( $actor, $season ) );
			}
		);
	}

	public static function weeks(): void {
		$season = self::int( 'season' );
		self::request( self::WEEKS, $season );
		$first  = array();
		$second = array();
		foreach ( self::map( 'week' ) as $monday => $leg ) {
			if ( '1' === (string) $leg ) {
				$first[] = (string) $monday;
			} elseif ( '2' === (string) $leg ) {
				$second[] = (string) $monday;
			}
		}
		self::finish(
			SeasonPage::url( SeasonPage::SCHEDULE ),
			static function ( Actor $actor ) use ( $season, $first, $second ): string {
				SeasonAdmin::scheduling()->save_weeks( $actor, $season, $first, $second );
				/* translators: 1: Wochen der Vorrunde, 2: Wochen der Rückrunde */
				return sprintf( __( 'Spielwochen gespeichert: %1$d in der Vorrunde, %2$d in der Rückrunde.', 'ttleague' ), count( $first ), count( $second ) );
			}
		);
	}

	public static function generate(): void {
		$season = self::int( 'season' );
		$group  = self::int( 'group' );
		self::request( self::GENERATE, $season );
		self::finish(
			SeasonPage::url( SeasonPage::SCHEDULE ),
			static function ( Actor $actor ) use ( $season, $group ): string {
				$result = SeasonAdmin::scheduling()->generate( $actor, $season, $group > 0 ? $group : null );
				/* translators: 1: Begegnungen, 2: harte Kollisionen, 3: Hinweise */
				return sprintf( __( 'Entwurf erzeugt: %1$d Begegnungen. Harte Kollisionen im Plan: %2$d, Hinweise: %3$d.', 'ttleague' ), $result['fixtures'], $result['hard'], $result['hints'] );
			}
		);
	}

	public static function clear(): void {
		$season = self::int( 'season' );
		$group  = self::int( 'group' );
		self::request( self::CLEAR, $season );
		self::finish(
			SeasonPage::url( SeasonPage::SCHEDULE ),
			static function ( Actor $actor ) use ( $season, $group ): string {
				/* translators: %d: Anzahl Begegnungen */
				return sprintf( __( 'Entwurf gelöscht: %d Begegnungen.', 'ttleague' ), SeasonAdmin::scheduling()->clear( $actor, $season, $group > 0 ? $group : null ) );
			}
		);
	}

	public static function move(): void {
		$fixture = self::int( 'fixture' );
		self::request( self::MOVE, $fixture );
		$date  = self::text( 'date' );
		$time  = self::text( 'time' );
		$venue = self::int( 'venue' );
		self::finish(
			SeasonPage::url( SeasonPage::SCHEDULE ),
			static function ( Actor $actor ) use ( $fixture, $date, $time, $venue ): string {
				SeasonAdmin::scheduling()->move( $actor, $fixture, $date, $time, $venue > 0 ? $venue : null );
				return __( 'Die Begegnung ist verlegt. Der Plan ist neu geprüft.', 'ttleague' );
			},
			null,
			array(),
			SeasonPage::url( SeasonPage::SCHEDULE, array( 'fixture' => (string) $fixture ) )
		);
	}

	public static function swap(): void {
		$fixture = self::int( 'fixture' );
		$both    = '' !== self::text( 'with_return' );
		self::request( self::SWAP, $fixture );
		self::finish(
			SeasonPage::url( SeasonPage::SCHEDULE ),
			static function ( Actor $actor ) use ( $fixture, $both ): string {
				SeasonAdmin::scheduling()->swap_home( $actor, $fixture, $both );
				return __( 'Das Heimrecht ist getauscht. Der Plan ist neu geprüft.', 'ttleague' );
			},
			null,
			array(),
			SeasonPage::url( SeasonPage::SCHEDULE, array( 'fixture' => (string) $fixture ) )
		);
	}

	public static function suggestion(): void {
		$fixture   = self::int( 'fixture' );
		$starts_at = self::text( 'starts_at' );
		self::request( self::SUGGESTION, $fixture );
		self::finish(
			SeasonPage::url( SeasonPage::SCHEDULE ),
			static function ( Actor $actor ) use ( $fixture, $starts_at ): string {
				SeasonAdmin::scheduling()->apply_suggestion( $actor, $fixture, $starts_at );
				return __( 'Der Vorschlag ist übernommen. Der Plan ist neu geprüft.', 'ttleague' );
			}
		);
	}

	public static function fixture_add(): void {
		$season = self::int( 'season' );
		self::request( self::FIXTURE_ADD, $season );
		$home  = self::int( 'home' );
		$away  = self::int( 'away' );
		$date  = self::text( 'date' );
		$time  = self::text( 'time' );
		$venue = self::int( 'venue' );
		self::finish(
			SeasonPage::url( SeasonPage::SCHEDULE ),
			static function ( Actor $actor ) use ( $season, $home, $away, $date, $time, $venue ): string {
				$db    = Services::db();
				$group = (int) $db->get_var( "SELECT competition_id FROM {$db->table( 'team' )} WHERE id = %d AND season_id = %d", array( $home, $season ) );
				SeasonAdmin::scheduling()->add_fixture( $actor, $group, $home, $away, $date, $time, $venue > 0 ? $venue : null );
				return __( 'Die Begegnung ist angesetzt.', 'ttleague' );
			}
		);
	}

	public static function fixture_cancel(): void {
		$season = self::int( 'season' );
		self::request( self::FIXTURE_CANCEL, $season );
		$fixture = self::int( 'fixture' );
		$reason  = self::note( 'reason' );
		self::finish(
			SeasonPage::url( SeasonPage::SCHEDULE ),
			static function ( Actor $actor ) use ( $fixture, $reason ): string {
				SeasonAdmin::scheduling()->cancel_fixture( $actor, $fixture, $reason );
				return __( 'Die Begegnung ist abgesagt.', 'ttleague' );
			}
		);
	}

	public static function publish(): void {
		$season = self::int( 'season' );
		self::request( self::PUBLISH, $season );
		$confirm  = self::text( 'confirm' );
		$accepted = array_values( array_intersect( LifecycleRules::CONFIRMABLE, array_map( 'strval', array_keys( array_filter( self::map( 'accept' ) ) ) ) ) );
		self::finish(
			SeasonPage::url( SeasonPage::CLOSE ),
			static function ( Actor $actor ) use ( $season, $confirm, $accepted ): string {
				SeasonAdmin::lifecycle()->publish( $actor, $season, $confirm, $accepted );
				return __( 'Die Saison ist freigegeben und jetzt aktiv. Captains und TK-Chefs erhalten den Spielplan in den nächsten Minuten.', 'ttleague' );
			},
			null,
			array(),
			SeasonPage::url( SeasonPage::PUBLISH )
		);
	}

	public static function withdraw(): void {
		$season = self::int( 'season' );
		self::request( self::WITHDRAW, $season );
		$team   = self::int( 'team' );
		$reason = self::note( 'reason' );
		self::finish(
			SeasonPage::url( SeasonPage::CLOSE ),
			static function ( Actor $actor ) use ( $team, $reason ): string {
				$count = SeasonAdmin::lifecycle()->withdraw_team( $actor, $team, $reason );
				/* translators: %d: Anzahl Begegnungen */
				return sprintf( __( 'Die Mannschaft ist zurückgezogen. Forfait gewertete Begegnungen: %d.', 'ttleague' ), $count );
			}
		);
	}

	public static function close(): void {
		$season = self::int( 'season' );
		self::request( self::CLOSE, $season );
		$confirm = self::text( 'confirm' );
		self::finish(
			SeasonPage::url( SeasonPage::CLOSE ),
			static function ( Actor $actor ) use ( $season, $confirm ): string {
				SeasonAdmin::lifecycle()->close( $actor, $season, $confirm );
				return __( 'Die Saison ist abgeschlossen und archiviert.', 'ttleague' );
			}
		);
	}

	/**
	 * Nonce (pro Aktion und Datensatz) und ttl_tk prüfen.
	 */
	private static function request( string $action, int $id ): void {
		check_admin_referer( self::nonce( $action, $id ) );
		if ( ! current_user_can( Capabilities::TK ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Dienst aufrufen, Rückmeldung ablegen, zurückleiten.
	 *
	 * @param string                 $url      Ziel nach dem Erfolg.
	 * @param callable(Actor):string $work     Aufruf des Dienstes; liefert die Erfolgsmeldung.
	 * @param string|null            $form     Formular, dessen Eingabe bei Feldfehlern gemerkt wird.
	 * @param array<string,mixed>    $values   Eingabe des Formulars.
	 * @param string|null            $error_url Ziel nach einer Ablehnung; ohne Angabe wie beim Erfolg.
	 */
	private static function finish( string $url, callable $work, ?string $form = null, array $values = array(), ?string $error_url = null ): never {
		try {
			$outcome = Outcome::success( $work( Results::current_actor() ) );
		} catch ( SeasonException $e ) {
			$details = array_values( array_unique( array_column( $e->errors, 'message' ) ) );
			$outcome = Outcome::error( $e->getMessage(), array( $e->getMessage() ) === $details ? array() : $details );
			$url     = $error_url ?? $url;
			if ( null !== $form ) {
				set_transient(
					self::memory_key( $form ),
					array(
						'values' => array_filter( $values, 'is_scalar' ),
						'errors' => array_column( $e->errors, 'message', 'field' ),
					),
					self::MEMORY
				);
			}
		}
		Flash::set( Tk::FLASH, get_current_user_id(), $outcome->type, $outcome->message, $outcome->details );
		wp_safe_redirect( $url );
		exit;
	}

	private static function memory_key( string $form ): string {
		return 'ttl_season_form_' . $form . '_' . get_current_user_id();
	}

	private static function int( string $key ): int {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce in request() geprüft.
	}

	private static function text( string $key ): string {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce in request() geprüft.
	}

	private static function note( string $key ): string {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? trim( sanitize_textarea_field( wp_unslash( (string) $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce in request() geprüft.
	}

	/**
	 * Feld mit Schlüsseln (z. B. team[12] = 3): bereinigte Schlüssel und Werte als Text.
	 *
	 * @return array<string,string>
	 */
	private static function map( string $key ): array {
		$values = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce in request() geprüft; Schlüssel und Werte werden unten bereinigt.
		$map    = array();
		foreach ( $values as $name => $value ) {
			if ( is_scalar( $value ) ) {
				$map[ sanitize_text_field( (string) $name ) ] = sanitize_text_field( (string) $value );
			}
		}
		return $map;
	}
}
