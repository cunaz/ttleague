/**
 * ttleague – Resultaterfassung: Ansichtsmodell, Prüfung und Daten für die REST-API.
 *
 * Reine Funktionen ohne Abhängigkeiten, damit sie im Browser (Skriptmodul @ttleague/entry-view)
 * und in den Tests mit Node laufen. buildView() ist das Gegenstück zu
 * TTLeague\Results\EntryForm::view() in PHP; beide müssen dasselbe liefern
 * (tests/Unit/Results/EntryViewParityTest.php). cfg sind die Daten aus EntryForm::data()
 * (Spielfolge, Slots, Spieler, Texte, vorgeschlagene Aufstellung …), form der Zustand des
 * Formulars.
 */

export const MISSING = 'none';

const SIDES = [ 'home', 'away' ];

/**
 * Platzhalter wie sprintf in PHP: %s, %d, %1$s, %2$d.
 *
 * @param {string} text Vorlage.
 * @param {...*}   args Werte.
 * @return {string} Text.
 */
export function fmt( text, ...args ) {
	let next = 0;
	return String( text ).replace( /%(?:(\d+)\$)?[sd]/g, ( match, position ) => {
		const value = position ? args[ Number( position ) - 1 ] : args[ next++ ];
		return undefined === value ? match : String( value );
	} );
}

export function isPlayer( value ) {
	return /^[1-9][0-9]*$/.test( String( value ) );
}

export function emptyGame( cfg ) {
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

/**
 * Besetzte und offene Slots, Vorschläge und Wirkung jedes Spiels (wie EntryForm::view()).
 *
 * @param {Object} cfg  Daten.
 * @param {Object} form Zustand des Formulars.
 * @return {Object} Analyse.
 */
export function analyse( cfg, form ) {
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
		const explicit = form.games[ seq ] ?? emptyGame( cfg );
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
			? { ...emptyGame( cfg ), st: expected.st, win: expected.win }
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
 * Ansichtsmodell, an das die Direktiven gebunden sind (Gegenstück zu EntryForm::view()).
 *
 * @param {Object} cfg    Daten.
 * @param {Object} form   Zustand des Formulars.
 * @param {Object} errors Feld => Meldung.
 * @param {string} mode   enter, correct oder leer (Formular verborgen).
 * @param {Object} ui     reason (Einsprache), action (Entscheid der TK).
 * @return {Object} Ansichtsmodell.
 */
export function buildView( cfg, form, errors, mode, ui ) {
	const S = cfg.strings;
	const lineup = form.lineup;
	const result = analyse( cfg, form );
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
		// Vorschlag aus dem letzten Spiel: gekennzeichnet, solange die Seite ihm genau entspricht.
		const suggestion = cfg.suggestion?.[ side ] ?? {};
		let suggested = Object.keys( suggestion ).length > 0;
		view.lineup[ side ] = { err: err( `lineup.${ side }` ) };
		for ( const slot of cfg.slots[ side ] ) {
			const value = String( lineup[ side ]?.[ slot ] ?? '' );
			const error = err( `lineup.${ side }.${ slot }` );
			if ( String( suggestion[ slot ] ?? '' ) !== value ) {
				suggested = false;
			}
			view.lineup[ side ][ slot ] = {
				err: error,
				bad: '' !== error,
				note: isPlayer( value )
					? String( cfg.eligibility[ side ]?.[ value ]?.note ?? '' )
					: '',
			};
		}
		view.lineup[ side ].sug = suggested;
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
	const auto = ( side ) =>
		fmt(
			S.auto,
			cfg.teams[ side ],
			name( side, present[ side ][ 0 ] ) + ' / ' + name( side, present[ side ][ 1 ] )
		);

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
			home: double ? doubleNames( explicit.hp, 'home' ) : name( 'home', slotGame.home ),
			away: double ? doubleNames( explicit.ap, 'away' ) : name( 'away', slotGame.away ),
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
			autoHome: double && 2 === present.home.length ? auto( 'home' ) : '',
			autoAway: double && 2 === present.away.length ? auto( 'away' ) : '',
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
export function targetOf( field ) {
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
 * Feldfehler der API auf die Felder der Seite abbilden.
 *
 * @param {Array}  list    Fehler { field, message }.
 * @param {string} context form, correct, objection, decision, photo.
 * @return {Object} Feld => Meldung.
 */
export function normalize( list, context ) {
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

function validPair( pair, present ) {
	return (
		2 === String( pair ).length &&
		present.includes( pair[ 0 ] ) &&
		present.includes( pair[ 1 ] )
	);
}

/**
 * Pflichtangaben vor dem Absenden (den Rest prüft der Server).
 *
 * @param {Object} cfg  Daten.
 * @param {Object} form Zustand des Formulars.
 * @return {Object} Feld => Meldung.
 */
export function validate( cfg, form ) {
	const S = cfg.strings;
	const errors = {};
	const add = ( field, message ) => {
		errors[ field ] = errors[ field ] ? errors[ field ] + ' ' + message : message;
	};
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
	const result = analyse( cfg, form );
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

/**
 * Daten für POST result (bzw. result im Entscheid «correct»).
 *
 * @param {Object} cfg     Daten.
 * @param {Object} form    Zustand des Formulars.
 * @param {string} version Erwarteter Stand der Begegnung.
 * @return {Object} lineup, games, comment, version.
 */
export function payload( cfg, form, version ) {
	const lineup = { home: {}, away: {} };
	for ( const side of SIDES ) {
		for ( const slot of cfg.slots[ side ] ) {
			const value = String( form.lineup[ side ][ slot ] ?? '' );
			lineup[ side ][ slot ] = isPlayer( value ) ? Number( value ) : null;
		}
	}
	const result = analyse( cfg, form );
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
	return { lineup, games, comment: form.comment, version };
}
