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
		$( 'div.shipping_address' ).toggle( checked );
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

	// Initial state (server already prefilled the fields and ticked the checkbox).
	var initial = $picker.find( 'input[name="zg_b2b_addr_choice"]:checked' ).val() || 'billing';
	setShipTo( initial !== 'billing' );
} )( jQuery );
