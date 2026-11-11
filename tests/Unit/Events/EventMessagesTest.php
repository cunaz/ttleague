<?php
/**
 * Texte der Benachrichtigungen zu Anlässen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TTLeague\Events\EventMessages;
use TTLeague\Notifications\EmailChannel;
use TTLeague\Notifications\Recipient;
use TTLeague\Tests\Integration\Events\FakeLinks;

final class EventMessagesTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__ ) . '/Frontend/Stubs/WordPress.php';
	}

	/**
	 * @param array<string,mixed> $overrides Abweichende Felder.
	 * @return array<string,mixed>
	 */
	private static function event( array $overrides = array() ): array {
		return $overrides + array(
			'id'               => 7,
			'season_id'        => 1,
			'type'             => 'tournament',
			'title'            => 'Plauschturnier',
			'starts_at'        => '2026-11-20 19:30:00',
			'ends_at'          => null,
			'location'         => 'Turnhalle Seefeld',
			'deadline'         => null,
			'max_participants' => null,
			'post_id'          => 42,
			'status'           => 'open',
			'created_by'       => 1,
		);
	}

	public function test_describe_names_date_time_and_place(): void {
		self::assertSame( 'Plauschturnier (Fr, 20.11.2026, 19:30, Turnhalle Seefeld)', EventMessages::describe( self::event() ) );
		self::assertSame( 'Plauschturnier (Fr, 20.11.2026, 19:30)', EventMessages::describe( self::event( array( 'location' => null ) ) ) );
	}

	public function test_all_messages_use_swiss_spelling_and_the_event_type(): void {
		$messages = new EventMessages( new FakeLinks() );
		$all      = array(
			$messages->registered( self::event(), 3, 'registered', null ),
			$messages->registered( self::event(), 3, 'registered', 'Charlotte Chef' ),
			$messages->registered( self::event(), 3, 'waitlist', null ),
			$messages->registered( self::event(), 3, 'waitlist', 'Charlotte Chef' ),
			$messages->unregistered( self::event(), 3, null ),
			$messages->unregistered( self::event(), 3, 'Charlotte Chef' ),
			$messages->promoted( self::event(), 3 ),
			$messages->cancelled( self::event() ),
		);

		foreach ( $all as $notification ) {
			$text = $notification->subject . ' ' . implode( ' ', $notification->paragraphs ) . ' ' . implode( ' ', $notification->push );
			self::assertSame( 'event_registration', $notification->type );
			self::assertStringNotContainsString( 'ß', $text );
			self::assertStringContainsString( 'Plauschturnier', $notification->subject );
			self::assertSame( 'https://liga.example.org/?p=42', $notification->push['url'] );
			self::assertLessThanOrEqual( 180, mb_strlen( $notification->push['body'] ) );
		}
		self::assertSame( 'Kannst du nicht teilnehmen, melde dich bitte wieder ab.', $all[0]->paragraphs[1], 'ohne Anmeldeschluss' );
		self::assertSame( 'Charlotte Chef hat dich für Plauschturnier (Fr, 20.11.2026, 19:30, Turnhalle Seefeld) angemeldet. Die Höchstzahl ist erreicht, du stehst auf der Warteliste.', $all[3]->paragraphs[0] );
		self::assertSame( 'high', $all[7]->urgency );
		self::assertSame( array( 'registered', 'registered', 'waitlist', 'waitlist', '', '', '', '' ), array_map( static fn( $n ): string => $n->ref_key, $all ) );
	}

	public function test_the_mail_escapes_title_and_name(): void {
		$messages     = new EventMessages( new FakeLinks() );
		$notification = $messages->registered( self::event( array( 'title' => 'Turnier <b>fett</b>' ) ), 3, 'registered', 'Charlotte <Chef>' );

		$message = EmailChannel::message( new Recipient( 5, null, 'h1@example.org', 'Hans Muster', 'Hans' ), $notification );

		self::assertSame( 'Anmeldung bestätigt: Turnier <b>fett</b>', $message->subject );
		self::assertSame( 'notification_event_registered', $message->type );
		self::assertStringContainsString( 'Charlotte &lt;Chef&gt; hat dich für Turnier &lt;b&gt;fett&lt;/b&gt;', (string) $message->html );
		self::assertStringNotContainsString( '<b>fett</b>', (string) $message->html );
		self::assertStringContainsString( 'Zum Anlass: https://liga.example.org/?p=42', $message->text );
	}

	public function test_a_long_title_is_shortened_for_push(): void {
		$messages     = new EventMessages( new FakeLinks() );
		$notification = $messages->cancelled( self::event( array( 'title' => str_repeat( 'Sehr langer Titel ', 9 ) ) ) );

		self::assertSame( 80, mb_strlen( $notification->push['title'] ) );
		self::assertStringEndsWith( '…', $notification->push['title'] );
		self::assertSame( 180, mb_strlen( $notification->push['body'] ) );
	}
}
