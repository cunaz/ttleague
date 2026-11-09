<?php
/**
 * Ereignisse der Erfassung als WordPress-Aktionen, nach Cache-Version und Wertungszahl.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

use TTLeague\Infrastructure\Cache\DataVersion;

/**
 * Die Ereignisse folgen erst nach dem Speichern. Deshalb kommen Cache-Version und Neuberechnung
 * zuerst, und ein Fehler in einem Listener (z. B. einer Erweiterung) wird nur protokolliert: Die
 * Aktion ist gespeichert, der Client soll keinen Fehler sehen und sie nicht wiederholen.
 *
 * Ausnahme Foto: Es erscheint nur mit Rechten über die REST-API, nie auf öffentlichen Seiten,
 * und ändert keine Wertung. photo_added() lässt Cache-Version und Neuberechnung deshalb aus.
 */
final class WordPressResultEvents implements ResultEvents {

	public function entered( int $fixture_id, int $user_id, bool $edited ): void {
		$this->changed( $fixture_id );
		try {
			/**
			 * Resultat erfasst oder geändert.
			 *
			 * @param int  $fixture_id Begegnung.
			 * @param int  $user_id    Konto.
			 * @param bool $edited     Änderung eines provisorischen Resultats.
			 */
			do_action( 'ttleague_result_entered', $fixture_id, $user_id, $edited );
		} catch ( \Throwable $e ) {
			self::listener_failed( 'ttleague_result_entered', $e );
		}
	}

	public function confirmed( int $fixture_id, int $user_id, bool $by_tk ): void {
		$this->changed( $fixture_id );
		try {
			/**
			 * Resultat bestätigt.
			 *
			 * @param int  $fixture_id Begegnung.
			 * @param int  $user_id    Konto.
			 * @param bool $by_tk      Durch die TK statt durch die Gastseite.
			 */
			do_action( 'ttleague_result_confirmed', $fixture_id, $user_id, $by_tk );
		} catch ( \Throwable $e ) {
			self::listener_failed( 'ttleague_result_confirmed', $e );
		}
	}

	public function disputed( int $fixture_id, int $user_id, string $reason ): void {
		$this->changed( $fixture_id );
		try {
			/**
			 * Einsprache gegen ein Resultat.
			 *
			 * @param int    $fixture_id Begegnung.
			 * @param int    $user_id    Konto.
			 * @param string $reason     Begründung.
			 */
			do_action( 'ttleague_result_disputed', $fixture_id, $user_id, $reason );
		} catch ( \Throwable $e ) {
			self::listener_failed( 'ttleague_result_disputed', $e );
		}
	}

	public function decided( int $fixture_id, int $user_id, string $action, ?string $comment ): void {
		$this->changed( $fixture_id );
		try {
			/**
			 * Entscheid der TK (finalize, correct, forfeit, reset).
			 *
			 * @param int         $fixture_id Begegnung.
			 * @param int         $user_id    Konto.
			 * @param string      $action     Entscheidung.
			 * @param string|null $comment    Bemerkung.
			 */
			do_action( 'ttleague_result_decided', $fixture_id, $user_id, $action, $comment );
		} catch ( \Throwable $e ) {
			self::listener_failed( 'ttleague_result_decided', $e );
		}
	}

	public function photo_added( int $fixture_id, int $user_id, int $attachment_id ): void {
		try {
			/**
			 * Foto des Spielberichts hochgeladen.
			 *
			 * @param int $fixture_id    Begegnung.
			 * @param int $user_id       Konto.
			 * @param int $attachment_id Anhang.
			 */
			do_action( 'ttleague_result_photo', $fixture_id, $user_id, $attachment_id );
		} catch ( \Throwable $e ) {
			self::listener_failed( 'ttleague_result_photo', $e );
		}
	}

	private function changed( int $fixture_id ): void {
		DataVersion::bump();
		Results::schedule_rating_recalc();
		try {
			/**
			 * Daten geändert (Lesemodelle, Caches, Kalender).
			 *
			 * @param string $area Bereich.
			 * @param int    $id   ID.
			 */
			do_action( 'ttleague_data_changed', 'fixture', $fixture_id );
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
