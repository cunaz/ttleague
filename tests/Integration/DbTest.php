<?php
declare(strict_types=1);

namespace TTLeague\Tests\Integration;

use TTLeague\Infrastructure\Database\DbException;

final class DbTest extends IntegrationTestCase {

	use \TTLeague\Tests\Integration\Database\TransactionAssertions;

	private function insert_club( int $number, string $short ): int {
		return $this->db->insert(
			'club',
			array(
				'number'     => $number,
				'name'       => 'TTC ' . $short,
				'short_name' => $short,
				'slug'       => strtolower( str_replace( ' ', '-', $short ) ),
				'active'     => 1,
				'source_id'  => null,
			)
		);
	}

	public function test_insert_and_read_with_placeholders(): void {
		$id  = $this->insert_club( 27, 'Swiss Life' );
		$row = $this->db->get_row( "SELECT * FROM {$this->db->table( 'club' )} WHERE number = %d AND short_name = %s", array( 27, 'Swiss Life' ) );
		self::assertSame( (string) $id, $row['id'] );
		self::assertSame( 'TTC Swiss Life', $row['name'] );
		self::assertNull( $row['source_id'] );
		self::assertSame( '1', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'club' )}" ) );
	}

	public function test_literal_percent_and_null_arguments(): void {
		$this->insert_club( 3, 'UBS' );
		$this->insert_club( 25, 'SRF' );
		$names = $this->db->get_col( "SELECT short_name FROM {$this->db->table( 'club' )} WHERE short_name LIKE 'U%%' ORDER BY number" );
		self::assertSame( array( 'UBS' ), $names );
		$count = $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'club' )} WHERE source_id <=> %s", array( null ) );
		self::assertSame( '2', $count );
	}

	public function test_update_and_delete_with_null_condition(): void {
		$id = $this->insert_club( 8, 'Züri Nord' );
		self::assertSame(
			1,
			$this->db->update(
				'club',
				array( 'active' => 0 ),
				array(
					'id'        => $id,
					'source_id' => null,
				)
			)
		);
		self::assertSame( '0', $this->db->get_var( "SELECT active FROM {$this->db->table( 'club' )} WHERE id = %d", array( $id ) ) );
		self::assertSame( 'Züri Nord', $this->db->get_var( "SELECT short_name FROM {$this->db->table( 'club' )} WHERE id = %d", array( $id ) ) );
		self::assertSame( 1, $this->db->delete( 'club', array( 'id' => $id ) ) );
	}

	public function test_transaction_rolls_back_on_exception(): void {
		try {
			$this->db->transaction(
				function (): void {
					$this->insert_club( 1, 'A' );
					throw new \RuntimeException( 'Abbruch' );
				}
			);
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'Abbruch', $e->getMessage() );
		}
		self::assertSame( '0', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'club' )}" ) );

		$result = $this->db->transaction( fn() => $this->insert_club( 2, 'B' ) );
		self::assertGreaterThan( 0, $result );
		self::assertSame( '1', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'club' )}" ) );
	}

	public function test_integer_placeholder_works_in_limit(): void {
		$this->insert_club( 1, 'A' );
		$this->insert_club( 2, 'B' );
		$this->insert_club( 3, 'C' );
		$rows = $this->db->get_col( "SELECT number FROM {$this->db->table( 'club' )} ORDER BY number LIMIT %d", array( 2 ) );
		self::assertSame( array( '1', '2' ), $rows );
	}

	public function test_wrong_argument_count_is_rejected(): void {
		$this->expectException( DbException::class );
		$this->db->get_var( 'SELECT %d + %d', array( 1 ) );
	}
}
