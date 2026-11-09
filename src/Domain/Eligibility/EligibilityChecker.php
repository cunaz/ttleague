<?php
/**
 * Spielberechtigung nach Art. 12 des Wettspielreglements.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Eligibility;

use TTLeague\Domain\Rules\SeasonRules;

/**
 * Regeln:
 * - Stammspieler spielen in ihrem Team. Für tiefer klassierte Teams des Vereins sind sie
 *   nicht spielberechtigt (Art. 12.2); in derselben Kategorie nur in einem Team mit tieferer
 *   Mannschaftsnummer (Art. 12.7), ausser die Kategorie erlaubt es frei (Kat. D, Art. 12.8).
 * - Ein Spieler kann nur in einem Team Ersatz sein (Art. 12.4).
 * - Nach substitute_max Einsätzen als Ersatz ist er an dieses Team gebunden (Art. 12.5).
 */
final class EligibilityChecker {

	/** @var array<int|string,TeamInfo> */
	private array $teams = array();

	/** @var array<int|string,int|string> player_id => team_id (Stammteam) */
	private array $stamm_team = array();

	/** @var array<int|string,int|string> player_id => club_id */
	private array $player_club = array();

	/** @var array<int|string,array<int|string,int>> player_id => team_id => Ersatzeinsätze */
	private array $substitute_counts = array();

	public function __construct( private readonly SeasonRules $rules ) {}

	public function add_team( TeamInfo $team ): self {
		$this->teams[ $team->id ] = $team;
		return $this;
	}

	/**
	 * Meldet einen Spieler für einen Club, optional als Stammspieler eines Teams.
	 */
	public function add_player( int|string $player_id, int|string $club_id, int|string|null $stamm_team_id = null ): self {
		$this->player_club[ $player_id ] = $club_id;
		if ( null !== $stamm_team_id ) {
			if ( ! isset( $this->teams[ $stamm_team_id ] ) ) {
				throw new \InvalidArgumentException( 'Unbekanntes Team: ' . $stamm_team_id );
			}
			$this->stamm_team[ $player_id ] = $stamm_team_id;
		}
		return $this;
	}

	/**
	 * Registriert einen bisherigen Einsatz. Einsätze im eigenen Stammteam zählen nicht als Ersatz.
	 */
	public function add_appearance( int|string $player_id, int|string $team_id ): self {
		if ( ( $this->stamm_team[ $player_id ] ?? null ) === $team_id ) {
			return $this;
		}
		$this->substitute_counts[ $player_id ][ $team_id ] = ( $this->substitute_counts[ $player_id ][ $team_id ] ?? 0 ) + 1;
		return $this;
	}

	/**
	 * Team, an das der Spieler durch Ersatzeinsätze gebunden ist, oder null.
	 */
	public function bound_team( int|string $player_id ): int|string|null {
		foreach ( $this->substitute_counts[ $player_id ] ?? array() as $team_id => $count ) {
			if ( $count >= $this->rules->substitute_max ) {
				return $team_id;
			}
		}
		return null;
	}

	public function check( int|string $player_id, int|string $team_id ): Eligibility {
		if ( ! isset( $this->teams[ $team_id ] ) ) {
			throw new \InvalidArgumentException( 'Unbekanntes Team: ' . $team_id );
		}
		$team = $this->teams[ $team_id ];

		if ( ! isset( $this->player_club[ $player_id ] ) || $this->player_club[ $player_id ] !== $team->club_id ) {
			return new Eligibility( false, EligibilityReason::BlockedOtherClub, 0, null, 'Spieler ist nicht für diesen Club gemeldet.' );
		}

		$bound = $this->bound_team( $player_id );
		if ( null !== $bound ) {
			if ( $bound === $team_id ) {
				$count = $this->substitute_counts[ $player_id ][ $team_id ];
				return new Eligibility( true, EligibilityReason::Bound, $count, 0, 'An dieses Team gebunden (Art. 12.5).' );
			}
			return new Eligibility( false, EligibilityReason::BlockedBoundElsewhere, 0, null, 'Durch Ersatzeinsätze an ein anderes Team gebunden (Art. 12.5).' );
		}

		$stamm_id = $this->stamm_team[ $player_id ] ?? null;
		if ( $stamm_id === $team_id ) {
			return new Eligibility( true, EligibilityReason::Stamm, 0, null, 'Stammspieler.' );
		}

		foreach ( $this->substitute_counts[ $player_id ] ?? array() as $other_team => $count ) {
			if ( $other_team !== $team_id && $count > 0 ) {
				return new Eligibility( false, EligibilityReason::BlockedOtherTeamSubstitute, 0, null, 'Bereits als Ersatz in einem anderen Team eingesetzt (Art. 12.4).' );
			}
		}

		if ( null !== $stamm_id ) {
			$stamm = $this->teams[ $stamm_id ];
			if ( $team->is_lower_than( $stamm ) ) {
				if ( strtoupper( $team->category ) !== strtoupper( $stamm->category ) ) {
					return new Eligibility( false, EligibilityReason::BlockedLowerTeam, 0, null, 'Stammspieler sind für tiefer klassierte Mannschaften nicht spielberechtigt (Art. 12.2).' );
				}
				if ( ! $this->rules->allows_any_team_in_category( $team->category ) ) {
					return new Eligibility( false, EligibilityReason::BlockedTeamNumber, 0, null, 'In dieser Kategorie nur in einer Mannschaft mit tieferer Nummer einsetzbar (Art. 12.7).' );
				}
			}
		}

		$count     = $this->substitute_counts[ $player_id ][ $team_id ] ?? 0;
		$remaining = max( 0, $this->rules->substitute_max - $count );
		$message   = sprintf(
			'%d. Einsatz als Ersatz von maximal %d, danach gebunden.',
			$count + 1,
			$this->rules->substitute_max
		);
		return new Eligibility( true, EligibilityReason::Substitute, $count, $remaining, $message );
	}
}
