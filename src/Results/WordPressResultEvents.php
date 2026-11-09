<?php
/**
 * Ereignisse der Erfassung als WordPress-Aktionen; danach Cache-Version und Wertungszahl.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

use TTLeague\Infrastructure\Cache\DataVersion;

final class WordPressResultEvents implements ResultEvents {

	public function entered( int $fixture_id, int $user_id, bool $edited ): void {
		/**
		 * Resultat erfasst oder geändert.
		 *
		 * @param int  $fixture_id Begegnung.
		 * @param int  $user_id    Konto.
		 * @param bool $edited     Änderung eines provisorischen Resultats.
		 */
		do_action( 'ttleague_result_entered', $fixture_id, $user_id, $edited );
		$this->changed( $fixture_id );
	}

	public function confirmed( int $fixture_id, int $user_id, bool $by_tk ): void {
		/**
		 * Resultat bestätigt.
		 *
		 * @param int  $fixture_id Begegnung.
		 * @param int  $user_id    Konto.
		 * @param bool $by_tk      Durch die TK statt durch die Gastseite.
		 */
		do_action( 'ttleague_result_confirmed', $fixture_id, $user_id, $by_tk );
		$this->changed( $fixture_id );
	}

	public function disputed( int $fixture_id, int $user_id, string $reason ): void {
		/**
		 * Einsprache gegen ein Resultat.
		 *
		 * @param int    $fixture_id Begegnung.
		 * @param int    $user_id    Konto.
		 * @param string $reason     Begründung.
		 */
		do_action( 'ttleague_result_disputed', $fixture_id, $user_id, $reason );
		$this->changed( $fixture_id );
	}

	public function decided( int $fixture_id, int $user_id, string $action, ?string $comment ): void {
		/**
		 * Entscheid der TK (finalize, correct, forfeit, reset).
		 *
		 * @param int         $fixture_id Begegnung.
		 * @param int         $user_id    Konto.
		 * @param string      $action     Entscheidung.
		 * @param string|null $comment    Bemerkung.
		 */
		do_action( 'ttleague_result_decided', $fixture_id, $user_id, $action, $comment );
		$this->changed( $fixture_id );
	}

	public function photo_added( int $fixture_id, int $user_id, int $attachment_id ): void {
		/**
		 * Foto des Spielberichts hochgeladen.
		 *
		 * @param int $fixture_id    Begegnung.
		 * @param int $user_id       Konto.
		 * @param int $attachment_id Anhang.
		 */
		do_action( 'ttleague_result_photo', $fixture_id, $user_id, $attachment_id );
	}

	private function changed( int $fixture_id ): void {
		/**
		 * Daten geändert (Lesemodelle, Caches, Kalender).
		 *
		 * @param string $area Bereich.
		 * @param int    $id   ID.
		 */
		do_action( 'ttleague_data_changed', 'fixture', $fixture_id );
		DataVersion::bump();
		Results::schedule_rating_recalc();
	}
}
