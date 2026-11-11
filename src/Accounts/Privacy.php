<?php
/**
 * Datenschutz-Werkzeuge: Export und Löschung über WordPress, Anonymisierung auf Wunsch.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

use TTLeague\Access\Capabilities;
use TTLeague\Frontend\Support\Format;
use TTLeague\Infrastructure\Cache\DataVersion;
use TTLeague\Notifications\EventTypes;

/**
 * Hängt das Plugin an «Werkzeuge → Personenbezogene Daten exportieren» und «… löschen». Die
 * Person wird über die E-Mail-Adresse der Anfrage gefunden: person.email oder das verknüpfte
 * WordPress-Konto mit dieser Adresse. Die Löschung entfernt Telefon, Einstellungen, Push-Abos
 * und Anmelde-Tokens; Name, Meldungen und Resultate gehören zum Spielbetrieb und werden als
 * aufbewahrt gemeldet. Wer ganz aus den Listen verschwinden will, wird anonymisiert
 * (wp ttleague account anonymize).
 */
final class Privacy {

	/** Schlüssel des Plugins in den Listen der Exporter und Eraser. */
	public const KEY = 'ttleague';

	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
	}

	public static function service(): PersonalData {
		return new PersonalData( Services::db(), Services::audit() );
	}

	/**
	 * @param array<string,array<string,mixed>> $exporters Registrierte Exporter.
	 * @return array<string,array<string,mixed>>
	 */
	public static function exporters( array $exporters ): array {
		$exporters[ self::KEY ] = array(
			'exporter_friendly_name' => __( 'Spielbetrieb (ttleague)', 'ttleague' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string,array<string,mixed>> $erasers Registrierte Eraser.
	 * @return array<string,array<string,mixed>>
	 */
	public static function erasers( array $erasers ): array {
		$erasers[ self::KEY ] = array(
			'eraser_friendly_name' => __( 'Spielbetrieb (ttleague)', 'ttleague' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exporter: Profil, Funktionen und Meldungen je Saison, Benachrichtigungen.
	 *
	 * @param string $email_address Adresse der Anfrage.
	 * @param int    $page          Seite (alles passt auf eine).
	 * @return array{data:list<array<string,mixed>>,done:bool}
	 */
	public static function export( string $email_address, int $page = 1 ): array {
		unset( $page );
		$service = self::service();
		$items   = array();
		// Zuordnen und Lesen in einer Transaktion (PersonalData::for_request).
		$found = $service->for_request( $email_address, self::user_id( $email_address ), static fn( int $person_id ): ?array => $service->export( $person_id ) );
		if ( $found['ambiguous'] ) {
			$items[] = array(
				'group_id'          => 'ttleague-notice',
				'group_label'       => __( 'Spielbetrieb: Hinweis', 'ttleague' ),
				'group_description' => __( 'Hinweis zur Zuordnung der Anfrage.', 'ttleague' ),
				'item_id'           => 'ttleague-notice-ambiguous',
				'data'              => self::rows( array( __( 'Hinweis', 'ttleague' ) => self::ambiguous_message() ) ),
			);
		}
		if ( null !== $found['person_id'] && null !== $found['result'] ) {
			$items = array_merge( $items, self::items( $found['person_id'], $found['result'] ) );
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Eraser: entfernt, was der Spielbetrieb nicht braucht, und meldet den Rest als aufbewahrt.
	 *
	 * @param string $email_address Adresse der Anfrage.
	 * @param int    $page          Seite (alles passt auf eine).
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	public static function erase( string $email_address, int $page = 1 ): array {
		unset( $page );
		$service  = self::service();
		$actor    = get_current_user_id();
		$removed  = false;
		$retained = false;
		$messages = array();
		// Zuordnen und Löschen in einer Transaktion (PersonalData::for_request).
		$found = $service->for_request(
			$email_address,
			self::user_id( $email_address ),
			static fn( int $person_id ): ?array => $service->erase( $person_id, $actor > 0 ? $actor : null, 'privacy-tools' )
		);
		if ( $found['ambiguous'] ) {
			// Nichts gelöscht: Die Daten könnten einer anderen Person gehören.
			return array(
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => array( self::ambiguous_message() ),
				'done'           => true,
			);
		}
		$result = $found['result'];
		if ( null !== $result ) {
			$retained = true;
			$removed  = $result['phone'] || $result['preferences'] + $result['push_subscriptions'] + $result['login_tokens'] > 0;
		}
		if ( $retained ) {
			$messages[] = __( 'Spielbetrieb: Name, E-Mail-Adresse, Lizenznummer, Meldungen (Club, Klassierung), Funktionen und Resultate bleiben gespeichert. Sie gehören zum Spielbetrieb: Ranglisten, Matchreports und die Anmeldung beruhen darauf. Auf Wunsch anonymisiert die Technische Kommission die Person; dann verschwinden Name und E-Mail-Adresse.', 'ttleague' );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Anonymisiert eine Person (PersonalData::anonymize) und erhöht danach die Datenversion,
	 * damit Ranglisten, Matchreports und Profile den Platzhalter zeigen. Prüft selbst keine
	 * Rechte: Aufrufer ist WP-CLI; jeder andere Aufrufer muss vorher ttl_tk verlangen.
	 *
	 * @return array{name:string,user_id:int|null,email:bool,phone:bool,preferences:int,push_subscriptions:int,login_tokens:int}
	 * @throws \RuntimeException Wenn es die Person nicht gibt oder sie schon anonymisiert ist.
	 */
	public static function anonymize( int $person_id, ?int $actor_user_id = null, ?string $note = null ): array {
		$result = self::service()->anonymize( $person_id, $actor_user_id, $note );
		DataVersion::bump();
		// Das gelöste Konto verliert seine Rechte sofort, auch in derselben Anfrage.
		Capabilities::policy()->reset();
		return $result;
	}

	/**
	 * WordPress-Konto mit der Adresse der Anfrage, falls es eines gibt.
	 */
	private static function user_id( string $email_address ): ?int {
		$user = get_user_by( 'email', $email_address );
		return $user instanceof \WP_User ? (int) $user->ID : null;
	}

	private static function ambiguous_message(): string {
		return __( 'Spielbetrieb: Zu dieser E-Mail-Adresse gehören zwei verschiedene Personen (eine über die Adresse, eine über das verknüpfte Konto). Es wurde nichts exportiert und nichts gelöscht. Die Technische Kommission muss die Zuordnung zuerst klären (Konten und Einladungen).', 'ttleague' );
	}

	/**
	 * Einträge für den Export einer Person im Format der WordPress-Schnittstelle.
	 *
	 * @param array<string,mixed> $data Daten aus PersonalData::export().
	 * @return list<array<string,mixed>>
	 */
	private static function items( int $person_id, array $data ): array {
		$yes     = __( 'ja', 'ttleague' );
		$no      = __( 'nein', 'ttleague' );
		$profile = $data['profile'];
		$items   = array(
			array(
				'group_id'          => 'ttleague-profile',
				'group_label'       => __( 'Spielbetrieb: Profil', 'ttleague' ),
				'group_description' => __( 'Angaben zur Person im Spielbetrieb.', 'ttleague' ),
				'item_id'           => 'ttleague-person-' . $person_id,
				'data'              => self::rows(
					array(
						__( 'Vorname', 'ttleague' )  => $profile['first_name'],
						__( 'Nachname', 'ttleague' ) => $profile['last_name'],
						__( 'E-Mail', 'ttleague' )   => (string) $profile['email'],
						__( 'Telefon', 'ttleague' )  => (string) $profile['phone'],
						__( 'Telefon für Captains, TK-Chefs und TK sichtbar', 'ttleague' ) => $profile['phone_visible'] ? $yes : $no,
						__( 'Einwilligung zum Datenschutzhinweis (UTC)', 'ttleague' ) => (string) $profile['consent_at'],
						__( 'Status', 'ttleague' )   => 'active' === $profile['status'] ? __( 'aktiv', 'ttleague' ) : __( 'inaktiv', 'ttleague' ),
					)
				),
			),
		);
		foreach ( $data['functions'] as $i => $function ) {
			$items[] = array(
				'group_id'          => 'ttleague-functions',
				'group_label'       => __( 'Spielbetrieb: Funktionen', 'ttleague' ),
				'group_description' => __( 'Funktionen als Captain oder TK-Chef je Saison.', 'ttleague' ),
				'item_id'           => 'ttleague-function-' . $person_id . '-' . $i,
				'data'              => self::rows(
					array(
						__( 'Saison', 'ttleague' )     => $function['season'],
						__( 'Funktion', 'ttleague' )   => 'tk_chef' === $function['role'] ? __( 'TK-Chef', 'ttleague' ) : __( 'Captain', 'ttleague' ),
						__( 'Club', 'ttleague' )       => $function['club'],
						__( 'Mannschaft', 'ttleague' ) => (string) $function['team'],
					)
				),
			);
		}
		foreach ( $data['registrations'] as $i => $registration ) {
			$items[] = array(
				'group_id'          => 'ttleague-registrations',
				'group_label'       => __( 'Spielbetrieb: Meldungen', 'ttleague' ),
				'group_description' => __( 'Meldung als Spielerin oder Spieler je Saison.', 'ttleague' ),
				'item_id'           => 'ttleague-registration-' . $person_id . '-' . $i,
				'data'              => self::rows(
					array(
						__( 'Saison', 'ttleague' )       => $registration['season'],
						__( 'Club', 'ttleague' )         => $registration['club'],
						__( 'Klassierung', 'ttleague' )  => Format::classification( $registration['classification'] ),
						__( 'Lizenznummer', 'ttleague' ) => (string) $registration['license_no'],
					)
				),
			);
		}

		$notifications = array();
		$stored        = $data['preferences'];
		foreach ( EventTypes::labels() + array_fill_keys( array_keys( $stored ), '' ) as $type => $label ) {
			$pref = $stored[ $type ] ?? array(
				'email' => true,
				'push'  => false,
			);
			/* translators: 1: ja oder nein für E-Mail, 2: ja oder nein für Push. */
			$notifications[ '' === $label ? (string) $type : $label ] = sprintf( __( 'E-Mail: %1$s, Push: %2$s', 'ttleague' ), $pref['email'] ? $yes : $no, $pref['push'] ? $yes : $no );
		}
		$notifications[ __( 'Push-Abos (Geräte)', 'ttleague' ) ] = (string) $data['push_subscriptions'];

		$items[] = array(
			'group_id'          => 'ttleague-notifications',
			'group_label'       => __( 'Spielbetrieb: Benachrichtigungen', 'ttleague' ),
			'group_description' => __( 'Einstellungen pro Ereignis und Anzahl der Geräte mit Push-Benachrichtigungen.', 'ttleague' ),
			'item_id'           => 'ttleague-notifications-' . $person_id,
			'data'              => self::rows( $notifications ),
		);
		return $items;
	}

	/**
	 * Name-Wert-Paare ohne leere Werte.
	 *
	 * @param array<string,string> $values Bezeichnung => Wert.
	 * @return list<array{name:string,value:string}>
	 */
	private static function rows( array $values ): array {
		$rows = array();
		foreach ( $values as $name => $value ) {
			if ( '' !== $value ) {
				$rows[] = array(
					'name'  => (string) $name,
					'value' => $value,
				);
			}
		}
		return $rows;
	}
}
