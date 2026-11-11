<?php
/**
 * Inhalt der Erfassungsseite je nach Anmeldung und Rolle.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

use TTLeague\Domain\Match\FixtureWorkflow as Flow;

/**
 * Entscheidet, was die Seite zeigt: Hinweis zur Anmeldung, Hinweis ohne Rolle oder das
 * Formular mit Startzustand aus ResultService::entry(). Ohne WordPress-Abhängigkeit ausser den
 * Übersetzungen; Adressen, Nonce und Fotoadresse kommen von aussen (EntryBlock).
 */
final class EntryPage {

	/** Höchstgrösse eines Fotos in Bytes (wie die REST-API). */
	private const PHOTO_MAX_BYTES = 10485760;

	public function __construct(
		private readonly ResultService $service,
		private readonly \DateTimeZone $timezone,
	) {}

	/**
	 * @param int                                                    $fixture_id Begegnung aus der Adresse (0 = keine).
	 * @param Actor|null                                             $actor      Angemeldete Person oder null.
	 * @param array{login:string,my_area:string,report:string,rest:string} $links Adressen (Anmeldung mit Rücksprung, Mein Bereich, Matchreport, REST-Basis der Begegnung).
	 * @param (callable(int):string)|null                           $photo_url  Geschützte Adresse des Fotos einer Begegnung (Results::photo_url).
	 * @param (callable(int):string)|null                           $contacts   Fertiges HTML des Abschnitts «Kontakt» einer Begegnung (ContactBox::fixture_section); prüft das Recht selbst.
	 * @param (callable(int):string)|null                           $sections   Fertiges HTML weiterer Abschnitte der Module direkt unter dem Kopf (z. B. «Termin» der Verschiebung); prüfen ihr Recht selbst.
	 * @return array{html:string,state:array<string,mixed>|null,config:array<string,mixed>|null}
	 */
	public function build( int $fixture_id, ?Actor $actor, array $links, ?callable $photo_url = null, ?callable $contacts = null, ?callable $sections = null ): array {
		if ( null === $actor ) {
			return self::only( EntryMarkup::logged_out( $links['login'] ) );
		}
		$my_area = array( __( 'Zu meinem Bereich', 'ttleague' ) => $links['my_area'] );
		if ( $fixture_id <= 0 ) {
			return self::only( EntryMarkup::notice( __( 'Diese Seite braucht die Nummer einer Begegnung. Du findest deine Begegnungen unter «Meine Spiele».', 'ttleague' ), $my_area, 'info' ) );
		}
		try {
			$entry = $this->service->entry( $fixture_id, $actor );
		} catch ( ResultException $e ) {
			if ( 404 === $e->status ) {
				return self::only( EntryMarkup::notice( $e->getMessage(), $my_area ) );
			}
			$message = null === $actor->person_id && ! $actor->is_tk
				? __( 'Dein Konto ist mit keiner Person verknüpft. Bitte wende dich an die TK.', 'ttleague' )
				: $e->getMessage() . ' ' . __( 'Resultate erfasst die Heimmannschaft (Captain oder TK-Chef), bestätigt werden sie von der Gastmannschaft.', 'ttleague' );
			return self::only( EntryMarkup::notice( $message, array( __( 'Zum Matchreport', 'ttleague' ) => $links['report'] ) + $my_area ) );
		}

		$data      = EntryForm::data( $entry, $this->extra_names( $entry ) );
		$form      = EntryForm::initial( $entry, $data );
		$mode      = $entry['can']['enter'] ? 'enter' : '';
		$decisions = $this->decisions( $entry );
		$photo_id  = null === $photo_url ? null : $entry['current']['photo_id'];
		$photo     = null === $photo_id ? null : $photo_url( (int) $entry['fixture']['id'] );
		$photo     = '' === $photo ? null : $photo;
		$state     = array(
			'form'      => $form,
			'mode'      => $mode,
			'errors'    => new \stdClass(),
			// Bis das Skript übernimmt, bleiben Knöpfe und Felder gesperrt (callbacks.init gibt sie frei).
			'busy'      => true,
			'reason'    => '',
			'decision'  => array(
				'action'  => '',
				'winner'  => '',
				'comment' => '',
			),
			'message'   => array(
				'text'    => '',
				'success' => false,
				'warning' => false,
				'reload'  => false,
				'login'   => false,
			),
			'draft'     => array(
				'text'  => '',
				'offer' => false,
			),
			'photo'     => array(
				'url'   => $photo,
				// Fotos haben keine Bildvarianten (PhotoStorage); die Vorschau verkleinert das CSS.
				'thumb' => $photo,
				'text'  => null === $photo ? '' : __( 'Ein Foto ist gespeichert. Ein neues Foto ersetzt es.', 'ttleague' ),
				'busy'  => false,
			),
			'view'      => EntryForm::view( $data, $form, array(), $mode ),
			'errorList' => array(),
		);
		$rest      = $links['rest'];
		$config    = $data + array(
			'fixture'       => $fixture_id,
			'version'       => (string) $entry['version'],
			'initialForm'   => $form,
			'initialMode'   => $mode,
			'decisions'     => $decisions,
			'maxPhotoBytes' => self::PHOTO_MAX_BYTES,
			'draftKey'      => 'ttleague-entry-' . $fixture_id . '-' . $actor->user_id,
			'flashKey'      => 'ttleague-entry-flash-' . $fixture_id,
			'urls'          => array(
				'result'    => $rest . 'result',
				'confirm'   => $rest . 'confirm',
				'objection' => $rest . 'objection',
				'decision'  => $rest . 'decision',
				'photo'     => $rest . 'photo',
			),
		);
		$markup    = new EntryMarkup(
			$entry,
			$data,
			$state,
			array(
				'report'    => $links['report'],
				'login'     => $links['login'],
				'decisions' => $decisions,
				// Nur hier, also nur für Personen mit Rolle für diese Begegnung.
				'contacts'  => null === $contacts ? '' : $contacts( $fixture_id ),
				'sections'  => null === $sections ? '' : $sections( $fixture_id ),
			),
			$this->timezone
		);
		return array(
			'html'   => $markup->render(),
			'state'  => $state,
			'config' => $config,
		);
	}

