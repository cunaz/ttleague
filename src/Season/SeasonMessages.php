<?php
/**
 * Text der Mitteilung «Spielplan freigegeben» (Schweizer Schreibweise) für E-Mail und Push.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Season;

use TTLeague\Frontend\Support\Format;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Notification;

/**
 * Eine Art: season_published. Bezug ist die Saison; jede Person erhält die Mitteilung einmal,
 * mit dem Spielplan ihrer Mannschaften und den Adressen der Kalender-Abos.
 */
final class SeasonMessages {

	public const PUBLISHED = 'season_published';

	public const REF_SEASON = 'season';

	public function __construct( private readonly SeasonLinks $links ) {}

	/**
	 * Mitteilung für eine Person.
	 *
	 * @param array{id:int,name:string,slug:string}                                                                                                                              $season Freigegebene Saison.
	 * @param list<array{name:string,slug:string,group:string,fixtures:list<array{id:int,scheduled_at:string|null,home:string,away:string,venue:string|null}>}> $teams  Mannschaften der Person mit ihren Begegnungen.
	 * @param list<array{name:string,slug:string}>                                                                                                                              $clubs  Clubs, deren TK-Chef die Person ist.
	 */
	public function published( array $season, array $teams, array $clubs ): Notification {
		/* translators: %s: Saison */
		$subject    = sprintf( __( 'Saison %s: Der Spielplan ist freigegeben', 'ttleague' ), $season['name'] );
		$paragraphs = array(
			/* translators: %s: Saison */
			sprintf( __( 'Die TK hat die Saison %s freigegeben. Der Spielplan der Meisterschaft steht fest und ist auf der Website veröffentlicht.', 'ttleague' ), $season['name'] ),
		);
		$lists      = array();
		$calendars  = array();
		foreach ( $teams as $team ) {
			$items = array();
			foreach ( $team['fixtures'] as $fixture ) {
				$when    = null === $fixture['scheduled_at'] ? __( 'Termin offen', 'ttleague' ) : Format::datetime( $fixture['scheduled_at'] );
				$items[] = array(
					'text' => $when . ': ' . $fixture['home'] . ' – ' . $fixture['away'] . ( null === $fixture['venue'] || '' === $fixture['venue'] ? '' : ' (' . $fixture['venue'] . ')' ),
					'url'  => $this->links->fixture( $fixture['id'] ),
				);
			}
			if ( array() !== $items ) {
				$lists[] = array(
					'heading' => '' === $team['group'] ? $team['name'] : $team['name'] . ', ' . $team['group'],
					'items'   => $items,
				);
			}
			$calendars[] = array(
				/* translators: %s: Mannschaft */
				'text' => sprintf( __( 'Kalender von %s', 'ttleague' ), $team['name'] ),
				'url'  => $this->links->team_calendar( $season['slug'], $team['slug'] ),
			);
		}
		foreach ( $clubs as $club ) {
			$calendars[] = array(
				/* translators: %s: Club */
				'text' => sprintf( __( 'Kalender aller Mannschaften von %s', 'ttleague' ), $club['name'] ),
				'url'  => $this->links->club_calendar( $club['slug'] ),
			);
		}
		if ( array() !== $calendars ) {
			$paragraphs[] = __( 'Mit dem Kalender-Abo stehen die Termine in deinem eigenen Kalender. Verschiebungen kommen von selbst nach.', 'ttleague' );
			$lists[]      = array(
				'heading' => __( 'Kalender abonnieren', 'ttleague' ),
				'items'   => $calendars,
			);
		}
		if ( array() !== $teams ) {
			$paragraphs[] = __( 'Als Captain oder TK-Chef erfasst du die Resultate ab jetzt auf der Website.', 'ttleague' );
		}
		$url = $this->links->schedule( $season['slug'] );
		return new Notification(
			EventTypes::SEASON_PUBLISHED,
			self::PUBLISHED,
			self::REF_SEASON,
			$season['id'],
			'',
			$subject,
			$paragraphs,
			$lists,
			array(
				'url'   => $url,
				'label' => __( 'Spielplan ansehen', 'ttleague' ),
			),
			$this->links->my_area(),
			array(
				'title' => $subject,
				'body'  => __( 'Der Spielplan der Meisterschaft steht fest.', 'ttleague' ),
				'url'   => $url,
				'tag'   => 'ttleague-season-' . $season['id'],
			)
		);
	}
}
