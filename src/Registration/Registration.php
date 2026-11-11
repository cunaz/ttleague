<?php
/**
 * Einstieg des Moduls «Online-Meldung der Clubs» in WordPress.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Registration;

use TTLeague\Accounts\Services;
use TTLeague\Domain\Registration\RegistrationRules as Rules;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Notifications\Deadlines;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Notifications;
use TTLeague\Notifications\WordPressLinks;

/**
 * Hängt nur Hooks ein (docs/meldung.md): den Abschnitt «Meldung» in «Mein Bereich», die Seite
 * «Meldungen» und die Liste «Meldungen zu prüfen» im Backend der TK, die Formularaktionen, die
 * Benachrichtigungstypen, die Erinnerungen im stündlichen Fristenlauf und die CSV-Listen.
 *
 * Filter: ttleague_registration_reminder_days (Tage vor Ablauf des Meldefensters, Standard 7
 * und 2), ttleague_registration_stamm_size (Stammspieler pro Mannschaft, Standard 3).
 */
final class Registration {

	/** Seite «Spielbetrieb → Meldungen». */
	public const PAGE = 'ttleague-tk-meldungen';

	/** Platz im Menü: gleich nach der Übersicht. */
	public const POSITION = 10;

	/** Nach «Mein Club» (20). */
	public const PRIORITY = 30;

	public static function register(): void {
		add_action( 'ttleague_my_area_sections', array( MyClubSection::class, 'render' ), self::PRIORITY );
		add_action( 'init', array( MyClubSection::class, 'register_style' ) );
		add_filter( 'ttleague_tk_pages', array( self::class, 'pages' ) );
		add_filter( 'ttleague_tk_overview_sections', array( ReviewPage::class, 'overview_sections' ) );
		add_filter( 'ttleague_notification_types', array( self::class, 'notification_types' ) );
		add_filter( 'ttleague_exports', array( RegistrationExport::class, 'exports' ) );
		// Erinnerungen laufen im stündlichen Fristenlauf der Benachrichtigungen mit, nach den Fristen der Erfassung.
		add_action( Notifications::CRON_HOOK, array( self::class, 'run_reminders' ), 20 );
		Actions::register();
	}

	/**
	 * Filter ttleague_tk_pages.
	 *
	 * @param mixed $pages Seiten.
	 * @return list<array<string,mixed>>
	 */
	public static function pages( mixed $pages ): array {
		$pages   = is_array( $pages ) ? array_values( $pages ) : array();
		$pages[] = array(
			'slug'     => self::PAGE,
			'title'    => __( 'Meldungen', 'ttleague' ),
			'render'   => array( ReviewPage::class, 'render' ),
			'position' => self::POSITION,
		);
		return $pages;
	}

	/**
	 * Filter ttleague_notification_types.
	 *
	 * @param mixed $types Typ => Bezeichnung.
	 * @return array<string,string>
	 */
	public static function notification_types( mixed $types ): array {
		$types                                      = is_array( $types ) ? $types : array();
		$types[ EventTypes::REGISTRATION_REMINDER ] = __( 'Erinnerung vor Ablauf des Meldefensters', 'ttleague' );
		$types[ EventTypes::REGISTRATION_DECIDED ]  = __( 'Entscheid der TK zur Meldung des Clubs', 'ttleague' );
		return $types;
	}

	/**
	 * Cron (stündlicher Fristenlauf): Erinnerungen vor Ablauf des Meldefensters.
	 */
	public static function run_reminders(): void {
		try {
			self::notifications()->remind();
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, RegistrationMessages::REMINDER );
		}
	}

	public static function access(): RegistrationAccess {
		return new RegistrationAccess( Services::db(), Services::policy(), Services::clock(), wp_timezone() );
	}

	public static function registration(): ClubRegistration {
		return new ClubRegistration( Services::db(), self::access(), Services::audit(), new WordPressRegistrationEvents(), self::stamm_size() );
	}

	public static function venues(): Venues {
		return new Venues( Services::db(), self::access(), Services::audit(), new WordPressRegistrationEvents() );
	}

	public static function review(): Review {
		return new Review( Services::db(), self::access(), self::registration(), Services::audit(), new WordPressRegistrationEvents() );
	}

	/**
	 * Benachrichtigungen mit der Uhr der Website oder einer festen Uhr (Tests).
	 */
	public static function notifications( ?Clock $clock = null ): RegistrationNotifications {
		/** Dieses Versandfenster gilt auch für die Fristen der Erfassung (docs/erfassung.md). */
		$hours = (array) apply_filters(
			'ttleague_notification_hours',
			array(
				'from_hour'   => Deadlines::FROM_HOUR,
				'until_hour'  => Deadlines::UNTIL_HOUR,
				'digest_hour' => Deadlines::DIGEST_HOUR,
			)
		);
		/**
		 * Erinnerungen so viele Tage vor dem letzten Tag des Meldefensters.
		 *
		 * @param list<int> $days Standard 7 und 2.
		 */
		$days = (array) apply_filters( 'ttleague_registration_reminder_days', Rules::REMINDER_DAYS );
		return new RegistrationNotifications(
			Services::db(),
			$clock ?? Services::clock(),
			wp_timezone(),
			Notifications::recipients(),
			Notifications::notifier(),
			new RegistrationMessages( new WordPressLinks() ),
			$hours,
			array_values( array_map( 'intval', $days ) )
		);
	}

	/**
	 * Bereich der Rückmeldungen (Flash) eines Clubs in «Mein Bereich».
	 */
	public static function flash_scope( int $club_id ): string {
		return 'meldung' . $club_id;
	}

	/**
	 * Stammspieler pro Mannschaft.
	 */
	public static function stamm_size(): int {
		/**
		 * Stammspieler pro Mannschaft (Art. 12.2).
		 *
		 * @param int $size Standard 3.
		 */
		return max( 1, (int) apply_filters( 'ttleague_registration_stamm_size', Rules::STAMM_SIZE ) );
	}
}
