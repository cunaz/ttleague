<?php
/**
 * Ereignisse der Verschiebung als WordPress-Aktionen, nach der Cache-Version.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Postponement;

use TTLeague\Infrastructure\Cache\DataVersion;

/**
 * Wie bei der Erfassung: Die Ereignisse folgen erst nach dem Speichern. Zuerst kommt die
 * Cache-Version (Spielpläne und Kalender zeigen den neuen Termin sofort), und ein Fehler in
 * einem Listener wird nur protokolliert: Die Aktion ist gespeichert, niemand soll sie wiederholen.
 */
final class WordPressPostponementEvents implements PostponementEvents {

	public function changed( int $postponement_id, string $action, int $user_id, ?int $person_id ): void {
		DataVersion::bump();
		try {
			/**
			 * Ein Antrag auf Verschiebung (oder ein Terminvorschlag) wurde gestellt oder hat den
			 * Status gewechselt.
			 *
			 * @param int      $postponement_id Antrag.
			 * @param string   $action          request, withdraw, decline, accept, approve oder reject.
			 * @param int      $user_id         Handelndes Konto.
			 * @param int|null $person_id       Handelnde Person.
			 */
			do_action( 'ttleague_postponement_changed', $postponement_id, $action, $user_id, $person_id );
		} catch ( \Throwable $e ) {
			self::listener_failed( 'ttleague_postponement_changed', $e );
		}
		try {
			/** Dokumentiert in WordPressResultEvents. */
			do_action( 'ttleague_data_changed', 'postponement', $postponement_id );
		} catch ( \Throwable $e ) {
			self::listener_failed( 'ttleague_data_changed', $e );
		}
	}

	/**
	 * Ein Listener ist gescheitert, nachdem die Aktion gespeichert war: nur protokollieren.
	 */
	private static function listener_failed( string $hook, \Throwable $e ): void {
		error_log( sprintf( 'ttleague: Fehler in %s: %s: %s', $hook, $e::class, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Hinweis für die Verwaltung.
	}
}
