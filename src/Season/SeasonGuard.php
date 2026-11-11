<?php
/**
 * Gemeinsame Prüfungen und Sperren der Dienste des Saison-Assistenten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldungen der Ausnahmen sind Klartext; escaped wird bei der Ausgabe (Flash).

namespace TTLeague\Season;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\Results\Actor;

/**
 * Alle Schreibvorgänge des Assistenten verlangen die TK und sperren in ihrer Transaktion zuerst
 * die Saisons (SELECT … FOR UPDATE auf alle Zeilen). So laufen Anlegen, Freigabe und Abschluss
 * hintereinander, und «höchstens eine aktive und eine geplante Saison» lässt sich unter der
 * Sperre prüfen. Die Erfassung sperrt ebenfalls zuerst die Saison; die Reihenfolge der Sperren
 * ist damit überall gleich (Saison, danach Clubs oder Begegnungen).
 */
final class SeasonGuard {

	private SeasonRepository $seasons;

	public function __construct( private readonly Db $db ) {
		$this->seasons = new SeasonRepository( $db );
	}

	/**
	 * @throws SeasonException Ohne TK-Recht.
	 */
	public static function require_tk( Actor $actor ): void {
		if ( ! $actor->is_tk ) {
			throw SeasonException::forbidden( __( 'Das darf nur die TK.', 'ttleague' ) );
		}
	}

	/**
	 * Sperrt alle Saisons bis zum Ende der Transaktion. Zuerst aufrufen, vor jeder Abfrage.
	 */
	public function lock(): void {
		$this->db->get_col( "SELECT id FROM {$this->db->table( 'season' )} ORDER BY id FOR UPDATE" );
	}

	/**
	 * @return array<string,mixed>
	 * @throws SeasonException Wenn es die Saison nicht gibt.
	 */
	public function season( int $season_id ): array {
		$season = $this->seasons->by_id( $season_id );
		if ( null === $season ) {
			throw SeasonException::not_found( __( 'Diese Saison gibt es nicht.', 'ttleague' ) );
		}
		return $season;
	}

	/**
	 * Die Saison, wenn sie geplant ist.
	 *
	 * @return array<string,mixed>
	 * @throws SeasonException Wenn es die Saison nicht gibt oder sie nicht (mehr) geplant ist.
	 */
	public function planned( int $season_id ): array {
		$season = $this->season( $season_id );
		if ( SeasonRepository::PLANNED !== $season['status'] ) {
			throw SeasonException::conflict( __( 'Das geht nur in der geplanten Saison. Diese Saison ist schon freigegeben oder abgeschlossen.', 'ttleague' ) );
		}
		return $season;
	}

	/**
	 * Die Saison, wenn sie aktiv ist.
	 *
	 * @return array<string,mixed>
	 * @throws SeasonException Wenn es die Saison nicht gibt oder sie nicht aktiv ist.
	 */
	public function active( int $season_id ): array {
		$season = $this->season( $season_id );
		if ( SeasonRepository::ACTIVE !== $season['status'] ) {
			throw SeasonException::conflict( __( 'Das geht nur in der aktiven Saison.', 'ttleague' ) );
		}
		return $season;
	}

	/**
	 * Feldfehler der Domäne als Ablehnung.
	 *
	 * @param list<array{field:string,message:string}> $errors Fehler.
	 * @throws SeasonException Wenn es Fehler gibt.
	 */
	public static function reject( array $errors ): void {
		if ( array() !== $errors ) {
			throw SeasonException::invalid( $errors );
		}
	}

	/**
	 * Zeitstempel «geändert» der Saison setzen.
	 */
	public function touch( int $season_id ): void {
		$this->db->update( 'season', array( 'updated_at' => $this->db->now() ), array( 'id' => $season_id ) );
	}
}
