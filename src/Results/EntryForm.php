<?php
/**
 * Startzustand und Ansichtsmodell des Erfassungsformulars.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

use TTLeague\Domain\Rules\SeasonRules;

/**
 * Baut aus der Antwort von ResultService::entry() die Daten für das Formular (Spielfolge,
 * Kandidaten, Texte), den Startzustand und das Ansichtsmodell, an das die Direktiven der
 * Interactivity API gebunden sind. assets/js/entry-view.js rechnet dasselbe Modell im Browser
 * nach (buildView); beide müssen übereinstimmen, damit die serverseitig gerenderte Seite beim
 * Hydrieren gleich bleibt (tests/Unit/Results/EntryViewParityTest.php). Reine Klasse ohne
 * WordPress ausser den Übersetzungen.
 *
 * Zustand eines Slots: Spieler-ID als Text, «none» (Spieler fehlt) oder leer (noch nicht gewählt).
 * Zustand eines Spiels: st (leer, played, walkover, not_played), sh/sa (Sätze), win (Sieger bei
 * walkover), hp/ap (Doppelpaarung als Slots, z. B. «AC»), pts (Satzpunkte als Text).
 * Fehlt ein Spieler, schlägt das Modell «kampflos» für die andere Seite vor, solange das Spiel
 * nicht ausdrücklich erfasst ist; fehlen beide, «nicht gespielt».
 * Ist noch nichts erfasst, beginnt die Aufstellung mit dem Vorschlag aus dem letzten Spiel
 * (suggestion); das Modell kennzeichnet eine Seite, solange sie dem Vorschlag entspricht.
 */
final class EntryForm {

	public const MISSING = 'none';

	/** Entscheide der TK in der Reihenfolge des Formulars. */
	public const ACTIONS = array( 'confirm', 'finalize', 'correct', 'forfeit', 'reset' );

	/**
	 * Daten für das Formular (unveränderlich während der Bearbeitung).
	 *
	 * @param array<string,mixed> $entry       Antwort von ResultService::entry().
	 * @param array<int,string>   $extra_names Namen weiterer Spieler (z. B. aus einer Korrektur der TK).
	 * @return array<string,mixed>
	 */
	public static function data( array $entry, array $extra_names = array() ): array {
		$fixture     = $entry['fixture'];
		$rules       = $entry['rules'];
		$players     = array();
		$eligibility = array(
			'home' => array(),
			'away' => array(),
		);
		foreach ( array( 'home', 'away' ) as $side ) {
			foreach ( $entry['candidates'][ $side ] as $candidate ) {
				$id             = (string) $candidate['player_id'];
				$players[ $id ] = array(
					'name'  => (string) $candidate['name'],
					'label' => (string) $candidate['label'],
				);

				$eligibility[ $side ][ $id ] = array(
					'ok'     => (bool) $candidate['eligible'],
					'reason' => (string) $candidate['reason'],
					'note'   => 'stamm' === $candidate['reason'] ? '' : (string) $candidate['message'],
				);
			}
		}
		foreach ( $extra_names as $id => $name ) {
			if ( ! isset( $players[ (string) $id ] ) ) {
				$players[ (string) $id ] = array(
					'name'  => $name,
					'label' => '',
				);
			}
		}
		$order = array();
		foreach ( $entry['order'] as $game ) {
			$order[] = array(
				'seq'  => (int) $game['sequence'],
				'type' => (string) $game['type'],
				'home' => $game['home'],
				'away' => $game['away'],
			);
		}
		$suggestion = array();
		foreach ( array( 'home', 'away' ) as $side ) {
			$suggestion[ $side ] = array();
			foreach ( (array) ( $entry['suggestion'][ $side ] ?? array() ) as $slot => $id ) {
				if ( in_array( (string) $slot, $rules['slots'][ $side ], true ) && isset( $players[ (string) $id ] ) ) {
					$suggestion[ $side ][ (string) $slot ] = (string) $id;
				}
			}
		}
		$sets = (int) $rules['sets_to_win'];
		$cup  = 'CUP' === $fixture['type'];
		return array(
			'type'        => (string) $fixture['type'],
			'setsToWin'   => $sets,
			'maxSets'     => 2 * $sets - 1,
			'setPoints'   => (bool) $rules['set_points_enabled'],
			'cupTarget'   => $cup && SeasonRules::CUP_FIRST_TO_THREE === $rules['cup_mode'] ? intdiv( count( $order ), 2 ) + 1 : null,
			'slots'       => $rules['slots'],
			'order'       => $order,
			'teams'       => array(
				'home' => (string) $fixture['home']['name'],
				'away' => (string) $fixture['away']['name'],
			),
			'players'     => $players,
			'eligibility' => $eligibility,
			'suggestion'  => $suggestion,
			'scores'      => self::scores( $sets ),
			'strings'     => self::strings(),
		);
	}

