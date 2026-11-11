<?php
/**
 * Wer darf an der Meldung eines Clubs was ändern? Saisonstatus, Meldefenster, Meldestand.
 *
 * @package TTLeague
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldungen der Ausnahmen sind Klartext; escaped wird bei der Ausgabe (Flash).

namespace TTLeague\Registration;

use TTLeague\Access\AccessPolicy;
use TTLeague\Domain\Registration\RegistrationRules as Rules;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Results\Actor;
use TTLeague\Season\SeasonRepository;

/**
 * Fachliche Rechteprüfung der Dienste (docs/meldung.md), zusätzlich zur Capability
 * ttl_manage_club der WordPress-Schicht:
 *
 * - Geplante Saison: Der TK-Chef des Clubs in dieser Saison ändert Spieler, Mannschaften und
 *   Captains und reicht ein, solange das Meldefenster offen ist und die Meldung «offen» steht.
 * - Aktive Saison: Der TK-Chef des Clubs in dieser Saison meldet Spieler bis zum letzten Tag
 *   der Nachmeldung nach und ändert Captains jederzeit. Mannschaften und Stammspieler ändert
 *   nur die TK.
 * - Die TK darf in der geplanten und der aktiven Saison immer. Eine archivierte Saison ändert
 *   niemand.
 *
 * Fenster und Fristen sind Kalendertage in der Zeitzone der Website.
 */
final class RegistrationAccess {

	public const PLAYERS   = 'players';
	public const TEAMS     = 'teams';
	public const STRUCTURE = 'structure';
	public const CAPTAIN   = 'captain';
	public const SUBMIT    = 'submit';

	private RegistrationRepository $repo;

	private SeasonRepository $seasons;

	public function __construct(
		Db $db,
		private readonly AccessPolicy $policy,
		private readonly Clock $clock,
		private readonly \DateTimeZone $timezone,
	) {
		$this->repo    = new RegistrationRepository( $db );
		$this->seasons = new SeasonRepository( $db );
	}

	/**
	 * Heutiger Tag in der Zeitzone der Website.
	 */
	public function today(): string {
		return $this->clock->now()->setTimezone( $this->timezone )->format( 'Y-m-d' );
	}

	/**
	 * Systemzeitpunkt in UTC.
	 */
	public function now(): string {
		return $this->clock->now()->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Ist die Person TK-Chef des Clubs in genau dieser Saison?
	 */
	public function is_chef( Actor $actor, int $season_id, int $club_id ): bool {
		return null !== $actor->person_id && in_array( $club_id, $this->policy->roles( $actor->person_id, $season_id )['tk_chef_clubs'], true );
	}

	/**
	 * Verwaltet die Person den Club (TK-Chef der aktiven oder geplanten Saison) oder gehört sie zur TK?
	 */
	public function manages( Actor $actor, int $club_id ): bool {
		return $actor->is_tk || ( null !== $actor->person_id && $this->policy->can_manage_club( $actor->person_id, $club_id ) );
	}

	/**
	 * Nach Änderungen an Funktionen (Captain) in derselben Anfrage: gemerkte Rollen verwerfen.
	 */
	public function forget_roles(): void {
		$this->policy->reset();
	}

	/**
	 * Stand und Rechte für die Meldung eines Clubs in einer Saison.
	 *
	 * @throws RegistrationException Saison oder Club gibt es nicht, der Club nimmt an der geplanten
	 *                               Saison nicht teil, oder die Person hat mit der Meldung nichts zu tun.
	 */
	public function context( Actor $actor, int $season_id, int $club_id ): RegistrationContext {
		$season = $this->seasons->by_id( $season_id );
		$club   = $this->repo->club( $club_id );
		if ( null === $season || null === $club ) {
			throw RegistrationException::not_found( __( 'Diese Meldung gibt es nicht.', 'ttleague' ) );
		}
		$chef = $this->is_chef( $actor, $season_id, $club_id );
		if ( ! $actor->is_tk && ! $chef ) {
			throw RegistrationException::forbidden( __( 'Du kannst die Meldung dieses Clubs nicht bearbeiten.', 'ttleague' ) );
		}
		$club_season = $this->repo->club_season( $season_id, $club_id );
		if ( SeasonRepository::PLANNED === $season['status'] && null === $club_season ) {
			throw RegistrationException::not_found( __( 'Für diesen Club ist in der geplanten Saison keine Meldung vorgesehen.', 'ttleague' ) );
		}

		$today  = $this->today();
		$can    = array_fill_keys( array( self::PLAYERS, self::TEAMS, self::STRUCTURE, self::CAPTAIN, self::SUBMIT ), false );
		$reason = '';
		$window = false;
		$late   = Rules::late_registration_deadline( $season['late_registration_until'], $season['end_date'] );
		$status = null === $club_season ? null : $club_season['status'];

		if ( SeasonRepository::PLANNED === $season['status'] ) {
			$window = Rules::window_open( $today, $season['registration_opens'], $season['registration_closes'] );
			if ( $actor->is_tk ) {
				$can                 = array_fill_keys( array_keys( $can ), true );
				$can[ self::SUBMIT ] = Rules::OPEN === $status;
			} elseif ( ! $window ) {
				$reason = null !== $season['registration_opens'] && $today < $season['registration_opens']
					? __( 'Das Meldefenster ist noch nicht offen.', 'ttleague' )
					: __( 'Das Meldefenster ist geschlossen. Änderungen nimmt die TK vor.', 'ttleague' );
			} elseif ( Rules::OPEN !== $status ) {
				$reason = Rules::SUBMITTED === $status
					? __( 'Die Meldung ist eingereicht. Ändern kannst du sie erst wieder, wenn die TK sie zurückgibt.', 'ttleague' )
					: __( 'Die Meldung ist geprüft. Änderungen nimmt die TK vor.', 'ttleague' );
			} else {
				$can = array_fill_keys( array_keys( $can ), true );
			}
		} elseif ( SeasonRepository::ACTIVE === $season['status'] ) {
			$open                   = Rules::late_registration_open( $today, $late );
			$can[ self::PLAYERS ]   = $actor->is_tk || $open;
			$can[ self::TEAMS ]     = $actor->is_tk;
			$can[ self::CAPTAIN ]   = true;
			$can[ self::STRUCTURE ] = false;
			if ( ! $can[ self::PLAYERS ] ) {
				$reason = __( 'Die Frist für Nachmeldungen ist abgelaufen. Weitere Spieler meldet die TK.', 'ttleague' );
			}
		} else {
			$reason = __( 'Die Saison ist abgeschlossen.', 'ttleague' );
		}

		return new RegistrationContext( $season, $club, $club_season, $today, $actor->is_tk, $chef, $can, $reason, $window, $late );
	}
}
