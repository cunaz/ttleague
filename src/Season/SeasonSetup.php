<?php
/**
 * Saison anlegen, Regeln und Meldefenster setzen, leere geplante Saison löschen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldungen der Ausnahmen sind Klartext; escaped wird bei der Ausgabe (Flash).

namespace TTLeague\Season;

use TTLeague\Domain\Registration\RegistrationRules;
use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Domain\Season\RulesInput;
use TTLeague\Domain\Season\SeasonDates;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Registration\RegistrationRepository;
use TTLeague\Results\Actor;

/**
 * Schritte 1 bis 3 des Assistenten (docs/saison.md). Alles verlangt die TK. Jeder Schreibvorgang
 * läuft in einer Transaktion unter der Sperre der Saisons, steht im audit_log und meldet danach
 * SeasonEvents::changed().
 *
 * Höchstens eine geplante Saison: create() prüft das unter der Sperre. Von zwei gleichzeitigen
 * Aufrufen legt nur der erste an; der zweite sieht die neue Saison und lehnt ab.
 */
final class SeasonSetup {

	private SeasonGuard $guard;

	private SeasonRepository $seasons;

	private RegistrationRepository $registrations;

	public function __construct(
		private readonly Db $db,
		private readonly AuditLog $audit,
		private readonly SeasonEvents $events,
	) {
		$this->guard         = new SeasonGuard( $db );
		$this->seasons       = new SeasonRepository( $db );
		$this->registrations = new RegistrationRepository( $db );
	}

	/**
	 * Was create() anlegen würde: Name, Kürzel und Daten der Folgesaison der aktiven Saison,
	 * ohne aktive Saison null (dann kommen Name und Daten aus dem Formular).
	 *
	 * @return array{name:string,slug:string,start_date:string,end_date:string}|null
	 */
	public function preview(): ?array {
		$active = $this->seasons->active();
		return null === $active ? null : SeasonDates::following( (string) $active['start_date'], (string) $active['end_date'] );
	}

	/**
	 * Geplante Saison anlegen.
	 *
	 * Mit aktiver Saison: Name, Kürzel und Daten des Folgejahrs, die Regeln übernommen, eine
	 * offene Meldung (club_season) für jeden Club mit einer nicht zurückgezogenen Mannschaft und
	 * die TK-Chefs dieser Clubs vorgetragen. $data wird dann nicht gelesen.
	 *
	 * Ohne aktive Saison (frische Installation): leere Saison mit Standardregeln, Name und Daten
	 * aus $data. Clubs nimmt die TK danach mit add_club() auf.
	 *
	 * @param array{name?:string,start_date?:string,end_date?:string} $data Angaben ohne aktive Saison.
	 * @return int ID der neuen Saison.
	 * @throws SeasonException Ohne TK-Recht, wenn es schon eine geplante Saison gibt, das Kürzel
	 *                         vergeben ist oder die Angaben ungültig sind.
	 */
	public function create( Actor $actor, array $data = array() ): int {
		SeasonGuard::require_tk( $actor );
		$season_id = $this->db->transaction(
			function () use ( $actor, $data ): int {
				$this->guard->lock();
				$planned = $this->seasons->planned();
				if ( null !== $planned ) {
					/* translators: %s: Saison */
					throw SeasonException::conflict( sprintf( __( 'Es gibt schon eine geplante Saison (%s). Gib sie zuerst frei oder lösche sie.', 'ttleague' ), $planned['name'] ) );
				}
				$active = $this->seasons->active();
				if ( null === $active ) {
					$new = array(
						'name'       => trim( (string) ( $data['name'] ?? '' ) ),
						'start_date' => trim( (string) ( $data['start_date'] ?? '' ) ),
						'end_date'   => trim( (string) ( $data['end_date'] ?? '' ) ),
					);
					SeasonGuard::reject( SeasonDates::errors( $new['name'], $new['start_date'], $new['end_date'] ) );
					$new['slug'] = (string) SeasonDates::slug_of_name( $new['name'] );
					$rules       = ( new SeasonRules() )->to_array();
				} else {
					$new   = SeasonDates::following( (string) $active['start_date'], (string) $active['end_date'] );
					$rules = $active['rules'];
				}
				if ( null !== $this->db->get_var( "SELECT id FROM {$this->db->table( 'season' )} WHERE slug = %s OR name = %s LIMIT 1", array( $new['slug'], $new['name'] ) ) ) {
					/* translators: %s: Saison */
					throw SeasonException::conflict( sprintf( __( 'Eine Saison %s gibt es schon.', 'ttleague' ), $new['name'] ) );
				}
				$now       = $this->db->now();
				$season_id = $this->db->insert(
					'season',
					array(
						'name'       => $new['name'],
						'slug'       => $new['slug'],
						'start_date' => $new['start_date'],
						'end_date'   => $new['end_date'],
						'status'     => SeasonRepository::PLANNED,
						'rules_json' => self::json( $rules ),
						'created_at' => $now,
						'updated_at' => $now,
					)
				);
				$clubs     = 0;
				$chefs     = 0;
				if ( null !== $active ) {
					$club_ids = array_map(
						'intval',
						$this->db->get_col(
							"SELECT DISTINCT club_id FROM {$this->db->table( 'team' )} WHERE season_id = %d AND status = 'active' ORDER BY club_id",
							array( $active['id'] )
						)
					);
					foreach ( $club_ids as $club_id ) {
						$this->db->insert(
							'club_season',
							array(
								'season_id' => $season_id,
								'club_id'   => $club_id,
								'status'    => RegistrationRules::OPEN,
							)
						);
						++$clubs;
						$chefs += $this->carry_chefs( (int) $active['id'], $season_id, $club_id );
					}
				}
				$this->audit->record(
					'season',
					$season_id,
					'created',
					$actor->user_id,
					null,
					$new + array(
						'from_season' => null === $active ? null : $active['id'],
						'clubs'       => $clubs,
						'tk_chefs'    => $chefs,
					)
				);
				return $season_id;
			}
		);
		$this->events->changed( $season_id );
		return $season_id;
	}

