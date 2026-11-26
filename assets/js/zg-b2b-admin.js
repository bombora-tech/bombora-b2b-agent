/* Bombora B2B Agent – záložky nastavení (všechna pole zůstávají v jednom formuláři). */
( function () {
	var root = document.querySelector( '.zg-adm' );
	if ( ! root ) {
		return;
	}
	var KEY = 'zgB2bAdmTab';
	var tabs = root.querySelectorAll( '.zg-adm-tabs a' );
	var panels = root.querySelectorAll( '.zg-adm-panel' );
	root.classList.add( 'has-js' );

	function show( name ) {
		var found = false;
		tabs.forEach( function ( t ) {
			var on = t.getAttribute( 'data-tab' ) === name;
			t.classList.toggle( 'is-active', on );
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			found = found || on;
		} );
		if ( ! found ) {
			name = 'prehled';
			return show( name );
		}
		panels.forEach( function ( p ) {
			p.classList.toggle( 'is-active', p.getAttribute( 'data-tab' ) === name );
		} );
		root.classList.toggle( 'is-tools', 'nastroje' === name );
		root.classList.toggle( 'is-overview', 'prehled' === name );
		try {
			localStorage.setItem( KEY, name );
		} catch ( e ) {}
		if ( history.replaceState ) {
			history.replaceState( null, '', '#' + name );
		}
	}

	root.addEventListener( 'click', function ( e ) {
		var a = e.target.closest( '[data-tab], [data-goto]' );
		if ( ! a || a.classList.contains( 'zg-adm-panel' ) ) {
			return;
		}
		e.preventDefault();
		show( a.getAttribute( 'data-goto' ) || a.getAttribute( 'data-tab' ) );
		window.scrollTo( { top: 0, behavior: 'smooth' } );
	} );

	// Staré odkazy (#zg-ef, #zg-ef-docs, #zg-sv) i uložená záložka.
	var hash = ( location.hash || '' ).replace( '#', '' );
	var map = { 'zg-ef': 'eurofaktura', 'zg-ef-docs': 'eurofaktura', 'zg-sv': 'sv' };
	var start = map[ hash ] || hash;
	if ( ! start ) {
		try {
			start = localStorage.getItem( KEY );
		} catch ( e ) {}
	}
	show( start || 'prehled' );

	// Neplatné pole ve skryté záložce → přepnout na ni.
	var form = root.querySelector( '.zg-adm-form' );
	if ( form ) {
		form.addEventListener( 'invalid', function ( e ) {
			var p = e.target.closest( '.zg-adm-panel' );
			if ( p && ! p.classList.contains( 'is-active' ) ) {
				show( p.getAttribute( 'data-tab' ) );
			}
		}, true );
	}
} )();
