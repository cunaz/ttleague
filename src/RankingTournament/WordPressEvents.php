<?php
/**
 * Ereignisse des Ranglistenturniers in WordPress: Cache-Version, Wertungszahl, Aufgebot.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Infrastructure\Cache\DataVersion;
use TTLeague\Notifications\Notifications;
use TTLeague\Results\Results;

/**
 * Wie bei der Resultaterfassung: zuerst die Cache-Version, bei geänderten Partien die
 * entprellte Neuberechnung der Wertungszahl (ttleague_rating_recalc), danach die Aktion
 * ttleague_data_changed. Ein Fehler in einem Listener oder beim Vormerken des Versands wird nur
 * protokolliert; die Aktion ist gespeichert.
 */
final class WordPressEvents implements Events {

	public function changed( string $area, int $id, bool $games = false ): void {
		DataVersion::bump();
		if ( $games ) {
			Results::schedule_rating_recalc();
		}
		try {
			/** This action is documented in src/Results/WordPressResultEvents.php */
			do_action( 'ttleague_data_changed', $area, $id );
		} catch ( \Throwable $e ) {
			error_log( sprintf( 'ttleague: Fehler in ttleague_data_changed: %s: %s', $e::class, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Hinweis für die Verwaltung.
		}
	}

	/**
	 * Das Aufgebot geht nicht in dieser Anfrage hinaus: Der Versand wird nur vorgemerkt und läuft
	 * danach über WP-Cron in begrenzten Schritten (RankingTournament::send_callups()). Welche
	 * Gruppen betroffen sind, rechnet der Schritt aus dem Versandprotokoll.
	 */
	public function callup( int $round_id, array $group_ids, int $user_id, bool $changed ): void {
		unset( $group_ids, $user_id, $changed );
		try {
			RankingTournament::schedule_callups( $round_id );
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, Notices::CALLUP );
		}
	}
}
