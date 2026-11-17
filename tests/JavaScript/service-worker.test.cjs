/** Laufzeitprüfungen des unveränderten Service-Worker-Skripts mit Browser-Schnittstellen. */
const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const { resolve } = require( 'node:path' );
const { test } = require( 'node:test' );
const { runInNewContext } = require( 'node:vm' );

const source = readFileSync( resolve( __dirname, '../../assets/pwa/sw.js' ), 'utf8' );
const origin = 'https://example.org';

function response( body, headers = {}, status = 200 ) {
	const result = new Response( body, { status, headers: { 'Content-Type': 'text/html', ...headers } } );
	Object.defineProperty( result, 'type', { value: 'basic' } );
	return result;
}

function worker( { fetch, cacheError, putError, cleanupError, cached, config = {} } = {} ) {
	const handlers = {};
	const entries = new Map();
	const key = ( request ) => 'string' === typeof request ? request : request.url;
	let claimed = false;
	let notification;
	const cache = {
		async put( request, value ) {
			if ( putError ) {
				throw new Error( 'QuotaExceededError' );
			}
			entries.set( key( request ), value );
		},
		async match( request, options ) {
			// Eine Antwort mit Vary: Cookie darf nicht ohne den passenden Cookie gelesen werden.
			const value = entries.get( key( request ) ) || cached;
			if ( value?.headers.get( 'Vary' ) === 'Cookie' && ! options?.ignoreVary ) {
				return undefined;
			}
			return value?.clone();
		},
		async keys() {
			return [ ...entries.keys() ];
		},
		async delete( request ) {
			return entries.delete( key( request ) );
		},
	};
	const caches = {
		async open() {
			if ( cacheError ) {
				throw new Error( 'Cache-Speicher gesperrt' );
			}
			return cache;
		},
		async keys() {
			if ( cleanupError ) {
				throw new Error( 'Cache-Speicher gesperrt' );
			}
			return [];
		},
	};
	const self = {
		location: new URL( origin + '/sw.js' ),
		addEventListener: ( name, callback ) => { handlers[ name ] = callback; },
		clients: {
			async matchAll() { return []; },
			async claim() { claimed = true; },
		},
		async skipWaiting() {},
		registration: {
			async showNotification( title, options ) { notification = { title, options }; },
		},
	};
	runInNewContext( source, {
		CONFIG: {
			prefix: 'test:', version: '1', base: '/', start: '/', never: [ 'mein-bereich', 'wp-json' ],
			maxPages: 2, maxAssets: 2,
			offline: { lang: 'de', site: 'Liga', title: 'Offline', heading: 'Keine Verbindung' },
			colors: { background: '#fff', text: '#111', accent: '#123' },
			...config,
		},
		self, caches, URL, Response,
		fetch: fetch || ( async () => response( 'Online' ) ),
	} );
	return {
		entries,
		get claimed() { return claimed; },
		get notification() { return notification; },
		async dispatch( name, properties = {} ) {
			const tasks = [];
			let result;
			handlers[ name ]( {
				...properties,
				respondWith( value ) { result = value; },
				waitUntil( task ) { tasks.push( task ); },
			} );
			const answer = await result;
			await Promise.all( tasks );
			return answer;
		},
		request( path, overrides = {} ) {
			return this.dispatch( 'fetch', {
				request: { method: 'GET', mode: 'navigate', destination: 'document', url: origin + path, ...overrides },
			} );
		},
	};
}

for ( const options of [ { cacheError: true }, { putError: true } ] ) {
	test( `Online-Seiten und Dateien bleiben bei Cache-Fehlern erreichbar: ${ JSON.stringify( options ) }`, async () => {
		const sw = worker( options );
		assert.equal( await ( await sw.request( '/spielplan/' ) ).text(), 'Online' );
		assert.equal( await ( await sw.request( '/style.css', { mode: 'cors', destination: 'style' } ) ).text(), 'Online' );
	} );
}

test( 'Offline und bei gesperrtem Cache bleibt die Offline-Seite erreichbar', async () => {
	const sw = worker( { cacheError: true, fetch: async () => { throw new Error( 'offline' ); } } );
	const result = await sw.request( '/spielplan/' );
	assert.match( await result.text(), /Keine Verbindung/ );
	assert.equal( result.headers.get( 'Cache-Control' ), 'no-store' );
} );

