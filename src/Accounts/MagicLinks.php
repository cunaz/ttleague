<?php
/**
 * Einmal-Tokens für die Anmeldung per Link.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;

/**
 * Ein Token besteht aus 32 Zufallsbytes (base64url, 43 Zeichen). Gespeichert wird nur der
 * SHA-256-Hash. Ein Token ist einmal verwendbar und läuft ab (Anmeldung 15 Minuten,
 * Einladung 7 Tage).
 */
final class MagicLinks {

	public const LOGIN_TTL  = 900;
	public const INVITE_TTL = 604800;

	public function __construct( private readonly Db $db, private readonly Clock $clock ) {}

	public function create( int $person_id, int $ttl_seconds, ?string $ip = null ): string {
		$token = self::encode( random_bytes( 32 ) );
		$now   = $this->clock->now();
		$this->db->insert(
			'login_token',
			array(
				'person_id'    => $person_id,
				'token_hash'   => self::hash( $token ),
				'expires_at'   => $now->modify( '+' . $ttl_seconds . ' seconds' )->format( 'Y-m-d H:i:s' ),
				'used_at'      => null,
				'requested_ip' => null === $ip ? null : mb_substr( $ip, 0, 45 ),
				'created_at'   => $now->format( 'Y-m-d H:i:s' ),
			)
		);
		return $token;
	}

	/**
	 * Gültiges, unverbrauchtes Token? Liefert die Person, ohne das Token zu verbrauchen.
	 */
	public function peek( string $token ): ?int {
		if ( ! self::well_formed( $token ) ) {
			return null;
		}
		$id = $this->db->get_var(
			"SELECT person_id FROM {$this->db->table( 'login_token' )} WHERE token_hash = %s AND used_at IS NULL AND expires_at > %s",
			array( self::hash( $token ), $this->clock->now()->format( 'Y-m-d H:i:s' ) )
		);
		return null === $id ? null : (int) $id;
	}

	/**
	 * Verbraucht das Token atomar; liefert die Person oder null (ungültig, abgelaufen, schon benutzt).
	 */
	public function consume( string $token ): ?int {
		if ( ! self::well_formed( $token ) ) {
			return null;
		}
		$now  = $this->clock->now()->format( 'Y-m-d H:i:s' );
		$hash = self::hash( $token );
		$done = $this->db->query(
			"UPDATE {$this->db->table( 'login_token' )} SET used_at = %s WHERE token_hash = %s AND used_at IS NULL AND expires_at > %s",
			array( $now, $hash, $now )
		);
		if ( 1 !== $done ) {
			return null;
		}
		$id = $this->db->get_var( "SELECT person_id FROM {$this->db->table( 'login_token' )} WHERE token_hash = %s", array( $hash ) );
		return null === $id ? null : (int) $id;
	}

	/**
	 * Entfernt Tokens, die seit mehr als einem Tag abgelaufen oder benutzt sind.
	 */
	public function purge(): int {
		$limit = $this->clock->now()->modify( '-1 day' )->format( 'Y-m-d H:i:s' );
		return $this->db->query(
			"DELETE FROM {$this->db->table( 'login_token' )} WHERE expires_at < %s OR ( used_at IS NOT NULL AND used_at < %s )",
			array( $limit, $limit )
		);
	}

	public static function well_formed( string $token ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $token );
	}

	private static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	private static function encode( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url für Tokens.
	}
}
