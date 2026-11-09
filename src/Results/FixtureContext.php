<?php
/**
 * Eckdaten einer Begegnung für die Erfassung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

use TTLeague\Domain\Match\GameOrder;
use TTLeague\Domain\Rules\SeasonRules;

final class FixtureContext {

	/**
	 * @param array{team_id:int|null,entry_id:int|null,club_id:int|null,name:string} $home Heimseite.
	 * @param array{team_id:int|null,entry_id:int|null,club_id:int|null,name:string} $away Gastseite.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly string $category,
		public readonly string $competition,
		public readonly int $season_id,
		public readonly string $season_slug,
		public readonly string $season_status,
		public readonly SeasonRules $rules,
		public readonly string $status,
		public readonly ?string $scheduled_at,
		public readonly array $home,
		public readonly array $away,
		public readonly ?int $home_score,
		public readonly ?int $away_score,
		public readonly ?int $entered_by,
		public readonly ?string $entered_at,
		public readonly ?int $confirmed_by,
		public readonly ?string $confirmed_at,
		public readonly ?int $photo_id,
		public readonly int $result_stamp = 0,
	) {}

	public function is_cup(): bool {
		return 'CUP' === $this->type;
	}

	/**
	 * Slots einer Seite in der Aufstellung.
	 *
	 * @return list<string>
	 */
	public function slots( string $side ): array {
		if ( $this->is_cup() ) {
			return 'home' === $side ? array( 'A', 'B' ) : array( 'Y', 'Z' );
		}
		return 'home' === $side ? array( 'A', 'B', 'C' ) : array( 'X', 'Y', 'Z' );
	}

	/**
	 * Spielfolge.
	 *
	 * @return list<array{sequence:int,type:string,home:string|null,away:string|null}>
	 */
	public function order(): array {
		return $this->is_cup() ? GameOrder::cup() : GameOrder::team_match();
	}

	/**
	 * @return array{team_id:int|null,entry_id:int|null,club_id:int|null,name:string}
	 */
	public function side( string $side ): array {
		return 'home' === $side ? $this->home : $this->away;
	}

	public function has_result(): bool {
		return null !== $this->home_score && null !== $this->away_score;
	}

	/**
	 * Kennung des aktuellen Stands; ändert sich mit jeder Erfassung und jedem Statuswechsel
	 * (result_stamp ist die höchste Spiel-ID, Spiele werden bei jeder Erfassung neu geschrieben).
	 */
	public function version(): string {
		return self::version_of( $this->status, $this->home_score, $this->away_score, $this->entered_at, $this->confirmed_at, $this->entered_by, $this->confirmed_by, $this->result_stamp );
	}

	/**
	 * Kennung aus den Rohwerten, für Listen, die keinen vollständigen Kontext laden (z. B. das
	 * TK-Dashboard). Werte wie in version(): fehlende Konten null, kein Spiel 0.
	 */
	public static function version_of( string $status, ?int $home_score, ?int $away_score, ?string $entered_at, ?string $confirmed_at, ?int $entered_by, ?int $confirmed_by, int $result_stamp ): string {
		return substr(
			hash(
				'sha256',
				implode(
					'|',
					array( $status, (string) $home_score, (string) $away_score, (string) $entered_at, (string) $confirmed_at, (string) $entered_by, (string) $confirmed_by, (string) $result_stamp )
				)
			),
			0,
			16
		);
	}
}
