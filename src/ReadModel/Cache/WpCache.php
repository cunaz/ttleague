<?php
/**
 * Cache über den WordPress-Object-Cache, ohne persistenten Object-Cache über Transients.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\ReadModel\Cache;

use TTLeague\Infrastructure\Cache\DataVersion;

/**
 * Der Schlüssel enthält die Datenversion (DataVersion::key()); nach jedem Schreibvorgang
 * zeigt er auf einen neuen Eintrag, alte Einträge laufen ab. Werte werden in ein Array
 * verpackt, damit auch null und false gespeichert werden können.
 */
final class WpCache implements Cache {

	private const GROUP = 'ttleague';

	public function __construct( private readonly int $ttl = 86400 ) {}

	public function remember( string $name, array $parts, callable $compute ): mixed {
		$key   = DataVersion::key( $name, $parts );
		$found = false;
		$value = wp_cache_get( $key, self::GROUP, false, $found );
		if ( $found && is_array( $value ) && array_key_exists( 'v', $value ) ) {
			return $value['v'];
		}

		$persistent = wp_using_ext_object_cache();
		if ( ! $persistent ) {
			$stored = get_transient( $key );
			if ( is_array( $stored ) && array_key_exists( 'v', $stored ) ) {
				wp_cache_set( $key, $stored, self::GROUP, $this->ttl );
				return $stored['v'];
			}
		}

		$wrapped = array( 'v' => $compute() );
		wp_cache_set( $key, $wrapped, self::GROUP, $this->ttl );
		if ( ! $persistent ) {
			set_transient( $key, $wrapped, $this->ttl );
		}
		return $wrapped['v'];
	}
}
