<?php
/**
 * Backend «Spielbetrieb → Anlässe»: Seite, Formulare mit Nonce und ttl_tk, Teilnehmerliste.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Events;

use PHPUnit\Framework\Attributes\DataProvider;
use TTLeague\Events\AdminActions;
use TTLeague\Events\AdminPage;
use TTLeague\Events\Events;
use TTLeague\Events\Export;
use TTLeague\Export\Exports;
use TTLeague\Infrastructure\Flash;
use TTLeague\Tk\Tk;

/**
 * Daten siehe EventsTestCase.
 */
final class AdminTest extends EventsTestCase {

	/**
	 * Felder des Formulars «Anlass anlegen».
	 *
	 * @param array<string,string> $overrides Abweichende Felder.
	 * @return array<string,string>
	 */
	private function fields( int $event, array $overrides = array() ): array {
		return $overrides + array(
			'event'            => (string) $event,
			'season_id'        => (string) $this->league->id['season'],
			'type'             => 'training',
			'title'            => 'Training für Einsteiger',
			'starts_at'        => $this->local( 10 ),
			'ends_at'          => '',
			'location'         => 'Turnhalle Seefeld',
			'deadline'         => $this->local( 5 ),
			'max_participants' => '12',
			'post'             => '',
		);
	}

	/**
	 * @return array{type:string,message:string,details:list<string>}|null
	 */
	private function flash(): ?array {
		return Flash::take( Tk::FLASH, get_current_user_id() );
	}

	private function page(): string {
		ob_start();
		AdminPage::render();
		return (string) ob_get_clean();
	}

	public function test_the_page_is_listed_under_spielbetrieb_before_accounts(): void {
		$pages = array_column( Tk::pages(), null, 'slug' );

		self::assertArrayHasKey( AdminPage::SLUG, $pages );
		self::assertSame( 'Anlässe', $pages[ AdminPage::SLUG ]['title'] );
		self::assertLessThan( Tk::ACCOUNTS_POSITION, $pages[ AdminPage::SLUG ]['position'] );
		self::assertStringEndsWith( 'admin.php?page=ttleague-tk-anlaesse&anlass=7', AdminPage::url( 7 ) );
		foreach ( array( AdminActions::SAVE, AdminActions::STATUS, AdminActions::REGISTER, AdminActions::UNREGISTER ) as $action ) {
			self::assertNotFalse( has_action( 'admin_post_' . $action ), $action );
			self::assertFalse( has_action( 'admin_post_nopriv_' . $action ), $action . ' nur angemeldet' );
		}
	}

	public function test_the_page_needs_the_tk_capability(): void {
		$this->event();
		wp_set_current_user( $this->user['chef'] );

		$error = $this->die_of( array( AdminPage::class, 'render' ) );

		self::assertSame( 'Dazu fehlt dir die Berechtigung.', $error->getMessage() );
	}

	public function test_the_list_shows_counts_and_links(): void {
		$first  = $this->event( array( 'max_participants' => '1' ) );
		$second = $this->event(
			array(
				'title'    => 'Captains <Sitzung>',
				'type'     => 'meeting',
				'deadline' => '',
			)
		);
		$this->register_all( $first, array( 'h1', 'a1' ) );
		Events::events()->close( $second, $this->actor( 'tk' ) );
		wp_set_current_user( $this->user['tk'] );

		$html = $this->page();

		self::assertStringContainsString( '<a class="page-title-action" href="' . esc_url( AdminPage::new_url() ) . '">Anlass anlegen</a>', $html );
		self::assertStringContainsString( '<a href="' . esc_url( AdminPage::url( $first ) ) . '">Plauschturnier</a>', $html );
		self::assertStringContainsString( 'Captains &lt;Sitzung&gt;', $html );
		self::assertStringContainsString( '<td>Turnier</td>', $html );
		self::assertStringContainsString( '<td>Sitzung</td>', $html );
		self::assertStringContainsString( '<td>1 von 1</td><td>1</td>', $html, 'ein Teilnehmender von einem Platz, eine Person wartet' );
		self::assertStringContainsString( '>geschlossen</span>', $html );
		self::assertStringContainsString( '<td>0</td><td>0</td>', $html );
	}

