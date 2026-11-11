<?php
/**
 * Seite «Spielbetrieb → Saison» und ihre Formulare, mit echtem WordPress.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Season;

use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Domain\Season\RulesInput;
use TTLeague\Infrastructure\Cache\DataVersion;
use TTLeague\Infrastructure\Flash;
use TTLeague\Notifications\EventTypes;
use TTLeague\Registration\Registration;
use TTLeague\Season\SeasonActions;
use TTLeague\Season\SeasonAdmin;
use TTLeague\Season\SeasonPage;
use TTLeague\Season\SeasonRepository;
use TTLeague\Tk\DashboardPage;
use TTLeague\Tk\Tk;

/**
 * Daten siehe SeasonWpTestCase.
 */
final class SeasonPageTest extends SeasonWpTestCase {

	private function page( int $step = 0, int $fixture = 0 ): string {
		return SeasonPage::html( $step, $fixture, Flash::take( Tk::FLASH, get_current_user_id() ) );
	}

	/**
	 * @return array<string,string>
	 */
	private function rules_form( array $change = array() ): array {
		$form = array();
		foreach ( ( new SeasonRules() )->to_array() as $field => $value ) {
			$form[ $field ] = RulesInput::text( $field, $value );
		}
		unset( $form['set_points_enabled'] );
		return $change + $form;
	}

