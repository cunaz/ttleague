/**
 * ttleague – Resultaterfassung (Interactivity API, ohne Build-Schritt).
 *
 * Der Block ttleague/result-entry rendert die Seite serverseitig mit dem Startzustand aus
 * ResultService::entry(); dieses Modul übernimmt danach. Das Ansichtsmodell, die Prüfung und
 * die Daten für die API stehen als reine Funktionen in entry-view.js (Gegenstück zu
 * TTLeague\Results\EntryForm in PHP). Unveränderliche Daten (Spielfolge, Kandidaten, Texte,
 * Adressen, Nonce) kommen über die Konfiguration, alles Veränderliche über den Zustand.
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
import {
	analyse,
	buildView,
	fmt,
	normalize,
	payload,
	targetOf,
	validate,
} from '@ttleague/entry-view';

const NS = 'ttleague/entry';
const cfg = getConfig( NS );
const S = cfg.strings || {};
const SIDES = [ 'home', 'away' ];

function clone( value ) {
	return JSON.parse( JSON.stringify( value ) );
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
			return buildView( cfg, state.form, state.errors, state.mode, {
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
				const expected = analyse( cfg, state.form ).games[ seq ].expected;
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
			const errors = validate( cfg, state.form );
			if ( Object.keys( errors ).length ) {
				state.errors = errors;
				focusLater( '#ttl-e-summary' );
				return;
			}
			state.errors = {};
			clearMessage();
			state.busy = true;
			const correct = 'correct' === state.mode;
			const body = payload( cfg, state.form, cfg.version );
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
