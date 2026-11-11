<?php
/**
 * Ereignisse der Anlässe als WordPress-Aktionen, nach der Cache-Version.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

use TTLeague\Infrastructure\Cache\DataVersion;

/**
 * Die Ereignisse folgen erst nach dem Speichern. Deshalb kommt die Cache-Version zuerst, und ein
 * Fehler in einem Listener (Benachrichtigung, Erweiterung) wird nur protokolliert: Die An- oder
 * Abmeldung ist gespeichert, die Person soll keinen Fehler sehen und sie nicht wiederholen.
 */
final class WordPressEventListener implements EventListener {

	public function event_changed( int $event_id, int $user_id, string $action ): void {
		$this->changed( $event_id );
		/**
		 * Anlass angelegt, geändert, geschlossen oder wieder geöffnet.
		 *
		 * @param int    $event_id Anlass.
		 * @param int    $user_id  Konto.
		 * @param string $action   created, updated, closed oder reopened.
		 */
		self::fire( 'ttleague_event_changed', $event_id, $user_id, $action );
	}

	public function cancelled( int $event_id, int $user_id ): void {
		$this->changed( $event_id );
		/**
		 * Anlass abgesagt.
		 *
		 * @param int $event_id Anlass.
		 * @param int $user_id  Konto.
		 */
		self::fire( 'ttleague_event_cancelled', $event_id, $user_id );
	}

	public function registered( int $event_id, int $registration_id, int $person_id, string $status, int $user_id ): void {
		$this->changed( $event_id );
		/**
		 * Person zu einem Anlass angemeldet.
		 *
		 * @param int    $event_id        Anlass.
		 * @param int    $registration_id Anmeldung.
		 * @param int    $person_id       Angemeldete Person.
		 * @param string $status          registered oder waitlist.
		 * @param int    $user_id         Konto, das angemeldet hat.
		 */
		self::fire( 'ttleague_event_registered', $event_id, $registration_id, $person_id, $status, $user_id );
	}

	public function unregistered( int $event_id, int $registration_id, int $person_id, string $status, int $user_id ): void {
		$this->changed( $event_id );
		/**
		 * Person von einem Anlass abgemeldet (die Anmeldung ist gelöscht).
		 *
		 * @param int    $event_id        Anlass.
		 * @param int    $registration_id Gelöschte Anmeldung.
		 * @param int    $person_id       Abgemeldete Person.
		 * @param string $status          Status vor der Abmeldung.
		 * @param int    $user_id         Konto, das abgemeldet hat.
		 */
		self::fire( 'ttleague_event_unregistered', $event_id, $registration_id, $person_id, $status, $user_id );
	}

	public function promoted( int $event_id, int $registration_id, int $person_id ): void {
		$this->changed( $event_id );
		/**
		 * Person von der Warteliste nachgerückt.
		 *
		 * @param int $event_id        Anlass.
		 * @param int $registration_id Anmeldung.
		 * @param int $person_id       Nachgerückte Person.
		 */
		self::fire( 'ttleague_event_promoted', $event_id, $registration_id, $person_id );
	}

	private function changed( int $event_id ): void {
		DataVersion::bump();
		/**
		 * Daten geändert (Lesemodelle, Caches).
		 *
		 * @param string $area Bereich.
		 * @param int    $id   ID.
		 */
		self::fire( 'ttleague_data_changed', 'event', $event_id );
	}

	/**
	 * Aktion auslösen; ein Fehler in einem Listener wird nur protokolliert.
	 *
	 * @param string $hook    Name der Aktion.
	 * @param mixed  ...$args Parameter.
	 */
	private static function fire( string $hook, mixed ...$args ): void {
		try {
			do_action( $hook, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Alle Namen beginnen mit ttleague_, siehe die Aufrufe oben.
		} catch ( \Throwable $e ) {
			error_log( sprintf( 'ttleague: Fehler in %s: %s: %s', $hook, $e::class, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Hinweis für die Verwaltung.
		}
	}
}
