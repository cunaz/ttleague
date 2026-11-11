<?php
/**
 * Einstieg des Moduls «Verschiebung und Terminvorschlag» in WordPress.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Postponement;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\MyAreaContext;
use TTLeague\Accounts\Services;
use TTLeague\Domain\Match\PostponementRules as Rules;
use TTLeague\Export\Exports;
use TTLeague\Infrastructure\Flash;
use TTLeague\Notifications\Deadlines;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Notifications;
use TTLeague\Notifications\WordPressLinks;
use TTLeague\Results\Actor;
use TTLeague\Results\EntryBlock;
use TTLeague\Results\ResultException;
use TTLeague\Results\ResultRepository;
use TTLeague\Results\Results;

/**
 * Hängt nur Hooks ein (docs/verschiebung.md, docs/saisonbetrieb.md):
 *
 * - REST-Routen und Formulare an admin-post.php; beide rufen den PostponementService auf.
 * - Abschnitt «Termin» auf der Erfassungsseite (Filter ttleague_entry_sections), Abschnitt in
 *   «Mein Bereich» vor «Meine Spiele», Link «Termin verschieben» in «Meine Spiele» (Filter
 *   ttleague_my_games_actions) und im Matchreport (Filter ttleague_report_actions).
 * - Liste «Verschiebungen» in der Übersicht der TK (Filter ttleague_tk_overview_sections) und
 *   CSV-Export «verschiebungen» (Filter ttleague_exports).
 * - Benachrichtigungstypen postponement und postponement_tk, Sofortmeldungen an der Aktion
 *   ttleague_postponement_changed und die Erinnerung an die Gegenseite im stündlichen Lauf
 *   der Fristen (Notifications::CRON_HOOK), ohne eigenen Cron.
 */
final class Postponements {

	/** Vor «Meine Spiele» (Standardpriorität 10): Ein Antrag der Gegenseite steht zuoberst. */
	public const AREA_PRIORITY = 5;

	/** Name der Liste im CSV-Export (wp ttleague export verschiebungen). */
	public const EXPORT = 'verschiebungen';

	public static function register(): void {
		add_action( 'rest_api_init', array( RestController::class, 'register_routes' ) );
		Forms::register();

		add_filter( 'ttleague_entry_sections', array( self::class, 'entry_section' ), 10, 2 );
		add_action( 'ttleague_my_area_sections', array( self::class, 'my_area_section' ), self::AREA_PRIORITY );
		add_filter( 'ttleague_my_games_actions', array( self::class, 'my_games_action' ), 10, 2 );
		add_filter( 'ttleague_report_actions', array( self::class, 'report_action' ), 10, 3 );
		add_filter( 'ttleague_tk_overview_sections', array( self::class, 'tk_section' ) );

		add_filter( 'ttleague_exports', array( self::class, 'exports' ) );

		add_filter( 'ttleague_notification_types', array( self::class, 'notification_types' ) );
		add_action( 'ttleague_postponement_changed', array( self::class, 'on_changed' ), 10, 4 );
		add_action( Notifications::CRON_HOOK, array( self::class, 'run_reminders' ) );
	}

	public static function service(): PostponementService {
		return new PostponementService(
			Services::db(),
			Capabilities::policy(),
			Services::clock(),
			wp_timezone(),
			Services::audit(),
			new WordPressPostponementEvents(),
			static function ( int $user_id ): ?string {
				$user = get_userdata( $user_id );
				return false === $user ? null : $user->display_name;
			}
		);
	}

	public static function notifications(): PostponementNotifications {
		/** Dokumentiert in Notifications::deadlines(). */
		$hours = (array) apply_filters(
			'ttleague_notification_hours',
			array(
				'from_hour'   => Deadlines::FROM_HOUR,
				'until_hour'  => Deadlines::UNTIL_HOUR,
				'digest_hour' => Deadlines::DIGEST_HOUR,
			)
		);
		return new PostponementNotifications(
			new PostponementRepository( Services::db() ),
			new ResultRepository( Services::db() ),
			new Parties( Capabilities::policy() ),
			Notifications::recipients(),
			Notifications::notifier(),
			new PostponementMessages( new WordPressLinks() ),
			Services::clock(),
			wp_timezone(),
			$hours,
			static function ( int $user_id ): ?string {
				$user = get_userdata( $user_id );
				return false === $user ? null : $user->display_name;
			}
		);
	}

	/**
	 * Filter ttleague_notification_types: Typ => Bezeichnung für die Einstellungen.
	 *
	 * @param mixed $types Typen der anderen Module.
	 * @return array<string,string>
	 */
	public static function notification_types( $types ): array {
		$types = is_array( $types ) ? $types : array();
		return $types + array(
			EventTypes::POSTPONEMENT    => __( 'Verschiebung oder Terminvorschlag: Antrag, Antwort, Entscheid', 'ttleague' ),
			EventTypes::POSTPONEMENT_TK => __( 'Verschiebungen für die TK: zur Kenntnis oder zur Bewilligung', 'ttleague' ),
		);
	}

