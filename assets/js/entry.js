/**
 * ttleague – Resultaterfassung (Interactivity API, ohne Build-Schritt).
 *
 * Der Block ttleague/result-entry rendert die Seite serverseitig mit dem Startzustand aus
 * ResultService::entry(); dieses Modul übernimmt danach. buildView() rechnet dasselbe
 * Ansichtsmodell wie TTLeague\Results\EntryForm::view() in PHP: Änderungen immer an beiden
 * Stellen nachführen. Unveränderliche Daten (Spielfolge, Kandidaten, Texte, Adressen, Nonce)
 * kommen über die Konfiguration, alles Veränderliche über den Zustand.
 *
 * Nach jeder erfolgreichen Aktion lädt die Seite neu (Meldung über sessionStorage), damit
 * Status, Verlauf und Hinweise wieder vom Server kommen. Ein Entwurf des Formulars liegt im
 * localStorage, solange er nicht gespeichert ist.
 */
import {
	store,
	getContext,
	getConfig,
	withSyncEvent,
} from '@wordpress/interactivity';

const NS = 'ttleague/entry';
const cfg = getConfig( NS );
const S = cfg.strings || {};
const MISSING = 'none';
const SIDES = [ 'home', 'away' ];

/**
 * Platzhalter wie in PHP: %s, %d, %1$s, %2$d.
 *
 * @param {string}    text Vorlage.
 * @param {...*}      args Werte.
 * @return {string} Text.
 */
function fmt( text, ...args ) {
	let next = 0;
	return String( text ).replace( /%(?:(\d+)\$)?[sd]/g, ( match, position ) => {
		const value = position ? args[ Number( position ) - 1 ] : args[ next++ ];
		return undefined === value ? match : String( value );
	} );
}

function isPlayer( value ) {
	return /^[1-9][0-9]*$/.test( String( value ) );
}

function emptyGame() {
	return {
		st: '',
		sh: null,
		sa: null,
		win: '',
		hp: '',
		ap: '',
		pts: Array.from( { length: cfg.maxSets }, () => [ '', '' ] ),
	};
}

function clone( value ) {
	return JSON.parse( JSON.stringify( value ) );
}

/**
 * Besetzte und offene Slots, Vorschläge und Wirkung jedes Spiels (wie EntryForm::view()).
 *
 * @param {Object} form Zustand des Formulars.
 * @return {Object} Analyse.
 */
function analyse( form ) {
	const lineup = form.lineup;
	const present = { home: [], away: [] };
	const open = { home: 0, away: 0 };
	for ( const side of SIDES ) {
		for ( const slot of cfg.slots[ side ] ) {
			const value = String( lineup[ side ]?.[ slot ] ?? '' );
			if ( isPlayer( value ) ) {
				present[ side ].push( slot );
			} else if ( '' === value ) {
				open[ side ]++;
			}
		}
	}
	const wins = { home: 0, away: 0 };
	const games = {};
	let decided = false;
	let required = 0;
	let done = 0;
	for ( const slotGame of cfg.order ) {
		const seq = slotGame.seq;
		const double = 'double' === slotGame.type;
		const explicit = form.games[ seq ] ?? emptyGame();
		const set = '' !== explicit.st;
		const optional = decided;
		let homeMissing;
		let awayMissing;
		if ( double ) {
			homeMissing = present.home.length + open.home < 2;
			awayMissing = present.away.length + open.away < 2;
		} else {
			homeMissing = MISSING === ( lineup.home?.[ slotGame.home ] ?? '' );
			awayMissing = MISSING === ( lineup.away?.[ slotGame.away ] ?? '' );
		}
		let expected = null;
		if ( homeMissing && awayMissing ) {
			expected = { st: 'not_played', win: '' };
		} else if ( homeMissing ) {
			expected = { st: 'walkover', win: 'away' };
		} else if ( awayMissing ) {
			expected = { st: 'walkover', win: 'home' };
		}
		const suggest = ! set && ! optional && null !== expected;
		const effect = suggest
			? { ...emptyGame(), st: expected.st, win: expected.win }
			: explicit;
		const mismatch =
			set &&
			null !== expected &&
			! ( optional && 'not_played' === expected.st ) &&
			! (
				explicit.st === expected.st &&
				( 'not_played' === expected.st || explicit.win === expected.win )
			);
		let winner = null;
		if ( 'played' === effect.st ) {
			winner = effect.sh > effect.sa ? 'home' : 'away';
		} else if ( 'walkover' === effect.st && '' !== effect.win ) {
			winner = effect.win;
		}
		if ( ! optional ) {
			required++;
			if ( '' !== effect.st ) {
				done++;
			}
			if ( null !== winner ) {
				wins[ winner ]++;
			}
			if (
				null !== cfg.cupTarget &&
				Math.max( wins.home, wins.away ) >= cfg.cupTarget
			) {
				decided = true;
			}
		}
		games[ seq ] = {
			slotGame,
			double,
			explicit,
			set,
			optional,
			homeMissing,
			awayMissing,
			expected,
			suggest,
			effect,
			mismatch,
			winner,
		};
	}
	return { present, open, games, wins, decided, required, done };
}

