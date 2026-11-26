/* Bombora B2B Agent – pokladna: výběr firmy pro fakturaci (doplní skrytá fakturační pole) */
( function ( $ ) {
	'use strict';
	var $picker = $( '#zg-b2b-company-picker' );
	if ( ! $picker.length ) {
		return;
	}
	var companies = ( typeof zgB2bCompanies !== 'undefined' && zgB2bCompanies.companies ) || {};

	function fill( id ) {
		var c = companies[ id ];
		if ( ! c ) {
			return;
		}
		if ( c.billing_country !== undefined ) {
			var $country = $( '#billing_country' );
			if ( $country.length && $country.val() !== c.billing_country ) {
				$country.val( c.billing_country ).trigger( 'change' );
			}
		}
		$.each( c, function ( field, value ) {
			if ( field !== 'billing_country' ) {
				$( '#' + field ).val( value ).trigger( 'input' );
			}
		} );
		$( document.body ).trigger( 'update_checkout' );
	}

	$picker.on( 'change', 'input[name="zg_b2b_company"]', function () {
		$picker.find( '.zg-b2b-addr-option' ).removeClass( 'is-selected' );
		$( this ).closest( '.zg-b2b-addr-option' ).addClass( 'is-selected' );
		fill( this.value );
	} );
} )( jQuery );