	public function test_the_page_is_high_up_in_the_menu_and_needs_the_capability_of_the_tk(): void {
		$pages = array_column( Tk::pages(), null, 'slug' );
		self::assertSame( 'Saison', $pages[ SeasonAdmin::PAGE ]['title'] ?? null );
		self::assertSame( SeasonAdmin::PAGE, Tk::pages()[0]['slug'], 'Die Seite steht vor allen anderen Modulseiten.' );
		self::assertLessThan( $pages[ Registration::PAGE ]['position'], $pages[ SeasonAdmin::PAGE ]['position'] );

		wp_set_current_user( $this->user['chef'] );
		try {
			SeasonPage::render();
			self::fail( 'wp_die() erwartet.' );
		} catch ( \WPDieException $e ) {
			self::assertStringContainsString( 'Dazu fehlt dir die Berechtigung', $e->getMessage() );
		}

		wp_set_current_user( $this->user['tk'] );
		ob_start();
		SeasonPage::render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'Spielbetrieb: Saison', $html );
		self::assertStringContainsString( 'Aktive Saison: 2026/27', $html );
	}

	public function test_all_seven_steps_are_tabs_with_their_state(): void {
		$html = $this->page();

		foreach ( array( '1. Saison anlegen', '2. Regeln', '3. Meldefenster', '4. Kategorien und Gruppen', '5. Spielplan', '6. Freigabe', '7. Abschluss' ) as $number => $title ) {
			self::assertStringContainsString( esc_url( SeasonPage::url( $number + 1 ) ), $html );
			self::assertStringContainsString( $title, $html );
		}
		self::assertStringContainsString( 'Es gibt noch keine geplante Saison.', $html );
		self::assertMatchesRegularExpression( '#class="nav-tab nav-tab-active" aria-current="page"[^>]*>.*?1\. Saison anlegen#s', $html, 'Ohne geplante Saison öffnet die Seite den ersten Schritt.' );
		self::assertStringContainsString( 'Es gibt keine geplante Saison.', $this->page( SeasonPage::RULES ) );
	}

	public function test_the_next_season_is_created_with_one_button(): void {
		$html = $this->page( SeasonPage::CREATE );
		self::assertStringContainsString( 'Saison 2027/28 anlegen', $html );
		self::assertStringContainsString( 'name="action" value="' . SeasonActions::CREATE . '"', $html );
		self::assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( SeasonActions::CREATE . '_0' ) . '"', $html );
		$version = DataVersion::get();

		$target = $this->post( SeasonActions::CREATE, array(), 0 );

		self::assertSame( SeasonPage::url( SeasonPage::CREATE ), $target );
		$planned = ( new SeasonRepository( $this->db ) )->planned();
		self::assertSame( '2027/28', $planned['name'] ?? null );
		self::assertGreaterThan( $version, DataVersion::get() );
		$html = $this->page( SeasonPage::CREATE );
		self::assertStringContainsString( 'Die Saison 2027/28 ist angelegt.', $html );
		self::assertStringContainsString( 'TTC Seefeld', $html );
		self::assertStringContainsString( 'Geplante Saison: 2027/28', $html );
		self::assertStringContainsString( 'name="action" value="' . SeasonActions::DELETE . '"', $html, 'Die leere Saison lässt sich löschen.' );

		$this->post( SeasonActions::CREATE, array(), 0 );
		self::assertStringContainsString( 'Es gibt schon eine geplante Saison (2027/28)', $this->page( SeasonPage::CREATE ) );
		self::assertSame( 1, $this->rows( 'season', "status = 'planned'" ) );
	}

	public function test_a_fresh_installation_enters_name_and_dates_with_errors_at_the_field(): void {
		$this->db->query( "DELETE FROM {$this->db->table( 'season' )}" );
		$html = $this->page( SeasonPage::CREATE );
		self::assertStringContainsString( 'name="name"', $html );
		self::assertStringContainsString( 'name="start_date"', $html );

		$this->post(
			SeasonActions::CREATE,
			array(
				'name'       => 'Saison "27" & mehr',
				'start_date' => '2027-08-01',
				'end_date'   => '2028-07-31',
			),
			0
		);

		self::assertSame( 0, $this->rows( 'season' ) );
		$html = $this->page( SeasonPage::CREATE );
		self::assertStringContainsString( 'value="Saison &quot;27&quot; &amp; mehr"', $html, 'Die Eingabe bleibt stehen, escaped.' );
		self::assertMatchesRegularExpression( '#<tr class="form-invalid">.*?name="name".*?<span class="ttl-season-error"[^>]*>Der Name hat die Form «2027/28»#s', $html );
		self::assertStringNotContainsString( 'ttl-season-error', $this->page( SeasonPage::CREATE ), 'Die gemerkten Fehler erscheinen einmal.' );

		$this->post(
			SeasonActions::CREATE,
			array(
				'name'       => '2027/28',
				'start_date' => '2027-08-01',
				'end_date'   => '2028-07-31',
			),
			0
		);
		self::assertSame( 1, $this->rows( 'season', "status = 'planned' AND slug = '2027-28'" ) );
	}

	public function test_a_club_is_added_and_removed_and_the_empty_season_deleted(): void {
		$next = $this->plan();
		$this->db->query( "DELETE FROM {$this->db->table( 'team' )} WHERE season_id = {$next}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Ganzzahl des Tests.
		$this->db->update( 'club_season', array( 'status' => 'open' ), array( 'season_id' => $next ) );
		$new = $this->seed->club( 'Neu <b>dabei</b>', 7 );
		self::assertStringContainsString( 'Neu &lt;b&gt;dabei&lt;/b&gt;', $this->page( SeasonPage::CREATE ) );

		$fields = array(
			'season' => (string) $next,
			'club'   => (string) $new,
		);
		$this->post( SeasonActions::CLUB_ADD, $fields, $next );
		self::assertSame( 1, $this->rows( 'club_season', "season_id = {$next} AND club_id = {$new}" ) );
		$this->post( SeasonActions::CLUB_REMOVE, $fields, $next );
		self::assertSame( 0, $this->rows( 'club_season', "season_id = {$next} AND club_id = {$new}" ) );

		$this->post(
			SeasonActions::DELETE,
			array(
				'season'  => (string) $next,
				'confirm' => 'nein',
			),
			$next
		);
		self::assertSame( 'planned', $this->season_status( $next ) );
		self::assertStringContainsString( 'Bitte bestätige das Löschen mit dem Namen der Saison (2027/28).', $this->page( SeasonPage::CREATE ) );
		$this->post(
			SeasonActions::DELETE,
			array(
				'season'  => (string) $next,
				'confirm' => '2027/28',
			),
			$next
		);
		self::assertSame( 0, $this->rows( 'season', "id = {$next}" ) );
	}

	public function test_rules_are_saved_or_shown_with_errors_at_their_fields(): void {
		$next = $this->plan();
		$html = $this->page( SeasonPage::RULES );
		self::assertStringContainsString( 'name="rules[substitute_max]" value="2"', $html, 'Die übernommene Regel der aktiven Saison.' );
		self::assertStringContainsString( 'name="rules[objection_days]" value="7"', $html, 'Was nicht gespeichert ist, zeigt den Standard.' );
		self::assertStringContainsString( 'name="rules[rl_round_points]" value="6, 5, 4, 3, 2, 1"', $html );
		foreach ( array_keys( RulesInput::FIELDS ) as $field ) {
			self::assertStringContainsString( 'name="rules[' . $field . ']"', $html );
		}

		$target = $this->post(
			SeasonActions::RULES,
			array(
				'season' => (string) $next,
				'rules'  => $this->rules_form(
					array(
						'substitute_max' => '5',
						'sets_to_win'    => 'drei "Sätze"',
					)
				),
			),
			$next
		);

		self::assertSame( SeasonPage::url( SeasonPage::RULES ), $target );
		self::assertSame( array( 'substitute_max' => 2 ), ( new SeasonRepository( $this->db ) )->planned()['rules'] );
		$html = $this->page( SeasonPage::RULES );
		self::assertMatchesRegularExpression( '#<tr class="form-invalid"><th scope="row"><label for="ttl-rule-sets-to-win">.*?value="drei &quot;Sätze&quot;".*?<span class="ttl-season-error" id="ttl-rule-sets-to-win-error">Bitte gib eine ganze Zahl ein\.</span>#s', $html );
		self::assertStringContainsString( 'name="rules[substitute_max]" value="5"', $html, 'Auch die gültige Eingabe bleibt stehen.' );
		self::assertSame( 1, substr_count( $html, 'class="form-invalid"' ) );

		$this->post(
			SeasonActions::RULES,
			array(
				'season' => (string) $next,
				'rules'  => $this->rules_form(
					array(
						'substitute_max'     => '5',
						'set_points_enabled' => '1',
					)
				),
			),
			$next
		);
		$rules = ( new SeasonRepository( $this->db ) )->planned()['rules'];
		self::assertSame( 5, $rules['substitute_max'] );
		self::assertTrue( $rules['set_points_enabled'] );
		$html = $this->page( SeasonPage::RULES );
		self::assertStringContainsString( 'Die Regeln sind gespeichert.', $html );
		self::assertMatchesRegularExpression( '#name="rules\[set_points_enabled\]" value="1" checked#', $html );
	}

	public function test_the_window_is_set_and_the_state_of_each_club_listed(): void {
		$next = $this->plan();
		$this->db->update(
			'club_season',
			array( 'status' => 'submitted' ),
			array(
				'season_id' => $next,
				'club_id'   => $this->id['club_oerlikon'],
			)
		);
		$html = $this->page( SeasonPage::WINDOW );
		self::assertStringContainsString( 'Das Meldefenster ist noch nicht gesetzt.', $html );
		self::assertMatchesRegularExpression( '#<td>TTC Oerlikon</td><td>eingereicht</td><td>0</td><td>1</td><td>0</td>#', $html );
		self::assertMatchesRegularExpression( '#<td>TTC Seefeld</td><td>geprüft</td><td>0</td><td>2</td><td>0</td>#', $html );

		$fields = array(
			'season'                  => (string) $next,
			'registration_opens'      => '2027-06-01',
			'registration_closes'     => '2027-05-31',
			'late_registration_until' => '',
		);
		$this->post( SeasonActions::WINDOW, $fields, $next );
		$html = $this->page( SeasonPage::WINDOW );
		self::assertStringContainsString( 'Das Meldefenster darf nicht vor seinem Beginn enden.', $html );
		self::assertStringContainsString( 'name="registration_opens" value="2027-06-01"', $html );
		self::assertNull( ( new SeasonRepository( $this->db ) )->planned()['registration_opens'] );

		$this->post( SeasonActions::WINDOW, array( 'registration_opens' => '2027-05-01' ) + $fields, $next );
		$planned = ( new SeasonRepository( $this->db ) )->planned();
		self::assertSame( '2027-05-01', $planned['registration_opens'] );
		self::assertSame( '2027-05-31', $planned['registration_closes'] );
		self::assertStringContainsString( '3 von 4 Meldungen geprüft.', $this->page( SeasonPage::WINDOW ) );
	}

	public function test_groups_are_proposed_created_and_teams_assigned(): void {
		$next = $this->plan();
		$this->seed->team( $next, $this->id['club_altstetten'], 2, 'Altstetten <2>', null, $this->id['venue_altstetten'] );
		$html = $this->page( SeasonPage::GROUPS );
		self::assertStringContainsString( 'Seefeld 1 ist noch keiner Gruppe zugeteilt.', $html );
		self::assertStringContainsString( 'Altstetten &lt;2&gt;', $html );
		self::assertStringNotContainsString( 'Altstetten <2>', $html );
		self::assertStringContainsString( 'Gruppen und Einteilung aus 2026/27 vorschlagen', $html );
		self::assertStringContainsString( 'Schlussrangliste 2026/27', $html );
		self::assertStringContainsString( 'Kategorie B, Gruppe 1', $html, 'Der Vorschlag steht neben der Mannschaft.' );

		$fields = array( 'season' => (string) $next );
		self::assertSame( SeasonPage::url( SeasonPage::GROUPS ), $this->post( SeasonActions::PROPOSAL, $fields, $next ) );
		$html = $this->page( SeasonPage::GROUPS );
		self::assertStringContainsString( 'Vorschlag übernommen: 2 Gruppen angelegt, 6 Mannschaften zugeteilt.', $html );
		self::assertStringContainsString( 'Altstetten &lt;2&gt; ist noch keiner Gruppe zugeteilt.', $html );

		$this->post(
			SeasonActions::GROUP_CREATE,
			$fields + array(
				'category' => 'c',
				'group_no' => '1',
				'name'     => '',
			),
			$next
		);
		$groups = array_column( SeasonAdmin::grouping()->groups( $next ), 'id', 'name' );
		self::assertSame( array( 'Gruppe A', 'Gruppe B', 'Gruppe C' ), array_keys( $groups ) );
		$this->post(
			SeasonActions::GROUP_CREATE,
			$fields + array(
				'category' => 'C',
				'group_no' => '1',
				'name'     => 'Doppelt',
			),
			$next
		);
		$html = $this->page( SeasonPage::GROUPS );
		self::assertStringContainsString( 'Die Gruppe 1 der Kategorie C gibt es schon.', $html );
		self::assertStringContainsString( 'value="Doppelt"', $html );

		$this->post(
			SeasonActions::GROUP_RENAME,
			array(
				'group' => (string) $groups['Gruppe C'],
				'name'  => 'Gruppe C Nord',
			),
			$groups['Gruppe C']
		);
		$team = (int) $this->db->get_var( "SELECT id FROM {$this->db->table( 'team' )} WHERE season_id = %d AND number = 2 AND club_id = %d", array( $next, $this->id['club_altstetten'] ) );
		$this->post(
			SeasonActions::ASSIGN,
			$fields + array(
				'team' => array(
					$team                        => (string) $groups['Gruppe B'],
					$this->id['next_seefeld_2']  => (string) $groups['Gruppe A'],
					$this->id['next_oerlikon_1'] => '0',
				),
			),
			$next
		);
		$of = fn( int $id ): ?string => $this->db->get_var( "SELECT competition_id FROM {$this->db->table( 'team' )} WHERE id = %d", array( $id ) );
		self::assertSame( (string) $groups['Gruppe B'], $of( $team ) );
		self::assertSame( (string) $groups['Gruppe A'], $of( $this->id['next_seefeld_2'] ), 'Aufstieg von Hand.' );
		self::assertNull( $of( $this->id['next_oerlikon_1'] ) );
		self::assertStringContainsString( 'Geänderte Mannschaften: 3.', $this->page( SeasonPage::GROUPS ) );

		$this->post( SeasonActions::GROUP_DELETE, array( 'group' => (string) $groups['Gruppe C'] ), $groups['Gruppe C'] );
		self::assertSame( array( 'Gruppe A', 'Gruppe B' ), array_column( SeasonAdmin::grouping()->groups( $next ), 'name' ) );
	}

	public function test_cup_and_ranking_tournament_are_carried_over_from_the_page(): void {
		$this->seed->competition( $this->id['active'], 'CUP', 'Mannschaftscup A', 'A' );
		$this->seed->competition( $this->id['active'], 'RL', 'Ranglistenturnier' );
		$next = $this->plan();
		self::assertStringContainsString( 'Cup und Ranglistenturnier übernehmen (2)', $this->page( SeasonPage::GROUPS ) );

		$this->post( SeasonActions::COPY, array( 'season' => (string) $next ), $next );

		self::assertSame( 2, $this->rows( 'competition', "season_id = {$next} AND type IN ( 'CUP', 'RL' )" ) );
		$html = $this->page( SeasonPage::GROUPS );
		self::assertStringContainsString( 'Weitere Konkurrenzen der Saison: Mannschaftscup A, Ranglistenturnier.', $html );
		self::assertStringNotContainsString( 'Cup und Ranglistenturnier übernehmen (', $html );
	}

	public function test_weeks_are_chosen_and_the_draft_generated_from_the_page(): void {
		$next = $this->plan();
		SeasonAdmin::grouping()->apply_proposal( $this->tk(), $next );
		$this->db->update( 'team', array( 'home_venue_id' => null ), array( 'id' => $this->id['next_seefeld_1'] ) );
		$html = $this->page( SeasonPage::SCHEDULE );
		self::assertStringContainsString( 'Vorschlag, noch nicht gespeichert', $html );
		self::assertStringContainsString( 'name="week[2027-09-06]" value="1" checked', $html );
		self::assertStringContainsString( 'name="week[2028-01-10]" value="2" checked', $html );
		self::assertStringContainsString( 'Seefeld 1: Das Heimlokal fehlt.', $html );
		self::assertStringContainsString( 'Die Spielwochen der Vorrunde sind noch nicht gewählt.', $html );
		self::assertStringNotContainsString( 'name="action" value="' . SeasonActions::GENERATE . '"', $html, 'Ohne vollständige Angaben kein Knopf.' );

		$fields = array( 'season' => (string) $next );
		$this->post( SeasonActions::GENERATE, $fields, $next );
		self::assertSame( 0, $this->rows( 'fixture' ) );
		$html = $this->page( SeasonPage::SCHEDULE );
		self::assertStringContainsString( 'Der Entwurf lässt sich noch nicht erzeugen: Es fehlen Angaben.', $html );

		$this->db->update( 'team', array( 'home_venue_id' => $this->id['venue_seefeld'] ), array( 'id' => $this->id['next_seefeld_1'] ) );
		$weeks = array(
			'2027-09-06' => '1',
			'2027-09-13' => '1',
			'2027-08-02' => '0',
			'2028-01-10' => '2',
			'2028-01-17' => '2',
		);
		self::assertSame( SeasonPage::url( SeasonPage::SCHEDULE ), $this->post( SeasonActions::WEEKS, $fields + array( 'week' => $weeks ), $next ) );
		self::assertSame(
			array(
				'first_leg_weeks'  => array( '2027-09-06', '2027-09-13' ),
				'second_leg_weeks' => array( '2028-01-10', '2028-01-17' ),
			),
			( new SeasonRepository( $this->db ) )->planned()['schedule']
		);
		$html = $this->page( SeasonPage::SCHEDULE );
		self::assertStringContainsString( 'Spielwochen gespeichert: 2 in der Vorrunde, 2 in der Rückrunde.', $html );
		self::assertStringContainsString( 'name="week[2027-09-20]" value="0" checked', $html );

		$this->post( SeasonActions::GENERATE, $fields, $next );
		self::assertSame( 0, $this->rows( 'fixture' ) );
		self::assertStringContainsString( 'Zu wenige Spielwochen für die Anzahl Runden: Gruppe A braucht in der Vorrunde 3, vorhanden sind 2', $this->page( SeasonPage::SCHEDULE ) );

		$weeks += array(
			'2027-09-20' => '1',
			'2028-01-24' => '2',
		);
		$this->post( SeasonActions::WEEKS, $fields + array( 'week' => $weeks ), $next );
		$this->post( SeasonActions::GENERATE, $fields, $next );
		self::assertSame( 12, $this->rows( 'fixture' ) );
		$html = $this->page( SeasonPage::SCHEDULE );
		self::assertStringContainsString( 'Entwurf erzeugt: 12 Begegnungen. Harte Kollisionen im Plan: 0', $html );
		self::assertStringContainsString( '6 von 6', $html );
		self::assertStringContainsString( 'Wollishofen 1 – Seefeld 1', $html );

		$group = (int) $this->db->get_var( "SELECT id FROM {$this->db->table( 'competition' )} WHERE season_id = %d AND category = 'A'", array( $next ) );
		$this->post( SeasonActions::CLEAR, $fields + array( 'group' => (string) $group ), $next );
		self::assertSame( 6, $this->rows( 'fixture' ) );
		$this->post( SeasonActions::GENERATE, $fields + array( 'group' => (string) $group ), $next );
		self::assertSame( 12, $this->rows( 'fixture' ) );
		$this->post( SeasonActions::CLEAR, $fields, $next );
		self::assertSame( 0, $this->rows( 'fixture' ) );
	}

	public function test_the_overview_of_the_tk_lists_the_next_steps(): void {
		self::assertNotContains( SeasonPage::SECTION, array_column( DashboardPage::module_sections(), 'id' ), 'Ohne geplante Saison keine Liste.' );
		$this->plan();

		$sections = array_column( DashboardPage::module_sections(), null, 'id' );

		self::assertSame( 'Saison 2027/28: nächste Schritte', $sections[ SeasonPage::SECTION ]['title'] ?? null );
		self::assertSame( 4, $sections[ SeasonPage::SECTION ]['count'], 'Meldefenster, Gruppen, Spielplan und Freigabe sind offen.' );
		self::assertStringContainsString( '6 Mannschaften ohne Gruppe.', $sections[ SeasonPage::SECTION ]['html'] );
		self::assertStringContainsString( esc_url( SeasonPage::url( SeasonPage::GROUPS ) ), $sections[ SeasonPage::SECTION ]['html'] );

		wp_set_current_user( $this->user['chef'] );
		self::assertNotContains( SeasonPage::SECTION, array_column( DashboardPage::module_sections(), 'id' ) );
	}

	public function test_the_notification_type_appears_in_the_settings(): void {
		self::assertSame( 'Spielplan der neuen Saison freigegeben', EventTypes::labels()[ EventTypes::SEASON_PUBLISHED ] ?? null );
	}

	public function test_every_form_needs_its_nonce_and_the_capability_of_the_tk(): void {
		$next    = $this->ready();
		$fixture = $this->fixture( 'oerlikon_1', 'wollishofen_1' );
		$actions = ( new \ReflectionClass( SeasonActions::class ) )->getConstants();
		unset( $actions['HANDLERS'], $actions['MEMORY'] );
		self::assertCount( 23, $actions );
		$before = array(
			$this->db->get_results( "SELECT * FROM {$this->db->table( 'season' )} ORDER BY id" ),
			$this->db->get_results( "SELECT * FROM {$this->db->table( 'fixture' )} ORDER BY id" ),
			$this->db->get_results( "SELECT * FROM {$this->db->table( 'team' )} ORDER BY id" ),
			$this->db->get_results( "SELECT * FROM {$this->db->table( 'competition' )} ORDER BY id" ),
			$this->db->get_results( "SELECT * FROM {$this->db->table( 'club_season' )} ORDER BY id" ),
		);

		foreach ( $actions as $action ) {
			self::assertNotFalse( has_action( 'admin_post_' . $action ), $action . ' ist angemeldet.' );
			self::assertFalse( has_action( 'admin_post_nopriv_' . $action ), $action . ' gibt es nicht ohne Anmeldung.' );
			$fields = array(
				'season'  => (string) ( in_array( $action, array( SeasonActions::WITHDRAW, SeasonActions::CLOSE, SeasonActions::FIXTURE_ADD, SeasonActions::FIXTURE_CANCEL ), true ) ? $this->id['active'] : $next ),
				'group'   => (string) $this->id['next_group_a'],
				'fixture' => (string) $fixture,
				'team'    => (string) $this->id['seefeld_1'],
				'club'    => (string) $this->id['club_seefeld'],
				'confirm' => SeasonActions::CLOSE === $action ? '2026/27' : '2027/28',
				'reason'  => 'Test',
				'name'    => 'Neu',
				'date'    => '2027-09-30',
				'time'    => '20:00',
			);
			foreach ( array( $next, $this->id['active'], $this->id['next_group_a'], $fixture, 0 ) as $nonce_id ) {
				// Als TK-Chefin mit eigenem, gültigem Nonce: Das Recht fehlt.
				wp_set_current_user( $this->user['chef'] );
				$denied = $this->denied( $action, $fields, wp_create_nonce( $action . '_' . $nonce_id ) );
				self::assertNotSame( '', $denied->getMessage() );
			}
			// Als TK ohne gültigen Nonce.
			wp_set_current_user( $this->user['tk'] );
			$this->denied( $action, $fields, 'falsch' );
			$this->denied( $action, $fields, wp_create_nonce( 'ttleague_season_anderes_' . $next ) );
		}

		wp_set_current_user( $this->user['tk'] );
		self::assertSame(
			$before,
			array(
				$this->db->get_results( "SELECT * FROM {$this->db->table( 'season' )} ORDER BY id" ),
				$this->db->get_results( "SELECT * FROM {$this->db->table( 'fixture' )} ORDER BY id" ),
				$this->db->get_results( "SELECT * FROM {$this->db->table( 'team' )} ORDER BY id" ),
				$this->db->get_results( "SELECT * FROM {$this->db->table( 'competition' )} ORDER BY id" ),
				$this->db->get_results( "SELECT * FROM {$this->db->table( 'club_season' )} ORDER BY id" ),
			),
			'Keine abgewiesene Anfrage hat etwas geändert.'
		);
	}
}