/**
 * Ansichtsmodell (Gegenstück zu EntryForm::view() in PHP).
 *
 * @param {Object} form   Zustand des Formulars.
 * @param {Object} errors Feld => Meldung.
 * @param {string} mode   enter, correct oder leer.
 * @param {Object} ui     reason, action.
 * @return {Object} Ansichtsmodell.
 */
function buildView( form, errors, mode, ui ) {
	const lineup = form.lineup;
	const result = analyse( form );
	const { present } = result;
	const err = ( key ) => errors[ key ] ?? '';
	const name = ( side, slot ) => {
		const value = String( lineup[ side ]?.[ slot ] ?? '' );
		if ( isPlayer( value ) ) {
			return cfg.players[ value ]?.name ?? S.unknown;
		}
		return MISSING === value ? fmt( S.slotMissing, slot ) : slot;
	};
	const correct = 'correct' === mode;
	const view = {
		formHidden: '' === mode,
		correct,
		lockBlocked: ! correct,
		formTitle: correct ? S.titleCorrect : S.titleEnter,
		submit: correct ? S.submitCorrect : S.submitEnter,
		comment: correct ? S.commentCorrect : S.commentEnter,
		lineup: { err: err( 'lineup' ) },
		pairs: {},
	};
	for ( const side of SIDES ) {
		view.lineup[ side ] = { err: err( `lineup.${ side }` ) };
		for ( const slot of cfg.slots[ side ] ) {
			const value = String( lineup[ side ]?.[ slot ] ?? '' );
			const error = err( `lineup.${ side }.${ slot }` );
			view.lineup[ side ][ slot ] = {
				err: error,
				bad: '' !== error,
				note: isPlayer( value )
					? String( cfg.eligibility[ side ]?.[ value ]?.note ?? '' )
					: '',
			};
		}
		view.pairs[ side ] = {};
		const slots = cfg.slots[ side ];
		for ( let i = 0; i < slots.length; i++ ) {
			for ( let j = i + 1; j < slots.length; j++ ) {
				let label = slots[ i ] + ' + ' + slots[ j ];
				if (
					present[ side ].includes( slots[ i ] ) &&
					present[ side ].includes( slots[ j ] )
				) {
					label +=
						': ' + name( side, slots[ i ] ) + ' / ' + name( side, slots[ j ] );
				}
				view.pairs[ side ][ slots[ i ] + slots[ j ] ] = label;
			}
		}
	}

	const doubleNames = ( pair, side ) => {
		if ( 2 === present[ side ].length ) {
			return name( side, present[ side ][ 0 ] ) + ' / ' + name( side, present[ side ][ 1 ] );
		}
		if (
			2 === pair.length &&
			present[ side ].includes( pair[ 0 ] ) &&
			present[ side ].includes( pair[ 1 ] )
		) {
			return name( side, pair[ 0 ] ) + ' / ' + name( side, pair[ 1 ] );
		}
		return cfg.teams[ side ];
	};

	for ( const slotGame of cfg.order ) {
		const seq = slotGame.seq;
		const a = result.games[ seq ];
		const { double, explicit, effect, expected } = a;
		const homeLabel = String( slotGame.home ?? '' );
		const awayLabel = String( slotGame.away ?? '' );
		let hint = '';
		if ( null !== expected && ( a.suggest || a.mismatch ) ) {
			const team = cfg.teams[ 'home' === expected.win ? 'home' : 'away' ];
			if ( 'not_played' === expected.st ) {
				hint = a.suggest ? S.sugNotPlayed : S.fixNotPlayed;
			} else if ( double ) {
				hint = fmt( a.suggest ? S.sugDouble : S.fixDouble, team );
			} else {
				hint = fmt(
					a.suggest ? S.sugWalkover : S.fixWalkover,
					a.homeMissing ? homeLabel : awayLabel,
					team
				);
			}
		}
		let out = S.open;
		if ( 'played' === effect.st ) {
			out = effect.sh + ':' + effect.sa;
		} else if ( 'walkover' === effect.st && '' !== effect.win ) {
			out =
				( 'home' === effect.win
					? cfg.setsToWin + ':0'
					: '0:' + cfg.setsToWin ) +
				' ' +
				S.walkover;
		} else if ( 'walkover' === effect.st ) {
			out = S.walkover;
		} else if ( 'not_played' === effect.st ) {
			out = S.notPlayed;
		}
		const pairable = [ '', 'played' ].includes( effect.st );
		const game = {
			home: double
				? doubleNames( explicit.hp, 'home' )
				: name( 'home', slotGame.home ),
			away: double
				? doubleNames( explicit.ap, 'away' )
				: name( 'away', slotGame.away ),
			out,
			done: '' !== effect.st,
			opt: a.optional,
			sug: a.suggest,
			hint,
			fix: a.mismatch,
			fixSt: a.mismatch ? expected.st : '',
			fixWin: a.mismatch ? expected.win : '',
			wo: 'walkover' === effect.st,
			woHome: 'walkover' === effect.st && 'home' === effect.win,
			woAway: 'walkover' === effect.st && 'away' === effect.win,
			np: 'not_played' === effect.st,
			pairHome: double && present.home.length > 2 && pairable,
			pairAway: double && present.away.length > 2 && pairable,
			autoHome:
				double && 2 === present.home.length
					? fmt( S.auto, name( 'home', present.home[ 0 ] ) + ' / ' + name( 'home', present.home[ 1 ] ) )
					: '',
			autoAway:
				double && 2 === present.away.length
					? fmt( S.auto, name( 'away', present.away[ 0 ] ) + ' / ' + name( 'away', present.away[ 1 ] ) )
					: '',
			pts: cfg.setPoints && a.set && 'played' === explicit.st,
			err: err( `games.${ seq }` ),
			errHome: err( `games.${ seq }.home_players` ),
			errAway: err( `games.${ seq }.away_players` ),
			errPts: err( `games.${ seq }.set_points` ),
			errWin: err( `games.${ seq }.winner` ),
		};
		for ( const [ h, aw ] of cfg.scores ) {
			game[ 'p' + h + aw ] =
				'played' === effect.st && effect.sh === h && effect.sa === aw;
		}
		const sets = game.pts ? Number( explicit.sh ) + Number( explicit.sa ) : 0;
		for ( let k = 1; k <= cfg.maxSets; k++ ) {
			game[ 's' + k ] = k <= sets;
			game[ 'v' + k + 'h' ] = String( explicit.pts?.[ k - 1 ]?.[ 0 ] ?? '' );
			game[ 'v' + k + 'a' ] = String( explicit.pts?.[ k - 1 ]?.[ 1 ] ?? '' );
		}
		game.badHome = '' !== game.errHome;
		game.badAway = '' !== game.errAway;
		game.bad =
			'' !== game.err + game.errHome + game.errAway + game.errPts + game.errWin;
		view[ 'g' + seq ] = game;
	}

	const reason = String( ui.reason ?? '' ).trim();
	const action = String( ui.action ?? '' );
	const count = Object.keys( errors ).length;
	const dec = {};
	for ( const key of [ 'confirm', 'finalize', 'correct', 'forfeit', 'reset' ] ) {
		dec[ key ] = key === action;
	}
	return Object.assign( view, {
		total: result.wins.home + ':' + result.wins.away,
		progress: result.decided
			? S.decided
			: fmt( S.progress, result.done, result.required ),
		decided: result.decided,
		gamesErr: err( 'games' ),
		commentErr: err( 'comment' ),
		photoErr: err( 'file' ),
		generalErr: err( 'general' ),
		reasonErr: err( 'reason' ),
		reasonBad: 'reason' in errors,
		decErr: err( 'action' ),
		decWinErr: err( 'winner' ),
		decCommentErr: err( 'decComment' ),
		hasErrors: count > 0,
		errorTitle: 1 === count ? S.errorOne : fmt( S.errorMany, count ),
		reasonInfo: fmt( S.reasonCount, [ ...reason ].length ),
		decForfeit: 'forfeit' === action,
		decCorrect: 'correct' === action,
		dec,
	} );
}

