<?php
/**
 * Abschnitt «Kalender abonnieren» auf Team-, Club- und Spielerseite.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Calendar;

use TTLeague\Frontend\Support\Html;

/**
 * Ein Satz Erklärung, ein Knopf mit webcal://-Link (öffnet die Kalender-App zum Abonnieren)
 * und die https://-Adresse in einem Feld zum Kopieren, z. B. für Google Kalender. Der Knopf
 * «Adresse kopieren» erscheint nur mit JavaScript und Zwischenablage (assets/js/calendar.js);
 * ohne beides bleibt das Feld markierbar.
 */
final class SubscribeBox {

	/**
	 * Kalender mit Pluszeichen, Strichstärke und Farbe aus dem Text.
	 */
	private const ICON = '<svg class="ttl-subscribe__icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M8 3v4M16 3v4M4 10h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zM12 13v5M9.5 15.5h5"/></svg>';

	/**
	 * @param string $url  https://-Adresse des Feeds.
	 * @param string $text Ein Satz Erklärung.
	 * @param string $id   Anker des Abschnitts und Präfix der IDs.
	 */
	public static function render( string $url, string $text, string $id = 'ttl-kalender' ): string {
		if ( function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script( Calendar::SCRIPT );
		}
		$text_id  = $id . '-text';
		$field_id = $id . '-adresse';
		$hint_id  = $id . '-hinweis';

		$body  = '<p class="ttl-subscribe__text" id="' . esc_attr( $text_id ) . '">' . esc_html( $text ) . '</p>';
		$body .= '<p class="ttl-subscribe__actions">' . Html::link_html(
			FeedUrls::webcal( $url ),
			self::ICON . '<span>' . esc_html__( 'Im Kalender abonnieren', 'ttleague' ) . '</span>',
			array(
				'class'            => 'ttl-button ttl-subscribe__webcal',
				'aria-describedby' => $text_id,
			)
		) . '</p>';
		$body .= '<div class="ttl-subscribe__copy">'
			. '<label class="ttl-subscribe__label" for="' . esc_attr( $field_id ) . '">' . esc_html__( 'Adresse zum Kopieren', 'ttleague' ) . '</label>'
			. '<span class="ttl-subscribe__hint ttl-footnote" id="' . esc_attr( $hint_id ) . '">' . esc_html__( 'Für Google Kalender («Per URL hinzufügen»), Outlook im Web und andere Programme.', 'ttleague' ) . '</span>'
			. '<span class="ttl-subscribe__field">'
			. '<input type="text" inputmode="url" class="ttl-subscribe__url" id="' . esc_attr( $field_id ) . '" value="' . esc_url( $url ) . '" readonly spellcheck="false" autocomplete="off" aria-describedby="' . esc_attr( $hint_id ) . '">'
			. '<button type="button" class="ttl-button ttl-button--secondary ttl-subscribe__button" data-ttl-copy="' . esc_attr( $field_id ) . '" data-ttl-copied="' . esc_attr__( 'Adresse kopiert.', 'ttleague' ) . '" hidden>' . esc_html__( 'Adresse kopieren', 'ttleague' ) . '</button>'
			. '</span>'
			. '<span class="ttl-subscribe__status" role="status" aria-live="polite"></span>'
			. '</div>';

		return Html::section( __( 'Kalender abonnieren', 'ttleague' ), $body, 'ttl-section--subscribe ttl-subscribe', $id );
	}
}
