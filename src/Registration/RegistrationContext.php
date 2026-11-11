<?php
/**
 * Stand der Meldung eines Clubs in einer Saison und was die handelnde Person daran darf.
 *
 * @package TTLeague
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldungen der Ausnahmen sind Klartext; escaped wird bei der Ausgabe (Flash).

namespace TTLeague\Registration;

use TTLeague\Season\SeasonRepository;

final class RegistrationContext {

	/**
	 * @param array<string,mixed>                             $season      Saison aus SeasonRepository.
	 * @param array{id:int,name:string,short_name:string}     $club        Club.
	 * @param array<string,mixed>|null                        $club_season Meldestand (club_season); in der aktiven Saison ohne Bedeutung.
	 * @param string                                          $today       Heutiger Tag (Ortszeit).
	 * @param bool                                            $is_tk       Handelt die TK?
	 * @param bool                                            $is_chef     TK-Chef des Clubs in dieser Saison.
	 * @param array<string,bool>                              $can         Erlaubte Bereiche (RegistrationAccess::PLAYERS …).
	 * @param string                                          $reason      Warum der Club gerade nichts oder nicht alles ändern kann.
	 * @param bool                                            $window_open Meldefenster der geplanten Saison offen.
	 * @param string                                          $late_until  Letzter Tag der Nachmeldung (aktive Saison).
	 */
	public function __construct(
		public readonly array $season,
		public readonly array $club,
		public readonly ?array $club_season,
		public readonly string $today,
		public readonly bool $is_tk,
		public readonly bool $is_chef,
		public readonly array $can,
		public readonly string $reason,
		public readonly bool $window_open,
		public readonly string $late_until,
	) {}

	public function season_id(): int {
		return (int) $this->season['id'];
	}

	public function club_id(): int {
		return $this->club['id'];
	}

	public function is_planned(): bool {
		return SeasonRepository::PLANNED === $this->season['status'];
	}

	public function is_active(): bool {
		return SeasonRepository::ACTIVE === $this->season['status'];
	}

	/**
	 * Meldestand der geplanten Saison (open, submitted, reviewed) oder null.
	 */
	public function status(): ?string {
		return null === $this->club_season ? null : (string) $this->club_season['status'];
	}

	public function can( string $what ): bool {
		return $this->can[ $what ] ?? false;
	}

	/**
	 * @throws RegistrationException Wenn der Bereich für die Person gesperrt ist.
	 */
	public function require( string $what ): void {
		if ( ! $this->can( $what ) ) {
			throw RegistrationException::forbidden( '' !== $this->reason ? $this->reason : __( 'Das kannst du an dieser Meldung nicht ändern.', 'ttleague' ) );
		}
	}
}