/**
 * Element, zu dem ein Feldfehler führt.
 *
 * @param {string} field Feld aus der API (z. B. lineup.home.A, games.7.home_players).
 * @return {string} ID.
 */
function targetOf( field ) {
	let match = /^lineup\.(home|away)\.([A-Z])$/.exec( field );
	if ( match ) {
		return `ttl-e-slot-${ match[ 1 ] }-${ match[ 2 ] }`;
	}
	match = /^lineup\.(home|away)$/.exec( field );
	if ( match ) {
		return `ttl-e-lineup-${ match[ 1 ] }`;
	}
	match = /^games\.(\d+)\.(home|away)_players$/.exec( field );
	if ( match ) {
		return `ttl-e-game-${ match[ 1 ] }-pair-${ match[ 2 ] }`;
	}
	match = /^games\.(\d+)/.exec( field );
	if ( match ) {
		return `ttl-e-game-${ match[ 1 ] }`;
	}
	const ids = {
		lineup: 'ttl-e-lineup',
		games: 'ttl-e-games',
		comment: 'ttl-e-comment',
		file: 'ttl-e-photo',
		reason: 'ttl-e-reason',
		action: 'ttl-e-action',
		winner: 'ttl-e-winner',
		decComment: 'ttl-e-dec-comment',
	};
	return ids[ field ] ?? 'ttl-e-form';
}

