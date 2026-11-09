<?php
/**
 * Kalender-Abos (iCal): Routen, Auslieferung der Feeds und Skript des Abo-Abschnitts.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Calendar;

use TTLeague\Frontend\Frontend;
use TTLeague\Frontend\PageInstaller;
use TTLeague\Infrastructure\Cache\DataVersion;
use TTLeague\Infrastructure\Database\WpdbDb;
use TTLeague\ReadModel\Cache\WpCache;
use TTLeague\ReadModel\ReadModel;

/**
 * Feeds unter /kalender/team/{saison}/{team}.ics, /kalender/club/{club}.ics und
 * /kalender/spieler/{lizenz}.ics. Die Anfrage wird bei parse_request beantwortet, bevor
 * WordPress Beiträge abfragt oder ein Template lädt.
 */
final class Calendar {

	public const QUERY_VAR  = 'ttl_ical';
	public const SEASON_VAR = 'ttl_ical_season';
	public const KEY_VAR    = 'ttl_ical_key';

	/**
	 * Version der Rewrite-Regeln; eine Änderung erneuert die Regeln einmalig.
	 */
	public const ROUTES_VERSION = '1';

	public const ROUTES_OPTION = 'ttleague_calendar_routes';

	/**
	 * Version des Feed-Formats; fliesst ins ETag ein.
	 */
	private const FORMAT = '1';

	/**
	 * Wird von TTLeague\Plugin::boot() aufgerufen.
	 */
	public static function register(): void {
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'init', array( self::class, 'add_rewrite_rules' ) );
		add_action( 'init', array( self::class, 'maybe_flush' ), 99 );
		add_action( 'parse_request', array( self::class, 'parse_request' ) );
	}

	/**
	 * @param list<string> $vars Öffentliche Query-Variablen.
	 * @return list<string>
	 */
	public static function query_vars( array $vars ): array {
		return array_values( array_unique( array_merge( $vars, array( self::QUERY_VAR, self::SEASON_VAR, self::KEY_VAR ) ) ) );
	}

	public static function add_rewrite_rules(): void {
		foreach ( FeedUrls::rules( self::QUERY_VAR, self::SEASON_VAR, self::KEY_VAR ) as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}
	}

	/**
	 * Nach einem Update mit neuen Regeln die Rewrite-Regeln einmalig erneuern.
	 */
	public static function maybe_flush(): void {
		if ( self::ROUTES_VERSION !== get_option( self::ROUTES_OPTION ) ) {
			flush_rewrite_rules( false );
			update_option( self::ROUTES_OPTION, self::ROUTES_VERSION, true );
		}
	}

	/**
	 * parse_request: Feed ausliefern, wenn die Anfrage einer Kalenderroute entspricht.
	 */
	public static function parse_request( \WP $wp ): void {
		$type = (string) ( $wp->query_vars[ self::QUERY_VAR ] ?? '' );
		if ( '' === $type ) {
			return;
		}
		$season = (string) ( $wp->query_vars[ self::SEASON_VAR ] ?? '' );
		$key    = strtolower( (string) ( $wp->query_vars[ self::KEY_VAR ] ?? '' ) );
		self::serve( $type, $season, $key );
		exit;
	}

	/**
	 * Feed mit ETag und 304 ausliefern, unbekannte Feeds mit 404.
	 */
	private static function serve( string $type, string $season, string $key ): void {
		$model = ReadModel::instance();
		$feeds = new Feeds( WpdbDb::from_global(), new WpCache(), $model );
		$feed  = match ( $type ) {
			'team'   => $feeds->team( $season, $key ),
			'club'   => $feeds->club( $key ),
			'player' => ctype_digit( $key ) ? $feeds->player( (int) $key ) : null,
			default  => null,
		};
		if ( null === $feed ) {
			self::not_found();
			return;
		}

		$etag = ConditionalGet::etag(
			array(
				self::FORMAT,
				TTLEAGUE_VERSION,
				DataVersion::get(),
				$type,
				$season,
				$key,
				home_url( '/' ),
				(string) wp_json_encode( PageInstaller::paths() ),
				get_locale(),
			)
		);
		status_header( 200 );
		header( 'ETag: ' . $etag );
		header( 'Cache-Control: public, max-age=900' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );

		$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';
		if ( ConditionalGet::matches( $if_none_match, $etag ) ) {
			status_header( 304 );
			return;
		}

		$data    = $feeds->rows( $feed );
		$factory = new EventFactory( Frontend::urls() );
		$stamp   = new \DateTimeImmutable( $data['generated'], new \DateTimeZone( 'UTC' ) );
		$body    = ( new IcsWriter( '-//ttleague//Spielbetrieb ' . TTLEAGUE_VERSION . '//DE' ) )->calendar(
			EventFactory::name( $feed ),
			$factory->events( $data['rows'] ),
			$stamp,
			EventFactory::description( $feed )
		);

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="' . Feeds::filename( $feed ) . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		if ( 'HEAD' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' ) ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar, Werte von IcsWriter maskiert.
		}
	}

	/**
	 * Unbekannter Kalender: 404 mit kurzem Text.
	 */
	private static function not_found(): void {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo esc_html__( 'Diesen Kalender gibt es nicht.', 'ttleague' ) . "\n";
	}
}
