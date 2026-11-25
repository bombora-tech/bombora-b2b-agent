/* Bombora Shipping Addresses – My Account */
( function ( $ ) {
	'use strict';
	$( document ).on( 'submit', '.zg-b2b-addr-inline-form', function ( e ) {
		if ( $( this ).find( '.zg-b2b-addr-delete' ).length && typeof zgB2bAddrAccount !== 'undefined' ) {
			if ( ! window.confirm( zgB2bAddrAccount.confirmDelete ) ) {
				e.preventDefault();
			}
		}
	} );
} )( jQuery );
