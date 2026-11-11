<?php
/**
 * Benachrichtigungen der Meldung: Entscheide der TK und Erinnerungen vor Ablauf des Meldefensters.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Registration;

use TTLeague\Domain\Registration\RegistrationRules as Rules;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Notifications\Deadlines;
use TTLeague\Notifications\Notifier;
use TTLeague\Notifications\RecipientResolver;
use TTLeague\Season\SeasonRepository;

/**
 * Empfänger sind die TK-Chefs des Clubs in der Saison der Meldung (club_role), nur aktive
 * Personen. Wer entschieden hat, erhält keine Nachricht über den eigenen Entscheid.
 *
 * Die Erinnerungen laufen im stündlichen Fristenlauf der Benachrichtigungen mit (kein eigener
 * Cron). Wie dort geht zwischen until_hour und from_hour (Ortszeit) nichts hinaus, ein
 * verpasster Lauf wird nachgeholt, und das Versandprotokoll verhindert doppelten Versand: Bezug
 * ist der Meldestand des Clubs mit letztem Tag des Fensters und Stufe. Von mehreren fälligen
 * Stufen geht nur die letzte hinaus (RegistrationRules::reminder_step()).
 */
final class RegistrationNotifications {

	private RegistrationRepository $repo;

	private SeasonRepository $seasons;

	private int $from_hour;

	private int $until_hour;

	/** @var list<int> */
	private array $days;

	/**
	 * @param array{from_hour?:int,until_hour?:int} $hours Versandfenster der Erinnerungen (Ortszeit).
	 * @param list<int>                             $days  Stufen der Erinnerung in Tagen vor dem letzten Tag.
	 */
	public function __construct(
		Db $db,
		private readonly Clock $clock,
		private readonly \DateTimeZone $timezone,
		private readonly RecipientResolver $recipients,
		private readonly Notifier $notifier,
		private readonly RegistrationMessages $messages,
		array $hours = array(),
		array $days = Rules::REMINDER_DAYS,
	) {
		$this->repo       = new RegistrationRepository( $db );
		$this->seasons    = new SeasonRepository( $db );
		$this->from_hour  = max( 0, min( 23, (int) ( $hours['from_hour'] ?? Deadlines::FROM_HOUR ) ) );
		$this->until_hour = max( $this->from_hour + 1, min( 24, (int) ( $hours['until_hour'] ?? Deadlines::UNTIL_HOUR ) ) );
		$this->days       = array_values( array_filter( array_map( 'intval', $days ), static fn( int $day ): bool => $day >= 0 ) );
	}

	/**
	 * Entscheid der TK an die TK-Chefs des Clubs.
	 *
	 * @return list<array{recipient:string,channel:string,status:string}>
	 */
	public function decided( Decision $decision ): array {
		return $this->notifier->send(
			$this->messages->decided( $decision ),
			$this->recipients->persons( $this->repo->tk_chefs( $decision->season_id, $decision->club_id ) ),
			$decision->user_id,
			$decision->person_id
		);
	}

	/**
	 * Ein Lauf der Erinnerungen; liefert, was versendet wurde.
	 *
	 * @return list<array{event:string,ref:int,deliveries:list<array{recipient:string,channel:string,status:string}>}>
	 */
	public function remind(): array {
		$local = $this->clock->now()->setTimezone( $this->timezone );
		$hour  = (int) $local->format( 'G' );
		if ( $hour < $this->from_hour || $hour >= $this->until_hour ) {
			return array();
		}
		$season = $this->seasons->planned();
		if ( null === $season ) {
			return array();
		}
		$today = $local->format( 'Y-m-d' );
		$step  = Rules::reminder_step( $today, $season['registration_opens'], $season['registration_closes'], $this->days );
		if ( null === $step ) {
			return array();
		}
		$left   = (int) ( new \DateTimeImmutable( $today ) )->diff( new \DateTimeImmutable( (string) $season['registration_closes'] ) )->days;
		$report = array();
		foreach ( $this->repo->overview( (int) $season['id'] ) as $row ) {
			if ( Rules::OPEN !== $row['status'] ) {
				continue;
			}
			$club_season = $this->repo->club_season( (int) $season['id'], $row['club_id'] );
			if ( null === $club_season ) {
				continue;
			}
			$notification = $this->messages->reminder(
				$season,
				array(
					'id'   => $row['club_id'],
					'name' => $row['name'],
				),
				$club_season['id'],
				$step,
				$left
			);
			$report[]     = array(
				'event'      => $notification->event,
				'ref'        => $notification->ref_id,
				'deliveries' => $this->notifier->send( $notification, $this->recipients->persons( $this->repo->tk_chefs( (int) $season['id'], $row['club_id'] ) ) ),
			);
		}
		return $report;
	}
}
