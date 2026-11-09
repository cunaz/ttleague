<?php
/**
 * Baut aus den Zeilen des Lesemodells Kalenderereignisse mit Titel, Ort, Beschreibung und Link.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Calendar;

use TTLeague\Frontend\Support\Format;
use TTLeague\Frontend\Support\Urls;
use TTLeague\ReadModel\Support\Sql;

/**
 * Ohne WordPress-Abhängigkeit ausser den Übersetzungsfunktionen. Zeiten der Datenbank sind
 * Ortszeit Europe/Zurich; eine Anspielzeit 00:00 gilt als unbekannt (Ganztagesereignis).
 *
 * Titel: «Heim – Gast (Gruppe A)», nach dem Spiel mit Resultat «Heim – Gast (Gruppe A) 6:4»,
 * bei Forfait mit Zusatz, bei verschobenen und annullierten Begegnungen mit Hinweis davor.
 */
final class EventFactory {

	/**
	 * Dauer in Minuten: Meisterschaft 2½ h, Cup 2 h, Ranglistenturnier 3 h.
	 */
	public const MINUTES = array(
		'MM'  => 150,
		'CUP' => 120,
		'RL'  => 180,
	);

	private readonly string $host;

	public function __construct( private readonly Urls $urls ) {
		$host       = (string) parse_url( $urls->home(), PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- reine Klasse ohne WordPress.
		$this->host = '' === $host ? 'localhost' : strtolower( $host );
	}

	/**
	 * Ereignisse aus den Zeilen von Feeds::rows(); Zeilen ohne gültiges Datum fallen weg.
	 *
	 * @param list<array<string,mixed>> $rows Zeilen.
	 * @return list<IcsEvent>
	 */
	public function events( array $rows ): array {
		$events = array();
		foreach ( $rows as $row ) {
			$event = 'rl_group' === ( $row['type'] ?? '' ) ? $this->rl_group( $row ) : $this->fixture( $row );
			if ( null !== $event ) {
				$events[] = $event;
			}
		}
		return $events;
	}

	/**
	 * Begegnung der Meisterschaft oder des Cups.
	 *
	 * @param array<string,mixed> $row Zeile.
	 */
	public function fixture( array $row ): ?IcsEvent {
		$start = self::start( $row['start'] ?? null );
		if ( null === $start ) {
			return null;
		}
		[ $time, $all_day ] = $start;

		$kind        = 'CUP' === $row['kind'] ? 'CUP' : 'MM';
		$status      = (string) $row['status'];
		$competition = (string) $row['competition'];
		$home_score  = $row['home_score'];
		$away_score  = $row['away_score'];
		$has_result  = in_array( $status, Sql::COUNTED_STATUSES, true ) && null !== $home_score && null !== $away_score;

		/* translators: 1: Heimteam, 2: Gastteam, 3: Gruppe oder Cup-Kategorie. */
		$summary = sprintf( __( '%1$s – %2$s (%3$s)', 'ttleague' ), $row['home'], $row['away'], $competition );
		if ( $has_result ) {
			$summary .= ' ' . Format::score( (int) $home_score, (int) $away_score );
			if ( 'forfeit' === $status ) {
				$summary .= ' ' . __( 'Forfait', 'ttleague' );
			}
		}
		if ( 'cancelled' === $status ) {
			/* translators: %s: Titel der Begegnung. */
			$summary = sprintf( __( 'Annulliert: %s', 'ttleague' ), $summary );
		} elseif ( 'postponed' === $status ) {
			/* translators: %s: Titel der Begegnung. */
			$summary = sprintf( __( 'Verschoben: %s', 'ttleague' ), $summary );
		}

		$url = $this->urls->fixture( (int) $row['id'] );
		if ( 'CUP' === $kind ) {
			$lines = array( $competition . ' ' . $row['season_name'] );
		} else {
			/* translators: 1: Saison, 2: Gruppe. */
			$lines = array( sprintf( __( 'Meisterschaft %1$s, %2$s', 'ttleague' ), $row['season_name'], $competition ) );
		}
		if ( $has_result ) {
			$marker = in_array( $status, array( 'provisional', 'disputed', 'forfeit' ), true ) ? ' (' . Format::fixture_status( $status ) . ')' : '';
			/* translators: %s: Resultat, z. B. 6:4. */
			$lines[] = sprintf( __( 'Resultat: %s', 'ttleague' ), Format::score( (int) $home_score, (int) $away_score ) ) . $marker;
		} elseif ( in_array( $status, array( 'postponed', 'cancelled' ), true ) ) {
			/* translators: %s: Status, z. B. verschoben. */
			$lines[] = sprintf( __( 'Status: %s', 'ttleague' ), Format::fixture_status( $status ) );
		}
		$lines[] = self::venue_line( $row['venue'] ?? null );
		if ( $all_day ) {
			$lines[] = __( 'Anspielzeit noch offen.', 'ttleague' );
		}
		/* translators: %s: Adresse des Matchreports. */
		$lines[] = sprintf( __( 'Matchreport: %s', 'ttleague' ), $url );

		return new IcsEvent(
			'fixture-' . (int) $row['id'] . '@' . $this->host,
			$summary,
			$time,
			self::MINUTES[ $kind ],
			$all_day,
			self::location( $row['venue'] ?? null ),
			implode( "\n", array_filter( $lines, static fn( string $line ): bool => '' !== $line ) ),
			$url,
			self::status( $status ),
			array( Format::kind( $kind ) ),
			self::geo( $row['venue'] ?? null )
		);
	}

	/**
	 * Gruppe des Ranglistenturniers.
	 *
	 * @param array<string,mixed> $row Zeile.
	 */
	public function rl_group( array $row ): ?IcsEvent {
		$start = self::start( $row['start'] ?? null );
		if ( null === $start ) {
			return null;
		}
		[ $time, $all_day ] = $start;

		/* translators: %d: Runde. */
		$round = $row['is_final'] ? __( 'Finalrunde', 'ttleague' ) : sprintf( __( '%d. Runde', 'ttleague' ), (int) $row['round_no'] );
		/* translators: 1: Runde, 2: Kategorie, 3: Gruppe. */
		$detail = sprintf( __( '%1$s, Kategorie %2$d, Gruppe %3$d', 'ttleague' ), $round, (int) $row['category'], (int) $row['group_no'] );
		/* translators: %s: Runde, Kategorie und Gruppe. */
		$summary = sprintf( __( 'Ranglistenturnier: %s', 'ttleague' ), $detail );
		$url     = $this->urls->ranking( '' === (string) $row['season_slug'] ? null : (string) $row['season_slug'] );

		/* translators: 1: Saison, 2: Runde, Kategorie und Gruppe. */
		$lines = array( sprintf( __( 'Ranglistenturnier %1$s, %2$s', 'ttleague' ), $row['season_name'], $detail ) );
		if ( null !== $row['host'] && '' !== (string) $row['host'] ) {
			/* translators: %s: Club. */
			$lines[] = sprintf( __( 'Platzclub: %s', 'ttleague' ), $row['host'] );
		}
		$lines[] = self::venue_line( $row['venue'] ?? null );
		if ( $all_day ) {
			$lines[] = __( 'Anspielzeit noch offen.', 'ttleague' );
		}
		/* translators: %s: Adresse der Seite des Ranglistenturniers. */
		$lines[] = sprintf( __( 'Ranglistenturnier: %s', 'ttleague' ), $url );

		$location = self::location( $row['venue'] ?? null );
		if ( '' === $location && null !== $row['host'] ) {
			$location = (string) $row['host'];
		}

		return new IcsEvent(
			'rlgroup-' . (int) $row['id'] . '@' . $this->host,
			$summary,
			$time,
			self::MINUTES['RL'],
			$all_day,
			$location,
			implode( "\n", array_filter( $lines, static fn( string $line ): bool => '' !== $line ) ),
			$url,
			IcsEvent::STATUS_CONFIRMED,
			array( Format::kind( 'RL' ) ),
			self::geo( $row['venue'] ?? null )
		);
	}

	/**
	 * Kalendername, z. B. «Seefeld 1 – Spiele 2026/27».
	 *
	 * @param array<string,mixed> $feed Feed aus Feeds.
	 */
	public static function name( array $feed ): string {
		if ( null === $feed['season'] ) {
			/* translators: %s: Mannschaft, Club oder Spieler. */
			return sprintf( __( '%s – Spiele', 'ttleague' ), $feed['name'] );
		}
		/* translators: 1: Mannschaft, Club oder Spieler, 2: Saison. */
		return sprintf( __( '%1$s – Spiele %2$s', 'ttleague' ), $feed['name'], $feed['season']['name'] );
	}

	/**
	 * Beschreibung des Kalenders.
	 *
	 * @param array<string,mixed> $feed Feed aus Feeds.
	 */
	public static function description( array $feed ): string {
		if ( 'team' === $feed['type'] ) {
			/* translators: %s: Mannschaft. */
			$text = sprintf( __( 'Spielplan und Resultate von %s in der Meisterschaft.', 'ttleague' ), $feed['name'] );
		} elseif ( 'club' === $feed['type'] ) {
			/* translators: %s: Club. */
			$text = sprintf( __( 'Begegnungen aller Teams von %s in Meisterschaft und Cup.', 'ttleague' ), $feed['name'] );
		} else {
			/* translators: %s: Spieler. */
			$text = sprintf( __( 'Begegnungen der Teams von %s und Gruppen im Ranglistenturnier.', 'ttleague' ), $feed['name'] );
		}
		if ( null !== $feed['season'] ) {
			/* translators: %s: Saison. */
			$text .= ' ' . sprintf( __( 'Saison %s.', 'ttleague' ), $feed['season']['name'] );
		}
		return $text;
	}

	/**
	 * Beginn aus Ortszeit «Y-m-d H:i:s»: Zeitpunkt in Europe/Zurich und ob nur das Datum bekannt ist.
	 *
	 * @return array{0:\DateTimeImmutable,1:bool}|null
	 */
	public static function start( mixed $value ): ?array {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', trim( $value ), $m ) ) {
			return null;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		$hour    = (int) ( $m[4] ?? 0 );
		$minute  = (int) ( $m[5] ?? 0 );
		$all_day = ! isset( $m[4] ) || ( 0 === $hour && 0 === $minute );
		if ( $hour > 23 || $minute > 59 ) {
			return null;
		}
		$time = ( new \DateTimeImmutable( 'now', new \DateTimeZone( IcsWriter::TIMEZONE ) ) )
			->setDate( (int) $m[1], (int) $m[2], (int) $m[3] )
			->setTime( $all_day ? 0 : $hour, $all_day ? 0 : $minute );
		return array( $time, $all_day );
	}

	/**
	 * Ort: «Lokal, Strasse, PLZ Ort».
	 *
	 * @param array<string,mixed>|null $venue Lokal.
	 */
	private static function location( ?array $venue ): string {
		if ( null === $venue ) {
			return '';
		}
		$address = Format::address( $venue['street'] ?? null, $venue['zip'] ?? null, $venue['city'] ?? null );
		return '' === $address ? (string) $venue['name'] : $venue['name'] . ', ' . $address;
	}

	/**
	 * Zeile «Spiellokal: …» der Beschreibung, leer ohne Lokal.
	 *
	 * @param array<string,mixed>|null $venue Lokal.
	 */
	private static function venue_line( ?array $venue ): string {
		$location = self::location( $venue );
		/* translators: %s: Spiellokal mit Adresse. */
		return '' === $location ? '' : sprintf( __( 'Spiellokal: %s', 'ttleague' ), $location );
	}

	/**
	 * @param array<string,mixed>|null $venue Lokal.
	 * @return array{0:float,1:float}|null
	 */
	private static function geo( ?array $venue ): ?array {
		if ( null === $venue || null === ( $venue['lat'] ?? null ) || null === ( $venue['lng'] ?? null ) ) {
			return null;
		}
		return array( (float) $venue['lat'], (float) $venue['lng'] );
	}

	/**
	 * STATUS des Ereignisses: annulliert, verschoben (vorläufig) oder bestätigt.
	 */
	private static function status( string $status ): string {
		if ( 'cancelled' === $status ) {
			return IcsEvent::STATUS_CANCELLED;
		}
		if ( 'postponed' === $status ) {
			return IcsEvent::STATUS_TENTATIVE;
		}
		return IcsEvent::STATUS_CONFIRMED;
	}
}