/**
 * Feldfehler der API auf die Felder des Formulars abbilden.
 *
 * @param {Array}  list    Fehler { field, message }.
 * @param {string} context form, correct, objection, decision, photo.
 * @return {Object} Feld => Meldung.
 */
function normalize( list, context ) {
	const known =
		/^(lineup(\.(home|away)(\.[A-Z])?)?|games(\.\d+(\.(home_players|away_players|set_points|winner))?)?|comment|file|reason|action|winner|general)$/;
	const errors = {};
	for ( const item of Array.isArray( list ) ? list : [] ) {
		let field = String( item?.field ?? 'general' ).replace(
			/^(games\.\d+)\.(sequence|status)$/,
			'$1'
		);
		if ( 'decision' === context && 'comment' === field ) {
			field = 'decComment';
		} else if ( ! known.test( field ) ) {
			field = 'general';
		}
		const message = String( item?.message ?? '' );
		errors[ field ] = errors[ field ] ? errors[ field ] + ' ' + message : message;
	}
	return errors;
}

function storage( kind ) {
	try {
		return 'session' === kind ? window.sessionStorage : window.localStorage;
	} catch ( e ) {
		return null;
	}
}

function readJson( kind, key ) {
	try {
		const raw = storage( kind )?.getItem( key );
		return raw ? JSON.parse( raw ) : null;
	} catch ( e ) {
		return null;
	}
}

function writeJson( kind, key, value ) {
	try {
		storage( kind )?.setItem( key, JSON.stringify( value ) );
	} catch ( e ) {
		// Speicher voll oder gesperrt: dann eben ohne.
	}
}

function removeKey( kind, key ) {
	try {
		storage( kind )?.removeItem( key );
	} catch ( e ) {
		// Gesperrter Speicher: nichts zu tun.
	}
}

/**
 * Fokus setzen, sobald das Element sichtbar ist (das Rendern folgt nach der Zustandsänderung).
 *
 * @param {string} selector CSS-Selektor.
 * @param {number} tries    Versuche.
 */
function focusLater( selector, tries = 15 ) {
	window.setTimeout( () => {
		const element = document.querySelector( selector );
		if ( element && ! element.closest( '[hidden]' ) ) {
			element.focus( { preventScroll: true } );
			element.scrollIntoView( { block: 'center' } );
		} else if ( tries > 0 ) {
			focusLater( selector, tries - 1 );
		}
	}, 40 );
}

function formSnapshot( form ) {
	return JSON.stringify( form );
}

const initialSnapshot = formSnapshot( cfg.initialForm );
let lastSnapshot = initialSnapshot;
let draftTimer = null;
let pendingDraft = null;
let leaving = false;

/**
 * Hat ein Entwurf die Form dieser Begegnung (Slots und Spiele)?
 *
 * @param {Object} form Entwurf.
 * @return {boolean} Passend.
 */
function fitsShape( form ) {
	if ( ! form || ! form.lineup || ! form.games ) {
		return false;
	}
	for ( const side of SIDES ) {
		for ( const slot of cfg.slots[ side ] ) {
			if ( ! ( slot in ( form.lineup[ side ] || {} ) ) ) {
				return false;
			}
		}
	}
	return cfg.order.every( ( game ) => {
		const row = form.games[ game.seq ];
		return row && Array.isArray( row.pts ) && row.pts.length === cfg.maxSets;
	} );
}

