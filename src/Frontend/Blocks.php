<?php
/**
 * Dynamische Blöcke der öffentlichen Darstellung (block.json plus render_callback).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend;

use TTLeague\Frontend\Support\Html;
use TTLeague\Frontend\Views\ArchiveView;
use TTLeague\Frontend\Views\ClubsView;
use TTLeague\Frontend\Views\ClubView;
use TTLeague\Frontend\Views\CupView;
use TTLeague\Frontend\Views\FixtureList;
use TTLeague\Frontend\Views\GroupView;
use TTLeague\Frontend\Views\MatchReportView;
use TTLeague\Frontend\Views\PlayersView;
use TTLeague\Frontend\Views\PlayerView;
use TTLeague\Frontend\Views\RankingView;
use TTLeague\Frontend\Views\SeasonOverview;
use TTLeague\Frontend\Views\SeasonSwitcher;
use TTLeague\Frontend\Views\StandingsCompact;
use TTLeague\Frontend\Views\TeamView;
use TTLeague\Frontend\Views\View;
use TTLeague\ReadModel\ReadModel;

final class Blocks {

	public const CATEGORY = 'ttleague';

	/**
	 * Blockname (ohne Namensraum) => Ansicht.
	 */
	public const VIEWS = array(
		'season-overview'    => SeasonOverview::class,
		'group'              => GroupView::class,
		'match-report'       => MatchReportView::class,
		'team'               => TeamView::class,
		'clubs'              => ClubsView::class,
		'club'               => ClubView::class,
		'players'            => PlayersView::class,
		'player'             => PlayerView::class,
		'ranking-tournament' => RankingView::class,
		'cup'                => CupView::class,
		'latest-results'     => FixtureList::class,
		'upcoming-fixtures'  => FixtureList::class,
		'standings-compact'  => StandingsCompact::class,
		'season-switcher'    => SeasonSwitcher::class,
		'archive'            => ArchiveView::class,
	);

	/**
	 * Registriert alle Blöcke aus blocks/<name>/block.json (init).
	 */
	public static function register(): void {
		foreach ( array_keys( self::VIEWS ) as $name ) {
			$dir = TTLEAGUE_DIR . 'blocks/' . $name;
			if ( ! is_readable( $dir . '/block.json' ) ) {
				continue;
			}
			register_block_type(
				$dir,
				array(
					'render_callback' => static fn( array $attributes ): string => self::render_block( $name, $attributes ),
				)
			);
		}
	}

	/**
	 * Eigene Kategorie im Block-Inserter.
	 *
	 * @param array<int,array<string,mixed>> $categories Kategorien.
	 * @return array<int,array<string,mixed>>
	 */
	public static function category( array $categories ): array {
		foreach ( $categories as $category ) {
			if ( self::CATEGORY === ( $category['slug'] ?? '' ) ) {
				return $categories;
			}
		}
		$categories[] = array(
			'slug'  => self::CATEGORY,
			'title' => __( 'Spielbetrieb (ttleague)', 'ttleague' ),
			'icon'  => null,
		);
		return $categories;
	}

	/**
	 * Render-Callback eines Blocks: Ansicht im Block-Wrapper.
	 *
	 * @param array<string,mixed> $attributes Attribute.
	 */
	public static function render_block( string $name, array $attributes ): string {
		$class = 'ttl ttl-' . $name;
		return '<div ' . get_block_wrapper_attributes( array( 'class' => $class ) ) . '>' . self::render_view( $name, $attributes ) . '</div>';
	}

	/**
	 * Inneres HTML einer Ansicht; Fehler werden als Hinweis angezeigt statt die Seite abzubrechen.
	 *
	 * @param array<string,mixed> $attributes Attribute.
	 */
	public static function render_view( string $name, array $attributes ): string {
		if ( ! isset( self::VIEWS[ $name ] ) ) {
			return '';
		}
		try {
			return self::view( $name )->render( $attributes );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				return Html::notice( 'ttleague: ' . $e->getMessage(), 'warning' );
			}
			return Html::notice( __( 'Diese Daten können im Moment nicht angezeigt werden.', 'ttleague' ), 'warning' );
		}
	}

	private static function view( string $name ): View {
		$model   = ReadModel::instance();
		$urls    = Frontend::urls();
		$context = Router::current();
		if ( 'latest-results' === $name ) {
			return new FixtureList( $model, $urls, $context, false );
		}
		if ( 'upcoming-fixtures' === $name ) {
			return new FixtureList( $model, $urls, $context, true, Frontend::now() );
		}
		$class = self::VIEWS[ $name ];
		return new $class( $model, $urls, $context );
	}
}
