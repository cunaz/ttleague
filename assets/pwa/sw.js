/**
 * ttleague – Service Worker der Web-App (ohne Push).
 *
 * WordPress liefert dieses Skript unter /sw.js aus und setzt die Konstante CONFIG davor
 * (siehe TTLeague\Pwa\ServiceWorker): Pfad der Startadresse, Version, Texte und Farben.
 *
 * - Seitenaufrufe: zuerst das Netz; offline die zuletzt gespeicherte Fassung der Seite,
 *   sonst eine kleine Offline-Seite.
 * - Statische Dateien (Stile, Skripte, Schriften, Bilder): sofort aus dem Speicher, im
 *   Hintergrund aus dem Netz erneuert (stale-while-revalidate).
 * - Nie gespeichert: alles ausser GET, fremde Adressen, die Pfade aus CONFIG.never
 *   (Verwaltung, Anmeldung, REST-API, persönliche Seiten), Vorschauen und Antworten mit
 *   Cache-Control private oder no-store (z. B. für angemeldete Personen). Solche Seiten
 *   kommen nur aus dem Netz, offline erscheint die Offline-Seite.
 * - Cache-Namen tragen die Version; beim Aktivieren werden ältere Caches dieses
 *   Service Workers gelöscht. skipWaiting und clients.claim übernehmen sofort.
 */

/* global CONFIG */
'use strict';

const PAGES = CONFIG.prefix + 'pages:' + CONFIG.version;
const ASSETS = CONFIG.prefix + 'assets:' + CONFIG.version;
const ASSET_EXTENSIONS = /\.(?:css|js|mjs|woff2?|ttf|otf|png|jpe?g|gif|webp|avif|svg|ico)$/i;
const SKIP_PARAMS = [ 'preview', 'preview_id', 'customize_changeset_uuid', 'rest_route', 'doing_wp_cron', 'ttl_pwa', 'ttl_ical' ];

/**
 * Gehört die Adresse zur Website (gleiche Herkunft, innerhalb der Startadresse)?
 *
 * @param {URL} url Adresse.
 * @return {boolean} Im Bereich des Service Workers.
 */
function inScope( url ) {
	return url.origin === self.location.origin && url.pathname.startsWith( CONFIG.base );
}

/**
 * Ist die Adresse vom Speichern ausgeschlossen (CONFIG.never, Vorschau, REST-API)?
 *
 * @param {URL} url Adresse.
 * @return {boolean} Nie speichern.
 */
function excluded( url ) {
	const path = url.pathname.slice( CONFIG.base.length );
	if ( CONFIG.never.some( ( entry ) => path === entry || path.startsWith( entry + '/' ) ) ) {
		return true;
	}
	return SKIP_PARAMS.some( ( name ) => url.searchParams.has( name ) );
}

/**
 * Darf die Antwort gespeichert werden?
 *
 * @param {Response} response Antwort.
 * @param {string}   type     Erwarteter Content-Type (Anfang) oder leer.
 * @return {boolean} Nur erfolgreiche Antworten gleicher Herkunft ohne private/no-store.
 */
function storable( response, type ) {
	if ( ! response || ! response.ok || 'basic' !== response.type ) {
		return false;
	}
	const control = ( response.headers.get( 'Cache-Control' ) || '' ).toLowerCase();
	if ( /(?:^|[\s,])(?:private|no-store)(?:[\s,=]|$)/.test( control ) ) {
		return false;
	}
	return '' === type || ( response.headers.get( 'Content-Type' ) || '' ).toLowerCase().startsWith( type );
}

/**
 * Antwort speichern und den Cache auf die Höchstzahl kürzen (älteste zuerst).
 *
 * @param {string}   name     Cache-Name.
 * @param {Request}  request  Anfrage oder Adresse.
 * @param {Response} response Antwort (wird verbraucht).
 * @param {number}   max      Höchstzahl Einträge.
 * @return {Promise<void>}
 */
async function put( name, request, response, max ) {
	const cache = await caches.open( name );
	await cache.put( request, response );
	const keys = await cache.keys();
	for ( let i = 0; i < keys.length - max; i++ ) {
		await cache.delete( keys[ i ] );
	}
}

/**
 * Text für HTML maskieren.
 *
 * @param {string} text Text.
 * @return {string} Maskierter Text.
 */
