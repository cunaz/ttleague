<?php
/**
 * Integrationstests der Klassierung: berechnen, entscheiden, abschliessen, wieder öffnen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Classification;

use PHPUnit\Framework\Attributes\DataProvider;
use TTLeague\Classification\ClassificationException;
use TTLeague\Classification\ProposalFilter;
use TTLeague\Classification\ProposalService;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Rating\RatingRepository;
use TTLeague\Rating\RatingService;
use TTLeague\ReadModel\Cache\NullCache;
use TTLeague\ReadModel\ReadModel;
use TTLeague\Results\Actor;
use TTLeague\Tests\Integration\Database\RecordingDb;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Daten siehe Scenario. Die Uhr steht auf dem 20. Juni 2027, 10:00 UTC.
 */
final class ProposalServiceTest extends IntegrationTestCase {

	private const NOW = '2027-06-20 10:00:00';

	private FixedClock $clock;

	private ProposalService $service;

	private Actor $tk;

	/** Aufrufe der Meldung «Daten geändert». */
	private int $changes = 0;

	/** Aufrufe von «Wertungszahlen aktuell machen». */
	private int $refreshes = 0;

	/** @var (callable():void)|null Ausstehende Neuberechnung der Wertungszahl; null: nichts steht aus. */
	private $pending = null;

	private Seeder $seeder;