test( 'Eine gespeicherte öffentliche Seite ist offline lesbar', async () => {
	let online = true;
	const sw = worker( { fetch: async () => {
		if ( ! online ) { throw new Error( 'offline' ); }
		return response( 'Spielplan' );
	} } );
	await sw.request( '/spielplan/' );
	online = false;
	assert.equal( await ( await sw.request( '/spielplan/' ) ).text(), 'Spielplan' );
} );

for ( const replacement of [
	() => response( 'Privat', { 'Cache-Control': 'private, no-store' } ),
	() => response( 'Entfernt', {}, 404 ),
	() => response( 'Nicht angemeldet', {}, 403 ),
	() => response( 'Unvollständiger Inhalt', {}, 206 ),
	() => {
		const redirected = response( 'Anmeldeseite nach einer Weiterleitung' );
		Object.defineProperty( redirected, 'redirected', { value: true } );
		return redirected;
	},
] ) {
	test( `Eine nicht mehr speicherbare Seite entfernt die frühere Offline-Kopie: ${ replacement().status } ${ replacement().headers.get( 'Cache-Control' ) }`, async () => {
		let next = response( 'Frühere Fassung' );
		const sw = worker( { fetch: async () => {
			if ( ! next ) { throw new Error( 'offline' ); }
			return next;
		} } );
		await sw.request( '/spielplan/' );
		next = replacement();
		await sw.request( '/spielplan/' );
		next = null;
		assert.match( await ( await sw.request( '/spielplan/' ) ).text(), /Keine Verbindung/ );
	} );
}

test( 'Ein Serverfehler entfernt keine gültige Offline-Kopie', async () => {
	let next = response( 'Spielplan' );
	const sw = worker( { fetch: async () => {
		if ( ! next ) { throw new Error( 'offline' ); }
		return next;
	} } );
	await sw.request( '/spielplan/' );
	next = response( 'Wartung', {}, 503 );
	assert.equal( ( await sw.request( '/spielplan/' ) ).status, 503 );
	next = null;
	assert.equal( await ( await sw.request( '/spielplan/' ) ).text(), 'Spielplan' );
} );

test( 'Vary wird beim Lesen einer Offline-Kopie beachtet', async () => {
	const sw = worker( {
		cached: response( 'Andere Cookie-Variante', { Vary: 'Cookie' } ),
		fetch: async () => { throw new Error( 'offline' ); },
	} );
	assert.match( await ( await sw.request( '/spielplan/' ) ).text(), /Keine Verbindung/ );
} );

test( 'Private Seiten und REST-Anfragen werden nicht gespeichert', async () => {
	const sw = worker();
	await sw.request( '/mein-bereich/' );
	await sw.request( '/?rest_route=/ttleague/v1/fixtures' );
	await sw.request( '/spielplan/?preview=true' );
	assert.equal( sw.entries.size, 0 );
	assert.equal( await sw.request( '/style.css', { method: 'POST' } ), undefined );
} );

test( 'Alte Cache-Einträge werden bis zur Höchstzahl entfernt', async () => {
	const sw = worker();
	await sw.request( '/eins/' );
	await sw.request( '/zwei/' );
	await sw.request( '/drei/' );
	assert.deepEqual( [ ...sw.entries.keys() ], [ origin + '/zwei/', origin + '/drei/' ] );
} );

test( 'Ein Fehler beim Cache-Aufräumen verhindert die Aktivierung nicht', async () => {
	const sw = worker( { cleanupError: true } );
	await sw.dispatch( 'activate' );
	assert.equal( sw.claimed, true );
} );

test( 'Push ohne Ziel öffnet auch bei Unterverzeichnissen die Startadresse der Liga', async () => {
	const sw = worker( { config: { base: '/liga/' } } );
	await sw.dispatch( 'push' );
	assert.equal( sw.notification.options.data.url, origin + '/liga/' );
	await sw.dispatch( 'push', { data: { json: () => ( { url: 'https://fremd.example.org/' } ) } } );
	assert.equal( sw.notification.options.data.url, origin + '/liga/' );
} );