	/**
	 * Schnellknöpfe der Satzresultate, z. B. 3:0, 3:1, 3:2, 2:3, 1:3, 0:3.
	 *
	 * @return list<array{0:int,1:int}>
	 */
	public static function scores( int $sets_to_win ): array {
		$scores = array();
		for ( $lost = 0; $lost < $sets_to_win; $lost++ ) {
			$scores[] = array( $sets_to_win, $lost );
		}
		for ( $won = $sets_to_win - 1; $won >= 0; $won-- ) {
			$scores[] = array( $won, $sets_to_win );
		}
		return $scores;
	}

	/**
	 * Startzustand: das erfasste Resultat, sonst ein leeres Formular mit der vorgeschlagenen
	 * Aufstellung aus dem letzten Spiel (data['suggestion']). Der Vorschlag ist kein Entwurf: Er
	 * ist der Ausgangszustand, mit dem das Skript Entwürfe im Browser vergleicht.
	 *
	 * @param array<string,mixed> $entry Antwort von ResultService::entry().
	 * @param array<string,mixed> $data  Daten aus data().
	 * @return array{lineup:array<string,array<string,string>>,games:array<string,array<string,mixed>>,comment:string}
	 */
	public static function initial( array $entry, array $data ): array {
		$current = $entry['current'];
		$games   = is_array( $current['games'] ) ? $current['games'] : array();
		$filled  = array() !== $games;
		$blank   = ! $filled && array() === (array) $current['lineup']['home'] && array() === (array) $current['lineup']['away'];
		$lineup  = array();
		foreach ( array( 'home', 'away' ) as $side ) {
			$saved           = (array) $current['lineup'][ $side ];
			$suggested       = $blank ? (array) ( $data['suggestion'][ $side ] ?? array() ) : array();
			$lineup[ $side ] = array();
			foreach ( $data['slots'][ $side ] as $slot ) {
				$id                       = $saved[ $slot ] ?? null;
				$lineup[ $side ][ $slot ] = null === $id ? ( $filled ? self::MISSING : (string) ( $suggested[ $slot ] ?? '' ) ) : (string) $id;
			}
		}

		$form = array();
		foreach ( $data['order'] as $game ) {
			$form[ (string) $game['seq'] ] = self::empty_game( $data['maxSets'] );
		}
		foreach ( $games as $game ) {
			$key = (string) $game['sequence'];
			if ( ! isset( $form[ $key ] ) ) {
				continue;
			}
			$row = self::empty_game( $data['maxSets'] );
			if ( 'played' === $game['status'] ) {
				$row['st'] = 'played';
				$row['sh'] = (int) $game['sets_home'];
				$row['sa'] = (int) $game['sets_away'];
				foreach ( array_values( $game['set_points'] ) as $i => $set ) {
					if ( $i < $data['maxSets'] ) {
						$row['pts'][ $i ] = array( (string) $set[0], (string) $set[1] );
					}
				}
			} elseif ( 'walkover' === $game['status'] ) {
				$row['st']  = 'walkover';
				$row['win'] = (int) $game['sets_home'] > (int) $game['sets_away'] ? 'home' : 'away';
			} else {
				$row['st'] = 'not_played';
			}
			if ( 'double' === $game['type'] ) {
				$row['hp'] = self::pair_of( $game['home_players'], $lineup['home'] );
				$row['ap'] = self::pair_of( $game['away_players'], $lineup['away'] );
			}
			$form[ $key ] = $row;
		}

		return array(
			'lineup'  => $lineup,
			'games'   => $form,
			'comment' => '',
		);
	}

