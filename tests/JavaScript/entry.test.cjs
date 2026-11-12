/** Resultaterfassung: das echte Modul mit ersetzten WordPress- und Browser-Schnittstellen. */
const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const { resolve } = require( 'node:path' );
const { test } = require( 'node:test' );
const { runInNewContext } = require( 'node:vm' );

const source = readFileSync( resolve( __dirname, '../../assets/js/entry.js' ), 'utf8' )
	.replace( /^import\s*\{[^}]+\}\s*from\s*'[^']+';\s*/gm, '' );
const model = runInNewContext(
	readFileSync( resolve( __dirname, '../../assets/js/entry-view.js' ), 'utf8' ).replace( /^export /gm, '' ) +
	'\n({ analyse, buildView, fmt, normalize, payload, targetOf, validate });'
);

function form() {
	return {
		lineup: { home: { A: '1', B: '2' }, away: { Y: '3', Z: '4' } },
		games: { 1: { st: 'played', sh: 3, sa: 0, win: '', hp: '', ap: '', pts: Array.from( { length: 5 }, () => [ '', '' ] ) } },
		comment: '',
	};
}

function entry( { draft, blockedStorage = false, fetch } = {} ) {
	const listeners = {};
	const timers = new Map();
	const values = new Map( draft ? [ [ 'draft', JSON.stringify( draft ) ] ] : [] );
	const cfg = {
		initialForm: form(), initialMode: 'enter', version: 'v1', draftKey: 'draft', flashKey: 'flash',
		slots: { home: [ 'A', 'B' ], away: [ 'Y', 'Z' ] },
		order: [ { seq: 1, type: 'single', home: 'A', away: 'Y' } ],
		maxSets: 5, setsToWin: 3, cupTarget: null, setPoints: true,
		decisions: [ 'correct' ], players: {}, eligibility: {}, scores: [], teams: { home: 'Heim', away: 'Gast' },
		strings: { savedEnter: 'Gespeichert', failed: 'Fehler %d', draftRestored: 'Entwurf wiederhergestellt', draftOffer: 'Entwurf übernehmen', offline: 'Keine Verbindung' },
		urls: { result: '/result', confirm: '/confirm' },
	};
	const state = {
		form: form(), mode: 'enter', errors: {}, busy: true, reason: '',
		decision: { action: '', winner: '', comment: '' }, photo: { busy: false }, draft: {}, message: {},
	};
	const storage = {
		getItem: ( key ) => values.get( key ),
		setItem( key, value ) {
			if ( blockedStorage ) { throw new Error( 'Speicher gesperrt' ); }
			values.set( key, value );
		},
		removeItem: ( key ) => values.delete( key ),
	};
	let registered;
	let reloaded = false;
	let timer = 0;
	const window = {
		localStorage: storage, sessionStorage: storage, FormData,
		location: { reload() { reloaded = true; } },
		addEventListener( event, callback ) { listeners[ event ] = callback; },
		setTimeout( callback ) { timers.set( ++timer, callback ); return timer; },
		clearTimeout( id ) { timers.delete( id ); },
		fetch: fetch || ( async () => ( { ok: true, status: 200, json: async () => ( { status: 'provisional', version: 'v2' } ) } ) ),
	};
	const document = {
		visibilityState: 'visible',
		addEventListener( event, callback ) { listeners[ event ] = callback; },
		querySelector() { return null; },
	};
	runInNewContext( source, {
		...model, window, document,
		getConfig: () => cfg, getContext: () => ( {} ), withSyncEvent: ( callback ) => callback,
		store( namespace, definition ) {
			registered = definition;
			Object.defineProperties( state, Object.getOwnPropertyDescriptors( definition.state ) );
			return { state };
		},
	} );
	registered.callbacks.init();
	return {
		...registered, state, document, values,
		get reloaded() { return reloaded; },
		fire( name, event = {} ) { listeners[ name ]?.( event ); },
		flushTimers() {
			const callbacks = [ ...timers.values() ];
			timers.clear();
			callbacks.forEach( ( callback ) => callback() );
		},
	};
}

async function run( generator ) {
	let step = generator.next();
	while ( ! step.done ) {
		try {
			step = generator.next( await step.value );
		} catch ( error ) {
			step = generator.throw( error );
		}
	}
}

