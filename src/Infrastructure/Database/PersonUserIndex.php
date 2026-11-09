<?php
/**
 * Eindeutigkeit von person.user_id: ein WordPress-Konto gehört höchstens einer Person.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure\Database;

/**
 * Neue Installationen legen den Index als UNIQUE an. Ältere haben einen gewöhnlichen Index;
 * migrate() wandelt ihn um, sobald keine doppelten Verknüpfungen mehr bestehen. Solange der
 * UNIQUE-Index fehlt, sperrt AccountService neue Verknüpfungen.
 */
final class PersonUserIndex {

	public const INDEX = 'user_id';

	/** Fehlermeldung des letzten misslungenen Umbaus (leer, wenn keiner). */
	public string $last_error = '';

	public function __construct( private readonly Db $db ) {}

	public function is_unique(): bool {
		$count = $this->db->get_var(
			'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s AND NON_UNIQUE = 0',
			array( $this->db->table( 'person' ), self::INDEX )
		);
		return (int) $count > 0;
	}

	/**
	 * Konten, die mit mehr als einer Person verknüpft sind.
	 *
	 * @return list<int>
	 */
	public function duplicates(): array {
		return array_map(
			'intval',
			$this->db->get_col( "SELECT user_id FROM {$this->db->table( 'person' )} WHERE user_id IS NOT NULL GROUP BY user_id HAVING COUNT(*) > 1 ORDER BY user_id" )
		);
	}

	/**
	 * Wandelt den Index in UNIQUE um, wenn das möglich ist.
	 *
	 * @return bool true, wenn der Index danach eindeutig ist.
	 */
	public function migrate(): bool {
		if ( $this->is_unique() ) {
			return true;
		}
		if ( array() !== $this->duplicates() ) {
			return false;
		}
		$table  = $this->db->table( 'person' );
		$exists = (int) $this->db->get_var(
			'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
			array( $table, self::INDEX )
		);
		$drop   = $exists > 0 ? 'DROP INDEX `' . self::INDEX . '`, ' : '';
		try {
			$this->db->query( "ALTER TABLE `{$table}` {$drop}ADD UNIQUE KEY `" . self::INDEX . '` (`user_id`)' );
		} catch ( \RuntimeException $e ) {
			// Z. B. Sperre auf einer ausgelasteten Seite oder fehlende Rechte: später erneut versuchen.
			$this->last_error = $e->getMessage();
			return false;
		}
		return $this->is_unique();
	}
}
