/* Der Verlauf und die Tabelle stehen bereits im HTML. Die Auswahl bleibt im Spielerprofil. */
( function () {
	let tooltipCount = 0;
	for ( const chart of document.querySelectorAll( '[data-rating-chart]' ) ) {
		const section = chart.closest( '.ttl-section--rating' );
		const scroller = chart.querySelector( '.ttl-chart__scroll' );
		const points = Array.from( chart.querySelectorAll( '.ttl-chart__point' ) );
		const rows = Array.from( section.querySelectorAll( '.ttl-rating-history tbody tr' ) ).reverse();
		const detail = section.querySelector( '.ttl-rating-game' );
		const nav = chart.querySelector( '.ttl-chart__nav' );
		if ( ! scroller || ! detail || ! nav || ! points.length || points.length !== rows.length ) {
			continue;
		}
		const [ earlier, later ] = nav.querySelectorAll( 'button' );
		let selected = points.length - 1;
		chart.dataset.interactive = 'true';
		points.forEach( ( point ) => {
			point.disabled = false;
			point.removeAttribute( 'title' );
		} );

		const frame = chart.querySelector( '.ttl-chart__frame' );
		const area = chart.querySelector( '.ttl-chart__area' );
		const guide = document.createElement( 'span' );
		guide.className = 'ttl-chart__guide';
		guide.hidden = true;
		guide.setAttribute( 'aria-hidden', 'true' );
		area.append( guide );

		const tip = document.createElement( 'div' );
		tip.className = 'ttl-chart__tooltip';
		tip.id = 'ttl-chart-tip-' + ++tooltipCount;
		tip.setAttribute( 'role', 'tooltip' );
		tip.hidden = true;
		frame.append( tip );

		let preview = null;
		let dismissed = null;

		function hidePreview() {
			if ( ! preview ) {
				return;
			}
			preview.removeAttribute( 'data-preview' );
			preview.removeAttribute( 'aria-describedby' );
			preview = null;
			tip.hidden = true;
			guide.hidden = true;
		}

		function placePreview( point ) {
			const box = frame.getBoundingClientRect();
			const dot = point.getBoundingClientRect();
			const x = dot.left + dot.width / 2 - box.left;
			const y = dot.top + dot.height / 2 - box.top;
			const height = tip.offsetHeight;
			const gap = 12;
			const firstY = Math.max( 0, -box.top ) + 4;
			const lastY = Math.min( box.height, window.innerHeight - box.top ) - 4;
			const above = y - gap - height;
			const below = y + gap;
			const top = above >= firstY || y - firstY > lastY - y ? above : below;
			tip.style.left = Math.max( 4, Math.min( x - tip.offsetWidth / 2, box.width - tip.offsetWidth - 4 ) ) + 'px';
			tip.style.top = Math.max( firstY, Math.min( top, lastY - height ) ) + 'px';
		}

		function showPreview( point ) {
			if ( preview === point || dismissed === point ) {
				return;
			}
			hidePreview();
			dismissed = null;
			preview = point;
			const cells = rows[ Number( point.dataset.index ) ].cells;
			tip.replaceChildren( ...Array.from( cells ).slice( 0, 3 ).map( ( cell ) => cell.firstElementChild.cloneNode( true ) ) );
			tip.hidden = false;
			guide.hidden = false;
			guide.style.left = point.style.left;
			point.dataset.preview = 'true';
			point.setAttribute( 'aria-describedby', tip.id );
			placePreview( point );
		}

		// Beim Abfahren der Kurve zählt die nächste Partie entlang der Spielachse.
		function nearestPoint( clientX ) {
			const x = clientX - area.getBoundingClientRect().left;
			let first = 0;
			let last = points.length - 1;
			while ( first < last ) {
				const mid = Math.floor( ( first + last ) / 2 );
				if ( x <= ( points[ mid ].offsetLeft + points[ mid + 1 ].offsetLeft ) / 2 ) {
					last = mid;
				} else {
					first = mid + 1;
				}
			}
			return points[ first ];
		}

		area.addEventListener( 'pointermove', ( event ) => {
			if ( event.pointerType !== 'touch' ) {
				showPreview( nearestPoint( event.clientX ) );
			}
		} );
		frame.addEventListener( 'pointermove', ( event ) => {
			if ( ! preview || area.contains( event.target ) ) {
				return;
			}
			const bounds = tip.getBoundingClientRect();
			if ( event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom ) {
				hidePreview();
			}
		} );
		frame.addEventListener( 'pointerleave', () => {
			dismissed = null;
			hidePreview();
		} );
		scroller.addEventListener( 'focusin', ( event ) => {
			if ( event.target.matches( '.ttl-chart__point:focus-visible' ) ) {
				dismissed = null;
				showPreview( event.target );
			}
		} );
		scroller.addEventListener( 'focusout', hidePreview );
		document.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'Escape' && preview ) {
				dismissed = preview;
				hidePreview();
			}
		} );

		function updateNavigation() {
			const end = scroller.scrollWidth - scroller.clientWidth;
			nav.hidden = end < 2;
			earlier.disabled = scroller.scrollLeft <= 1;
			later.disabled = scroller.scrollLeft >= end - 1;
		}

		function select( index ) {
			if ( index !== selected ) {
				points[ selected ].tabIndex = -1;
				points[ selected ].setAttribute( 'aria-pressed', 'false' );
				points[ index ].tabIndex = 0;
				points[ index ].setAttribute( 'aria-pressed', 'true' );
				selected = index;
				detail.replaceChildren( ...Array.from( rows[ index ].cells, ( cell ) => cell.firstElementChild.cloneNode( true ) ) );
			}
		}

		scroller.addEventListener( 'click', ( event ) => {
			const point = event.target.closest( '.ttl-chart__point' ) ||
				( area.contains( event.target ) && preview && nearestPoint( event.clientX ) );
			if ( point ) {
				select( Number( point.dataset.index ) );
			}
		} );
		scroller.addEventListener( 'keydown', ( event ) => {
			if ( ! event.target.matches( '.ttl-chart__point' ) || event.altKey || event.ctrlKey || event.metaKey ) {
				return;
			}
			const targets = { ArrowLeft: selected - 1, ArrowRight: selected + 1, Home: 0, End: points.length - 1 };
			if ( ! Object.hasOwn( targets, event.key ) ) {
				return;
			}
			event.preventDefault();
			const index = Math.max( 0, Math.min( points.length - 1, targets[ event.key ] ) );
			select( index );
			points[ index ].focus( { preventScroll: true } );
			const bounds = scroller.getBoundingClientRect();
			const point = points[ index ].getBoundingClientRect();
			if ( point.left < bounds.left || point.right > bounds.right ) {
				scroller.scrollLeft += point.left - bounds.left - ( bounds.width - point.width ) / 2;
			}
		} );
		nav.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( 'button[data-page]' );
			if ( button ) {
				scroller.scrollBy( { left: Number( button.dataset.page ) * scroller.clientWidth * 0.8 } );
			}
		} );
		function updateLayout() {
			if ( preview && preview.matches( ':focus-visible' ) && preview === document.activeElement ) {
				const dot = preview.getBoundingClientRect();
				const bounds = scroller.getBoundingClientRect();
				if ( dot.right > bounds.left && dot.left < bounds.right ) {
					placePreview( preview );
				} else {
					hidePreview();
				}
			} else {
				hidePreview();
			}
			updateNavigation();
		}
		scroller.addEventListener( 'scroll', updateLayout, { passive: true } );
		scroller.scrollLeft = scroller.scrollWidth;
		updateNavigation();
		if ( 'ResizeObserver' in window ) {
			new ResizeObserver( updateLayout ).observe( scroller );
		}
	}
}() );
