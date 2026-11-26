/* Bombora B2B Agent – pokladna: firma pro fakturaci + kontaktní údaje (řádek se „Změnit údaje“) */
( function ( $ ) {
	'use strict';
	var $picker = $( '#zg-b2b-company-picker' );
	if ( ! $picker.length ) {
		return;
	}
	var companies = ( typeof zgB2bCompanies !== 'undefined' && zgB2bCompanies.companies ) || {};
	var rows = '#billing_first_name_field, #billing_last_name_field, #billing_phone_field, #billing_email_field';
	var $line = $picker.find( '[data-zg-contact="billing"]' );
	var $toggle = $line.find( '.zg-b2b-sum__toggle' );

	function val( id ) {
		return $.trim( $( '#' + id ).val() || '' );
	}
	function complete() {
		return !! ( val( 'billing_first_name' ) && val( 'billing_last_name' ) && val( 'billing_phone' ) && val( 'billing_email' ) );
	}
	function renderLine() {
		$line.find( '.zg-b2b-contact__text' ).text(
			[ $.trim( val( 'billing_first_name' ) + ' ' + val( 'billing_last_name' ) ), val( 'billing_phone' ), val( 'billing_email' ) ].filter( Boolean ).join( ' · ' )
		);
	}
	var cfg = ( typeof zgB2bCompanies !== 'undefined' ) ? zgB2bCompanies : {};
	// Lišta pod poli: stav ukládání + výrazné „Hotovo“ (dřív bylo nahoře a zapomínalo se na něj).
	var $done = $( '<div class="zg-b2b-done-row" hidden><span class="zg-b2b-save-state" aria-live="polite"></span><button type="button" class="zg-b2b-done-btn"></button></div>' );
	$done.find( 'button' ).text( $toggle.data( 'close' ) || 'OK' );

	function placeDone() {
		var $last = $( rows ).last();
		if ( $last.length ) {
			$done.insertAfter( $last );
			// Šablona řadí pole přes CSS order → lišta dostane stejné pořadí jako poslední pole (a je za ním).
			var maxOrder = 0;
			$( rows ).each( function () {
				maxOrder = Math.max( maxOrder, parseInt( $( this ).css( 'order' ), 10 ) || 0 );
			} );
			$done.css( 'order', maxOrder );
		}
	}

	function setOpen( open ) {
		$( rows ).toggleClass( 'zg-b2b-collapsed', ! open );
		$line.toggleClass( 'is-open', open ).prop( 'hidden', open );
		$toggle.attr( 'aria-expanded', open ? 'true' : 'false' );
		if ( open ) {
			placeDone();
		}
		$done.prop( 'hidden', ! open );
		renderLine();
	}

	/* Okamžité uložení do účtu (debounce), aby nebylo nutné klikat na „Hotovo“. */
	var saveTimer = null;
	function save() {
		if ( ! cfg.ajaxUrl || ! complete() ) {
			return;
		}
		var $state = $done.find( '.zg-b2b-save-state' ).removeClass( 'is-ok is-error' ).text( cfg.saving || '…' );
		$.post( cfg.ajaxUrl, {
			action: 'zg_b2b_save_contact',
			nonce: cfg.nonce,
			type: 'billing',
			fields: { first_name: val( 'billing_first_name' ), last_name: val( 'billing_last_name' ), phone: val( 'billing_phone' ), email: val( 'billing_email' ) }
		} ).done( function ( r ) {
			if ( r && r.success ) {
				$state.addClass( 'is-ok' ).text( '✓ ' + ( cfg.saved || '' ) );
			} else {
				$state.addClass( 'is-error' ).text( cfg.error || '' );
			}
		} ).fail( function () {
			$state.addClass( 'is-error' ).text( cfg.error || '' );
		} );
	}
	$( document.body ).on( 'change', '#billing_first_name, #billing_last_name, #billing_phone, #billing_email', function () {
		clearTimeout( saveTimer );
		saveTimer = setTimeout( save, 300 );
	} );
	$done.on( 'click', '.zg-b2b-done-btn', function () {
		if ( complete() ) {
			setOpen( false );
		}
	} );

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

	/* Vidět je jen zvolená firma; seznam ostatních až po „Vybrat jinou firmu“. */
	var $switch = $picker.find( '.zg-b2b-company-switch' );
	function setChoosing( on ) {
		$picker.toggleClass( 'is-choosing', on );
		$switch.attr( 'aria-expanded', on ? 'true' : 'false' ).text( on ? $switch.data( 'close' ) : $switch.data( 'open' ) );
	}
	if ( $switch.length ) {
		$picker.addClass( 'has-choice' );
		$switch.prop( 'hidden', false ).on( 'click', function () {
			setChoosing( ! $picker.hasClass( 'is-choosing' ) );
		} );
	}

	$picker.on( 'change', 'input[name="zg_b2b_company"]', function () {
		$picker.find( '.zg-b2b-addr-option' ).removeClass( 'is-selected' );
		$( this ).closest( '.zg-b2b-addr-option' ).addClass( 'is-selected' );
		fill( this.value );
		setChoosing( false );
	} );

	$toggle.on( 'click', function () {
		setOpen( true );
		$( '#billing_phone' ).trigger( 'focus' );
	} );

	$( document.body ).on( 'input change', '#billing_first_name, #billing_last_name, #billing_phone, #billing_email', renderLine );
	$( document.body ).on( 'checkout_error', function () {
		setOpen( true );
	} );

	$line.prop( 'hidden', false );
	setOpen( ! complete() );
} )( jQuery );
