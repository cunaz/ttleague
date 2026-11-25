/* Der Verlauf und die Tabelle stehen bereits im HTML. Die Auswahl bleibt im Spielerprofil. */
( function () {
	let tooltipCount = 0;
	for ( const chart of document.querySelectorAll( '[data-rating-chart]' ) ) {
		const section = chart.closest( '.ttl-section--rating' );
		const scroller = chart.querySelector( '.ttl-chart__scroll' );
		const points = Array.from( chart.querySelectorAll( '.ttl-chart__point' ) );
		const rows = Array.from( section.querySelectorAll( '.ttl-rating-history tbody tr' ) ).reverse();
		const detail = section.querySelector( '.ttl-rating-game' );
		const overview = chart.querySelector( '.ttl-chart__overview' );
		const range = chart.querySelector( '.ttl-chart__range' );
		if ( ! scroller || ! detail || ! overview || ! range || ! points.length || points.length !== rows.length ) {
			continue;
		}
		let selected = points.length - 1;
		chart.dataset.interactive = 'true';
		scroller.dir = 'ltr';
		points.forEach( ( point ) => {
			point.disabled = false;
			point.removeAttribute( 'title' );
		} );

		const frame = chart.querySelector( '.ttl-chart__frame' );
		const canAnchor = getComputedStyle( frame ).getPropertyValue( 'anchor-scope' ).trim() === '--ttl-preview';
		const area = chart.querySelector( '.ttl-chart__area' );
		const svg = area.querySelector( '.ttl-chart__svg' );
		const mini = svg.cloneNode( false );
		mini.setAttribute( 'class', 'ttl-chart__mini' );
		for ( const line of svg.querySelectorAll( '.ttl-chart__line, .ttl-chart__reset, .ttl-chart__boundary' ) ) {
			mini.append( line.cloneNode( false ) );
		}
		range.before( mini );

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

		function visibleBounds( start, end, size, viewport, padding ) {
			const min = Math.max( start, 0 ) + padding;
			const max = Math.min( end, viewport ) - padding;
			return max - min >= size ? [ min, max ] : [ padding, viewport - padding ];
		}

		function placePreview( point ) {
			const box = frame.getBoundingClientRect();
			const dot = point.getBoundingClientRect();
			const height = tip.offsetHeight;
			const viewportWidth = document.documentElement.clientWidth;
			const viewportHeight = window.innerHeight;
			// CSS zentriert und wendet die Vorschau innerhalb eines vollständig sichtbaren Rahmens.
			const frameVisible = box.left >= 0 && box.right <= viewportWidth && box.top >= 0 && box.bottom <= viewportHeight;
			if ( canAnchor && frameVisible && Math.max( dot.top - box.top, box.bottom - dot.bottom ) >= height + 4 ) {
				tip.style.inset = '';
				tip.dataset.anchored = '';
				return;
			}
			delete tip.dataset.anchored;
			// Ist weniger als eine Karte vom Rahmen sichtbar, nutzt sie den Platz im Fenster.
			const width = tip.offsetWidth;
			const [ firstX, lastX ] = visibleBounds( box.left, box.right, width, viewportWidth, 4 );
			const [ firstY, lastY ] = visibleBounds( box.top, box.bottom, height, viewportHeight, 2 );
			const x = dot.left + dot.width / 2;
			const y = dot.top + dot.height / 2;
			const gap = 12;
			const above = y - gap - height;
			const below = y + gap;
			const top = above >= firstY || y - firstY > lastY - y ? above : below;
			const left = Math.max( firstX, Math.min( x - width / 2, lastX - width ) );
			const clippedTop = Math.max( firstY, Math.min( top, lastY - height ) );
			tip.style.inset = ( clippedTop - box.top ) + 'px auto auto ' + ( left - box.left ) + 'px';
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

		function pointAtOrAfter( x ) {
			let first = 0;
			let last = points.length - 1;
			while ( first < last ) {
				const mid = Math.floor( ( first + last ) / 2 );
				if ( x <= points[ mid ].offsetLeft ) {
					last = mid;
				} else {
					first = mid + 1;
				}
			}
			return first;
		}

		// Beim Abfahren der Kurve zählt die nächste Partie entlang der Spielachse.
		function nearestPoint( clientX ) {
			const x = clientX - area.getBoundingClientRect().left;
			const right = pointAtOrAfter( x );
			const left = Math.max( 0, right - 1 );
			return x - points[ left ].offsetLeft <= points[ right ].offsetLeft - x ? points[ left ] : points[ right ];
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
		function updateViewport() {
			if ( ! preview ) {
				return;
			}
			const dot = preview.getBoundingClientRect();
			if ( dot.bottom > 0 && dot.top < window.innerHeight && dot.right > 0 && dot.left < document.documentElement.clientWidth ) {
				placePreview( preview );
			} else {
				hidePreview();
			}
		}
		window.addEventListener( 'scroll', updateViewport, { passive: true } );
		window.addEventListener( 'resize', updateViewport );
		document.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'Escape' && preview ) {
				dismissed = preview;
				hidePreview();
			}
		} );

		function updateOverview() {
			const width = scroller.clientWidth;
			const total = scroller.scrollWidth;
			const end = total - width;
			overview.hidden = end < 2 || ! width;
			if ( overview.hidden ) {
				return;
			}
			// Der Range-Daumen deckt genau den im Hauptdiagramm sichtbaren Anteil ab.
			const left = Math.max( 0, Math.min( end, scroller.scrollLeft ) );
			const right = left + width;
			const first = pointAtOrAfter( left );
			let last = pointAtOrAfter( right );
			if ( last > first && points[ last ].offsetLeft > right ) {
				last--;
			}
			const from = points[ first ].dataset.date;
			const to = points[ last ].dataset.date;
			overview.style.setProperty( '--ttl-window-width', range.clientWidth * width / total + 'px' );
			range.value = 100 * left / end;
			range.setAttribute( 'aria-valuetext', from === to ? from : from + ' – ' + to );
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
		range.addEventListener( 'input', () => {
			scroller.scrollLeft = range.valueAsNumber / 100 * ( scroller.scrollWidth - scroller.clientWidth );
		} );
		// Beim Ziehen des breiten Sichtfensters bleibt die gegriffene Stelle unter dem Zeiger.
		let grabOffset = 0;
		function overviewPosition( event ) {
			const bounds = range.getBoundingClientRect();
			return ( event.clientX - bounds.left ) / bounds.width * scroller.scrollWidth;
		}
		range.addEventListener( 'pointerdown', ( event ) => {
			if ( ! event.isPrimary || event.button !== 0 ) {
				return;
			}
			event.preventDefault();
			range.focus( { preventScroll: true } );
			range.setPointerCapture( event.pointerId );
			const x = overviewPosition( event );
			const offset = x - scroller.scrollLeft;
			grabOffset = offset >= 0 && offset <= scroller.clientWidth ? offset : scroller.clientWidth / 2;
			scroller.scrollLeft = x - grabOffset;
		} );
		range.addEventListener( 'pointermove', ( event ) => {
			if ( event.isPrimary && range.hasPointerCapture( event.pointerId ) ) {
				scroller.scrollLeft = overviewPosition( event ) - grabOffset;
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
			updateOverview();
		}
		scroller.addEventListener( 'scroll', updateLayout, { passive: true } );
		scroller.scrollLeft = scroller.scrollWidth;
		updateOverview();
		if ( 'IntersectionObserver' in window ) {
			// Angeschnittene Datumsangaben ausblenden, ohne ihren Platz auf der Achse zu ändern.
			const threshold = 0.99;
			const labels = new IntersectionObserver( ( entries ) => {
				for ( const entry of entries ) {
					const shown = entry.intersectionRatio >= threshold && entry.intersectionRect.width > 0 && entry.intersectionRect.height > 0;
					entry.target.style.visibility = shown ? '' : 'hidden';
				}
			}, { root: scroller, threshold } );
			for ( const label of area.querySelectorAll( '.ttl-chart__date' ) ) {
				labels.observe( label );
			}
		}
		if ( 'ResizeObserver' in window ) {
			const observer = new ResizeObserver( updateLayout );
			observer.observe( scroller );
			observer.observe( range );
		}
	}
}() );
