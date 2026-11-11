<?php
/**
 * Einstieg der Klassierung zum Saisonende in WordPress.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Classification;

use TTLeague\Accounts\Services;
use TTLeague\Domain\Rating\ClassificationScale;
use TTLeague\Infrastructure\Cache\DataVersion;

/**
 * Hängt nur Hooks ein: die Seite «Spielbetrieb → Klassierung», ihre Formularaktionen
 * (admin-post.php) und den Export «classification». Die Regeln stehen im ProposalService.
 */
final class Classification {

	/** Seite unter «Spielbetrieb». */
	public const PAGE = 'ttleague-tk-klassierung';

	/** Platz im Menü: nach den Seiten des laufenden Betriebs, vor «Konten und Einladungen» (90). */
	public const POSITION = 70;

	/** Name der Liste für den CSV-Export. */
	public const EXPORT = 'classification';

	public const COMPUTE     = 'ttleague_classification_compute';
	public const ACCEPT      = 'ttleague_classification_accept';
	public const ACCEPT_OPEN = 'ttleague_classification_accept_open';
	public const OVERRIDE    = 'ttleague_classification_override';
	public const CLOSE       = 'ttleague_classification_close';
	public const REOPEN      = 'ttleague_classification_reopen';

	public static function register(): void {
		add_filter( 'ttleague_tk_pages', array( self::class, 'pages' ) );
		add_filter( 'ttleague_exports', array( self::class, 'exports' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'styles' ) );
		add_action( 'admin_post_' . self::COMPUTE, array( Actions::class, 'compute' ) );
		add_action( 'admin_post_' . self::ACCEPT, array( Actions::class, 'accept' ) );
		add_action( 'admin_post_' . self::ACCEPT_OPEN, array( Actions::class, 'accept_open' ) );
		add_action( 'admin_post_' . self::OVERRIDE, array( Actions::class, 'override' ) );
		add_action( 'admin_post_' . self::CLOSE, array( Actions::class, 'close' ) );
		add_action( 'admin_post_' . self::REOPEN, array( Actions::class, 'reopen' ) );
	}

	/**
	 * Dienst mit der Datenbank von WordPress; nach jedem Schreibvorgang eine neue Cache-Version.
	 */
	public static function service(): ProposalService {
		return new ProposalService(
			Services::db(),
			Services::clock(),
			static function (): void {
				DataVersion::bump();
			}
		);
	}

	/**
	 * @param mixed $pages Seiten unter «Spielbetrieb» (Filter ttleague_tk_pages).
	 * @return array<int|string,mixed>
	 */
	public static function pages( $pages ): array {
		$pages   = is_array( $pages ) ? $pages : array();
		$pages[] = array(
			'slug'     => self::PAGE,
			'title'    => __( 'Klassierung', 'ttleague' ),
			'render'   => array( Page::class, 'render' ),
			'position' => self::POSITION,
		);
		return $pages;
	}

	/**
	 * @param mixed $exports Listen für den CSV-Export (Filter ttleague_exports).
	 * @return array<int|string,mixed>
	 */
	public static function exports( $exports ): array {
		$exports                 = is_array( $exports ) ? $exports : array();
		$exports[ self::EXPORT ] = array(
			'title'  => __( 'Klassierungssitzung', 'ttleague' ),
			'header' => array(
				__( 'Lizenznummer', 'ttleague' ),
				__( 'Name', 'ttleague' ),
				__( 'Club', 'ttleague' ),
				__( 'Bisherige Klassierung', 'ttleague' ),
				__( 'Wertungszahl', 'ttleague' ),
				__( 'Spiele', 'ttleague' ),
				__( 'Vorschlag', 'ttleague' ),
				__( 'Entscheid', 'ttleague' ),
				__( 'Bemerkung', 'ttleague' ),
			),
			'rows'   => array( self::class, 'export_rows' ),
		);
		return $exports;
	}

	/**
	 * Zeilen der Liste «Klassierungssitzung». Filter: club (ID), deviating=1, open=1 wie auf der
	 * Seite; season (ID) für eine andere als die aktive Saison. Ein ungültiger Wert für season
	 * ergibt eine leere Liste statt der aktiven Saison.
	 *
	 * @param array<string,string> $args Filter der Anfrage.
	 * @return list<list<int|float|string>>
	 */
	public static function export_rows( array $args ): array {
		$season = null;
		if ( isset( $args['season'] ) && '' !== $args['season'] ) {
			if ( ! ctype_digit( $args['season'] ) ) {
				return array();
			}
			$season = (int) $args['season'];
		}
		$rows = array();
		foreach ( self::service()->rows( ProposalFilter::from_array( $args ), $season ) as $row ) {
			$rows[] = array(
				$row['license_no'],
				$row['name'],
				$row['club'],
				ClassificationScale::label( $row['current'] ),
				$row['rating'] ?? '',
				$row['games'],
				ClassificationScale::label( $row['proposed'] ),
				null === $row['decided'] ? '' : ClassificationScale::label( $row['decided'] ),
				$row['note'] ?? '',
			);
		}
		return $rows;
	}

	/**
	 * Wenige Regeln für die Tabelle; sonst gelten die von Tk::styles(). Die Spalten richten sich
	 * nach dem Inhalt, nur die Spalte der Formulare ist fest, damit ein geöffnetes Formular die
	 * Tabelle nicht verschiebt.
	 */
	public static function styles( string $hook ): void {
		if ( ! str_ends_with( $hook, '_page_' . self::PAGE ) ) {
			return;
		}
		wp_add_inline_style(
			'common',
			'.ttl-cl-table th,.ttl-cl-table .ttl-cl-player{white-space:nowrap}'
			. '.ttl-cl-table .ttl-col-action{width:220px}'
			. '.ttl-cl-table .ttl-cl-note{min-width:120px;overflow-wrap:anywhere}'
			. '.ttl-cl-table .ttl-cl-deviating{font-weight:600}'
			. '.ttl-cl-table .ttl-tk-inline{margin-bottom:6px}'
			. '.ttl-cl-table .ttl-tk-form{max-width:200px}'
			. '.ttl-tk .ttl-cl-open{background:#fcf9e8;color:#614a00}'
			. '.ttl-tk .ttl-cl-closed{border-left:4px solid #00a32a;background:#fff;padding:8px 12px;margin:12px 0;max-width:720px}'
		);
	}
}
