<?php
/**
 * Wer erhält Benachrichtigungen zu einer Begegnung?
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\Results\FixtureContext;

/**
 * Empfänger aus den Daten der Saison der Begegnung:
 *
 * - Meisterschaft: Captains des Teams (club_role captain mit team_id) und TK-Chefs des Clubs.
 * - Cup: Spieler der Cup-Mannschaft (Personen von player1 und player2) und TK-Chefs des Clubs.
 * - TK: Konten mit der Rolle ttl_tk; Konten, die das Recht nur als Administrator haben, nur
 *   wenn sie mit einer Person verknüpft sind (sonst erhielte jede Website-Verwaltung alles).
 *
 * Nur aktive Personen; ohne gültige E-Mail-Adresse bleibt die Adresse leer (kein E-Mail-Versand).
 */
final class RecipientResolver {

	public function __construct( private readonly Db $db, private readonly ?TkDirectory $tk = null ) {}

	/**
	 * Empfänger einer Seite (home oder away).
	 *
	 * @return list<Recipient>
	 */
	public function side( FixtureContext $ctx, string $side ): array {
		$info   = $ctx->side( $side );
		$people = array();
		if ( $ctx->is_cup() ) {
			if ( null !== $info['entry_id'] ) {
				$people = $this->db->get_col(
					"SELECT p.person_id FROM {$this->db->table( 'cup_entry' )} ce
					JOIN {$this->db->table( 'player' )} p ON p.id = ce.player1_id OR p.id = ce.player2_id
					WHERE ce.id = %d",
					array( $info['entry_id'] )
				);
			}
		} elseif ( null !== $info['team_id'] ) {
			$people = $this->db->get_col(
				"SELECT person_id FROM {$this->db->table( 'club_role' )} WHERE season_id = %d AND role = 'captain' AND team_id = %d ORDER BY id",
				array( $ctx->season_id, $info['team_id'] )
			);
		}
		if ( null !== $info['club_id'] ) {
			$people = array_merge(
				$people,
				$this->db->get_col(
					"SELECT person_id FROM {$this->db->table( 'club_role' )} WHERE season_id = %d AND role = 'tk_chef' AND club_id = %d ORDER BY id",
					array( $ctx->season_id, $info['club_id'] )
				)
			);
		}
		return $this->persons( array_map( 'intval', $people ) );
	}

	/**
	 * Empfänger mehrerer Seiten, jede Person einmal.
	 *
	 * @param list<string> $sides Seiten.
	 * @return list<Recipient>
	 */
	public function sides( FixtureContext $ctx, array $sides = array( 'home', 'away' ) ): array {
		$all = array();
		foreach ( $sides as $side ) {
			$all = array_merge( $all, $this->side( $ctx, $side ) );
		}
		return self::unique( $all );
	}

	/**
	 * Mitglieder der TK.
	 *
	 * @return list<Recipient>
	 */
	public function tk(): array {
		if ( null === $this->tk ) {
			return array();
		}
		$recipients = array();
		foreach ( $this->tk->accounts() as $account ) {
			$person = $this->db->get_row(
				"SELECT id, user_id, first_name, last_name, email, status FROM {$this->db->table( 'person' )} WHERE user_id = %d ORDER BY id LIMIT 1",
				array( $account['user_id'] )
			);
			if ( null === $person ) {
				if ( ! $account['tk_role'] ) {
					continue;
				}
				$recipients[] = new Recipient( null, $account['user_id'], Recipient::valid_email( $account['email'] ), $account['name'], $account['name'] );
				continue;
			}
			if ( 'active' !== $person['status'] ) {
				continue;
			}
			$recipient    = self::from_row( $person );
			$recipients[] = null !== $recipient->email ? $recipient : new Recipient(
				$recipient->person_id,
				$account['user_id'],
				Recipient::valid_email( $account['email'] ),
				$recipient->name,
				$recipient->salutation
			);
		}
		return self::unique( $recipients );
	}

	/**
	 * Aktive Personen in der Reihenfolge der IDs, ohne Doppel.
	 *
	 * @param list<int> $person_ids Personen.
	 * @return list<Recipient>
	 */
	public function persons( array $person_ids ): array {
		$ids = array_values( array_unique( array_filter( $person_ids, static fn( int $id ): bool => $id > 0 ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$rows       = $this->db->get_results(
			"SELECT id, user_id, first_name, last_name, email, status FROM {$this->db->table( 'person' )}
			WHERE id IN (" . implode( ',', $ids ) . ") AND status = 'active'" // Nur Ganzzahlen.
		);
		$by_id      = array();
		$recipients = array();
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$recipients[] = self::from_row( $by_id[ $id ] );
			}
		}
		return $recipients;
	}

	/**
	 * Jede Person bzw. jedes Konto einmal, Reihenfolge bleibt.
	 *
	 * @param list<Recipient> $recipients Empfänger.
	 * @return list<Recipient>
	 */
	public static function unique( array $recipients ): array {
		$unique = array();
		foreach ( $recipients as $recipient ) {
			$unique[ $recipient->key() ] ??= $recipient;
		}
		return array_values( $unique );
	}

	/**
	 * @param array<string,string|null> $row Zeile aus person.
	 */
	private static function from_row( array $row ): Recipient {
		$first = trim( (string) $row['first_name'] );
		$last  = trim( (string) $row['last_name'] );
		return new Recipient(
			(int) $row['id'],
			null === $row['user_id'] || (int) $row['user_id'] <= 0 ? null : (int) $row['user_id'],
			Recipient::valid_email( $row['email'] ),
			trim( $first . ' ' . $last ),
			'' !== $first ? $first : $last
		);
	}
}
