<?php
/**
 * Ein Ereignis (VEVENT) eines iCalendar-Kalenders.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Calendar;

/**
 * Reiner Wert ohne WordPress. Zeitpunkte werden in der Zeitzone des Kalenders
 * (IcsWriter::TIMEZONE) ausgegeben; bei Ganztagesereignissen zählt nur das Datum.
 */
final class IcsEvent {

	public const STATUS_CONFIRMED = 'CONFIRMED';
	public const STATUS_TENTATIVE = 'TENTATIVE';
	public const STATUS_CANCELLED = 'CANCELLED';

	/**
	 * @param string                      $uid         Stabile, weltweit eindeutige Kennung, z. B. fixture-12@example.org.
	 * @param string                      $summary     Titel.
	 * @param \DateTimeImmutable          $start       Beginn bzw. Datum bei Ganztagesereignissen.
	 * @param int                         $minutes     Dauer in Minuten (bei Ganztagesereignissen ohne Bedeutung).
	 * @param bool                        $all_day     Ganztagesereignis, weil die Anspielzeit unbekannt ist.
	 * @param string                      $location    Ort.
	 * @param string                      $description Beschreibung, mehrzeilig.
	 * @param string                      $url         Link auf die Detailseite.
	 * @param string                      $status      CONFIRMED, TENTATIVE, CANCELLED oder leer.
	 * @param list<string>                $categories  Kategorien, z. B. «Meisterschaft».
	 * @param array{0:float,1:float}|null $geo         Breite und Länge des Ortes.
	 */
	public function __construct(
		public readonly string $uid,
		public readonly string $summary,
		public readonly \DateTimeImmutable $start,
		public readonly int $minutes = 0,
		public readonly bool $all_day = false,
		public readonly string $location = '',
		public readonly string $description = '',
		public readonly string $url = '',
		public readonly string $status = '',
		public readonly array $categories = array(),
		public readonly ?array $geo = null,
	) {}
}
