/* Bombora B2B Agent – pokladna: firma pro fakturaci + sbalené kontaktní údaje */
( function ( $ ) {
	'use strict';
	var $picker = $( '#zg-b2b-company-picker' );
	if ( ! $picker.length ) {
		return;
	}
	var companies = ( typeof zgB2bCompanies !== 'undefined' && zgB2bCompanies.companies ) || {};
	var contactRows = '#billing_first_name_field, #billing_last_name_field, #billing_phone_field, #billing_email_field';
	var $sum = $picker.find( '[data-zg-sum="billing"]' );
	var $edit = $picker.find( '.zg-b2b-sum__edit' );
	var $toggle = $sum.find( '.zg-b2b-sum__toggle' );

	function val( id ) {
		return $.trim( $( '#' + id ).val() || '' );
	}

	function contactComplete() {
		return !! ( val( 'billing_first_name' ) && val( 'billing_last_name' ) && val( 'billing_phone' ) && val( 'billing_email' ) );
	}

	function renderSummary() {
		var $opt = $picker.find( 'input[name="zg_b2b_company"]:checked' ).closest( '.zg-b2b-addr-option' );
		if ( ! $opt.length ) {
			$opt = $picker.find( '.zg-b2b-addr-option' ).first();
		}
		$sum.find( '.zg-b2b-sum__main' ).html( $opt.find( '.zg-b2b-addr-option__text' ).html() || '' );
		var contact = [ $.trim( val( 'billing_first_name' ) + ' ' + val( 'billing_last_name' ) ), val( 'billing_phone' ), val( 'billing_email' ) ].filter( Boolean );
		$sum.find( '.zg-b2b-sum__contact' ).text( contact.join( ' · ' ) );
	}

	function setOpen( open ) {
		$picker.toggleClass( 'is-collapsed', ! open );
		$edit.prop( 'hidden', ! open );
		$( contactRows ).toggleClass( 'zg-b2b-collapsed', ! open );
		$toggle.attr( 'aria-expanded', open ? 'true' : 'false' ).text( open ? $toggle.data( 'close' ) : $toggle.data( 'open' ) );
		if ( ! open ) {
			renderSummary();
		}
	}

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
		renderSummary();
	} );

	$toggle.on( 'click', function () {
		var open = $picker.hasClass( 'is-collapsed' );
		if ( ! open && ! contactComplete() ) {
			return; // Neúplné kontaktní údaje nejdřív doplnit.
		}
		setOpen( open );
	} );

	// Chyba při odeslání → vše rozbalit, aby byla vidět chybná pole.
	$( document.body ).on( 'checkout_error', function () {
		if ( $picker.hasClass( 'is-collapsed' ) ) {
			setOpen( true );
		}
	} );

	// Výchozí stav: sbaleno, pokud jsou kontaktní údaje vyplněné.
	$sum.prop( 'hidden', false );
	setOpen( ! contactComplete() );
} )( jQuery );