	/** @var array<string,int> */
	private array $id = array();

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$scenario        = new Scenario( $this->db );
		$this->id        = $scenario->id;
		$this->seeder    = $scenario->seeder;
		$this->clock     = new FixedClock( self::NOW );
		$this->changes   = 0;
		$this->refreshes = 0;
		$this->pending   = null;
		$this->service   = new ProposalService(
			$this->db,
			$this->clock,
			function (): void {
				++$this->changes;
			},
			function (): bool {
				++$this->refreshes;
				if ( null === $this->pending ) {
					return false;
				}
				( $this->pending )();
				$this->pending = null;
				return true;
			}
		);
		$this->tk        = new Actor( 7, null, true, 'TK' );
	}

	public function test_compute_writes_one_row_per_registered_player_of_the_active_season(): void {
		$result = $this->service->compute( $this->tk );

		self::assertSame(
			array(
				'players'   => 6,
				'created'   => 6,
				'updated'   => 0,
				'removed'   => 0,
				'reset'     => 0,
				'refreshed' => false,
			),
			$result
		);
		self::assertSame(
			array(
				'ablage'   => array( 5, 520.0, 1, 5 ),
				'beispiel' => array( 10, 1160.0, 2, 10 ),
				'muster'   => array( 6, 812.5, 3, 8 ),
				'entwurf'  => array( 4, 380.0, 1, 4 ),
				'probe'    => array( 9, null, 0, 9 ),
				'vorlage'  => array( 7, 640.0, 3, 6 ),
			),
			$this->computed(),
			'bisher, Wertungszahl, gewertete Spiele dieser Saison, Vorschlag; sortiert nach Club und Name'
		);
		self::assertSame( 0, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'classification_proposal' )} WHERE player_id = %d OR season_id <> %d", array( $this->id['kopie'], $this->id['season'] ) ), 'nur gemeldete Spieler der aktiven Saison' );
		self::assertSame( 0, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'classification_proposal' )} WHERE decided IS NOT NULL OR decided_by IS NOT NULL OR decided_at IS NOT NULL OR note IS NOT NULL" ), 'noch nichts entschieden' );

		$audit = $this->audit( 'season', $this->id['season'] );
		self::assertSame( array( 'classification_computed' ), array_column( $audit, 'action' ) );
		self::assertSame( '7', $audit[0]['user_id'] );
		self::assertSame( $result, json_decode( (string) $audit[0]['after_json'], true ) );
		self::assertSame( 1, $this->changes );
	}

	public function test_player_without_rating_is_stored_with_zero_and_read_as_no_rating(): void {
		$this->service->compute( $this->tk );

		self::assertSame( '0.00', $this->db->get_var( "SELECT rating FROM {$this->db->table( 'classification_proposal' )} WHERE player_id = %d", array( $this->id['probe'] ) ) );
		self::assertNull( $this->row( 'probe' )['rating'] );
	}

	public function test_games_count_equals_the_rated_games_of_the_season(): void {
		// Szenario der Lesemodelle: Meisterschaft mit kampflosen Spielen, Cup, Ranglistenturnier, zwei Saisons.
		$this->truncate_all();
		$ids = ( new Seeder( $this->db ) )->scenario();
		$this->db->query( "DELETE FROM {$this->db->table( 'rating_event' )}" );
		$this->db->query( "DELETE FROM {$this->db->table( 'player_rating' )}" );
		$rated = ( new RatingService( $this->db ) )->recalc();

		$season   = array();
		$expected = array();
		foreach ( ( new RatingRepository( $this->db ) )->games() as $game ) {
			$season[ $game->id ] = $game->season_id;
		}
		foreach ( $rated->events as $event ) {
			if ( $season[ $event->game_id ] === $ids['season_new'] ) {
				$expected[ $event->player_id ] = ( $expected[ $event->player_id ] ?? 0 ) + 1;
			}
		}

		$this->service->compute( $this->tk );

		$counted = array();
		foreach ( $this->db->get_results( "SELECT player_id, games_count FROM {$this->db->table( 'classification_proposal' )}" ) as $row ) {
			if ( (int) $row['games_count'] > 0 ) {
				$counted[ (int) $row['player_id'] ] = (int) $row['games_count'];
			}
		}
		ksort( $expected );
		ksort( $counted );
		self::assertSame( $expected, $counted );
		self::assertSame( 14, $counted[ $ids['player_muster'] ], 'Muster: zwei Begegnungen der Meisterschaft (je 3 Einzel und 1 Doppel), Cup (2 Einzel, 1 Doppel), Ranglistenturnier (3 Einzel)' );
		self::assertGreaterThan( $counted[ $ids['player_muster'] ], (int) $this->db->get_var( "SELECT games_count FROM {$this->db->table( 'player_rating' )} WHERE player_id = %d", array( $ids['player_muster'] ) ), 'die Vorsaison zählt nicht mit' );
	}

	public function test_compute_runs_a_pending_rating_recalculation_first(): void {
		// Nach einem Resultat steht die Neuberechnung aus: Sie bringt Muster ein Spiel und eine neue Wertungszahl.
		$this->pending = fn() => $this->rated_game( 'muster', 951.0 );

		$result = $this->service->compute( $this->tk );

		self::assertTrue( $result['refreshed'] );
		self::assertSame( 1, $this->refreshes );
		self::assertSame( array( 6, 951.0, 4, 10 ), $this->computed()['muster'], 'der Vorschlag beruht auf der neu berechneten Wertungszahl' );
		self::assertTrue( json_decode( (string) $this->audit( 'season', $this->id['season'] )[0]['after_json'], true )['refreshed'] );

		self::assertFalse( $this->service->compute( $this->tk )['refreshed'], 'steht nichts aus, wird nicht neu berechnet' );
		self::assertSame( 2, $this->refreshes );
	}

	public function test_recompute_overwrites_the_calculation_and_keeps_overrides_and_unchanged_acceptances(): void {
		$this->service->compute( $this->tk );
		$this->service->accept( $this->tk, $this->row( 'muster' )['id'] );
		$this->service->accept( $this->tk, $this->row( 'beispiel' )['id'] );
		$this->service->override( $this->tk, $this->row( 'vorlage' )['id'], 7, 'Lange verletzt, bleibt C7.' );
		$this->service->override( $this->tk, $this->row( 'entwurf' )['id'], 4, 'Nach Rücksprache bestätigt.' );
		$decided = $this->decisions();

		// Seither:
		// Muster: ein Spiel mehr, Vorschlag C8 → C10 (reine Übernahme von C8).
		// Beispiel: neue Wertungszahl, Vorschlag bleibt C10 (reine Übernahme).
		// Vorlage: neue Wertungszahl, Vorschlag C6 → C8 (übersteuert auf C7).
		// Entwurf: Klassierung korrigiert, Vorschlag D4 → D5 (mit Bemerkung auf D4 entschieden, gleich dem alten Vorschlag).
		// Ablage: Klassierung korrigiert, noch offen. Dazu eine Nachmeldung.
		$this->clock->advance( '+1 day' );
		$this->rated_game( 'muster', 951.0 );
		$this->db->update( 'player_rating', array( 'rating' => 1171.0 ), array( 'player_id' => $this->id['beispiel'] ) );
		$this->db->update( 'player_rating', array( 'rating' => 760.0 ), array( 'player_id' => $this->id['vorlage'] ) );
		$this->db->update( 'player_season', array( 'classification' => 5 ), array( 'player_id' => $this->id['entwurf'] ) );
		$this->db->update( 'player_season', array( 'classification' => 6 ), array( 'player_id' => $this->id['ablage'] ) );
		$late = $this->seeder->player( 'Skizze', 'Urs' );
		$this->seeder->register( $late, $this->id['season'], $this->id['club_seefeld'], 3 );

		$result = $this->service->compute( new Actor( 8, null, true ) );

		self::assertSame(
			array(
				'players'   => 7,
				'created'   => 1,
				'updated'   => 5,
				'removed'   => 0,
				'reset'     => 1,
				'refreshed' => false,
			),
			$result
		);
		$computed = $this->computed();
		self::assertSame( array( 6, 951.0, 4, 10 ), $computed['muster'], 'Wertungszahl, Spiele und Vorschlag neu' );
		self::assertSame( array( 10, 1171.0, 2, 10 ), $computed['beispiel'] );
		self::assertSame( array( 7, 760.0, 3, 8 ), $computed['vorlage'] );
		self::assertSame( array( 5, 380.0, 1, 5 ), $computed['entwurf'] );
		self::assertSame( array( 6, 520.0, 1, 6 ), $computed['ablage'], 'bisherige Klassierung nachgeführt' );

		$after = $this->decisions();
		self::assertSame( array( null, null, null, null ), $after['muster'], 'Übernahme eines Vorschlags, den es nicht mehr gibt: wieder offen' );
		self::assertSame( $decided['beispiel'], $after['beispiel'], 'Übernahme bleibt, wenn der Vorschlag gleich bleibt' );
		self::assertSame( $decided['vorlage'], $after['vorlage'], 'Übersteuerung bleibt mit Konto, Zeitpunkt und Bemerkung' );
		self::assertSame( $decided['entwurf'], $after['entwurf'], 'ein Entscheid mit Bemerkung ist keine reine Übernahme' );
		self::assertNull( $this->db->get_var( "SELECT decided FROM {$this->db->table( 'classification_proposal' )} WHERE player_id = %d", array( $late ) ) );
		self::assertSame( 4, $this->service->overview( new ProposalFilter() )['counts']['open'], 'Muster, Ablage, Probe und die Nachmeldung' );
		self::assertContains( $this->id['muster'], array_column( $this->service->overview( new ProposalFilter( 0, false, true ) )['rows'], 'player_id' ), 'unter «noch offen» zu finden' );

		$audit = $this->audit( 'classification_proposal', $this->row( 'muster' )['id'] );
		self::assertSame( array( 'accepted', 'decision_reset' ), array_column( $audit, 'action' ) );
		self::assertSame( '8', $audit[1]['user_id'], 'wer neu berechnet hat' );
		self::assertSame(
			array(
				'decided'  => 8,
				'note'     => null,
				'proposed' => 8,
			),
			json_decode( (string) $audit[1]['before_json'], true )
		);
		self::assertSame(
			array(
				'decided'  => null,
				'note'     => null,
				'proposed' => 10,
			),
			json_decode( (string) $audit[1]['after_json'], true )
		);
		foreach ( array( 'beispiel', 'vorlage', 'entwurf' ) as $key ) {
			self::assertNotContains( 'decision_reset', array_column( $this->audit( 'classification_proposal', $this->row( $key )['id'] ), 'action' ), $key );
		}
		self::assertSame( 1, json_decode( (string) $this->audit( 'season', $this->id['season'] )[1]['after_json'], true )['reset'] );
	}

	public function test_recompute_drops_open_rows_of_players_no_longer_registered_but_keeps_decided_ones(): void {
		$this->service->compute( $this->tk );
		$this->service->accept( $this->tk, $this->row( 'entwurf' )['id'] );
		$this->db->delete( 'player_season', array( 'player_id' => $this->id['entwurf'] ) );
		$this->db->delete( 'player_season', array( 'player_id' => $this->id['probe'] ) );

		$result = $this->service->compute( $this->tk );

		self::assertSame( 1, $result['removed'] );
		self::assertSame( 4, $result['players'] );
		$rows = array_column( $this->service->overview( new ProposalFilter() )['rows'], null, 'player_id' );
		self::assertArrayNotHasKey( $this->id['probe'], $rows, 'offene Zeile entfernt' );
		self::assertSame( 4, $rows[ $this->id['entwurf'] ]['decided'], 'entschiedene Zeile bleibt' );
		self::assertNull( $rows[ $this->id['entwurf'] ]['club_id'], 'ohne Meldung kein Club' );
		self::assertSame( $this->id['entwurf'], array_key_last( $rows ), 'Zeilen ohne Club stehen am Schluss' );
	}

	public function test_accept_takes_the_proposal_and_logs_before_and_after(): void {
		$this->service->compute( $this->tk );
		$id = $this->row( 'muster' )['id'];
		$this->service->override( $this->tk, $id, 7, 'Vorläufig C7.' );
		$this->changes = 0;
		$this->clock->advance( '+2 hours' );

		$row = $this->service->accept( new Actor( 9, null, true ), $id, 8 );

		self::assertSame( 8, $row['decided'] );
		self::assertSame( 9, $row['decided_by'] );
		self::assertSame( '2027-06-20 12:00:00', $row['decided_at'], 'UTC aus der Uhr' );
		self::assertNull( $row['note'], 'die Bemerkung des früheren Entscheids entfällt' );
		$audit = $this->audit( 'classification_proposal', $id );
		self::assertSame( array( 'overridden', 'accepted' ), array_column( $audit, 'action' ) );
		self::assertSame(
			array(
				'decided' => 7,
				'note'    => 'Vorläufig C7.',
			),
			json_decode( (string) $audit[1]['before_json'], true )
		);
		self::assertSame(
			array(
				'decided'  => 8,
				'note'     => null,
				'proposed' => 8,
			),
			json_decode( (string) $audit[1]['after_json'], true )
		);
		self::assertSame( '9', $audit[1]['user_id'] );
		self::assertSame( 1, $this->changes );
	}

	public function test_repeating_the_same_decision_writes_nothing(): void {
		$this->service->compute( $this->tk );
		$id    = $this->row( 'muster' )['id'];
		$first = $this->service->accept( $this->tk, $id );
		$this->clock->advance( '+1 hour' );
		$this->changes = 0;

		$again = $this->service->accept( new Actor( 9, null, true ), $id );

		self::assertSame( $first, $again, 'Konto und Zeitpunkt des ersten Entscheids bleiben' );
		self::assertCount( 1, $this->audit( 'classification_proposal', $id ) );
		self::assertSame( 0, $this->changes );
	}

	public function test_accept_is_rejected_when_the_proposal_changed_since_the_page_was_loaded(): void {
		$this->service->compute( $this->tk );
		$id = $this->row( 'muster' )['id'];

		try {
			$this->service->accept( $this->tk, $id, 7 );
			self::fail( 'Ablehnung erwartet.' );
		} catch ( ClassificationException $e ) {
			self::assertSame( ClassificationException::CONFLICT, $e->reason );
		}
		self::assertNull( $this->row( 'muster' )['decided'] );
		self::assertSame( array(), $this->audit( 'classification_proposal', $id ) );
	}

	public function test_accept_of_an_unknown_row_or_a_row_of_another_season_is_not_found(): void {
		$this->service->compute( $this->tk );
		$foreign = $this->db->insert(
			'classification_proposal',
			array(
				'season_id'     => $this->id['old_season'],
				'player_id'     => $this->id['kopie'],
				'current_value' => 8,
				'rating'        => 790.0,
				'games_count'   => 3,
				'proposed'      => 8,
			)
		);

		foreach ( array( $foreign, 999999 ) as $id ) {
			try {
				$this->service->accept( $this->tk, $id );
				self::fail( 'Ablehnung erwartet.' );
			} catch ( ClassificationException $e ) {
				self::assertSame( ClassificationException::NOT_FOUND, $e->reason );
			}
		}
		self::assertNull( $this->db->get_var( "SELECT decided FROM {$this->db->table( 'classification_proposal' )} WHERE id = %d", array( $foreign ) ) );
	}

	public function test_accept_open_decides_only_open_rows_of_the_filter_in_one_transaction(): void {
		$this->service->compute( $this->tk );
		$this->service->override( $this->tk, $this->row( 'vorlage' )['id'], 7, 'Bleibt C7.' );
		$before        = $this->decisions();
		$this->changes = 0;
		$recording     = new RecordingDb( $this->db );
		$service       = new ProposalService(
			$recording,
			$this->clock,
			function (): void {
				++$this->changes;
			}
		);

		$count = $service->accept_open( $this->tk, new ProposalFilter( $this->id['club_wollishofen'] ) );

		self::assertSame( 2, $count, 'Entwurf und Probe; Vorlage ist schon entschieden' );
		$after = $this->decisions();
		self::assertSame( array( 4, 7, self::NOW, null ), $after['entwurf'] );
		self::assertSame( array( 9, 7, self::NOW, null ), $after['probe'] );
		self::assertSame( $before['vorlage'], $after['vorlage'], 'der abweichende Entscheid bleibt' );
		foreach ( array( 'ablage', 'beispiel', 'muster' ) as $key ) {
			self::assertSame( array( null, null, null, null ), $after[ $key ], 'anderer Club: ' . $key );
		}
		foreach ( array( 'entwurf', 'probe' ) as $key ) {
			$audit = $this->audit( 'classification_proposal', $this->row( $key )['id'] );
			self::assertSame( array( 'accepted' ), array_column( $audit, 'action' ) );
			self::assertSame(
				array(
					'decided'  => $after[ $key ][0],
					'note'     => null,
					'proposed' => $after[ $key ][0],
				),
				json_decode( (string) $audit[0]['after_json'], true )
			);
			self::assertSame(
				array(
					'decided' => null,
					'note'    => null,
				),
				json_decode( (string) $audit[0]['before_json'], true )
			);
		}
		self::assertSame( 1, $this->changes );

		$inside = $recording->statements( true );
		self::assertStringContainsString( 'FOR UPDATE', $inside[0], 'zuerst die Sperre der Saison' );
		self::assertStringContainsString( $this->db->table( 'season' ), $inside[0] );
		$writes = array_filter( $recording->statements( false ), static fn( string $sql ): bool => 1 === preg_match( '/^\s*(INSERT|UPDATE|DELETE)/i', $sql ) );
		self::assertSame( array(), $writes, 'kein Schreibvorgang ausserhalb der Transaktion' );
	}

	public function test_accept_open_respects_the_deviating_filter_and_reports_nothing_to_do(): void {
		$this->service->compute( $this->tk );

		self::assertSame( 2, $this->service->accept_open( $this->tk, new ProposalFilter( 0, true ) ), 'Muster und Vorlage weichen ab' );
		self::assertSame( array( 'muster', 'vorlage' ), array_keys( array_filter( $this->decisions(), static fn( array $d ): bool => null !== $d[0] ) ) );

		$this->changes = 0;
		self::assertSame( 0, $this->service->accept_open( $this->tk, new ProposalFilter( 0, true ) ) );
		self::assertSame( 0, $this->changes, 'ohne Änderung keine neue Cache-Version' );
		self::assertSame( 4, $this->service->accept_open( $this->tk, new ProposalFilter() ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function empty_notes(): array {
		return array(
			'leer'            => array( '' ),
			'nur Leerzeichen' => array( " \n\t " ),
		);
	}

	#[DataProvider( 'empty_notes' )]
	public function test_override_without_a_note_is_rejected( string $note ): void {
		$this->service->compute( $this->tk );
		$id            = $this->row( 'muster' )['id'];
		$this->changes = 0;

		try {
			$this->service->override( $this->tk, $id, 7, $note );
			self::fail( 'Ablehnung erwartet.' );
		} catch ( ClassificationException $e ) {
			self::assertSame( ClassificationException::INVALID, $e->reason );
			self::assertStringContainsString( 'Bemerkung', $e->getMessage() );
		}
		self::assertNull( $this->row( 'muster' )['decided'] );
		self::assertSame( array(), $this->audit( 'classification_proposal', $id ) );
		self::assertSame( 0, $this->changes );
	}

	public function test_override_accepts_the_ends_of_the_scale_and_rejects_values_beyond(): void {
		$this->service->compute( $this->tk );
		$id = $this->row( 'muster' )['id'];

		foreach ( array( 0, 23, -1 ) as $value ) {
			try {
				$this->service->override( $this->tk, $id, $value, 'Sonderfall.' );
				self::fail( 'Ablehnung erwartet: ' . $value );
			} catch ( ClassificationException $e ) {
				self::assertSame( ClassificationException::INVALID, $e->reason );
			}
		}
		self::assertNull( $this->row( 'muster' )['decided'] );

		self::assertSame( 1, $this->service->override( $this->tk, $id, 1, 'Sonderfall unten.' )['decided'] );
		$row = $this->service->override( $this->tk, $id, 22, ' Sonderfall oben. ' );
		self::assertSame( 22, $row['decided'] );
		self::assertSame( 'Sonderfall oben.', $row['note'] );

		$audit = $this->audit( 'classification_proposal', $id );
		self::assertSame( array( 'overridden', 'overridden' ), array_column( $audit, 'action' ) );
		self::assertSame( 1, json_decode( (string) $audit[1]['before_json'], true )['decided'], 'vorheriger Wert' );
		self::assertSame( 22, json_decode( (string) $audit[1]['after_json'], true )['decided'], 'neuer Wert' );
		self::assertSame( 'Sonderfall oben.', $audit[1]['note'] );
	}

	public function test_override_rejects_a_note_longer_than_the_limit(): void {
		$this->service->compute( $this->tk );
		$id = $this->row( 'muster' )['id'];

		// Genau die Höchstlänge, mit Zeilenumbruch wie aus einem Formular (CRLF zählt als ein Zeichen).
		$note = str_repeat( 'ä', ProposalService::NOTE_MAX - 2 ) . "\r\nb";
		$row  = $this->service->override( $this->tk, $id, 7, $note );
		self::assertSame( 7, $row['decided'] );
		self::assertSame( ProposalService::NOTE_MAX, mb_strlen( (string) $row['note'] ) );
		$this->expectException( ClassificationException::class );
		$this->service->override( $this->tk, $id, 8, str_repeat( 'ä', ProposalService::NOTE_MAX + 1 ) );
	}

	public function test_close_needs_a_proposal(): void {
		self::assertSame( ClassificationException::CONFLICT, $this->rejected( fn() => $this->service->close( $this->tk ) ) );
		self::assertNull( $this->closed_at() );
	}

	public function test_close_is_rejected_while_one_row_is_open(): void {
		$this->service->compute( $this->tk );
		$this->service->accept_open( $this->tk, new ProposalFilter() );
		$this->db->update(
			'classification_proposal',
			array(
				'decided'    => null,
				'decided_by' => null,
				'decided_at' => null,
			),
			array( 'player_id' => $this->id['probe'] )
		);

		try {
			$this->service->close( $this->tk );
			self::fail( 'Ablehnung erwartet.' );
		} catch ( ClassificationException $e ) {
			self::assertSame( ClassificationException::CONFLICT, $e->reason );
			self::assertStringContainsString( '1 Zeile ist ohne Entscheid', $e->getMessage() );
		}
		self::assertNull( $this->closed_at() );

		$this->service->accept( $this->tk, $this->row( 'probe' )['id'] );
		self::assertSame( self::NOW, $this->service->close( $this->tk ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function changes_since_the_calculation(): array {
		return array(
			'Wertungszahl, Vorschlag bleibt' => array( 'rating' ),
			'nur ein gewertetes Spiel mehr'  => array( 'games' ),
			'Klassierung korrigiert'         => array( 'classification' ),
			'Nachmeldung'                    => array( 'registration' ),
		);
	}

	#[DataProvider( 'changes_since_the_calculation' )]
	public function test_close_is_rejected_when_the_rows_no_longer_match_a_fresh_calculation( string $change ): void {
		$this->service->compute( $this->tk );
		$this->service->accept_open( $this->tk, new ProposalFilter() );
		match ( $change ) {
			'rating'         => $this->db->update( 'player_rating', array( 'rating' => 815.0 ), array( 'player_id' => $this->id['muster'] ) ),
			// Ablage: zwei statt ein Spiel, weiter unter der Mindestzahl, Wertungszahl und Vorschlag gleich.
			'games'          => $this->rated_game( 'ablage', 520.0 ),
			'classification' => $this->db->update( 'player_season', array( 'classification' => 6 ), array( 'player_id' => $this->id['ablage'] ) ),
			'registration'   => $this->seeder->register( $this->seeder->player( 'Skizze', 'Urs' ), $this->id['season'], $this->id['club_seefeld'], 3 ),
		};
		$before        = array( $this->computed(), $this->decisions() );
		$this->changes = 0;

		try {
			$this->service->close( $this->tk );
			self::fail( 'Ablehnung erwartet.' );
		} catch ( ClassificationException $e ) {
			self::assertSame( ClassificationException::CONFLICT, $e->reason );
			self::assertStringContainsString( '1 Zeile entspricht nicht mehr dem aktuellen Stand', $e->getMessage() );
			self::assertStringContainsString( '«Vorschlag neu berechnen»', $e->getMessage() );
		}
		self::assertNull( $this->closed_at() );
		self::assertSame( $before, array( $this->computed(), $this->decisions() ), 'die Prüfung ändert keine Zeile' );
		self::assertSame( 0, $this->changes );

		// Nach dem Neuberechnen (und dem Entscheid über wieder offene Zeilen) ist alles aktuell.
		$this->service->compute( $this->tk );
		$this->service->accept_open( $this->tk, new ProposalFilter() );
		self::assertSame( self::NOW, $this->service->close( $this->tk ) );
	}

	public function test_close_runs_a_pending_rating_recalculation_and_rejects_what_it_changes(): void {
		$this->service->compute( $this->tk );
		$this->service->accept_open( $this->tk, new ProposalFilter() );
		$before          = array( $this->computed(), $this->decisions() );
		$this->refreshes = 0;
		$this->pending   = function (): void {
			$this->rated_game( 'muster', 951.0 );
			$this->rated_game( 'vorlage', 645.0 );
		};

		try {
			$this->service->close( $this->tk );
			self::fail( 'Ablehnung erwartet.' );
		} catch ( ClassificationException $e ) {
			self::assertSame( ClassificationException::CONFLICT, $e->reason );
			self::assertStringContainsString( '2 Zeilen entsprechen nicht mehr dem aktuellen Stand', $e->getMessage() );
		}
		self::assertSame( 1, $this->refreshes );
		self::assertNull( $this->closed_at() );
		self::assertSame( $before, array( $this->computed(), $this->decisions() ), 'nichts stillschweigend geändert' );

		self::assertSame( 1, $this->service->compute( $this->tk )['reset'], 'Muster: Vorschlag C8 → C10; bei Vorlage bleibt der Vorschlag C6' );
		$this->service->accept_open( $this->tk, new ProposalFilter() );
		self::assertSame( self::NOW, $this->service->close( $this->tk ) );
	}

	public function test_close_sets_the_time_and_locks_everything_until_reopened(): void {
		$this->service->compute( $this->tk );
		$this->service->accept_open( $this->tk, new ProposalFilter() );
		$muster = $this->row( 'muster' )['id'];
		$this->clock->advance( '+3 days' );

		$closed = $this->service->close( new Actor( 9, null, true ) );

		self::assertSame( '2027-06-23 10:00:00', $closed );
		self::assertSame( $closed, $this->closed_at() );
		$audit = $this->audit( 'season', $this->id['season'] );
		self::assertSame( 'classification_closed', $audit[1]['action'] );
		self::assertSame( '9', $audit[1]['user_id'] );
		self::assertSame(
			array(
				'classification_closed_at' => $closed,
				'rows'                     => 6,
			),
			json_decode( (string) $audit[1]['after_json'], true )
		);

		// Gesperrt: Nichts ändert sich, auch nicht mit geänderten Wertungszahlen.
		$this->db->update( 'player_rating', array( 'rating' => 1400.0 ), array( 'player_id' => $this->id['muster'] ) );
		$before          = array( $this->computed(), $this->decisions() );
		$this->changes   = 0;
		$this->refreshes = 0;
		foreach ( array(
			'berechnen'       => fn() => $this->service->compute( $this->tk ),
			'übernehmen'      => fn() => $this->service->accept( $this->tk, $muster ),
			'alle übernehmen' => fn() => $this->service->accept_open( $this->tk, new ProposalFilter() ),
			'übersteuern'     => fn() => $this->service->override( $this->tk, $muster, 7, 'Nachträglich.' ),
			'abschliessen'    => fn() => $this->service->close( $this->tk ),
		) as $name => $action ) {
			self::assertSame( ClassificationException::CONFLICT, $this->rejected( $action ), $name );
		}
		self::assertSame( $before, array( $this->computed(), $this->decisions() ) );
		self::assertSame( $closed, $this->closed_at() );
		self::assertSame( 0, $this->changes );
		self::assertSame( 0, $this->refreshes, 'abgeschlossen: keine Neuberechnung der Wertungszahl' );
		self::assertCount( 2, $this->audit( 'season', $this->id['season'] ) );
	}

	public function test_reopen_needs_a_note_and_unlocks(): void {
		$this->service->compute( $this->tk );
		$this->service->accept_open( $this->tk, new ProposalFilter() );
		self::assertSame( ClassificationException::CONFLICT, $this->rejected( fn() => $this->service->reopen( $this->tk, 'Zu früh.' ) ), 'nicht abgeschlossen' );
		$closed = $this->service->close( $this->tk );

		self::assertSame( ClassificationException::INVALID, $this->rejected( fn() => $this->service->reopen( $this->tk, '  ' ) ) );
		self::assertSame( $closed, $this->closed_at(), 'ohne Bemerkung bleibt sie abgeschlossen' );

		$this->service->reopen( new Actor( 9, null, true ), 'Rekurs Vorlage gutgeheissen.' );

		self::assertNull( $this->closed_at() );
		$audit = $this->audit( 'season', $this->id['season'] );
		self::assertSame( 'classification_reopened', $audit[2]['action'] );
		self::assertSame( 'Rekurs Vorlage gutgeheissen.', $audit[2]['note'] );
		self::assertSame( '9', $audit[2]['user_id'] );
		self::assertSame( array( 'classification_closed_at' => $closed ), json_decode( (string) $audit[2]['before_json'], true ) );

		$row = $this->service->override( $this->tk, $this->row( 'vorlage' )['id'], 7, 'Rekurs gutgeheissen.' );
		self::assertSame( 7, $row['decided'], 'nach dem Öffnen wieder änderbar' );
		self::assertSame( 6, $this->row( 'muster' )['current'] );
		self::assertSame( 8, $this->row( 'muster' )['decided'], 'die übrigen Entscheide bleiben' );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function writes(): array {
		return array(
			'berechnen'       => array( 'compute' ),
			'übernehmen'      => array( 'accept' ),
			'alle übernehmen' => array( 'accept_open' ),
			'übersteuern'     => array( 'override' ),
			'abschliessen'    => array( 'close' ),
			'wieder öffnen'   => array( 'reopen' ),
		);
	}

	#[DataProvider( 'writes' )]
	public function test_only_the_tk_may_write( string $write ): void {
		$this->service->compute( $this->tk );
		if ( in_array( $write, array( 'close', 'reopen' ), true ) ) {
			$this->service->accept_open( $this->tk, new ProposalFilter() );
		}
		if ( 'reopen' === $write ) {
			$this->service->close( $this->tk );
		}
		$this->db->update( 'player_rating', array( 'rating' => 1400.0 ), array( 'player_id' => $this->id['muster'] ) );
		$id              = $this->row( 'muster' )['id'];
		$before          = array( $this->computed(), $this->decisions(), $this->closed_at() );
		$logged          = (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'audit_log' )}" );
		$this->changes   = 0;
		$this->refreshes = 0;
		// TK-Chefin eines Clubs: angemeldet und mit Person, aber ohne ttl_tk.
		$chef = new Actor( 12, 3, false, 'Charlotte Chef' );

		$reason = $this->rejected(
			fn() => match ( $write ) {
				'compute'     => $this->service->compute( $chef ),
				'accept'      => $this->service->accept( $chef, $id ),
				'accept_open' => $this->service->accept_open( $chef, new ProposalFilter() ),
				'override'    => $this->service->override( $chef, $id, 7, 'Bitte C7.' ),
				'close'       => $this->service->close( $chef ),
				'reopen'      => $this->service->reopen( $chef, 'Bitte öffnen.' ),
			}
		);

		self::assertSame( ClassificationException::FORBIDDEN, $reason );
		self::assertSame( $before, array( $this->computed(), $this->decisions(), $this->closed_at() ) );
		self::assertSame( $logged, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'audit_log' )}" ) );
		self::assertSame( 0, $this->changes );
		self::assertSame( 0, $this->refreshes, 'ohne Recht keine Neuberechnung der Wertungszahl' );
	}

	public function test_without_an_active_season_nothing_is_written(): void {
		$this->db->update( 'season', array( 'status' => 'archived' ), array( 'id' => $this->id['season'] ) );
		$this->seeder->season( '2027-28', 'planned' );

		self::assertSame( ClassificationException::NOT_FOUND, $this->rejected( fn() => $this->service->compute( $this->tk ) ) );
		self::assertSame( 0, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'classification_proposal' )}" ) );
		$overview = $this->service->overview( new ProposalFilter() );
		self::assertNull( $overview['season'] );
		self::assertSame( array(), $overview['rows'] );
	}

	public function test_invalid_season_rules_are_an_error_and_not_replaced_by_defaults(): void {
		$this->db->update( 'season', array( 'rules_json' => '{"classification_min_games":-1}' ), array( 'id' => $this->id['season'] ) );

		self::assertSame( ClassificationException::INVALID, $this->rejected( fn() => $this->service->compute( $this->tk ) ) );
		self::assertSame( 0, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'classification_proposal' )}" ) );
	}

	public function test_overview_filters_by_club_deviation_and_open_rows(): void {
		$this->service->compute( $this->tk );
		$this->service->accept( $this->tk, $this->row( 'muster' )['id'] );
		$keys = function ( ProposalFilter $filter ): array {
			$names = array_flip( array_intersect_key( $this->id, array_flip( Scenario::PLAYERS ) ) );
			return array_map( static fn( array $row ): string => $names[ $row['player_id'] ], $this->service->overview( $filter )['rows'] );
		};

		self::assertSame( Scenario::PLAYERS, $keys( new ProposalFilter() ), 'nach Club und Name' );
		self::assertSame( array( 'ablage', 'beispiel', 'muster' ), $keys( new ProposalFilter( $this->id['club_seefeld'] ) ) );
		self::assertSame( array( 'muster', 'vorlage' ), $keys( new ProposalFilter( 0, true ) ) );
		self::assertSame( array( 'ablage', 'beispiel', 'entwurf', 'probe', 'vorlage' ), $keys( new ProposalFilter( 0, false, true ) ) );
		self::assertSame( array( 'vorlage' ), $keys( new ProposalFilter( $this->id['club_wollishofen'], true, true ) ) );
		self::assertSame( array(), $keys( new ProposalFilter( $this->id['club_seefeld'], true, true ) ) );

		$overview = $this->service->overview( new ProposalFilter( $this->id['club_seefeld'] ) );
		self::assertSame(
			array(
				'total'     => 6,
				'open'      => 5,
				'deviating' => 2,
				'missing'   => 0,
			),
			$overview['counts'],
			'Zahlen gelten für die ganze Saison, nicht für den Filter'
		);
		self::assertSame( 3, $overview['min_games'] );
		self::assertSame( array( 'TTC Seefeld', 'TTC Wollishofen' ), array_column( $overview['clubs'], 'name' ) );
		$muster = $overview['rows'][2];
		self::assertSame( 'Muster Hans', $muster['name'] );
		self::assertSame( 'TTC Seefeld', $muster['club'] );
		self::assertGreaterThan( 90000, $muster['license_no'] );
	}

	public function test_proposals_and_decisions_do_not_change_the_public_profile(): void {
		$model  = new ReadModel( $this->db, new NullCache() );
		$before = array(
			$model->profiles->get( $this->id['muster'] ),
			$model->players->season_list( $this->id['season'] ),
		);
		self::assertSame( 6, $before[0]['seasons'][0]['classification'] );

		$this->service->compute( $this->tk );
		$this->service->override( $this->tk, $this->row( 'muster' )['id'], 9, 'Starke Rückrunde.' );
		$this->service->accept_open( $this->tk, new ProposalFilter() );
		$this->service->close( $this->tk );

		self::assertSame(
			$before,
			array(
				$model->profiles->get( $this->id['muster'] ),
				$model->players->season_list( $this->id['season'] ),
			),
			'das Profil zeigt weiter die offizielle Klassierung der Saison'
		);
		self::assertSame( 6, (int) $this->db->get_var( "SELECT classification FROM {$this->db->table( 'player_season' )} WHERE player_id = %d AND season_id = %d", array( $this->id['muster'], $this->id['season'] ) ) );
	}

	public function test_no_public_code_reads_the_proposals(): void {
		$root  = dirname( __DIR__, 3 );
		$files = array();
		foreach ( array( 'src/ReadModel', 'src/Frontend', 'src/Calendar', 'src/Rest', 'src/Pwa', 'src/Content', 'blocks' ) as $dir ) {
			self::assertDirectoryExists( $root . '/' . $dir );
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( false !== strpos( (string) file_get_contents( $file->getPathname() ), 'classification_proposal' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test liest Quelltext.
					$files[] = $file->getPathname();
				}
			}
		}
		self::assertSame( array(), $files, 'Vorschläge und offene Entscheide sind nicht öffentlich' );
	}

	/**
	 * Ein weiteres gewertetes Spiel der aktiven Saison (Ranglistenturnier) und die Wertungszahl
	 * danach: das, was eine Neuberechnung der Wertungszahl nach einem neuen Resultat hinterlässt.
	 */
	private function rated_game( string $key, float $rating ): void {
		$t      = $this->db->table( 'game' );
		$group  = (int) $this->db->get_var( "SELECT id FROM {$this->db->table( 'rl_group' )} LIMIT 1" );
		$game   = $this->db->insert(
			'game',
			array(
				'rl_group_id'  => $group,
				'sequence'     => 1 + (int) $this->db->get_var( "SELECT MAX(sequence) FROM $t WHERE rl_group_id = %d", array( $group ) ),
				'a1_player_id' => $this->id[ $key ],
				'b1_player_id' => $this->id['probe'],
				'sets_a'       => 3,
				'sets_b'       => 0,
			)
		);
		$before = (float) $this->db->get_var( "SELECT rating FROM {$this->db->table( 'player_rating' )} WHERE player_id = %d", array( $this->id[ $key ] ) );
		$this->db->insert(
			'rating_event',
			array(
				'player_id'     => $this->id[ $key ],
				'game_id'       => $game,
				'rating_before' => $before,
				'rating_after'  => $rating,
				'delta'         => $rating - $before,
				'played_at'     => '2027-06-01 20:00:00',
			)
		);
		$this->db->update( 'player_rating', array( 'rating' => $rating ), array( 'player_id' => $this->id[ $key ] ) );
	}

	/**
	 * Berechneter Teil je Spieler in der Reihenfolge der Tabelle: bisher, Wertungszahl, Spiele, Vorschlag.
	 *
	 * @return array<string,array{0:int,1:float|null,2:int,3:int}>
	 */
	private function computed(): array {
		$names = array_flip( array_intersect_key( $this->id, array_flip( Scenario::PLAYERS ) ) );
		$out   = array();
		foreach ( $this->service->overview( new ProposalFilter() )['rows'] as $row ) {
			$out[ $names[ $row['player_id'] ] ?? 'p' . $row['player_id'] ] = array( $row['current'], $row['rating'], $row['games'], $row['proposed'] );
		}
		return $out;
	}

	/**
	 * Entscheid je Spieler: Wert, Konto, Zeitpunkt, Bemerkung.
	 *
	 * @return array<string,array{0:int|null,1:int|null,2:string|null,3:string|null}>
	 */
	private function decisions(): array {
		$names = array_flip( array_intersect_key( $this->id, array_flip( Scenario::PLAYERS ) ) );
		$out   = array();
		foreach ( $this->service->overview( new ProposalFilter() )['rows'] as $row ) {
			$out[ $names[ $row['player_id'] ] ?? 'p' . $row['player_id'] ] = array( $row['decided'], $row['decided_by'], $row['decided_at'], $row['note'] );
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row( string $key ): array {
		foreach ( $this->service->overview( new ProposalFilter() )['rows'] as $row ) {
			if ( $row['player_id'] === $this->id[ $key ] ) {
				return $row;
			}
		}
		self::fail( 'Keine Zeile für ' . $key );
	}

	private function closed_at(): ?string {
		return $this->db->get_var( "SELECT classification_closed_at FROM {$this->db->table( 'season' )} WHERE id = %d", array( $this->id['season'] ) );
	}

	/**
	 * Einträge des audit_log zu einem Objekt, älteste zuerst.
	 *
	 * @return list<array<string,string|null>>
	 */
	private function audit( string $entity, int $entity_id ): array {
		return $this->db->get_results( "SELECT action, user_id, before_json, after_json, note FROM {$this->db->table( 'audit_log' )} WHERE entity = %s AND entity_id = %d ORDER BY id", array( $entity, $entity_id ) );
	}

	/**
	 * Grund der Ablehnung; scheitert, wenn die Aktion durchgeht.
	 *
	 * @param callable():mixed $action Aktion.
	 */
	private function rejected( callable $action ): string {
		try {
			$action();
		} catch ( ClassificationException $e ) {
			return $e->reason;
		}
		self::fail( 'Ablehnung erwartet.' );
	}
}
