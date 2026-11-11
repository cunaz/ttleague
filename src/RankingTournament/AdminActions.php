<?php
/**
 * Formularaktionen der Backend-Seite «Ranglistenturnier» (admin-post.php).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\Outcome;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\Actor;
use TTLeague\Results\ResultException;
use TTLeague\Results\Results;
use TTLeague\Tk\Tk;

/**
 * Eine Aktion für alle Formulare; das Feld «do» nennt den Vorgang, «id» sein Ziel. Jede Anfrage
 * prüft den Nonce (pro Vorgang und Ziel) und ttl_tk, ruft den Dienst auf, legt die Rückmeldung
 * ab (Flash) und leitet zurück auf die Seite. Die fachlichen Regeln prüfen die Dienste.
 */
final class AdminActions {

	public const ACTION = 'ttleague_rl_admin';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Name des Nonce für einen Vorgang und sein Ziel.
	 */
	public static function nonce( string $task, int $id ): string {
		return self::ACTION . '_' . $task . '_' . $id;
	}

	public static function handle(): void {
		$task = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( (string) $_POST['do'] ) ) : '';
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( self::nonce( $task, $id ) );
		if ( ! current_user_can( Capabilities::TK ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
		$round = isset( $_POST['round'] ) ? absint( $_POST['round'] ) : 0;
		$actor = Results::current_actor();
		if ( 'register' === $task && 0 === $id ) {
			// Formular «Person anmelden»: Die Person kommt aus der Auswahl.
			$id = self::number( 'player' );
		}
		$back = in_array( $task, array( 'configure', 'register', 'withdraw', 'category' ), true )
			? AdminPage::url( 'anmeldungen' )
			: ( $round > 0 ? AdminPage::url( 'runden', $round ) : AdminPage::url( 'runden' ) );
		try {
			[ $outcome, $target ] = self::run( $task, $id, $round, $actor );
		} catch ( ResultException $e ) {
			$details = count( $e->errors ) > 1 ? array_column( $e->errors, 'message' ) : array();
			self::back( $back, Outcome::error( $e->getMessage(), $details ) );
		} catch ( \InvalidArgumentException $e ) {
			self::back( $back, Outcome::error( __( 'Die Regeln der Saison sind ungültig. Bitte prüfe die Saisoneinstellungen.', 'ttleague' ) ) );
		}
		self::back( $target ?? $back, $outcome );
	}

	/**
	 * Führt den Vorgang aus.
	 *
	 * @return array{0:Outcome,1:string|null} Rückmeldung und, falls abweichend, das Ziel der Weiterleitung.
	 */
	private static function run( string $task, int $id, int $round, Actor $actor ): array {
		$registrations = RankingTournament::registrations();
		$rounds        = RankingTournament::rounds();
		switch ( $task ) {
			case 'configure':
				$registrations->configure(
					$actor,
					$id,
					array(
						'name'                  => self::text( 'name' ),
						'categories'            => self::categories( self::text( 'categories' ) ),
						'registration_deadline' => self::text( 'registration_deadline' ),
						'start_time'            => self::text( 'start_time' ),
					)
				);
				return array( Outcome::success( __( 'Einstellungen gespeichert.', 'ttleague' ) ), null );

			case 'register':
				$category = self::number( 'category' );
				$result   = $registrations->register( $actor, $id, $category > 0 ? $category : null );
				return array(
					0 === $result['category']
						? Outcome::warning( __( 'Angemeldet, aber ohne Kategorie. Bitte setze die Kategorie.', 'ttleague' ) )
						/* translators: %d: Kategorie */
						: Outcome::success( sprintf( __( 'Angemeldet in Kategorie %d.', 'ttleague' ), $result['category'] ) ),
					null,
				);

			case 'withdraw':
				$registrations->withdraw( $actor, $id );
				return array( Outcome::success( __( 'Abgemeldet.', 'ttleague' ) ), null );

			case 'category':
				$registrations->set_category( $actor, $id, self::number( 'category' ) );
				return array( Outcome::success( __( 'Kategorie gespeichert.', 'ttleague' ) ), null );

			case 'round_create':
				$new = $rounds->create( $actor, $id, self::text( 'date_from' ), self::text( 'date_to' ), isset( $_POST['is_final'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in handle() geprüft.
				return array( Outcome::success( __( 'Runde angelegt. Sie ist noch nicht öffentlich.', 'ttleague' ) ), AdminPage::url( 'runden', $new ) );

			case 'round_update':
				$rounds->reschedule( $actor, $id, self::text( 'date_from' ), self::text( 'date_to' ), isset( $_POST['is_final'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in handle() geprüft.
				return array( Outcome::success( __( 'Rundenwoche gespeichert.', 'ttleague' ) ), null );

			case 'round_delete':
				$rounds->delete( $actor, $id );
				return array( Outcome::success( __( 'Runde gelöscht.', 'ttleague' ) ), AdminPage::url( 'runden' ) );

			case 'propose':
				return array( self::proposal( $rounds->propose( $actor, $id ) ), null );

			case 'publish':
				$rounds->publish( $actor, $id );
				return array( Outcome::success( __( 'Runde freigegeben. Die Gruppen sind öffentlich, die Aufgebote werden jetzt versendet.', 'ttleague' ) ), null );

			case 'close':
				$rounds->close( $actor, $id, self::manual(), isset( $_POST['allow_missing'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in handle() geprüft.
				return array( Outcome::success( __( 'Runde abgeschlossen. Rang und Punkte sind festgeschrieben.', 'ttleague' ) ), null );

			case 'group_save':
				$data = array(
					'venue_id'        => self::number( 'venue_id' ),
					'host_club_id'    => null,
					'start_at'        => self::text( 'start_at' ),
					'chief_player_id' => self::number( 'chief_player_id' ),
					'note'            => self::text( 'note' ),
					'members'         => array_merge( self::numbers( 'members' ), self::numbers( 'add' ) ),
				);
				if ( 0 === $data['venue_id'] ) {
					$data['venue_id'] = null;
				}
				if ( 0 === $id ) {
					$data['category'] = self::number( 'category' );
				}
				$rounds->save_group( $actor, $round, $id > 0 ? $id : null, $data );
				$current = RankingTournament::repository()->round( $round );
				return array(
					Outcome::success(
						null !== $current && Rounds::PUBLISHED === $current['status']
							? __( 'Gruppe gespeichert. Hat sich das Aufgebot geändert, wird es den Aufgebotenen der Gruppe neu versendet.', 'ttleague' )
							: __( 'Gruppe gespeichert.', 'ttleague' )
					),
					null,
				);

			case 'group_delete':
				$rounds->delete_group( $actor, $id );
				return array( Outcome::success( __( 'Gruppe gelöscht. Ihre Mitglieder sind nicht zugeteilt.', 'ttleague' ) ), null );

			case 'group_reset':
				// Das Zurücksetzen löscht erfasste Partien: nur mit ausdrücklicher Bestätigung.
				if ( ! isset( $_POST['confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in handle() geprüft.
					return array( Outcome::error( __( 'Nicht zurückgesetzt: Bitte bestätige mit dem Kontrollkästchen, dass alle erfassten Partien der Gruppe gelöscht werden.', 'ttleague' ) ), null );
				}
				RankingTournament::entry()->reset( $id, $actor );
				return array( Outcome::success( __( 'Gruppenzettel zurückgesetzt.', 'ttleague' ) ), null );
		}
		return array( Outcome::error( __( 'Unbekannte Aktion.', 'ttleague' ) ), null );
	}

	/**
	 * Rückmeldung nach dem Einteilen.
	 *
	 * @param array{groups:int,unassigned:list<array{player_id:int,name:string,category:int,reason:string}>,repeats:int,draw:list<string>,without_slot:int} $result Ergebnis.
	 */
	private static function proposal( array $result ): Outcome {
		$details = array();
		foreach ( $result['unassigned'] as $row ) {
			$details[] = sprintf(
				/* translators: 1: Name, 2: Kategorie, 3: Grund */
				__( 'Nicht zugeteilt: %1$s (Kategorie %2$d), %3$s', 'ttleague' ),
				$row['name'],
				$row['category'],
				'blocked' === $row['reason'] ? __( 'an allen möglichen Abenden gesperrt', 'ttleague' ) : __( 'kein Platz an einem freien Abend', 'ttleague' )
			);
		}
		if ( $result['without_slot'] > 0 ) {
			/* translators: %d: Anzahl Gruppen */
			$details[] = sprintf( _n( '%d Gruppe hat noch kein Lokal und keinen Abend.', '%d Gruppen haben noch kein Lokal und keinen Abend.', $result['without_slot'], 'ttleague' ), $result['without_slot'] );
		}
		if ( $result['repeats'] > 0 ) {
			/* translators: %d: Anzahl Paarungen */
			$details[] = sprintf( _n( '%d Paarung gab es schon in einer früheren Runde.', '%d Paarungen gab es schon in einer früheren Runde.', $result['repeats'], 'ttleague' ), $result['repeats'] );
		}
		if ( array() !== $result['draw'] ) {
			/* translators: %s: Namen */
			$details[] = sprintf( __( 'Gleichstand am letzten Finalplatz, das Los entscheidet zwischen: %s. Passe die Gruppe nach dem Los an.', 'ttleague' ), implode( ', ', $result['draw'] ) );
		}
		/* translators: %d: Anzahl Gruppen */
		$message = sprintf( _n( 'Vorschlag mit %d Gruppe gespeichert. Prüfe ihn und gib die Runde frei.', 'Vorschlag mit %d Gruppen gespeichert. Prüfe ihn und gib die Runde frei.', $result['groups'], 'ttleague' ), $result['groups'] );
		return array() === $result['unassigned'] && 0 === $result['without_slot'] ? Outcome::success( $message, $details ) : Outcome::warning( $message, $details );
	}

	/**
	 * Kategorien aus dem Textfeld «1:16, 2:9, 3:1» (Nummer:Grenze). Ungültige Teile bleiben als
	 * ungültiger Eintrag stehen, damit der Dienst die Eingabe ablehnt.
	 *
	 * @return list<array{no:mixed,min:mixed}>
	 */
	public static function categories( string $text ): array {
		$categories = array();
		foreach ( preg_split( '/[,;\s]+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
			$pair         = explode( ':', $part );
			$categories[] = array(
				'no'  => 2 === count( $pair ) && ctype_digit( $pair[0] ) ? (int) $pair[0] : null,
				'min' => 2 === count( $pair ) && ctype_digit( $pair[1] ) ? (int) $pair[1] : null,
			);
		}
		return $categories;
	}

	/**
	 * Von Hand gesetzte Ränge und Punkte: manual[Gruppe][Spieler][rank|points].
	 *
	 * @return array<int,array<int,array{rank:string,points:string}>>
	 */
	private static function manual(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce in handle() geprüft; jeder Wert wird unten bereinigt.
		$raw    = isset( $_POST['manual'] ) && is_array( $_POST['manual'] ) ? wp_unslash( $_POST['manual'] ) : array();
		$manual = array();
		foreach ( $raw as $group_id => $players ) {
			foreach ( is_array( $players ) ? $players : array() as $player_id => $values ) {
				if ( ! is_array( $values ) ) {
					continue;
				}
				$manual[ absint( $group_id ) ][ absint( $player_id ) ] = array(
					'rank'   => sanitize_text_field( (string) ( $values['rank'] ?? '' ) ),
					'points' => sanitize_text_field( (string) ( $values['points'] ?? '' ) ),
				);
			}
		}
		return $manual;
	}

	private static function text( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- in handle() geprüft.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
	}

	private static function number( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- in handle() geprüft.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : 0;
	}

	/**
	 * @return list<int>
	 */
	private static function numbers( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- in handle() geprüft.
		$raw = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? array_map( 'absint', wp_unslash( $_POST[ $key ] ) ) : array();
		return array_values( array_filter( $raw ) );
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
