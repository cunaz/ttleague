<?php
/**
 * Übersicht «Spielbetrieb» mit echtem WordPress gerendert.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Tk;

use TTLeague\Results\ResultRepository;
use TTLeague\Tests\WordPress\League;
use TTLeague\Tests\WordPress\TestCase;
use TTLeague\Tk\DashboardPage;
use TTLeague\Tk\Tk;

/**
 * Liga siehe League. Die Begegnung f1 ist strittig und hat einen Hinweis aus der Erfassung: Sie
 * steht deshalb unter «Einsprachen» und unter «Hinweise», jeweils mit dem Formular «Abschliessen».
 */
final class DashboardPageTest extends TestCase {

	public function test_form_fields_have_unique_ids_when_a_fixture_appears_twice(): void {
		$league  = new League( $this->db, wp_date( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) );
		$f1      = $league->id['f1'];
		$entered = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		$this->db->update(
			'fixture',
			array(
				'status'     => 'disputed',
				'home_score' => 6,
				'away_score' => 4,
				'entered_by' => 1,
				'entered_at' => $entered,
			),
			array( 'id' => $f1 )
		);
		$repo = new ResultRepository( $this->db );
		$repo->add_event( $f1, 'entered', 1, null, null, array( 'warnings' => array( 'Erfasst nach Ablauf der Frist von 48 Stunden.' ) ), $entered );
		$repo->add_event( $f1, 'disputed', 1, $league->id['captain_a1'], 'Das Doppel ging an uns.', array(), gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		$overview = Tk::dashboard()->overview();
		self::assertSame( array( $f1 ), array_map( 'intval', array_column( $overview['disputed'], 'id' ) ) );
		self::assertSame( array( $f1 ), array_map( 'intval', array_column( $overview['warnings'], 'id' ) ) );

		$html = DashboardPage::html( $overview, null );

		preg_match_all( '/\sid="([^"]+)"/', $html, $ids );
		preg_match_all( '/<label for="([^"]+)"/', $html, $labels );
		self::assertCount( 2, preg_grep( '/^ttl-finalize-/', $labels[1] ), 'zwei Formulare «Abschliessen» für f1' );
		// Doppelt ist nur _wpnonce aus wp_nonce_field(): verstecktes Feld ohne Label.
		self::assertSame( array_values( array_unique( $labels[1] ) ), $labels[1], 'jedes Label zeigt auf ein eigenes Feld' );
		foreach ( $labels[1] as $for ) {
			self::assertSame( 1, count( array_keys( $ids[1], $for, true ) ), 'Label zeigt auf genau ein Feld: ' . $for );
		}
	}
}
