<?php
/**
 * Spielberechtigung einer Seite einer Begegnung (Meisterschaftsteam oder Cup-Mannschaft).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

use TTLeague\Domain\Cup\LineupRule;
use TTLeague\Domain\Eligibility\Eligibility;
use TTLeague\Domain\Eligibility\EligibilityChecker;
use TTLeague\Domain\Eligibility\EligibilityReason;

final class SideRoster {

	/**
	 * @param array<int,array{player_id:int,first_name:string,last_name:string,license_no:int,classification:int|null,stamm_team_id:int|null}> $players       Gemeldete Spieler des Clubs.
	 * @param EligibilityChecker|null                                                                                                           $checker       Meisterschaft: Prüfung für das Team.
	 * @param int|null                                                                                                                          $team_id       Meisterschaft: Team.
	 * @param list<int>                                                                                                                         $entry_players Cup: gemeldete Spieler der Mannschaft.
	 * @param string                                                                                                                            $name          Name der Mannschaft.
	 * @param int|null                                                                                                                          $points_total  Cup: Punktesumme der Meldung.
	 * @param array<int,string>                                                                                                                 $other_teams   Cup: Spieler => andere Cupmannschaft der Saison, in der er gemeldet ist oder für die er gespielt hat.
	 */
	public function __construct(
		public readonly array $players,
		private readonly ?EligibilityChecker $checker,
		public readonly ?int $team_id,
		public readonly array $entry_players = array(),
		public readonly string $name = '',
		public readonly ?int $points_total = null,
		public readonly array $other_teams = array(),
	) {}

	public function check( int $player_id ): Eligibility {
		if ( null !== $this->checker && null !== $this->team_id ) {
			return $this->checker->check( $player_id, $this->team_id );
		}
		if ( ! isset( $this->players[ $player_id ] ) ) {
			return new Eligibility( false, EligibilityReason::BlockedOtherClub, 0, null, __( 'Spieler ist nicht für diesen Club gemeldet.', 'ttleague' ) );
		}
		if ( in_array( $player_id, $this->entry_players, true ) ) {
			return new Eligibility( true, EligibilityReason::Stamm, 0, null, __( 'Für die Cup-Mannschaft gemeldet.', 'ttleague' ) );
		}
		$other = LineupRule::blocked_by( $player_id, $this->entry_players, $this->other_teams );
		if ( null !== $other ) {
			/* translators: %s: Cupmannschaft */
			return new Eligibility( false, EligibilityReason::BlockedBoundElsewhere, 0, null, sprintf( __( 'In der Cupmannschaft %s gemeldet oder eingesetzt; ein Spieler spielt in der Saison nur für eine Cupmannschaft (Art. 20.2).', 'ttleague' ), $other ) );
		}
		return new Eligibility( true, EligibilityReason::Substitute, 0, null, __( 'Nicht für diese Cup-Mannschaft gemeldet (Ersatz aus dem Club).', 'ttleague' ) );
	}

	public function is_cup(): bool {
		return null === $this->checker;
	}

	public function player_name( int $player_id ): string {
		$player = $this->players[ $player_id ] ?? null;
		if ( null === $player ) {
			/* translators: %d: Spieler-ID */
			return sprintf( __( 'Spieler %d', 'ttleague' ), $player_id );
		}
		return trim( $player['first_name'] . ' ' . $player['last_name'] );
	}
}
