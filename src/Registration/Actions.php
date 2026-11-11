<?php
/**
 * Formularaktionen der Meldung (admin-post.php): Club in «Mein Bereich», TK im Backend.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Registration;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\LoginFlow;
use TTLeague\Accounts\Outcome;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\Actor;
use TTLeague\Results\Results;
use TTLeague\Tk\Tk;

/**
 * Jede Aktion prüft ihren Nonce (pro Aktion und Club, bei Entscheiden der TK pro Aktion und
 * Datensatz) und das Recht: ttl_manage_club für den Club, bei Entscheiden ttl_tk. Danach ruft
 * sie den Dienst auf, der Saisonstatus, Meldefenster und Meldestand selbst prüft, legt die
 * Rückmeldung ab (Flash) und leitet zurück: in «Mein Bereich» oder, mit dem Feld «back» und
 * ttl_tk, auf die Seite «Meldungen» im Backend. Beide Oberflächen verwenden dieselben Aktionen.
 */
final class Actions {

	public const CONFIRM        = 'ttleague_reg_confirm';
	public const CONFIRM_ALL    = 'ttleague_reg_confirm_all';
	public const LICENSE        = 'ttleague_reg_license';
	public const WITHDRAW       = 'ttleague_reg_withdraw';
	public const REQUEST        = 'ttleague_reg_request';
	public const REQUEST_CANCEL = 'ttleague_reg_request_cancel';
	public const TEAM_ADD       = 'ttleague_reg_team_add';
	public const TEAM_COPY      = 'ttleague_reg_team_copy';
	public const TEAM_REMOVE    = 'ttleague_reg_team_remove';
	public const TEAM_SAVE      = 'ttleague_reg_team_save';
	public const CAPTAIN        = 'ttleague_reg_captain';
	public const SUBMIT         = 'ttleague_reg_submit';
	public const VENUE          = 'ttleague_reg_venue';
	public const CLOSURE_ADD    = 'ttleague_reg_closure_add';
	public const CLOSURE_DELETE = 'ttleague_reg_closure_delete';

	public const REQUEST_APPROVE = 'ttleague_reg_request_approve';
	public const REQUEST_REJECT  = 'ttleague_reg_request_reject';
	public const PLAYER_APPROVE  = 'ttleague_reg_player_approve';
	public const PLAYER_REJECT   = 'ttleague_reg_player_reject';
	public const APPROVE         = 'ttleague_reg_approve';
	public const RETURN          = 'ttleague_reg_return';

	/** Aktion => Methode. */
	private const HANDLERS = array(
		self::CONFIRM         => 'confirm',
		self::CONFIRM_ALL     => 'confirm_all',
		self::LICENSE         => 'license',
		self::WITHDRAW        => 'withdraw',
		self::REQUEST         => 'request',
		self::REQUEST_CANCEL  => 'request_cancel',
		self::TEAM_ADD        => 'team_add',
		self::TEAM_COPY       => 'team_copy',
		self::TEAM_REMOVE     => 'team_remove',
		self::TEAM_SAVE       => 'team_save',
		self::CAPTAIN         => 'captain',
		self::SUBMIT          => 'submit',
		self::VENUE           => 'venue',
		self::CLOSURE_ADD     => 'closure_add',
		self::CLOSURE_DELETE  => 'closure_delete',
		self::REQUEST_APPROVE => 'request_approve',
		self::REQUEST_REJECT  => 'request_reject',
		self::PLAYER_APPROVE  => 'player_approve',
		self::PLAYER_REJECT   => 'player_reject',
		self::APPROVE         => 'approve',
		self::RETURN          => 'return_to_club',
	);

	public static function register(): void {
		foreach ( self::HANDLERS as $action => $method ) {
			add_action( 'admin_post_' . $action, array( self::class, $method ) );
		}
	}