	/**
	 * Ansichtsmodell zu einem Zustand (gleiche Rechnung wie buildView in assets/js/entry-view.js).
	 *
	 * @param array<string,mixed>  $data   Daten aus data().
	 * @param array<string,mixed>  $form   Zustand des Formulars.
	 * @param array<string,string> $errors Feld => Fehlermeldung.
	 * @param string               $mode   enter, correct oder leer (Formular verborgen).
	 * @param array<string,mixed>  $ui     reason (Einsprache), action (Entscheid der TK).
	 * @return array<string,mixed>
	 */
	public static function view( array $data, array $form, array $errors = array(), string $mode = 'enter', array $ui = array() ): array {
		$s       = $data['strings'];
		$teams   = $data['teams'];
		$lineup  = $form['lineup'];
		$present = array();
		$open    = array();
		foreach ( array( 'home', 'away' ) as $side ) {
			$present[ $side ] = array();
			$open[ $side ]    = 0;
			foreach ( $data['slots'][ $side ] as $slot ) {
				$value = (string) ( $lineup[ $side ][ $slot ] ?? '' );
				if ( self::is_player( $value ) ) {
					$present[ $side ][] = $slot;
				} elseif ( '' === $value ) {
					++$open[ $side ];
				}
			}
		}
		$name = static function ( string $side, string $slot ) use ( $data, $lineup, $s ): string {
			$value = (string) ( $lineup[ $side ][ $slot ] ?? '' );
			if ( self::is_player( $value ) ) {
				return (string) ( $data['players'][ $value ]['name'] ?? $s['unknown'] );
			}
			return self::MISSING === $value ? sprintf( $s['slotMissing'], $slot ) : $slot;
		};

		$view = array(
			'formHidden'  => '' === $mode,
			'correct'     => 'correct' === $mode,
			'lockBlocked' => 'correct' !== $mode,
			'formTitle'   => 'correct' === $mode ? $s['titleCorrect'] : $s['titleEnter'],
			'submit'      => 'correct' === $mode ? $s['submitCorrect'] : $s['submitEnter'],
			'comment'     => 'correct' === $mode ? $s['commentCorrect'] : $s['commentEnter'],
			'lineup'      => array( 'err' => $errors['lineup'] ?? '' ),
			'pairs'       => array(),
		);

		foreach ( array( 'home', 'away' ) as $side ) {
			// Vorschlag aus dem letzten Spiel: gekennzeichnet, solange die Seite ihm genau entspricht.
			$suggestion = (array) ( $data['suggestion'][ $side ] ?? array() );
			$suggested  = array() !== $suggestion;

			$view['lineup'][ $side ] = array( 'err' => $errors[ 'lineup.' . $side ] ?? '' );
			foreach ( $data['slots'][ $side ] as $slot ) {
				$value = (string) ( $lineup[ $side ][ $slot ] ?? '' );
				$error = $errors[ 'lineup.' . $side . '.' . $slot ] ?? '';
				if ( (string) ( $suggestion[ $slot ] ?? '' ) !== $value ) {
					$suggested = false;
				}

				$view['lineup'][ $side ][ $slot ] = array(
					'err'  => $error,
					'bad'  => '' !== $error,
					'note' => self::is_player( $value ) ? (string) ( $data['eligibility'][ $side ][ $value ]['note'] ?? '' ) : '',
				);
			}
			$view['lineup'][ $side ]['sug'] = $suggested;

			$view['pairs'][ $side ] = array();
			$slots                  = $data['slots'][ $side ];
			$count                  = count( $slots );
			for ( $i = 0; $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$label = $slots[ $i ] . ' + ' . $slots[ $j ];
					if ( in_array( $slots[ $i ], $present[ $side ], true ) && in_array( $slots[ $j ], $present[ $side ], true ) ) {
						$label .= ': ' . $name( $side, $slots[ $i ] ) . ' / ' . $name( $side, $slots[ $j ] );
					}
					$view['pairs'][ $side ][ $slots[ $i ] . $slots[ $j ] ] = $label;
				}
			}
		}

