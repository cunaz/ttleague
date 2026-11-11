<?php
/**
 * Darstellung der Verschiebung: Erfassungsseite, «Mein Bereich», Matchreport, Spielplan,
 * Übersicht der TK, Benachrichtigungen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Postponement;

use TTLeague\Accounts\AccountService;
use TTLeague\Export\Exports;
use TTLeague\Frontend\Support\Format;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Notifications;
use TTLeague\Postponement\Forms;
use TTLeague\Postponement\Postponements;
use TTLeague\Results\EntryBlock;
use TTLeague\Results\Results;
use TTLeague\Tk\DashboardPage;

/**
 * Daten siehe PostponementTestCase.
 */
final class ViewsTest extends PostponementTestCase {

	private function entry_page( string $user, string $fixture = 'f1' ): string {
		$_GET[ EntryBlock::PARAM ] = (string) $this->league->id[ $fixture ];
		wp_set_current_user( $this->users[ $user ] ?? 0 );
		\TTLeague\Access\Capabilities::use_policy( null );
		return EntryBlock::render();
	}

	private function my_area( string $user ): string {
		( new AccountService( $this->db ) )->set_consent( $this->league->id[ $user ] );
		wp_set_current_user( $this->users[ $user ] );
		return do_blocks( '<!-- wp:ttleague/my-area /-->' );
	}

	private function report( int $user_id ): string {
		wp_set_current_user( $user_id );
		return do_blocks( '<!-- wp:ttleague/match-report {"fixture":' . $this->league->id['f1'] . '} /-->' );
	}

	public function test_entry_page_offers_to_reschedule_an_open_fixture(): void {
		$html = $this->entry_page( 'captain_h1' );

		self::assertStringContainsString( '<section class="ttl-section ttl-postponement" id="ttl-termin"', $html );
		self::assertStringContainsString( '<summary>Termin verschieben</summary>', $html );
		self::assertStringContainsString( 'name="action" value="' . Forms::REQUEST . '"', $html );
		self::assertStringContainsString( 'name="fixture" value="' . $this->league->id['f1'] . '"', $html );
		self::assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( Forms::REQUEST . '_' . $this->league->id['f1'] ) . '"', $html );
		self::assertMatchesRegularExpression( '/<input id="ttl-postponement-date-\d+" type="datetime-local" name="new_date" required min="\d{4}-\d\d-\d\dT\d\d:\d\d" max="' . preg_quote( wp_date( 'Y-m-d', time() + 300 * DAY_IN_SECONDS ), '/' ) . 'T23:59">/', $html );
		self::assertStringContainsString( 'Liegt der neue Termin mehr als 14 Tage nach dem bisherigen, entscheidet danach die TK.', $html );
		self::assertStringContainsString( 'Verschiebung beantragen', $html );
		self::assertLessThan( strpos( $html, 'ttl-e-form' ), strpos( $html, 'id="ttl-termin"' ), 'der Abschnitt steht unter dem Kopf, vor dem Formular' );

