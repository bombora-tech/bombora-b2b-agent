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
	var collapsed = false;
	var $sum = $picker.find( '[data-zg-sum="shipping"]' );
	var $edit = $picker.find( '.zg-b2b-sum__edit' );
	var $toggle = $sum.find( '.zg-b2b-sum__toggle' );

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
		$( 'div.shipping_address' ).toggle( checked && ! collapsed );
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

	function renderSummary() {
		var choice = currentChoice();
		var $opt = $picker.find( 'input[name="zg_b2b_addr_choice"][value="' + choice + '"]' ).closest( '.zg-b2b-addr-option' );
		$sum.find( '.zg-b2b-sum__main' ).html( $opt.find( '.zg-b2b-addr-option__text' ).html() || '' );
		var contact = '';
		if ( choice !== 'billing' ) {
			contact = [ $.trim( ( $( '#shipping_first_name' ).val() || '' ) + ' ' + ( $( '#shipping_last_name' ).val() || '' ) ), $.trim( $( '#shipping_phone' ).val() || '' ) ].filter( Boolean ).join( ' · ' );
		}
		$sum.find( '.zg-b2b-sum__contact' ).text( contact );
	}

	function setCollapsed( state ) {
		collapsed = state;
		$picker.toggleClass( 'is-collapsed', state );
		$edit.prop( 'hidden', state );
		$toggle.attr( 'aria-expanded', state ? 'false' : 'true' ).text( state ? $toggle.data( 'open' ) : $toggle.data( 'close' ) );
		$( 'div.shipping_address' ).toggle( currentChoice() !== 'billing' && ! state );
		if ( state ) {
			renderSummary();
		}
	}

	function applyChoice( choice ) {
		$picker.find( '.zg-b2b-addr-option' ).removeClass( 'is-selected' );
		$picker.find( 'input[name="zg_b2b_addr_choice"][value="' + choice + '"]' ).closest( '.zg-b2b-addr-option' ).addClass( 'is-selected' );
		$picker.find( '.zg-b2b-addr-picker__save' ).prop( 'hidden', choice !== 'new' );

		if ( choice === 'billing' ) {
			setShipTo( false );
		} else if ( choice === 'new' ) {
			if ( collapsed ) {
				setCollapsed( false );
			}
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

	$toggle.on( 'click', function () {
		setCollapsed( ! collapsed );
	} );

	// Chyba při odeslání → rozbalit, aby byla vidět chybná pole.
	$( document.body ).on( 'checkout_error', function () {
		if ( collapsed ) {
			setCollapsed( false );
		}
	} );

	// Initial state (server already prefilled the fields and ticked the checkbox).
	var initial = currentChoice();
	setShipTo( initial !== 'billing' );
	// Sbalit, pokud je vybraná uložená adresa nebo fakturační (opakované objednávky na stejnou adresu).
	if ( $sum.length ) {
		$sum.prop( 'hidden', false );
		var incomplete = initial !== 'billing' && $( 'div.shipping_address .validate-required' ).filter( function () {
			var $in = $( this ).find( 'input, select' ).first();
			return $in.length && ! $.trim( $in.val() || '' );
		} ).length > 0;
		setCollapsed( initial !== 'new' && ! incomplete );
	}
} )( jQuery );