		$wins     = array(
			'home' => 0,
			'away' => 0,
		);
		$decided  = false;
		$required = 0;
		$done     = 0;
		foreach ( $data['order'] as $slot_game ) {
			$seq      = (int) $slot_game['seq'];
			$double   = 'double' === $slot_game['type'];
			$explicit = $form['games'][ (string) $seq ] ?? self::empty_game( $data['maxSets'] );
			$set      = '' !== $explicit['st'];
			$optional = $decided;

			if ( $double ) {
				$home_missing = count( $present['home'] ) + $open['home'] < 2;
				$away_missing = count( $present['away'] ) + $open['away'] < 2;
			} else {
				$home_missing = self::MISSING === ( $lineup['home'][ $slot_game['home'] ] ?? '' );
				$away_missing = self::MISSING === ( $lineup['away'][ $slot_game['away'] ] ?? '' );
			}
			$home_label = (string) $slot_game['home'];
			$away_label = (string) $slot_game['away'];

			$expected = null;
			if ( $home_missing && $away_missing ) {
				$expected = array(
					'st'  => 'not_played',
					'win' => '',
				);
			} elseif ( $home_missing ) {
				$expected = array(
					'st'  => 'walkover',
					'win' => 'away',
				);
			} elseif ( $away_missing ) {
				$expected = array(
					'st'  => 'walkover',
					'win' => 'home',
				);
			}
			$suggest = ! $set && ! $optional && null !== $expected;
			$effect  = $explicit;
			if ( $suggest ) {
				$effect        = self::empty_game( $data['maxSets'] );
				$effect['st']  = $expected['st'];
				$effect['win'] = $expected['win'];
			}
			$mismatch = $set && null !== $expected
				&& ! ( $optional && 'not_played' === $expected['st'] )
				&& ! ( $explicit['st'] === $expected['st'] && ( 'not_played' === $expected['st'] || $explicit['win'] === $expected['win'] ) );

			$hint = '';
			if ( null !== $expected && ( $suggest || $mismatch ) ) {
				$winner_team = $teams[ 'home' === $expected['win'] ? 'home' : 'away' ];
				if ( 'not_played' === $expected['st'] ) {
					$hint = $suggest ? $s['sugNotPlayed'] : $s['fixNotPlayed'];
				} elseif ( $double ) {
					$hint = sprintf( $suggest ? $s['sugDouble'] : $s['fixDouble'], $winner_team );
				} else {
					$hint = sprintf( $suggest ? $s['sugWalkover'] : $s['fixWalkover'], $home_missing ? $home_label : $away_label, $winner_team );
				}
			}

			$winner = null;
			$out    = $s['open'];
			if ( 'played' === $effect['st'] ) {
				$winner = $effect['sh'] > $effect['sa'] ? 'home' : 'away';
				$out    = $effect['sh'] . ':' . $effect['sa'];
			} elseif ( 'walkover' === $effect['st'] && '' !== $effect['win'] ) {
				$winner = $effect['win'];
				$out    = ( 'home' === $winner ? $data['setsToWin'] . ':0' : '0:' . $data['setsToWin'] ) . ' ' . $s['walkover'];
			} elseif ( 'walkover' === $effect['st'] ) {
				$out = $s['walkover'];
			} elseif ( 'not_played' === $effect['st'] ) {
				$out = $s['notPlayed'];
			}
			if ( ! $optional ) {
				++$required;
				if ( '' !== $effect['st'] ) {
					++$done;
				}
				if ( null !== $winner ) {
					++$wins[ $winner ];
				}
				if ( null !== $data['cupTarget'] && max( $wins ) >= $data['cupTarget'] ) {
					$decided = true;
				}
			}

			$game = array(
				'home'     => $double ? self::double_names( $data, $explicit['hp'], $present['home'], 'home', $name ) : $name( 'home', (string) $slot_game['home'] ),
				'away'     => $double ? self::double_names( $data, $explicit['ap'], $present['away'], 'away', $name ) : $name( 'away', (string) $slot_game['away'] ),
				'out'      => $out,
				'done'     => '' !== $effect['st'],
				'opt'      => $optional,
				'sug'      => $suggest,
				'hint'     => $hint,
				'fix'      => $mismatch,
				'fixSt'    => $mismatch ? $expected['st'] : '',
				'fixWin'   => $mismatch ? $expected['win'] : '',
				'wo'       => 'walkover' === $effect['st'],
				'woHome'   => 'walkover' === $effect['st'] && 'home' === $effect['win'],
				'woAway'   => 'walkover' === $effect['st'] && 'away' === $effect['win'],
				'np'       => 'not_played' === $effect['st'],
				'pairHome' => $double && count( $present['home'] ) > 2 && in_array( $effect['st'], array( '', 'played' ), true ),
				'pairAway' => $double && count( $present['away'] ) > 2 && in_array( $effect['st'], array( '', 'played' ), true ),
				'autoHome' => $double && 2 === count( $present['home'] ) ? sprintf( $s['auto'], $teams['home'], $name( 'home', $present['home'][0] ) . ' / ' . $name( 'home', $present['home'][1] ) ) : '',
				'autoAway' => $double && 2 === count( $present['away'] ) ? sprintf( $s['auto'], $teams['away'], $name( 'away', $present['away'][0] ) . ' / ' . $name( 'away', $present['away'][1] ) ) : '',
				'pts'      => $data['setPoints'] && $set && 'played' === $explicit['st'],
				'err'      => $errors[ 'games.' . $seq ] ?? '',
				'errHome'  => $errors[ 'games.' . $seq . '.home_players' ] ?? '',
				'errAway'  => $errors[ 'games.' . $seq . '.away_players' ] ?? '',
				'errPts'   => $errors[ 'games.' . $seq . '.set_points' ] ?? '',
				'errWin'   => $errors[ 'games.' . $seq . '.winner' ] ?? '',
			);
			foreach ( $data['scores'] as [ $h, $a ] ) {
				$game[ 'p' . $h . $a ] = 'played' === $effect['st'] && $effect['sh'] === $h && $effect['sa'] === $a;
			}
			$sets = $game['pts'] ? (int) $explicit['sh'] + (int) $explicit['sa'] : 0;
			for ( $k = 1; $k <= $data['maxSets']; $k++ ) {
				$game[ 's' . $k ]       = $k <= $sets;
				$game[ 'v' . $k . 'h' ] = (string) ( $explicit['pts'][ $k - 1 ][0] ?? '' );
				$game[ 'v' . $k . 'a' ] = (string) ( $explicit['pts'][ $k - 1 ][1] ?? '' );
			}
			$game['badHome'] = '' !== $game['errHome'];
			$game['badAway'] = '' !== $game['errAway'];
			$game['bad']     = '' !== $game['err'] . $game['errHome'] . $game['errAway'] . $game['errPts'] . $game['errWin'];

			$view[ 'g' . $seq ] = $game;
		}

