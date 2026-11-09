<?php
/**
 * Personen- und Spielerreferenzen für die Darstellung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\ReadModel\Support;

/**
 * Eine Spielerreferenz ist ein Array
 * array{id:int,license:int,first_name:string,last_name:string,name:string,slug:string}.
 * Angezeigt wird «Vorname Nachname», sortiert nach Nachname.
 */
final class Names {

	/**
	 * Anzeigename «Vorname Nachname».
	 */
	public static function full( ?string $first_name, ?string $last_name ): string {
		return trim( trim( (string) $first_name ) . ' ' . trim( (string) $last_name ) );
	}

	/**
	 * Spielerreferenz aus einer Zeile mit id/player_id, license_no, first_name, last_name.
	 *
	 * @param array<string,string|null> $row Datenbankzeile.
	 * @return array{id:int,license:int,first_name:string,last_name:string,name:string,slug:string}
	 */
	public static function player( array $row, string $id_column = 'player_id' ): array {
		$first = (string) ( $row['first_name'] ?? '' );
		$last  = (string) ( $row['last_name'] ?? '' );
		$name  = self::full( $first, $last );
		return array(
			'id'         => (int) ( $row[ $id_column ] ?? 0 ),
			'license'    => (int) ( $row['license_no'] ?? 0 ),
			'first_name' => $first,
			'last_name'  => $last,
			'name'       => $name,
			'slug'       => Slug::make( $name ),
		);
	}

	/**
	 * Sortierschlüssel «Nachname Vorname» ohne Akzente.
	 *
	 * @param array{first_name:string,last_name:string} $player Spielerreferenz.
	 */
	public static function sort_key( array $player ): string {
		return Slug::make( $player['last_name'] . ' ' . $player['first_name'] );
	}
}
