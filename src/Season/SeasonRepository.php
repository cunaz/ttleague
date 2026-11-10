<?php
/**
 * Saisons für die Schreibseite: aktive, geplante, einzelne Saison.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Season;

use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Infrastructure\Database\Db;

/**
 * Liest Saisons ohne Cache und mit allen Feldern, auch geplante. Die öffentliche Darstellung
 * verwendet ReadModel\Seasons, das geplante Saisons nicht kennt.
 *
 * Eine Saison ist ein Array
 * array{id:int,name:string,slug:string,start_date:string,end_date:string,status:string,
 *   rules:array<string,mixed>,registration_opens:?string,registration_closes:?string,
 *   late_registration_until:?string,schedule:array<string,mixed>,classification_closed_at:?string}.
 */
final class SeasonRepository {

	public const PLANNED  = 'planned';
	public const ACTIVE   = 'active';
	public const ARCHIVED = 'archived';

	private const COLUMNS = 'id, name, slug, start_date, end_date, status, rules_json, registration_opens, registration_closes, late_registration_until, schedule_json, classification_closed_at';

	public function __construct( private readonly Db $db ) {}

	/**
	 * Die aktive Saison (laufender Spielbetrieb).
	 *
	 * @return array<string,mixed>|null
	 */
	public function active(): ?array {
		return $this->by_status( self::ACTIVE );
	}

	/**
	 * Die geplante Saison, die die TK gerade aufsetzt.
	 *
	 * @return array<string,mixed>|null
	 */
	public function planned(): ?array {
		return $this->by_status( self::PLANNED );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function by_id( int $id ): ?array {
		$row = $this->db->get_row( 'SELECT ' . self::COLUMNS . " FROM {$this->db->table( 'season' )} WHERE id = %d", array( $id ) );
		return null === $row ? null : self::season( $row );
	}

	/**
	 * Regeln einer Saison. Anders als die öffentliche Darstellung fällt die Schreibseite bei
	 * ungültigen Regeln nicht auf Standardwerte zurück.
	 *
	 * @param array{rules:array<string,mixed>} $season Saison.
	 * @throws \InvalidArgumentException Wenn rules_json ungültige Werte enthält.
	 */
	public function rules( array $season ): SeasonRules {
		return SeasonRules::from_array( $season['rules'] );
	}

	/**
	 * Die neueste Saison mit dem Status. Es soll höchstens eine aktive und eine geplante geben;
	 * gibt es (aus Altdaten) mehrere, gilt die mit dem spätesten Beginn.
	 *
	 * @return array<string,mixed>|null
	 */
	private function by_status( string $status ): ?array {
		$row = $this->db->get_row( 'SELECT ' . self::COLUMNS . " FROM {$this->db->table( 'season' )} WHERE status = %s ORDER BY start_date DESC, id DESC LIMIT 1", array( $status ) );
		return null === $row ? null : self::season( $row );
	}

	/**
	 * @param array<string,mixed> $row Zeile aus season.
	 * @return array<string,mixed>
	 */
	private static function season( array $row ): array {
		$rules    = json_decode( (string) $row['rules_json'], true );
		$schedule = json_decode( (string) $row['schedule_json'], true );
		$text     = static fn( mixed $value ): ?string => null === $value ? null : (string) $value;
		return array(
			'id'                       => (int) $row['id'],
			'name'                     => (string) $row['name'],
			'slug'                     => (string) $row['slug'],
			'start_date'               => (string) $row['start_date'],
			'end_date'                 => (string) $row['end_date'],
			'status'                   => (string) $row['status'],
			'rules'                    => is_array( $rules ) ? $rules : array(),
			'registration_opens'       => $text( $row['registration_opens'] ),
			'registration_closes'      => $text( $row['registration_closes'] ),
			'late_registration_until'  => $text( $row['late_registration_until'] ),
			'schedule'                 => is_array( $schedule ) ? $schedule : array(),
			'classification_closed_at' => $text( $row['classification_closed_at'] ),
		);
	}
}
