/**
 * ttleague – Blöcke im Editor (ohne Build-Schritt).
 *
 * Die Metadaten (Titel, Attribute, Kategorie) kommen aus den block.json-Dateien, die
 * WordPress serverseitig registriert und an den Editor übergibt. Hier kommen nur die
 * Bearbeitungsansicht (ServerSideRender) und einfache Einstellungen dazu.
 * Globals: wp.blocks, wp.element, wp.serverSideRender, wp.blockEditor, wp.components, wp.i18n.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function ( text ) {
		return text;
	};
	var ServerSideRender = wp.serverSideRender;
	var blockEditor = wp.blockEditor || {};
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var components = wp.components || {};
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var RangeControl = components.RangeControl;
	var ToggleControl = components.ToggleControl;
	var Disabled = components.Disabled;
	var Placeholder = components.Placeholder;

	var data = window.ttleagueEditor || { seasons: [], clubs: [] };

	function withEmpty( options, label ) {
		return [ { value: '', label: label } ].concat( options || [] );
	}

	function seasonControl( props, help ) {
		return el( SelectControl, {
			key: 'season',
			label: __( 'Saison', 'ttleague' ),
			help: help || __( 'Leer: aus der Adresse, sonst die laufende Saison.', 'ttleague' ),
			value: props.attributes.season || '',
			options: withEmpty( data.seasons, __( 'Automatisch', 'ttleague' ) ),
			onChange: function ( value ) {
				props.setAttributes( { season: value } );
			},
			__nextHasNoMarginBottom: true,
		} );
	}

	function textControl( props, name, label, help ) {
		return el( TextControl, {
			key: name,
			label: label,
			help: help,
			value: props.attributes[ name ] || '',
			onChange: function ( value ) {
				var attrs = {};
				attrs[ name ] = value;
				props.setAttributes( attrs );
			},
			__nextHasNoMarginBottom: true,
		} );
	}

	function numberControl( props, name, label, help ) {
		return el( TextControl, {
			key: name,
			type: 'number',
			min: 0,
			label: label,
			help: help,
			value: props.attributes[ name ] ? String( props.attributes[ name ] ) : '',
			onChange: function ( value ) {
				var attrs = {};
				attrs[ name ] = parseInt( value, 10 ) || 0;
				props.setAttributes( attrs );
			},
			__nextHasNoMarginBottom: true,
		} );
	}

	function countControl( props ) {
		return el( RangeControl, {
			key: 'count',
			label: __( 'Anzahl', 'ttleague' ),
			min: 1,
			max: 20,
			value: props.attributes.count || 6,
			onChange: function ( value ) {
				props.setAttributes( { count: value } );
			},
			__nextHasNoMarginBottom: true,
		} );
	}

	function clubControl( props ) {
		return el( SelectControl, {
			key: 'club',
			label: __( 'Club', 'ttleague' ),
			value: props.attributes.club || '',
			options: withEmpty( data.clubs, __( 'Automatisch / alle', 'ttleague' ) ),
			onChange: function ( value ) {
				props.setAttributes( { club: value } );
			},
			__nextHasNoMarginBottom: true,
		} );
	}

	/**
	 * Einstellungen pro Block: Funktion (props) => Liste von Controls.
	 */
	var settings = {
		'ttleague/season-overview': function ( p ) {
			return [ seasonControl( p ) ];
		},
		'ttleague/group': function ( p ) {
			return [
				seasonControl( p ),
				textControl( p, 'group', __( 'Gruppe', 'ttleague' ), __( 'Kürzel wie a, b oder a-2. Leer: aus der Adresse.', 'ttleague' ) ),
				el( SelectControl, {
					key: 'show',
					label: __( 'Anzeigen', 'ttleague' ),
					value: p.attributes.show || 'all',
					options: [
						{ value: 'all', label: __( 'Tabelle und Spielplan', 'ttleague' ) },
						{ value: 'table', label: __( 'Nur Tabelle', 'ttleague' ) },
						{ value: 'schedule', label: __( 'Nur Spielplan', 'ttleague' ) },
					],
					onChange: function ( value ) {
						p.setAttributes( { show: value } );
					},
					__nextHasNoMarginBottom: true,
				} ),
			];
		},
		'ttleague/match-report': function ( p ) {
			return [ numberControl( p, 'fixture', __( 'Begegnung (Nummer)', 'ttleague' ), __( '0: aus der Adresse /begegnung/123/.', 'ttleague' ) ) ];
		},
		'ttleague/team': function ( p ) {
			return [
				seasonControl( p ),
				textControl( p, 'team', __( 'Mannschaft', 'ttleague' ), __( 'Kürzel wie seefeld-1. Leer: aus der Adresse.', 'ttleague' ) ),
			];
		},
		'ttleague/clubs': function ( p ) {
			return [ seasonControl( p ) ];
		},
		'ttleague/club': function ( p ) {
			return [ seasonControl( p ), clubControl( p ) ];
		},
		'ttleague/players': function ( p ) {
			return [ seasonControl( p ), clubControl( p ) ];
		},
		'ttleague/player': function ( p ) {
			return [ numberControl( p, 'player', __( 'Lizenznummer', 'ttleague' ), __( '0: aus der Adresse /spieler/90001-hans-muster/.', 'ttleague' ) ) ];
		},
		'ttleague/ranking-tournament': function ( p ) {
			return [
				seasonControl( p ),
				el( SelectControl, {
					key: 'category',
					label: __( 'Kategorie', 'ttleague' ),
					value: String( p.attributes.category || 0 ),
					options: [
						{ value: '0', label: __( 'Alle', 'ttleague' ) },
						{ value: '1', label: '1' },
						{ value: '2', label: '2' },
						{ value: '3', label: '3' },
					],
					onChange: function ( value ) {
						p.setAttributes( { category: parseInt( value, 10 ) || 0 } );
					},
					__nextHasNoMarginBottom: true,
				} ),
			];
		},
		'ttleague/cup': function ( p ) {
			return [
				seasonControl( p ),
				el( SelectControl, {
					key: 'category',
					label: __( 'Kategorie', 'ttleague' ),
					value: p.attributes.category || '',
					options: [
						{ value: '', label: __( 'Alle', 'ttleague' ) },
						{ value: 'a', label: 'A' },
						{ value: 'b', label: 'B' },
					],
					onChange: function ( value ) {
						p.setAttributes( { category: value } );
					},
					__nextHasNoMarginBottom: true,
				} ),
			];
		},
		'ttleague/latest-results': function ( p ) {
			return [ seasonControl( p, __( 'Leer: alle Saisons.', 'ttleague' ) ), countControl( p ) ];
		},
		'ttleague/upcoming-fixtures': function ( p ) {
			return [ seasonControl( p, __( 'Leer: alle Saisons.', 'ttleague' ) ), countControl( p ) ];
		},
		'ttleague/standings-compact': function ( p ) {
			return [
				seasonControl( p ),
				textControl( p, 'group', __( 'Nur Gruppe', 'ttleague' ), __( 'Leer: alle Gruppen. Sonst Kürzel wie a.', 'ttleague' ) ),
			];
		},
		'ttleague/season-switcher': function ( p ) {
			return [ textControl( p, 'label', __( 'Beschriftung', 'ttleague' ), __( 'Leer: «Saison».', 'ttleague' ) ) ];
		},
		'ttleague/archive': function ( p ) {
			return [
				el( ToggleControl, {
					key: 'compact',
					label: __( 'Kompakte Liste', 'ttleague' ),
					checked: !! p.attributes.compact,
					onChange: function ( value ) {
						p.setAttributes( { compact: value } );
					},
					__nextHasNoMarginBottom: true,
				} ),
			];
		},
		'ttleague/documents': function ( p ) {
			return [
				textControl( p, 'type', __( 'Dokumenttyp', 'ttleague' ), __( 'Slug eines Dokumenttyps; leer: alle.', 'ttleague' ) ),
				textControl( p, 'season', __( 'Saison', 'ttleague' ), __( 'Slug einer Saison; leer: alle.', 'ttleague' ) ),
				numberControl( p, 'limit', __( 'Höchstzahl', 'ttleague' ), __( '0: alle.', 'ttleague' ) ),
			];
		},
		'ttleague/login': function () {
			return [];
		},
		'ttleague/my-area': function () {
			return [];
		},
		'ttleague/account-link': function () {
			return [];
		},
		'ttleague/result-entry': function () {
			return [];
		},
	};

	/**
	 * Blöcke mit persönlichem Inhalt: im Editor nur ein Platzhalter statt der Vorschau.
	 */
	var placeholders = {
		'ttleague/login': {
			icon: 'admin-users',
			label: __( 'Anmelden', 'ttleague' ),
			text: __( 'Hier erscheint das Formular für den Anmeldelink. Besucher geben ihre E-Mail-Adresse ein und erhalten einen Link.', 'ttleague' ),
		},
		'ttleague/my-area': {
			icon: 'id-alt',
			label: __( 'Mein Bereich', 'ttleague' ),
			text: __( 'Hier erscheinen für angemeldete Personen Einwilligung, Profil, Benachrichtigungen und ihre Spiele.', 'ttleague' ),
		},
		'ttleague/result-entry': {
			icon: 'edit',
			label: __( 'Resultat erfassen', 'ttleague' ),
			text: __( 'Hier erscheint für angemeldete Captains, TK-Chefs und die TK die Erfassung der Begegnung aus der Adresse (?begegnung=123): Resultat erfassen, bestätigen, Einsprache, Entscheid der TK.', 'ttleague' ),
		},
	};

	/**
	 * Fallback-Metadaten für Blöcke, die ohne block.json registriert sind.
	 */
	var fallback = {
		'ttleague/documents': {
			title: __( 'Dokumente', 'ttleague' ),
			description: __( 'Dokumente nach Typ gruppiert.', 'ttleague' ),
			icon: 'media-document',
			attributes: {
				type: { type: 'string', default: '' },
				season: { type: 'string', default: '' },
				limit: { type: 'integer', default: 0 },
			},
		},
	};

	function makeEdit( name ) {
		return function Edit( props ) {
			var blockProps = useBlockProps ? useBlockProps() : {};
			var controls = settings[ name ] ? settings[ name ]( props ) : [];
			var info = placeholders[ name ];
			if ( info ) {
				return el(
					'div',
					blockProps,
					Placeholder
						? el( Placeholder, { icon: info.icon, label: info.label, instructions: info.text } )
						: el( 'p', null, info.text )
				);
			}
			var preview = el( ServerSideRender, {
				block: name,
				attributes: props.attributes,
				httpMethod: 'POST',
			} );
			return el(
				Fragment,
				null,
				controls.length && InspectorControls
					? el( InspectorControls, null, el( PanelBody, { title: __( 'Einstellungen', 'ttleague' ), initialOpen: true }, controls ) )
					: null,
				el( 'div', blockProps, Disabled ? el( Disabled, null, preview ) : preview )
			);
		};
	}

	Object.keys( settings ).forEach( function ( name ) {
		var existing = wp.blocks.getBlockType( name );
		if ( existing ) {
			wp.blocks.unregisterBlockType( name );
		}
		var config = {
			apiVersion: 3,
			category: 'ttleague',
			edit: makeEdit( name ),
			save: function () {
				return null;
			},
		};
		var extra = fallback[ name ] || {};
		Object.keys( extra ).forEach( function ( key ) {
			config[ key ] = extra[ key ];
		} );
		if ( existing ) {
			[ 'title', 'description', 'icon', 'attributes', 'supports', 'keywords' ].forEach( function ( key ) {
				if ( existing[ key ] !== undefined ) {
					config[ key ] = existing[ key ];
				}
			} );
		}
		wp.blocks.registerBlockType( name, config );
	} );
} )( window.wp );
