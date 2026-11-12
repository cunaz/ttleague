<?php
/**
 * Begrenzte Fotohistorie pro Begegnung, auch bei gleichzeitigen Uploads.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Database\DbException;

final class PhotoUploads {

	public const MAX_PER_FIXTURE = 5;

	public function __construct( private readonly Db $db ) {}

	public static function lock_name( string $database, string $prefix, int $fixture_id ): string {
		return 'ttl_photo_' . md5( $database . ':' . $prefix . ':' . $fixture_id );
	}

	/**
	 * Die Sperre umfasst Prüfung, Speichern, Verknüpfen und ggf. Aufräumen. Keine äussere
	 * Transaktion: Dateischreibvorgänge lassen sich nicht mit den Anhang-Zeilen zurückrollen.
	 * Historische Anhänge bleiben als Belege erhalten und zählen auch im Papierkorb mit.
	 *
	 * @param callable():int $upload Speichert und verknüpft ein Foto; liefert dessen Anhang-ID.
	 */
	public function run( int $fixture_id, callable $upload ): int {
		try {
			$name = self::lock_name( (string) $this->db->get_var( 'SELECT DATABASE()' ), $this->db->prefix(), $fixture_id );
			// GET_LOCK wäre auf derselben Verbindung rekursiv: auch verschachtelte Uploads abweisen.
			$locked = $this->db->get_var( 'SELECT IF(IS_USED_LOCK(%s) = CONNECTION_ID(), 0, GET_LOCK(%s, 0))', array( $name, $name ) );
			if ( '1' !== $locked ) {
				throw ResultException::unavailable( __( 'Ein Foto wird gerade verarbeitet oder die Upload-Sperre ist nicht verfügbar. Bitte versuche es später erneut.', 'ttleague' ) );
			}
			try {
				$prefix = $this->db->prefix();
				$photos = $this->db->get_col(
					"SELECT p.ID FROM {$prefix}posts p WHERE p.post_type = 'attachment'
					AND EXISTS (SELECT 1 FROM {$prefix}postmeta m WHERE m.post_id = p.ID AND m.meta_key = '_ttleague_fixture' AND m.meta_value = %s)
					LIMIT %d FOR UPDATE",
					array( (string) $fixture_id, self::MAX_PER_FIXTURE )
				);
				if ( count( $photos ) >= self::MAX_PER_FIXTURE ) {
					throw ResultException::conflict( __( 'Zu dieser Begegnung sind bereits fünf Fotos gespeichert. Bitte die Website-Verwaltung bitten, nicht mehr benötigte Anhänge endgültig zu löschen.', 'ttleague' ) );
				}
				return $upload();
			} finally {
				$this->db->get_var( 'SELECT RELEASE_LOCK(%s)', array( $name ) );
			}
		} catch ( DbException | \PDOException $e ) {
			throw ResultException::unavailable( __( 'Die Fotogrenze liess sich nicht prüfen. Bitte versuche es später erneut.', 'ttleague' ) );
		}
	}
}
