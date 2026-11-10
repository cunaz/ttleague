<?php
/**
 * Erfassungsseite und «Meine Spiele» gegen MariaDB: abgemeldet, ohne Rolle, Heim, Gast, TK.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Results;

use TTLeague\Access\AccessPolicy;
use TTLeague\Domain\Match\GameOrder;
use TTLeague\Frontend\Support\Urls;
use TTLeague\Frontend\Views\MatchReportView;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\ReadModel\Cache\NullCache;
use TTLeague\ReadModel\ReadModel;
use TTLeague\Results\Actor;
use TTLeague\Results\EntryForm;
use TTLeague\Results\EntryPage;
use TTLeague\Results\MyGames;
use TTLeague\Results\ResultService;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;
use TTLeague\Tests\Unit\Results\EntryScript;

/**
 * Erfundene Clubs Seefeld (Teams 1 und «Seefeld <2> & Co») und Wollishofen in einer Gruppe der
 * Kategorie B. Heim ist «Seefeld <2> & Co» (prüft das Escaping); die Stammspieler von Seefeld 1
 * sind dort nicht spielberechtigt. Die Uhr steht auf Dienstag, 10. November 2026, 22:30 Ortszeit.
 */
final class EntryPageTest extends IntegrationTestCase {

	private const LINKS = array(
		'login'   => 'https://liga.example/anmelden/?redirect_to=https%3A%2F%2Fliga.example%2Ferfassen%2F',
		'my_area' => 'https://liga.example/mein-bereich/',
		'report'  => 'https://liga.example/begegnung/1/',
		'rest'    => 'https://liga.example/wp-json/ttleague/v1/fixtures/1/',
	);

	private ResultService $service;

	private EntryPage $page;

	/** @var array<string,int> */
	private array $id = array();

	/** @var array<string,Actor> */
	private array $actor = array();

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$s      = new Seeder( $this->db );
		$season = $s->season( '2026-27' );
		$home   = $s->club( 'Seefeld', 1 );
		$away   = $s->club( 'Wollishofen', 2 );
		$group  = $s->competition( $season, 'MM', 'Gruppe B1', 'B', 1 );
		$h1     = $s->team( $season, $home, 1, 'Seefeld 1', $group );
		$h2     = $s->team( $season, $home, 2, 'Seefeld <2> & Co', $group );
		$a1     = $s->team( $season, $away, 1, 'Wollishofen 1', $group );

