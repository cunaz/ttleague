<?php
/**
 * Stapelweises Einfügen über einzelne INSERT-Anweisungen mit mehreren Wertzeilen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure\Database;

trait InsertMany {

	/**
	 * @param list<array<string,int|float|string|null>> $rows Zeilen mit identischen Schlüsseln.
	 */
	public function insert_many( string $table, array $rows, int $chunk = 500 ): int {
		if ( array() === $rows ) {
			return 0;
		}
		$columns = array_keys( $rows[0] );
		$total   = 0;
		foreach ( array_chunk( $rows, max( 1, $chunk ) ) as $batch ) {
			$values = array();
			$args   = array();
			foreach ( $batch as $row ) {
				if ( array_keys( $row ) !== $columns ) {
					throw new DbException( 'insert_many erwartet in allen Zeilen dieselben Spalten.' );
				}
				$placeholders = array();
				foreach ( $row as $value ) {
					if ( null === $value ) {
						$placeholders[] = 'NULL';
					} else {
						$placeholders[] = is_int( $value ) || is_bool( $value ) ? '%d' : ( is_float( $value ) ? '%f' : '%s' );
						$args[]         = is_bool( $value ) ? (int) $value : $value;
					}
				}
				$values[] = '(' . implode( ', ', $placeholders ) . ')';
			}
			$sql    = sprintf(
				'INSERT INTO `%s` (%s) VALUES %s',
				$this->table( $table ),
				implode( ', ', array_map( static fn( $c ) => '`' . $c . '`', $columns ) ),
				implode( ', ', $values )
			);
			$total += $this->query( $sql, $args );
		}
		return $total;
	}
}
