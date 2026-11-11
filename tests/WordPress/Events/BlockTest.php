<?php
/**
 * Block ttleague/event-registration und Shortcode-Ansicht für Besucher, Personen, TK-Chefs und TK.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Events;

use TTLeague\Events\AdminPage;
use TTLeague\Events\BlockActions;
use TTLeague\Events\Events;
use TTLeague\Events\RegistrationView;
use TTLeague\Frontend\Assets;
use TTLeague\Frontend\Support\Format;
use TTLeague\Infrastructure\Flash;

/**
 * Daten siehe EventsTestCase. Angemeldet sind h1 (Seefeld) und a1 (Wollishofen), bei
 * Höchstzahl 2 steht Hanna Heim auf der Warteliste.
 */
final class BlockTest extends EventsTestCase {

	private function render( int $event ): string {
		return do_blocks( '<!-- wp:ttleague/event-registration {"event":' . $event . '} /-->' );
	}

	private function full_event(): int {
		$event = $this->event( array( 'max_participants' => '2' ) );
		Events::registrations()->register( $event, $this->person['h1'], $this->actor( 'h1' ), 'Bringe <b>Bälle</b> mit' );
		$this->register_all( $event, array( 'a1', 'captain' ) );
		return $event;
	}

	public function test_block_is_registered_with_the_event_attribute(): void {
		$block = \WP_Block_Type_Registry::get_instance()->get_registered( 'ttleague/event-registration' );

		self::assertNotNull( $block );
		self::assertTrue( $block->is_dynamic() );
		self::assertSame( 'integer', $block->attributes['event']['type'] );
	}

	public function test_guests_see_dates_counts_and_the_login_link_but_no_names(): void {
		$event = $this->full_event();
		$data  = Events::repository()->find( $event );

		$html = $this->render( $event );

		self::assertStringContainsString( 'class="ttl ttl-event-registration wp-block-ttleague-event-registration"', $html );
		self::assertStringContainsString( 'id="ttl-anlass-' . $event . '"', $html );
		self::assertStringContainsString( 'Plauschturnier', $html );
		self::assertStringContainsString( '<dt>Termin</dt><dd><time datetime="' . str_replace( ' ', 'T', substr( $data['starts_at'], 0, 16 ) ) . '">' . Format::datetime( $data['starts_at'] ) . '</time></dd>', $html );
		self::assertStringContainsString( '<dt>Ort</dt><dd>Turnhalle Seefeld</dd>', $html );
		self::assertStringContainsString( '<dt>Anmeldeschluss</dt><dd>' . Format::datetime( $data['deadline'] ) . '</dd>', $html );
		self::assertStringContainsString( '<dt>Angemeldet</dt><dd>2 Personen, 1 auf der Warteliste</dd>', $html );
		self::assertStringContainsString( '<dt>Freie Plätze</dt><dd>0 von 2</dd>', $html );
		self::assertStringContainsString( 'href="' . esc_url( 'http://example.org/anmelden/?redirect_to=' . rawurlencode( 'http://example.org/' ) ) . '"', $html, 'Login führt zurück' );
		foreach ( array( 'Seefelder', 'Wollishofer', 'Heim', 'Bälle', '<form', 'Teilnehmende (' ) as $private ) {
			self::assertStringNotContainsString( $private, $html, $private );
		}
	}

	public function test_counts_without_a_limit_and_with_one_person(): void {
		$event = $this->event();
		$this->register_all( $event, array( 'h1' ) );

		$html = $this->render( $event );

		self::assertStringContainsString( '<dt>Angemeldet</dt><dd>1 Person</dd>', $html );
		self::assertStringNotContainsString( 'Freie Plätze', $html );
	}

	/**
	 * Ohne gesetzte Frist gilt der Beginn als Anmeldeschluss; das steht auch so da.
	 */
	public function test_without_a_deadline_the_start_is_shown_as_the_deadline(): void {
		$event = $this->event( array( 'deadline' => '' ) );
		$data  = Events::repository()->find( $event );
		self::assertNull( $data['deadline'] );

		$html = $this->render( $event );

		self::assertStringContainsString( '<dt>Anmeldeschluss</dt><dd>' . Format::datetime( $data['starts_at'] ) . '</dd>', $html );
	}

