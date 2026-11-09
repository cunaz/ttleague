<?php
/**
 * Db-Implementierung auf Basis von $wpdb.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure\Database;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- SQL wird in prepare() mit Platzhaltern aufbereitet.

final class WpdbDb implements Db {

	use InsertMany;

	private int $depth = 0;

	public function __construct( private readonly \wpdb $wpdb ) {}

	public static function from_global(): self {
		global $wpdb;
		return new self( $wpdb );
	}

	public function table( string $name ): string {
		return Schema::table( $name, $this->wpdb->prefix );
	}

	public function prefix(): string {
		return $this->wpdb->prefix;
	}

	public function get_results( string $sql, array $args = array() ): array {
		$rows = $this->wpdb->get_results( $this->prepare( $sql, $args ), ARRAY_A );
		$this->throw_on_error();
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	public function get_row( string $sql, array $args = array() ): ?array {
		$row = $this->wpdb->get_row( $this->prepare( $sql, $args ), ARRAY_A );
		$this->throw_on_error();
		return is_array( $row ) ? $row : null;
	}

	public function get_var( string $sql, array $args = array() ): ?string {
		$value = $this->wpdb->get_var( $this->prepare( $sql, $args ) );
		$this->throw_on_error();
		return null === $value ? null : (string) $value;
	}

	public function get_col( string $sql, array $args = array() ): array {
		$col = $this->wpdb->get_col( $this->prepare( $sql, $args ) );
		$this->throw_on_error();
		return array_values( array_map( static fn( $v ) => null === $v ? null : (string) $v, (array) $col ) );
	}

	public function query( string $sql, array $args = array() ): int {
		$result = $this->wpdb->query( $this->prepare( $sql, $args ) );
		$this->throw_on_error();
		return is_int( $result ) ? $result : 0;
	}

	public function insert( string $table, array $data ): int {
		$ok = $this->wpdb->insert( $this->table( $table ), $data, $this->formats( $data ) );
		$this->throw_on_error();
		if ( false === $ok ) {
			throw new DbException( 'Einfügen in ' . $table . ' fehlgeschlagen.' );
		}
		return (int) $this->wpdb->insert_id;
	}

	public function update( string $table, array $data, array $where ): int {
		$result = $this->wpdb->update( $this->table( $table ), $data, $where, $this->formats( $data ), $this->formats( $where ) );
		$this->throw_on_error();
		return is_int( $result ) ? $result : 0;
	}

	public function delete( string $table, array $where ): int {
		$result = $this->wpdb->delete( $this->table( $table ), $where, $this->formats( $where ) );
		$this->throw_on_error();
		return is_int( $result ) ? $result : 0;
	}

	public function transaction( callable $work ): mixed {
		$outer = 0 === $this->depth;
		if ( $outer && false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			$this->throw_on_error();
			throw new DbException( 'Datenbankfehler: Transaktion konnte nicht gestartet werden.' );
		}
		++$this->depth;
		try {
			$result = $work( $this );
		} catch ( \Throwable $e ) {
			--$this->depth;
			if ( $outer ) {
				$this->wpdb->query( 'ROLLBACK' );
			}
			throw $e;
		}
		--$this->depth;
		if ( $outer && false === $this->wpdb->query( 'COMMIT' ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			$this->throw_on_error();
			throw new DbException( 'Datenbankfehler: Transaktion konnte nicht abgeschlossen werden.' );
		}
		return $result;
	}


	public function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * @param list<int|float|string|null> $args Werte.
	 */
	private function prepare( string $sql, array $args ): string {
		if ( array() === $args ) {
			return str_replace( '%%', '%', $sql );
		}
		// NULL-Werte direkt einsetzen, da $wpdb->prepare() sie als leeren String behandeln würde.
		$parts = Placeholders::split( $sql, count( $args ) );
		$out   = '';
		$rest  = array();
		foreach ( $parts['tokens'] as $i => $token ) {
			$out  .= $parts['literals'][ $i ];
			$value = $args[ $i ];
			if ( null === $value ) {
				$out .= 'NULL';
			} else {
				$out   .= $token;
				$rest[] = $value;
			}
		}
		$out .= $parts['literals'][ count( $parts['tokens'] ) ];
		if ( array() === $rest ) {
			return str_replace( '%%', '%', $out );
		}
		return $this->wpdb->prepare( $out, ...$rest ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
	}

	/**
	 * @param array<string,int|float|string|null> $data Werte.
	 * @return list<string|null>
	 */
	private function formats( array $data ): array {
		$formats = array();
		foreach ( $data as $value ) {
			if ( is_int( $value ) || is_bool( $value ) ) {
				$formats[] = '%d';
			} elseif ( is_float( $value ) ) {
				$formats[] = '%f';
			} else {
				$formats[] = '%s';
			}
		}
		return $formats;
	}

	private function throw_on_error(): void {
		if ( '' !== $this->wpdb->last_error ) {
			$message                = $this->wpdb->last_error;
			$this->wpdb->last_error = '';
			throw new DbException( 'Datenbankfehler: ' . $message );
		}
	}
}
