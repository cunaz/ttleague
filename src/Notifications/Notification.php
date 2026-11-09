<?php
/**
 * Eine Benachrichtigung: Art, Bezug für die Dedupe und Inhalt für E-Mail und Push.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

/**
 * Der Inhalt ist neutral formuliert; die Anrede setzt der Kanal pro Person davor.
 * Absätze und Listen sind reiner Text, die E-Mail maskiert sie beim Aufbau des HTML.
 */
final class Notification {

	/**
	 * @param string                                                                 $type     Ereignistyp der Einstellungen (EventTypes).
	 * @param string                                                                 $event    Art der Nachricht für das Protokoll, z. B. confirm_reminder.
	 * @param string                                                                 $ref_type Bezug: fixture, fixture_event oder day.
	 * @param int                                                                    $ref_id   ID des Bezugs (Tag als JJJJMMTT).
	 * @param string                                                                 $ref_key  Zusatz zum Bezug, z. B. Anspielzeit oder Stufe.
	 * @param string                                                                 $subject  Betreff.
	 * @param list<string>                                                           $paragraphs Absätze.
	 * @param list<array{heading:string,items:list<array{text:string,url:string}>}> $lists    Listen (Übersicht der TK).
	 * @param array{url:string,label:string}|null                                    $action   Hauptlink.
	 * @param string                                                                 $settings_url Adresse der Einstellungen («Mein Bereich»).
	 * @param array{title:string,body:string,url:string,tag:string}                  $push     Inhalt der Push-Nachricht.
	 * @param int                                                                    $ttl      Gültigkeit der Push-Nachricht in Sekunden.
	 * @param string                                                                 $urgency  Dringlichkeit der Push-Nachricht (very-low, low, normal, high).
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $event,
		public readonly string $ref_type,
		public readonly int $ref_id,
		public readonly string $ref_key,
		public readonly string $subject,
		public readonly array $paragraphs,
		public readonly array $lists,
		public readonly ?array $action,
		public readonly string $settings_url,
		public readonly array $push,
		public readonly int $ttl = 86400,
		public readonly string $urgency = 'normal',
	) {}
}
