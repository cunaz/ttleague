<?php
/**
 * Formular des Gruppenzettels: serverseitig gerendert, ohne JavaScript.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Frontend\Support\Format;

/**
 * Baut aus GroupEntry::entry() das Formular (Status je Person, pro Partie das Satzresultat als
 * Auswahl, auf Wunsch Satzpunkte und Sperrdaten der nächsten Runde) und übersetzt die
 * abgeschickten Felder in die Eingabe von GroupEntry::save(). Ohne WordPress-Abhängigkeit
 * ausser den Übersetzungs- und Escaping-Funktionen.
 *
 * Felder: status[Spieler], result[Nummer] («3:1» oder leer), points[Nummer] («11:8 9:11 11:5»),
 * next[Spieler][] (gesperrte Abende), next_take (Sperrdaten übernehmen), version.
 */
final class SheetForm {

	/**
	 * Mögliche Satzresultate bei $sets_to_win Gewinnsätzen, z. B. 3:0 bis 0:3.
	 *
	 * @return list<string>
	 */
	public static function results( int $sets_to_win ): array {
		$results = array();
		for ( $lost = 0; $lost < $sets_to_win; $lost++ ) {
			$results[] = $sets_to_win . ':' . $lost;
		}
		for ( $won = $sets_to_win - 1; $won >= 0; $won-- ) {
			$results[] = $won . ':' . $sets_to_win;
		}
		return $results;
	}

