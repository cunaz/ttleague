<?php
/**
 * Abschnitt «Ranglistenturnier» in «Mein Bereich»: Anmeldung, Sperrdaten, Aufgebot.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\RankingTournament;

use TTLeague\Accounts\Services;
use TTLeague\RankingTournament\MyArea;
use TTLeague\RankingTournament\RankingTournament;

final class MyAreaTest extends RlTestCase {

	public function set_up(): void {
		parent::set_up();
		// «Mein Bereich» zeigt die Abschnitte erst nach der Einwilligung.
		$this->db->query( "UPDATE {$this->db->table( 'person' )} SET consent_at = %s", array( '2026-01-01 00:00:00' ) );
	}

	public function test_person_registers_herself(): void {
		wp_set_current_user( $this->users['h1'] );
		$html = $this->my_area();
		self::assertStringContainsString( 'id="ttl-rl"', $html );
		self::assertStringContainsString( 'Du bist nicht angemeldet. Anmeldeschluss ist am', $html );
		self::assertStringContainsString( 'Zum Ranglistenturnier anmelden', $html );

		$location = $this->submit( 'register', $this->league->id['h1'] );

		self::assertSame( 'http://example.org/mein-bereich/#ttl-rl', $location );
		$row = RankingTournament::repository()->participant( $this->competition, $this->league->id['h1'] );
		self::assertSame( 'registered', $row['status'] );
		self::assertSame( 1, $row['category'], 'Klassierung 10 gehört zur Kategorie 1 (ab 9).' );
		self::assertSame( $this->users['h1'], $row['registered_by'] );
		$html = $this->my_area();
		self::assertStringContainsString( 'Angemeldet in Kategorie 1.', $html, 'Rückmeldung nach der Weiterleitung.' );
		self::assertStringContainsString( 'Du bist angemeldet in Kategorie 1.', $html );
		self::assertStringContainsString( 'Vom Ranglistenturnier abmelden', $html );
		self::assertStringNotContainsString( 'Angemeldet in Kategorie 1.', $this->my_area(), 'Die Rückmeldung erscheint einmal.' );

		$this->submit( 'withdraw', $this->league->id['h1'] );
		self::assertSame( 'withdrawn', RankingTournament::repository()->participant( $this->competition, $this->league->id['h1'] )['status'] );
	}

	public function test_tk_chef_registers_players_of_her_club_only(): void {
		wp_set_current_user( $this->users['chef'] );
		$html = $this->my_area();
		self::assertStringContainsString( 'Personen von Seefeld anmelden', $html );
		self::assertStringContainsString( 'Spieler2 Seefelder', $html );
		self::assertStringNotContainsString( 'Wollishofer', $html );

		$this->submit( 'register', $this->league->id['h3'] );
		$row = RankingTournament::repository()->participant( $this->competition, $this->league->id['h3'] );
		self::assertSame( array( 'registered', 2, $this->users['chef'] ), array( $row['status'], $row['category'], $row['registered_by'] ) );
		self::assertStringContainsString( 'angemeldet, Kategorie 2', $this->my_area() );

		// Fremder Club: Die WordPress-Schicht lehnt ab (ttl_manage_club), nichts wird gespeichert.
		$error = $this->die_of( fn() => $this->submit( 'register', $this->league->id['a1'] ) );
		self::assertSame( 403, $error->getCode() );
		self::assertNull( RankingTournament::repository()->participant( $this->competition, $this->league->id['a1'] ) );
	}

	public function test_others_cannot_act_for_a_player(): void {
		// Ohne Funktion für eine andere Person, als Mitspieler für einen Clubkollegen, ohne Person.
		foreach ( array( $this->users['nobody'], $this->users['h2'], $this->account( 'subscriber', 'ohne.person@example.invalid' ) ) as $user ) {
			wp_set_current_user( $user );
			self::assertSame( 403, $this->die_of( fn() => $this->submit( 'register', $this->league->id['h1'] ) )->getCode() );
		}
		// Ungültiger Nonce (der eines anderen Vorgangs).
		wp_set_current_user( $this->users['h1'] );
		$this->post(
			MyArea::ACTION . '_withdraw_' . $this->league->id['h1'] . '_0',
			array(
				'do'     => 'register',
				'player' => (string) $this->league->id['h1'],
				'round'  => '0',
			)
		);
		self::assertSame( 403, $this->die_of( array( MyArea::class, 'handle' ) )->getCode() );
		self::assertNull( RankingTournament::repository()->participant( $this->competition, $this->league->id['h1'] ) );
	}

	public function test_registration_after_the_deadline_is_refused_with_a_message(): void {
		$this->db->update(
			'competition',
			array( 'format_json' => (string) wp_json_encode( array( 'registration_deadline' => wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) ) ) ),
			array( 'id' => $this->competition )
		);
		wp_set_current_user( $this->users['h1'] );
		$html = $this->my_area();
		self::assertStringContainsString( 'Die Anmeldefrist ist abgelaufen', $html );
		self::assertStringNotContainsString( 'Zum Ranglistenturnier anmelden', $html );

		// Auch ein von Hand abgeschicktes Formular meldet nicht an.
		$this->submit( 'register', $this->league->id['h1'] );
		self::assertNull( RankingTournament::repository()->participant( $this->competition, $this->league->id['h1'] ) );
		self::assertStringContainsString( 'ttl-notice--error', $this->my_area() );
	}

	public function test_blocked_dates_for_upcoming_rounds(): void {
		$this->register( array( 'h1' ) );
		$round  = $this->planned_round();
		$monday = $this->monday();
		$thu    = ( new \DateTimeImmutable( $monday ) )->modify( '+3 days' )->format( 'Y-m-d' );
		wp_set_current_user( $this->users['h1'] );
		$html = $this->my_area();
		self::assertStringContainsString( 'Sperrdaten für die 1. Runde', $html );
		self::assertStringContainsString( 'Noch nicht gemeldet.', $html );
		self::assertSame( 4, substr_count( $html, 'name="dates[]"' ), 'Vier Abende der Rundenwoche.' );

		$this->submit( 'blocked', $this->league->id['h1'], $round, array( 'dates' => array( $monday, $thu ) ) );
		$person = $this->league->id['person_h1'];
		self::assertSame( array( $person => array( $monday, $thu ) ), RankingTournament::repository()->blocked_dates( array( $person ), $monday, $thu ) );
		$html = $this->my_area();
		self::assertStringContainsString( 'Sperrdaten gespeichert', $html );
		self::assertStringContainsString( 'Gemeldet.', $html );
		$section = substr( $html, (int) strpos( $html, 'id="ttl-rl"' ) );
		$section = substr( $section, 0, (int) strpos( $section, '</section>' ) );
		self::assertSame( 2, substr_count( $section, "checked='checked'" ), 'Die gemeldeten Abende sind angekreuzt.' );

		// Ein Tag ausserhalb der Woche wird abgelehnt, die Meldung bleibt.
		$this->submit( 'blocked', $this->league->id['h1'], $round, array( 'dates' => array( ( new \DateTimeImmutable( $thu ) )->modify( '+1 day' )->format( 'Y-m-d' ) ) ) );
		self::assertSame( array( $person => array( $monday, $thu ) ), RankingTournament::repository()->blocked_dates( array( $person ), $monday, $thu ) );
		self::assertStringContainsString( 'Sperrdaten müssen Abende der Rundenwoche sein.', $this->my_area() );
	}

	public function test_callup_withdrawal_and_link_to_the_sheet(): void {
		[ $round, $group ] = $this->published_group();

		// Gruppenchef: Aufgebot und Link zum Nachtragen.
		wp_set_current_user( $this->users['h1'] );
		$html = $this->my_area();
		self::assertStringContainsString( 'Aufgebot: 1. Runde, Kategorie 1, Gruppe 1', $html );
		self::assertStringContainsString( 'Halle Wollishofen', $html );
		self::assertStringContainsString( 'Gruppenchef: Spieler1 Seefelder', $html );
		self::assertStringContainsString( 'Spieler2 Wollishofer', $html );
		self::assertStringContainsString( 'href="http://example.org/erfassen/?rl-gruppe=' . $group . '"', $html );
		self::assertStringContainsString( 'Gruppenzettel nachtragen', $html );
		self::assertStringNotContainsString( 'Sperrdaten für die 1. Runde', $html, 'Nach der Freigabe gibt es keine Sperrdaten mehr.' );

		// Mitspieler: Aufgebot ohne Link; er meldet sich für die Runde ab.
		wp_set_current_user( $this->users['a2'] );
		$html = $this->my_area();
		self::assertStringContainsString( 'Aufgebot: 1. Runde', $html );
		self::assertStringNotContainsString( 'rl-gruppe=', $html );
		$this->submit( 'round_withdraw', $this->league->id['a2'], $round );
		$members = array_column( RankingTournament::repository()->group( $group )['members'], 'status', 'player_id' );
		self::assertSame( 'absent', $members[ $this->league->id['a2'] ] );
		self::assertStringContainsString( 'Du bist für diese Runde abgemeldet.', $this->my_area() );

		// Die TK-Chefin des Clubs des Gruppenchefs sieht den Link ebenfalls, Unbeteiligte sehen nichts.
		wp_set_current_user( $this->users['chef'] );
		self::assertStringContainsString( 'rl-gruppe=' . $group, $this->my_area() );
		wp_set_current_user( $this->users['nobody'] );
		self::assertStringNotContainsString( 'id="ttl-rl"', $this->my_area() );
	}

	/**
	 * Schickt ein Formular des Abschnitts ab und liefert das Ziel der Weiterleitung.
	 *
	 * @param array<string,mixed> $fields Weitere Felder.
	 */
	private function submit( string $task, int $player, int $round = 0, array $fields = array() ): string {
		$this->post(
			MyArea::ACTION . '_' . $task . '_' . $player . '_' . $round,
			$fields + array(
				'do'     => $task,
				'player' => (string) $player,
				'round'  => (string) $round,
			)
		);
		return $this->redirect_of( array( MyArea::class, 'handle' ) );
	}

	private function my_area(): string {
		Services::policy()->reset();
		return do_blocks( '<!-- wp:ttleague/my-area /-->' );
	}
}
