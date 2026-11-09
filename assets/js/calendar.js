/**
 * ttleague – Kalender-Abo: Adresse in die Zwischenablage kopieren.
 *
 * Ohne JavaScript bleibt das Feld lesbar und markierbar. Der Knopf «Adresse kopieren»
 * erscheint nur, wenn die Zwischenablage verfügbar ist; das Ergebnis wird über eine
 * Live-Region angesagt.
 */
( function () {
	'use strict';

	function select( field ) {
		field.focus();
		field.select();
		try {
			field.setSelectionRange( 0, field.value.length );
		} catch ( e ) {
			// Ältere Browser ohne Auswahl per setSelectionRange: select() genügt.
		}
	}

	function init() {
		var fields = document.querySelectorAll( '.ttl-subscribe__url' );
		Array.prototype.forEach.call( fields, function ( field ) {
			field.addEventListener( 'focus', function () {
				field.select();
			} );
		} );

		if ( ! navigator.clipboard || ! window.isSecureContext ) {
			return;
		}
		var buttons = document.querySelectorAll( '[data-ttl-copy]' );
		Array.prototype.forEach.call( buttons, function ( button ) {
			var field = document.getElementById( button.getAttribute( 'data-ttl-copy' ) );
			var box = button.closest( '.ttl-subscribe__copy' );
			var status = box ? box.querySelector( '.ttl-subscribe__status' ) : null;
			if ( ! field ) {
				return;
			}
			button.hidden = false;
			button.addEventListener( 'click', function () {
				navigator.clipboard.writeText( field.value ).then(
					function () {
						if ( status ) {
							status.textContent = '';
							window.setTimeout( function () {
								status.textContent = button.getAttribute( 'data-ttl-copied' ) || '';
							}, 50 );
						}
					},
					function () {
						select( field );
					}
				);
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