		// Auch die Gastseite, die bei einer angesetzten Begegnung sonst nichts tun kann.
		self::assertStringContainsString( '<summary>Termin verschieben</summary>', $this->entry_page( 'captain_a1' ) );
		// Die TK legt direkt fest.
		$tk = $this->entry_page( 'tk' );
		self::assertStringContainsString( 'Als TK legst du den Termin direkt fest.', $tk );
		self::assertStringContainsString( 'Termin festlegen', $tk );
	}

	public function test_entry_page_shows_nothing_without_a_role_or_after_the_result(): void {
		self::assertStringNotContainsString( 'ttl-postponement', $this->entry_page( 'nobody' ) );
		self::assertStringNotContainsString( 'ttl-postponement', $this->entry_page( '' ), 'abgemeldet' );

		$this->db->update(
			'fixture',
			array(
				'status'     => 'final',
				'home_score' => 6,
				'away_score' => 4,
			),
			array( 'id' => $this->league->id['f1'] )
		);
		self::assertStringNotContainsString( 'ttl-postponement', $this->entry_page( 'tk' ), 'mit Resultat kein Termin mehr' );
		self::assertStringNotContainsString( 'ttl-postponement', $this->entry_page( 'tk', 'old_fixture' ), 'Vorsaison' );
	}

	public function test_an_open_request_of_the_other_side_is_on_top_with_accept_and_decline(): void {
		$id = $this->request_as( 'captain_h1' )['id'];

		$away = $this->entry_page( 'captain_a1' );
		self::assertStringContainsString( 'Antrag auf Verschiebung: neuer Termin <strong>' . esc_html( Format::datetime( $this->day( 8 ) ) ) . '</strong> (bisher ' . esc_html( Format::datetime( $this->scheduled ) ) . ').', $away );
		self::assertStringContainsString( 'Gestellt von Hanna Heim, Seefeld 1.', $away );
		self::assertStringContainsString( 'Begründung: «Die Halle ist an diesem Abend belegt.»', $away );
		self::assertStringContainsString( 'Bitte stimme zu oder lehne ab. Mit deiner Zustimmung gilt der neue Termin.', $away );
		self::assertStringContainsString( 'name="response" value="accept"', $away );
		self::assertStringContainsString( '>Zustimmen</button>', $away );
		self::assertStringContainsString( '<summary>Ablehnen</summary>', $away );
		self::assertStringContainsString( 'name="response" value="decline"', $away );
		self::assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( Forms::RESPOND . '_' . $id ) . '"', $away );
		self::assertStringNotContainsString( 'name="response" value="withdraw"', $away );
		self::assertStringNotContainsString( 'Termin verschieben', $away, 'kein zweiter Antrag' );
		self::assertLessThan( strpos( $away, 'ttl-entry__state' ), strpos( $away, 'ttl-postponement__open' ), 'zuoberst, vor dem Stand der Erfassung' );
		self::assertSame( 0, preg_match( '/\sid="_wpnonce"/', $away ), 'keine doppelten IDs durch mehrere Formulare' );

		$home = $this->entry_page( 'captain_h1' );
		self::assertStringContainsString( 'Wartet auf die Antwort der Gegenseite.', $home );
		self::assertStringContainsString( 'name="response" value="withdraw"', $home );
		self::assertStringNotContainsString( 'name="response" value="accept"', $home );

		$tk = $this->entry_page( 'tk' );
		self::assertStringContainsString( 'An Stelle der Gegenseite gutheissen', $tk );
		self::assertStringContainsString( 'name="decision" value="approve"', $tk );
		self::assertStringContainsString( 'name="decision" value="reject"', $tk );
		self::assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( Forms::DECIDE . '_' . $id ) . '"', $tk );
	}

	public function test_texts_of_a_request_are_escaped(): void {
		wp_set_current_user( $this->users['captain_h1'] );
		Postponements::service()->request(
			$this->league->id['f1'],
			Results::current_actor(),
			array(
				'new_date' => $this->day( 8 ),
				'reason'   => '<script>alert(1)</script> & mehr',
			)
		);

		$html = $this->entry_page( 'captain_a1' );

		self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; &amp; mehr', $html );
		self::assertStringNotContainsString( '<script>alert(1)</script>', $html );
		self::assertStringNotContainsString( '<script>alert(1)</script>', $this->my_area( 'captain_a1' ) );
	}

	public function test_the_outcome_of_a_form_is_shown_once_and_earlier_requests_are_listed(): void {
		$id = $this->request_as( 'captain_h1' )['id'];
		wp_set_current_user( $this->users['captain_a1'] );
		Postponements::service()->decline( $id, Results::current_actor(), 'Da sind wir im Trainingslager.' );
		\TTLeague\Infrastructure\Flash::set( Forms::FLASH, $this->users['captain_a1'], 'success', 'Du hast den Antrag abgelehnt.' );

		$html = $this->entry_page( 'captain_a1' );
		self::assertStringContainsString( '<div class="ttl-notice ttl-notice--success" role="status"><p>Du hast den Antrag abgelehnt.</p></div>', $html );
		self::assertStringContainsString( 'Früherer Antrag (1)', $html );
		self::assertStringContainsString( 'von der Gegenseite abgelehnt', $html );
		self::assertStringContainsString( '«Da sind wir im Trainingslager.»', $html );
		self::assertStringContainsString( '<summary>Termin verschieben</summary>', $html, 'ein neuer Antrag ist möglich' );
		self::assertStringNotContainsString( 'Du hast den Antrag abgelehnt.', $this->entry_page( 'captain_a1' ), 'nur einmal' );
	}

	public function test_my_area_puts_requests_first_and_links_open_fixtures(): void {
		$id = $this->request_as( 'captain_h1' )['id'];

		$away = $this->my_area( 'captain_a1' );
		self::assertStringContainsString( 'id="ttl-termine"', $away );
		self::assertStringContainsString( 'Verschiebungen und Termine', $away );
		self::assertStringContainsString( 'name="response" value="accept"', $away );
		self::assertStringContainsString( 'name="postponement" value="' . $id . '"', $away );
		self::assertStringContainsString( 'name="back" value="' . Forms::BACK_AREA . '"', $away );
		self::assertLessThan( strpos( $away, 'ttl-my-games-title' ), strpos( $away, 'id="ttl-termine"' ), 'vor «Meine Spiele»' );
		// «Meine Spiele»: Link bei der offenen Begegnung.
		self::assertStringContainsString( '<a class="ttl-postponement__link" href="http://example.org/erfassen/?begegnung=' . $this->league->id['f1'] . '#ttl-termin">Termin verschieben</a>', $away );

		$home = $this->my_area( 'captain_h1' );
		self::assertStringContainsString( 'name="response" value="withdraw"', $home );
		self::assertStringNotContainsString( 'name="response" value="accept"', $home );

		// Ohne Funktion: weder Abschnitt noch Link.
		$nobody = $this->my_area( 'nobody' );
		self::assertStringNotContainsString( 'ttl-termine', $nobody );
		self::assertStringNotContainsString( 'Termin verschieben', $nobody );
	}

	public function test_my_area_without_open_requests_has_no_section_and_no_link_after_the_result(): void {
		$html = $this->my_area( 'captain_h1' );
		self::assertStringNotContainsString( 'id="ttl-termine"', $html );
		self::assertSame( 1, substr_count( $html, 'ttl-postponement__link' ), 'die angesetzte Begegnung unter «Demnächst»' );

		$this->db->update(
			'fixture',
			array(
				'status'     => 'provisional',
				'home_score' => 6,
				'away_score' => 4,
				'entered_by' => 1,
				'entered_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $this->league->id['f1'] )
		);
		\TTLeague\Access\Capabilities::use_policy( null );
		self::assertSame( 0, substr_count( $this->my_area( 'captain_a1' ), 'ttl-postponement__link' ), 'zu bestätigen: kein Termin mehr zu verschieben' );
	}

	public function test_my_area_lists_cup_fixtures_without_a_date(): void {
		$this->db->update( 'fixture', array( 'scheduled_at' => null ), array( 'id' => $this->league->id['cup'] ) );
		$this->db->insert(
			'cup_tie',
			array(
				'competition_id' => (int) $this->db->get_var( "SELECT competition_id FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $this->league->id['cup'] ) ),
				'round'          => 1,
				'slot'           => 1,
				'fixture_id'     => $this->league->id['cup'],
				'deadline'       => substr( $this->day( 30 ), 0, 10 ),
			)
		);

		$html = $this->my_area( 'chef_home' );
		self::assertStringContainsString( 'Ohne Termin', $html );
		self::assertStringContainsString( 'zu spielen bis ' . Format::date_plain( $this->day( 30 ) ), $html );
		self::assertStringContainsString( 'Termin vorschlagen', $html );

		$entry = $this->entry_page( 'chef_home', 'cup' );
		self::assertStringContainsString( '<details class="ttl-details ttl-postponement__request" open><summary>Termin vorschlagen</summary>', $entry );
		self::assertStringContainsString( 'Ein Termin danach braucht die Bewilligung der TK.', $entry );
		self::assertStringContainsString( 'Vorschlag senden', $entry );
		self::assertStringNotContainsString( ' required></textarea>', $entry, 'ohne Pflichtbegründung' );
	}

	public function test_match_report_links_to_rescheduling_only_for_people_with_the_right(): void {
		$guest = $this->report( 0 );
		self::assertStringNotContainsString( 'ttl-postponement', $guest );
		self::assertSame( $guest, $this->report( $this->users['nobody'] ), 'ohne Funktion: wie Gäste' );

		$link = 'href="http://example.org/erfassen/?begegnung=' . $this->league->id['f1'] . '#ttl-termin">';
		self::assertStringContainsString( $link . 'Termin verschieben</a>', $this->report( $this->users['captain_a1'] ), 'auch die Gastseite' );
		self::assertStringContainsString( $link . 'Termin verschieben</a>', $this->report( $this->users['captain_h1'] ) );

		$this->request_as( 'captain_h1' );
		self::assertStringContainsString( $link . 'Antrag auf neuen Termin beantworten</a>', $this->report( $this->users['captain_a1'] ) );
		self::assertStringContainsString( $link . 'Antrag auf neuen Termin ansehen</a>', $this->report( $this->users['captain_h1'] ) );
		self::assertSame( $guest, $this->report( 0 ), 'für Gäste unverändert' );
	}

	public function test_match_report_and_schedules_show_the_new_date_marked_as_postponed(): void {
		$team  = '<!-- wp:ttleague/team {"season":"2026-27","team":"seefeld-1"} /-->';
		$old   = Format::date( $this->scheduled );
		$new   = Format::date( $this->day( 8 ) );
		$badge = '<span class="ttl-badge ttl-badge--muted">verschoben</span>';
		wp_set_current_user( 0 );
		self::assertStringContainsString( esc_html( Format::datetime( $this->scheduled ) ), $this->report( 0 ) );
		self::assertStringNotContainsString( 'verschoben', $this->report( 0 ) );
		self::assertStringNotContainsString( 'verschoben', do_blocks( $team ) );

		// Ein offener Antrag ändert die öffentliche Darstellung nicht.
		$id = $this->request_as( 'captain_h1' )['id'];
		self::assertStringNotContainsString( 'verschoben', $this->report( 0 ) );

		wp_set_current_user( $this->users['captain_a1'] );
		Postponements::service()->accept( $id, Results::current_actor() );

		// Ohne Leeren des Caches: Die Datenversion ist erhöht, die Ansichten lesen neu.
		$report = $this->report( 0 );
		self::assertStringContainsString( esc_html( Format::datetime( $this->day( 8 ) ) ), $report );
		self::assertStringNotContainsString( esc_html( Format::datetime( $this->scheduled ) ), $report );
		self::assertStringContainsString( $badge, $report );

		$schedule = do_blocks( $team );
		self::assertStringContainsString( esc_html( $new ), $schedule );
		self::assertStringNotContainsString( esc_html( $old ), $schedule );
		self::assertStringContainsString( $badge, $schedule );

		$upcoming = do_blocks( '<!-- wp:ttleague/upcoming-fixtures {"season":"2026-27"} /-->' );
		self::assertStringContainsString( esc_html( Format::datetime( $this->day( 8 ) ) ), $upcoming );
		self::assertStringContainsString( 'verschoben', $upcoming );

		// Verlauf der Erfassungsseite.
		$entry = $this->entry_page( 'captain_h1' );
		self::assertStringContainsString( '<strong>Termin verschoben</strong> · Gustav Gast', $entry );
		self::assertStringContainsString( 'Früherer Antrag (1)', $entry );
		self::assertStringContainsString( '<summary>Termin verschieben</summary>', $entry, 'eine verschobene Begegnung lässt sich erneut verschieben' );
		self::assertStringContainsString( 'ttl-e-form', $entry, 'und normal erfassen' );
	}

	public function test_tk_overview_lists_approvals_and_unanswered_requests(): void {
		wp_set_current_user( $this->users['tk'] );
		$section = $this->tk_section();
		self::assertSame( 'Verschiebungen', $section['title'] );
		self::assertSame( 0, $section['count'] );
		self::assertStringContainsString( 'Keine offenen Verschiebungen.', $section['html'] );

		// Neuer Antrag: noch nicht bei der TK. Nach sieben Tagen ohne Antwort schon.
		$waiting = $this->request_as( 'captain_h1' )['id'];
		wp_set_current_user( $this->users['tk'] );
		self::assertSame( 0, $this->tk_section()['count'] );
		$this->db->update( 'postponement', array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS - 60 ) ), array( 'id' => $waiting ) );
		$section = $this->tk_section();
		self::assertSame( 1, $section['count'] );
		self::assertStringContainsString( 'unbeantwortet', $section['html'] );
		self::assertStringContainsString( '<strong>Seefeld 1 – Wollishofen 1</strong>', $section['html'] );
		self::assertStringContainsString( '«Die Halle ist an diesem Abend belegt.»', $section['html'] );
		self::assertStringContainsString( 'Hanna Heim, Seefeld 1', $section['html'] );
		self::assertStringContainsString( 'An Stelle der Gegenseite gutheissen', $section['html'] );
		self::assertStringContainsString( 'name="action" value="' . Forms::DECIDE . '"', $section['html'] );
		self::assertStringContainsString( 'name="back" value="' . Forms::BACK_TK . '"', $section['html'] );
		self::assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( Forms::DECIDE . '_' . $waiting ) . '"', $section['html'] );
		self::assertMatchesRegularExpression( '/name="decision" value="reject">.*?<textarea id="ttl-postponement-reject-tk-' . $waiting . '" name="comment" rows="2" maxlength="2000" required>/s', $section['html'] );

		// Zugestimmt mit Bewilligung: sofort bei der TK.
		wp_set_current_user( $this->users['captain_a1'] );
		Postponements::service()->decline( $waiting, Results::current_actor() );
		$accepted = $this->request_as( 'captain_h1', 25 )['id'];
		wp_set_current_user( $this->users['captain_a1'] );
		Postponements::service()->accept( $accepted, Results::current_actor() );
		wp_set_current_user( $this->users['tk'] );
		$section = $this->tk_section();
		self::assertSame( 1, $section['count'] );
		self::assertStringContainsString( 'zu bewilligen', $section['html'] );
		self::assertStringContainsString( 'Bewilligen …', $section['html'] );

		// Die ganze Seite mit der Liste der Module.
		$page = DashboardPage::html( \TTLeague\Tk\Tk::dashboard()->overview(), null, DashboardPage::module_sections() );
		self::assertStringContainsString( '<h2 id="ttl-verschiebungen">Verschiebungen <span class="count">(1)</span></h2>', $page );
		self::assertStringContainsString( '<a href="#ttl-verschiebungen">Verschiebungen <span class="count">(1)</span></a>', $page );

		// Ohne TK-Recht liefert der Filter nichts.
		wp_set_current_user( $this->users['captain_h1'] );
		self::assertSame( array(), DashboardPage::module_sections() );
	}

	public function test_notification_types_are_registered_and_the_reminder_runs_with_the_hourly_deadlines(): void {
		$labels = EventTypes::labels();
		self::assertArrayHasKey( EventTypes::POSTPONEMENT, $labels );
		self::assertArrayHasKey( EventTypes::POSTPONEMENT_TK, $labels );
		self::assertNotFalse( has_action( Notifications::CRON_HOOK, array( Postponements::class, 'run_reminders' ) ) );
		self::assertNotFalse( has_action( Notifications::CRON_HOOK, array( Notifications::class, 'run_deadlines' ) ), 'derselbe stündliche Lauf' );
		self::assertFalse( wp_next_scheduled( 'ttleague_postponement_reminders' ), 'kein eigener Cron' );

		// Einstellungen in «Mein Bereich».
		$html = $this->my_area( 'captain_h1' );
		self::assertStringContainsString( 'name="notify_email[' . EventTypes::POSTPONEMENT . ']"', $html );
		self::assertStringContainsString( 'Verschiebung oder Terminvorschlag: Antrag, Antwort, Entscheid', $html );
	}

	public function test_a_request_sends_a_mail_to_the_other_side_and_the_reminder_follows_after_three_days(): void {
		$id = $this->request_as( 'captain_h1' )['id'];

		self::assertCount( 1, $this->mails, 'nur der Captain der Gegenseite hat eine Adresse' );
		self::assertSame( 'captain.a1@example.invalid', $this->mails[0]->to );
		self::assertStringContainsString( 'Verschiebung beantragt: Seefeld 1 – Wollishofen 1', $this->mails[0]->subject );
		self::assertSame( 'notification_postponement_request', $this->mails[0]->type );

		// Stündlicher Lauf: nach zwei Tagen nichts, nach drei Tagen die Erinnerung, einmal.
		add_filter(
			'ttleague_notification_hours',
			static fn(): array => array(
				'from_hour'   => 0,
				'until_hour'  => 24,
				'digest_hour' => 7,
			)
		);
		$this->mails = array();
		$this->db->update( 'postponement', array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ), array( 'id' => $id ) );
		Postponements::run_reminders();
		self::assertSame( array(), $this->mails );

		$this->db->update( 'postponement', array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS - 60 ) ), array( 'id' => $id ) );
		Postponements::run_reminders();
		Postponements::run_reminders();
		self::assertCount( 1, $this->mails );
		self::assertSame( 'captain.a1@example.invalid', $this->mails[0]->to );
		self::assertStringContainsString( 'Erinnerung: Verschiebung beantworten', $this->mails[0]->subject );
	}

	public function test_all_requests_of_a_season_are_exported_as_csv(): void {
		$id = $this->request_as( 'captain_h1' )['id'];
		wp_set_current_user( $this->users['captain_a1'] );
		Postponements::service()->decline( $id, Results::current_actor(), '=SUMME(A1)' );
		wp_set_current_user( $this->users['tk'] );

		self::assertArrayHasKey( Postponements::EXPORT, Exports::all() );
		$lines = explode( "\r\n", trim( (string) Exports::csv( Postponements::EXPORT ) ) );
		self::assertCount( 2, $lines );
		self::assertStringStartsWith( "\xEF\xBB\xBFBegegnung;Konkurrenz;Heim;Gast;Art;", $lines[0] );
		$cells = str_getcsv( $lines[1], ';', '"', '' );
		self::assertSame(
			array( (string) $this->league->id['f1'], 'Gruppe B1', 'Seefeld 1', 'Wollishofen 1', 'Verschiebung', $this->scheduled, $this->day( 8 ), 'von der Gegenseite abgelehnt', 'Hanna Heim' ),
			array_slice( $cells, 0, 9 )
		);
		self::assertSame( 'Die Halle ist an diesem Abend belegt.', $cells[10] );
		self::assertCount( 15, $cells );
		self::assertStringNotContainsString( ';=SUMME', $lines[1], 'Formeln sind entschärft' );

		self::assertSame( 1, substr_count( (string) Exports::csv( Postponements::EXPORT, array( 'saison' => '2025-26' ) ), "\r\n" ), 'Vorsaison: nur die Kopfzeile' );
		self::assertSame( 1, substr_count( (string) Exports::csv( Postponements::EXPORT, array( 'saison' => 'gibt-es-nicht' ) ), "\r\n" ) );
		self::assertStringContainsString( 'list=verschiebungen', $this->tk_section()['html'], 'Link in der Übersicht' );
	}

	/**
	 * Liste «Verschiebungen» aus dem Filter der TK-Übersicht.
	 *
	 * @return array{id:string,title:string,count:int,html:string}
	 */
	private function tk_section(): array {
		foreach ( DashboardPage::module_sections() as $section ) {
			if ( 'ttl-verschiebungen' === $section['id'] ) {
				return $section;
			}
		}
		self::fail( 'Liste «Verschiebungen» fehlt.' );
	}
}
