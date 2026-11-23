<?php
/**
 * Stammdaten für die erste Clubmeldung in einer geplanten Saison.
 *
 * @package TTLeague
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Klartextfehler werden bei der Ausgabe als Flash-Meldung escaped.

namespace TTLeague\Season;

use TTLeague\Accounts\UserDirectory;
use TTLeague\Domain\Registration\RegistrationRules;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Registration\RegistrationRepository;
use TTLeague\Results\Actor;

/**
 * Anlage von Club samt Meldung oder Person samt Funktion in einer Transaktion.
 * Personen brauchen für diese Funktion weder Lizenz noch bestehendes WordPress-Konto.
 */
final class ClubSetup {

	public function __construct(
		private readonly Db $db,
		private readonly AuditLog $audit,
		private readonly SeasonEvents $events,
		private readonly UserDirectory $users,
	) {}

	public function create_club( Actor $actor, int $season_id, string $number, string $name, string $short_name, string $slug ): int {
		SeasonGuard::require_tk( $actor );
		$number = filter_var(
			$number,
			FILTER_VALIDATE_INT,
			array(
				'options' => array(
					'min_range' => 1,
					'max_range' => 2147483647,
				),
			)
		);
		if ( false === $number ) {
			throw SeasonException::field( 'number', __( 'Die Clubnummer muss eine positive ganze Zahl bis 2147483647 sein.', 'ttleague' ) );
		}
		if ( '' === trim( $name ) || mb_strlen( $name ) > 120 || '' === trim( $short_name ) || mb_strlen( $short_name ) > 60 ) {
			throw SeasonException::field( 'name', __( 'Gib einen Clubnamen (bis 120 Zeichen) und einen Kurznamen (bis 60 Zeichen) an.', 'ttleague' ) );
		}
		if ( strlen( $slug ) > 60 || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug ) ) {
			throw SeasonException::field( 'slug', __( 'Das URL-Kürzel besteht aus Kleinbuchstaben, Ziffern und Bindestrichen (bis 60 Zeichen).', 'ttleague' ) );
		}

		$id = $this->db->transaction(
			function () use ( $actor, $season_id, $number, $name, $short_name, $slug ): int {
				$guard = new SeasonGuard( $this->db );
				$guard->lock();
				$guard->planned( $season_id );
				if ( null !== $this->db->get_var( "SELECT id FROM {$this->db->table( 'club' )} WHERE number = %d OR slug = %s LIMIT 1", array( $number, $slug ) ) ) {
					throw SeasonException::conflict( __( 'Clubnummer oder URL-Kürzel ist bereits vergeben. Prüfe die bestehenden Clubs.', 'ttleague' ) );
				}
				$data = array(
					'number'     => $number,
					'name'       => trim( $name ),
					'short_name' => trim( $short_name ),
					'slug'       => $slug,
				);
				$club = $this->db->insert( 'club', $data );
				$this->db->insert(
					'club_season',
					array(
						'club_id'   => $club,
						'season_id' => $season_id,
						'status'    => RegistrationRules::OPEN,
					)
				);
				$this->audit->record( 'club', $club, 'created', $actor->user_id, null, array( 'season_id' => $season_id ) + $data );
				return $club;
			}
		);
		$this->events->changed( $season_id );
		return $id;
	}

	public function create_officer( Actor $actor, int $season_id, int $club_id, string $first_name, string $last_name, string $email ): int {
		SeasonGuard::require_tk( $actor );
		if ( '' === trim( $last_name ) || mb_strlen( $last_name ) > 80 || mb_strlen( $first_name ) > 80 ) {
			throw SeasonException::field( 'last_name', __( 'Gib einen Nachnamen an. Vor- und Nachname dürfen je höchstens 80 Zeichen enthalten.', 'ttleague' ) );
		}
		$email = strtolower( trim( $email ) );
		if ( ! RegistrationRules::is_email( $email ) || ! is_email( $email ) ) {
			throw SeasonException::field( 'email', __( 'Bitte gib eine gültige E-Mail-Adresse an.', 'ttleague' ) );
		}
		$id = $this->db->transaction(
			function () use ( $actor, $season_id, $club_id, $first_name, $last_name, $email ): int {
				$guard = new SeasonGuard( $this->db );
				$guard->lock();
				$guard->planned( $season_id );
				$repo = new RegistrationRepository( $this->db );
				$repo->lock_club( $club_id );
				$registration = $repo->club_season( $season_id, $club_id );
				if ( null === $registration ) {
					throw SeasonException::not_found( __( 'Dieser Club nimmt an der Saison nicht teil.', 'ttleague' ) );
				}
				if ( null !== $repo->person_by_email( $email ) || null !== $this->users->find_by_email( $email ) ) {
					throw SeasonException::conflict( __( 'Die E-Mail-Adresse ist bereits bei einer Person oder einem WordPress-Konto hinterlegt. Weise die passende bestehende Person zu oder kläre mit der Person eine andere Kontaktadresse.', 'ttleague' ) );
				}
				$now    = $this->db->now();
				$person = $this->db->insert(
					'person',
					array(
						'first_name' => trim( $first_name ),
						'last_name'  => trim( $last_name ),
						'email'      => $email,
						'created_at' => $now,
						'updated_at' => $now,
					)
				);
				$role   = array(
					'person_id' => $person,
					'club_id'   => $club_id,
					'season_id' => $season_id,
					'role'      => 'tk_chef',
				);
				$this->db->insert( 'club_role', $role );
				$this->audit->record( 'club_season', $registration['id'], 'officer_added', $actor->user_id, null, $role );
				return $person;
			}
		);
		$this->events->changed( $season_id );
		return $id;
	}
}
