/* Bombora Shipping Addresses – checkout picker */
( function ( $ ) {
	'use strict';

	if ( typeof zgB2bAddrCheckout === 'undefined' ) {
		return;
	}

	var cfg = zgB2bAddrCheckout;
	var $picker = $( '#zg-b2b-addr-picker' );
	if ( ! $picker.length ) {
		return;
	}

	var $form = $( 'form.checkout' );
	var shipToSelector = '#ship-to-different-address-checkbox, #ship-to-different-address input[type="checkbox"]';
	var syncing = false;
	var fieldsOpen = false;
	var $line = $picker.find( '[data-zg-contact="shipping"]' );
	var $toggle = $line.find( '.zg-b2b-sum__toggle' );

	$form.addClass( 'zg-b2b-addr-active' );

	function setShipTo( checked ) {
		var $cb = $( shipToSelector ).first();
		if ( ! $cb.length ) {
			return;
		}
		if ( $cb.prop( 'checked' ) !== checked ) {
			syncing = true;
			$cb.prop( 'checked', checked ).trigger( 'change' );
			syncing = false;
		}
		// WooCommerce toggles via slideToggle; enforce the final state.
		updateFields();
	}

	function setField( key, value ) {
		var $f = $( '#shipping_' + key );
		if ( ! $f.length ) {
			return;
		}
		$f.val( value == null ? '' : value );
		if ( key === 'country' ) {
			// Rebuilds the state field (country-select.js) and updates selectWoo.
			$f.trigger( 'change' );
		} else {
			$f.trigger( 'input' );
		}
	}

	function fill( fields ) {
		// Country first: it re-renders locale-dependent fields.
		if ( fields.hasOwnProperty( 'country' ) ) {
			setField( 'country', fields.country );
		}
		$.each( cfg.fieldKeys, function ( i, key ) {
			if ( key !== 'country' ) {
				setField( key, fields[ key ] );
			}
		} );
	}

	function currentChoice() {
		return $picker.find( 'input[name="zg_b2b_addr_choice"]:checked' ).val() || 'billing';
	}

	/* Pole doručovací adresy: u uložené adresy skrytá za řádkem kontaktu („Změnit údaje“),
	   u „Jiná adresa“ vždy vidět, u fakturační adresy skrytá. */
	var $done = $( '<div class="zg-b2b-done-row" hidden><span class="zg-b2b-save-state" aria-live="polite"></span><button type="button" class="zg-b2b-done-btn"></button></div>' );
	$done.find( 'button' ).text( cfg.done || 'OK' );
	$( 'div.shipping_address' ).first().append( $done );

	function updateFields() {
		var choice = currentChoice();
		var saved = choice !== 'billing' && choice !== 'new';
		var show = choice === 'new' || ( saved && fieldsOpen );
		$( 'div.shipping_address' ).toggle( show );
		$line.prop( 'hidden', ! saved || fieldsOpen || ! $line.length );
		$done.prop( 'hidden', ! ( saved && fieldsOpen ) );
		var text = [ $.trim( ( $( '#shipping_first_name' ).val() || '' ) + ' ' + ( $( '#shipping_last_name' ).val() || '' ) ), $.trim( $( '#shipping_phone' ).val() || '' ) ].filter( Boolean ).join( ' · ' );
		$line.find( '.zg-b2b-contact__text' ).text( text );
	}

	/* Změny u uložené adresy se hned uloží do adresáře (není nutné „Hotovo“). */
	var saveTimer = null;
	function saveAddress() {
		var choice = currentChoice();
		if ( ! cfg.ajaxUrl || choice === 'billing' || choice === 'new' || ! cfg.addresses[ choice ] ) {
			return;
		}
		var fields = {};
		$.each( cfg.fieldKeys, function ( i, key ) {
			var $f = $( '#shipping_' + key );
			if ( $f.length && key !== 'company' ) {
				fields[ key ] = $f.val() || '';
			}
		} );
		var $state = $done.find( '.zg-b2b-save-state' ).removeClass( 'is-ok is-error' ).text( cfg.saving || '…' );
		$.post( cfg.ajaxUrl, { action: 'zg_b2b_save_contact', nonce: cfg.nonce, type: 'shipping', address: choice, fields: fields } ).done( function ( r ) {
			if ( r && r.success ) {
				cfg.addresses[ choice ] = r.data.fields;
				$picker.find( 'input[name="zg_b2b_addr_choice"][value="' + choice + '"]' ).closest( '.zg-b2b-addr-option' ).find( '.zg-b2b-addr-option__detail' ).text( r.data.inline );
				$state.addClass( 'is-ok' ).text( '✓ ' + ( cfg.saved || '' ) );
			} else {
				$state.addClass( 'is-error' ).text( cfg.error || '' );
			}
		} ).fail( function () {
			$state.addClass( 'is-error' ).text( cfg.error || '' );
		} );
	}
	$( document.body ).on( 'change', 'div.shipping_address :input', function () {
		if ( ! fieldsOpen ) {
			return;
		}
		clearTimeout( saveTimer );
		saveTimer = setTimeout( saveAddress, 300 );
	} );
	$done.on( 'click', '.zg-b2b-done-btn', function () {
		fieldsOpen = false;
		updateFields();
	} );

	function applyChoice( choice ) {
		$picker.find( '.zg-b2b-addr-option' ).removeClass( 'is-selected' );
		$picker.find( 'input[name="zg_b2b_addr_choice"][value="' + choice + '"]' ).closest( '.zg-b2b-addr-option' ).addClass( 'is-selected' );
		$picker.find( '.zg-b2b-addr-picker__save' ).prop( 'hidden', choice !== 'new' );

		if ( choice === 'billing' ) {
			setShipTo( false );
		} else if ( choice === 'new' ) {
			var empty = {};
			$.each( cfg.fieldKeys, function ( i, key ) {
				empty[ key ] = '';
			} );
			empty.country = cfg.billingCountry;
			setShipTo( true );
			fill( empty );
			$( '#shipping_first_name, #shipping_company' ).first().trigger( 'focus' );
		} else if ( cfg.addresses[ choice ] ) {
			setShipTo( true );
			fill( cfg.addresses[ choice ] );
		}

		updateFields();
		$( document.body ).trigger( 'update_checkout' );
	}

	$picker.on( 'change', 'input[name="zg_b2b_addr_choice"]', function () {
		applyChoice( this.value );
	} );

	// Keep the picker in sync if the native checkbox is used anyway.
	$( document.body ).on( 'change', shipToSelector, function () {
		if ( syncing ) {
			return;
		}
		var current = $picker.find( 'input[name="zg_b2b_addr_choice"]:checked' ).val();
		if ( ! this.checked && current !== 'billing' ) {
			$picker.find( 'input[name="zg_b2b_addr_choice"][value="billing"]' ).prop( 'checked', true );
			applyChoice( 'billing' );
		} else if ( this.checked && current === 'billing' ) {
			$picker.find( 'input[name="zg_b2b_addr_choice"][value="new"]' ).prop( 'checked', true );
			applyChoice( 'new' );
		}
	} );

	$toggle.on( 'click', function () {
		fieldsOpen = true;
		updateFields();
	} );
	$( document.body ).on( 'input change', '#shipping_first_name, #shipping_last_name, #shipping_phone', updateFields );

	// Chyba při odeslání → pole adresy zobrazit.
	$( document.body ).on( 'checkout_error', function () {
		fieldsOpen = true;
		updateFields();
	} );

	// Initial state (server already prefilled the fields and ticked the checkbox).
	var initial = currentChoice();
	fieldsOpen = initial !== 'billing' && $( 'div.shipping_address .validate-required' ).filter( function () {
		var $in = $( this ).find( 'input, select' ).first();
		return $in.length && ! $.trim( $in.val() || '' );
	} ).length > 0;
	setShipTo( initial !== 'billing' );
	updateFields();
} )( jQuery );
