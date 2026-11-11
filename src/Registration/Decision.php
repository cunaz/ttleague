<?php
/**
 * Ein Entscheid der TK zu einer Meldung, für die Benachrichtigung des TK-Chefs.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Registration;

final class Decision {

	public const REGISTRATION_APPROVED = 'registration_approved';
	public const REGISTRATION_RETURNED = 'registration_returned';
	public const REQUEST_APPROVED      = 'request_approved';
	public const REQUEST_REJECTED      = 'request_rejected';
	public const PLAYER_APPROVED       = 'player_approved';
	public const PLAYER_REJECTED       = 'player_rejected';

	/**
	 * @param string      $kind        Art des Entscheids (Konstanten dieser Klasse).
	 * @param int         $season_id   Saison der Meldung.
	 * @param string      $season_name Name der Saison, z. B. 2027/28.
	 * @param int         $club_id     Club.
	 * @param string      $club_name   Name des Clubs.
	 * @param int         $audit_id    Eintrag im audit_log; Bezug der Benachrichtigung.
	 * @param int         $user_id     Konto, das entschieden hat.
	 * @param int|null    $person_id   Person, die entschieden hat.
	 * @param string      $subject     Name der Spielerin oder des Spielers, leer bei der ganzen Meldung.
	 * @param string|null $note        Bemerkung der TK.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly int $season_id,
		public readonly string $season_name,
		public readonly int $club_id,
		public readonly string $club_name,
		public readonly int $audit_id,
		public readonly int $user_id,
		public readonly ?int $person_id,
		public readonly string $subject = '',
		public readonly ?string $note = null,
	) {}
}