const { state } = store( NS, {
	state: {
		get view() {
			return buildView( state.form, state.errors, state.mode, {
				reason: state.reason,
				action: state.decision.action,
			} );
		},
		/** Werte der Auswahllisten und der Bemerkung (nur im Browser gebunden). */
		get sel() {
			const sel = { comment: state.form.comment };
			for ( const side of SIDES ) {
				for ( const slot of cfg.slots[ side ] ) {
					sel[ side + slot ] = String( state.form.lineup[ side ][ slot ] ?? '' );
				}
			}
			for ( const game of cfg.order ) {
				if ( 'double' === game.type ) {
					sel[ 'pair' + game.seq + 'home' ] = state.form.games[ game.seq ].hp;
					sel[ 'pair' + game.seq + 'away' ] = state.form.games[ game.seq ].ap;
				}
			}
			return sel;
		},
		/** Fehlerübersicht mit Sprungmarken (Fehler der Aufstellung mit Platz und Mannschaft). */
		get errorList() {
			return Object.entries( state.errors ).map( ( [ field, text ] ) => {
				const slot = /^lineup\.(home|away)\.([A-Z])$/.exec( field );
				return {
					key: field,
					text: slot
						? fmt( S.slotLabel, slot[ 2 ], cfg.teams[ slot[ 1 ] ] ) + ': ' + text
						: text,
					href: '#' + targetOf( field ),
				};
			} );
		},
	},

	actions: {
		setSlot( event ) {
			const { side, slot } = getContext();
			state.form.lineup[ side ][ slot ] = event.target.value;
			clearErrors( [ `lineup.${ side }.${ slot }`, `lineup.${ side }`, 'lineup' ] );
		},

		setScore() {
			const { seq, h, a } = getContext();
			const game = state.form.games[ seq ];
			if ( 'played' === game.st && game.sh === h && game.sa === a ) {
				game.st = '';
				game.sh = null;
				game.sa = null;
			} else {
				game.st = 'played';
				game.sh = h;
				game.sa = a;
				game.win = '';
			}
			clearGameErrors( seq );
		},

		toggleWalkover( event ) {
			const { seq } = getContext();
			const game = state.form.games[ seq ];
			if ( event.target.checked ) {
				const expected = analyse( state.form ).games[ seq ].expected;
				game.st = 'walkover';
				game.sh = null;
				game.sa = null;
				game.win =
					expected && 'walkover' === expected.st ? expected.win : game.win;
			} else {
				game.st = '';
				game.win = '';
			}
			clearGameErrors( seq );
			// Ein Vorschlag bleibt bestehen, solange der Spieler fehlt: Häkchen wieder setzen.
			event.target.checked = state.view[ 'g' + seq ].wo;
		},

		setWinner( event ) {
			const { seq } = getContext();
			const game = state.form.games[ seq ];
			game.st = 'walkover';
			game.sh = null;
			game.sa = null;
			game.win = event.target.value;
			clearGameErrors( seq );
		},

		applyFix() {
			const { seq } = getContext();
			const view = state.view[ 'g' + seq ];
			const game = state.form.games[ seq ];
			game.st = view.fixSt;
			game.win = view.fixWin;
			game.sh = null;
			game.sa = null;
			clearGameErrors( seq );
		},

		setPair( event ) {
			const { seq, side } = getContext();
			state.form.games[ seq ][ 'home' === side ? 'hp' : 'ap' ] =
				event.target.value;
			clearErrors( [ `games.${ seq }.${ side }_players` ] );
		},

		setPoint( event ) {
			const { seq, set, col } = getContext();
			const value = String( event.target.value ).replace( /\D/g, '' ).slice( 0, 2 );
			state.form.games[ seq ].pts[ set - 1 ][ col ] = value;
			if ( value !== event.target.value ) {
				event.target.value = value;
			}
			clearErrors( [ `games.${ seq }.set_points` ] );
		},

		setComment( event ) {
			state.form.comment = event.target.value;
			clearErrors( [ 'comment', 'decComment' ] );
		},

		setReason( event ) {
			state.reason = event.target.value;
			clearErrors( [ 'reason' ] );
		},

		setDecision( event ) {
			const action = event.target.value;
			state.decision.action = action;
			clearErrors( [ 'action', 'winner', 'decComment' ] );
			if ( 'correct' === action ) {
				state.mode = 'correct';
			} else if ( 'correct' === state.mode ) {
				state.mode = cfg.initialMode;
			}
		},

		setDecisionWinner( event ) {
			state.decision.winner = event.target.value;
			clearErrors( [ 'winner' ] );
		},

		setDecisionComment( event ) {
			state.decision.comment = event.target.value;
			clearErrors( [ 'decComment' ] );
		},

		jump: withSyncEvent( ( event ) => {
			const id = ( event.currentTarget.getAttribute( 'href' ) || '' ).slice( 1 );
			if ( document.getElementById( id ) ) {
				event.preventDefault();
				focusLater( '#' + id, 0 );
			}
		} ),

		reload() {
			window.location.reload();
		},

		restoreDraft() {
			if ( pendingDraft ) {
				applyDraft( pendingDraft );
				pendingDraft = null;
				state.draft = { text: S.draftRestored, offer: false };
			}
		},

		discardDraft() {
			pendingDraft = null;
			state.form = clone( cfg.initialForm );
			if ( 'correct' === state.mode ) {
				state.mode = cfg.initialMode;
				state.decision.action = '';
			}
			state.draft = { text: '', offer: false };
			state.errors = {};
			removeKey( 'local', cfg.draftKey );
		},

		submit: withSyncEvent( function* ( event ) {
			event.preventDefault();
			if ( state.busy || '' === state.mode ) {
				return;
			}
			const errors = validate();
			if ( Object.keys( errors ).length ) {
				state.errors = errors;
				focusLater( '#ttl-e-summary' );
				return;
			}
			state.errors = {};
			clearMessage();
			state.busy = true;
			const correct = 'correct' === state.mode;
			const body = payload();
			const response = yield* request(
				correct ? cfg.urls.decision : cfg.urls.result,
				correct
					? {
							action: 'correct',
							result: { lineup: body.lineup, games: body.games },
							comment: body.comment,
							version: cfg.version,
					  }
					: body
			);
			state.busy = false;
			if ( response.ok ) {
				removeKey( 'local', cfg.draftKey );
				finish( correct ? S.savedCorrect : S.savedEnter );
				return;
			}
			fail( response, correct ? 'correct' : 'form' );
		} ),

		*confirm() {
			if ( state.busy ) {
				return;
			}
			clearMessage();
			state.busy = true;
			const response = yield* request( cfg.urls.confirm, {
				version: cfg.version,
			} );
			state.busy = false;
			if ( response.ok ) {
				finish( S.confirmed );
				return;
			}
			fail( response, 'confirm' );
		},

		object: withSyncEvent( function* ( event ) {
			event.preventDefault();
			if ( state.busy ) {
				return;
			}
			const reason = String( state.reason ).trim();
			const length = [ ...reason ].length;
			if ( length < 10 || length > 2000 ) {
				state.errors = { reason: S.reasonLength };
				focusLater( '#ttl-e-reason' );
				return;
			}
			state.errors = {};
			clearMessage();
			state.busy = true;
			const response = yield* request( cfg.urls.objection, {
				reason,
				version: cfg.version,
			} );
			state.busy = false;
			if ( response.ok ) {
				finish( S.objected );
				return;
			}
			fail( response, 'objection' );
		} ),

		decide: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const { action, winner, comment } = state.decision;
			if ( state.busy || 'correct' === action ) {
				return;
			}
			const errors = {};
			if ( '' === action ) {
				errors.action = S.actionRequired;
			} else if ( 'forfeit' === action && '' === winner ) {
				errors.winner = S.winnerForfeit;
			}
			if ( Object.keys( errors ).length ) {
				state.errors = errors;
				focusLater( '#' + targetOf( Object.keys( errors )[ 0 ] ) );
				return;
			}
			if (
				( 'reset' === action && ! window.confirm( S.askReset ) ) ||
				( 'forfeit' === action && ! window.confirm( S.askForfeit ) )
			) {
				return;
			}
			state.errors = {};
			clearMessage();
			state.busy = true;
			const body = { action, comment, version: cfg.version };
			if ( 'forfeit' === action ) {
				body.winner = winner;
			}
			const response = yield* request( cfg.urls.decision, body );
			state.busy = false;
			if ( response.ok ) {
				finish(
					{
						confirm: S.decidedConfirm,
						finalize: S.decidedFinalize,
						forfeit: S.decidedForfeit,
						reset: S.decidedReset,
					}[ action ]
				);
				return;
			}
			fail( response, 'decision' );
		} ),

		*uploadPhoto( event ) {
			const input = event.target;
			const file = input.files && input.files[ 0 ];
			if ( ! file || state.photo.busy ) {
				return;
			}
			clearErrors( [ 'file' ] );
			if ( file.size > cfg.maxPhotoBytes ) {
				state.errors = { ...state.errors, file: S.photoTooLarge };
				input.value = '';
				return;
			}
			state.photo.busy = true;
			state.photo.text = S.photoUploading;
			const body = new window.FormData();
			body.append( 'file', file, file.name );
			const response = yield* request( cfg.urls.photo, body );
			state.photo.busy = false;
			input.value = '';
			if ( response.ok && response.data ) {
				state.photo.url = response.data.url || null;
				state.photo.text = S.photoSaved;
				return;
			}
			state.photo.text = '';
			fail( response, 'photo' );
		},
	},

	callbacks: {
		init() {
			// Serverseitig gesperrt, bis das Skript bereit ist.
			state.busy = false;
			const flash = readJson( 'session', cfg.flashKey );
			if ( flash && flash.text ) {
				removeKey( 'session', cfg.flashKey );
				showMessage( flash.text, 'success' );
			}
			const draft = readJson( 'local', cfg.draftKey );
			if ( ! draft || ! fitsShape( draft.form ) || formSnapshot( draft.form ) === initialSnapshot ) {
				return;
			}
			const allowed =
				'correct' === draft.mode
					? cfg.decisions.includes( 'correct' )
					: 'enter' === cfg.initialMode;
			if ( ! allowed ) {
				removeKey( 'local', cfg.draftKey );
			} else if ( draft.v === cfg.version ) {
				applyDraft( draft );
				state.draft = { text: S.draftRestored, offer: false };
			} else {
				pendingDraft = draft;
				state.draft = { text: S.draftOffer, offer: true };
			}
		},

		/** Entwurf im Browser sichern (verzögert, nur bei Änderungen). */
		persist() {
			const snapshot = formSnapshot( state.form );
			const mode = state.mode;
			if ( snapshot === lastSnapshot ) {
				return;
			}
			lastSnapshot = snapshot;
			if ( pendingDraft ) {
				pendingDraft = null;
				state.draft = { text: '', offer: false };
			}
			window.clearTimeout( draftTimer );
			draftTimer = window.setTimeout( () => {
				if ( leaving ) {
					return;
				}
				if ( snapshot === initialSnapshot ) {
					removeKey( 'local', cfg.draftKey );
				} else {
					writeJson( 'local', cfg.draftKey, {
						v: cfg.version,
						mode,
						at: Date.now(),
						form: JSON.parse( snapshot ),
					} );
				}
			}, 300 );
		},
	},
} );

