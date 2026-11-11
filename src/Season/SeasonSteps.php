<?php
/**
 * Inhalt der sieben Schritte der Seite «Spielbetrieb → Saison».
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Season;

use TTLeague\Accounts\Services;
use TTLeague\Domain\Match\FixtureWorkflow as Flow;
use TTLeague\Domain\Registration\RegistrationRules;
use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Domain\Season\RulesInput;
use TTLeague\Domain\Season\SeasonDates;
use TTLeague\Frontend\Support\Format;
use TTLeague\Registration\RegistrationRepository;
use TTLeague\Registration\RegistrationView;
use TTLeague\Registration\ReviewPage;

/**
 * Baut HTML aus dem, was die Dienste liefern; jeder Wert wird beim Einsetzen escaped. Formulare
 * gehen an admin-post.php (SeasonPage::form(), SeasonActions). Die Klasse prüft keine Rechte:
 * Das tut SeasonPage::render() vor dem Aufruf.
 */
final class SeasonSteps {

	/** Regeln in der Reihenfolge und Gliederung des Formulars. */
	private const RULE_GROUPS = array(
		'eligibility'   => array( 'substitute_max', 'category_any_team' ),
		'deadlines'     => array( 'entry_deadline_hours', 'objection_days', 'forfeit_after_days', 'postponement_approval_days' ),
		'notifications' => array( 'match_reminder_days', 'result_reminder_hours', 'confirm_reminder_days' ),
		'match'         => array( 'sets_to_win', 'team_match_games', 'forfeit_score', 'two_player_max_wins', 'set_points_enabled' ),
		'cup'           => array( 'cup_mode', 'cup_games', 'cup_players' ),
		'ranking'       => array( 'rl_round_points', 'rl_seven_player_bonus', 'rl_finalists', 'rl_group_size', 'rl_group_size_max' ),
		'rating'        => array( 'rating_k', 'rating_scale', 'rating_doubles_weight', 'rating_points_per_class', 'classification_min_games' ),
		'schedule'      => array( 'schedule_tables_per_match' ),
	);

	/**
	 * @param array<string,mixed>|null $planned    Geplante Saison.
	 * @param array<string,mixed>|null $active     Aktive Saison.
	 * @param int                      $fixture_id Begegnung, die zum Verlegen offen ist.
	 * @throws SeasonException Wenn ein Dienst die Saison nicht (mehr) findet.
	 */
	public static function render( int $step, ?array $planned, ?array $active, int $fixture_id = 0 ): string {
		if ( SeasonPage::CREATE === $step ) {
			return self::create( $planned, $active );
		}
		if ( SeasonPage::CLOSE === $step ) {
			return self::close( $active );
		}
		if ( SeasonPage::SCHEDULE === $step ) {
			return ( null === $planned ? self::no_planned() : self::schedule( $planned, $fixture_id ) ) . ( null === $active ? '' : self::single_fixtures( $active ) );
		}
		if ( null === $planned ) {
			return self::no_planned();
		}
		return match ( $step ) {
			SeasonPage::RULES  => self::rules( $planned ),
			SeasonPage::WINDOW => self::window( $planned ),
			SeasonPage::GROUPS => self::groups( $planned ),
			default            => self::publish( $planned ),
		};
	}

	private static function no_planned(): string {
		return '<p>' . esc_html__( 'Es gibt keine geplante Saison.', 'ttleague' ) . ' <a href="' . esc_url( SeasonPage::url( SeasonPage::CREATE ) ) . '">' . esc_html__( 'Saison anlegen', 'ttleague' ) . '</a></p>';
	}

	/**
	 * Schritt 1: Saison anlegen, teilnehmende Clubs, leere Saison löschen.
	 *
	 * @param array<string,mixed>|null $planned Geplante Saison.
	 * @param array<string,mixed>|null $active  Aktive Saison.
	 */
	private static function create( ?array $planned, ?array $active ): string {
		if ( null === $planned ) {
			$next = SeasonAdmin::setup()->preview();
			if ( null !== $active && null !== $next ) {
				return '<p>' . esc_html(
					sprintf(
						/* translators: 1: aktive Saison, 2: neue Saison, 3: Zeitraum */
						__( 'Aus der aktiven Saison %1$s entsteht die Saison %2$s (%3$s): Die Regeln werden übernommen, jeder Club mit einer Mannschaft erhält eine offene Meldung, und die TK-Chefs werden vorgetragen.', 'ttleague' ),
						$active['name'],
						$next['name'],
						SeasonPage::period( $next )
					)
				) . '</p>'
					. SeasonPage::form( SeasonActions::CREATE, 0, array(), 'ttl-tk-inline' )
					/* translators: %s: Saison */
					. '<button type="submit" class="button button-primary">' . esc_html( sprintf( __( 'Saison %s anlegen', 'ttleague' ), $next['name'] ) ) . '</button></form>';
			}
			$memory  = SeasonActions::recall( 'create' );
			$default = SeasonDates::upcoming( Services::clock()->now()->setTimezone( wp_timezone() )->format( 'Y-m-d' ) );
			$value   = static fn( string $field ): string => $memory['values'][ $field ] ?? $default[ $field ];
			return '<p>' . esc_html__( 'Es gibt noch keine Saison. Die erste Saison entsteht leer, mit den Standardregeln; die Clubs nimmst du danach einzeln auf.', 'ttleague' ) . '</p>'
				. SeasonPage::form( SeasonActions::CREATE, 0, array(), '' ) . '<table class="form-table" role="presentation"><tbody>'
				. self::row( 'ttl-season-name', 'name', __( 'Name', 'ttleague' ), $value( 'name' ), 'text', $memory['errors']['name'] ?? null, __( 'In der Form «2027/28».', 'ttleague' ) )
				. self::row( 'ttl-season-start', 'start_date', __( 'Beginn', 'ttleague' ), $value( 'start_date' ), 'date', $memory['errors']['start_date'] ?? null )
				. self::row( 'ttl-season-end', 'end_date', __( 'Ende', 'ttleague' ), $value( 'end_date' ), 'date', $memory['errors']['end_date'] ?? null )
				. '</tbody></table><p><button type="submit" class="button button-primary">' . esc_html__( 'Saison anlegen', 'ttleague' ) . '</button></p></form>';
		}

		$season_id = (int) $planned['id'];
		$db        = Services::db();
		$clubs     = ( new RegistrationRepository( $db ) )->overview( $season_id );
		$out       = '<h3>' . esc_html__( 'Teilnehmende Clubs', 'ttleague' ) . '</h3>';
		if ( array() === $clubs ) {
			$out .= '<p>' . esc_html__( 'Noch nimmt kein Club teil. Ohne Aufnahme kann ein Club nicht melden.', 'ttleague' ) . '</p>';
		} else {
			$out .= '<table class="widefat striped ttl-tk-table ttl-season-narrow"><thead><tr><th scope="col">' . esc_html__( 'Club', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Meldung', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Aktion', 'ttleague' ) . '</th></tr></thead><tbody>';
			foreach ( $clubs as $club ) {
				$empty = RegistrationRules::OPEN === $club['status'] && 0 === $club['players'] + $club['teams'] + $club['requests'];
				$out  .= '<tr><td>' . esc_html( $club['name'] ) . '</td><td>' . esc_html( RegistrationView::status_label( $club['status'] ) ) . '</td><td>';
				if ( $empty ) {
					$out .= SeasonPage::form(
						SeasonActions::CLUB_REMOVE,
						$season_id,
						array(
							'season' => (string) $season_id,
							'club'   => (string) $club['club_id'],
						),
						'ttl-tk-inline'
					) . '<button type="submit" class="button button-small">' . esc_html__( 'Aus der Saison nehmen', 'ttleague' ) . '<span class="screen-reader-text">: ' . esc_html( $club['name'] ) . '</span></button></form>';
				} else {
					$out .= '<span class="ttl-tk-muted">' . esc_html__( 'hat schon gemeldet', 'ttleague' ) . '</span>';
				}
				$out .= '</td></tr>';
			}
			$out .= '</tbody></table>';
		}
		$taken   = array_column( $clubs, 'club_id' );
		$options = '';
		foreach ( $db->get_results( "SELECT id, name FROM {$db->table( 'club' )} WHERE active = 1 ORDER BY name, id" ) as $club ) {
			if ( ! in_array( (int) $club['id'], $taken, true ) ) {
				$options .= '<option value="' . esc_attr( (string) $club['id'] ) . '">' . esc_html( (string) $club['name'] ) . '</option>';
			}
		}
		if ( '' !== $options ) {
			$out .= SeasonPage::form( SeasonActions::CLUB_ADD, $season_id, array( 'season' => (string) $season_id ), 'ttl-tk-inline' )
				. '<label for="ttl-season-club">' . esc_html__( 'Club aufnehmen', 'ttleague' ) . '</label> <select id="ttl-season-club" name="club">' . $options . '</select>'
				. '<button type="submit" class="button">' . esc_html__( 'Aufnehmen', 'ttleague' ) . '</button></form>';
		}

		$out     .= '<h3>' . esc_html__( 'Geplante Saison löschen', 'ttleague' ) . '</h3>';
		$contents = SeasonAdmin::setup()->contents( $season_id );
		if ( 0 !== array_sum( $contents ) ) {
			return $out . '<p class="ttl-tk-muted">' . esc_html__( 'Die Saison hat schon Meldungen, Mannschaften, Begegnungen oder Anmeldungen und lässt sich nicht mehr löschen.', 'ttleague' ) . '</p>';
		}
		return $out . '<p>' . esc_html__( 'Solange die Saison leer ist, lässt sie sich löschen. Mit ihr verschwinden die offenen Meldungen, die vorgetragenen TK-Chefs und die leeren Konkurrenzen.', 'ttleague' ) . '</p>'
			. SeasonPage::form( SeasonActions::DELETE, $season_id, array( 'season' => (string) $season_id ) )
			/* translators: %s: Saison */
			. '<label for="ttl-season-delete">' . esc_html( sprintf( __( 'Zur Bestätigung den Namen der Saison eingeben (%s)', 'ttleague' ), $planned['name'] ) ) . '</label>'
			. '<input type="text" id="ttl-season-delete" name="confirm" required autocomplete="off">'
			. '<button type="submit" class="button">' . esc_html__( 'Saison löschen', 'ttleague' ) . '</button></form>';
	}

