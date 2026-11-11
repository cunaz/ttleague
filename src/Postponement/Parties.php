<?php
/**
 * Wer vertritt bei einem Antrag welche Seite der Begegnung?
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Postponement;

use TTLeague\Access\AccessPolicy;
use TTLeague\Domain\Match\PostponementRules as Rules;
use TTLeague\Results\Actor;
use TTLeague\Results\FixtureContext;

/**
 * Leitet aus der Rechteprüfung (AccessPolicy::side_for) ab, welche Seiten eine Person vertritt,
 * welche Seite einen Antrag gestellt hat und welche Rollen die handelnde Person für ihn hat.
 * Die Seite des Antrags folgt aus den heutigen Funktionen der antragstellenden Person: Verliert
 * sie ihre Funktion, hat der Antrag keine Seiten mehr, und nur die TK kann noch entscheiden.
 */
final class Parties {

	public function __construct( private readonly AccessPolicy $policy ) {}

	/**
	 * Seiten, die die Person vertritt; im Clubduell kann es der TK-Chef für beide sein. Nur in
	 * der aktiven Saison.
	 *
	 * @return list<string>
	 */
	public function sides( FixtureContext $ctx, ?int $person_id ): array {
		if ( null === $person_id ) {
			return array();
		}
		$side = $this->policy->side_for( $person_id, $ctx->id );
		if ( null === $side ) {
			return array();
		}
		if ( AccessPolicy::HOME === $side && $this->policy->can_confirm_result( $person_id, $ctx->id ) ) {
			return array( Rules::HOME, Rules::AWAY );
		}
		return array( $side );
	}

	/**
	 * Person, die den Antrag gestellt hat (aus dem Konto), oder null.
	 *
	 * @param array<string,mixed> $row Antrag.
	 */
	public function requester_person( array $row ): ?int {
		return $this->policy->person_for_user( (int) $row['requested_by'] );
	}

	/**
	 * Seiten des Antragstellers und der Gegenseite.
	 *
	 * @param array<string,mixed> $row Antrag.
	 * @return array{requester:list<string>,opponent:list<string>}
	 */
	public function of( array $row, FixtureContext $ctx ): array {
		$requester = $this->sides( $ctx, $this->requester_person( $row ) );
		return array(
			'requester' => $requester,
			'opponent'  => array_values( array_unique( array_map( array( Rules::class, 'opposite' ), $requester ) ) ),
		);
	}

	/**
	 * Rollen der handelnden Person für den Antrag (Antragsteller, Gegenseite, TK).
	 *
	 * @param array<string,mixed> $row Antrag.
	 * @return list<string>
	 */
	public function roles( array $row, FixtureContext $ctx, Actor $actor ): array {
		$requester    = $this->requester_person( $row );
		$is_requester = $actor->user_id === (int) $row['requested_by'] || ( null !== $requester && $requester === $actor->person_id );
		return Rules::roles( $this->sides( $ctx, $requester ), $this->sides( $ctx, $actor->person_id ), $is_requester, $actor->is_tk );
	}
}
