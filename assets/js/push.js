/**
 * ttleague – Push auf diesem Gerät aktivieren oder deaktivieren («Mein Bereich»).
 *
 * Einstellungen kommen als window.ttleaguePush (endpoint, nonce, key, sw, scope, texts).
 * Aktivieren: Erlaubnis anfragen, Service Worker sicherstellen, mit dem öffentlichen
 * VAPID-Schlüssel abonnieren und das Abo an die REST-API melden. Deaktivieren: Abo im
 * Browser kündigen und auf dem Server löschen. Ein bestehendes Abo wird beim Laden erneut
 * gemeldet und nach einem Schlüsselwechsel der Website ersetzt.
 */
( function () {
	'use strict';

	const config = window.ttleaguePush;
	const root = document.querySelector( '[data-ttl-push]' );
	if ( ! config || ! root ) {
		return;
	}
	const status = root.querySelector( '[data-ttl-push-status]' );
	const actions = root.querySelector( '[data-ttl-push-actions]' );
	const button = root.querySelector( '[data-ttl-push-toggle]' );
	const texts = config.texts || {};

	const ios = /iPad|iPhone|iPod/.test( navigator.userAgent ) || ( 'MacIntel' === navigator.platform && navigator.maxTouchPoints > 1 );
	const standalone = true === window.navigator.standalone || ( !! window.matchMedia && window.matchMedia( '(display-mode: standalone)' ).matches );
	const supported = window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

	/**
	 * Zustand anzeigen.
	 *
	 * @param {string} message Text.
	 */
	function show( message ) {
		status.textContent = message || '';
	}

	/**
	 * Knopf und Zustand für «aktiv» oder «nicht aktiv».
	 *
	 * @param {boolean} subscribed Abo vorhanden.
	 */
	function render( subscribed ) {
		show( subscribed ? texts.on : texts.off );
		button.textContent = subscribed ? texts.disable : texts.enable;
		button.dataset.state = subscribed ? 'on' : 'off';
		button.disabled = false;
		actions.hidden = false;
	}

	/**
	 * Base64url in Bytes (applicationServerKey).
	 *
	 * @param {string} value Base64url.
	 * @return {Uint8Array} Bytes.
	 */
	function bytes( value ) {
		const base64 = ( value + '==='.slice( ( value.length + 3 ) % 4 ) ).replace( /-/g, '+' ).replace( /_/g, '/' );
		const raw = window.atob( base64 );
		const out = new Uint8Array( raw.length );
		for ( let i = 0; i < raw.length; i++ ) {
			out[ i ] = raw.charCodeAt( i );
		}
		return out;
	}

	/**
	 * Wurde das Abo mit dem aktuellen Schlüssel der Website erstellt?
	 *
	 * @param {PushSubscription} subscription Abo.
	 * @return {boolean} Gleicher Schlüssel (oder unbekannt).
	 */
	function sameKey( subscription ) {
		const current = subscription.options && subscription.options.applicationServerKey;
		if ( ! current ) {
			return true;
		}
		const a = new Uint8Array( current );
		const b = bytes( config.key );
		return a.length === b.length && a.every( ( value, i ) => value === b[ i ] );
	}

	/**
	 * Anmeldung des Service Workers, bei Bedarf neu.
	 *
	 * @return {Promise<ServiceWorkerRegistration>} Anmeldung mit aktivem Service Worker.
	 */
	async function registration() {
		const existing = await navigator.serviceWorker.getRegistration( config.scope );
		if ( ! existing ) {
			await navigator.serviceWorker.register( config.sw, { scope: config.scope } );
		}
		return navigator.serviceWorker.ready;
	}

	/**
	 * Vorhandenes Abo dieses Browsers.
	 *
	 * @return {Promise<PushSubscription|null>} Abo.
	 */
	async function current() {
		const reg = await navigator.serviceWorker.getRegistration( config.scope );
		return reg && reg.pushManager ? reg.pushManager.getSubscription() : null;
	}

	/**
	 * Neues Abo mit dem Schlüssel der Website.
	 *
	 * @return {Promise<PushSubscription>} Abo.
	 */
	async function subscribe() {
		const reg = await registration();
		let subscription = await reg.pushManager.getSubscription();
		if ( subscription && ! sameKey( subscription ) ) {
			await subscription.unsubscribe();
			subscription = null;
		}
		return subscription || reg.pushManager.subscribe( { userVisibleOnly: true, applicationServerKey: bytes( config.key ) } );
	}

	/**
	 * Anfrage an die REST-API.
	 *
	 * @param {string} method POST oder DELETE.
	 * @param {Object} body   Inhalt.
	 * @return {Promise<Object>} Antwort.
	 */
	async function send( method, body ) {
		const response = await window.fetch( config.endpoint, {
			method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( body ),
		} );
		if ( ! response.ok ) {
			throw new Error( 'HTTP ' + response.status );
		}
		return response.json();
	}

	async function enable() {
		const permission = await window.Notification.requestPermission();
		if ( 'granted' !== permission ) {
			render( false );
			if ( 'denied' === permission ) {
				show( texts.denied );
				actions.hidden = true;
			}
			return;
		}
		const subscription = await subscribe();
		await send( 'POST', subscription.toJSON() );
		render( true );
	}

	async function disable() {
		const subscription = await current();
		if ( subscription ) {
			const endpoint = subscription.endpoint;
			await subscription.unsubscribe();
			await send( 'DELETE', { endpoint } );
		}
		render( false );
	}

	async function init() {
		if ( ! supported ) {
			show( ios && ! standalone ? texts.install : texts.unsupported );
			return;
		}
		if ( 'denied' === window.Notification.permission ) {
			show( texts.denied );
			return;
		}
		try {
			let subscription = await current();
			if ( subscription && 'granted' === window.Notification.permission ) {
				if ( ! sameKey( subscription ) ) {
					subscription = await subscribe();
				}
				await send( 'POST', subscription.toJSON() );
				render( true );
				return;
			}
		} catch ( error ) {
			// Ohne Abo weiter; der Knopf bietet das Aktivieren an.
		}
		render( false );
	}

	button.addEventListener( 'click', async () => {
		const active = 'on' === button.dataset.state;
		button.disabled = true;
		show( texts.working );
		try {
			if ( active ) {
				await disable();
			} else {
				await enable();
			}
		} catch ( error ) {
			show( texts.error );
			button.disabled = false;
		}
	} );

	init();
}() );
