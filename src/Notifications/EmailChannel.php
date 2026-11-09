<?php
/**
 * Versand per E-Mail mit Text- und HTML-Teil.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

use TTLeague\Infrastructure\Mail\Mailer;
use TTLeague\Infrastructure\Mail\Message;

/**
 * Baut aus der Benachrichtigung eine Message (Anrede, Absätze, Listen, Link, Hinweis auf die
 * Einstellungen) und übergibt sie dem Versand, in WordPress Mailer::send().
 */
final class EmailChannel implements Channel {

	/** @var callable(Message):bool */
	private $send;

	/**
	 * @param callable(Message):bool $send Versand.
	 */
	public function __construct( callable $send ) {
		$this->send = $send;
	}

	public function name(): string {
		return self::EMAIL;
	}

	public function reaches( Recipient $recipient ): bool {
		return null !== $recipient->email;
	}

	public function deliver( Recipient $recipient, Notification $notification ): bool {
		if ( null === $recipient->email ) {
			return false;
		}
		return (bool) ( $this->send )( self::message( $recipient, $notification ) );
	}

	/**
	 * E-Mail für eine Person.
	 */
	public static function message( Recipient $recipient, Notification $notification ): Message {
		/* translators: %s: Vorname */
		$hello = '' === $recipient->salutation ? __( 'Hallo', 'ttleague' ) : sprintf( __( 'Hallo %s', 'ttleague' ), $recipient->salutation );
		/* translators: %s: Adresse von «Mein Bereich» */
		$settings = sprintf( __( 'Welche Benachrichtigungen du erhältst, stellst du in «Mein Bereich» ein: %s', 'ttleague' ), $notification->settings_url );

		$text = array( $hello );
		$html = '<p>' . esc_html( $hello ) . '</p>';
		foreach ( $notification->paragraphs as $paragraph ) {
			$text[] = $paragraph;
			$html  .= '<p>' . nl2br( esc_html( $paragraph ), false ) . '</p>';
		}
		foreach ( $notification->lists as $list ) {
			$lines = array( $list['heading'] );
			$html .= '<p style="margin:20px 0 8px;font-weight:600">' . esc_html( $list['heading'] ) . '</p><ul style="margin:0;padding-left:20px">';
			foreach ( $list['items'] as $item ) {
				$lines[] = '- ' . $item['text'] . ( '' === $item['url'] ? '' : "\n  " . $item['url'] );
				$html   .= '<li style="margin:0 0 6px">' . ( '' === $item['url'] ? esc_html( $item['text'] ) : '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['text'] ) . '</a>' ) . '</li>';
			}
			$html  .= '</ul>';
			$text[] = implode( "\n", $lines );
		}
		if ( null !== $notification->action ) {
			$text[] = $notification->action['label'] . ': ' . $notification->action['url'];
			$html  .= Mailer::button( $notification->action['url'], $notification->action['label'] );
		}
		$text[] = $settings;
		$html  .= '<p style="margin:24px 0 0;font-size:13px;color:#5a6472">'
			/* translators: %s: Link «Mein Bereich» */
			. sprintf( esc_html__( 'Welche Benachrichtigungen du erhältst, stellst du in %s ein.', 'ttleague' ), '<a href="' . esc_url( $notification->settings_url ) . '">' . esc_html__( '«Mein Bereich»', 'ttleague' ) . '</a>' )
			. '</p>';

		return new Message( (string) $recipient->email, $notification->subject, implode( "\n\n", $text ), $html, 'notification_' . $notification->event );
	}
}
