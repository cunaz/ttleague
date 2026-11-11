<?php
/**
 * Ereignisse des Saison-Assistenten in WordPress: Datenversion, Mitteilungen, Aktionen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Season;

use TTLeague\Access\Capabilities;
use TTLeague\Infrastructure\Cache\DataVersion;
use TTLeague\Notifications\Notifications;
use TTLeague\Results\Results;

/**
 * Die Ereignisse folgen erst nach dem Speichern. Ein Fehler in einem Listener oder beim
 * Einplanen der Mitteilungen wird deshalb nur gemeldet: Die Änderung ist gespeichert, niemand
 * soll sie wiederholen.
 */
final class WordPressSeasonEvents implements SeasonEvents {

	public function changed( int $season_id ): void {
		DataVersion::bump();
		$this->data_changed( $season_id );
	}

	public function published( int $season_id, ?int $archived_id, int $user_id ): void {
		DataVersion::bump();
		// Rechte hängen an der aktiven Saison: gemerkte Rollen dieser Anfrage verwerfen.
		Capabilities::use_policy( null );
		// Die Mitteilungen an Captains und TK-Chefs gehen nie in dieser Anfrage hinaus, sondern in Schritten über WP-Cron.
		SeasonAdmin::on_published( $season_id );
		try {
			/**
			 * Saison freigegeben: Sie ist jetzt aktiv, die bisher aktive archiviert.
			 *
			 * @param int      $season_id   Freigegebene Saison.
			 * @param int|null $archived_id Archivierte Saison.
			 * @param int      $user_id     Konto, das freigegeben hat.
			 */
			do_action( 'ttleague_season_published', $season_id, $archived_id, $user_id );
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, 'ttleague_season_published' );
		}
		$this->data_changed( $season_id );
	}

	public function team_withdrawn( int $season_id, int $team_id ): void {
		DataVersion::bump();
		// Die gespielten Begegnungen der Mannschaft sind forfait gewertet: Ihre Spiele zählen nicht mehr.
		Results::schedule_rating_recalc();
		try {
			/**
			 * Mannschaft zurückgezogen (Art. 13.3).
			 *
			 * @param int $team_id   Mannschaft.
			 * @param int $season_id Saison.
			 */
			do_action( 'ttleague_team_withdrawn', $team_id, $season_id );
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, 'ttleague_team_withdrawn' );
		}
		$this->data_changed( $season_id );
	}

	public function closed( int $season_id ): void {
		DataVersion::bump();
		Capabilities::use_policy( null );
		$this->data_changed( $season_id );
	}

	private function data_changed( int $season_id ): void {
		try {
			/**
			 * Daten geändert (Lesemodelle, Caches, Kalender).
			 *
			 * @param string $area Bereich.
			 * @param int    $id   ID.
			 */
			do_action( 'ttleague_data_changed', 'season', $season_id );
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, 'ttleague_data_changed season' );
		}
	}
}
