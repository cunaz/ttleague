<?php
/**
 * HTTP-Versand an den Push-Dienst (in Tests ersetzbar).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications\Push;

interface Transport {

	/**
	 * POST-Anfrage; liefert den HTTP-Status oder 0 bei einem Netzwerkfehler.
	 *
	 * @param string               $url     Endpunkt.
	 * @param array<string,string> $headers Kopfzeilen.
	 * @param string               $body    Verschlüsselter Inhalt.
	 */
	public function post( string $url, array $headers, string $body ): int;
}
