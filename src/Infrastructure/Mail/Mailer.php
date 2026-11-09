<?php
/**
 * Versand von E-Mails über wp_mail mit Text- und HTML-Teil.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure\Mail;

/**
 * Der Versand läuft über wp_mail, damit ein SMTP- oder API-Plugin des Hostings greift.
 * Die HTML-Fassung wird in einen schlichten Rahmen gesetzt; die Textfassung geht als
 * AltBody mit. Vor dem Versand ist die Nachricht über den Filter ttleague_mail änderbar
 * oder mit false unterdrückbar.
 */
final class Mailer {

	private ?string $alt_body = null;

	public function send( Message $message ): bool {
		return true === $this->dispatch( $message );
	}

	/**
	 * Wie send(), aber ein Fehlschlag von wp_mail wirft eine RuntimeException, damit der
	 * Aufrufer ihn melden kann. false heisst nur noch: über ttleague_mail unterdrückt, also
	 * absichtlich nicht versendet. Die Meldung nennt weder Empfänger noch den Text von
	 * PHPMailer, der Adressen enthalten kann.
	 *
	 * @throws \RuntimeException Wenn wp_mail scheitert.
	 */
	public function send_or_throw( Message $message ): bool {
		$sent = $this->dispatch( $message );
		if ( false === $sent ) {
			throw new \RuntimeException( 'wp_mail meldet einen Fehler, die E-Mail ist nicht versendet.' );
		}
		return true === $sent;
	}

	/**
	 * @return bool|null true versendet, false von wp_mail abgelehnt, null über ttleague_mail unterdrückt.
	 */
	private function dispatch( Message $message ): ?bool {
		/**
		 * Nachricht vor dem Versand anpassen oder unterdrücken (false).
		 *
		 * @param Message|false $message Nachricht.
		 */
		$message = apply_filters( 'ttleague_mail', $message );
		if ( ! $message instanceof Message ) {
			return null;
		}
		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$body    = $this->frame( $site, $message );

		$this->alt_body = $message->text . "\n\n-- \n" . $site . "\n" . home_url( '/' );
		add_action( 'phpmailer_init', array( $this, 'add_alt_body' ) );
		try {
			return (bool) wp_mail( $message->to, $message->subject, $body, $headers );
		} finally {
			remove_action( 'phpmailer_init', array( $this, 'add_alt_body' ) );
			$this->alt_body = null;
		}
	}

	/**
	 * @param object $phpmailer PHPMailer-Instanz.
	 */
	public function add_alt_body( $phpmailer ): void {
		if ( null !== $this->alt_body && property_exists( $phpmailer, 'AltBody' ) ) {
			$phpmailer->AltBody = $this->alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	private function frame( string $site, Message $message ): string {
		$content = $message->html ?? wpautop( esc_html( $message->text ) );
		$footer  = sprintf(
			/* translators: %s: Name der Seite */
			esc_html__( 'Diese Nachricht wurde automatisch von %s versendet.', 'ttleague' ),
			esc_html( $site )
		);
		return '<!doctype html><html lang="de-CH"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
			. '<body style="margin:0;padding:24px;background:#f4f6f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1b2430;line-height:1.5">'
			. '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px">'
			. '<p style="margin:0 0 16px;font-weight:600;color:#0b3a75">' . esc_html( $site ) . '</p>'
			. $content
			. '<p style="margin:24px 0 0;font-size:13px;color:#5a6472">' . $footer . '</p>'
			. '</div></body></html>';
	}

	/**
	 * HTML-Knopf für Links in E-Mails.
	 */
	public static function button( string $url, string $label ): string {
		return '<p style="margin:24px 0"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#0b3a75;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:600">' . esc_html( $label ) . '</a></p>';
	}
}
