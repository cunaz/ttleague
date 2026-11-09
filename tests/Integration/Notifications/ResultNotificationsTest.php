<?php
/**
 * Sofortmeldungen zu Erfassung, Änderung, Einsprache und Entscheiden der TK.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Notifications;

use TTLeague\Accounts\Preferences;
use TTLeague\Domain\Match\GameOrder;
use TTLeague\Notifications\EventTypes;

final class ResultNotificationsTest extends NotificationTestCase {

	private function event_id( int $fixture_id, string $type ): int {
		return (int) $this->db->get_var(
			"SELECT MAX(id) FROM {$this->db->table( 'fixture_event' )} WHERE fixture_id = %d AND type = %s",
			array( $fixture_id, $type )
		);
	}

	public function test_entry_asks_the_away_side_to_confirm_but_not_the_person_who_entered(): void {
		$this->service()->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );

		self::assertSame( array( $this->p( 'captain_a1' ), $this->p( 'chef_away' ) ), $this->email->recipients( 'confirm_request' ) );
		$sent = $this->email->sent[0];
		self::assertSame( 'Bitte bestätigen: Seefeld 1 – Wollishofen 1', $sent['subject'] );
		self::assertSame( array( 'fixture_event', $this->event_id( $this->id['f1'], 'entered' ) ), array( $sent['ref_type'], $sent['ref_id'] ) );
		self::assertStringStartsWith( 'Hanna Heim hat das Resultat der Begegnung Seefeld 1 – Wollishofen 1', $sent['notification']->paragraphs[0] );
		self::assertStringEndsWith( 'erfasst: 6:4.', $sent['notification']->paragraphs[0] );
	}

	public function test_each_change_asks_again_as_changed_result(): void {
		$service = $this->service();
		$service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );
		$this->clock->advance( '+20 minutes' );
		$service->submit( $this->id['f1'], $this->seven_three(), $this->actor['chef_home'] );

		self::assertSame( array( $this->p( 'captain_a1' ), $this->p( 'chef_away' ), $this->p( 'captain_a1' ), $this->p( 'chef_away' ) ), $this->email->recipients( 'confirm_request' ) );
		$changed = $this->email->sent[2];
		self::assertSame( 'Geändertes Resultat bestätigen: Seefeld 1 – Wollishofen 1', $changed['subject'] );
		self::assertSame( $this->event_id( $this->id['f1'], 'edited' ), $changed['ref_id'] );
		self::assertStringStartsWith( 'Charlotte Chef hat das Resultat', $changed['notification']->paragraphs[0] );
		self::assertStringEndsWith( 'Neues Resultat: 7:3.', $changed['notification']->paragraphs[0] );
	}

	public function test_club_derby_entered_by_the_tk_chef_reaches_only_the_away_captain(): void {
		$payload                             = $this->six_four();
		$payload['lineup']['away']           = array(
			'X' => $this->id['h4'],
			'Y' => $this->id['h5'],
			'Z' => $this->id['h6'],
		);
		$payload['games'][6]['away_players'] = array( $this->id['h4'], $this->id['h5'] );
		$this->service()->submit( $this->id['derby'], $payload, $this->actor['chef_home'] );

		self::assertSame( array( $this->p( 'captain_h2' ) ), $this->email->recipients( 'confirm_request' ), 'der TK-Chef vertritt beide Seiten, hat aber selbst erfasst' );
	}

	public function test_entry_by_the_tk_without_person_reaches_the_away_side(): void {
		$this->service()->submit( $this->id['f1'], $this->six_four(), $this->actor['tk_account'] );
		self::assertSame( array( $this->p( 'captain_a1' ), $this->p( 'chef_away' ) ), $this->email->recipients( 'confirm_request' ) );
		self::assertStringStartsWith( 'TK-Konto hat das Resultat', $this->email->sent[0]['notification']->paragraphs[0] );
	}

	public function test_cup_entry_reaches_the_players_of_the_away_entry(): void {
		$games = array();
		foreach ( GameOrder::cup() as $i => $slot ) {
			$game = array(
				'sequence'  => $slot['sequence'],
				'status'    => $i < 3 ? 'played' : 'not_played',
				'sets_home' => 3,
				'sets_away' => 1,
			);
			if ( GameOrder::DOUBLE === $slot['type'] && $i < 3 ) {
				$game['home_players'] = array( $this->id['h1'], $this->id['h2'] );
				$game['away_players'] = array( $this->id['a1'], $this->id['a2'] );
			}
			$games[] = $i < 3 ? $game : array(
				'sequence' => $slot['sequence'],
				'status'   => 'not_played',
			);
		}
		$actor = new \TTLeague\Results\Actor( 120, $this->person['cup_h1'], false );
		$this->service()->submit(
			$this->id['cup'],
			array(
				'lineup' => array(
					'home' => array(
						'A' => $this->id['h1'],
						'B' => $this->id['h2'],
					),
					'away' => array(
						'Y' => $this->id['a1'],
						'Z' => $this->id['a2'],
					),
				),
				'games'  => $games,
			),
			$actor
		);

		self::assertSame( array( $this->p( 'cup_a1' ), $this->p( 'cup_a2' ), $this->p( 'chef_away' ) ), $this->email->recipients( 'confirm_request' ) );
	}

	public function test_objection_reaches_the_tk_and_the_home_side(): void {
		$service = $this->service();
		$service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );
		$service->object( $this->id['f1'], $this->actor['captain_a1'], 'Spiel 4 ging an uns, nicht an Seefeld.' );

		self::assertSame(
			array( $this->p( 'tk' ), 'u108', $this->p( 'admin' ), $this->p( 'captain_h1' ), $this->p( 'chef_home' ) ),
			$this->email->recipients( 'result_disputed' ),
			'nicht an die Gastseite, nicht an Administratoren ohne Person'
		);
		$sent = $this->email->sent[2];
		self::assertSame( 'Einsprache: Seefeld 1 – Wollishofen 1', $sent['subject'] );
		self::assertSame( $this->event_id( $this->id['f1'], 'disputed' ), $sent['ref_id'] );
		self::assertSame( 'Begründung: «Spiel 4 ging an uns, nicht an Seefeld.»', $sent['notification']->paragraphs[1] );
		self::assertStringStartsWith( 'Gustav Gast hat gegen das Resultat 6:4', $sent['notification']->paragraphs[0] );
	}

	public function test_decisions_of_the_tk_reach_both_sides(): void {
		$service = $this->service();
		$service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );
		$service->object( $this->id['f1'], $this->actor['captain_a1'], 'Spiel 4 ging an uns, nicht an Seefeld.' );
		$service->decide(
			$this->id['f1'],
			$this->actor['tk'],
			array(
				'action'  => 'finalize',
				'comment' => 'Matchblatt geprüft.',
			)
		);
		$both = array( $this->p( 'captain_h1' ), $this->p( 'chef_home' ), $this->p( 'captain_a1' ), $this->p( 'chef_away' ) );
		self::assertSame( $both, $this->email->recipients( 'result_decided' ) );
		self::assertSame( 'Resultat abgeschlossen: Seefeld 1 – Wollishofen 1', $this->email->sent[ count( $this->email->sent ) - 1 ]['subject'] );

		$service->decide(
			$this->id['f1'],
			$this->actor['tk'],
			array(
				'action' => 'forfeit',
				'winner' => 'away',
			)
		);
		$service->decide( $this->id['f1'], $this->actor['tk'], array( 'action' => 'reset' ) );
		self::assertCount( 12, $this->email->recipients( 'result_decided' ), 'jeder Entscheid ist ein eigener Bezug' );
		$subjects = array_column( array_slice( $this->email->sent, -8 ), 'subject' );
		self::assertSame( 'Forfait: Seefeld 1 – Wollishofen 1', $subjects[0] );
		self::assertSame( 'Resultat zurückgesetzt: Seefeld 1 – Wollishofen 1', $subjects[4] );
		self::assertStringEndsWith( 'für Wollishofen 1.', $this->email->sent[ count( $this->email->sent ) - 8 ]['notification']->paragraphs[0] );
	}

	public function test_the_deciding_tk_member_is_left_out_when_also_on_a_side(): void {
		$service = $this->service();
		$service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );
		// Konto 110 wandert von der Person «admin» zum TK-Chef des Gasts (person.user_id ist eindeutig).
		$this->db->update( 'person', array( 'user_id' => null ), array( 'id' => $this->person['admin'] ) );
		$this->db->update( 'person', array( 'user_id' => 110 ), array( 'id' => $this->person['chef_away'] ) );
		$service->decide(
			$this->id['f1'],
			new \TTLeague\Results\Actor( 110, $this->person['chef_away'], true ),
			array( 'action' => 'finalize' )
		);
		self::assertSame( array( $this->p( 'captain_h1' ), $this->p( 'chef_home' ), $this->p( 'captain_a1' ) ), $this->email->recipients( 'result_decided' ) );
	}

	public function test_confirmation_by_the_tk_is_reported_but_not_by_the_away_side(): void {
		$service = $this->service();
		$service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );
		$service->confirm( $this->id['f1'], $this->actor['captain_a1'] );
		self::assertSame( array(), $this->email->recipients( 'result_decided' ) );

		$service->decide( $this->id['f1'], $this->actor['tk'], array( 'action' => 'reset' ) );
		$service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );
		$service->decide( $this->id['f1'], $this->actor['tk_account'], array( 'action' => 'confirm' ) );
		$confirmed = array_values( array_filter( $this->email->sent, static fn( array $s ): bool => 'Resultat bestätigt: Seefeld 1 – Wollishofen 1' === $s['subject'] ) );
		self::assertSame( array( $this->p( 'captain_h1' ), $this->p( 'chef_home' ), $this->p( 'captain_a1' ), $this->p( 'chef_away' ) ), array_column( $confirmed, 'to' ) );
		self::assertSame( $this->event_id( $this->id['f1'], 'confirmed' ), $confirmed[0]['ref_id'] );
	}

	public function test_preferences_apply_to_immediate_messages(): void {
		( new Preferences( $this->db ) )->set( $this->person['chef_away'], EventTypes::CONFIRM_REQUEST, false, false );
		( new Preferences( $this->db ) )->set( $this->person['captain_a1'], EventTypes::CONFIRM_REQUEST, true, true );
		$this->push->push_people = array( $this->person['captain_a1'] );

		$this->service()->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );

		self::assertSame( array( $this->p( 'captain_a1' ) ), $this->email->recipients( 'confirm_request' ) );
		self::assertSame( array( $this->p( 'captain_a1' ) ), $this->push->recipients( 'confirm_request' ) );
	}
}
