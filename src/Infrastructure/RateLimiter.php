<?php
/**
 * Einfaches Rate-Limit mit festen Zeitfenstern.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure;

use TTLeague\Infrastructure\Time\Clock;

/**
 * Zählt Versuche pro Schlüssel in einem Zeitfenster. Speicherung über zwei Callbacks
 * (in WordPress Transients, in Tests ein Array), damit die Klasse ohne WordPress testbar ist.
 */
final class RateLimiter {

	/** @var callable(string):mixed */
	private $read;

	/** @var callable(string,mixed,int):void */
	private $write;

	/**
	 * @param callable(string):mixed            $read  Liest einen gespeicherten Wert (false, wenn keiner).
	 * @param callable(string,mixed,int):void   $write Speichert einen Wert mit Ablauf in Sekunden.
	 */
	public function __construct( private readonly Clock $clock, callable $read, callable $write ) {
		$this->read  = $read;
		$this->write = $write;
	}

	public static function wordpress( Clock $clock ): self {
		return new self(
			$clock,
			static fn( string $key ) => get_transient( $key ),
			static function ( string $key, $value, int $ttl ): void {
				set_transient( $key, $value, $ttl );
			}
		);
	}

	/**
	 * Registriert einen Versuch und meldet, ob er erlaubt ist.
	 */
	public function hit( string $bucket, string $subject, int $limit, int $window_seconds ): bool {
		$now    = $this->clock->now()->getTimestamp();
		$window = intdiv( $now, $window_seconds );
		$key    = 'ttl_rl_' . md5( $bucket . '|' . $subject . '|' . $window );
		$stored = ( $this->read )( $key );
		$count  = is_numeric( $stored ) ? (int) $stored : 0;
		if ( $count >= $limit ) {
			return false;
		}
		( $this->write )( $key, $count + 1, $window_seconds );
		return true;
	}
}