		$reason = trim( (string) ( $ui['reason'] ?? '' ) );
		$action = (string) ( $ui['action'] ?? '' );
		$count  = count( $errors );
		return $view + array(
			'total'         => $wins['home'] . ':' . $wins['away'],
			'progress'      => $decided ? $s['decided'] : sprintf( $s['progress'], $done, $required ),
			'decided'       => $decided,
			'gamesErr'      => $errors['games'] ?? '',
			'commentErr'    => $errors['comment'] ?? '',
			'photoErr'      => $errors['file'] ?? '',
			'generalErr'    => $errors['general'] ?? '',
			'reasonErr'     => $errors['reason'] ?? '',
			'reasonBad'     => isset( $errors['reason'] ),
			'decErr'        => $errors['action'] ?? '',
			'decWinErr'     => $errors['winner'] ?? '',
			'decCommentErr' => $errors['decComment'] ?? '',
			'hasErrors'     => $count > 0,
			'errorTitle'    => 1 === $count ? $s['errorOne'] : sprintf( $s['errorMany'], $count ),
			'reasonInfo'    => sprintf( $s['reasonCount'], mb_strlen( $reason ) ),
			'decForfeit'    => 'forfeit' === $action,
			'decCorrect'    => 'correct' === $action,
			'dec'           => array_combine( self::ACTIONS, array_map( static fn( string $a ): bool => $a === $action, self::ACTIONS ) ),
		);
	}

	/**
	 * Texte des Formulars (auch für das Skript).
	 *
	 * @return array<string,string>
	 */
	public static function strings(): array {
		return array(
			'unknown'          => __( 'Unbekannter Spieler', 'ttleague' ),
			/* translators: %s: Platz in der Aufstellung, z. B. A */
			'slotMissing'      => __( '%s fehlt', 'ttleague' ),
			/* translators: 1: Mannschaft, 2: Namen der beiden Doppelspieler */
			'auto'             => __( 'Doppel %1$s: %2$s (automatisch)', 'ttleague' ),
			'open'             => __( 'offen', 'ttleague' ),
			'walkover'         => __( 'w.o.', 'ttleague' ),
			'notPlayed'        => __( 'nicht gespielt', 'ttleague' ),
			/* translators: 1: Platz in der Aufstellung, 2: Mannschaft */
			'sugWalkover'      => __( 'Vorschlag: %1$s fehlt, kampflos für %2$s.', 'ttleague' ),
			'sugNotPlayed'     => __( 'Vorschlag: Beide Spieler fehlen, das Spiel gilt als nicht gespielt.', 'ttleague' ),
			/* translators: 1: Platz in der Aufstellung, 2: Mannschaft */
			'fixWalkover'      => __( '%1$s fehlt in der Aufstellung: Das Spiel ist kampflos für %2$s.', 'ttleague' ),
			'fixNotPlayed'     => __( 'Beide Spieler fehlen: Das Spiel gilt als nicht gespielt.', 'ttleague' ),
			/* translators: %s: Mannschaft */
			'sugDouble'        => __( 'Vorschlag: Für das Doppel fehlt ein Spieler, kampflos für %s.', 'ttleague' ),
			/* translators: %s: Mannschaft */
			'fixDouble'        => __( 'Für das Doppel fehlt ein Spieler: Das Spiel ist kampflos für %s.', 'ttleague' ),
			/* translators: 1: erfasste Spiele, 2: nötige Spiele */
			'progress'         => __( '%1$d von %2$d erfasst', 'ttleague' ),
			'decided'          => __( 'Entschieden, weitere Spiele sind freiwillig', 'ttleague' ),
			'titleEnter'       => __( 'Spielbericht eintragen', 'ttleague' ),
			'titleCorrect'     => __( 'Resultat korrigieren (Entscheid der TK)', 'ttleague' ),
			'submitEnter'      => __( 'Speichern', 'ttleague' ),
			'submitCorrect'    => __( 'Korrektur speichern', 'ttleague' ),
			'commentEnter'     => __( 'Bemerkung (freiwillig)', 'ttleague' ),
			'commentCorrect'   => __( 'Bemerkung zum Entscheid (für den Verlauf)', 'ttleague' ),
			'errorOne'         => __( 'Bitte prüfe die markierte Angabe.', 'ttleague' ),
			/* translators: %d: Anzahl Fehler */
			'errorMany'        => __( 'Bitte prüfe die %d markierten Angaben.', 'ttleague' ),
			/* translators: %d: Anzahl Zeichen */
			'reasonCount'      => __( '%d Zeichen (mindestens 10, höchstens 2000)', 'ttleague' ),
			/* translators: 1: Platz in der Aufstellung, 2: Mannschaft */
			'slotLabel'        => __( 'Platz %1$s, %2$s', 'ttleague' ),
			'slotRequired'     => __( 'Bitte einen Spieler wählen oder «fehlt» angeben.', 'ttleague' ),
			/* translators: 1: Spielername, 2: Platz in der Aufstellung */
			'slotDuplicate'    => __( '%1$s steht schon auf Platz %2$s.', 'ttleague' ),
			/* translators: %d: Spielnummer */
			'gameRequired'     => __( 'Spiel %d: Das Resultat fehlt.', 'ttleague' ),
			/* translators: %d: Spielnummer */
			'winnerRequired'   => __( 'Spiel %d: Wer gewinnt kampflos?', 'ttleague' ),
			/* translators: %d: Spielnummer */
			'pairRequired'     => __( 'Spiel %d: Bitte die Doppelpaarung wählen.', 'ttleague' ),
			/* translators: %d: Spielnummer */
			'pointsIncomplete' => __( 'Spiel %d: Bitte alle Sätze vollständig erfassen oder die Satzpunkte leer lassen.', 'ttleague' ),
			'reasonLength'     => __( 'Bitte begründe die Einsprache (10 bis 2000 Zeichen).', 'ttleague' ),
			'actionRequired'   => __( 'Bitte wähle einen Entscheid.', 'ttleague' ),
			'winnerForfeit'    => __( 'Wer gewinnt forfait?', 'ttleague' ),
			'photoTooLarge'    => __( 'Das Foto ist zu gross (höchstens 10 MB).', 'ttleague' ),
			'photoUploading'   => __( 'Foto wird hochgeladen …', 'ttleague' ),
			'photoSaved'       => __( 'Foto gespeichert.', 'ttleague' ),
			'saving'           => __( 'Wird gespeichert …', 'ttleague' ),
			'savedEnter'       => __( 'Resultat gespeichert. Die Gastmannschaft wird um Bestätigung gebeten.', 'ttleague' ),
			'savedCorrect'     => __( 'Korrektur gespeichert. Das Resultat ist abgeschlossen.', 'ttleague' ),
			'confirmed'        => __( 'Danke! Das Resultat ist bestätigt.', 'ttleague' ),
			'objected'         => __( 'Einsprache erhoben. Die TK entscheidet.', 'ttleague' ),
			'decidedConfirm'   => __( 'Resultat bestätigt.', 'ttleague' ),
			'decidedFinalize'  => __( 'Resultat abgeschlossen.', 'ttleague' ),
			'decidedForfeit'   => __( 'Forfait gespeichert.', 'ttleague' ),
			'decidedReset'     => __( 'Begegnung zurückgesetzt.', 'ttleague' ),
			'askReset'         => __( 'Resultat, Aufstellung und Spiele dieser Begegnung löschen?', 'ttleague' ),
			'askForfeit'       => __( 'Die Begegnung forfait werten? Erfasste Spiele werden gelöscht.', 'ttleague' ),
			'draftRestored'    => __( 'Dein Entwurf wurde wiederhergestellt.', 'ttleague' ),
			'draftOffer'       => __( 'Es gibt einen Entwurf, der auf einem älteren Stand beruht. Wiederherstellen?', 'ttleague' ),
			'conflict'         => __( 'Die Begegnung wurde inzwischen geändert. Bitte lade die Seite neu; dein Entwurf bleibt erhalten.', 'ttleague' ),
			'session'          => __( 'Deine Anmeldung ist abgelaufen. Bitte melde dich neu an; dein Entwurf bleibt erhalten.', 'ttleague' ),
			'offline'          => __( 'Keine Verbindung zum Server. Dein Entwurf ist gespeichert; versuche es nochmals.', 'ttleague' ),
			/* translators: %d: HTTP-Status */
			'failed'           => __( 'Speichern fehlgeschlagen (Fehler %d). Versuche es nochmals.', 'ttleague' ),
		);
	}

	/**
	 * Spieler-ID (nicht leer und nicht «fehlt»)?
	 */
	public static function is_player( string $value ): bool {
		return 1 === preg_match( '/^[1-9][0-9]*$/', $value );
	}

	/**
	 * @return array{st:string,sh:int|null,sa:int|null,win:string,hp:string,ap:string,pts:list<array{0:string,1:string}>}
	 */
	private static function empty_game( int $max_sets ): array {
		return array(
			'st'  => '',
			'sh'  => null,
			'sa'  => null,
			'win' => '',
			'hp'  => '',
			'ap'  => '',
			'pts' => array_fill( 0, $max_sets, array( '', '' ) ),
		);
	}

	/**
	 * Doppelpaarung als Slots («AC») aus den Spieler-IDs; leer, wenn sie nicht aufgeht.
	 *
	 * @param list<int>             $players Spieler des Doppels.
	 * @param array<string,string>  $lineup  Slot => Spieler-ID.
	 */
	private static function pair_of( array $players, array $lineup ): string {
		if ( count( $lineup ) < 3 || 2 !== count( $players ) ) {
			return '';
		}
		$slots = array();
		foreach ( $lineup as $slot => $value ) {
			if ( in_array( (int) $value, array_map( 'intval', $players ), true ) ) {
				$slots[] = $slot;
			}
		}
		return 2 === count( $slots ) ? implode( '', $slots ) : '';
	}

	/**
	 * Namen einer Seite im Doppel.
	 *
	 * @param array<string,mixed>          $data    Daten.
	 * @param list<string>                 $present Besetzte Slots der Seite.
	 * @param callable(string,string):string $name  Name zu Seite und Slot.
	 */
	private static function double_names( array $data, string $pair, array $present, string $side, callable $name ): string {
		if ( 2 === count( $present ) ) {
			return $name( $side, $present[0] ) . ' / ' . $name( $side, $present[1] );
		}
		if ( 2 === strlen( $pair ) && in_array( $pair[0], $present, true ) && in_array( $pair[1], $present, true ) ) {
			return $name( $side, $pair[0] ) . ' / ' . $name( $side, $pair[1] );
		}
		return $data['teams'][ $side ];
	}
}
