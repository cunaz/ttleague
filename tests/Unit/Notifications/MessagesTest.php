<?php
/**
 * Texte der Benachrichtigungen und Aufbau der E-Mail.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Infrastructure\Mail\Message;
use TTLeague\Notifications\EmailChannel;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Messages;
use TTLeague\Notifications\Notification;
use TTLeague\Notifications\Recipient;
use TTLeague\Results\FixtureContext;
use TTLeague\Tests\Integration\Notifications\FakeLinks;

final class MessagesTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	private static function ctx( string $type = 'MM', ?string $scheduled = '2026-11-10 20:00:00', string $home = 'Seefeld 1' ): FixtureContext {
		return new FixtureContext(
			7,
			$type,
			'B',
			'MM' === $type ? 'Gruppe B' : 'Mannschaftscup A',
			1,
			'2026-27',
			'active',
			new SeasonRules(),
			'provisional',
			$scheduled,
			array(
				'team_id'  => 1,
				'entry_id' => null,
				'club_id'  => 1,
				'name'     => $home,
			),
			array(
				'team_id'  => 2,
				'entry_id' => null,
				'club_id'  => 2,
				'name'     => 'Wollishofen 1',
			),
			6,
			4,
			101,
			'2026-11-10 21:30:00',
			null,
			null,
			null
		);
	}

	private static function messages(): Messages {
		return new Messages( new FakeLinks() );
	}

	private static function mail( Notification $notification, string $salutation = 'Gustav' ): Message {
		return EmailChannel::message( new Recipient( 3, 102, 'gustav.gast@example.org', 'Gustav Gast', $salutation ), $notification );
	}

	public function test_confirm_request_names_the_fixture_and_links_entry_and_settings(): void {
		$n = self::messages()->confirm_request( self::ctx(), 55, false, 'Hanna Heim' );

		self::assertSame( EventTypes::CONFIRM_REQUEST, $n->type );
		self::assertSame( 'confirm_request', $n->event );
		self::assertSame( array( 'fixture_event', 55, '' ), array( $n->ref_type, $n->ref_id, $n->ref_key ) );
		self::assertSame( 'Bitte bestätigen: Seefeld 1 – Wollishofen 1', $n->subject );
		self::assertSame( 'Hanna Heim hat das Resultat der Begegnung Seefeld 1 – Wollishofen 1 (Meisterschaft, Gruppe B, Di, 10.11.2026, 20:00) erfasst: 6:4.', $n->paragraphs[0] );
		self::assertStringContainsString( 'innert 7 Tagen Einsprache', $n->paragraphs[1] );
		self::assertSame( 'https://liga.example.org/erfassen/?begegnung=7', $n->action['url'] );
		self::assertSame( 'https://liga.example.org/erfassen/?begegnung=7', $n->push['url'] );
		self::assertSame( 'ttleague-fixture-7', $n->push['tag'] );
		self::assertSame( 'high', $n->urgency );

		$mail = self::mail( $n );
		self::assertSame( 'gustav.gast@example.org', $mail->to );
		self::assertStringStartsWith( "Hallo Gustav\n\nHanna Heim hat das Resultat", $mail->text );
		self::assertStringContainsString( 'Resultat prüfen: https://liga.example.org/erfassen/?begegnung=7', $mail->text );
		self::assertStringContainsString( 'in «Mein Bereich» ein: https://liga.example.org/mein-bereich/', $mail->text );
		self::assertStringContainsString( '<p>Hallo Gustav</p>', (string) $mail->html );
		self::assertStringContainsString( 'href="https://liga.example.org/mein-bereich/"', (string) $mail->html );
		self::assertSame( 'notification_confirm_request', $mail->type );
	}

	public function test_edited_result_has_its_own_wording(): void {
		$n = self::messages()->confirm_request( self::ctx(), 56, true, null );
		self::assertSame( 'Geändertes Resultat bestätigen: Seefeld 1 – Wollishofen 1', $n->subject );
		self::assertStringStartsWith( 'Seefeld 1 hat das Resultat der Begegnung', $n->paragraphs[0], 'ohne Namen das Heimteam' );
		self::assertStringEndsWith( 'geändert. Neues Resultat: 6:4.', $n->paragraphs[0] );
		self::assertSame( 'Resultat geändert: Seefeld 1 – Wollishofen 1', $n->push['title'] );
	}

	public function test_html_is_escaped(): void {
		$n    = self::messages()->result_disputed( self::ctx( 'MM', '2026-11-10 20:00:00', 'Seefeld <b>1</b>' ), 9, 'Spiel 3 war <script>x</script> anders', 'Gustav Gast' );
		$html = (string) self::mail( $n )->html;
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<b>1</b>', $html );
		self::assertStringContainsString( 'Seefeld &lt;b&gt;1&lt;/b&gt;', $html );
		self::assertStringContainsString( 'Begründung: «Spiel 3 war &lt;script&gt;x&lt;/script&gt; anders»', $html );
		self::assertStringContainsString( 'Begründung: «Spiel 3 war <script>x</script> anders»', self::mail( $n )->text, 'Textfassung bleibt unverändert' );
	}

	public function test_texts_use_swiss_spelling(): void {
		$messages = self::messages();
		$ctx      = self::ctx();
		$all      = array(
			$messages->confirm_request( $ctx, 1, false, null ),
			$messages->confirm_request( $ctx, 1, true, null ),
			$messages->confirm_reminder( $ctx, 1, 3 ),
			$messages->confirm_overdue( $ctx, 1 ),
			$messages->match_reminder(
				$ctx,
				array(
					'name'      => 'Turnhalle',
					'street'    => 'Musterstrasse 1',
					'zip'       => '8000',
					'city'      => 'Musterstadt',
					'open_time' => '19:30:00',
				)
			),
			$messages->result_missing( $ctx, '2026-11-12 20:00:00' ),
			$messages->result_missing( $ctx, '2026-11-12 20:00:00', true ),
			$messages->result_missing_tk( $ctx ),
			$messages->forfeit_proposal( $ctx ),
			$messages->result_disputed( $ctx, 1, 'Grund', null ),
			$messages->tk_digest( '2026-11-10', array( 'missing' => array( $ctx ) ) ),
		);
		foreach ( array( 'confirm', 'finalize', 'correct', 'forfeit', 'reset' ) as $action ) {
			$all[] = $messages->result_decided( $ctx, 1, $action, 'Gemäss Matchblatt', 'home' );
		}
		foreach ( $all as $n ) {
			$mail = self::mail( $n );
			self::assertStringNotContainsString( 'ß', $mail->subject . $mail->text, $n->event );
			self::assertStringContainsString( 'https://liga.example.org/', $mail->text, $n->event );
			self::assertNotSame( '', $n->push['title'], $n->event );
			self::assertLessThanOrEqual( 180, mb_strlen( $n->push['body'] ), $n->event );
		}
	}

	public function test_match_reminder_with_venue_and_open_time(): void {
		$venue = array(
			'name'      => 'Turnhalle Seefeld',
			'street'    => 'Musterstrasse 1',
			'zip'       => '8000',
			'city'      => 'Musterstadt',
			'open_time' => '19:30:00',
		);
		$n     = self::messages()->match_reminder( self::ctx(), $venue );
		self::assertSame( 'Spiel am Di, 10.11.2026: Seefeld 1 – Wollishofen 1', $n->subject );
		self::assertSame( array( 'fixture', 7, '2026-11-10 20:00' ), array( $n->ref_type, $n->ref_id, $n->ref_key ) );
		self::assertSame( 'Am Di, 10.11.2026 um 20:00 spielt Seefeld 1 gegen Wollishofen 1 (Meisterschaft, Gruppe B).', $n->paragraphs[0] );
		self::assertStringContainsString( "Spiellokal: Turnhalle Seefeld, Musterstrasse 1, 8000 Musterstadt\nHalle offen ab 19:30\nKarte: https://www.openstreetmap.org/search?query=", $n->paragraphs[1] );
		self::assertSame( 'Spiel am Di, 10.11.2026, 20:00', $n->push['title'] );
		self::assertSame( 'Seefeld 1 – Wollishofen 1, Turnhalle Seefeld', $n->push['body'] );

		$open = self::messages()->match_reminder( self::ctx( 'CUP', '2026-11-12 00:00:00' ), null );
		self::assertSame( 'Am Do, 12.11.2026 spielt Seefeld 1 gegen Wollishofen 1 (Mannschaftscup A). Die Anspielzeit ist noch offen.', $open->paragraphs[0] );
	}

	public function test_decisions_and_reminders(): void {
		$messages = self::messages();
		$ctx      = self::ctx();
		self::assertSame( 'Die TK hat die Begegnung Seefeld 1 – Wollishofen 1 (Meisterschaft, Gruppe B, Di, 10.11.2026, 20:00) forfait gewertet: 6:4 für Wollishofen 1.', $messages->result_decided( $ctx, 3, 'forfeit', null, 'away' )->paragraphs[0] );
		self::assertSame( 'Bemerkung der TK: «Gemäss Matchblatt»', $messages->result_decided( $ctx, 3, 'correct', 'Gemäss Matchblatt', null )->paragraphs[1] );
		$reset = $messages->result_decided( $ctx, 3, 'reset', null, null );
		self::assertSame( 'Resultat zurückgesetzt: Seefeld 1 – Wollishofen 1', $reset->subject );
		self::assertSame( 'Resultat zurückgesetzt: Seefeld 1 – Wollishofen 1', $reset->push['title'] );
		self::assertSame( 'Die Begegnung ist wieder offen, die Heimmannschaft erfasst das Resultat neu.', $reset->push['body'] );
		self::assertSame( 'Forfait: 6:4 für Wollishofen 1.', $messages->result_decided( $ctx, 3, 'forfeit', null, 'away' )->push['body'] );

		$reminder = $messages->confirm_reminder( $ctx, 12, 3 );
		self::assertSame( array( 'confirm_reminder', 'fixture_event', 12, '3' ), array( $reminder->event, $reminder->ref_type, $reminder->ref_id, $reminder->ref_key ) );
		self::assertStringContainsString( 'wartet seit 3 Tagen auf eure Bestätigung', $reminder->paragraphs[0] );
		self::assertSame( EventTypes::CONFIRM_REMINDER, $messages->confirm_overdue( $ctx, 12 )->type );
		self::assertSame( 'Bitte erfasse es bis Do, 12.11.2026, 20:00 (48 Stunden nach Anspielzeit).', $messages->result_missing( $ctx, '2026-11-12 20:00:00' )->paragraphs[1] );
		self::assertSame( EventTypes::RESULT_MISSING, $messages->forfeit_proposal( $ctx )->type );
	}

	public function test_digest_lists_open_points_with_links(): void {
		$ctx  = self::ctx();
		$n    = self::messages()->tk_digest(
			'2026-11-26',
			array(
				'missing'  => array(),
				'forfeit'  => array( $ctx ),
				'overdue'  => array( $ctx ),
				'disputed' => array( $ctx ),
			)
		);
		$mail = self::mail( $n, 'Tamara' );
		self::assertSame( array( 'tk_digest', 'day', 20261126 ), array( $n->event, $n->ref_type, $n->ref_id ) );
		self::assertSame( 'TK: 3 offene Punkte am Do, 26.11.2026', $n->subject );
		self::assertSame( array( 'Forfait-Vorschlag, 16 Tage ohne Resultat (1)', 'Bestätigung durch die TK nötig, seit 7 Tagen provisorisch (1)', 'Einsprachen (1)' ), array_column( $n->lists, 'heading' ) );
		self::assertSame( 'Di, 10.11.2026, 20:00 · Seefeld 1 – Wollishofen 1 (Gruppe B) · 6:4', $n->lists[2]['items'][0]['text'] );
		self::assertStringContainsString( "- Di, 10.11.2026, 20:00 · Seefeld 1 – Wollishofen 1 (Gruppe B)\n  https://liga.example.org/erfassen/?begegnung=7", $mail->text );
		self::assertStringContainsString( '<li style="margin:0 0 6px"><a href="https://liga.example.org/erfassen/?begegnung=7">', (string) $mail->html );
		self::assertNull( $n->action );
	}
}
