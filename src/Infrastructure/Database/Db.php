<?php
/**
 * Schmale Datenbank-Schnittstelle für alle Zugriffe des Plugins.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure\Database;

/**
 * SQL wird mit Platzhaltern wie bei $wpdb->prepare() geschrieben: %d, %s, %f, %% für ein
 * Prozentzeichen. Platzhalter stehen ohne Anführungszeichen. Tabellennamen kommen aus
 * table() und werden direkt eingesetzt, nie aus Benutzereingaben.
 *
 * Alle Leseergebnisse sind assoziative Arrays mit String-Werten (wie $wpdb mit ARRAY_A),
 * NULL bleibt null. Die Produktion nutzt WpdbDb, die Integrationstests PdoDb.
 */
interface Db {

	/**
	 * Vollständiger Name einer Plugin-Tabelle, z. B. table( 'fixture' ) => wp_ttl_fixture.
	 */
	public function table( string $name ): string;

	/**
	 * WordPress-Tabellenpräfix, z. B. wp_.
	 */
	public function prefix(): string;

	/**
	 * @param list<int|float|string|null> $args Werte für die Platzhalter.
	 * @return list<array<string,string|null>>
	 */
	public function get_results( string $sql, array $args = array() ): array;

	/**
	 * @param list<int|float|string|null> $args Werte für die Platzhalter.
	 * @return array<string,string|null>|null
	 */
	public function get_row( string $sql, array $args = array() ): ?array;

	/**
	 * Erster Wert der ersten Zeile oder null.
	 *
	 * @param list<int|float|string|null> $args Werte für die Platzhalter.
	 */
	public function get_var( string $sql, array $args = array() ): ?string;

	/**
	 * Erste Spalte aller Zeilen.
	 *
	 * @param list<int|float|string|null> $args Werte für die Platzhalter.
	 * @return list<string|null>
	 */
	public function get_col( string $sql, array $args = array() ): array;

	/**
	 * Schreibende Anweisung, liefert die Anzahl betroffener Zeilen.
	 *
	 * @param list<int|float|string|null> $args Werte für die Platzhalter.
	 */
	public function query( string $sql, array $args = array() ): int;

	/**
	 * Fügt eine Zeile ein und liefert die neue ID.
	 *
	 * @param string                               $table Kurzname, z. B. 'fixture'.
	 * @param array<string,int|float|string|null> $data  Spalte => Wert.
	 */
	public function insert( string $table, array $data ): int;

	/**
	 * Aktualisiert Zeilen; null in $where bedeutet IS NULL.
	 *
	 * @param string                               $table Kurzname.
	 * @param array<string,int|float|string|null> $data  Neue Werte.
	 * @param array<string,int|float|string|null> $where Bedingungen (UND).
	 */
	public function update( string $table, array $data, array $where ): int;

	/**
	 * @param string                               $table Kurzname.
	 * @param array<string,int|float|string|null> $where Bedingungen (UND).
	 */
	public function delete( string $table, array $where ): int;

	/**
	 * Führt die Funktion in einer Transaktion aus; bei einer Ausnahme wird zurückgerollt.
	 *
	 * @template T
	 * @param callable(Db):T $fn Arbeit.
	 * @return T
	 */
	public function transaction( callable $fn ): mixed;

	/**
	 * Aktuelle Zeit in UTC als 'Y-m-d H:i:s'.
	 */
	public function now(): string;
}
