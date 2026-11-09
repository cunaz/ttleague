<?php
/**
 * Dokumente: Reglemente, Ausschreibungen, Spielmodus, Formulare, Resultatlisten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Content;

/**
 * Ein Dokument ist ein Beitrag vom Typ ttl_document. Die Datei steckt als Datei-Block im Inhalt,
 * dazu kommen ein Dokumenttyp und optional eine Saison (beides Taxonomien mit der normalen
 * Bearbeitungsoberfläche von WordPress). Der Block ttleague/documents bzw. der Shortcode
 * [ttleague_documents] listet die Dokumente nach Typ gruppiert, mit direktem Link zur Datei.
 */
final class Documents {

	public const POST_TYPE  = 'ttl_document';
	public const TAX_TYPE   = 'ttl_document_type';
	public const TAX_SEASON = 'ttl_document_season';

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'        => array(
					'name'               => __( 'Dokumente', 'ttleague' ),
					'singular_name'      => __( 'Dokument', 'ttleague' ),
					'add_new'            => __( 'Dokument hinzufügen', 'ttleague' ),
					'add_new_item'       => __( 'Neues Dokument', 'ttleague' ),
					'edit_item'          => __( 'Dokument bearbeiten', 'ttleague' ),
					'view_item'          => __( 'Dokument ansehen', 'ttleague' ),
					'all_items'          => __( 'Alle Dokumente', 'ttleague' ),
					'search_items'       => __( 'Dokumente durchsuchen', 'ttleague' ),
					'not_found'          => __( 'Keine Dokumente gefunden.', 'ttleague' ),
					'not_found_in_trash' => __( 'Keine Dokumente im Papierkorb.', 'ttleague' ),
				),
				'public'        => true,
				'show_in_rest'  => true,
				'has_archive'   => false,
				'rewrite'       => array(
					'slug'       => 'dokument',
					'with_front' => false,
				),
				'menu_icon'     => 'dashicons-media-document',
				'menu_position' => 21,
				'supports'      => array( 'title', 'editor', 'excerpt', 'revisions' ),
				'template'      => array(
					array( 'core/file', array() ),
					array( 'core/paragraph', array( 'placeholder' => __( 'Kurzbeschreibung (optional)', 'ttleague' ) ) ),
				),
			)
		);

		register_taxonomy(
			self::TAX_TYPE,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Dokumenttypen', 'ttleague' ),
					'singular_name' => __( 'Dokumenttyp', 'ttleague' ),
				),
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'dokumenttyp' ),
			)
		);

		register_taxonomy(
			self::TAX_SEASON,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Saisons', 'ttleague' ),
					'singular_name' => __( 'Saison', 'ttleague' ),
				),
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'dokumente-saison' ),
			)
		);

		register_block_type(
			'ttleague/documents',
			array(
				'title'           => __( 'Dokumente', 'ttleague' ),
				'category'        => 'ttleague',
				'attributes'      => array(
					'type'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'season' => array(
						'type'    => 'string',
						'default' => '',
					),
					'limit'  => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
				'render_callback' => array( self::class, 'render_block' ),
			)
		);
		add_shortcode( 'ttleague_documents', array( self::class, 'render_shortcode' ) );
	}

	/**
	 * @param array<string,mixed> $attributes Blockattribute.
	 */
	public static function render_block( array $attributes ): string {
		return self::render( (string) ( $attributes['type'] ?? '' ), (string) ( $attributes['season'] ?? '' ), (int) ( $attributes['limit'] ?? 0 ) );
	}

	/**
	 * @param array<string,string>|string $atts Shortcode-Attribute.
	 */
	public static function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'type'   => '',
				'season' => '',
				'limit'  => '0',
			),
			is_array( $atts ) ? $atts : array(),
			'ttleague_documents'
		);
		return self::render( (string) $atts['type'], (string) $atts['season'], (int) $atts['limit'] );
	}

	/**
	 * Liste der Dokumente, gruppiert nach Typ.
	 *
	 * @param string $type   Slug eines Dokumenttyps (leer = alle).
	 * @param string $season Slug einer Saison (leer = alle).
	 * @param int    $limit  Höchstzahl (0 = alle).
	 */
	public static function render( string $type = '', string $season = '', int $limit = 0 ): string {
		$tax_query = array();
		if ( '' !== $type ) {
			$tax_query[] = array(
				'taxonomy' => self::TAX_TYPE,
				'field'    => 'slug',
				'terms'    => sanitize_title( $type ),
			);
		}
		if ( '' !== $season ) {
			$tax_query[] = array(
				'taxonomy' => self::TAX_SEASON,
				'field'    => 'slug',
				'terms'    => sanitize_title( $season ),
			);
		}
		$query = new \WP_Query(
			array(
				'post_type'           => self::POST_TYPE,
				'post_status'         => 'publish',
				'posts_per_page'      => $limit > 0 ? $limit : 200,
				'orderby'             => array(
					'date'  => 'DESC',
					'title' => 'ASC',
				),
				'tax_query'           => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);
		if ( ! $query->have_posts() ) {
			return '<p class="ttl-documents ttl-empty">' . esc_html__( 'Noch keine Dokumente vorhanden.', 'ttleague' ) . '</p>';
		}

		$groups = array();
		foreach ( $query->posts as $post ) {
			$terms              = get_the_terms( $post, self::TAX_TYPE );
			$label              = is_array( $terms ) && array() !== $terms ? $terms[0]->name : __( 'Weitere Dokumente', 'ttleague' );
			$groups[ $label ][] = $post;
		}
		ksort( $groups, SORT_NATURAL | SORT_FLAG_CASE );

		$html = '<div class="ttl-documents">';
		foreach ( $groups as $label => $posts ) {
			$html .= '<section class="ttl-documents__group"><h3 class="ttl-documents__type">' . esc_html( $label ) . '</h3><ul class="ttl-documents__list">';
			foreach ( $posts as $post ) {
				$file    = self::file( $post );
				$url     = null === $file ? get_permalink( $post ) : $file['url'];
				$seasons = get_the_terms( $post, self::TAX_SEASON );
				$meta    = array();
				if ( is_array( $seasons ) && array() !== $seasons ) {
					$meta[] = implode( ', ', wp_list_pluck( $seasons, 'name' ) );
				}
				if ( null !== $file && '' !== $file['ext'] ) {
					$meta[] = strtoupper( $file['ext'] ) . ( $file['size'] > 0 ? ', ' . size_format( $file['size'] ) : '' );
				}
				$html .= sprintf(
					'<li class="ttl-documents__item"><a class="ttl-documents__link" href="%s">%s</a>%s</li>',
					esc_url( (string) $url ),
					esc_html( get_the_title( $post ) ),
					array() === $meta ? '' : ' <span class="ttl-documents__meta">' . esc_html( implode( ' · ', $meta ) ) . '</span>'
				);
			}
			$html .= '</ul></section>';
		}
		return $html . '</div>';
	}

	/**
	 * Erste Datei eines Dokuments aus dem Datei-Block im Inhalt.
	 *
	 * @return array{url:string,ext:string,size:int}|null
	 */
	public static function file( \WP_Post $post ): ?array {
		foreach ( parse_blocks( $post->post_content ) as $block ) {
			if ( 'core/file' !== $block['blockName'] ) {
				continue;
			}
			$id   = (int) ( $block['attrs']['id'] ?? 0 );
			$href = (string) ( $block['attrs']['href'] ?? '' );
			if ( '' === $href && $id > 0 ) {
				$href = (string) wp_get_attachment_url( $id );
			}
			if ( '' === $href ) {
				continue;
			}
			$path = $id > 0 ? get_attached_file( $id ) : false;
			return array(
				'url'  => $href,
				'ext'  => strtolower( (string) pathinfo( (string) wp_parse_url( $href, PHP_URL_PATH ), PATHINFO_EXTENSION ) ),
				'size' => is_string( $path ) && is_readable( $path ) ? (int) filesize( $path ) : 0,
			);
		}
		return null;
	}
}