	/**
	 * Eingabe für GroupEntry::save() aus den Formularfeldern.
	 *
	 * @param array<string,mixed> $post Bereinigte Formularfelder.
	 * @return array<string,mixed>
	 */
	public static function payload( array $post ): array {
		$players = array();
		foreach ( is_array( $post['status'] ?? null ) ? $post['status'] : array() as $player_id => $status ) {
			$players[ (int) $player_id ] = is_string( $status ) ? $status : '';
		}
		$points = is_array( $post['points'] ?? null ) ? $post['points'] : array();
		$games  = array();
		foreach ( is_array( $post['result'] ?? null ) ? $post['result'] : array() as $sequence => $result ) {
			$result = is_string( $result ) ? trim( $result ) : '';
			if ( '' === $result ) {
				continue;
			}
			$sets = explode( ':', $result );
			$game = array(
				'sequence' => (int) $sequence,
				'sets_a'   => $sets[0],
				'sets_b'   => $sets[1] ?? '',
			);
			$text = is_string( $points[ $sequence ] ?? null ) ? trim( $points[ $sequence ] ) : '';
			if ( '' !== $text ) {
				$game['set_points'] = array_map(
					static fn( string $set ): array => array_pad( explode( ':', $set, 2 ), 2, '' ),
					preg_split( '/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY )
				);
			}
			$games[] = $game;
		}
		$payload = array(
			'players' => $players,
			'games'   => $games,
			'version' => is_string( $post['version'] ?? null ) ? $post['version'] : '',
		);
		if ( ! empty( $post['next_take'] ) ) {
			$next = is_array( $post['next'] ?? null ) ? $post['next'] : array();
			// Gemeldet wird für alle, die gespielt haben; ohne Häkchen heisst «an jedem Abend frei».
			foreach ( $players as $player_id => $status ) {
				if ( 'played' === $status ) {
					$payload['next_blocked'][ $player_id ] = array_values( array_map( 'strval', is_array( $next[ $player_id ] ?? null ) ? $next[ $player_id ] : array() ) );
				}
			}
		}
		return $payload;
	}

	/**
	 * Kopf mit Runde, Gruppe, Termin und Lokal.
	 *
	 * @param array<string,mixed> $entry Aus GroupEntry::entry().
	 */
	public static function head( array $entry ): string {
		$group = $entry['group'];
		/* translators: 1: Kategorie, 2: Gruppe */
		$title = sprintf( __( 'Kategorie %1$d, Gruppe %2$d', 'ttleague' ), $group['category'], $group['group_no'] );
		$meta  = array();
		$when  = Format::datetime( $group['start_at'] );
		if ( '' !== $when ) {
			$meta[] = $when;
		}
		if ( null !== $group['venue'] ) {
			$meta[] = $group['venue']['name'];
		}
		if ( null !== $group['host'] ) {
			/* translators: %s: Platzclub */
			$meta[] = sprintf( __( 'Platzclub %s', 'ttleague' ), $group['host']['short'] );
		}
		return '<header class="ttl-entry__head">'
			. '<p class="ttl-kicker">' . esc_html( __( 'Ranglistenturnier', 'ttleague' ) . ' · ' . Notices::round_label( $group ) ) . '</p>'
			. '<h2 class="ttl-entry__title">' . esc_html( $title ) . '</h2>'
			. '<p class="ttl-entry__meta">' . esc_html( implode( ' · ', $meta ) ) . ' <span class="ttl-badge">' . esc_html( (string) $group['status_label'] ) . '</span></p>'
			. '</header>';
	}

	/**
	 * Formular zum Nachtragen.
	 *
	 * @param array<string,mixed>      $entry  Aus GroupEntry::entry().
	 * @param string                   $action Adresse von admin-post.php.
	 * @param string                   $hidden Versteckte Felder (Aktion, Gruppe, Nonce), fertig escaped.
	 * @param array<string,mixed>|null $draft  Zuletzt abgeschickte Felder nach einem Fehler.
	 */
	public static function form( array $entry, string $action, string $hidden, ?array $draft = null ): string {
		$id      = (int) $entry['group']['id'];
		$names   = array_column( $entry['players'], 'name', 'player_id' );
		$stored  = array_column( $entry['games'], null, 'sequence' );
		$results = self::results( (int) $entry['rules']['sets_to_win'] );
		$points  = (bool) $entry['rules']['set_points_enabled'];

		$out = '<form class="ttl-form ttl-rl-sheet" method="post" action="' . esc_url( $action ) . '">' . $hidden
			. '<input type="hidden" name="version" value="' . esc_attr( (string) $entry['version'] ) . '">';

		// Status je Person.
		$out .= '<h3>' . esc_html__( 'Wer hat gespielt?', 'ttleague' ) . '</h3>'
			. '<p class="ttl-muted">' . esc_html__( 'Disqualifiziert ist, wer 15 Minuten nach Spielbeginn nicht da ist. Wer forfait gibt, gibt seine ausstehenden Partien auf; seine Partien zählen nicht.', 'ttleague' ) . '</p>'
			. '<table class="ttl-table ttl-table--compact"><thead><tr><th scope="col">' . esc_html__( 'Nr.', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Name', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Status', 'ttleague' ) . '</th></tr></thead><tbody>';
		foreach ( $entry['players'] as $player ) {
			$player_id = (int) $player['player_id'];
			$current   = (string) ( $draft['status'][ $player_id ] ?? ( 'scheduled' === $player['status'] ? 'played' : $player['status'] ) );
			$field     = 'ttl-rl-status-' . $id . '-' . $player_id;
			$options   = '';
			foreach ( GroupEntry::STATUSES as $status ) {
				$options .= '<option value="' . esc_attr( $status ) . '"' . selected( $status, $current, false ) . '>' . esc_html( GroupEntry::status_label( $status ) ) . '</option>';
			}
			$out .= '<tr><td>' . esc_html( (string) $player['position'] ) . '</td>'
				. '<th scope="row"><label for="' . esc_attr( $field ) . '">' . esc_html( $player['name'] ) . '</label>'
				. ( null === $player['club'] ? '' : ' <span class="ttl-muted">' . esc_html( (string) $player['club'] ) . '</span>' )
				. ( $player['is_chief'] ? ' <span class="ttl-badge ttl-badge--muted">' . esc_html__( 'Gruppenchef', 'ttleague' ) . '</span>' : '' ) . '</th>'
				. '<td><select id="' . esc_attr( $field ) . '" name="' . esc_attr( 'status[' . $player_id . ']' ) . '">' . $options . '</select></td></tr>';
		}
		$out .= '</tbody></table>';

		// Partien in der Reihenfolge des Spielplans.
		$out .= '<h3>' . esc_html__( 'Partien', 'ttleague' ) . '</h3>'
			. '<p class="ttl-muted">' . esc_html__( 'Resultat aus Sicht der erstgenannten Person. Partien mit abgemeldeten oder disqualifizierten Personen bleiben leer.', 'ttleague' ) . '</p>'
			. '<table class="ttl-table ttl-table--compact"><thead><tr><th scope="col">' . esc_html__( 'Nr.', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Partie', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Sätze', 'ttleague' ) . '</th>'
			. ( $points ? '<th scope="col">' . esc_html__( 'Satzpunkte (freiwillig)', 'ttleague' ) . '</th>' : '' ) . '</tr></thead><tbody>';
		foreach ( $entry['order'] as $game ) {
			$sequence = (int) $game['sequence'];
			$pairing  = $names[ $game['a'] ] . ' – ' . $names[ $game['b'] ];
			$saved    = isset( $stored[ $sequence ] ) ? $stored[ $sequence ]['sets_a'] . ':' . $stored[ $sequence ]['sets_b'] : '';
			$current  = (string) ( null === $draft ? $saved : ( $draft['result'][ $sequence ] ?? '' ) );
			$field    = 'ttl-rl-result-' . $id . '-' . $sequence;
			$options  = '<option value="">' . esc_html__( 'nicht gespielt', 'ttleague' ) . '</option>';
			foreach ( $results as $result ) {
				$options .= '<option value="' . esc_attr( $result ) . '"' . selected( $result, $current, false ) . '>' . esc_html( $result ) . '</option>';
			}
			$out .= '<tr><td>' . esc_html( (string) $sequence ) . '</td><th scope="row"><label for="' . esc_attr( $field ) . '">' . esc_html( $pairing ) . '</label></th>'
				. '<td><select id="' . esc_attr( $field ) . '" name="' . esc_attr( 'result[' . $sequence . ']' ) . '">' . $options . '</select></td>';
			if ( $points ) {
				$text = implode( ' ', array_map( static fn( array $set ): string => $set[0] . ':' . $set[1], $stored[ $sequence ]['set_points'] ?? array() ) );
				/* translators: %s: Paarung */
				$out .= '<td><input type="text" name="' . esc_attr( 'points[' . $sequence . ']' ) . '" value="' . esc_attr( (string) ( null === $draft ? $text : ( $draft['points'][ $sequence ] ?? '' ) ) ) . '" placeholder="11:8 9:11 11:5" aria-label="' . esc_attr( sprintf( __( 'Satzpunkte %s', 'ttleague' ), $pairing ) ) . '"></td>';
			}
			$out .= '</tr>';
		}
		$out .= '</tbody></table>';

		// Sperrdaten der nächsten Runde.
		$next = $entry['next_round'];
		if ( null !== $next && array() !== $next['dates'] ) {
			/* translators: 1: Runde, 2: Woche */
			$out .= '<h3>' . esc_html( sprintf( __( 'Sperrdaten für die %1$s (%2$s)', 'ttleague' ), Notices::round_label( $next ), Format::date_range( $next['date_from'], $next['date_to'] ) ) ) . '</h3>'
				. '<p class="ttl-field ttl-field--check"><input type="checkbox" id="' . esc_attr( 'ttl-rl-next-' . $id ) . '" name="next_take" value="1"' . checked( true, ! empty( $draft['next_take'] ), false ) . '> '
				. '<label for="' . esc_attr( 'ttl-rl-next-' . $id ) . '">' . esc_html__( 'Ich habe die Anwesenden gefragt: Sperrdaten übernehmen (angekreuzt = an diesem Abend gesperrt, nichts angekreuzt = an jedem Abend frei).', 'ttleague' ) . '</label></p>'
				. '<table class="ttl-table ttl-table--compact"><thead><tr><th scope="col">' . esc_html__( 'Name', 'ttleague' ) . '</th>';
			foreach ( $next['dates'] as $date ) {
				$out .= '<th scope="col">' . esc_html( Format::date( $date ) ) . '</th>';
			}
			$out .= '</tr></thead><tbody>';
			foreach ( $entry['players'] as $player ) {
				$player_id = (int) $player['player_id'];
				$blocked   = null === $draft ? ( $next['blocked'][ $player_id ] ?? array() ) : (array) ( $draft['next'][ $player_id ] ?? array() );
				$out      .= '<tr><th scope="row">' . esc_html( $player['name'] ) . '</th>';
				foreach ( $next['dates'] as $date ) {
					/* translators: 1: Name, 2: Datum */
					$out .= '<td><input type="checkbox" name="' . esc_attr( 'next[' . $player_id . '][]' ) . '" value="' . esc_attr( $date ) . '"' . checked( true, in_array( $date, $blocked, true ), false ) . ' aria-label="' . esc_attr( sprintf( __( '%1$s gesperrt am %2$s', 'ttleague' ), $player['name'], Format::date_plain( $date ) ) ) . '"></td>';
				}
				$out .= '</tr>';
			}
			$out .= '</tbody></table>';
		}

		return $out . '<p class="ttl-actions"><button type="submit" class="ttl-button ttl-button--primary">' . esc_html( null === $entry['entered_at'] ? __( 'Gruppenzettel speichern', 'ttleague' ) : __( 'Änderung speichern', 'ttleague' ) ) . '</button></p></form>';
	}

	/**
	 * Erfasster Stand zum Lesen: Rangliste und Partien.
	 *
	 * @param array<string,mixed> $entry Aus GroupEntry::entry().
	 */
	public static function result( array $entry ): string {
		if ( null === $entry['entered_at'] ) {
			return '<p class="ttl-notice" role="status">' . esc_html__( 'Für diese Gruppe ist noch kein Gruppenzettel erfasst.', 'ttleague' ) . '</p>';
		}
		$out    = '<h3>' . esc_html__( 'Rangliste', 'ttleague' ) . '</h3>'
			. '<table class="ttl-table ttl-table--compact"><thead><tr><th scope="col">' . esc_html__( 'Rang', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Name', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Siege', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Sätze', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Punkte', 'ttleague' ) . '</th></tr></thead><tbody>';
		$shared = false;
		foreach ( $entry['ranking'] as $row ) {
			$ranked = null !== $row['rank'];
			$shared = $shared || $row['shared'];
			$out   .= '<tr><td>' . esc_html( $ranked ? $row['rank'] . '.' . ( $row['shared'] ? '*' : '' ) : '–' ) . '</td>'
				. '<th scope="row">' . esc_html( $row['name'] ) . ( $ranked ? '' : ' <span class="ttl-badge ttl-badge--muted">' . esc_html( GroupEntry::status_label( $row['status'] ) ) . '</span>' ) . '</th>'
				. '<td>' . esc_html( $ranked ? (string) $row['wins'] : '–' ) . '</td><td>' . esc_html( $ranked ? Format::ratio( $row['sets_won'], $row['sets_lost'] ) : '–' ) . '</td>'
				. '<td><strong>' . esc_html( Format::points( $row['points'] ) ) . '</strong></td></tr>';
		}
		$out .= '</tbody></table>';
		if ( $shared ) {
			$out .= '<p class="ttl-footnote">' . esc_html__( '* Gleichstand: Die Punkte der belegten Plätze sind geteilt. Entscheidet das Los, setzt die TK Rang und Punkte beim Abschluss der Runde.', 'ttleague' ) . '</p>';
		}
		$names = array_column( $entry['players'], 'name', 'player_id' );
		$items = '';
		foreach ( $entry['games'] as $game ) {
			$items .= '<li>' . esc_html( $names[ $game['a'] ] . ' – ' . $names[ $game['b'] ] ) . ' <span class="ttl-games-list__score">' . esc_html( $game['sets_a'] . ':' . $game['sets_b'] ) . '</span>'
				. ( $game['counts'] ? '' : ' <span class="ttl-badge ttl-badge--muted">' . esc_html__( 'nicht gewertet', 'ttleague' ) . '</span>' ) . '</li>';
		}
		/* translators: %d: Anzahl Partien */
		return $out . '<details class="ttl-details"><summary>' . esc_html( sprintf( _n( '%d Partie', '%d Partien', count( $entry['games'] ), 'ttleague' ), count( $entry['games'] ) ) ) . '</summary><ul class="ttl-games-list">' . $items . '</ul></details>';
	}
}