	/**
	 * Schritt 2: Formular der Regeln mit Fehlern am Feld.
	 *
	 * @param array<string,mixed> $planned Geplante Saison.
	 */
	private static function rules( array $planned ): string {
		$season_id = (int) $planned['id'];
		$memory    = SeasonActions::recall( 'rules' );
		$labels    = self::rule_labels();
		$current   = $planned['rules'] + ( new SeasonRules() )->to_array();
		$out       = '';
		try {
			SeasonRules::from_array( $planned['rules'] );
		} catch ( \InvalidArgumentException $e ) {
			$out .= '<div class="notice notice-error inline"><p>' . esc_html__( 'Die gespeicherten Regeln sind ungültig. Korrigiere sie und speichere neu.', 'ttleague' ) . ' ' . esc_html( $e->getMessage() ) . '</p></div>';
		}
		$out .= '<p>' . esc_html__( 'Die Regeln gelten für diese Saison. Gespeichert wird nur, wenn alle Felder gültig sind.', 'ttleague' ) . '</p>'
			. SeasonPage::form( SeasonActions::RULES, $season_id, array( 'season' => (string) $season_id ), '' );
		foreach ( self::RULE_GROUPS as $group => $fields ) {
			$out .= '<h3>' . esc_html( self::rule_group_label( $group ) ) . '</h3><table class="form-table" role="presentation"><tbody>';
			foreach ( $fields as $field ) {
				$spec                    = RulesInput::FIELDS[ $field ];
				$id                      = 'ttl-rule-' . str_replace( '_', '-', $field );
				$value                   = $memory['values'][ $field ] ?? RulesInput::text( $field, $current[ $field ] ?? null );
				$error                   = $memory['errors'][ $field ] ?? null;
				$name                    = 'rules[' . $field . ']';
				[ $label, $description ] = $labels[ $field ];
				if ( RulesInput::BOOL === $spec['type'] ) {
					$checked = array() === $memory['values'] ? ! empty( $current[ $field ] ) : isset( $memory['values'][ $field ] ) && '' !== $memory['values'][ $field ];
					$input   = '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . ( $checked ? ' checked' : '' ) . '>';
				} elseif ( RulesInput::CHOICE === $spec['type'] ) {
					$input = '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
					foreach ( $spec['choices'] ?? array() as $choice ) {
						$input .= '<option value="' . esc_attr( $choice ) . '"' . ( $choice === $value ? ' selected' : '' ) . '>' . esc_html( self::choice_label( $choice ) ) . '</option>';
					}
					$input .= '</select>';
				} else {
					$input = '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="' . ( in_array( $spec['type'], array( RulesInput::INT, RulesInput::FLOAT ), true ) ? 'small-text' : 'regular-text' ) . '"'
						. ( null === $error ? '' : ' aria-invalid="true" aria-describedby="' . esc_attr( $id ) . '-error"' ) . '>';
				}
				$out .= '<tr' . ( null === $error ? '' : ' class="form-invalid"' ) . '><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>' . $input
					. ( null === $error ? '' : '<span class="ttl-season-error" id="' . esc_attr( $id ) . '-error">' . esc_html( $error ) . '</span>' )
					. ( '' === $description ? '' : '<p class="description">' . esc_html( $description ) . '</p>' )
					. '</td></tr>';
			}
			$out .= '</tbody></table>';
		}
		return $out . '<p><button type="submit" class="button button-primary">' . esc_html__( 'Regeln speichern', 'ttleague' ) . '</button></p></form>';
	}

	/**
	 * Schritt 3: Meldefenster und Meldestand je Club.
	 *
	 * @param array<string,mixed> $planned Geplante Saison.
	 */
	private static function window( array $planned ): string {
		$season_id = (int) $planned['id'];
		$memory    = SeasonActions::recall( 'window' );
		$value     = static fn( string $field ): string => $memory['values'][ $field ] ?? (string) $planned[ $field ];
		$out       = '<p>' . esc_html__( 'Im Meldefenster melden die TK-Chefs in «Mein Bereich». Nach dem letzten Tag ändert nur noch die TK. Die Frist der Nachmeldung gilt, sobald die Saison läuft; ohne Datum ist es der 31. Januar.', 'ttleague' ) . '</p>'
			. SeasonPage::form( SeasonActions::WINDOW, $season_id, array( 'season' => (string) $season_id ), '' ) . '<table class="form-table" role="presentation"><tbody>'
			. self::row( 'ttl-season-opens', 'registration_opens', __( 'Meldefenster: erster Tag', 'ttleague' ), $value( 'registration_opens' ), 'date', $memory['errors']['registration_opens'] ?? null )
			. self::row( 'ttl-season-closes', 'registration_closes', __( 'Meldefenster: letzter Tag', 'ttleague' ), $value( 'registration_closes' ), 'date', $memory['errors']['registration_closes'] ?? null )
			. self::row( 'ttl-season-late', 'late_registration_until', __( 'Nachmeldungen bis', 'ttleague' ), $value( 'late_registration_until' ), 'date', $memory['errors']['late_registration_until'] ?? null )
			. '</tbody></table><p><button type="submit" class="button button-primary">' . esc_html__( 'Daten speichern', 'ttleague' ) . '</button></p></form>';

		$out  .= '<h3>' . esc_html__( 'Meldestand', 'ttleague' ) . '</h3>';
		$clubs = ( new RegistrationRepository( Services::db() ) )->overview( $season_id );
		if ( array() === $clubs ) {
			return $out . '<p>' . esc_html__( 'Noch nimmt kein Club teil.', 'ttleague' ) . '</p>';
		}
		$out .= '<table class="widefat striped ttl-tk-table"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Club', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Stand', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Spieler', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Mannschaften', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Offene Neumeldungen', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Aktion', 'ttleague' ) . '</th></tr></thead><tbody>';
		foreach ( $clubs as $club ) {
			$out .= '<tr><td>' . esc_html( $club['name'] ) . '</td><td>' . esc_html( RegistrationView::status_label( $club['status'] ) ) . '</td>'
				. '<td>' . esc_html( (string) $club['players'] ) . '</td><td>' . esc_html( (string) $club['teams'] ) . '</td><td>' . esc_html( (string) $club['requests'] ) . '</td>'
				. '<td><a class="button button-small" href="' . esc_url( ReviewPage::url( $season_id, $club['club_id'] ) ) . '">' . esc_html__( 'Meldung öffnen', 'ttleague' )
				. '<span class="screen-reader-text">: ' . esc_html( $club['name'] ) . '</span></a></td></tr>';
		}
		return $out . '</tbody></table>';
	}

