/* Der Verlauf und die Tabelle stehen bereits im HTML. Die Auswahl bleibt im Spielerprofil. */
( function () {
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
		points.forEach( ( point ) => { point.disabled = false; } );

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
			const point = event.target.closest( '.ttl-chart__point' );
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
		scroller.addEventListener( 'scroll', updateNavigation, { passive: true } );
		scroller.scrollLeft = scroller.scrollWidth;
		updateNavigation();
		if ( 'ResizeObserver' in window ) {
			new ResizeObserver( updateNavigation ).observe( scroller );
		}
	}
}() );