	/**
	 * Regeln der geplanten Saison speichern. Gespeichert wird nur, was RulesInput und
	 * SeasonRules::from_array() annehmen; sonst bleibt alles, wie es war.
	 *
	 * @param array<string,mixed> $input Feld => Eingabe des Formulars.
	 * @return array<string,mixed> Die gespeicherten Regeln.
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der geplanten Saison oder mit Fehlern an den Feldern.
	 */
	public function save_rules( Actor $actor, int $season_id, array $input ): array {
		SeasonGuard::require_tk( $actor );
		$rules = $this->db->transaction(
			function () use ( $actor, $season_id, $input ): array {
				$this->guard->lock();
				$season = $this->guard->planned( $season_id );
				$parsed = RulesInput::parse( $input, $season['rules'] );
				SeasonGuard::reject( $parsed['errors'] );
				$rules = (array) $parsed['rules'];
				$this->db->update(
					'season',
					array(
						'rules_json' => self::json( $rules ),
						'updated_at' => $this->db->now(),
					),
					array( 'id' => $season_id )
				);
				$this->audit->record( 'season', $season_id, 'rules_saved', $actor->user_id, $season['rules'], $rules );
				return $rules;
			}
		);
		$this->events->changed( $season_id );
		return $rules;
	}

	/**
	 * Meldefenster und Frist der Nachmeldung setzen. Leere Angaben löschen das Datum. In der
	 * aktiven Saison ändert die TK so die Frist der Nachmeldung.
	 *
	 * @param array{registration_opens?:string|null,registration_closes?:string|null,late_registration_until?:string|null} $dates Daten als JJJJ-MM-TT.
	 * @throws SeasonException Ohne TK-Recht, in einer archivierten Saison oder bei ungültigen Daten.
	 */
	public function save_window( Actor $actor, int $season_id, array $dates ): void {
		SeasonGuard::require_tk( $actor );
		$this->db->transaction(
			function () use ( $actor, $season_id, $dates ): void {
				$this->guard->lock();
				$season = $this->guard->season( $season_id );
				if ( SeasonRepository::ARCHIVED === $season['status'] ) {
					throw SeasonException::conflict( __( 'Die Saison ist abgeschlossen.', 'ttleague' ) );
				}
				$new = array();
				foreach ( array( 'registration_opens', 'registration_closes', 'late_registration_until' ) as $field ) {
					$value         = array_key_exists( $field, $dates ) ? trim( (string) $dates[ $field ] ) : (string) $season[ $field ];
					$new[ $field ] = '' === $value ? null : $value;
				}
				SeasonGuard::reject(
					SeasonDates::window_errors(
						$new['registration_opens'],
						$new['registration_closes'],
						$new['late_registration_until'],
						(string) $season['start_date'],
						(string) $season['end_date']
					)
				);
				$this->db->update( 'season', $new + array( 'updated_at' => $this->db->now() ), array( 'id' => $season_id ) );
				$this->audit->record( 'season', $season_id, 'window_saved', $actor->user_id, array_intersect_key( $season, $new ), $new );
			}
		);
		$this->events->changed( $season_id );
	}