	/**
	 * Aktion ttleague_postponement_changed: Sofortmeldungen. Ein Fehler beim Versand bricht die
	 * auslösende Aktion nie ab.
	 */
	public static function on_changed( int $postponement_id, string $action, int $user_id, ?int $person_id ): void {
		try {
			self::notifications()->changed( $postponement_id, $action, $user_id, $person_id );
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, 'Verschiebung ' . $postponement_id );
		}
	}

	/**
	 * Stündlicher Lauf der Fristen: Erinnerung an die Gegenseite.
	 */
	public static function run_reminders(): void {
		try {
			self::notifications()->remind();
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, 'Erinnerung Verschiebung' );
		}
	}

	/**
	 * Filter ttleague_entry_sections: Abschnitt «Termin» auf der Erfassungsseite. Leer ohne
	 * Rolle für die Begegnung.
	 *
	 * @param mixed $html       Abschnitte der anderen Module.
	 * @param mixed $fixture_id Begegnung.
	 */
	public static function entry_section( $html, $fixture_id ): string {
		$html       = is_string( $html ) ? $html : '';
		$fixture_id = (int) $fixture_id;
		if ( $fixture_id <= 0 || ! is_user_logged_in() || ! current_user_can( Capabilities::RESCHEDULE, $fixture_id ) ) {
			return $html;
		}
		$actor = Results::current_actor();
		try {
			$overview = self::service()->overview( $fixture_id, $actor );
		} catch ( ResultException ) {
			return $html;
		}
		$now = Services::clock()->now()->setTimezone( wp_timezone() );
		return $html . Views::entry_section( $overview, Flash::take( Forms::FLASH, $actor->user_id ), $now, $actor->is_tk );
	}

	/**
	 * Aktion ttleague_my_area_sections: offene Anträge und Begegnungen ohne Termin.
	 */
	public static function my_area_section( MyAreaContext $context ): void {
		$roles = $context->roles;
		if ( array() === $roles['captain_teams'] && array() === $roles['tk_chef_clubs'] && array() === $roles['cup_entries'] ) {
			return;
		}
		$mine = self::service()->mine( new Actor( $context->user_id, $context->person_id, $context->is_tk ) );
		$html = Views::my_area_section( $mine, Flash::take( Forms::FLASH, $context->user_id ), array( Results::class, 'entry_url' ) );
		if ( '' === $html ) {
			return;
		}
		wp_enqueue_style( EntryBlock::STYLE );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Views escaped jede Ausgabe.
	}

	/**
	 * Filter ttleague_my_games_actions: Link «Termin verschieben» bei offenen Begegnungen in
	 * «Meine Spiele».
	 *
	 * @param mixed $html    Links der anderen Module.
	 * @param mixed $fixture Begegnung aus ResultService::my_fixtures().
	 */
	public static function my_games_action( $html, $fixture ): string {
		$html = is_string( $html ) ? $html : '';
		if ( ! is_array( $fixture ) || ! Rules::fixture_open( (string) ( $fixture['status'] ?? '' ) ) ) {
			return $html;
		}
		return $html . Views::link( Results::entry_url( (int) $fixture['id'] ), __( 'Termin verschieben', 'ttleague' ) );
	}

	/**
	 * Filter ttleague_report_actions: Link im Matchreport für Personen mit Recht, solange die
	 * Begegnung offen ist. Für Gäste bleibt der Matchreport unverändert.
	 *
	 * @param mixed $html       Knöpfe der anderen Module.
	 * @param mixed $fixture_id Begegnung.
	 * @param mixed $status     Status der Begegnung.
	 */
	public static function report_action( $html, $fixture_id, $status ): string {
		$html       = is_string( $html ) ? $html : '';
		$fixture_id = (int) $fixture_id;
		if ( $fixture_id <= 0 || ! Rules::fixture_open( (string) $status ) || ! is_user_logged_in() || ! current_user_can( Capabilities::RESCHEDULE, $fixture_id ) ) {
			return $html;
		}
		try {
			$overview = self::service()->overview( $fixture_id, Results::current_actor() );
		} catch ( ResultException ) {
			return $html;
		}
		if ( null !== $overview['open'] ) {
			$label = $overview['can']['accept'] ? __( 'Antrag auf neuen Termin beantworten', 'ttleague' ) : __( 'Antrag auf neuen Termin ansehen', 'ttleague' );
		} elseif ( $overview['can']['request'] ) {
			$label = Rules::PROPOSE === $overview['kind'] ? __( 'Termin vorschlagen', 'ttleague' ) : __( 'Termin verschieben', 'ttleague' );
		} else {
			return $html;
		}
		return $html . '<p class="ttl-actions ttl-report-action">' . Views::link( Results::entry_url( $fixture_id ), $label, 'ttl-button ttl-button--secondary' ) . '</p>';
	}

	/**
	 * Filter ttleague_exports: Liste «verschiebungen» (alle Anträge einer Saison) als CSV.
	 *
	 * @param mixed $exports Listen der anderen Module.
	 * @return array<string,mixed>
	 */
	public static function exports( $exports ): array {
		$exports                 = is_array( $exports ) ? $exports : array();
		$exports[ self::EXPORT ] = array(
			'title'  => __( 'Verschiebungen und Terminvorschläge', 'ttleague' ),
			'header' => array(
				__( 'Begegnung', 'ttleague' ),
				__( 'Konkurrenz', 'ttleague' ),
				__( 'Heim', 'ttleague' ),
				__( 'Gast', 'ttleague' ),
				__( 'Art', 'ttleague' ),
				__( 'Bisheriger Termin', 'ttleague' ),
				__( 'Neuer Termin', 'ttleague' ),
				__( 'Status', 'ttleague' ),
				__( 'Beantragt von', 'ttleague' ),
				__( 'Beantragt am (UTC)', 'ttleague' ),
				__( 'Begründung', 'ttleague' ),
				__( 'Beantwortet am (UTC)', 'ttleague' ),
				__( 'Bemerkung', 'ttleague' ),
				__( 'Entschieden von', 'ttleague' ),
				__( 'Entschieden am (UTC)', 'ttleague' ),
			),
			'rows'   => array( self::class, 'export_rows' ),
		);
		return $exports;
	}

	/**
	 * Zeilen des Exports: alle Anträge der Saison (Filter «saison» mit dem Slug, sonst die aktive).
	 *
	 * @param array<string,string> $args Filter der Anfrage.
	 * @return list<list<string>>
	 */
	public static function export_rows( array $args ): array {
		$repo   = new PostponementRepository( Services::db() );
		$slug   = (string) ( $args['saison'] ?? '' );
		$season = '' === $slug ? Capabilities::policy()->active_season_id() : $repo->season_by_slug( $slug );
		if ( null === $season ) {
			return array();
		}
		$rows  = $repo->export_rows( $season );
		$users = array();
		foreach ( $rows as $row ) {
			array_push( $users, (int) $row['requested_by'], (int) $row['accepted_by'], (int) $row['approved_by'] );
		}
		$names = $repo->names_for_users( $users );
		$name  = static function ( ?string $user_id ) use ( $names ): string {
			if ( null === $user_id ) {
				return '';
			}
			if ( isset( $names[ (int) $user_id ] ) ) {
				return $names[ (int) $user_id ];
			}
			// Konto ohne Person (z. B. ein Konto der TK).
			$user = get_userdata( (int) $user_id );
			return false === $user ? '' : (string) $user->display_name;
		};
		$out   = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				(string) $row['fixture_id'],
				(string) $row['competition'],
				(string) $row['home_name'],
				(string) $row['away_name'],
				Rules::PROPOSE === $row['kind'] ? __( 'Terminvorschlag', 'ttleague' ) : __( 'Verschiebung', 'ttleague' ),
				(string) $row['old_date'],
				(string) $row['new_date'],
				PostponementService::status_label( (string) $row['status'] ),
				$name( $row['requested_by'] ),
				(string) $row['created_at'],
				(string) $row['reason'],
				(string) $row['responded_at'],
				(string) $row['response_note'],
				$name( $row['approved_by'] ),
				(string) $row['approved_at'],
			);
		}
		return $out;
	}

	/**
	 * Filter ttleague_tk_overview_sections: Liste «Verschiebungen».
	 *
	 * @param mixed $sections Listen der anderen Module.
	 * @return list<array<string,mixed>>
	 */
	public static function tk_section( $sections ): array {
		$sections = is_array( $sections ) ? array_values( $sections ) : array();
		if ( ! current_user_can( Capabilities::TK ) ) {
			return $sections;
		}
		$lists      = self::service()->for_tk( Results::current_actor() );
		$sections[] = array(
			'id'    => Views::TK_ANCHOR,
			'title' => __( 'Verschiebungen', 'ttleague' ),
			'count' => count( $lists['to_approve'] ) + count( $lists['unanswered'] ),
			'html'  => Views::tk_section( $lists, array( Results::class, 'entry_url' ), wp_timezone(), Exports::url( self::EXPORT ) ),
		);
		return $sections;
	}
}
