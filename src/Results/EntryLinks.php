<?php
/**
 * Knopf zur Erfassungsseite im Matchreport.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

use TTLeague\Access\Capabilities;
use TTLeague\Domain\Match\FixtureWorkflow as Flow;

/**
 * Nur angemeldete Personen mit Recht sehen den Knopf; für Gäste bleibt der Matchreport
 * unverändert, damit er zwischengespeichert werden kann.
 */
final class EntryLinks {

	/**
	 * Knopf für die aktuelle Person oder leer.
	 */
	public static function report_button( int $fixture_id, string $status ): string {
		if ( $fixture_id <= 0 || ! is_user_logged_in() ) {
			return '';
		}
		$label  = self::label(
			$status,
			current_user_can( Capabilities::ENTER_RESULT, $fixture_id ),
			current_user_can( Capabilities::CONFIRM, $fixture_id ),
			current_user_can( Capabilities::TK )
		);
		$button = null === $label ? '' : '<p class="ttl-actions ttl-report-action"><a class="ttl-button" href="' . esc_url( Results::entry_url( $fixture_id ) ) . '">' . esc_html( $label ) . '</a></p>';
		/**
		 * Weitere Knöpfe im Matchreport für angemeldete Personen, z. B. «Termin verschieben».
		 * Fertiges, escaptes HTML; jedes Modul prüft das Recht selbst.
		 *
		 * @param string $html       Knöpfe.
		 * @param int    $fixture_id Begegnung.
		 * @param string $status     Status der Begegnung.
		 */
		return $button . (string) apply_filters( 'ttleague_report_actions', '', $fixture_id, $status );
	}

	/**
	 * Beschriftung je nach Status und Rechten, null ohne passende Aktion.
	 *
	 * @param string $status      Status der Begegnung.
	 * @param bool   $can_enter   ttl_enter_result für diese Begegnung.
	 * @param bool   $can_confirm ttl_confirm_result für diese Begegnung.
	 * @param bool   $is_tk       ttl_tk.
	 */
	public static function label( string $status, bool $can_enter, bool $can_confirm, bool $is_tk ): ?string {
		if ( in_array( $status, array( Flow::SCHEDULED, Flow::POSTPONED ), true ) ) {
			return $can_enter ? __( 'Resultat erfassen', 'ttleague' ) : null;
		}
		if ( Flow::PROVISIONAL === $status ) {
			if ( $is_tk || ( $can_confirm && ! $can_enter ) ) {
				return __( 'Resultat bestätigen', 'ttleague' );
			}
			return $can_enter ? __( 'Resultat ändern', 'ttleague' ) : null;
		}
		return $is_tk ? __( 'Entscheid der TK', 'ttleague' ) : null;
	}
}