	/**
	 * Club in die geplante Saison aufnehmen: offene Meldung (club_season). Hat der Club in der
	 * geplanten Saison noch keinen TK-Chef, werden die der Vorsaison vorgetragen.
	 *
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der geplanten Saison, bei einem unbekannten
	 *                         Club oder wenn der Club schon teilnimmt.
	 */
	public function add_club( Actor $actor, int $season_id, int $club_id ): void {
		SeasonGuard::require_tk( $actor );
		$this->db->transaction(
			function () use ( $actor, $season_id, $club_id ): void {
				$this->guard->lock();
				$season = $this->guard->planned( $season_id );
				$this->registrations->lock_club( $club_id );
				$club = $this->registrations->club( $club_id );
				if ( null === $club ) {
					throw SeasonException::not_found( __( 'Diesen Club gibt es nicht.', 'ttleague' ) );
				}
				if ( null !== $this->registrations->club_season( $season_id, $club_id ) ) {
					/* translators: %s: Club */
					throw SeasonException::conflict( sprintf( __( '%s nimmt schon an der Saison teil.', 'ttleague' ), $club['name'] ) );
				}
				$id       = $this->db->insert(
					'club_season',
					array(
						'season_id' => $season_id,
						'club_id'   => $club_id,
						'status'    => RegistrationRules::OPEN,
					)
				);
				$chefs    = 0;
				$previous = $this->registrations->previous_season( $season );
				if ( null !== $previous && array() === $this->registrations->tk_chefs( $season_id, $club_id ) ) {
					$chefs = $this->carry_chefs( $previous['id'], $season_id, $club_id );
				}
				$this->audit->record(
					'club_season',
					$id,
					'created',
					$actor->user_id,
					null,
					array(
						'season_id' => $season_id,
						'club_id'   => $club_id,
						'tk_chefs'  => $chefs,
					)
				);
			}
		);
		$this->events->changed( $season_id );
	}

	/**
	 * Club aus der geplanten Saison nehmen, solange er nichts gemeldet hat: keine Spieler, keine
	 * Neumeldungen, keine Mannschaften. Seine Funktionen in dieser Saison fallen weg.
	 *
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der geplanten Saison, wenn der Club nicht
	 *                         teilnimmt oder schon etwas gemeldet hat.
	 */
	public function remove_club( Actor $actor, int $season_id, int $club_id ): void {
		SeasonGuard::require_tk( $actor );
		$this->db->transaction(
			function () use ( $actor, $season_id, $club_id ): void {
				$this->guard->lock();
				$this->guard->planned( $season_id );
				$this->registrations->lock_club( $club_id );
				$club_season = $this->registrations->club_season( $season_id, $club_id );
				if ( null === $club_season ) {
					throw SeasonException::not_found( __( 'Dieser Club nimmt an der Saison nicht teil.', 'ttleague' ) );
				}
				$used = 0;
				foreach ( array( 'player_season', 'player_request', 'team' ) as $table ) {
					$used += (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( $table )} WHERE season_id = %d AND club_id = %d", array( $season_id, $club_id ) );
				}
				if ( $used > 0 || RegistrationRules::OPEN !== $club_season['status'] ) {
					throw SeasonException::conflict( __( 'Der Club hat schon etwas gemeldet. Entferne zuerst seine Spieler und Mannschaften.', 'ttleague' ) );
				}
				$this->db->delete( 'club_season', array( 'id' => $club_season['id'] ) );
				$this->db->delete(
					'club_role',
					array(
						'season_id' => $season_id,
						'club_id'   => $club_id,
					)
				);
				$this->audit->record( 'club_season', $club_season['id'], 'deleted', $actor->user_id, $club_season );
			}
		);
		$this->events->changed( $season_id );
	}

