<?php
/**
 * Db, die bei einem bestimmten Schreibvorgang scheitert.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Season;

use TTLeague\Infrastructure\Database\Db;

/**
 * Reicht alles an eine echte Verbindung weiter. Bei jedem Einfügen und Ändern fragt sie zuerst
 * $fails (Art «insert» oder «update», Tabelle, Daten); liefert die Funktion true, wirft sie eine
 * Ausnahme statt zu schreiben. So lässt sich prüfen, dass eine Transaktion nach einem Fehler
 * mittendrin nichts hinterlässt.
 */
final class FailingDb implements Db {

	/** @var callable(string,string,array<string,mixed>):bool */
	private $fails;

	/**
	 * @param callable(string,string,array<string,mixed>):bool $fails Soll dieser Schreibvorgang scheitern?
	 */
	public function __construct( private readonly Db $db, callable $fails ) {
		$this->fails = $fails;
	}

	public function table( string $name ): string {
		return $this->db->table( $name );
	}

	public function prefix(): string {
		return $this->db->prefix();
	}

	public function get_results( string $sql, array $args = array() ): array {
		return $this->db->get_results( $sql, $args );
	}

	public function get_row( string $sql, array $args = array() ): ?array {
		return $this->db->get_row( $sql, $args );
	}

	public function get_var( string $sql, array $args = array() ): ?string {
		return $this->db->get_var( $sql, $args );
	}

	public function get_col( string $sql, array $args = array() ): array {
		return $this->db->get_col( $sql, $args );
	}

	public function query( string $sql, array $args = array() ): int {
		return $this->db->query( $sql, $args );
	}

	public function insert( string $table, array $data ): int {
		$this->maybe_fail( 'insert', $table, $data );
		return $this->db->insert( $table, $data );
	}

	public function insert_many( string $table, array $rows, int $chunk = 500 ): int {
		return $this->db->insert_many( $table, $rows, $chunk );
	}

	public function update( string $table, array $data, array $where ): int {
		$this->maybe_fail( 'update', $table, $data );
		return $this->db->update( $table, $data, $where );
	}

	public function delete( string $table, array $where ): int {
		return $this->db->delete( $table, $where );
	}

	public function transaction( callable $work ): mixed {
		return $this->db->transaction( fn(): mixed => $work( $this ) );
	}

	public function now(): string {
		return $this->db->now();
	}

	/**
	 * @param array<string,mixed> $data Daten des Schreibvorgangs.
	 * @throws \RuntimeException Wenn der Schreibvorgang scheitern soll.
	 */
	private function maybe_fail( string $kind, string $table, array $data ): void {
		if ( ( $this->fails )( $kind, $table, $data ) ) {
			throw new \RuntimeException( 'Eingestreuter Datenbankfehler: ' . $kind . ' ' . $table ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Ausnahme im Test, keine Ausgabe.
		}
	}
}