	public function test_a_person_without_function_gets_the_form_but_no_names(): void {
		$event = $this->full_event();
		wp_set_current_user( $this->user['nobody'] );

		$html = $this->render( $event );

		self::assertStringContainsString( 'action="http://example.org/wp-admin/admin-post.php"', $html );
		self::assertStringContainsString( 'name="action" value="' . BlockActions::REGISTER . '"', $html );
		self::assertStringContainsString( 'name="event" value="' . $event . '"', $html );
		self::assertStringContainsString( 'name="person" value="' . $this->person['nobody'] . '"', $html );
		self::assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( BlockActions::REGISTER . '_' . $event ) . '"', $html );
		self::assertStringContainsString( 'name="redirect_to" value="http://example.org/"', $html );
		self::assertStringContainsString( 'name="note" maxlength="200"', $html );
		self::assertStringContainsString( 'Die Höchstzahl ist erreicht. Deine Anmeldung kommt auf die Warteliste', $html );
		self::assertStringContainsString( '>Auf die Warteliste setzen</button>', $html );
		self::assertStringNotContainsString( 'anmelden/?redirect_to', $html, 'kein Login-Link für Angemeldete' );
		foreach ( array( 'Seefelder', 'Wollishofer', 'Hanna', 'Teilnehmende (', 'Spieler deines Clubs' ) as $private ) {
			self::assertStringNotContainsString( $private, $html, $private );
		}
	}

	public function test_a_registered_person_sees_her_state_and_can_unregister(): void {
		$event = $this->full_event();
		wp_set_current_user( $this->user['h1'] );

		$html = $this->render( $event );

		self::assertStringContainsString( '<strong>Du bist angemeldet.</strong>', $html );
		self::assertStringContainsString( 'Deine Bemerkung: Bringe &lt;b&gt;Bälle&lt;/b&gt; mit', $html, 'Bemerkung maskiert' );
		self::assertStringContainsString( 'name="action" value="' . BlockActions::UNREGISTER . '"', $html );
		self::assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( BlockActions::UNREGISTER . '_' . $event ) . '"', $html );
		self::assertStringNotContainsString( 'value="' . BlockActions::REGISTER . '"', $html );
		self::assertStringNotContainsString( 'Wollishofer', $html, 'ein Spieler ohne Funktion sieht keine Namen' );
	}

	public function test_a_captain_sees_the_names_and_her_place_on_the_waitlist(): void {
		$event = $this->full_event();
		Events::registrations()->register( $event, $this->person['nobody'], $this->actor( 'nobody' ) );
		wp_set_current_user( $this->user['captain'] );

		$html = $this->render( $event );

		self::assertStringContainsString( 'Du stehst auf der Warteliste (Platz 1).', $html );
		self::assertStringContainsString( '<h4>Teilnehmende (2)</h4><ol><li>Spieler1 Seefelder <span class="ttl-muted">(Seefeld)</span></li><li>Spieler1 Wollishofer <span class="ttl-muted">(Wollishofen)</span></li></ol>', $html );
		self::assertStringContainsString( '<h4>Warteliste (2)</h4><ol><li>Hanna Heim <span class="ttl-muted">(Seefeld)</span></li><li>Nina Niemand</li></ol>', $html );
		self::assertStringNotContainsString( 'Spieler deines Clubs', $html, 'Captains melden niemanden an' );
		self::assertStringNotContainsString( 'Bälle', $html, 'Bemerkungen anderer stehen nur im Backend' );

		wp_set_current_user( $this->user['nobody'] );
		self::assertStringContainsString( 'Du stehst auf der Warteliste (Platz 2).', $this->render( $event ) );
	}

	public function test_a_tk_chef_registers_and_unregisters_players_of_her_club(): void {
		$event = $this->full_event();
		wp_set_current_user( $this->user['chef'] );

		$html = $this->render( $event );

		self::assertStringContainsString( '<h4>Spieler deines Clubs</h4>', $html );
		self::assertSame( 1, preg_match( '/<select id="ttl-anlass-spieler-' . $event . '" name="person" required>(.*?)<\/select>/s', $html, $select ) );
		self::assertStringContainsString( '<option value="' . $this->league->id['person_h3'] . '">Spieler3 Seefelder</option>', $select[1] );
		self::assertStringContainsString( 'Spieler2 Seefelder', $select[1] );
		self::assertStringNotContainsString( 'Wollishofer', $select[1], 'keine Spieler anderer Clubs' );
		self::assertStringNotContainsString( 'Spieler1 Seefelder', $select[1], 'schon angemeldet' );
		self::assertSame( 1, preg_match( '/<ul class="ttl-event__players">(.*?)<\/ul>/s', $html, $players ) );
		self::assertStringContainsString( 'Spieler1 Seefelder', $players[1] );
		self::assertStringContainsString( 'name="person" value="' . $this->person['h1'] . '"', $players[1] );
		self::assertStringContainsString( 'aria-label="Spieler1 Seefelder abmelden"', $players[1] );
		self::assertStringNotContainsString( 'Wollishofer', $players[1] );
		self::assertStringContainsString( '<h4>Teilnehmende (2)</h4>', $html, 'TK-Chefs sehen die Namen' );
		self::assertStringContainsString( 'name="person" value="' . $this->person['chef'] . '"', $html, 'eigene Anmeldung' );
	}

	public function test_the_tk_sees_names_and_the_link_to_the_backend(): void {
		$event = $this->full_event();
		wp_set_current_user( $this->user['tk'] );

		$html = $this->render( $event );

		self::assertStringContainsString( '<h4>Teilnehmende (2)</h4>', $html );
		self::assertStringContainsString( 'href="' . esc_url( AdminPage::url( $event ) ) . '"', $html );
		self::assertStringContainsString( 'Dein Konto ist mit keiner Person verknüpft.', $html, 'TK-Konto ohne Person meldet im Backend an' );
		self::assertStringNotContainsString( '<form', $html );
	}

	public function test_an_account_without_person_cannot_register(): void {
		$event = $this->event();
		wp_set_current_user( $this->user['unlinked'] );

		$html = $this->render( $event );

		self::assertStringContainsString( 'Dein Konto ist mit keiner Person verknüpft.', $html );
		self::assertStringNotContainsString( '<form', $html );
		self::assertStringNotContainsString( 'Teilnehmende (', $html );
	}

	public function test_after_the_deadline_there_is_no_form(): void {
		$event = $this->event( array( 'deadline' => $this->local( -1 ) ) );
		$this->db->insert(
			'registration',
			array(
				'event_id'   => $event,
				'person_id'  => $this->person['h1'],
				'status'     => 'registered',
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
			)
		);

		$guest = $this->render( $event );
		wp_set_current_user( $this->user['nobody'] );
		$person = $this->render( $event );
		wp_set_current_user( $this->user['h1'] );
		$registered = $this->render( $event );
		wp_set_current_user( $this->user['chef'] );
		$chef = $this->render( $event );

		foreach ( array( $guest, $person, $registered, $chef ) as $html ) {
			self::assertStringContainsString( 'Der Anmeldeschluss ist vorbei. Änderungen nimmt die TK entgegen.', $html );
			self::assertStringNotContainsString( '<form', $html );
		}
		self::assertStringNotContainsString( 'anmelden/?redirect_to', $guest );
		self::assertStringContainsString( 'Du bist angemeldet.', $registered );
		self::assertStringNotContainsString( 'Spieler deines Clubs', $chef );
	}

	public function test_closed_and_cancelled_events(): void {
		$event = $this->event();
		$this->register_all( $event, array( 'h1' ) );
		Events::events()->close( $event, $this->actor( 'tk' ) );
		wp_set_current_user( $this->user['h1'] );

		$closed = $this->render( $event );
		Events::events()->cancel( $event, $this->actor( 'tk' ) );
		$cancelled = $this->render( $event );
		wp_set_current_user( 0 );
		$guest = $this->render( $event );

		self::assertStringContainsString( 'Die Anmeldung ist geschlossen. Änderungen nimmt die TK entgegen.', $closed );
		self::assertStringNotContainsString( '<form', $closed );
		self::assertStringContainsString( '<p class="ttl-notice ttl-notice--warning">Der Anlass ist abgesagt.</p>', $cancelled );
		self::assertStringNotContainsString( '<form', $cancelled );
		self::assertStringContainsString( 'Der Anlass ist abgesagt.', $guest );
		self::assertStringNotContainsString( 'anmelden/?redirect_to', $guest );
	}

	public function test_title_and_location_are_escaped(): void {
		$event = $this->event(
			array(
				'title'    => 'Turnier <script>alert(1)</script> & Fest',
				'location' => 'Halle "Nord" <i>',
			)
		);

		$html = $this->render( $event );

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( 'Turnier &lt;script&gt;alert(1)&lt;/script&gt; &amp; Fest', $html );
		self::assertStringContainsString( 'Halle &quot;Nord&quot; &lt;i&gt;', $html );
	}

	public function test_the_feedback_of_a_form_is_shown_once(): void {
		$event = $this->event();
		wp_set_current_user( $this->user['nobody'] );
		Flash::set( RegistrationView::FLASH . $event, $this->user['nobody'], 'error', 'Das hat <nicht> geklappt.' );

		$first  = $this->render( $event );
		$second = $this->render( $event );

		self::assertStringContainsString( '<div class="ttl-notice ttl-notice--error" role="alert"><p>Das hat &lt;nicht&gt; geklappt.</p></div>', $first );
		self::assertStringNotContainsString( 'geklappt', $second );
	}

	public function test_shortcode_renders_the_same_view(): void {
		$event = $this->full_event();

		$english = do_shortcode( '[ttleague view="event-registration" event="' . $event . '"]' );
		$german  = do_shortcode( '[ttleague view="anlass" event="' . $event . '"]' );

		self::assertStringContainsString( '<div class="ttl ttl-event-registration"><section class="ttl-section ttl-event" id="ttl-anlass-' . $event . '"', $english );
		self::assertStringContainsString( '<dt>Angemeldet</dt><dd>2 Personen, 1 auf der Warteliste</dd>', $english );
		self::assertSame( $english, $german );
	}

	public function test_an_unknown_event_is_empty_for_visitors_and_explained_to_editors(): void {
		self::assertSame( '<div class="ttl ttl-event-registration"></div>', do_shortcode( '[ttleague view="event-registration" event="99999"]' ) );
		self::assertStringNotContainsString( 'Anlass nicht gefunden', $this->render( 99999 ) );
		self::assertStringNotContainsString( 'Anlass nicht gefunden', do_blocks( '<!-- wp:ttleague/event-registration /-->' ) );

		wp_set_current_user( $this->user['tk'] );
		self::assertStringContainsString( 'Anlass nicht gefunden.', $this->render( 99999 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		self::assertStringContainsString( 'Anlass nicht gefunden.', do_blocks( '<!-- wp:ttleague/event-registration /-->' ) );
	}

	public function test_the_editor_preview_shows_the_public_part_only(): void {
		$event = $this->full_event();
		$model = RegistrationView::model( Events::repository()->find( $event ), $this->actor( 'tk' ), 'http://example.org/' );

		$html = RegistrationView::html( array( 'preview' => true ) + $model );

		self::assertStringContainsString( '<dt>Angemeldet</dt><dd>2 Personen, 1 auf der Warteliste</dd>', $html );
		self::assertStringContainsString( 'Angemeldete Personen sehen hier ihre An- und Abmeldung.', $html );
		self::assertStringNotContainsString( 'Seefelder', $html );
		self::assertStringNotContainsString( '<form', $html );
	}

	public function test_the_form_returns_to_the_page_with_the_block(): void {
		$event   = $this->event();
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Ausschreibung',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:ttleague/event-registration {"event":' . $event . '} /-->',
			)
		);
		wp_set_current_user( $this->user['nobody'] );
		$this->go_to( get_permalink( $page_id ) );

		$html = $this->render( $event );

		self::assertStringContainsString( 'name="redirect_to" value="' . esc_attr( get_permalink( $page_id ) ) . '"', $html );
	}

	public function test_the_editor_gets_the_list_of_events(): void {
		$event = $this->event();
		wp_register_script( Assets::EDITOR, 'http://example.org/editor.js', array(), '1', true );

		RegistrationView::editor_data();

		$inline = implode( "\n", (array) wp_scripts()->get_data( Assets::EDITOR, 'before' ) );
		self::assertStringContainsString( 'window.ttleagueEvents = [{"value":"' . $event . '"', $inline );
		self::assertStringContainsString( 'Plauschturnier', $inline );
	}
}