for ( const event of [ 'pagehide', 'visibilitychange' ] ) {
	test( `Letzte Eingaben werden beim ${ event } ohne Verzögerung gesichert`, () => {
		const ui = entry();
		ui.state.form.comment = 'Letzter Satz';
		ui.callbacks.persist();
		ui.document.visibilityState = 'hidden';
		ui.fire( event );
		assert.equal( JSON.parse( ui.values.get( 'draft' ) ).form.comment, 'Letzter Satz' );
	} );
}

test( 'Auch bei gesperrtem Speicher wird vor dem Verlassen ungespeicherter Eingaben gewarnt', () => {
	const ui = entry( { blockedStorage: true } );
	let prevented = false;
	const event = { preventDefault() { prevented = true; } };
	ui.fire( 'beforeunload', event );
	assert.equal( prevented, false );
	ui.state.form.comment = 'Noch nicht gespeichert';
	ui.fire( 'beforeunload', event );
	assert.equal( prevented, true );
	assert.equal( event.returnValue, '' );
} );

for ( const [ name, update ] of [
	[ 'Einsprache', ( state ) => { state.reason = 'Meine Einsprache'; } ],
	[ 'Entscheidbegründung', ( state ) => { state.decision.comment = 'Begründung der TK'; } ],
	[ 'Laufender Upload', ( state ) => { state.photo.busy = true; } ],
] ) {
	test( `${ name } löst ebenfalls die Warnung beim Verlassen aus`, () => {
		const ui = entry();
		update( ui.state );
		let prevented = false;
		ui.fire( 'beforeunload', { preventDefault() { prevented = true; } } );
		assert.equal( prevented, true );
	} );
}

test( 'Ein angebotener älterer Entwurf bleibt beim Ausblenden erhalten', () => {
	const old = form();
	old.comment = 'Älterer Entwurf';
	const ui = entry( { draft: { v: 'v0', mode: 'enter', form: old } } );
	ui.fire( 'pagehide' );
	assert.equal( ui.state.draft.offer, true );
	assert.equal( JSON.parse( ui.values.get( 'draft' ) ).form.comment, old.comment );
} );

for ( const [ field, invalid ] of [
	[ 'Aufstellung', { ...form(), lineup: { home: 'kaputt', away: {} } } ],
	[ 'Satzpunkte', { ...form(), games: { 1: { ...form().games[ 1 ], pts: [ null, null, null, null, null ] } } } ],
	[ 'Bemerkung', { ...form(), comment: { value: 'kaputt' } } ],
] ) {
	test( `Ein beschädigter lokaler Entwurf verhindert die Erfassung nicht: ${ field }`, () => {
		const ui = entry( { draft: { v: 'v1', mode: 'enter', form: invalid } } );
		assert.equal( ui.state.busy, false );
		assert.deepEqual( ui.state.form, form() );
		assert.doesNotThrow( () => ui.state.view );
	} );
}

test( 'Nach erfolgreichem Speichern wird der Entwurf auch beim Neuladen nicht wieder angelegt', async () => {
	const ui = entry();
	ui.state.form.comment = 'Gespeichert';
	ui.callbacks.persist();
	await run( ui.actions.submit( { preventDefault() {} } ) );
	ui.fire( 'pagehide' );
	ui.flushTimers();
	assert.equal( ui.reloaded, true );
	assert.equal( ui.values.has( 'draft' ), false );
	let prevented = false;
	ui.fire( 'beforeunload', { preventDefault() { prevented = true; } } );
	assert.equal( prevented, false );
} );

test( 'Eine fehlerhafte Erfolgsantwort verwirft den Entwurf nicht', async () => {
	const ui = entry( { fetch: async () => ( { ok: true, status: 200, json: async () => { throw new SyntaxError( 'HTML statt JSON' ); } } ) } );
	ui.state.form.comment = 'Unbestätigter Spielbericht';
	ui.callbacks.persist();
	ui.flushTimers();
	await run( ui.actions.submit( { preventDefault() {} } ) );
	assert.equal( ui.reloaded, false );
	assert.equal( ui.state.busy, false );
	assert.equal( ui.state.message.warning, true );
	assert.equal( JSON.parse( ui.values.get( 'draft' ) ).form.comment, 'Unbestätigter Spielbericht' );
} );
