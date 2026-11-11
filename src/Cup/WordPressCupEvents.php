<?php
/**
 * Ereignisse des Mannschaftscups als WordPress-Aktionen, nach der Cache-Version.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cup;

use TTLeague\Infrastructure\Cache\DataVersion;

/**
 * Wie bei der Erfassung: Die Ereignisse folgen erst nach dem Speichern. Zuerst kommt die
 * Cache-Version (die Cup-Seite zeigt Meldungen und Tableau sofort), und ein Fehler in einem
 * Listener wird nur protokolliert: Die Aktion ist gespeichert, niemand soll sie wiederholen.
 */
final class WordPressCupEvents implements CupEvents {

	public function changed( int $competition_id, string $action, int $user_id, array $tie_ids = array() ): void {
		DataVersion::bump();
		try {
			/**
			 * Meldungen, Auslosung oder Tableau einer Cup-Konkurrenz haben sich geändert.
			 *
			 * @param int       $competition_id Konkurrenz.
			 * @param string    $action         Art der Änderung (Konstanten in CupEvents).
			 * @param int       $user_id        Handelndes Konto, 0 für das System.
			 * @param list<int> $tie_ids        Paarungen, über die die Mannschaften informiert werden.
			 */
			do_action( 'ttleague_cup_changed', $competition_id, $action, $user_id, $tie_ids );
		} catch ( \Throwable $e ) {
			self::listener_failed( 'ttleague_cup_changed', $e );
		}
		try {
			/** Dokumentiert in WordPressResultEvents. */
			do_action( 'ttleague_data_changed', 'cup', $competition_id );
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
