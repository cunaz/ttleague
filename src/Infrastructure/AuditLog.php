<?php
/**
 * Technisches Änderungsprotokoll (audit_log): wer hat wann was geändert, Zustand vorher und nachher.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure;

use TTLeague\Infrastructure\Database\Db;

final class AuditLog {

	public function __construct( private readonly Db $db ) {}

	/**
	 * @param mixed $before Zustand vorher (wird als JSON gespeichert).
	 * @param mixed $after  Zustand nachher.
	 */
	public function record( string $entity, int $entity_id, string $action, ?int $user_id, mixed $before = null, mixed $after = null, ?string $note = null ): int {
		return $this->db->insert(
			'audit_log',
			array(
				'entity'      => $entity,
				'entity_id'   => $entity_id,
				'action'      => $action,
				'user_id'     => $user_id,
				'created_at'  => $this->db->now(),
				'before_json' => null === $before ? null : self::json( $before ),
				'after_json'  => null === $after ? null : self::json( $after ),
				'note'        => null === $note ? null : mb_substr( $note, 0, 255 ),
			)
		);
	}

	/**
	 * @return list<array<string,string|null>>
	 */
	public function for_entity( string $entity, int $entity_id, int $limit = 50 ): array {
		return $this->db->get_results(
			"SELECT * FROM {$this->db->table( 'audit_log' )} WHERE entity = %s AND entity_id = %d ORDER BY id DESC LIMIT %d",
			array( $entity, $entity_id, $limit )
		);
	}

	private static function json( mixed $value ): string {
		return (string) json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- läuft auch ohne WordPress (Tests, Werkzeuge).
	}
}
