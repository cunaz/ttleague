<?php
/**
 * Erweiterungspunkte für die Module des Saisonbetriebs: Backend-Seiten, Listen der Übersicht,
 * Benachrichtigungstypen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Tk;

use TTLeague\Accounts\Preferences;
use TTLeague\Notifications\EventTypes;
use TTLeague\Plugin;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\WordPress\TestCase;
use TTLeague\Tk\DashboardPage;
use TTLeague\Tk\Tk;

final class ExtensionPointsTest extends TestCase {

	/**
	 * Die Tests prüfen die Erweiterungspunkte selbst, ohne die Einträge der Module. Die
	 * WordPress-Testsuite stellt die Hooks nach jedem Test wieder her.
	 */
	public function set_up(): void {
		parent::set_up();
		remove_all_filters( 'ttleague_tk_pages' );
		remove_all_filters( 'ttleague_tk_overview_sections' );
		remove_all_filters( 'ttleague_notification_types' );
	}

	public function tear_down(): void {
		remove_all_filters( 'ttleague_tk_pages' );
		remove_all_filters( 'ttleague_tk_overview_sections' );
		remove_all_filters( 'ttleague_notification_types' );
		parent::tear_down();
	}

	public function test_module_pages_are_sorted_and_invalid_ones_dropped(): void {
		add_filter(
			'ttleague_tk_pages',
			static fn( array $pages ): array => array_merge(
				$pages,
				array(
					array(
						'slug'     => 'ttleague-tk-cup',
						'title'    => 'Cup',
						'render'   => '__return_null',
						'position' => 40,
					),
					array(
						'slug'     => 'ttleague-tk-meldung',
						'title'    => 'Meldungen',
						'render'   => '__return_null',
						'position' => 10,
					),
					array(
						'slug'   => 'ttleague-tk-anlaesse',
						'title'  => 'Anlässe',
						'render' => '__return_null',
					),
					array(
						'slug'   => 'ttleague-tk-cup',
						'title'  => 'Cup doppelt',
						'render' => '__return_null',
					),
					array(
						'slug'   => Tk::ACCOUNTS_PAGE,
						'title'  => 'Überschreibt Konten',
						'render' => '__return_null',
					),
					array(
						'slug'   => 'ttleague-tk-kaputt',
						'title'  => 'Ohne Ausgabe',
						'render' => 'gibt_es_nicht',
					),
					'kein Array',
				)
			)
		);

		$pages = Tk::pages();

		self::assertSame( array( 'ttleague-tk-meldung', 'ttleague-tk-cup', 'ttleague-tk-anlaesse' ), array_column( $pages, 'slug' ) );
		self::assertSame( array( 10, 40, 50 ), array_column( $pages, 'position' ) );
		self::assertSame( 'Cup', $pages[1]['title'], 'der erste Eintrag eines Slugs gilt' );
	}

	public function test_module_pages_appear_in_the_menu_before_accounts_and_need_the_tk_capability(): void {
		global $submenu;
		add_filter(
			'ttleague_tk_pages',
			static fn( array $pages ): array => array_merge(
				$pages,
				array(
					array(
						'slug'     => 'ttleague-tk-meldung',
						'title'    => 'Meldungen',
						'render'   => '__return_null',
						'position' => 10,
					),
					array(
						'slug'     => 'ttleague-tk-archiv',
						'title'    => 'Archiv',
						'render'   => '__return_null',
						'position' => 95,
					),
				)
			)
		);
		wp_set_current_user( $this->account( 'ttl_tk', 'tk@example.invalid' ) );
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Menü für den Test neu aufbauen.

		Tk::menu();

		$entries = $submenu[ Tk::OVERVIEW_PAGE ] ?? array();
		self::assertSame( array( Tk::OVERVIEW_PAGE, 'ttleague-tk-meldung', Tk::ACCOUNTS_PAGE, 'ttleague-tk-archiv' ), array_column( $entries, 2 ) );
		self::assertSame( array( 'ttl_tk' ), array_values( array_unique( array_column( $entries, 1 ) ) ) );
		self::assertStringEndsWith( 'admin.php?page=ttleague-tk-meldung&club=12', Tk::page_url( 'ttleague-tk-meldung', array( 'club' => '12' ) ) );
	}

	public function test_overview_shows_module_sections_with_count_and_anchor(): void {
		( new Seeder( $this->db ) )->season( '2026-27', 'active' );
		add_filter(
			'ttleague_tk_overview_sections',
			static fn( array $sections ): array => array_merge(
				$sections,
				array(
					array(
						'id'    => 'ttl-verschiebungen',
						'title' => 'Verschiebungen',
						'count' => 2,
						'html'  => '<p class="ttl-test">Zwei Anträge</p>',
					),
					array(
						'id'    => '',
						'title' => 'Ohne Anker',
						'html'  => '<p>fehlt</p>',
					),
					array(
						'id'    => 'ttl-ohne-inhalt',
						'title' => 'Ohne Inhalt',
					),
					array(
						'id'    => 'ttl-verschiebungen',
						'title' => 'Anker doppelt',
						'html'  => '<p>doppelt</p>',
					),
					array(
						'id'    => DashboardPage::SECTION_IDS['disputed'],
						'title' => 'Anker einer festen Liste',
						'html'  => '<p>fest</p>',
					),
				)
			)
		);

		$sections = DashboardPage::module_sections();
		$html     = DashboardPage::html( Tk::dashboard()->overview(), null, $sections );

		self::assertSame( array( 'ttl-verschiebungen' ), array_column( $sections, 'id' ) );
		self::assertStringContainsString( '<a href="#ttl-verschiebungen">Verschiebungen <span class="count">(2)</span></a>', $html );
		self::assertStringContainsString( '<h2 id="ttl-verschiebungen">Verschiebungen <span class="count">(2)</span></h2><p class="ttl-test">Zwei Anträge</p>', $html );
		self::assertStringNotContainsString( 'Ohne Anker', $html );
		self::assertStringNotContainsString( 'Anker doppelt', $html );
		self::assertStringNotContainsString( 'Anker einer festen Liste', $html );
		self::assertSame( 1, substr_count( $html, 'id="' . DashboardPage::SECTION_IDS['disputed'] . '"' ) );
	}

	public function test_module_sections_stay_visible_without_an_active_season(): void {
		( new Seeder( $this->db ) )->season( '2027-28', 'planned' );
		add_filter(
			'ttleague_tk_overview_sections',
			static fn( array $sections ): array => array_merge(
				$sections,
				array(
					array(
						'id'    => 'ttl-meldungen',
						'title' => 'Meldungen zu prüfen',
						'count' => 3,
						'html'  => '<p class="ttl-test">Drei Meldungen</p>',
					),
				)
			)
		);

		$html = DashboardPage::html( Tk::dashboard()->overview(), null, DashboardPage::module_sections() );

		self::assertStringContainsString( 'Es gibt keine aktive Saison.', $html );
		self::assertStringContainsString( '<a href="#ttl-meldungen">Meldungen zu prüfen <span class="count">(3)</span></a>', $html );
		self::assertStringContainsString( '<h2 id="ttl-meldungen">Meldungen zu prüfen <span class="count">(3)</span></h2><p class="ttl-test">Drei Meldungen</p>', $html );
		self::assertStringNotContainsString( '<ul class="subsubsub ttl-tk-nav">', DashboardPage::html( Tk::dashboard()->overview(), null ), 'ohne Listen keine leere Sprungleiste' );
	}

	public function test_modules_add_notification_types_but_cannot_replace_core_types(): void {
		$core = EventTypes::labels();
		add_filter(
			'ttleague_notification_types',
			static fn( array $types ): array => $types + array(
				EventTypes::POSTPONEMENT   => 'Verschiebung einer Begegnung',
				EventTypes::MATCH_REMINDER => 'Überschrieben',
				'Zu Lang Und Falsch'       => 'Ungültiger Schlüssel',
				str_repeat( 'x', 33 )      => 'Passt nicht in die Spalte',
				'rl_callup'                => '',
				"rl_reminder\n"            => 'Zeilenumbruch am Ende',
			)
		);

		$labels = EventTypes::labels();

		self::assertSame( array_merge( array_keys( $core ), array( EventTypes::POSTPONEMENT ) ), array_keys( $labels ) );
		self::assertSame( $core[ EventTypes::MATCH_REMINDER ], $labels[ EventTypes::MATCH_REMINDER ] );
		self::assertSame( 'Verschiebung einer Begegnung', $labels[ EventTypes::POSTPONEMENT ] );
	}

	public function test_a_module_type_gets_the_default_preference_and_can_be_switched_off(): void {
		add_filter( 'ttleague_notification_types', static fn( array $types ): array => $types + array( EventTypes::RL_CALLUP => 'Aufgebot zum Ranglistenturnier' ) );
		$person = ( new Seeder( $this->db ) )->person( 'Muster', 'Hans' );
		$prefs  = new Preferences( $this->db );

		self::assertSame(
			array(
				'email' => true,
				'push'  => false,
			),
			$prefs->for_person( $person )[ EventTypes::RL_CALLUP ] ?? null
		);
		$prefs->set( $person, EventTypes::RL_CALLUP, false, true );
		self::assertFalse( $prefs->wants( $person, EventTypes::RL_CALLUP, 'email' ) );
		self::assertTrue( $prefs->wants( $person, EventTypes::RL_CALLUP, 'push' ) );
	}

	public function test_reserved_types_fit_the_preference_column(): void {
		$reflection = new \ReflectionClass( EventTypes::class );
		foreach ( $reflection->getConstants() as $type ) {
			self::assertMatchesRegularExpression( '/^[a-z][a-z0-9_]{0,31}$/D', $type );
		}
	}

	public function test_modules_register_when_their_class_exists(): void {
		self::assertContains( 'TTLeague\\Postponement\\Postponements', Plugin::MODULES );
		foreach ( Plugin::MODULES as $module ) {
			if ( class_exists( $module ) ) {
				self::assertTrue( method_exists( $module, 'register' ), $module . '::register()' );
			}
		}
	}
}