	/**
	 * Was die geplante Saison schon enthält; nur wenn alles 0 ist, lässt sie sich löschen.
	 *
	 * @return array{registrations:int,teams:int,fixtures:int,entries:int}
	 */
	public function contents( int $season_id ): array {
		$competition = $this->db->table( 'competition' );
		$count       = fn( string $sql ): int => (int) $this->db->get_var( $sql, array( $season_id ) );
		return array(
			'registrations' => $count( "SELECT COUNT(*) FROM {$this->db->table( 'player_season' )} WHERE season_id = %d" )
				+ $count( "SELECT COUNT(*) FROM {$this->db->table( 'player_request' )} WHERE season_id = %d" )
				+ $count( "SELECT COUNT(*) FROM {$this->db->table( 'club_season' )} WHERE season_id = %d AND status <> 'open'" ),
			'teams'         => $count( "SELECT COUNT(*) FROM {$this->db->table( 'team' )} WHERE season_id = %d" ),
			'fixtures'      => $count( "SELECT COUNT(*) FROM {$this->db->table( 'fixture' )} f JOIN {$competition} c ON c.id = f.competition_id WHERE c.season_id = %d" ),
			'entries'       => $count( "SELECT COUNT(*) FROM {$this->db->table( 'cup_entry' )} e JOIN {$competition} c ON c.id = e.competition_id WHERE c.season_id = %d" )
				+ $count( "SELECT COUNT(*) FROM {$this->db->table( 'rl_participant' )} p JOIN {$competition} c ON c.id = p.competition_id WHERE c.season_id = %d" )
				+ $count( "SELECT COUNT(*) FROM {$this->db->table( 'rl_round' )} r JOIN {$competition} c ON c.id = r.competition_id WHERE c.season_id = %d" ),
		);
	}

	/**
	 * Geplante Saison löschen, solange sie leer ist: keine Meldungen (gemeldete Spieler,
	 * Neumeldungen, eingereichte oder geprüfte Meldungen), keine Mannschaften, keine Begegnungen,
	 * nichts im Cup und im Ranglistenturnier. Mit ihr verschwinden die offenen Meldungen der
	 * Clubs, die vorgetragenen Funktionen und die leeren Konkurrenzen.
	 *
	 * @param string $confirmation Name der Saison als Bestätigung.
	 * @throws SeasonException Ohne TK-Recht, ohne passende Bestätigung, ausserhalb der geplanten
	 *                         Saison oder wenn die Saison nicht mehr leer ist.
	 */
	public function delete( Actor $actor, int $season_id, string $confirmation ): void {
		SeasonGuard::require_tk( $actor );
		$this->db->transaction(
			function () use ( $actor, $season_id, $confirmation ): void {
				$this->guard->lock();
				$season = $this->guard->planned( $season_id );
				if ( trim( $confirmation ) !== $season['name'] ) {
					/* translators: %s: Saison */
					throw SeasonException::field( 'confirm', sprintf( __( 'Bitte bestätige das Löschen mit dem Namen der Saison (%s).', 'ttleague' ), $season['name'] ) );
				}
				if ( 0 !== array_sum( $this->contents( $season_id ) ) ) {
					throw SeasonException::conflict( __( 'Die Saison ist nicht mehr leer: Es gibt schon Meldungen, Mannschaften, Begegnungen oder Anmeldungen. Sie lässt sich nicht mehr löschen.', 'ttleague' ) );
				}
				foreach ( array( 'club_season', 'club_role', 'competition' ) as $table ) {
					$this->db->delete( $table, array( 'season_id' => $season_id ) );
				}
				$this->db->delete( 'season', array( 'id' => $season_id ) );
				$this->audit->record( 'season', $season_id, 'deleted', $actor->user_id, array_intersect_key( $season, array_flip( array( 'name', 'slug', 'start_date', 'end_date', 'status' ) ) ) );
			}
		);
		$this->events->changed( $season_id );
	}

	/**
	 * TK-Chefs eines Clubs aus einer Saison in eine andere vortragen; liefert die Anzahl.
	 */
	private function carry_chefs( int $from_season, int $to_season, int $club_id ): int {
		$persons = array_map(
			'intval',
			$this->db->get_col(
				"SELECT DISTINCT person_id FROM {$this->db->table( 'club_role' )} WHERE season_id = %d AND club_id = %d AND role = 'tk_chef' ORDER BY person_id",
				array( $from_season, $club_id )
			)
		);
		foreach ( $persons as $person_id ) {
			$this->db->insert(
				'club_role',
				array(
					'season_id' => $to_season,
					'club_id'   => $club_id,
					'person_id' => $person_id,
					'role'      => 'tk_chef',
					'team_id'   => null,
				)
			);
		}
		return count( $persons );
	}

	/**
	 * @param array<string,mixed> $value Wert.
	 */
	private static function json( array $value ): string {
		return (string) json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- läuft auch ohne WordPress (Tests, Werkzeuge).
	}
}
