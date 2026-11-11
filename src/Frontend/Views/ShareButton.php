<?php
/**
 * Knopf «Teilen» für den Matchreport und die Leseansicht der Erfassungsseite.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Frontend\Assets;

/**
 * Der Knopf ist für alle Besucher gleich (die Ausgabe bleibt zwischenspeicherbar) und ohne
 * JavaScript verborgen. assets/js/share.js blendet ihn ein: mit der Web Share API als «Teilen»,
 * sonst als «Link kopieren» mit Rückmeldung über die Live-Region. Geteilt werden ein Titel wie
 * «Seefeld 1 – Wollishofen 1 6:4» und die Adresse des Matchreports.
 */
final class ShareButton {

	/**
	 * Pfeil aus einem Kasten, Strichstärke und Farbe aus dem Text.
	 */
	private const ICON = '<svg class="ttl-share__icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 15V3M8 7l4-4 4 4M6 11H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1h-1"/></svg>';

	/**
	 * @param string $title Titel der geteilten Seite, z. B. «Seefeld 1 – Wollishofen 1 6:4».
	 * @param string $url   Adresse des Matchreports.
	 */
	public static function render( string $title, string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		if ( function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script( Assets::SHARE );
		}
		return '<div class="ttl-share" data-ttl-share data-ttl-share-title="' . esc_attr( $title ) . '" data-ttl-share-url="' . esc_url( $url ) . '"'
			. ' data-ttl-share-copy="' . esc_attr__( 'Link kopieren', 'ttleague' ) . '"'
			. ' data-ttl-share-copied="' . esc_attr__( 'Link kopiert.', 'ttleague' ) . '"'
			. ' data-ttl-share-failed="' . esc_attr__( 'Kopieren nicht möglich. Bitte die Adresse aus der Adresszeile kopieren.', 'ttleague' ) . '" hidden>'
			. '<button type="button" class="ttl-button ttl-button--secondary ttl-share__button">' . self::ICON . '<span class="ttl-share__label">' . esc_html__( 'Teilen', 'ttleague' ) . '</span></button>'
			. '<span class="ttl-share__status" role="status" aria-live="polite"></span>'
			. '</div>';
	}

	/**
	 * Titel einer Begegnung mit Resultat: «Seefeld 1 – Wollishofen 1 6:4».
	 */
	public static function title( string $home, string $away, int $home_score, int $away_score ): string {
		/* translators: 1: Heimmannschaft, 2: Gastmannschaft, 3: Resultat, z. B. 6:4. */
		return sprintf( __( '%1$s – %2$s %3$s', 'ttleague' ), $home, $away, $home_score . ':' . $away_score );
	}
}