	/**
	 * Entscheide, die die TK im aktuellen Status treffen kann.
	 *
	 * @param array<string,mixed> $entry Antwort von ResultService::entry().
	 * @return list<string>
	 */
	public function decisions( array $entry ): array {
		if ( ! $entry['can']['decide'] ) {
			return array();
		}
		$status  = (string) $entry['fixture']['status'];
		$allowed = array();
		foreach ( EntryForm::ACTIONS as $action ) {
			if ( ! Flow::allows( ResultService::DECISIONS[ $action ], $status, array( Flow::ROLE_TK ) ) ) {
				continue;
			}
			if ( 'finalize' === $action && null === $entry['fixture']['home_score'] ) {
				continue;
			}
			$allowed[] = $action;
		}
		return $allowed;
	}

	/**
	 * Namen von Spielern im Resultat, die nicht unter den Kandidaten sind (z. B. nach einer Korrektur der TK).
	 *
	 * @param array<string,mixed> $entry Antwort von ResultService::entry().
	 * @return array<int,string>
	 */
	private function extra_names( array $entry ): array {
		$known = array();
		foreach ( array( 'home', 'away' ) as $side ) {
			foreach ( $entry['candidates'][ $side ] as $candidate ) {
				$known[ (int) $candidate['player_id'] ] = true;
			}
		}
		$ids = array();
		foreach ( array( 'home', 'away' ) as $side ) {
			foreach ( (array) $entry['current']['lineup'][ $side ] as $id ) {
				$ids[] = (int) $id;
			}
		}
		foreach ( $entry['current']['games'] as $game ) {
			$ids = array_merge( $ids, $game['home_players'], $game['away_players'] );
		}
		$missing = array_values( array_filter( array_unique( $ids ), static fn( int $id ): bool => ! isset( $known[ $id ] ) ) );
		return array() === $missing ? array() : $this->service->repository()->player_names( $missing );
	}

	/**
	 * @return array{html:string,state:null,config:null}
	 */
	private static function only( string $html ): array {
		return array(
			'html'   => $html,
			'state'  => null,
			'config' => null,
		);
	}
}
