<?php
/**
 * Ereignisse der Meldung in WordPress: Datenversion, Benachrichtigung, Aktionen für Erweiterungen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Registration;

use TTLeague\Infrastructure\Cache\DataVersion;
use TTLeague\Notifications\Notifications;

/**
 * Die Ereignisse folgen erst nach dem Speichern. Ein Fehler beim Versand oder in einem Listener
 * wird deshalb nur gemeldet: Die Änderung ist gespeichert, niemand soll sie wiederholen.
 */
final class WordPressRegistrationEvents implements RegistrationEvents {

	public function changed( ?int $season_id, int $club_id ): void {
		DataVersion::bump();
		try {
			/**
			 * Daten geändert (Lesemodelle, Caches, Kalender).
			 *
			 * @param string $area Bereich.
			 * @param int    $id   ID.
			 */
			do_action( 'ttleague_data_changed', 'registration', $club_id );
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, 'ttleague_data_changed registration' );
		}
	}

	public function decided( Decision $decision ): void {
		try {
			Registration::notifications()->decided( $decision );
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, RegistrationMessages::DECIDED . ' ' . $decision->kind );
		}
		try {
			/**
			 * Entscheid der TK zu einer Meldung.
			 *
			 * @param Decision $decision Entscheid.
			 */
			do_action( 'ttleague_registration_decided', $decision );
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, 'ttleague_registration_decided' );
		}
	}
}
