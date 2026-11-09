<?php
/**
 * Web Push: Abos speichern, versenden, ungültige Abos löschen, Rückfall auf E-Mail.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Notifications;

use TTLeague\Accounts\Preferences;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\NotificationLog;
use TTLeague\Notifications\Notifier;
use TTLeague\Notifications\Push\P256;
use TTLeague\Notifications\Push\PushChannel;
use TTLeague\Notifications\Push\Subscriptions;
use TTLeague\Notifications\Push\Transport;
use TTLeague\Notifications\Push\Vapid;
use TTLeague\Notifications\Push\WebPush;
use TTLeague\Tests\Unit\Notifications\Push\PushTestKit;

final class PushChannelTest extends NotificationTestCase {

	private Subscriptions $subscriptions;

	/** @var object{requests:list<array{url:string,headers:array<string,string>,body:string}>,status:array<string,int>} */
	private object $transport;

	private PushChannel $channel;

	protected function setUp(): void {
		if ( ! P256::supported() ) {
			self::markTestSkipped( 'OpenSSL ohne P-256.' );
		}
		parent::setUp();
		$this->subscriptions = new Subscriptions( $this->db, $this->clock, 3 );
		$this->transport     = new class() implements Transport {
			/** @var list<array{url:string,headers:array<string,string>,body:string}> */
			public array $requests = array();
			/** @var array<string,int> */
			public array $status = array();
			public function post( string $url, array $headers, string $body ): int {
				$this->requests[] = array(
					'url'     => $url,
					'headers' => $headers,
					'body'    => $body,
				);
				return $this->status[ $url ] ?? 201;
			}
		};
		$keys                = Vapid::generate();
		$this->channel       = new PushChannel(
			$this->subscriptions,
			new WebPush( Vapid::from_base64url( $keys['private'] ), $this->transport, $this->clock, 'mailto:liga@example.org' ),
			'https://liga.example.org/icon-192.png'
		);
		$this->notifier      = new Notifier( new Preferences( $this->db ), new NotificationLog( $this->db, $this->clock ), array( $this->channel, $this->email ) );
		( new Preferences( $this->db ) )->set( $this->person['captain_a1'], EventTypes::CONFIRM_REQUEST, false, true );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function subscribe( string $key, string $endpoint ): array {
		$browser = PushTestKit::browser( $endpoint );
		$this->subscriptions->save( $this->person[ $key ], $browser['subscription'] );
		$this->clock->advance( '+1 minute' );
		return $browser;
	}

	private function count_subscriptions(): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'push_subscription' )}" );
	}

	public function test_subscriptions_are_stored_per_person_and_limited(): void {
		$this->subscribe( 'captain_a1', 'https://fcm.googleapis.com/fcm/send/1' );
		$this->subscribe( 'captain_a1', 'https://fcm.googleapis.com/fcm/send/2' );
		$this->subscribe( 'captain_a1', 'https://fcm.googleapis.com/fcm/send/3' );
		$this->subscribe( 'captain_a1', 'https://fcm.googleapis.com/fcm/send/4' );

		$endpoints = array_map( static fn( $s ): string => $s->endpoint, $this->subscriptions->for_person( $this->person['captain_a1'] ) );
		self::assertSame( array( 'https://fcm.googleapis.com/fcm/send/4', 'https://fcm.googleapis.com/fcm/send/3', 'https://fcm.googleapis.com/fcm/send/2' ), $endpoints, 'höchstens drei, das älteste fällt weg' );

		$row = $this->db->get_row( "SELECT * FROM {$this->db->table( 'push_subscription' )} WHERE endpoint = %s", array( 'https://fcm.googleapis.com/fcm/send/4' ) );
		self::assertSame( hash( 'sha256', 'https://fcm.googleapis.com/fcm/send/4' ), $row['endpoint_hash'] );
		self::assertSame( array( 'p256dh', 'auth' ), array_keys( json_decode( (string) $row['keys_json'], true ) ) );
	}

	public function test_same_endpoint_moves_to_the_person_logged_in_now(): void {
		$browser = $this->subscribe( 'captain_a1', 'https://fcm.googleapis.com/fcm/send/geteilt' );
		$this->subscriptions->save( $this->person['chef_away'], $browser['subscription'] );

		self::assertSame( 0, $this->subscriptions->count( $this->person['captain_a1'] ) );
		self::assertSame( 1, $this->subscriptions->count( $this->person['chef_away'] ) );
		self::assertSame( 1, $this->count_subscriptions() );

		self::assertFalse( $this->subscriptions->remove( $this->person['captain_a1'], 'https://fcm.googleapis.com/fcm/send/geteilt' ), 'nur eigene Abos löschen' );
		self::assertTrue( $this->subscriptions->remove( $this->person['chef_away'], 'https://fcm.googleapis.com/fcm/send/geteilt' ) );
		self::assertSame( 0, $this->count_subscriptions() );
	}

	/**
	 * Die Person hat schon so viele Abos, wie erlaubt sind, und übernimmt einen alten Endpunkt
	 * einer anderen Person (gleicher Browser). Das übernommene Abo gilt als neu gemeldet und
	 * bleibt; wegfallen muss das am längsten nicht gemeldete.
	 */
	public function test_taken_over_endpoint_counts_as_newest_at_the_limit(): void {
		$old = $this->subscribe( 'captain_a1', 'https://fcm.googleapis.com/fcm/send/alt' );
		foreach ( array( 1, 2, 3 ) as $i ) {
			$this->subscribe( 'chef_away', 'https://fcm.googleapis.com/fcm/send/chef-' . $i );
		}

		$this->subscriptions->save( $this->person['chef_away'], $old['subscription'] );

		$endpoints = $this->db->get_col( "SELECT endpoint FROM {$this->db->table( 'push_subscription' )} WHERE person_id = %d ORDER BY endpoint", array( $this->person['chef_away'] ) );
		self::assertSame( array( 'https://fcm.googleapis.com/fcm/send/alt', 'https://fcm.googleapis.com/fcm/send/chef-2', 'https://fcm.googleapis.com/fcm/send/chef-3' ), $endpoints );
	}

	public function test_reported_endpoint_stays_at_the_limit_within_the_same_second(): void {
		// Ohne Vorrücken der Uhr: alle Abos mit demselben Zeitpunkt, das übernommene mit der kleinsten ID.
		$old = PushTestKit::browser( 'https://fcm.googleapis.com/fcm/send/alt' );
		$this->subscriptions->save( $this->person['captain_a1'], $old['subscription'] );
		foreach ( array( 1, 2, 3 ) as $i ) {
			$this->subscriptions->save( $this->person['chef_away'], PushTestKit::browser( 'https://fcm.googleapis.com/fcm/send/chef-' . $i )['subscription'] );
		}

		$this->subscriptions->save( $this->person['chef_away'], $old['subscription'] );

		$endpoints = $this->db->get_col( "SELECT endpoint FROM {$this->db->table( 'push_subscription' )} WHERE person_id = %d ORDER BY endpoint", array( $this->person['chef_away'] ) );
		self::assertSame( array( 'https://fcm.googleapis.com/fcm/send/alt', 'https://fcm.googleapis.com/fcm/send/chef-2', 'https://fcm.googleapis.com/fcm/send/chef-3' ), $endpoints );
	}

	public function test_push_is_encrypted_with_headers_and_vapid(): void {
		$browser = $this->subscribe( 'captain_a1', 'https://fcm.googleapis.com/fcm/send/handy' );
		$this->service()->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );

		self::assertCount( 1, $this->transport->requests );
		$request = $this->transport->requests[0];
		self::assertSame( 'https://fcm.googleapis.com/fcm/send/handy', $request['url'] );
		self::assertSame( 'aes128gcm', $request['headers']['Content-Encoding'] );
		self::assertSame( 'application/octet-stream', $request['headers']['Content-Type'] );
		self::assertSame( '86400', $request['headers']['TTL'] );
		self::assertSame( 'high', $request['headers']['Urgency'] );

		$claims = PushTestKit::verify_vapid( $request['headers']['Authorization'] );
		self::assertSame( 'https://fcm.googleapis.com', $claims['aud'] );
		self::assertSame( 'mailto:liga@example.org', $claims['sub'] );
		self::assertSame( $this->clock->now()->getTimestamp() + Vapid::TTL, $claims['exp'] );

		$payload = json_decode( PushTestKit::decrypt( $request['body'], $browser['private'], $browser['subscription']->auth )['plaintext'], true );
		self::assertSame(
			array(
				'title' => 'Bitte bestätigen: Seefeld 1 – Wollishofen 1',
				'body'  => 'Resultat 6:4 erfasst. Bitte prüfen und bestätigen.',
				'url'   => 'https://liga.example.org/erfassen/?begegnung=' . $this->id['f1'],
				'tag'   => 'ttleague-fixture-' . $this->id['f1'],
				'icon'  => 'https://liga.example.org/icon-192.png',
			),
			$payload
		);
		self::assertSame( array( $this->p( 'chef_away' ) ), $this->email->recipients( 'confirm_request' ), 'Gast-Captain nur per Push' );
	}

	public function test_gone_subscriptions_are_deleted_and_email_is_the_fallback(): void {
		$this->subscribe( 'captain_a1', 'https://fcm.googleapis.com/fcm/send/alt' );
		$this->transport->status['https://fcm.googleapis.com/fcm/send/alt'] = 410;

		$this->service()->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );

		self::assertSame( 0, $this->subscriptions->count( $this->person['captain_a1'] ), '410: Abo gelöscht' );
		self::assertSame( array( $this->p( 'captain_a1' ), $this->p( 'chef_away' ) ), $this->email->recipients( 'confirm_request' ), 'Rückfall auf E-Mail' );
		self::assertSame( '0', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'notification_log' )} WHERE channel = 'push'" ) );
	}

	public function test_one_accepted_device_is_enough_and_errors_are_reported(): void {
		$this->subscribe( 'captain_a1', 'https://fcm.googleapis.com/fcm/send/weg' );
		$this->subscribe( 'captain_a1', 'https://fcm.googleapis.com/fcm/send/da' );
		$this->transport->status['https://fcm.googleapis.com/fcm/send/weg'] = 404;

		$this->service()->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );
		self::assertSame( 1, $this->subscriptions->count( $this->person['captain_a1'] ) );
		self::assertSame( array( $this->p( 'chef_away' ) ), $this->email->recipients( 'confirm_request' ) );

		$errors         = array();
		$this->notifier = new Notifier(
			new Preferences( $this->db ),
			new NotificationLog( $this->db, $this->clock ),
			array( $this->channel, $this->email ),
			static function ( \Throwable $e ) use ( &$errors ): void {
				$errors[] = $e->getMessage();
			}
		);
		$this->transport->status['https://fcm.googleapis.com/fcm/send/da'] = 403;
		$this->service()->submit( $this->id['f1'], $this->seven_three(), $this->actor['captain_h1'] );
		self::assertSame( array( 'Push-Dienst antwortete mit Status 403 (1 Abo).' ), $errors );
		self::assertSame( 1, $this->subscriptions->count( $this->person['captain_a1'] ), '403 löscht das Abo nicht' );
		self::assertCount( 3, $this->email->recipients( 'confirm_request' ), 'Rückfall auf E-Mail' );
	}

	public function test_push_needs_a_person_with_subscription(): void {
		$tk = $this->recipients->tk();
		self::assertFalse( $this->channel->reaches( $tk[1] ), 'Konto ohne Person' );
		self::assertFalse( $this->channel->reaches( $tk[0] ) );
		$this->subscribe( 'tk', 'https://web.push.apple.com/tk' );
		self::assertTrue( $this->channel->reaches( $tk[0] ) );
	}

	public function test_long_payloads_are_shortened(): void {
		$long = WebPush::payload(
			array(
				'title' => 'Titel',
				'body'  => str_repeat( 'Einsprache ', 600 ),
				'url'   => 'https://liga.example.org/',
			)
		);
		self::assertLessThanOrEqual( WebPush::MAX_PAYLOAD, strlen( $long ) );
		self::assertStringEndsWith( '…"', substr( $long, 0, strpos( $long, ',"url"' ) ) );
		self::assertSame(
			'{"title":"T"}',
			WebPush::payload(
				array(
					'title' => 'T',
					'body'  => '',
				)
			)
		);
	}
}