	public static function confirm(): void {
		[ $club, $season ] = self::club_request( self::CONFIRM );
		$player            = self::int( 'player' );
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $club, $season, $player ): string {
				Registration::registration()->confirm_player( $actor, $season, $club, $player, self::classification( $actor ) );
				return __( 'Gemeldet.', 'ttleague' );
			}
		);
	}

	/**
	 * Alle Vorjahresspieler bestätigen, die kein anderer Club gemeldet hat.
	 */
	public static function confirm_all(): void {
		[ $club, $season ] = self::club_request( self::CONFIRM_ALL );
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $club, $season ): string {
				$service = Registration::registration();
				$count   = 0;
				foreach ( $service->state( $actor, $season, $club )['candidates'] as $candidate ) {
					if ( null === $candidate['other_club'] ) {
						$service->confirm_player( $actor, $season, $club, $candidate['player_id'] );
						++$count;
					}
				}
				/* translators: %d: Anzahl */
				return sprintf( __( 'Vorjahresspieler bestätigt: %d.', 'ttleague' ), $count );
			}
		);
	}

	public static function license(): void {
		[ $club, $season ] = self::club_request( self::LICENSE );
		$license           = self::int( 'license_no' );
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $club, $season, $license ): string {
				Registration::registration()->register_license( $actor, $season, $club, $license, self::classification( $actor ) );
				return __( 'Gemeldet.', 'ttleague' );
			}
		);
	}

	public static function withdraw(): void {
		[ $club, $season ] = self::club_request( self::WITHDRAW );
		$player            = self::int( 'player' );
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $club, $season, $player ): string {
				Registration::registration()->withdraw_player( $actor, $season, $club, $player );
				return __( 'Abgemeldet.', 'ttleague' );
			}
		);
	}

	public static function request(): void {
		[ $club, $season ] = self::club_request( self::REQUEST );
		$data              = array(
			'last_name'      => self::text( 'last_name' ),
			'first_name'     => self::text( 'first_name' ),
			'email'          => self::text( 'email' ),
			'classification' => '' === self::text( 'classification' ) ? null : self::int( 'classification' ),
			'stt_license'    => self::text( 'stt_license' ),
		);
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $club, $season, $data ): string {
				Registration::registration()->request_player( $actor, $season, $club, $data );
				return __( 'Gemeldet. Die TK prüft die Neumeldung und vergibt die Lizenznummer.', 'ttleague' );
			}
		);
	}

	public static function request_cancel(): void {
		[ $club, $season ] = self::club_request( self::REQUEST_CANCEL );
		$request           = self::int( 'request' );
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $request ): string {
				Registration::registration()->cancel_request( $actor, $request );
				return __( 'Die Neumeldung ist zurückgezogen.', 'ttleague' );
			}
		);
	}

	public static function team_add(): void {
		[ $club, $season ] = self::club_request( self::TEAM_ADD );
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $club, $season ): string {
				Registration::registration()->add_team( $actor, $season, $club );
				return __( 'Mannschaft hinzugefügt. Wähle jetzt Stammspieler, Lokal, Anspielzeit und Captain.', 'ttleague' );
			}
		);
	}

	/**
	 * Mannschaften der aktiven Saison in die noch leere Meldung der geplanten Saison übernehmen.
	 */
	public static function team_copy(): void {
		[ $club, $season ] = self::club_request( self::TEAM_COPY );
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $club, $season ): string {
				$count = Registration::registration()->copy_teams( $actor, $season, $club );
				/* translators: %d: Anzahl */
				return sprintf( _n( '%d Mannschaft übernommen. Bitte prüfe Stammspieler, Lokal, Anspielzeit und Captain.', '%d Mannschaften übernommen. Bitte prüfe Stammspieler, Lokal, Anspielzeit und Captain.', $count, 'ttleague' ), $count );
			}
		);
	}

	public static function team_remove(): void {
		[ $club, $season ] = self::club_request( self::TEAM_REMOVE );
		$team              = self::int( 'team' );
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $team ): string {
				Registration::registration()->remove_team( $actor, $team );
				return __( 'Mannschaft entfernt.', 'ttleague' );
			}
		);
	}

	public static function team_save(): void {
		[ $club, $season ] = self::club_request( self::TEAM_SAVE );
		$team              = self::int( 'team' );
		$data              = array(
			'players'       => self::ints( 'players' ),
			'home_venue_id' => self::int( 'home_venue_id' ),
			'start_time'    => self::text( 'start_time' ),
		);
		if ( self::has( 'captain' ) ) {
			$data['captain_person_id'] = self::int( 'captain' );
		}
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $team, $data ): string {
				Registration::registration()->save_team( $actor, $team, $data );
				return __( 'Mannschaft gespeichert.', 'ttleague' );
			}
		);
	}

	public static function captain(): void {
		[ $club, $season ] = self::club_request( self::CAPTAIN );
		$team              = self::int( 'team' );
		$person            = self::int( 'captain' );
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $team, $person ): string {
				Registration::registration()->set_captain( $actor, $team, $person > 0 ? $person : null );
				return __( 'Captain gespeichert. Die Änderung gilt sofort.', 'ttleague' );
			}
		);
	}

	public static function submit(): void {
		[ $club, $season ] = self::club_request( self::SUBMIT );
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $club, $season ): string {
				Registration::registration()->submit( $actor, $season, $club );
				return __( 'Die Meldung ist eingereicht. Die TK prüft sie und meldet sich.', 'ttleague' );
			}
		);
	}

	public static function venue(): void {
		[ $club, $season ] = self::club_request( self::VENUE );
		$venue             = self::int( 'venue' );
		$data              = array(
			'name'         => self::text( 'name' ),
			'street'       => self::text( 'street' ),
			'zip'          => self::text( 'zip' ),
			'city'         => self::text( 'city' ),
			'phone'        => self::text( 'phone' ),
			'weekday'      => '' === self::text( 'weekday' ) ? null : self::int( 'weekday' ),
			'open_time'    => self::text( 'open_time' ),
			'close_time'   => self::text( 'close_time' ),
			'tables_count' => '' === self::text( 'tables_count' ) ? null : self::int( 'tables_count' ),
			'active'       => $venue <= 0 || '1' === self::text( 'active' ),
		);
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $club, $venue, $data ): string {
				Registration::venues()->save( $actor, $club, $venue > 0 ? $venue : null, $data );
				return __( 'Lokal gespeichert. Die Änderung gilt sofort.', 'ttleague' );
			}
		);
	}

	public static function closure_add(): void {
		[ $club, $season ] = self::club_request( self::CLOSURE_ADD );
		$venue             = self::int( 'venue' );
		$from              = self::text( 'date_from' );
		$to                = self::text( 'date_to' );
		$reason            = self::text( 'reason' );
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $venue, $from, $to, $reason ): string {
				Registration::venues()->add_closure( $actor, $venue, $from, '' === $to ? $from : $to, $reason );
				return __( 'Hallensperre eingetragen.', 'ttleague' );
			}
		);
	}

	public static function closure_delete(): void {
		[ $club, $season ] = self::club_request( self::CLOSURE_DELETE );
		$closure           = self::int( 'closure' );
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $closure ): string {
				Registration::venues()->delete_closure( $actor, $closure );
				return __( 'Hallensperre entfernt.', 'ttleague' );
			}
		);
	}

	public static function request_approve(): void {
		$request           = self::int( 'request' );
		[ $club, $season ] = self::tk_request( self::REQUEST_APPROVE, $request );
		$data              = array(
			'license_no' => self::int( 'license_no' ),
			'person_id'  => self::int( 'person_id' ),
			'email'      => self::text( 'email' ),
		);
		if ( '' !== self::text( 'classification' ) ) {
			$data['classification'] = self::int( 'classification' );
		}
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $request, $data ): string {
				Registration::review()->approve_request( $actor, $request, $data );
				return __( 'Freigegeben. Der Club ist benachrichtigt.', 'ttleague' );
			}
		);
	}

	public static function request_reject(): void {
		$request           = self::int( 'request' );
		[ $club, $season ] = self::tk_request( self::REQUEST_REJECT, $request );
		$note              = self::note();
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $request, $note ): string {
				Registration::review()->reject_request( $actor, $request, $note );
				return __( 'Abgelehnt. Der Club ist benachrichtigt.', 'ttleague' );
			}
		);
	}

	public static function player_approve(): void {
		$player            = self::int( 'player' );
		[ $club, $season ] = self::tk_request( self::PLAYER_APPROVE, $player );
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $club, $season, $player ): string {
				Registration::review()->approve_player( $actor, $season, $club, $player );
				return __( 'Freigegeben. Der Club ist benachrichtigt.', 'ttleague' );
			}
		);
	}

	public static function player_reject(): void {
		$player            = self::int( 'player' );
		[ $club, $season ] = self::tk_request( self::PLAYER_REJECT, $player );
		$note              = self::note();
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $club, $season, $player, $note ): string {
				Registration::review()->reject_player( $actor, $season, $club, $player, $note );
				return __( 'Abgelehnt. Der Club ist benachrichtigt.', 'ttleague' );
			}
		);
	}

	public static function approve(): void {
		$club         = self::int( 'club' );
		[ , $season ] = self::tk_request( self::APPROVE, $club );
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $club, $season ): string {
				Registration::review()->approve( $actor, $season, $club );
				return __( 'Die Meldung ist geprüft. Alle Spieler des Clubs sind freigegeben, der Club ist benachrichtigt.', 'ttleague' );
			}
		);
	}

	public static function return_to_club(): void {
		$club         = self::int( 'club' );
		[ , $season ] = self::tk_request( self::RETURN, $club );
		$note         = self::note();
		self::finish(
			$club,
			$season,
			static function ( Actor $actor ) use ( $club, $season, $note ): string {
				Registration::review()->return_to_club( $actor, $season, $club, $note );
				return __( 'Die Meldung ist zurückgegeben. Der Club ist benachrichtigt.', 'ttleague' );
			}
		);
	}

	/**
	 * Nonce der Aktion für einen Club (Feld _wpnonce).
	 */
	public static function nonce( string $action, int $id ): string {
		return $action . '_' . $id;
	}

	/**
	 * Aktion eines Clubs: Nonce und ttl_manage_club prüfen.
	 *
	 * @return array{0:int,1:int} Club und Saison.
	 */
	private static function club_request( string $action ): array {
		$club = self::int( 'club' );
		check_admin_referer( self::nonce( $action, $club ) );
		if ( $club <= 0 || ! current_user_can( Capabilities::MANAGE_CLUB, $club ) ) {
			self::deny();
		}
		return array( $club, self::int( 'season' ) );
	}

	/**
	 * Entscheid der TK: Nonce (pro Aktion und Datensatz) und ttl_tk prüfen.
	 *
	 * @return array{0:int,1:int} Club und Saison.
	 */
	private static function tk_request( string $action, int $id ): array {
		check_admin_referer( self::nonce( $action, $id ) );
		if ( ! current_user_can( Capabilities::TK ) ) {
			self::deny();
		}
		return array( self::int( 'club' ), self::int( 'season' ) );
	}

	private static function deny(): never {
		wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
	}

	/**
	 * Dienst aufrufen, Rückmeldung ablegen, zurückleiten.
	 *
	 * @param callable(Actor):string $work Aufruf des Dienstes; liefert die Erfolgsmeldung.
	 */
	private static function finish( int $club, int $season, callable $work ): never {
		try {
			$outcome = Outcome::success( $work( Results::current_actor() ) );
		} catch ( RegistrationException $e ) {
			$details = array_values( array_unique( array_column( $e->errors, 'message' ) ) );
			$outcome = Outcome::error( $e->getMessage(), array( $e->getMessage() ) === $details ? array() : $details );
		}
		$to_tk = 'tk' === self::text( 'back' ) && current_user_can( Capabilities::TK );
		Flash::set( $to_tk ? Tk::FLASH : Registration::flash_scope( $club ), get_current_user_id(), $outcome->type, $outcome->message, $outcome->details );
		wp_safe_redirect( $to_tk ? ReviewPage::url( $season, $club ) : LoginFlow::my_area_url() . '#' . RegistrationMessages::anchor( $club ) );
		exit;
	}

	/**
	 * Klassierung, die nur die TK mitgeben kann.
	 */
	private static function classification( Actor $actor ): ?int {
		return $actor->is_tk && '' !== self::text( 'classification' ) ? self::int( 'classification' ) : null;
	}

	private static function note(): string {
		return isset( $_POST['note'] ) && is_scalar( $_POST['note'] ) ? trim( sanitize_textarea_field( wp_unslash( (string) $_POST['note'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce in tk_request() geprüft.
	}

	private static function has( string $key ): bool {
		return isset( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce in club_request() bzw. tk_request() geprüft.
	}

	private static function int( string $key ): int {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce in club_request() bzw. tk_request() geprüft.
	}

	private static function text( string $key ): string {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce in club_request() bzw. tk_request() geprüft.
	}

	/**
	 * @return list<int>
	 */
	private static function ints( string $key ): array {
		$values = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce geprüft; jeder Wert wird zur Ganzzahl.
		return array_values( array_filter( array_map( 'absint', array_filter( $values, 'is_scalar' ) ) ) );
	}
}