function escapeHtml( text ) {
	return String( text ).replace( /[&<>"']/g, ( char ) => '&#' + char.charCodeAt( 0 ) + ';' );
}

/**
 * Kleine Offline-Seite ohne weitere Dateien.
 *
 * @return {Response} HTML-Antwort.
 */
function offlinePage() {
	const t = CONFIG.offline;
	const c = CONFIG.colors;
	const html = '<!doctype html><html lang="' + escapeHtml( t.lang ) + '"><head><meta charset="utf-8">' +
		'<meta name="viewport" content="width=device-width, initial-scale=1">' +
		'<meta name="robots" content="noindex">' +
		'<title>' + escapeHtml( t.title + ' – ' + t.site ) + '</title><style>' +
		'body{margin:0;font:1.0625rem/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;background:' + c.background + ';color:' + c.text + '}' +
		'main{max-width:34rem;margin:0 auto;padding:12vh 1.25rem 2rem}' +
		'h1{font-size:1.75rem;line-height:1.2;margin:0 0 .75rem}' +
		'p{margin:0 0 1.25rem}' +
		'.site{font-weight:700;margin-bottom:2rem}' +
		'a{display:inline-flex;align-items:center;min-height:2.75rem;padding:.4rem 1.1rem;margin:0 .5rem .5rem 0;border-radius:.4rem;font-weight:700;text-decoration:none;border:1px solid ' + c.accent + ';color:' + c.accent + '}' +
		'a.primary{background:' + c.accent + ';color:' + c.background + '}' +
		'a:focus-visible{outline:3px solid currentColor;outline-offset:2px}' +
		'</style></head><body><main>' +
		'<p class="site">' + escapeHtml( t.site ) + '</p>' +
		'<h1>' + escapeHtml( t.heading ) + '</h1>' +
		'<p>' + escapeHtml( t.text ) + '</p>' +
		'<p><a class="primary" href="">' + escapeHtml( t.retry ) + '</a><a href="' + escapeHtml( CONFIG.base ) + '">' + escapeHtml( t.home ) + '</a></p>' +
		'</main></body></html>';
	return new Response( html, {
		status: 200,
		headers: {
			'Content-Type': 'text/html; charset=utf-8',
			'Cache-Control': 'no-store',
		},
	} );
}

/**
 * Seite: Netz zuerst, offline die gespeicherte Fassung oder die Offline-Seite.
 *
 * @param {FetchEvent} event Ereignis.
 * @return {Promise<Response>} Antwort.
 */
async function page( event ) {
	const request = event.request;
	try {
		const response = await fetch( request );
		if ( storable( response, 'text/html' ) ) {
			event.waitUntil( put( PAGES, request, response.clone(), CONFIG.maxPages ) );
		}
		return response;
	} catch ( error ) {
		const cache = await caches.open( PAGES );
		const cached = await cache.match( request, { ignoreVary: true } );
		return cached || offlinePage();
	}
}

/**
 * Ausgeschlossene Seite: nur aus dem Netz, nie gespeichert; offline die Offline-Seite.
 *
 * @param {Request} request Anfrage.
 * @return {Promise<Response>} Antwort.
 */
async function networkOnly( request ) {
	try {
		return await fetch( request );
	} catch ( error ) {
		return offlinePage();
	}
}

/**
 * Statische Datei: gespeicherte Fassung sofort, im Hintergrund erneuern.
 *
 * @param {FetchEvent} event Ereignis.
 * @return {Promise<Response>} Antwort.
 */
async function asset( event ) {
	const request = event.request;
	const cache = await caches.open( ASSETS );
	const cached = await cache.match( request, { ignoreVary: true } );
	const network = fetch( request ).then( ( response ) => {
		if ( storable( response, '' ) ) {
			return put( ASSETS, request, response.clone(), CONFIG.maxAssets ).then( () => response );
		}
		return response;
	} );
	if ( cached ) {
		event.waitUntil( network.catch( () => undefined ) );
		return cached;
	}
	return network;
}

/**
 * Seite im Hintergrund laden und speichern (Start der App, beim Installieren offene Seiten).
 *
 * @param {string} address Adresse.
 * @return {Promise<void>}
 */
async function warm( address ) {
	const url = new URL( address, self.location.href );
	if ( ! inScope( url ) || excluded( url ) ) {
		return;
	}
	try {
		const response = await fetch( url.href, { credentials: 'same-origin' } );
		if ( storable( response, 'text/html' ) ) {
			await put( PAGES, url.href, response, CONFIG.maxPages );
		}
	} catch ( error ) {
		// Offline beim Installieren: die Seite wird beim nächsten Besuch gespeichert.
	}
}

// Installieren: Startadresse und bereits offene Seiten speichern, damit sie schon nach dem
// ersten Besuch offline verfügbar sind. Seitenaufrufe werden dabei nicht aufgehalten.
self.addEventListener( 'install', ( event ) => {
	event.waitUntil(
		( async () => {
			const windows = await self.clients.matchAll( { type: 'window', includeUncontrolled: true } );
			const addresses = [ CONFIG.start ].concat( windows.map( ( client ) => client.url ) );
			await Promise.all( addresses.map( warm ) );
			await self.skipWaiting();
		} )()
	);
} );

// Aktivieren: alte Caches dieses Service Workers löschen und offene Seiten übernehmen.
self.addEventListener( 'activate', ( event ) => {
	event.waitUntil(
		( async () => {
			const keep = [ PAGES, ASSETS ];
			for ( const name of await caches.keys() ) {
				if ( name.startsWith( CONFIG.prefix ) && ! keep.includes( name ) ) {
					await caches.delete( name );
				}
			}
			await self.clients.claim();
		} )()
	);
} );

self.addEventListener( 'fetch', ( event ) => {
	const request = event.request;
	if ( 'GET' !== request.method ) {
		return;
	}
	const url = new URL( request.url );
	if ( ! inScope( url ) ) {
		return;
	}
	if ( 'navigate' === request.mode ) {
		event.respondWith( excluded( url ) ? networkOnly( request ) : page( event ) );
		return;
	}
	if ( excluded( url ) ) {
		return;
	}
	if ( [ 'style', 'script', 'font', 'image' ].includes( request.destination ) || ASSET_EXTENSIONS.test( url.pathname ) ) {
		event.respondWith( asset( event ) );
	}
} );