		$players = array();
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $i => $key ) {
			$players[ $key ] = $s->player( 'Seefelder', 'Spieler' . ( $i + 1 ) );
			$s->register( $players[ $key ], $season, $home, 12 - $i );
		}
		foreach ( array( 'a1', 'a2', 'a3' ) as $i => $key ) {
			$players[ $key ] = $s->player( 'Wollishofer', 'Spieler' . ( $i + 1 ) );
			$s->register( $players[ $key ], $season, $away, 10 - $i );
		}
		$s->roster( $h1, array( $players['h1'], $players['h2'], $players['h3'] ) );
		$s->roster( $h2, array( $players['h4'], $players['h5'], $players['h6'] ) );
		$s->roster( $a1, array( $players['a1'], $players['a2'], $players['a3'] ) );

		$people = array(
			'home'   => $s->person( 'Heim', 'Hanna' ),
			'away'   => $s->person( 'Gast', 'Gustav' ),
			'nobody' => $s->person( 'Niemand', 'Nina' ),
		);
		$s->role( $season, $home, $people['home'], 'captain', $h2 );
		$s->role( $season, $away, $people['away'], 'captain', $a1 );
		$users = array(
			'home'   => 201,
			'away'   => 202,
			'nobody' => 203,
		);
		foreach ( $users as $key => $user_id ) {
			$this->db->update( 'person', array( 'user_id' => $user_id ), array( 'id' => $people[ $key ] ) );
			$this->actor[ $key ] = new Actor( $user_id, $people[ $key ], false );
		}
		$this->actor['tk']       = new Actor( 204, null, true, 'TK' );
		$this->actor['unlinked'] = new Actor( 205, null, false );

		$this->id = $players + array(
			'fixture' => $s->fixture(
				$group,
				array(
					'home_team_id' => $h2,
					'away_team_id' => $a1,
					'scheduled_at' => '2026-11-10 20:00:00',
				)
			),
		);

		$this->service = new ResultService(
			$this->db,
			new AccessPolicy( $this->db ),
			new FixedClock( '2026-11-10 21:30:00' ),
			new \DateTimeZone( 'Europe/Zurich' ),
			new AuditLog( $this->db ),
			new RecordingEvents(),
			static fn( int $id ): ?string => 204 === $id ? 'TK' : null
		);
		$this->page    = new EntryPage( $this->service, new \DateTimeZone( 'Europe/Zurich' ) );
	}

	/**
	 * @return array{html:string,state:array<string,mixed>|null,config:array<string,mixed>|null}
	 */
	private function build( string $actor, ?int $fixture = null ): array {
		return $this->page->build(
			$fixture ?? $this->id['fixture'],
			'' === $actor ? null : $this->actor[ $actor ],
			self::LINKS,
			static fn( int $fixture ): string => 'https://liga.example/wp-json/ttleague/v1/fixtures/' . $fixture . '/photo?_wpnonce=abc'
		);
	}

	/**
	 * Gültige Erfassung: Seefeld <2> & Co gewinnt 6:4.
	 *
	 * @return array<string,mixed>
	 */
	private function payload(): array {
		$results = array( '3:1', '3:0', '3:2', '1:3', '3:1', '0:3', '3:2', '2:3', '1:3', '3:0' );
		$games   = array();
		foreach ( GameOrder::team_match() as $i => $slot ) {
			[ $h, $a ] = array_map( 'intval', explode( ':', $results[ $i ] ) );
			$game      = array(
				'sequence'  => $slot['sequence'],
				'status'    => 'played',
				'sets_home' => $h,
				'sets_away' => $a,
			);
			if ( GameOrder::DOUBLE === $slot['type'] ) {
				$game['home_players'] = array( $this->id['h4'], $this->id['h5'] );
				$game['away_players'] = array( $this->id['a1'], $this->id['a2'] );
			}
			$games[] = $game;
		}
		return array(
			'lineup'  => array(
				'home' => array(
					'A' => $this->id['h4'],
					'B' => $this->id['h5'],
					'C' => $this->id['h6'],
				),
				'away' => array(
					'X' => $this->id['a1'],
					'Y' => $this->id['a2'],
					'Z' => $this->id['a3'],
				),
			),
			'games'   => $games,
			'comment' => 'Spielbericht <nachgetragen>.',
		);
	}

	public function test_logged_out_visitors_get_a_login_link_back(): void {
		$result = $this->build( '' );
		self::assertNull( $result['state'] );
		self::assertNull( $result['config'] );
		self::assertStringContainsString( 'href="https://liga.example/anmelden/?redirect_to=https%3A%2F%2Fliga.example%2Ferfassen%2F"', $result['html'] );
		self::assertStringContainsString( 'Bitte melde dich an.', $result['html'] );
		self::assertStringNotContainsString( 'data-wp-interactive', $result['html'] );
	}

	public function test_accounts_without_role_get_an_explanation(): void {
		$result = $this->build( 'nobody' );
		self::assertNull( $result['state'] );
		self::assertStringContainsString( 'Du kannst diese Begegnung nicht bearbeiten.', $result['html'] );
		self::assertStringContainsString( 'Resultate erfasst die Heimmannschaft', $result['html'] );
		self::assertStringContainsString( 'href="https://liga.example/begegnung/1/"', $result['html'] );
		self::assertStringNotContainsString( 'ttl-e-form', $result['html'] );

		self::assertStringContainsString( 'mit keiner Person verknüpft', $this->build( 'unlinked' )['html'] );
		self::assertStringContainsString( 'gibt es nicht', $this->build( 'home', 999999 )['html'] );
		self::assertStringContainsString( 'braucht die Nummer einer Begegnung', $this->build( 'home', 0 )['html'] );
	}

	public function test_home_side_gets_the_form_with_initial_state(): void {
		$result = $this->build( 'home' );
		$html   = $result['html'];

		self::assertStringContainsString( 'data-wp-interactive="ttleague/entry"', $html );
		self::assertStringContainsString( 'id="ttl-e-form"', $html );
		self::assertStringContainsString( 'Spielbericht eintragen', $html );
		self::assertSame( 6, substr_count( $html, 'class="ttl-entry__select"' ) );
		self::assertSame( 10, substr_count( $html, 'class="ttl-game ' ) + substr_count( $html, 'class="ttl-game"' ) );
		self::assertSame( 60, substr_count( $html, 'data-wp-on--click="actions.setScore"' ) );
		self::assertStringContainsString( 'id="ttl-e-game-7-pair-home"', $html, 'Doppelpaarung' );
		self::assertStringContainsString( 'capture="environment"', $html, 'Foto mit Kamera' );
		self::assertStringNotContainsString( 'ttl-entry__guest', $html );
		self::assertStringNotContainsString( 'ttl-entry__decision', $html );

		// Stammspieler von Seefeld 1 sind sichtbar, aber gesperrt, mit Grund.
		self::assertStringContainsString( '<option value="' . $this->id['h1'] . '" data-wp-bind--disabled="state.view.lockBlocked" disabled>Spieler1 Seefelder (B12) · gesperrt: Stammspieler eines anderen Teams (Art. 12.7)</option>', $html );
		self::assertStringContainsString( '3 Spieler nicht spielberechtigt', $html );
		self::assertMatchesRegularExpression( '#<optgroup label="Stammspieler"><option value="' . $this->id['h4'] . '"#', $html );

		// Escaping des Teamnamens.
		self::assertStringContainsString( 'Seefeld &lt;2&gt; &amp; Co', $html );
		self::assertStringNotContainsString( 'Seefeld <2>', $html );

		$state = $result['state'];
		self::assertSame( 'enter', $state['mode'] );
		self::assertSame( '', $state['form']['lineup']['home']['A'] );
		self::assertSame( '0:0', $state['view']['total'] );
		self::assertSame( EntryForm::view( EntryForm::data( $this->service->entry( $this->id['fixture'], $this->actor['home'] ) ), $state['form'] ), $state['view'] );
		self::assertInstanceOf( \stdClass::class, $state['errors'], 'leeres Objekt, nicht Liste' );

		$config = $result['config'];
		self::assertSame( 'https://liga.example/wp-json/ttleague/v1/fixtures/1/result', $config['urls']['result'] );
		self::assertSame( $this->service->entry( $this->id['fixture'], $this->actor['home'] )['version'], $config['version'] );
		self::assertSame( 'ttleague-entry-' . $this->id['fixture'] . '-201', $config['draftKey'] );
		self::assertSame( array(), $config['decisions'] );
		self::assertSame( $state['form'], $config['initialForm'] );
	}

	public function test_form_starts_with_the_lineup_of_the_last_match_and_says_so(): void {
		$hint  = 'Aufstellung aus dem letzten Spiel vorgeschlagen, bitte prüfen.';
		$plain = $this->build( 'home' )['html'];
		self::assertSame( 2, substr_count( $plain, '<p class="ttl-entry__suggested" data-wp-bind--hidden="!state.view.lineup.' ), 'pro Seite ein Hinweis' );
		self::assertSame( 2, substr_count( $plain, ' hidden>' . $hint . '</p>' ), 'ohne Vorschlag verborgen' );

		// Frühere Begegnung derselben Teams: Heim mit h6, h5, h4, Gast nur mit zwei Spielern.
		$fixture = $this->db->get_row( "SELECT competition_id, home_team_id, away_team_id FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $this->id['fixture'] ) );
		$s       = new Seeder( $this->db );
		$earlier = $s->fixture(
			(int) $fixture['competition_id'],
			array(
				'home_team_id' => (int) $fixture['home_team_id'],
				'away_team_id' => (int) $fixture['away_team_id'],
				'scheduled_at' => '2026-10-27 20:00:00',
			)
		);
		$s->team_match(
			$earlier,
			'2026-10-27 20:00:00',
			array(
				'A' => $this->id['h6'],
				'B' => $this->id['h5'],
				'C' => $this->id['h4'],
			),
			array(
				'X' => $this->id['a2'],
				'Z' => $this->id['a1'],
			),
			array( '3:1', 'wo:h', '3:2', '1:3', '3:1', 'wo:h', '3:2', 'wo:h', '1:3', 'wo:h' )
		);

		$result = $this->build( 'home' );
		$html   = $result['html'];
		self::assertSame( 2, substr_count( $html, '>' . $hint . '</p>' ) );
		self::assertSame( 0, substr_count( $html, ' hidden>' . $hint . '</p>' ), 'für beide Seiten sichtbar' );
		self::assertMatchesRegularExpression( '#<fieldset id="ttl-e-lineup-home"[^>]*><legend[^>]*>.*?</legend><p class="ttl-entry__suggested"#s', $html, 'gleich nach dem Namen der Mannschaft' );
		self::assertStringContainsString( '<option value="' . $this->id['h6'] . '" selected>', $html );
		self::assertSame(
			array(
				'A' => (string) $this->id['h6'],
				'B' => (string) $this->id['h5'],
				'C' => (string) $this->id['h4'],
			),
			$result['state']['form']['lineup']['home']
		);
		self::assertSame(
			array(
				'X' => (string) $this->id['a2'],
				'Y' => '',
				'Z' => (string) $this->id['a1'],
			),
			$result['state']['form']['lineup']['away']
		);
		self::assertSame( $result['state']['form'], $result['config']['initialForm'], 'Ausgangszustand, mit dem das Skript Entwürfe vergleicht' );
		self::assertSame( $result['state']['form']['lineup']['home'], $result['config']['suggestion']['home'] );
		self::assertSame( '0 von 10 erfasst', $result['state']['view']['progress'], 'Spiele bleiben offen' );

		// Die Gastseite sieht kein Formular und erhält keinen Vorschlag.
		$away = $this->build( 'away' );
		self::assertStringNotContainsString( 'ttl-entry__suggested', $away['html'] );
		self::assertSame( '', $away['state']['form']['lineup']['home']['A'] );

		// Mit erfasstem Resultat zeigt das Formular dieses, ohne Hinweis.
		$this->service->submit( $this->id['fixture'], $this->payload(), $this->actor['home'] );
		$saved = $this->build( 'home' );
		self::assertSame( (string) $this->id['h4'], $saved['state']['form']['lineup']['home']['A'] );
		self::assertSame( 2, substr_count( $saved['html'], ' hidden>' . $hint . '</p>' ) );
	}

	public function test_away_side_confirms_or_objects_after_entry(): void {
		$this->service->submit( $this->id['fixture'], $this->payload(), $this->actor['home'] );
		$this->service->attach_photo( $this->id['fixture'], $this->actor['home'], 77 );
		$result = $this->build( 'away' );
		$html   = $result['html'];

		self::assertStringContainsString( 'ttl-entry__guest', $html );
		self::assertStringContainsString( 'data-wp-on--click="actions.confirm"', $html );
		self::assertStringContainsString( 'id="ttl-e-reason"', $html );
		self::assertStringContainsString( 'Erfasstes Resultat', $html );
		self::assertStringContainsString( 'Total 6:4', $html );
		$url = 'https://liga.example/wp-json/ttleague/v1/fixtures/' . $this->id['fixture'] . '/photo?_wpnonce=abc';
		self::assertStringContainsString( 'href="' . esc_url( $url ) . '"', $html, 'geschützte Adresse statt Datei' );
		self::assertSame( $url, $result['state']['photo']['thumb'], 'Vorschau über dieselbe Adresse (keine Bildvarianten)' );
		self::assertStringNotContainsString( 'id="ttl-e-form"', $html, 'Gastseite erfasst nicht' );
		self::assertStringContainsString( 'Provisorisch erfasst am Di, 10.11.2026, 22:30 von Hanna Heim.', $html );
		self::assertStringContainsString( 'Resultat erfasst (6:4)', $html, 'Verlauf' );
		self::assertStringContainsString( 'Spielbericht &lt;nachgetragen&gt;.', $html, 'Bemerkung escaped' );
		self::assertSame( '', $result['state']['mode'] );
		self::assertTrue( $result['state']['view']['formHidden'] );
	}

	public function test_reading_view_offers_sharing_the_match_report(): void {
		self::assertStringNotContainsString( 'ttl-share', $this->build( 'home' )['html'], 'ohne Resultat kein Knopf' );

		$this->service->submit( $this->id['fixture'], $this->payload(), $this->actor['home'] );
		$html = $this->build( 'away' )['html'];

		self::assertSame( 1, substr_count( $html, 'data-ttl-share ' ) );
		self::assertStringContainsString( 'data-ttl-share-title="Seefeld &lt;2&gt; &amp; Co – Wollishofen 1 6:4"', $html );
		self::assertStringContainsString( 'data-ttl-share-url="https://liga.example/begegnung/1/"', $html, 'Adresse des Matchreports, nicht der Erfassungsseite' );
		self::assertMatchesRegularExpression( '#<section class="ttl-section ttl-entry__result"[^>]*>.*<div class="ttl-share"[^>]* hidden>.*</section>#s', $html, 'in der Leseansicht' );

		// Die Heimseite sieht das Formular; die Leseansicht samt Knopf ist verborgen.
		$home = $this->build( 'home' )['html'];
		self::assertMatchesRegularExpression( '#<section class="ttl-section ttl-entry__result"[^>]*data-wp-bind--hidden="!state.view.formHidden" hidden>#', $home );
	}

	public function test_tk_gets_the_decisions_of_the_status(): void {
		$scheduled = $this->build( 'tk' );
		self::assertSame( array( 'correct', 'forfeit' ), $scheduled['config']['decisions'] );

		$this->service->submit( $this->id['fixture'], $this->payload(), $this->actor['home'] );
		$result = $this->build( 'tk' );
		self::assertSame( array( 'confirm', 'finalize', 'correct', 'forfeit', 'reset' ), $result['config']['decisions'] );
		self::assertStringContainsString( 'Entscheid der TK', $result['html'] );
		self::assertStringContainsString( 'id="ttl-e-action-reset"', $result['html'] );
		self::assertStringContainsString( 'id="ttl-e-form"', $result['html'], 'Korrektur über das Formular' );
		self::assertStringNotContainsString( 'ttl-entry__guest', $result['html'], 'Bestätigen der TK läuft über den Entscheid' );

		$this->service->decide(
			$this->id['fixture'],
			$this->actor['tk'],
			array( 'action' => 'finalize' )
		);
		$final = $this->build( 'tk' );
		self::assertSame( array( 'correct', 'forfeit', 'reset' ), $final['config']['decisions'] );
		self::assertSame( '', $final['state']['mode'], 'Formular erst nach «Korrigieren»' );
		self::assertStringContainsString( 'Entscheid der TK: abgeschlossen', $final['html'] );
	}

	public function test_script_payload_is_accepted_by_the_service(): void {
		if ( ! EntryScript::available() ) {
			self::markTestSkipped( 'Node ist nicht verfügbar.' );
		}
		$entry = $this->service->entry( $this->id['fixture'], $this->actor['home'] );
		$data  = EntryForm::data( $entry );
		$form  = EntryForm::initial( $entry, $data );

		// C fehlt: Spiele 3, 6 und 9 bleiben offen und gehen als Vorschlag kampflos an den Gast.
		$form['lineup'] = array(
			'home' => array(
				'A' => (string) $this->id['h4'],
				'B' => (string) $this->id['h5'],
				'C' => EntryForm::MISSING,
			),
			'away' => array(
				'X' => (string) $this->id['a1'],
				'Y' => (string) $this->id['a2'],
				'Z' => (string) $this->id['a3'],
			),
		);
		foreach ( array(
			1  => '3:1',
			2  => '3:0',
			4  => '1:3',
			5  => '3:1',
			7  => '3:2',
			8  => '2:3',
			10 => '3:0',
		) as $seq => $result ) {
			[ $h, $a ]                            = array_map( 'intval', explode( ':', $result ) );
			$form['games'][ (string) $seq ]['st'] = 'played';
			$form['games'][ (string) $seq ]['sh'] = $h;
			$form['games'][ (string) $seq ]['sa'] = $a;
		}
		$form['games']['7']['ap'] = 'XZ';

		self::assertSame( array(), EntryScript::call( 'validate', array( $data, $form ) ) );
		$payload = EntryScript::call( 'payload', array( $data, $form, $entry['version'] ) );
		self::assertSame(
			array(
				'sequence' => 3,
				'status'   => 'walkover',
				'winner'   => 'away',
			),
			$payload['games'][2]
		);
		self::assertSame( array( $this->id['a1'], $this->id['a3'] ), $payload['games'][6]['away_players'] );
		self::assertArrayNotHasKey( 'home_players', $payload['games'][6], 'zwei Spieler: Paarung automatisch' );

		$result = $this->service->submit( $this->id['fixture'], $payload, $this->actor['home'] );
		self::assertSame( array( 'provisional', 5, 5 ), array( $result['status'], $result['home_score'], $result['away_score'] ) );

		$form['lineup']['away']['Z'] = '';
		$form['games']['10']['st']   = '';
		self::assertSame( array( 'lineup.away.Z', 'games.10' ), array_keys( EntryScript::call( 'validate', array( $data, $form ) ) ) );
	}

	public function test_public_match_report_stays_unchanged_for_guests(): void {
		$html = ( new MatchReportView( new ReadModel( $this->db, new NullCache() ), new Urls( 'https://liga.example/' ) ) )->render( array( 'fixture' => $this->id['fixture'] ) );
		self::assertStringContainsString( 'Wollishofen 1', $html );
		self::assertStringNotContainsString( 'ttl-report-action', $html, 'kein Knopf ohne Anmeldung' );
		self::assertStringNotContainsString( 'erfassen', $html );
	}

	public function test_my_games_section(): void {
		$entry_url = static fn( int $id ): string => 'https://liga.example/erfassen/?begegnung=' . $id;
		$home      = MyGames::render( $this->service->my_fixtures( $this->actor['home'] ), $entry_url );

		self::assertStringContainsString( 'Meine Spiele', $home );
		self::assertStringContainsString( 'href="https://liga.example/erfassen/?begegnung=' . $this->id['fixture'] . '">Resultat erfassen', $home );
		self::assertStringContainsString( 'Seefeld &lt;2&gt; &amp; Co – Wollishofen 1', $home );
		self::assertStringContainsString( 'Keine Resultate zu bestätigen.', $home );
		self::assertStringContainsString( 'In den nächsten drei Wochen stehen keine Spiele an.', $home );
		self::assertStringContainsString( 'Di, 10.11.2026, 20:00', $home );

		$this->service->submit( $this->id['fixture'], $this->payload(), $this->actor['home'] );
		$away = MyGames::render( $this->service->my_fixtures( $this->actor['away'] ), $entry_url );
		self::assertStringContainsString( '>Resultat bestätigen<', $away );
		self::assertStringContainsString( 'provisorisch 6:4', $away );
		self::assertStringContainsString( 'Keine offenen Resultate.', $away );

		$empty = MyGames::render(
			array(
				'to_enter'   => array(),
				'to_confirm' => array(),
				'upcoming'   => array(),
			),
			$entry_url
		);
		self::assertSame( 3, substr_count( $empty, 'ttl-my-games__empty' ) );
	}
}
