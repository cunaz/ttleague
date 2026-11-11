<?php
/**
 * Seite zum Nachtragen des Gruppenzettels (Seite «Resultat erfassen» mit ?rl-gruppe=).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\RankingTournament;

use TTLeague\RankingTournament\EntryPage;
use TTLeague\RankingTournament\RankingTournament;
use TTLeague\RankingTournament\SheetForm;

final class EntryPageTest extends RlTestCase {

	private int $round = 0;

	private int $group = 0;

	public function set_up(): void {
		parent::set_up();
		[ $this->round, $this->group ] = $this->published_group();
	}

	public function test_block_shows_the_fixture_entry_without_the_parameter(): void {
		wp_set_current_user( $this->users['h1'] );
		$html = do_blocks( '<!-- wp:ttleague/result-entry /-->' );
		self::assertStringNotContainsString( 'ttl-rl-entry', $html );
		self::assertStringContainsString( 'Nummer einer Begegnung', $html );
	}

	public function test_guest_is_sent_to_the_login(): void {
		$html = $this->page();
		self::assertStringContainsString( 'ttl-rl-entry', $html );
		self::assertStringContainsString( 'Bitte melde dich an.', $html );
		self::assertStringContainsString( 'redirect_to=' . rawurlencode( RankingTournament::entry_url( $this->group ) ), $html );
		self::assertStringNotContainsString( '<form', $html );
		self::assertStringNotContainsString( 'Seefelder', $html );
	}

	public function test_accounts_without_right_see_a_notice_only(): void {
		foreach ( array( 'h2', 'nobody' ) as $key ) {
			wp_set_current_user( $this->users[ $key ] );
			$html = $this->page();
			self::assertStringContainsString( 'Den Gruppenzettel trägt der Gruppenchef nach', $html );
			self::assertStringNotContainsString( '<form', $html );
		}
		wp_set_current_user( $this->users['h1'] );
		self::assertStringContainsString( 'Diese Gruppe gibt es nicht.', $this->page( 999999 ) );
	}

	public function test_chief_sees_the_form(): void {
		wp_set_current_user( $this->users['h1'] );
		$html = $this->page();

		self::assertStringContainsString( 'Kategorie 1, Gruppe 1', $html );
		self::assertStringContainsString( 'Halle Wollishofen', $html );
		self::assertStringContainsString( 'action="http://example.org/wp-admin/admin-post.php"', $html );
		self::assertStringContainsString( 'name="action" value="' . EntryPage::ACTION . '"', $html );
		self::assertSame( 4, substr_count( $html, 'name="status[' ) );
		self::assertSame( 6, substr_count( $html, 'name="result[' ), 'Sechs Partien in der Vierergruppe.' );
		self::assertStringContainsString( 'Spieler1 Seefelder – Spieler2 Seefelder', $html );
		self::assertStringContainsString( '<option value="3:2">3:2</option>', $html );
		self::assertStringNotContainsString( 'name="points[', $html, 'Satzpunkte nur mit set_points_enabled.' );
		self::assertStringContainsString( 'Gruppenzettel speichern', $html );
		self::assertSame( array( '3:0', '3:1', '3:2', '2:3', '1:3', '0:3' ), SheetForm::results( 3 ) );
	}

	public function test_chief_saves_the_form_without_javascript(): void {
		wp_set_current_user( $this->users['h1'] );
		$location = $this->submit( $this->form_fields() );

		self::assertSame( RankingTournament::entry_url( $this->group ), $location );
		$group = RankingTournament::repository()->group( $this->group );
		self::assertSame( $this->users['h1'], $group['entered_by'] );
		self::assertCount( 6, $group['games'] );
		self::assertSame( array( 1, 2, 3, 4 ), array_column( $group['members'], 'rank_no' ) );
		$html = $this->page();
		self::assertStringContainsString( 'Gruppenzettel gespeichert.', $html );
		self::assertStringContainsString( 'Rangliste', $html );
		self::assertStringContainsString( 'Änderung speichern', $html );
		self::assertStringContainsString( '<option value="3:0" selected=\'selected\'>3:0</option>', $html, 'Der erfasste Stand ist vorausgewählt.' );
	}

	public function test_invalid_form_keeps_the_input_and_shows_the_errors(): void {
		wp_set_current_user( $this->users['h1'] );
		$fields              = $this->form_fields();
		$fields['result'][1] = '2:3';
		$fields['result'][2] = '';
		$fields['result'][3] = '';
		$this->submit( $fields );

		self::assertNull( RankingTournament::repository()->group( $this->group )['entered_at'] );
		$html = $this->page();
		self::assertStringContainsString( 'ttl-notice--error', $html );
		self::assertSame( 2, substr_count( $html, 'Das Resultat fehlt.' ) );
		self::assertStringContainsString( '<option value="2:3" selected=\'selected\'>2:3</option>', $html, 'Die Eingabe bleibt stehen.' );
		// Beim nächsten Aufruf ist der Entwurf weg.
		self::assertStringNotContainsString( 'selected=\'selected\'>2:3', $this->page() );
	}

	public function test_form_needs_nonce_and_right(): void {
		// Mitspieler ohne Recht, mit gültigem Nonce.
		wp_set_current_user( $this->users['h2'] );
		$this->post( EntryPage::ACTION . '_' . $this->group, $this->form_fields() );
		self::assertSame( 403, $this->die_of( array( EntryPage::class, 'handle' ) )->getCode() );

		// Gruppenchef mit dem Nonce einer anderen Gruppe.
		wp_set_current_user( $this->users['h1'] );
		$this->post( EntryPage::ACTION . '_' . ( $this->group + 1 ), $this->form_fields() );
		self::assertSame( 403, $this->die_of( array( EntryPage::class, 'handle' ) )->getCode() );
		self::assertNull( RankingTournament::repository()->group( $this->group )['entered_at'] );
	}

	public function test_closed_round_is_read_only(): void {
		RankingTournament::entry()->save( $this->group, $this->sheet( $this->group ), $this->tk() );
		wp_set_current_user( $this->users['h1'] );
		$fields = $this->form_fields();
		RankingTournament::rounds()->close( $this->tk(), $this->round );

		$html = $this->page();
		self::assertStringContainsString( 'Die Runde ist abgeschlossen.', $html );
		self::assertStringNotContainsString( '<form', $html );
		self::assertStringContainsString( 'Rangliste', $html );

		// Ein noch offenes Formular lässt sich nicht mehr speichern.
		$fields['result'][1] = '0:3';
		$this->submit( $fields );
		self::assertSame( 3, RankingTournament::repository()->group( $this->group )['games'][0]['sets_a'] );
		self::assertStringContainsString( 'ttl-notice--error', $this->page() );
	}

	public function test_form_fields_become_the_service_payload(): void {
		$payload = SheetForm::payload(
			array(
				'status'    => array(
					5 => 'played',
					6 => 'played',
					7 => 'absent',
				),
				'result'    => array(
					1 => '3:1',
					2 => '',
					3 => ' 0:3 ',
				),
				'points'    => array( 1 => '11:8, 9:11 11:5  11:9' ),
				'next'      => array( 5 => array( '2026-10-20' ) ),
				'next_take' => '1',
				'version'   => 'abc',
			)
		);

		self::assertSame(
			array(
				5 => 'played',
				6 => 'played',
				7 => 'absent',
			),
			$payload['players']
		);
		self::assertSame(
			array(
				array(
					'sequence'   => 1,
					'sets_a'     => '3',
					'sets_b'     => '1',
					'set_points' => array( array( '11', '8' ), array( '9', '11' ), array( '11', '5' ), array( '11', '9' ) ),
				),
				array(
					'sequence' => 3,
					'sets_a'   => '0',
					'sets_b'   => '3',
				),
			),
			$payload['games']
		);
		// Sperrdaten für alle, die gespielt haben; ohne Häkchen «an jedem Abend frei».
		self::assertSame(
			array(
				5 => array( '2026-10-20' ),
				6 => array(),
			),
			$payload['next_blocked']
		);
		self::assertSame( 'abc', $payload['version'] );
		self::assertArrayNotHasKey( 'next_blocked', SheetForm::payload( array( 'status' => array( 5 => 'played' ) ) ) );
	}

	/**
	 * Felder des Formulars: alle haben gespielt, die erstgenannte Person gewinnt 3:0.
	 *
	 * @return array<string,mixed>
	 */
	private function form_fields(): array {
		$entry  = RankingTournament::entry()->entry( $this->group, $this->tk() );
		$fields = array(
			'group'   => (string) $this->group,
			'version' => $entry['version'],
			'status'  => array(),
			'result'  => array(),
		);
		foreach ( $entry['players'] as $player ) {
			$fields['status'][ $player['player_id'] ] = 'played';
		}
		foreach ( $entry['order'] as $game ) {
			$fields['result'][ $game['sequence'] ] = '3:0';
		}
		return $fields;
	}

	/**
	 * @param array<string,mixed> $fields Formularfelder.
	 */
	private function submit( array $fields ): string {
		$this->post( EntryPage::ACTION . '_' . $this->group, $fields );
		return $this->redirect_of( array( EntryPage::class, 'handle' ) );
	}

	private function page( ?int $group = null ): string {
		$_GET[ RankingTournament::PARAM ] = (string) ( $group ?? $this->group );
		return do_blocks( '<!-- wp:ttleague/result-entry /-->' );
	}
}