	/**
	 * Schritt 4: Gruppen, Einteilung, Vorschlag und Schlussrangliste des Vorjahrs.
	 *
	 * @param array<string,mixed> $planned Geplante Saison.
	 * @throws SeasonException Wenn es die Saison nicht mehr gibt.
	 */
	private static function groups( array $planned ): string {
		$season_id = (int) $planned['id'];
		$state     = SeasonAdmin::grouping()->state( $season_id );
		$hidden    = array( 'season' => (string) $season_id );
		$out       = '';

		if ( array() !== $state['hints'] ) {
			$out .= '<h3>' . esc_html__( 'Hinweise', 'ttleague' ) . '</h3><ul class="ttl-season-list">';
			foreach ( $state['hints'] as $hint ) {
				$out .= '<li>' . esc_html( $hint['message'] ) . '</li>';
			}
			$out .= '</ul><p class="ttl-tk-muted">' . esc_html__( 'Die Hinweise verhindern nichts: Über die Einteilung entscheidet die TK (Art. 11.5). Freigeben lässt sich die Saison aber erst, wenn jede Mannschaft eine Gruppe hat.', 'ttleague' ) . '</p>';
		}

		$out .= '<div class="ttl-season-actions">';
		if ( null !== $state['previous'] ) {
			$out .= SeasonPage::form( SeasonActions::PROPOSAL, $season_id, $hidden, 'ttl-tk-inline' )
				/* translators: %s: Saison */
				. '<button type="submit" class="button">' . esc_html( sprintf( __( 'Gruppen und Einteilung aus %s vorschlagen', 'ttleague' ), $state['previous']['name'] ) ) . '</button></form>';
		}
		if ( array() !== $state['copyable'] ) {
			$out .= SeasonPage::form( SeasonActions::COPY, $season_id, $hidden, 'ttl-tk-inline' )
				/* translators: %d: Anzahl */
				. '<button type="submit" class="button">' . esc_html( sprintf( __( 'Cup und Ranglistenturnier übernehmen (%d)', 'ttleague' ), count( $state['copyable'] ) ) ) . '</button></form>';
		}
		$out .= '</div>';
		if ( array() !== $state['other'] ) {
			/* translators: %s: Konkurrenzen */
			$out .= '<p class="ttl-tk-muted">' . esc_html( sprintf( __( 'Weitere Konkurrenzen der Saison: %s.', 'ttleague' ), implode( ', ', array_column( $state['other'], 'name' ) ) ) ) . '</p>';
		}

		$out .= '<h3>' . esc_html__( 'Gruppen der Meisterschaft', 'ttleague' ) . '</h3>';
		if ( array() !== $state['groups'] ) {
			$out .= '<table class="widefat striped ttl-tk-table"><thead><tr><th scope="col">' . esc_html__( 'Name', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Kategorie', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Gruppe', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Mannschaften', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Begegnungen', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Aktion', 'ttleague' ) . '</th></tr></thead><tbody>';
			foreach ( $state['groups'] as $group ) {
				$id   = 'ttl-group-name-' . $group['id'];
				$out .= '<tr><td>' . SeasonPage::form( SeasonActions::GROUP_RENAME, $group['id'], array( 'group' => (string) $group['id'] ), 'ttl-tk-inline' )
					. '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html__( 'Name der Gruppe', 'ttleague' ) . '</label>'
					. '<input type="text" id="' . esc_attr( $id ) . '" name="name" value="' . esc_attr( $group['name'] ) . '" maxlength="80" required>'
					. '<button type="submit" class="button button-small">' . esc_html__( 'Umbenennen', 'ttleague' ) . '</button></form></td>'
					. '<td>' . esc_html( (string) $group['category'] ) . '</td><td>' . esc_html( (string) $group['group_no'] ) . '</td>'
					. '<td>' . esc_html( (string) $group['teams'] ) . '</td><td>' . esc_html( (string) $group['fixtures'] ) . '</td><td>';
				if ( 0 === $group['teams'] && 0 === $group['fixtures'] ) {
					$out .= SeasonPage::form( SeasonActions::GROUP_DELETE, $group['id'], array( 'group' => (string) $group['id'] ), 'ttl-tk-inline' )
						. '<button type="submit" class="button button-small">' . esc_html__( 'Löschen', 'ttleague' ) . '<span class="screen-reader-text">: ' . esc_html( $group['name'] ) . '</span></button></form>';
				}
				$out .= '</td></tr>';
			}
			$out .= '</tbody></table>';
		} else {
			$out .= '<p>' . esc_html__( 'Noch keine Gruppe.', 'ttleague' ) . '</p>';
		}
		$memory = SeasonActions::recall( 'group' );
		$error  = static fn( string $field, string $id ): string => isset( $memory['errors'][ $field ] ) ? '<span class="ttl-season-error" id="' . esc_attr( $id ) . '-error">' . esc_html( $memory['errors'][ $field ] ) . '</span>' : '';
		$aria   = static fn( string $field, string $id ): string => isset( $memory['errors'][ $field ] ) ? ' aria-invalid="true" aria-describedby="' . esc_attr( $id ) . '-error"' : '';
		$out   .= '<details' . ( array() === $memory['errors'] ? '' : ' open' ) . '><summary class="button">' . esc_html__( 'Neue Gruppe', 'ttleague' ) . '</summary>'
			. SeasonPage::form( SeasonActions::GROUP_CREATE, $season_id, $hidden )
			. '<label for="ttl-group-category">' . esc_html__( 'Kategorie (Buchstabe)', 'ttleague' ) . '</label>'
			. '<input type="text" id="ttl-group-category" name="category" maxlength="1" size="3" required value="' . esc_attr( $memory['values']['category'] ?? '' ) . '"' . $aria( 'category', 'ttl-group-category' ) . '>' . $error( 'category', 'ttl-group-category' )
			. '<label for="ttl-group-no">' . esc_html__( 'Gruppennummer', 'ttleague' ) . '</label>'
			. '<input type="number" id="ttl-group-no" name="group_no" min="1" max="99" value="' . esc_attr( $memory['values']['group_no'] ?? '1' ) . '"' . $aria( 'group_no', 'ttl-group-no' ) . '>' . $error( 'group_no', 'ttl-group-no' )
			. '<label for="ttl-group-new-name">' . esc_html__( 'Name (leer: «Gruppe A»)', 'ttleague' ) . '</label>'
			. '<input type="text" id="ttl-group-new-name" name="name" maxlength="80" value="' . esc_attr( $memory['values']['name'] ?? '' ) . '"' . $aria( 'name', 'ttl-group-new-name' ) . '>' . $error( 'name', 'ttl-group-new-name' )
			. '<button type="submit" class="button">' . esc_html__( 'Gruppe anlegen', 'ttleague' ) . '</button></form></details>';

		$out .= '<h3>' . esc_html__( 'Einteilung der Mannschaften', 'ttleague' ) . '</h3>';
		if ( array() === $state['teams'] ) {
			$out .= '<p>' . esc_html__( 'Noch ist keine Mannschaft gemeldet.', 'ttleague' ) . '</p>';
		} else {
			$out .= SeasonPage::form( SeasonActions::ASSIGN, $season_id, $hidden, '' )
				. '<table class="widefat striped ttl-tk-table"><thead><tr><th scope="col">' . esc_html__( 'Club', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Mannschaft', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Vorjahr', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Vorschlag', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Gruppe', 'ttleague' ) . '</th></tr></thead><tbody>';
			foreach ( $state['teams'] as $team ) {
				$before = __( 'neu', 'ttleague' );
				if ( null !== $team['previous'] ) {
					/* translators: 1: Gruppe, 2: Rang */
					$before = sprintf( __( '%1$s, Rang %2$d', 'ttleague' ), $team['previous']['group'], (int) $team['previous']['rank'] ) . ( $team['previous']['withdrawn'] ? ' ' . __( '(zurückgezogen)', 'ttleague' ) : '' );
				}
				$proposal = '–';
				if ( null !== $team['proposal'] ) {
					/* translators: 1: Kategorie, 2: Gruppennummer */
					$proposal = sprintf( __( 'Kategorie %1$s, Gruppe %2$d', 'ttleague' ), $team['proposal']['category'], $team['proposal']['group_no'] );
				}
				$id = 'ttl-team-group-' . $team['id'];
				if ( $team['fixtures'] > 0 ) {
					$current = array_column( $state['groups'], 'name', 'id' )[ $team['competition_id'] ] ?? '–';
					$select  = esc_html( $current ) . ' <span class="ttl-tk-muted">' . esc_html__( '(hat Begegnungen; zuerst den Entwurf der Gruppe löschen)', 'ttleague' ) . '</span>';
				} else {
					$select = '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( sprintf( /* translators: %s: Mannschaft */ __( 'Gruppe von %s', 'ttleague' ), $team['name'] ) ) . '</label>'
						. '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( 'team[' . $team['id'] . ']' ) . '"><option value="0">' . esc_html__( 'ohne Gruppe', 'ttleague' ) . '</option>';
					foreach ( $state['groups'] as $group ) {
						$select .= '<option value="' . esc_attr( (string) $group['id'] ) . '"' . ( $group['id'] === $team['competition_id'] ? ' selected' : '' ) . '>' . esc_html( $group['name'] ) . '</option>';
					}
					$select .= '</select>';
				}
				$out .= '<tr><td>' . esc_html( $team['club'] ) . '</td><td><strong>' . esc_html( $team['name'] ) . '</strong></td><td>' . esc_html( $before ) . '</td><td>' . esc_html( $proposal ) . '</td><td>' . $select . '</td></tr>';
			}
			$out .= '</tbody></table><p><button type="submit" class="button button-primary"' . ( array() === $state['groups'] ? ' disabled' : '' ) . '>' . esc_html__( 'Einteilung speichern', 'ttleague' ) . '</button></p></form>';
		}

		if ( null !== $state['previous'] && array() !== $state['previous']['groups'] ) {
			/* translators: %s: Saison */
			$out .= '<h3>' . esc_html( sprintf( __( 'Schlussrangliste %s', 'ttleague' ), $state['previous']['name'] ) ) . '</h3>';
			foreach ( $state['previous']['groups'] as $group ) {
				$out .= '<details><summary>' . esc_html( $group['name'] ) . '</summary><table class="widefat striped ttl-tk-table ttl-season-narrow"><thead><tr><th scope="col">' . esc_html__( 'Rang', 'ttleague' ) . '</th>'
					. '<th scope="col">' . esc_html__( 'Mannschaft', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Begegnungen', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Punkte', 'ttleague' ) . '</th></tr></thead><tbody>';
				foreach ( $group['rows'] as $row ) {
					$out .= '<tr><td>' . esc_html( $row['rank'] . '.' ) . '</td><td>' . esc_html( $row['team'] ) . ( $row['withdrawn'] ? ' <span class="ttl-tk-muted">' . esc_html__( '(zurückgezogen)', 'ttleague' ) . '</span>' : '' ) . '</td>'
						. '<td>' . esc_html( (string) $row['played'] ) . '</td><td>' . esc_html( (string) $row['points'] ) . '</td></tr>';
				}
				$out .= '</tbody></table></details>';
			}
		}
		return $out;
	}

	/**
	 * Schritt 5: Spielwochen, Entwurf, Prüfung, Verlegen.
	 *
	 * @param array<string,mixed> $planned    Geplante Saison.
	 * @param int                 $fixture_id Begegnung, die zum Verlegen offen ist.
	 * @throws SeasonException Wenn es die Saison nicht mehr gibt.
	 */
	private static function schedule( array $planned, int $fixture_id ): string {
		$season_id  = (int) $planned['id'];
		$scheduling = SeasonAdmin::scheduling();
		$weeks      = $scheduling->weeks( $season_id );
		$overview   = $scheduling->overview( $season_id );
		$hidden     = array( 'season' => (string) $season_id );

		$out = '<h3>' . esc_html__( 'Spielwochen', 'ttleague' ) . '</h3><p>'
			. esc_html( $weeks['saved'] ? __( 'Deine Auswahl ist gespeichert.', 'ttleague' ) : __( 'Vorschlag, noch nicht gespeichert: alle Wochen der Saison ohne die Runden des Ranglistenturniers. Streiche die Ferienwochen und speichere.', 'ttleague' ) )
			. ' ' . esc_html__( 'Wählst du mehr Wochen, als eine Gruppe Runden hat, verteilt der Entwurf die Runden über alle Wochen und versetzt die Gruppen gegeneinander. Wochen, in denen eine Gruppe nicht spielt, dienen als Ausweichtermine.', 'ttleague' ) . '</p>'
			. '<details' . ( $weeks['saved'] ? '' : ' open' ) . '><summary class="button">' . esc_html(
				sprintf(
					/* translators: 1: Wochen der Vorrunde, 2: Wochen der Rückrunde */
					__( 'Spielwochen wählen (Vorrunde %1$d, Rückrunde %2$d)', 'ttleague' ),
					count( array_filter( $weeks['weeks'], static fn( array $week ): bool => 1 === $week['leg'] ) ),
					count( array_filter( $weeks['weeks'], static fn( array $week ): bool => 2 === $week['leg'] ) )
				)
			) . '</summary>'
			. SeasonPage::form( SeasonActions::WEEKS, $season_id, $hidden, '' )
			. '<table class="widefat striped ttl-tk-table ttl-season-narrow"><thead><tr><th scope="col">' . esc_html__( 'Woche (Montag bis Sonntag)', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Vorrunde', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Rückrunde', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'keine Spielwoche', 'ttleague' ) . '</th></tr></thead><tbody>';
		foreach ( $weeks['weeks'] as $week ) {
			$sunday = ( new \DateTimeImmutable( $week['monday'] ) )->modify( '+6 days' )->format( 'Y-m-d' );
			$label  = Format::date_range( $week['monday'], $sunday );
			$out   .= '<tr><th scope="row">' . esc_html( $label ) . ( $week['blocked'] ? ' <span class="ttl-tk-muted">' . esc_html__( '(Ranglistenturnier)', 'ttleague' ) . '</span>' : '' ) . '</th>';
			foreach ( array(
				1 => __( 'Vorrunde', 'ttleague' ),
				2 => __( 'Rückrunde', 'ttleague' ),
				0 => __( 'keine Spielwoche', 'ttleague' ),
			) as $leg => $name ) {
				$out .= '<td><label><input type="radio" name="' . esc_attr( 'week[' . $week['monday'] . ']' ) . '" value="' . esc_attr( (string) $leg ) . '"' . ( $leg === $week['leg'] ? ' checked' : '' ) . '>'
					. '<span class="screen-reader-text">' . esc_html( $label . ': ' . $name ) . '</span></label></td>';
			}
			$out .= '</tr>';
		}
		$out .= '</tbody></table><p><button type="submit" class="button button-primary">' . esc_html__( 'Spielwochen speichern', 'ttleague' ) . '</button></p></form></details>';

		$out .= '<h3>' . esc_html__( 'Entwurf', 'ttleague' ) . '</h3>';
		if ( array() !== $overview['missing'] ) {
			$out .= '<p>' . esc_html__( 'Bevor der Entwurf erzeugt werden kann, fehlt noch:', 'ttleague' ) . '</p><ul class="ttl-season-list">';
			foreach ( $overview['missing'] as $item ) {
				$out .= '<li>' . esc_html( $item['message'] ) . '</li>';
			}
			$out .= '</ul><p class="ttl-tk-muted">' . esc_html__( 'Heimlokal und Anspielzeit setzt du in der Meldung des Clubs, den Spieltag beim Lokal.', 'ttleague' ) . '</p>';
		}
		$has_fixtures = array() !== $overview['fixtures'];
		$out         .= '<div class="ttl-season-actions">';
		if ( array() === $overview['missing'] ) {
			$out .= SeasonPage::form( SeasonActions::GENERATE, $season_id, $hidden, 'ttl-tk-inline' )
				. '<button type="submit" class="button button-primary">' . esc_html( $has_fixtures ? __( 'Entwurf für alle Gruppen neu erzeugen', 'ttleague' ) : __( 'Entwurf erzeugen', 'ttleague' ) ) . '</button></form>';
		}
		if ( $has_fixtures ) {
			$out .= SeasonPage::form( SeasonActions::CLEAR, $season_id, $hidden, 'ttl-tk-inline' ) . '<button type="submit" class="button">' . esc_html__( 'Entwurf aller Gruppen löschen', 'ttleague' ) . '</button></form>'
				. '<span class="ttl-tk-muted">' . esc_html__( 'Ein neuer Entwurf ersetzt alle Begegnungen, auch von Hand verlegte.', 'ttleague' ) . '</span>';
		}
		$out .= '</div>';
		if ( array() !== $overview['groups'] ) {
			$out .= '<table class="widefat striped ttl-tk-table"><thead><tr><th scope="col">' . esc_html__( 'Gruppe', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Mannschaften', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Begegnungen', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Stand', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Aktion', 'ttleague' ) . '</th></tr></thead><tbody>';
			foreach ( $overview['groups'] as $group ) {
				$state = $group['complete'] ? __( 'vollständig', 'ttleague' ) : ( 0 === $group['fixtures'] ? __( 'kein Entwurf', 'ttleague' ) : __( 'passt nicht zu den Mannschaften: neu erzeugen', 'ttleague' ) );
				$forms = '';
				$mine  = $hidden + array( 'group' => (string) $group['id'] );
				if ( array() === $overview['missing'] ) {
					$forms .= SeasonPage::form( SeasonActions::GENERATE, $season_id, $mine, 'ttl-tk-inline' ) . '<button type="submit" class="button button-small">' . esc_html__( 'Neu erzeugen', 'ttleague' )
						. '<span class="screen-reader-text">: ' . esc_html( $group['name'] ) . '</span></button></form>';
				}
				if ( $group['fixtures'] > 0 ) {
					$forms .= SeasonPage::form( SeasonActions::CLEAR, $season_id, $mine, 'ttl-tk-inline' ) . '<button type="submit" class="button button-small">' . esc_html__( 'Entwurf löschen', 'ttleague' )
						. '<span class="screen-reader-text">: ' . esc_html( $group['name'] ) . '</span></button></form>';
				}
				$out .= '<tr><td><strong>' . esc_html( $group['name'] ) . '</strong></td><td>' . esc_html( (string) $group['teams'] ) . '</td>'
					/* translators: 1: vorhandene, 2: erwartete Begegnungen */
					. '<td>' . esc_html( sprintf( __( '%1$d von %2$d', 'ttleague' ), $group['fixtures'], $group['expected'] ) ) . '</td><td>' . esc_html( $state ) . '</td>'
					. '<td><div class="ttl-season-actions">' . $forms . '</div></td></tr>';
			}
			$out .= '</tbody></table>';
		}
		if ( ! $has_fixtures ) {
			return $out;
		}

		$fixtures = $overview['fixtures'];
		$name     = static fn( int $id ): string => isset( $fixtures[ $id ] ) ? $fixtures[ $id ]['home'] . ' – ' . $fixtures[ $id ]['away'] : '';

		if ( isset( $fixtures[ $fixture_id ] ) && Flow::SCHEDULED === $fixtures[ $fixture_id ]['status'] ) {
			$out .= self::fixture_editor( $fixtures[ $fixture_id ], $overview['venues'] );
		}

		$out .= '<h3 id="ttl-season-report">' . esc_html__( 'Prüfung', 'ttleague' ) . '</h3>';
		if ( null !== $overview['error'] ) {
			$out .= '<div class="notice notice-error inline"><p>' . esc_html__( 'Der Plan lässt sich nicht prüfen:', 'ttleague' ) . ' ' . esc_html( $overview['error'] ) . '</p></div>';
		} elseif ( array() === $overview['conflicts'] ) {
			$out .= '<p>' . esc_html__( 'Keine Kollisionen und keine Hinweise.', 'ttleague' ) . '</p>';
		} else {
			/* translators: 1: harte Kollisionen, 2: Hinweise */
			$out .= '<p>' . esc_html( sprintf( __( 'Harte Kollisionen: %1$d. Hinweise: %2$d. Verlegt wird nie von selbst: Übernimm einen Vorschlag oder verlege von Hand.', 'ttleague' ), $overview['hard'], $overview['hints'] ) ) . '</p>';
			// Harte Kollisionen stehen offen da; Hinweise je Art zugeklappt, damit die Seite lesbar bleibt.
			$hints = array();
			foreach ( $overview['conflicts'] as $conflict ) {
				$html = self::conflict( $conflict, $fixtures, $name );
				if ( $conflict['hard'] ) {
					$out .= $html;
				} else {
					$hints[ (string) $conflict['kind'] ][] = $html;
				}
			}
			foreach ( $hints as $kind => $items ) {
				$out .= '<details class="ttl-season-hints"><summary>' . esc_html( self::hint_label( $kind ) . ' (' . count( $items ) . ')' ) . '</summary>' . implode( '', $items ) . '</details>';
			}
		}

		$out     .= '<h3>' . esc_html__( 'Begegnungen', 'ttleague' ) . '</h3>';
		$by_group = array();
		foreach ( $fixtures as $fixture ) {
			$by_group[ $fixture['group'] ][] = $fixture;
		}
		foreach ( $by_group as $group => $rows ) {
			$out .= '<details><summary>' . esc_html( $group . ' (' . count( $rows ) . ')' ) . '</summary><table class="widefat striped ttl-tk-table"><thead><tr><th scope="col">' . esc_html__( 'Nr.', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Runde', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Termin', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Begegnung', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Lokal', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Aktion', 'ttleague' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $fixture ) {
				$out .= '<tr><td>' . esc_html( (string) $fixture['match_no'] ) . '</td><td>' . esc_html( (string) $fixture['round'] ) . '</td><td>' . esc_html( Format::datetime( $fixture['scheduled_at'] ) ) . '</td>'
					. '<td>' . esc_html( $fixture['home'] . ' – ' . $fixture['away'] ) . '</td><td>' . esc_html( (string) $fixture['venue'] ) . '</td>'
					. '<td><a href="' . esc_url( SeasonPage::url( SeasonPage::SCHEDULE, array( 'fixture' => (string) $fixture['id'] ) ) ) . '">' . esc_html__( 'verlegen oder Heimrecht tauschen', 'ttleague' )
					. '<span class="screen-reader-text">: ' . esc_html( $fixture['home'] . ' – ' . $fixture['away'] ) . '</span></a></td></tr>';
			}
			$out .= '</tbody></table></details>';
		}
		return $out;
	}

	/**
	 * Eine Kollision oder ein Hinweis der Prüfung mit den betroffenen Begegnungen und den Vorschlägen.
	 *
	 * @param array<string,mixed>            $conflict Eintrag aus Scheduling::overview().
	 * @param array<int,array<string,mixed>> $fixtures Begegnungen des Entwurfs.
	 * @param callable(int):string           $name     Bezeichnung einer Begegnung.
	 */
	private static function conflict( array $conflict, array $fixtures, callable $name ): string {
		$context = array_filter( array( $conflict['venue'], $conflict['club'], $conflict['team'] ) );
		$out     = '<div class="' . esc_attr( $conflict['hard'] ? 'ttl-season-hard' : 'ttl-season-soft' ) . '"><p><strong>' . esc_html( $conflict['hard'] ? __( 'Kollision', 'ttleague' ) : __( 'Hinweis', 'ttleague' ) ) . ':</strong> '
			. esc_html( ( array() === $context ? '' : implode( ', ', $context ) . ': ' ) . $conflict['message'] ) . '</p>';
		if ( count( $conflict['fixtures'] ) <= 12 ) {
			$out .= '<ul class="ttl-season-list">';
			foreach ( $conflict['fixtures'] as $id ) {
				$out .= '<li>' . esc_html( $name( $id ) . ', ' . Format::datetime( $fixtures[ $id ]['scheduled_at'] ) ) . ' <a href="' . esc_url( SeasonPage::url( SeasonPage::SCHEDULE, array( 'fixture' => (string) $id ) ) ) . '">'
					. esc_html__( 'verlegen', 'ttleague' ) . '<span class="screen-reader-text">: ' . esc_html( $name( $id ) ) . '</span></a></li>';
			}
			$out .= '</ul>';
		}
		if ( array() !== $conflict['suggestions'] ) {
			$out .= '<div class="ttl-season-actions">';
			foreach ( $conflict['suggestions'] as $suggestion ) {
				$out .= SeasonPage::form(
					SeasonActions::SUGGESTION,
					$suggestion['fixture_id'],
					array(
						'fixture'   => (string) $suggestion['fixture_id'],
						'starts_at' => $suggestion['starts_at'],
					),
					'ttl-tk-inline'
				)
					/* translators: 1: Begegnung, 2: Termin */
					. '<button type="submit" class="button button-small">' . esc_html( sprintf( __( '%1$s auf %2$s verlegen', 'ttleague' ), $name( $suggestion['fixture_id'] ), Format::datetime( $suggestion['starts_at'] ) ) ) . '</button></form>';
			}
			$out .= '</div>';
		} elseif ( 'tables_unknown' !== $conflict['kind'] ) {
			$out .= '<p class="ttl-tk-muted">' . esc_html__( 'Kein freier Ausweichtermin in den gewählten Spielwochen.', 'ttleague' ) . '</p>';
		}
		return $out . '</div>';
	}

	/**
	 * Überschrift einer Art von Hinweisen (ConflictKind).
	 */
	private static function hint_label( string $kind ): string {
		return match ( $kind ) {
			'club_same_evening' => __( 'Hinweise: mehrere Mannschaften eines Clubs am selben Abend', 'ttleague' ),
			'tables_unknown'    => __( 'Hinweise: Tischzahl eines Lokals unbekannt', 'ttleague' ),
			default             => __( 'Weitere Hinweise', 'ttleague' ),
		};
	}

	/**
	 * Formulare für eine Begegnung des Entwurfs: verlegen, Heimrecht tauschen.
	 *
	 * @param array<string,mixed>            $fixture Begegnung.
	 * @param array<int,array<string,mixed>> $venues  Lokale.
	 */
	private static function fixture_editor( array $fixture, array $venues ): string {
		$id      = (int) $fixture['id'];
		$title   = $fixture['home'] . ' – ' . $fixture['away'];
		$hidden  = array( 'fixture' => (string) $id );
		$options = '';
		foreach ( $venues as $venue ) {
			if ( $venue['active'] || $venue['id'] === $fixture['venue_id'] ) {
				$options .= '<option value="' . esc_attr( (string) $venue['id'] ) . '"' . ( $venue['id'] === $fixture['venue_id'] ? ' selected' : '' ) . '>' . esc_html( $venue['name'] ) . '</option>';
			}
		}
		return '<div class="card"><h3>' . esc_html( $title ) . '</h3><p>' . esc_html( Format::datetime( $fixture['scheduled_at'] ) . ', ' . (string) $fixture['venue'] ) . '</p>'
			. SeasonPage::form( SeasonActions::MOVE, $id, $hidden )
			. '<label for="ttl-fixture-date">' . esc_html__( 'Datum', 'ttleague' ) . '</label><input type="date" id="ttl-fixture-date" name="date" required value="' . esc_attr( substr( (string) $fixture['scheduled_at'], 0, 10 ) ) . '">'
			. '<label for="ttl-fixture-time">' . esc_html__( 'Anspielzeit', 'ttleague' ) . '</label><input type="time" id="ttl-fixture-time" name="time" required value="' . esc_attr( substr( (string) $fixture['scheduled_at'], 11, 5 ) ) . '">'
			. '<label for="ttl-fixture-venue">' . esc_html__( 'Lokal', 'ttleague' ) . '</label><select id="ttl-fixture-venue" name="venue">' . $options . '</select>'
			. '<button type="submit" class="button button-primary">' . esc_html__( 'Verlegen', 'ttleague' ) . '</button></form>'
			. '<p>' . esc_html__( 'Beim Tausch des Heimrechts bleibt die Begegnung in ihrer Woche und fällt auf Heimspieltag, Anspielzeit und Lokal der neuen Heimmannschaft.', 'ttleague' ) . '</p>'
			. SeasonPage::form( SeasonActions::SWAP, $id, $hidden )
			. '<label><input type="checkbox" name="with_return" value="1"> ' . esc_html__( 'auch die Begegnung der anderen Halbserie tauschen', 'ttleague' ) . '</label>'
			. '<button type="submit" class="button">' . esc_html__( 'Heimrecht tauschen', 'ttleague' ) . '</button></form>'
			. '<p><a href="' . esc_url( SeasonPage::url( SeasonPage::SCHEDULE ) ) . '">' . esc_html__( 'Schliessen', 'ttleague' ) . '</a></p></div>';
	}

	/**
	 * Aktive Saison: einzelne Begegnung ansetzen (Entscheidungsspiel, Art. 15) oder absagen.
	 *
	 * @param array<string,mixed> $active Aktive Saison.
	 */
	private static function single_fixtures( array $active ): string {
		$season_id = (int) $active['id'];
		$grouping  = SeasonAdmin::grouping();
		$groups    = array_column( $grouping->groups( $season_id ), 'name', 'id' );
		$options   = '';
		foreach ( $grouping->teams( $season_id ) as $team ) {
			if ( null !== $team['competition_id'] && isset( $groups[ $team['competition_id'] ] ) ) {
				$options .= '<option value="' . esc_attr( (string) $team['id'] ) . '">' . esc_html( $team['name'] . ' (' . $groups[ $team['competition_id'] ] . ')' ) . '</option>';
			}
		}
		/* translators: %s: Saison */
		$out = '<h3>' . esc_html( sprintf( __( 'Aktive Saison %s: einzelne Begegnung', 'ttleague' ), $active['name'] ) ) . '</h3>';
		if ( '' === $options ) {
			return $out . '<p>' . esc_html__( 'Die aktive Saison hat keine Mannschaften in einer Gruppe.', 'ttleague' ) . '</p>';
		}
		$hidden = array( 'season' => (string) $season_id );
		$out   .= '<details><summary class="button">' . esc_html__( 'Begegnung ansetzen (Entscheidungsspiel, Art. 15)', 'ttleague' ) . '</summary>'
			. SeasonPage::form( SeasonActions::FIXTURE_ADD, $season_id, $hidden )
			. '<label for="ttl-add-home">' . esc_html__( 'Heim', 'ttleague' ) . '</label><select id="ttl-add-home" name="home">' . $options . '</select>'
			. '<label for="ttl-add-away">' . esc_html__( 'Gast (gleiche Gruppe)', 'ttleague' ) . '</label><select id="ttl-add-away" name="away">' . $options . '</select>'
			. '<label for="ttl-add-date">' . esc_html__( 'Datum', 'ttleague' ) . '</label><input type="date" id="ttl-add-date" name="date" required>'
			. '<label for="ttl-add-time">' . esc_html__( 'Anspielzeit', 'ttleague' ) . '</label><input type="time" id="ttl-add-time" name="time" required>'
			. '<p class="description">' . esc_html__( 'Gespielt wird im Heimlokal der Heimmannschaft.', 'ttleague' ) . '</p>'
			. '<button type="submit" class="button">' . esc_html__( 'Ansetzen', 'ttleague' ) . '</button></form></details>';

		$open = '';
		foreach ( SeasonAdmin::scheduling()->fixtures( $season_id ) as $fixture ) {
			if ( in_array( $fixture['status'], array( Flow::SCHEDULED, Flow::POSTPONED ), true ) && null === $fixture['home_score'] ) {
				$open .= '<option value="' . esc_attr( (string) $fixture['id'] ) . '">' . esc_html( trim( Format::datetime( $fixture['scheduled_at'] ) . ' ' . $fixture['home'] . ' – ' . $fixture['away'] ) ) . '</option>';
			}
		}
		if ( '' !== $open ) {
			$out .= ' <details><summary class="button">' . esc_html__( 'Begegnung absagen', 'ttleague' ) . '</summary>'
				. SeasonPage::form( SeasonActions::FIXTURE_CANCEL, $season_id, $hidden )
				. '<label for="ttl-cancel-fixture">' . esc_html__( 'Begegnung ohne Resultat', 'ttleague' ) . '</label><select id="ttl-cancel-fixture" name="fixture">' . $open . '</select>'
				. '<label for="ttl-cancel-reason">' . esc_html__( 'Begründung (Pflicht)', 'ttleague' ) . '</label><textarea id="ttl-cancel-reason" name="reason" rows="2" required></textarea>'
				. '<button type="submit" class="button">' . esc_html__( 'Absagen', 'ttleague' ) . '</button></form></details>';
		}
		return $out;
	}

	/**
	 * Schritt 6: was noch fehlt, Bestätigungen und Freigabe mit dem Namen der Saison.
	 *
	 * @param array<string,mixed> $planned Geplante Saison.
	 * @throws SeasonException Wenn es die Saison nicht mehr gibt.
	 */
	private static function publish( array $planned ): string {
		$season_id = (int) $planned['id'];
		$state     = SeasonAdmin::lifecycle()->readiness( $season_id );
		$blocking  = array_values( array_filter( $state['open'], static fn( array $item ): bool => ! $item['confirmable'] ) );
		$confirm   = array_values( array_filter( $state['open'], static fn( array $item ): bool => $item['confirmable'] ) );
		$out       = '<p>' . esc_html__( 'Mit der Freigabe wird die geplante Saison aktiv und öffentlich, die bisher aktive wird archiviert. Die Captains erhalten ihre Rechte, und Captains und TK-Chefs bekommen den Spielplan. Über die Oberfläche lässt sich das nicht rückgängig machen.', 'ttleague' ) . '</p>';

		if ( array() !== $blocking ) {
			$out .= '<h3>' . esc_html__( 'Was noch fehlt', 'ttleague' ) . '</h3><ul class="ttl-season-list">';
			foreach ( $blocking as $item ) {
				$out .= '<li>' . esc_html( $item['message'] ) . '</li>';
			}
			$out .= '</ul>';
			if ( array() !== $confirm ) {
				$out .= '<p>' . esc_html__( 'Danach zu beheben oder ausdrücklich zu bestätigen:', 'ttleague' ) . '</p><ul class="ttl-season-list">';
				foreach ( $confirm as $item ) {
					$out .= '<li>' . esc_html( $item['message'] ) . '</li>';
				}
				$out .= '</ul>';
			}
			return $out;
		}

		$out .= '<h3>' . esc_html__( 'Bereit zur Freigabe', 'ttleague' ) . '</h3><p>' . esc_html__( 'Alle Meldungen sind geprüft, jede Mannschaft ist zugeteilt, und der Spielplan ist vollständig.', 'ttleague' ) . '</p>'
			. SeasonPage::form( SeasonActions::PUBLISH, $season_id, array( 'season' => (string) $season_id ) );
		foreach ( $confirm as $item ) {
			$out .= '<label class="ttl-season-soft"><input type="checkbox" name="' . esc_attr( 'accept[' . $item['code'] . ']' ) . '" value="1" required> '
				. esc_html( $item['message'] ) . ' <strong>' . esc_html__( 'Ich gebe trotzdem frei.', 'ttleague' ) . '</strong></label>';
		}
		return $out
			/* translators: %s: Saison */
			. '<label for="ttl-season-publish">' . esc_html( sprintf( __( 'Zur Bestätigung den Namen der Saison eingeben (%s)', 'ttleague' ), $planned['name'] ) ) . '</label>'
			. '<input type="text" id="ttl-season-publish" name="confirm" required autocomplete="off">'
			/* translators: %s: Saison */
			. '<button type="submit" class="button button-primary">' . esc_html( sprintf( __( 'Saison %s freigeben', 'ttleague' ), $planned['name'] ) ) . '</button></form>';
	}

	/**
	 * Schritt 7: während der Saison (Nachmeldefrist, Rückzug) und Abschluss der aktiven Saison.
	 *
	 * @param array<string,mixed>|null $active Aktive Saison.
	 * @throws SeasonException Wenn es die Saison nicht mehr gibt.
	 */
	private static function close( ?array $active ): string {
		if ( null === $active ) {
			return '<p>' . esc_html__( 'Es gibt keine aktive Saison. Die nächste Freigabe macht die geplante Saison aktiv.', 'ttleague' ) . '</p>';
		}
		$season_id = (int) $active['id'];
		$hidden    = array( 'season' => (string) $season_id );
		$memory    = SeasonActions::recall( 'window' );

		/* translators: %s: Saison */
		$out = '<h3>' . esc_html( sprintf( __( 'Während der Saison %s', 'ttleague' ), $active['name'] ) ) . '</h3>'
			. SeasonPage::form(
				SeasonActions::WINDOW,
				$season_id,
				$hidden + array(
					'step'                => (string) SeasonPage::CLOSE,
					'registration_opens'  => (string) $active['registration_opens'],
					'registration_closes' => (string) $active['registration_closes'],
				),
				'ttl-tk-inline'
			)
			. '<label for="ttl-season-late-active">' . esc_html__( 'Nachmeldungen bis', 'ttleague' ) . '</label> '
			. '<input type="date" id="ttl-season-late-active" name="late_registration_until" value="' . esc_attr( $memory['values']['late_registration_until'] ?? (string) $active['late_registration_until'] ) . '"'
			. ( isset( $memory['errors']['late_registration_until'] ) ? ' aria-invalid="true" aria-describedby="ttl-season-late-active-error"' : '' ) . '>'
			. '<button type="submit" class="button">' . esc_html__( 'Speichern', 'ttleague' ) . '</button>'
			. '<span class="ttl-tk-muted">' . esc_html__( 'Ohne Datum gilt der 31. Januar.', 'ttleague' ) . '</span>'
			. ( isset( $memory['errors']['late_registration_until'] ) ? '<span class="ttl-season-error" id="ttl-season-late-active-error">' . esc_html( $memory['errors']['late_registration_until'] ) . '</span>' : '' )
			. '</form>';

		$db        = Services::db();
		$options   = '';
		$withdrawn = array();
		foreach ( $db->get_results( "SELECT id, name, status FROM {$db->table( 'team' )} WHERE season_id = %d ORDER BY name, id", array( $season_id ) ) as $team ) {
			if ( 'active' === $team['status'] ) {
				$options .= '<option value="' . esc_attr( (string) $team['id'] ) . '">' . esc_html( (string) $team['name'] ) . '</option>';
			} else {
				$withdrawn[] = (string) $team['name'];
			}
		}
		$out .= '<h3>' . esc_html__( 'Rückzug einer Mannschaft (Art. 13.3)', 'ttleague' ) . '</h3>'
			. '<p>' . esc_html__( 'Alle Begegnungen der Mannschaft werden forfait gegen sie gewertet, auch schon gespielte; ihre Spiele zählen nicht mehr. In der Tabelle steht sie am Ende. Das lässt sich nur Begegnung für Begegnung über die Erfassung zurücknehmen.', 'ttleague' ) . '</p>';
		if ( array() !== $withdrawn ) {
			/* translators: %s: Mannschaften */
			$out .= '<p>' . esc_html( sprintf( __( 'Zurückgezogen: %s.', 'ttleague' ), implode( ', ', $withdrawn ) ) ) . '</p>';
		}
		if ( '' !== $options ) {
			$out .= '<details><summary class="button">' . esc_html__( 'Mannschaft zurückziehen', 'ttleague' ) . '</summary>'
				. SeasonPage::form( SeasonActions::WITHDRAW, $season_id, $hidden )
				. '<label for="ttl-withdraw-team">' . esc_html__( 'Mannschaft', 'ttleague' ) . '</label><select id="ttl-withdraw-team" name="team">' . $options . '</select>'
				. '<label for="ttl-withdraw-reason">' . esc_html__( 'Begründung (Pflicht, steht im Protokoll)', 'ttleague' ) . '</label><textarea id="ttl-withdraw-reason" name="reason" rows="3" maxlength="500" required></textarea>'
				. '<button type="submit" class="button">' . esc_html__( 'Zurückziehen', 'ttleague' ) . '</button></form></details>';
		}

		$out .= '<h3>' . esc_html__( 'Saison abschliessen', 'ttleague' ) . '</h3>';
		$open = SeasonAdmin::lifecycle()->closing( $season_id );
		if ( array() !== $open ) {
			$out .= '<p>' . esc_html__( 'Was noch fehlt:', 'ttleague' ) . '</p><ul class="ttl-season-list">';
			foreach ( $open as $item ) {
				$out .= '<li>' . esc_html( $item['message'] ) . '</li>';
			}
			return $out . '</ul>';
		}
		return $out . '<p>' . esc_html__( 'Alle Begegnungen haben ein Resultat oder einen Entscheid, und die Klassierung ist abgeschlossen. Nach dem Abschluss ist die Saison archiviert; ohne freigegebene Folgesaison gibt es bis zur nächsten Freigabe keine aktive Saison.', 'ttleague' ) . '</p>'
			. SeasonPage::form( SeasonActions::CLOSE, $season_id, $hidden )
			/* translators: %s: Saison */
			. '<label for="ttl-season-close">' . esc_html( sprintf( __( 'Zur Bestätigung den Namen der Saison eingeben (%s)', 'ttleague' ), $active['name'] ) ) . '</label>'
			. '<input type="text" id="ttl-season-close" name="confirm" required autocomplete="off">'
			/* translators: %s: Saison */
			. '<button type="submit" class="button button-primary">' . esc_html( sprintf( __( 'Saison %s abschliessen', 'ttleague' ), $active['name'] ) ) . '</button></form>';
	}

	/**
	 * Zeile einer Formulartabelle mit Fehler am Feld.
	 */
	private static function row( string $id, string $name, string $label, string $value, string $type, ?string $error, string $description = '' ): string {
		return '<tr' . ( null === $error ? '' : ' class="form-invalid"' ) . '><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>'
			. '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"'
			. ( null === $error ? '' : ' aria-invalid="true" aria-describedby="' . esc_attr( $id ) . '-error"' ) . '>'
			. ( null === $error ? '' : '<span class="ttl-season-error" id="' . esc_attr( $id ) . '-error">' . esc_html( $error ) . '</span>' )
			. ( '' === $description ? '' : '<p class="description">' . esc_html( $description ) . '</p>' )
			. '</td></tr>';
	}

	private static function rule_group_label( string $group ): string {
		return match ( $group ) {
			'eligibility'   => __( 'Spielberechtigung', 'ttleague' ),
			'deadlines'     => __( 'Fristen der Erfassung', 'ttleague' ),
			'notifications' => __( 'Erinnerungen', 'ttleague' ),
			'match'         => __( 'Begegnung der Meisterschaft', 'ttleague' ),
			'cup'           => __( 'Cup', 'ttleague' ),
			'ranking'       => __( 'Ranglistenturnier', 'ttleague' ),
			'rating'        => __( 'Wertungszahl und Klassierung', 'ttleague' ),
			default         => __( 'Spielplan', 'ttleague' ),
		};
	}

	private static function choice_label( string $choice ): string {
		return match ( $choice ) {
			SeasonRules::CUP_FIRST_TO_THREE => __( 'Ende beim dritten Sieg', 'ttleague' ),
			SeasonRules::CUP_ALL_GAMES      => __( 'Alle Spiele werden gewertet', 'ttleague' ),
			default                         => $choice,
		};
	}

	/**
	 * Bezeichnung und Erklärung jeder Regel.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function rule_labels(): array {
		return array(
			'substitute_max'             => array( __( 'Einsätze als Ersatz bis zur Bindung', 'ttleague' ), __( 'Mit diesem Einsatz ist ein Ersatzspieler an die Mannschaft gebunden (Art. 12.5).', 'ttleague' ) ),
			'category_any_team'          => array( __( 'Kategorien mit freiem Ersatz', 'ttleague' ), __( 'Kategorien, in denen unabhängig von der Mannschaftsnummer ausgeholfen werden darf (Art. 12.8), mit Komma getrennt, z. B. «D».', 'ttleague' ) ),
			'entry_deadline_hours'       => array( __( 'Frist für das Erfassen (Stunden)', 'ttleague' ), __( 'So viele Stunden nach Anspielzeit muss das Resultat erfasst sein (Art. 7.3).', 'ttleague' ) ),
			'objection_days'             => array( __( 'Einsprachefrist (Tage)', 'ttleague' ), __( 'Danach bestätigt die TK ein unbestätigtes Resultat.', 'ttleague' ) ),
			'forfeit_after_days'         => array( __( 'Forfait-Vorschlag nach (Tagen)', 'ttleague' ), __( 'Ohne Resultat so viele Tage nach Anspielzeit schlägt das System der TK ein Forfait vor (Art. 10.2).', 'ttleague' ) ),
			'postponement_approval_days' => array( __( 'Verschiebung mit Bewilligung ab (Tagen)', 'ttleague' ), __( 'Eine Verschiebung um mehr Tage braucht die Bewilligung der TK (Art. 10.2).', 'ttleague' ) ),
			'match_reminder_days'        => array( __( 'Erinnerung vor dem Spiel (Tage)', 'ttleague' ), __( '0 schaltet die Erinnerung aus.', 'ttleague' ) ),
			'result_reminder_hours'      => array( __( 'Erinnerung «Resultat fehlt» nach (Stunden)', 'ttleague' ), '' ),
			'confirm_reminder_days'      => array( __( 'Erinnerungen an die Gastseite (Tage nach der Erfassung)', 'ttleague' ), __( 'Mit Komma getrennt, z. B. «3, 6»; jede vor Ablauf der Einsprachefrist.', 'ttleague' ) ),
			'sets_to_win'                => array( __( 'Gewinnsätze pro Spiel', 'ttleague' ), '' ),
			'team_match_games'           => array( __( 'Spiele pro Begegnung', 'ttleague' ), __( 'Die Erfassung kennt die Spielfolge mit 10 Spielen. Nur nach einem Beschluss ändern.', 'ttleague' ) ),
			'forfeit_score'              => array( __( 'Forfait-Wertung', 'ttleague' ), __( 'Spiele für die Siegerseite, z. B. 10 für 10:0 (Art. 33).', 'ttleague' ) ),
			'two_player_max_wins'        => array( __( 'Höchster Sieg eines Teams zu zweit', 'ttleague' ), __( 'Z. B. 7 für 7:3 (Art. 14.4).', 'ttleague' ) ),
			'set_points_enabled'         => array( __( 'Satzpunkte erfassbar', 'ttleague' ), '' ),
			'cup_mode'                   => array( __( 'Wertung im Cup', 'ttleague' ), '' ),
			'cup_games'                  => array( __( 'Spiele pro Cup-Begegnung', 'ttleague' ), __( 'Die Erfassung kennt die Spielfolge mit 5 Spielen. Nur nach einem Beschluss ändern.', 'ttleague' ) ),
			'cup_players'                => array( __( 'Spieler pro Cup-Mannschaft', 'ttleague' ), '' ),
			'rl_round_points'            => array( __( 'Punkte pro Rang in einer Gruppe des Ranglistenturniers', 'ttleague' ), __( 'Mit Komma getrennt, Rang 1 zuerst.', 'ttleague' ) ),
			'rl_seven_player_bonus'      => array( __( 'Zusatzpunkt in grösseren Gruppen', 'ttleague' ), '' ),
			'rl_finalists'               => array( __( 'Finalteilnehmer pro Kategorie', 'ttleague' ), '' ),
			'rl_group_size'              => array( __( 'Übliche Grösse einer Gruppe', 'ttleague' ), '' ),
			'rl_group_size_max'          => array( __( 'Ausnahmsweise erlaubte Grösse', 'ttleague' ), '' ),
			'rating_k'                   => array( __( 'K-Faktor der Wertungszahl', 'ttleague' ), '' ),
			'rating_scale'               => array( __( 'Skalierung der Wertungszahl', 'ttleague' ), '' ),
			'rating_doubles_weight'      => array( __( 'Gewicht eines Doppels', 'ttleague' ), __( 'Zwischen 0 und 1.', 'ttleague' ) ),
			'rating_points_per_class'    => array( __( 'Wertungspunkte pro Klassierungsstufe', 'ttleague' ), '' ),
			'classification_min_games'   => array( __( 'Spiele für einen Klassierungsvorschlag', 'ttleague' ), __( 'Unter so vielen gewerteten Spielen schlägt das System keine Änderung vor.', 'ttleague' ) ),
			'schedule_tables_per_match'  => array( __( 'Tische pro Begegnung', 'ttleague' ), __( 'Für die Kapazitätsprüfung des Spielplans.', 'ttleague' ) ),
		);
	}
}