function applyDraft( draft ) {
	state.form = clone( draft.form );
	if ( 'correct' === draft.mode ) {
		state.mode = 'correct';
		state.decision.action = 'correct';
	}
}

function clearErrors( keys ) {
	if ( ! keys.some( ( key ) => key in state.errors ) ) {
		return;
	}
	const next = { ...state.errors };
	keys.forEach( ( key ) => delete next[ key ] );
	state.errors = next;
}

function clearGameErrors( seq ) {
	clearErrors( [
		`games.${ seq }`,
		`games.${ seq }.winner`,
		`games.${ seq }.set_points`,
		`games.${ seq }.home_players`,
		`games.${ seq }.away_players`,
	] );
}

function showMessage( text, type, extra = {} ) {
	state.message = {
		text,
		success: 'success' === type,
		warning: 'warning' === type,
		reload: !! extra.reload,
		login: !! extra.login,
	};
	focusLater( '#ttl-e-message' );
}

function clearMessage() {
	state.message = {
		text: '',
		success: false,
		warning: false,
		reload: false,
		login: false,
	};
}

/**
 * Erfolg: Meldung merken und neu laden (Status, Verlauf und Hinweise kommen vom Server).
 *
 * @param {string} text Meldung.
 */
function finish( text ) {
	leaving = true;
	window.clearTimeout( draftTimer );
	writeJson( 'session', cfg.flashKey, { text } );
	window.location.reload();
}

