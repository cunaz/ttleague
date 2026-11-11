/**
 * ttleague – Knopf «Teilen» im Matchreport und in der Leseansicht der Erfassungsseite.
 *
 * Ohne JavaScript bleibt der Knopf verborgen. Mit der Web Share API öffnet er den Teilen-Dialog
 * des Geräts mit Titel («Seefeld 1 – Wollishofen 1 6:4») und Adresse des Matchreports. Fehlt
 * sie, heisst der Knopf «Link kopieren» und legt die Adresse in die Zwischenablage; das
 * Ergebnis wird über eine Live-Region angesagt. Ohne beides bleibt der Knopf verborgen.
 */
( function () {
	'use strict';

	function setup( box ) {
		var button = box.querySelector( '.ttl-share__button' );
		var label = box.querySelector( '.ttl-share__label' );
		var status = box.querySelector( '.ttl-share__status' );
		var url = box.getAttribute( 'data-ttl-share-url' ) || window.location.href;
		var title = box.getAttribute( 'data-ttl-share-title' ) || document.title;
		var canShare = 'function' === typeof navigator.share;
		var canCopy =
			!! navigator.clipboard &&
			'function' === typeof navigator.clipboard.writeText &&
			false !== window.isSecureContext;

		if ( ! button || ( ! canShare && ! canCopy ) ) {
			return;
		}

		function announce( attribute ) {
			if ( ! status ) {
				return;
			}
			// Erst leeren, damit auch eine wiederholte Meldung angesagt wird.
			status.textContent = '';
			window.setTimeout( function () {
				status.textContent = box.getAttribute( attribute ) || '';
			}, 50 );
		}

		function copy() {
			if ( ! canCopy ) {
				announce( 'data-ttl-share-failed' );
				return;
			}
			navigator.clipboard.writeText( url ).then(
				function () {
					announce( 'data-ttl-share-copied' );
				},
				function () {
					announce( 'data-ttl-share-failed' );
				}
			);
		}

		if ( ! canShare && label ) {
			label.textContent = box.getAttribute( 'data-ttl-share-copy' ) || label.textContent;
		}
		box.hidden = false;

		button.addEventListener( 'click', function () {
			if ( ! canShare ) {
				copy();
				return;
			}
			if ( status ) {
				status.textContent = '';
			}
			var shared;
			try {
				shared = navigator.share( { title: title, text: title, url: url } );
			} catch ( e ) {
				copy();
				return;
			}
			if ( shared && 'function' === typeof shared.then ) {
				shared.then( null, function ( error ) {
					// Abbruch durch die Person ist kein Fehler; sonst bleibt der Link zum Kopieren.
					if ( ! error || 'AbortError' !== error.name ) {
						copy();
					}
				} );
			}
		} );
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-ttl-share]' ), setup );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
