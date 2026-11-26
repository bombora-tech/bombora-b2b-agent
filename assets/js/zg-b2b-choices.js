/* Bombora B2B Agent – výběr firmy / doručovací adresy: zvolená položka + rozbalovací „Jiná … (N)“ */
( function ( $ ) {
	'use strict';
	$( '[data-zg-choices]' ).each( function () {
		var $box = $( this );
		var $more = $box.children( '.zg-b2b-choices__more' );
		var $opts = $box.children( '.zg-b2b-addr-option' );
		if ( ! $more.length || $opts.length < 2 ) {
			return;
		}
		$box.addClass( 'is-enhanced' );
		$more.prop( 'hidden', false );

		function setOpen( open ) {
			$box.toggleClass( 'is-open', open );
			$more.attr( 'aria-expanded', open ? 'true' : 'false' );
		}
		$more.on( 'click', function () {
			setOpen( ! $box.hasClass( 'is-open' ) );
		} );
		$box.on( 'change', 'input[type="radio"]', function () {
			$opts.removeClass( 'is-selected' );
			$( this ).closest( '.zg-b2b-addr-option' ).addClass( 'is-selected' );
			setOpen( false );
		} );
		// Chyba při objednávce → zobrazit všechny možnosti.
		$( document.body ).on( 'checkout_error', function () {
			if ( ! $opts.filter( '.is-selected' ).length ) {
				setOpen( true );
			}
		} );
	} );
} )( jQuery );