/**
 * Fehler der API anzeigen: am Feld (422), mit Neu-laden-Hinweis (409) oder als Meldung.
 *
 * @param {Object} response { ok, status, data }.
 * @param {string} context  form, correct, confirm, objection, decision, photo.
 */
function fail( response, context ) {
	const data = response.data || {};
	const code = data.code || '';
	if ( 422 === response.status ) {
		const errors = normalize( data.data?.errors, context );
		state.errors = Object.keys( errors ).length
			? errors
			: { general: data.message || fmt( S.failed, response.status ) };
		const first = Object.keys( state.errors )[ 0 ];
		focusLater(
			'form' === context || 'correct' === context
				? '#ttl-e-summary'
				: '#' + targetOf( first )
		);
		return;
	}
	if ( 409 === response.status ) {
		showMessage( data.message || S.conflict, 'warning', { reload: true } );
		return;
	}
	if (
		401 === response.status ||
		'rest_cookie_invalid_nonce' === code ||
		'rest_forbidden' === code
	) {
		showMessage( S.session, 'warning', { login: true } );
		return;
	}
	if ( 0 === response.status ) {
		showMessage( S.offline, 'warning' );
		return;
	}
	showMessage( data.message || fmt( S.failed, response.status ), 'warning' );
}

/**
 * POST an die REST-API mit Nonce; liefert auch bei Netzwerkfehlern ein Ergebnis.
 *
 * @param {string}          url  Adresse.
 * @param {Object|FormData} body JSON-Daten oder Formular (Foto).
 * @return {Generator} { ok, status, data }.
 */
function* request( url, body ) {
	const form = body instanceof window.FormData;
	const headers = { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' };
	if ( ! form ) {
		headers[ 'Content-Type' ] = 'application/json';
	}
	let response;
	try {
		response = yield window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers,
			body: form ? body : JSON.stringify( body ),
		} );
	} catch ( e ) {
		return { ok: false, status: 0, data: null };
	}
	let data = null;
	try {
		data = yield response.json();
	} catch ( e ) {
		data = null;
	}
	return { ok: response.ok, status: response.status, data };
}

