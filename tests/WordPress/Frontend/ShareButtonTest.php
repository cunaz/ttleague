<?php
/**
 * Knopf «Teilen»: Skript nur auf Seiten mit dem Knopf, gleiche Ausgabe für alle Besucher.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Frontend;

use TTLeague\Frontend\Assets;
use TTLeague\ReadModel\ReadModel;
use TTLeague\Results\EntryBlock;
use TTLeague\Results\Results;
use TTLeague\Tests\WordPress\League;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Liga siehe League; f1 ist Seefeld 1 gegen Wollishofen 1.
 */
final class ShareButtonTest extends TestCase {

	private League $league;

	public function set_up(): void {
		parent::set_up();
		ReadModel::set_instance( null );
		$this->league = new League( $this->db, '2026-11-10 20:00:00' );
		wp_dequeue_script( Assets::SHARE );
	}

	public function tear_down(): void {
		wp_dequeue_script( Assets::SHARE );
		ReadModel::set_instance( null );
		$_GET = array();
		parent::tear_down();
	}

	public function test_script_is_registered_deferred_in_the_footer(): void {
		$script = wp_scripts()->registered[ Assets::SHARE ] ?? null;

		self::assertNotNull( $script );
		self::assertStringEndsWith( 'assets/js/share.js', (string) $script->src );
		self::assertSame( array(), $script->deps );
		self::assertSame( 'defer', wp_scripts()->get_data( Assets::SHARE, 'strategy' ) );
		self::assertSame( 1, (int) wp_scripts()->get_data( Assets::SHARE, 'group' ), 'im Fuss der Seite' );
		self::assertFalse( wp_script_is( Assets::SHARE, 'enqueued' ), 'nicht auf jeder Seite' );
	}

	public function test_match_report_gets_the_button_and_the_script_once_there_is_a_result(): void {
		$block = '<!-- wp:ttleague/match-report {"fixture":' . $this->league->id['f1'] . '} /-->';

		wp_set_current_user( 0 );
		self::assertStringNotContainsString( 'ttl-share', do_blocks( $block ) );
		self::assertStringNotContainsString( 'ttl-share', do_blocks( '<!-- wp:ttleague/club {"club":"seefeld"} /-->' ) );
		self::assertFalse( wp_script_is( Assets::SHARE, 'enqueued' ) );

		$captain = $this->account( 'subscriber', 'konto.heim@example.invalid', $this->league->id['captain_h1'] );
		wp_set_current_user( $captain );
		Results::service()->submit( $this->league->id['f1'], $this->league->six_four(), Results::current_actor() );

		wp_set_current_user( 0 );
		$guest = do_blocks( $block );
		self::assertStringContainsString( 'data-ttl-share-title="Seefeld 1 – Wollishofen 1 6:4"', $guest );
		self::assertStringContainsString( 'data-ttl-share-url="http://example.org/begegnung/' . $this->league->id['f1'] . '/"', $guest );
		self::assertTrue( wp_script_is( Assets::SHARE, 'enqueued' ) );

		// Für alle gleich: Angemeldete ohne Rolle sehen dieselbe Seite wie Gäste.
		wp_set_current_user( $this->account( 'subscriber', 'konto.niemand@example.invalid', $this->league->id['nobody'] ) );
		self::assertSame( $guest, do_blocks( $block ) );
	}

	public function test_entry_page_gets_the_button_in_the_reading_view(): void {
		$_GET[ EntryBlock::PARAM ] = (string) $this->league->id['f1'];
		$home                      = $this->account( 'subscriber', 'konto.heim@example.invalid', $this->league->id['captain_h1'] );
		$away                      = $this->account( 'subscriber', 'konto.gast@example.invalid', $this->league->id['captain_a1'] );

		wp_set_current_user( $home );
		self::assertStringNotContainsString( 'ttl-share', EntryBlock::render() );
		self::assertFalse( wp_script_is( Assets::SHARE, 'enqueued' ) );

		Results::service()->submit( $this->league->id['f1'], $this->league->six_four(), Results::current_actor() );
		wp_set_current_user( $away );
		$html = EntryBlock::render();

		self::assertStringContainsString( 'data-ttl-share-title="Seefeld 1 – Wollishofen 1 6:4"', $html );
		self::assertStringContainsString( 'data-ttl-share-url="http://example.org/begegnung/' . $this->league->id['f1'] . '/"', $html );
		self::assertTrue( wp_script_is( Assets::SHARE, 'enqueued' ) );
	}
}
