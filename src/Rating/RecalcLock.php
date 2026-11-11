<?php
/**
 * Benannte Datenbanksperre für die Neuberechnung der Wertungszahl.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Rating;

use TTLeague\Infrastructure\Database\Db;

/**
 * Wer die Wertungszahlen neu berechnet, hält diese Sperre für die Dauer des Laufs. Wer aktuelle
 * Wertungszahlen braucht (Klassierung), nimmt sie zuerst: So wartet er auf einen Lauf, der
 * gerade rechnet, statt mit dem alten Stand zu arbeiten.
 *
 * GET_LOCK gilt für den ganzen Datenbankserver und gehört der Verbindung, nicht der Transaktion.
 * Der Name enthält deshalb Datenbank und Tabellenpräfix (als Hash, wegen der Höchstlänge von
 * 64 Zeichen), damit sich Installationen auf demselben Server nicht stören. Für dieselbe
 * Verbindung ist die Sperre wiedereintrittsfähig und muss dann ebenso oft freigegeben werden:
 * acquire() und release() gehören immer paarweise zusammen.
 */
final class RecalcLock {

	public function __construct( private readonly Db $db, private readonly string $name ) {}

	/**
	 * Name der Sperre einer Installation.
	 */
	public static function name( string $database, string $prefix ): string {
		return 'ttl_rating_recalc_' . md5( $database . ':' . $prefix );
	}

	/**
	 * Nimmt die Sperre und wartet dafür höchstens so viele Sekunden (0: nicht warten).
	 *
	 * @return bool false, wenn eine andere Verbindung sie nach der Wartezeit noch hält oder die Datenbank sie verweigert.
	 */
	public function acquire( int $wait_seconds ): bool {
		return '1' === $this->db->get_var( 'SELECT GET_LOCK(%s, %d)', array( $this->name, max( 0, $wait_seconds ) ) );
	}

	public function release(): void {
		$this->db->get_var( 'SELECT RELEASE_LOCK(%s)', array( $this->name ) );
	}
}