/**
 * Pflichtangaben vor dem Absenden (der Server prüft den Rest).
 *
 * @return {Object} Feld => Meldung.
 */
function validate() {
	const errors = {};
	const add = ( field, message ) => {
		errors[ field ] = errors[ field ] ? errors[ field ] + ' ' + message : message;
	};
	const form = state.form;
	for ( const side of SIDES ) {
		const seen = {};
		for ( const slot of cfg.slots[ side ] ) {
			const value = String( form.lineup[ side ][ slot ] ?? '' );
			if ( '' === value ) {
				add( `lineup.${ side }.${ slot }`, S.slotRequired );
			} else if ( isPlayer( value ) ) {
				if ( seen[ value ] ) {
					add(
						`lineup.${ side }.${ slot }`,
						fmt( S.slotDuplicate, cfg.players[ value ]?.name ?? S.unknown, seen[ value ] )
					);
				} else {
					seen[ value ] = slot;
				}
			}
		}
	}
	const result = analyse( form );
	for ( const game of cfg.order ) {
		const seq = game.seq;
		const a = result.games[ seq ];
		const effect = a.effect;
		if ( '' === effect.st ) {
			if ( ! a.optional ) {
				add( `games.${ seq }`, fmt( S.gameRequired, seq ) );
			}
			continue;
		}
		if ( 'walkover' === effect.st && '' === effect.win ) {
			add( `games.${ seq }.winner`, fmt( S.winnerRequired, seq ) );
		}
		if ( 'played' === effect.st && a.double ) {
			for ( const side of SIDES ) {
				const pair = a.explicit[ 'home' === side ? 'hp' : 'ap' ];
				if ( result.present[ side ].length > 2 && ! validPair( pair, result.present[ side ] ) ) {
					add( `games.${ seq }.${ side }_players`, fmt( S.pairRequired, seq ) );
				}
			}
		}
		if ( 'played' === effect.st && cfg.setPoints ) {
			const rows = a.explicit.pts.slice( 0, Number( effect.sh ) + Number( effect.sa ) );
			const touched = rows.some( ( row ) => '' !== row[ 0 ] || '' !== row[ 1 ] );
			const complete = rows.every( ( row ) => '' !== row[ 0 ] && '' !== row[ 1 ] );
			if ( touched && ! complete ) {
				add( `games.${ seq }.set_points`, fmt( S.pointsIncomplete, seq ) );
			}
		}
	}
	return errors;
}

function validPair( pair, present ) {
	return (
		2 === String( pair ).length &&
		present.includes( pair[ 0 ] ) &&
		present.includes( pair[ 1 ] )
	);
}

/**
 * Daten für POST result (bzw. result im Entscheid «correct»).
 *
 * @return {Object} lineup, games, comment, version.
 */
function payload() {
	const form = state.form;
	const lineup = { home: {}, away: {} };
	for ( const side of SIDES ) {
		for ( const slot of cfg.slots[ side ] ) {
			const value = String( form.lineup[ side ][ slot ] ?? '' );
			lineup[ side ][ slot ] = isPlayer( value ) ? Number( value ) : null;
		}
	}
	const result = analyse( form );
	const games = [];
	for ( const game of cfg.order ) {
		const a = result.games[ game.seq ];
		const effect = a.effect;
		if ( '' === effect.st ) {
			continue;
		}
		const item = { sequence: game.seq, status: effect.st };
		if ( 'walkover' === effect.st ) {
			item.winner = effect.win;
		} else if ( 'played' === effect.st ) {
			item.sets_home = effect.sh;
			item.sets_away = effect.sa;
			if ( a.double ) {
				for ( const side of SIDES ) {
					const pair = a.explicit[ 'home' === side ? 'hp' : 'ap' ];
					if (
						result.present[ side ].length > 2 &&
						validPair( pair, result.present[ side ] )
					) {
						item[ side + '_players' ] = [ pair[ 0 ], pair[ 1 ] ].map(
							( slot ) => lineup[ side ][ slot ]
						);
					}
				}
			}
			if ( cfg.setPoints ) {
				const rows = a.explicit.pts.slice( 0, effect.sh + effect.sa );
				if (
					rows.length &&
					rows.every( ( row ) => '' !== row[ 0 ] && '' !== row[ 1 ] )
				) {
					item.set_points = rows.map( ( row ) => [
						Number( row[ 0 ] ),
						Number( row[ 1 ] ),
					] );
				}
			}
		}
		games.push( item );
	}
	return { lineup, games, comment: form.comment, version: cfg.version };
}
