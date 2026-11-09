<?php
/**
 * «Mein Bereich»: Push auf diesem Gerät aktivieren oder deaktivieren.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications\Push;

use TTLeague\Pwa\Manifest;

/**
 * Knopf und Hinweise unter den Benachrichtigungseinstellungen (Filter ttleague_push_controls in
 * Accounts\Blocks), dazu das Skript assets/js/push.js ohne Build-Schritt. Das Skript meldet bei
 * Bedarf den Service Worker an, fragt die Erlaubnis an, abonniert mit dem öffentlichen
 * VAPID-Schlüssel und meldet das Abo an die REST-API. Ohne JavaScript bleibt nur der Hinweis.
 */
final class PushUi {

	public const SCRIPT = 'ttleague-push';

	/**
	 * Filter ttleague_push_controls.
	 *
	 * @param mixed $html      Bisherige Ausgabe.
	 * @param mixed $person_id Person.
	 */
	public static function controls( mixed $html, mixed $person_id = 0 ): string {
		unset( $person_id );
		$html  = is_string( $html ) ? $html : '';
		$vapid = VapidKeys::get();
		if ( null === $vapid ) {
			return $html;
		}
		self::enqueue( $vapid );
		return $html . '<div class="ttl-push" data-ttl-push>'
			. '<p class="ttl-field"><span class="ttl-label">' . esc_html__( 'Push auf diesem Gerät', 'ttleague' ) . '</span> '
			. '<span data-ttl-push-status role="status" aria-live="polite">' . esc_html__( 'Für Push-Benachrichtigungen braucht es JavaScript.', 'ttleague' ) . '</span></p>'
			. '<p data-ttl-push-actions hidden><button type="button" class="ttl-button ttl-button--secondary" data-ttl-push-toggle>' . esc_html__( 'Push auf diesem Gerät aktivieren', 'ttleague' ) . '</button></p>'
			. '<p class="ttl-muted" data-ttl-push-ios>' . esc_html__( 'iPhone und iPad: Push-Benachrichtigungen gibt es nur, wenn du die Website als App auf dem Home-Bildschirm installierst (in Safari «Teilen» › «Zum Home-Bildschirm», ab iOS 16.4) und sie von dort öffnest.', 'ttleague' ) . '</p>'
			. '<p class="ttl-muted">' . esc_html__( 'Wähle in der Tabelle, welche Benachrichtigungen als Push kommen sollen. Ohne Push auf einem Gerät erhältst du sie per E-Mail.', 'ttleague' ) . '</p>'
			. '</div>';
	}

	private static function enqueue( Vapid $vapid ): void {
		$file = 'assets/js/push.js';
		$path = TTLEAGUE_DIR . $file;
		wp_enqueue_script(
			self::SCRIPT,
			TTLEAGUE_URL . $file,
			array(),
			is_readable( $path ) ? TTLEAGUE_VERSION . '.' . (string) filemtime( $path ) : TTLEAGUE_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		$base = Manifest::base( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		wp_add_inline_script(
			self::SCRIPT,
			'window.ttleaguePush = ' . wp_json_encode(
				array(
					'endpoint' => rest_url( SubscriptionController::NAMESPACE . SubscriptionController::ROUTE ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'key'      => $vapid->public_key(),
					'sw'       => $base . 'sw.js',
					'scope'    => $base,
					'texts'    => array(
						'on'          => __( 'Aktiv. Dieses Gerät erhält Push-Benachrichtigungen.', 'ttleague' ),
						'off'         => __( 'Nicht aktiv.', 'ttleague' ),
						'enable'      => __( 'Push auf diesem Gerät aktivieren', 'ttleague' ),
						'disable'     => __( 'Push auf diesem Gerät deaktivieren', 'ttleague' ),
						'working'     => __( 'Einen Moment …', 'ttleague' ),
						'unsupported' => __( 'Dieser Browser unterstützt keine Push-Benachrichtigungen.', 'ttleague' ),
						'install'     => __( 'Auf dem iPhone und iPad zuerst die Website zum Home-Bildschirm hinzufügen und von dort öffnen.', 'ttleague' ),
						'denied'      => __( 'Push-Benachrichtigungen sind für diese Website im Browser blockiert. Erlaube sie in den Einstellungen des Browsers und lade die Seite neu.', 'ttleague' ),
						'error'       => __( 'Das hat nicht geklappt. Bitte versuche es später noch einmal.', 'ttleague' ),
					),
				)
			) . ';',
			'before'
		);
	}
}
