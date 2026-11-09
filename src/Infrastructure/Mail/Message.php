<?php
/**
 * Eine E-Mail: Empfänger, Betreff, Textfassung, optionale HTML-Fassung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure\Mail;

final class Message {

	/**
	 * @param string      $to      Empfängeradresse.
	 * @param string      $subject Betreff.
	 * @param string      $text    Textfassung (immer vorhanden).
	 * @param string|null $html    HTML-Fassung (ohne Rahmen, wird vom Mailer eingebettet).
	 * @param string      $type    Art der Nachricht, z. B. magic_link (für Protokoll und Filter).
	 */
	public function __construct(
		public readonly string $to,
		public readonly string $subject,
		public readonly string $text,
		public readonly ?string $html = null,
		public readonly string $type = 'general',
	) {}
}
