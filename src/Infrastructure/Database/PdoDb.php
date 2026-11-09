<?php
/**
 * Db-Implementierung auf Basis von PDO (MySQL/MariaDB), für Integrationstests und Werkzeuge.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure\Database;

final class PdoDb implements Db {

	private int $depth = 0;

	public function __construct( private readonly \PDO $pdo, private readonly string $prefix = 'wp_' ) {
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$this->pdo->setAttribute( \PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC );
		$this->pdo->setAttribute( \PDO::ATTR_EMULATE_PREPARES, true );
		$this->pdo->setAttribute( \PDO::ATTR_STRINGIFY_FETCHES, true );
	}

	public static function connect( string $dsn, string $user, string $password, string $prefix = 'wp_' ): self {
		$pdo = new \PDO( $dsn, $user, $password, array( \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+00:00'" ) );
		return new self( $pdo, $prefix );
	}

	public function pdo(): \PDO {
		return $this->pdo;
	}

	public function table( string $name ): string {
		return Schema::table( $name, $this->prefix );
	}

	public function prefix(): string {
		return $this->prefix;
	}

	public function get_results( string $sql, array $args = array() ): array {
		$rows = $this->run( $sql, $args )->fetchAll();
		return array_map( array( $this, 'stringify' ), $rows );
	}

	public function get_row( string $sql, array $args = array() ): ?array {
		$row = $this->run( $sql, $args )->fetch();
		return is_array( $row ) ? $this->stringify( $row ) : null;
	}

	public function get_var( string $sql, array $args = array() ): ?string {
		$row = $this->run( $sql, $args )->fetch( \PDO::FETCH_NUM );
		if ( ! is_array( $row ) || ! array_key_exists( 0, $row ) || null === $row[0] ) {
			return null;
		}
		return (string) $row[0];
	}

	public function get_col( string $sql, array $args = array() ): array {
		$values = $this->run( $sql, $args )->fetchAll( \PDO::FETCH_COLUMN, 0 );
		return array_values( array_map( static fn( $v ) => null === $v ? null : (string) $v, $values ) );
	}

	public function query( string $sql, array $args = array() ): int {
		return $this->run( $sql, $args )->rowCount();
	}

	public function insert( string $table, array $data ): int {
		$columns = array_keys( $data );
		$sql     = sprintf(
			'INSERT INTO `%s` (%s) VALUES (%s)',
			$this->table( $table ),
			implode( ', ', array_map( static fn( $c ) => '`' . $c . '`', $columns ) ),
			implode( ', ', array_fill( 0, count( $columns ), '?' ) )
		);
		$stmt = $this->pdo->prepare( $sql );
		$stmt->execute( array_values( array_map( array( $this, 'bindable' ), $data ) ) );
		return (int) $this->pdo->lastInsertId();
	}

	public function update( string $table, array $data, array $where ): int {
		$set    = array();
		$values = array();
		foreach ( $data as $column => $value ) {
			$set[]    = '`' . $column . '` = ?';
			$values[] = $this->bindable( $value );
		}
		[ $where_sql, $where_values ] = $this->where( $where );
		$stmt = $this->pdo->prepare( sprintf( 'UPDATE `%s` SET %s WHERE %s', $this->table( $table ), implode( ', ', $set ), $where_sql ) );
		$stmt->execute( array_merge( $values, $where_values ) );
		return $stmt->rowCount();
	}

	public function delete( string $table, array $where ): int {
		[ $where_sql, $where_values ] = $this->where( $where );
		$stmt = $this->pdo->prepare( sprintf( 'DELETE FROM `%s` WHERE %s', $this->table( $table ), $where_sql ) );
		$stmt->execute( $where_values );
		return $stmt->rowCount();
	}

	public function transaction( callable $fn ): mixed {
		if ( 0 === $this->depth ) {
			$this->pdo->beginTransaction();
		}
		++$this->depth;
		try {
			$result = $fn( $this );
			--$this->depth;
			if ( 0 === $this->depth ) {
				$this->pdo->commit();
			}
			return $result;
		} catch ( \Throwable $e ) {
			--$this->depth;
			if ( 0 === $this->depth && $this->pdo->inTransaction() ) {
				$this->pdo->rollBack();
			}
			throw $e;
		}
	}

	public function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Übersetzt %d/%s/%f in ?-Platzhalter und führt aus.
	 *
	 * @param list<int|float|string|null> $args Werte.
	 */
	private function run( string $sql, array $args ): \PDOStatement {
		$parts = Placeholders::split( $sql, count( $args ) );
		$out   = '';
		foreach ( $parts['tokens'] as $i => $token ) {
			$out .= str_replace( '%%', '%', $parts['literals'][ $i ] ) . '?';
		}
		$out .= str_replace( '%%', '%', $parts['literals'][ count( $parts['tokens'] ) ] );

		$values = array();
		foreach ( $parts['tokens'] as $i => $token ) {
			$value = $args[ $i ];
			if ( null === $value ) {
				$values[] = null;
			} elseif ( '%d' === $token ) {
				$values[] = (int) $value;
			} elseif ( '%f' === $token ) {
				$values[] = (float) $value;
			} else {
				$values[] = (string) $value;
			}
		}
		$stmt = $this->pdo->prepare( $out );
		$stmt->execute( $values );
		return $stmt;
	}

	/**
	 * @param array<string,int|float|string|null> $where Bedingungen.
	 * @return array{0:string,1:list<int|float|string|null>}
	 */
	private function where( array $where ): array {
		if ( array() === $where ) {
			throw new DbException( 'Leere WHERE-Bedingung ist nicht erlaubt.' );
		}
		$parts  = array();
		$values = array();
		foreach ( $where as $column => $value ) {
			if ( null === $value ) {
				$parts[] = '`' . $column . '` IS NULL';
			} else {
				$parts[]  = '`' . $column . '` = ?';
				$values[] = $this->bindable( $value );
			}
		}
		return array( implode( ' AND ', $parts ), $values );
	}

	private function bindable( mixed $value ): int|float|string|null {
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}
		return $value;
	}

	/**
	 * @param array<string,mixed> $row Zeile.
	 * @return array<string,string|null>
	 */
	private function stringify( array $row ): array {
		return array_map( static fn( $v ) => null === $v ? null : (string) $v, $row );
	}
}
