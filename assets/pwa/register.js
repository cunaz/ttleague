/**
 * ttleague – meldet den Service Worker der Web-App an.
 *
 * Einstellungen kommen als window.ttleaguePwa (sw, scope). Ohne Unterstützung für
 * Service Worker oder ausserhalb eines sicheren Kontexts geschieht nichts.
 */
( function () {
	'use strict';

	var config = window.ttleaguePwa;
	if ( ! config || ! config.sw || ! ( 'serviceWorker' in navigator ) || ! window.isSecureContext ) {
		return;
	}

	function register() {
		navigator.serviceWorker.register( config.sw, { scope: config.scope || '/' } ).catch( function () {
			// Ohne Service Worker funktioniert die Website wie bisher.
		} );
	}

	if ( 'complete' === document.readyState ) {
		register();
	} else {
		window.addEventListener( 'load', register );
	}
}() );
