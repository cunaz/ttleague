<?php
/**
 * Hallensperre: ein Lokal ist an ganzen Tagen nicht verfügbar.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Schedule;

final class VenueClosure {

	/**
	 * Die Sperre gilt für ganze Kalendertage, erster und letzter Tag eingeschlossen. Uhrzeiten
	 * in «von» und «bis» zählen nicht.
	 *
	 * @param int|string         $venue_id Lokal.
	 * @param \DateTimeImmutable $from     Erster gesperrter Tag.
	 * @param \DateTimeImmutable $to       Letzter gesperrter Tag.
	 * @param string             $reason   Grund, z. B. «Hallenreinigung».
	 */
	public function __construct(
		public readonly int|string $venue_id,
		public readonly \DateTimeImmutable $from,
		public readonly \DateTimeImmutable $to,
		public readonly string $reason = '',
	) {
		if ( $from->format( 'Y-m-d' ) > $to->format( 'Y-m-d' ) ) {
			throw new \InvalidArgumentException( 'Hallensperre: «von» liegt nach «bis».' );
		}
	}

	/**
	 * Fällt der Tag des Termins in die Sperre?
	 */
	public function covers( \DateTimeInterface $date ): bool {
		$day = $date->format( 'Y-m-d' );
		return $day >= $this->from->format( 'Y-m-d' ) && $day <= $this->to->format( 'Y-m-d' );
	}
}