	public function test_the_detail_page_lists_participants_with_club_local_time_and_note(): void {
		$event = $this->event( array( 'max_participants' => '1' ) );
		Events::registrations()->register( $event, $this->person['h1'], $this->actor( 'h1' ), 'Brauche <b>Schläger</b>' );
		$this->register_all( $event, array( 'captain' ) );
		$this->db->query( "UPDATE {$this->db->table( 'registration' )} SET created_at = %s WHERE person_id = %d", array( '2026-07-01 10:00:00', $this->person['h1'] ) );
		update_option( 'timezone_string', 'Europe/Zurich' );
		wp_set_current_user( $this->user['tk'] );
		$_GET['anlass'] = (string) $event;

		$html = $this->page();

		self::assertStringContainsString( 'Spielbetrieb: Anlass «Plauschturnier»', $html );
		self::assertStringContainsString( '<h2 id="ttl-teilnehmende">Teilnehmende <span class="count">(1)</span></h2>', $html );
		self::assertStringContainsString( '<h2 id="ttl-warteliste">Warteliste <span class="count">(1)</span></h2>', $html );
		self::assertStringContainsString( '<td class="column-primary"><strong>Spieler1 Seefelder</strong></td><td>Seefeld</td><td>Mi, 1.7.2026, 12:00</td><td>Brauche &lt;b&gt;Schläger&lt;/b&gt;</td>', $html, 'Zeitpunkt in Ortszeit, Bemerkung maskiert' );
		self::assertStringContainsString( '<strong>Hanna Heim</strong></td><td>Seefeld</td>', $html );
		self::assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( AdminActions::UNREGISTER . '_' . $event . '_' . $this->person['h1'] ) . '"', $html );
		self::assertStringContainsString( 'href="' . esc_url( Exports::url( Export::NAME, array( 'event' => (string) $event ) ) ) . '"', $html );
		self::assertStringContainsString( '[ttleague view="event-registration" event="' . $event . '"]', $html );
		self::assertSame( 1, preg_match( '/<select id="ttl-event-person" name="person" required>(.*?)<\/select>/s', $html, $select ) );
		self::assertStringContainsString( 'Spieler1 Wollishofer (Wollishofen)', $select[1] );
		self::assertStringNotContainsString( 'Spieler1 Seefelder', $select[1], 'schon angemeldet' );
		self::assertStringContainsString( 'name="title" value="Plauschturnier"', $html );
		self::assertStringContainsString( 'name="max_participants" value="1"', $html );
		self::assertStringContainsString( 'name="decision" value="close"', $html );
		self::assertStringContainsString( 'name="decision" value="cancel"', $html );
	}

	public function test_a_cancelled_event_offers_no_forms(): void {
		$event = $this->event();
		$this->register_all( $event, array( 'h1' ) );
		Events::events()->cancel( $event, $this->actor( 'tk' ) );
		wp_set_current_user( $this->user['tk'] );
		$_GET['anlass'] = (string) $event;

		$html = $this->page();

		self::assertStringContainsString( 'Der Anlass ist abgesagt.', $html );
		self::assertStringContainsString( 'Spieler1 Seefelder', $html, 'die Liste bleibt sichtbar' );
		self::assertStringNotContainsString( '<form', $html );
	}

	public function test_the_tk_creates_an_event_with_the_page_of_the_announcement(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Ausschreibung',
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( $this->user['tk'] );
		$this->post( AdminActions::SAVE . '_0', $this->fields( 0, array( 'post' => get_permalink( $page_id ) ) ) );

		$target = $this->redirect_of( array( AdminActions::class, 'save' ) );

		$events = Events::repository()->all();
		self::assertCount( 1, $events );
		self::assertSame( AdminPage::url( $events[0]['id'] ), $target );
		self::assertSame( 'Training für Einsteiger', $events[0]['title'] );
		self::assertSame( 'training', $events[0]['type'] );
		self::assertSame( 12, $events[0]['max_participants'] );
		self::assertSame( $page_id, $events[0]['post_id'] );
		self::assertSame( 'open', $events[0]['status'] );
		self::assertSame( $this->user['tk'], $events[0]['created_by'] );
		self::assertSame( 'Anlass «Training für Einsteiger» angelegt.', $this->flash()['message'] );
		self::assertSame( get_permalink( $page_id ), AdminPage::values( $events[0] )['post'], 'das Formular zeigt die Adresse' );
	}

	public function test_invalid_input_keeps_the_form_values(): void {
		wp_set_current_user( $this->user['tk'] );
		$this->post(
			AdminActions::SAVE . '_0',
			$this->fields(
				0,
				array(
					'title'    => 'Turnier mit "Fehler"',
					'ends_at'  => $this->local( 9 ),
					'deadline' => $this->local( 11 ),
				)
			)
		);

		$target = $this->redirect_of( array( AdminActions::class, 'save' ) );

		self::assertSame( AdminPage::new_url(), $target );
		self::assertSame( array(), Events::repository()->all() );
		$_GET['ansicht'] = 'neu';
		$html            = $this->page();
		self::assertStringContainsString( 'Die Eingabe enthält Fehler.', $html );
		self::assertStringContainsString( '<li>Das Ende liegt vor dem Beginn.</li><li>Der Anmeldeschluss liegt nach dem Beginn.</li>', $html );
		self::assertStringContainsString( 'name="title" value="Turnier mit &quot;Fehler&quot;"', $html, 'Eingabe bleibt erhalten' );
		self::assertStringNotContainsString( 'Turnier mit', $this->page(), 'nur einmal' );
	}

	public function test_an_unknown_announcement_is_rejected(): void {
		wp_set_current_user( $this->user['tk'] );
		$this->post( AdminActions::SAVE . '_0', $this->fields( 0, array( 'post' => 'http://example.org/gibt-es-nicht/' ) ) );

		$target = $this->redirect_of( array( AdminActions::class, 'save' ) );

		self::assertSame( AdminPage::new_url(), $target );
		self::assertSame( array(), Events::repository()->all() );
		self::assertStringStartsWith( 'Die Ausschreibung wurde nicht gefunden.', $this->flash()['message'] );
		self::assertNull( AdminActions::post_id( '999999' ) );
		self::assertSame( 0, AdminActions::post_id( ' ' ) );
	}

	public function test_updating_raises_the_limit_and_names_who_moved_up(): void {
		$event = $this->event( array( 'max_participants' => '1' ) );
		$this->register_all( $event, array( 'h1', 'a1' ) );
		wp_set_current_user( $this->user['tk'] );
		$this->post(
			AdminActions::SAVE . '_' . $event,
			$this->fields(
				$event,
				array(
					'title'            => 'Plauschturnier',
					'max_participants' => '2',
				)
			)
		);

		$target = $this->redirect_of( array( AdminActions::class, 'save' ) );

		self::assertSame( AdminPage::url( $event ), $target );
		self::assertSame(
			array(
				'h1' => 'registered',
				'a1' => 'registered',
			),
			$this->list_of( $event )
		);
		self::assertSame(
			array(
				'type'    => 'success',
				'message' => 'Änderungen gespeichert.',
				'details' => array( 'Von der Warteliste nachgerückt: Spieler1 Wollishofer' ),
			),
			$this->flash()
		);
		self::assertSame( array( 'a1@example.invalid' => array( 'Platz frei: Plauschturnier' ) ), $this->subjects() );
	}

	public function test_close_reopen_and_cancel_with_confirmation(): void {
		$event = $this->event( array( 'max_participants' => '1' ) );
		$this->register_all( $event, array( 'h1', 'a1' ) );
		wp_set_current_user( $this->user['tk'] );
		$status = function ( array $fields ) use ( $event ): string {
			$this->post( AdminActions::STATUS . '_' . $event, $fields + array( 'event' => (string) $event ) );
			$this->redirect_of( array( AdminActions::class, 'status' ) );
			return (string) $this->db->get_var( "SELECT status FROM {$this->db->table( 'event' )} WHERE id = %d", array( $event ) );
		};

		self::assertSame( 'closed', $status( array( 'decision' => 'close' ) ) );
		self::assertSame( 'open', $status( array( 'decision' => 'reopen' ) ) );
		self::assertSame( 'open', $status( array( 'decision' => 'cancel' ) ), 'ohne Häkchen keine Absage' );
		self::assertSame( 'Bitte bestätige die Absage mit dem Häkchen.', $this->flash()['message'] );
		self::assertSame( array(), $this->mails );
		self::assertSame( 'open', $status( array( 'decision' => 'löschen' ) ) );

		self::assertSame(
			'cancelled',
			$status(
				array(
					'decision' => 'cancel',
					'confirm'  => '1',
				)
			)
		);
		self::assertSame( 'Anlass abgesagt. Die Mitteilungen an 2 angemeldete Personen werden in den nächsten Minuten versendet.', $this->flash()['message'] );
		self::assertSame( array(), $this->mails, 'der Versand läuft getrennt über WP-Cron, siehe CancellationTest' );
		self::assertSame( 'cancelled', $status( array( 'decision' => 'reopen' ) ) );
		self::assertSame( 'error', $this->flash()['type'] );
	}

	public function test_the_tk_registers_and_unregisters_by_hand_after_the_deadline(): void {
		$event = $this->event(
			array(
				'deadline'         => $this->local( -1 ),
				'max_participants' => '1',
			)
		);
		wp_set_current_user( $this->user['tk'] );
		$register = function ( string $key ) use ( $event ): string {
			$this->post(
				AdminActions::REGISTER . '_' . $event,
				array(
					'event'  => (string) $event,
					'person' => (string) $this->person[ $key ],
					'note'   => 'telefonisch',
				)
			);
			return $this->redirect_of( array( AdminActions::class, 'register' ) );
		};

		$target = $register( 'h1' );
		self::assertSame( AdminPage::url( $event, 'ttl-person-' . $this->person['h1'] ), $target );
		self::assertSame( 'Spieler1 Seefelder ist angemeldet.', $this->flash()['message'] );
		$register( 'a1' );
		self::assertSame(
			array(
				'type'    => 'warning',
				'message' => 'Die Höchstzahl ist erreicht: Spieler1 Wollishofer steht auf der Warteliste.',
				'details' => array(),
			),
			$this->flash()
		);
		$register( 'a1' );
		self::assertSame( 'Diese Person steht bereits auf der Warteliste.', $this->flash()['message'] );
		self::assertStringContainsString( get_userdata( $this->user['tk'] )->display_name . ' hat dich für Plauschturnier', $this->mails[0]->text, 'TK-Konto ohne Person: Anzeigename des Kontos' );

		$this->mails = array();
		$this->post(
			AdminActions::UNREGISTER . '_' . $event . '_' . $this->person['h1'],
			array(
				'event'  => (string) $event,
				'person' => (string) $this->person['h1'],
			)
		);
		$this->redirect_of( array( AdminActions::class, 'unregister' ) );

		self::assertSame( array( 'a1' => 'registered' ), $this->list_of( $event ) );
		self::assertSame(
			array(
				'type'    => 'success',
				'message' => 'Spieler1 Seefelder ist abgemeldet.',
				'details' => array( 'Von der Warteliste nachgerückt: Spieler1 Wollishofer' ),
			),
			$this->flash()
		);
		self::assertSame(
			array(
				'a1@example.invalid' => array( 'Platz frei: Plauschturnier' ),
				'h1@example.invalid' => array( 'Abmeldung bestätigt: Plauschturnier' ),
			),
			$this->subjects()
		);
	}

	/**
	 * @return iterable<string,array{string}>
	 */
	public static function actions(): iterable {
		yield 'speichern' => array( 'save' );
		yield 'Status' => array( 'status' );
		yield 'anmelden' => array( 'register' );
		yield 'abmelden' => array( 'unregister' );
	}

	#[DataProvider( 'actions' )]
	public function test_every_action_needs_the_tk_capability_and_its_nonce( string $method ): void {
		$event = $this->event();
		$this->register_all( $event, array( 'h1' ) );
		$before = Events::repository()->find( $event );
		$nonce  = array(
			'save'       => AdminActions::SAVE . '_' . $event,
			'status'     => AdminActions::STATUS . '_' . $event,
			'register'   => AdminActions::REGISTER . '_' . $event,
			'unregister' => AdminActions::UNREGISTER . '_' . $event . '_' . $this->person['h1'],
		)[ $method ];
		$fields = $this->fields(
			$event,
			array(
				'title'    => 'Übernommen',
				'decision' => 'cancel',
				'confirm'  => '1',
				'person'   => (string) $this->person[ 'register' === $method ? 'a1' : 'h1' ],
			)
		);

		// TK-Chefin mit gültigem Nonce: Ohne die Prüfung von ttl_tk ginge die Aktion durch.
		wp_set_current_user( $this->user['chef'] );
		$this->post( $nonce, $fields );
		$forbidden = $this->die_of( array( AdminActions::class, $method ) );
		// TK mit dem Nonce einer anderen Aktion.
		wp_set_current_user( $this->user['tk'] );
		$this->post( 'ttleague_event_andere_' . $event, $fields );
		$this->die_of( array( AdminActions::class, $method ) );

		self::assertSame( 'Dazu fehlt dir die Berechtigung.', $forbidden->getMessage() );
		self::assertSame( $before, Events::repository()->find( $event ) );
		self::assertSame( array( 'h1' => 'registered' ), $this->list_of( $event ) );
		self::assertSame( array(), $this->mails );
	}
}
