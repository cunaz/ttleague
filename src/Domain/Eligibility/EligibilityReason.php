<?php
/**
 * Begründung einer Spielberechtigungsprüfung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Eligibility;

enum EligibilityReason: string {
	case Stamm                      = 'stamm';
	case Substitute                 = 'substitute';
	case Bound                      = 'bound';
	case BlockedOtherClub           = 'blocked_other_club';
	case BlockedLowerTeam           = 'blocked_lower_team';
	case BlockedTeamNumber          = 'blocked_team_number';
	case BlockedOtherTeamSubstitute = 'blocked_other_team_substitute';
	case BlockedBoundElsewhere      = 'blocked_bound_elsewhere';

	public function is_allowed(): bool {
		return in_array( $this, array( self::Stamm, self::Substitute, self::Bound ), true );
	}
}
