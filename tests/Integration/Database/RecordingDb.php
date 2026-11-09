<?php
/**
 * Db, die jede Abfrage mitschreibt, ob innerhalb einer Transaktion oder nicht.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Database;

use TTLeague\Infrastructure\Database\Db;

/**
 * Reicht alles an eine echte Verbindung weiter und hält die Reihenfolge der Abfragen fest. So
 * lässt sich prüfen, welche Abfragen in einer Transaktion vor oder nach einer Sperre laufen.
 * Einfügen, Ändern und Löschen erscheinen als «INSERT tabelle» usw.
 */
final class RecordingDb implements Db {

	/** @var list<array{transaction:bool,sql:string}> */
	public array $log = array();

	private int $depth = 0;

	public function __construct( private readonly Db $db ) {}

	/**
	 * Abfragen innerhalb (true) oder ausserhalb (false) einer Transaktion.
	 *
	 * @return list<string>
	 */
	public function statements( bool $in_transaction ): array {
		return array_values(
			array_map(
				static fn( array $entry ): string => $entry['sql'],
				array_filter( $this->log, static fn( array $entry ): bool => $entry['transaction'] === $in_transaction )
			)
		);
	}

	public function table( string $name ): string {
		return $this->db->table( $name );
	}

	public function prefix(): string {
		return $this->db->prefix();
	}

	public function get_results( string $sql, array $args = array() ): array {
		$this->record( $sql );
		return $this->db->get_results( $sql, $args );
	}

	public function get_row( string $sql, array $args = array() ): ?array {
		$this->record( $sql );
		return $this->db->get_row( $sql, $args );
	}

	public function get_var( string $sql, array $args = array() ): ?string {
		$this->record( $sql );
		return $this->db->get_var( $sql, $args );
	}

	public function get_col( string $sql, array $args = array() ): array {
		$this->record( $sql );
		return $this->db->get_col( $sql, $args );
	}

	public function query( string $sql, array $args = array() ): int {
		$this->record( $sql );
		return $this->db->query( $sql, $args );
	}

	public function insert( string $table, array $data ): int {
		$this->record( 'INSERT ' . $this->table( $table ) );
		return $this->db->insert( $table, $data );
	}

	public function insert_many( string $table, array $rows, int $chunk = 500 ): int {
		$this->record( 'INSERT ' . $this->table( $table ) );
		return $this->db->insert_many( $table, $rows, $chunk );
	}

	public function update( string $table, array $data, array $where ): int {
		$this->record( 'UPDATE ' . $this->table( $table ) );
		return $this->db->update( $table, $data, $where );
	}

	public function delete( string $table, array $where ): int {
		$this->record( 'DELETE ' . $this->table( $table ) );
		return $this->db->delete( $table, $where );
	}

	public function transaction( callable $work ): mixed {
		return $this->db->transaction(
			function () use ( $work ): mixed {
				++$this->depth;
				try {
					return $work( $this );
				} finally {
					--$this->depth;
				}
			}
		);
	}

	public function now(): string {
		return $this->db->now();
	}

	private function record( string $sql ): void {
		$this->log[] = array(
			'transaction' => $this->depth > 0,
			'sql'         => (string) preg_replace( '/\s+/', ' ', trim( $sql ) ),
		);
	}
}
