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
	var $edit = $picker.find( '.zg-b2b-addr-edit' );
	var contactKeys = [ 'first_name', 'last_name', 'phone' ];
	var $recipient = $( '#zg-b2b-recipient' );

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
			// Jméno a telefon řídí blok „Kontaktní osoba“.
			if ( key !== 'country' && ( ! $recipient.length || contactKeys.indexOf( key ) === -1 ) ) {
				setField( key, fields[ key ] );
			}
		} );
		applyRecipient();
	}

	function currentChoice() {
		return $picker.find( 'input[name="zg_b2b_addr_choice"]:checked' ).val() || 'billing';
	}

	/* Pole doručovací adresy: u uložené adresy skrytá (tlačítko „Upravit adresu“),
	   u „Jiná adresa“ vždy vidět, u fakturační adresy skrytá. */
	var $done = $( '<div class="zg-b2b-done-row" hidden><span class="zg-b2b-save-state" aria-live="polite"></span><button type="button" class="zg-b2b-done-btn"></button></div>' );
	$done.find( 'button' ).text( cfg.done || 'OK' );
	$( 'div.shipping_address' ).first().append( $done );

	/* Pole adresy hned pod výběrem doručovací adresy (ne až pod kontaktní osobou / v dalším bloku).
	   Obal .woocommerce-address-fields zachová správné chování výběru země (address-i18n). */
	var $shipFields = $( 'div.shipping_address' ).first();
	if ( $shipFields.length ) {
		var $slot = $( '<div class="woocommerce-address-fields zg-b2b-shipping-inline"></div>' );
		var $manage = $picker.find( '.zg-b2b-addr-picker__manage' );
		if ( $manage.length ) {
			$slot.insertBefore( $manage );
		} else {
			$slot.appendTo( $picker );
		}
		$slot.append( $shipFields );
	}

	/* Hlavička editačního panelu: jasně říká, co se upravuje, že se ukládá automaticky a jak skončit. */
	var $head = $( '<div class="zg-b2b-edit-head"><div class="zg-b2b-edit-head__text"><strong class="zg-b2b-edit-head__title"></strong><span class="zg-b2b-edit-head__hint"></span></div><button type="button" class="zg-b2b-edit-head__close" aria-label="">&times;</button></div>' );
	$head.find( '.zg-b2b-edit-head__close' ).attr( 'aria-label', cfg.done || 'OK' );
	$shipFields.prepend( $head );

	/* Úprava uložené adresy v pop-upu. Pole se do okna přesunou (zůstávají uvnitř formuláře pokladny),
	   po zavření se vrátí zpět. Tělo okna má třídu .woocommerce-address-fields kvůli výběru země. */
	var $modal = $(
		'<div class="zg-b2b-addr zg-b2b-addr-modal zg-b2b-checkout-modal" hidden>' +
			'<div class="zg-b2b-addr-modal__backdrop" data-zg-modal-close></div>' +
			'<div class="zg-b2b-addr-modal__dialog" role="dialog" aria-modal="true">' +
				'<div class="zg-b2b-addr-modal__head"><h2 class="zg-b2b-addr-modal__title"></h2>' +
				'<button type="button" class="zg-b2b-addr-modal__close" data-zg-modal-close>&times;</button></div>' +
				'<div class="zg-b2b-addr-modal__body woocommerce-address-fields"><p class="zg-b2b-addr-intro zg-b2b-checkout-modal__hint"></p></div>' +
			'</div>' +
		'</div>'
	);
	$modal.find( '.zg-b2b-addr-modal__close' ).attr( 'aria-label', cfg.close || '×' );
	// Ne jako poslední potomek formuláře – šablona mu dává position: sticky (sloupec souhrnu).
	$picker.after( $modal );
	var modalOpen = false;

	function openModal() {
		if ( modalOpen || ! $shipFields.length ) {
			return;
		}
		modalOpen = true;
		$modal.find( '.zg-b2b-addr-modal__title' ).text( ( cfg.editTitle || '%s' ).replace( '%s', choiceLabel( currentChoice() ) ) );
		$modal.find( '.zg-b2b-checkout-modal__hint' ).text( cfg.editHint || '' );
		$modal.find( '.zg-b2b-addr-modal__body' ).append( $shipFields );
		$modal.prop( 'hidden', false );
		$( 'html' ).addClass( 'zg-b2b-addr-modal-open' );
		$( document.body ).trigger( 'country_to_state_changed' );
		window.setTimeout( function () { $shipFields.find( '#shipping_address_1' ).trigger( 'focus' ); }, 30 );
	}
	function closeModal() {
		if ( ! modalOpen ) {
			return;
		}
		modalOpen = false;
		$slot.append( $shipFields );
		$modal.prop( 'hidden', true );
		$( 'html' ).removeClass( 'zg-b2b-addr-modal-open' );
		$edit.trigger( 'focus' );
	}
	$modal.on( 'click', '[data-zg-modal-close]', function ( e ) {
		e.preventDefault();
		fieldsOpen = false;
		updateFields();
	} );
	$( document ).on( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && modalOpen ) {
			fieldsOpen = false;
			updateFields();
		}
	} );

	function choiceLabel( choice ) {
		return $.trim( $picker.find( 'input[name="zg_b2b_addr_choice"][value="' + choice + '"]' ).closest( '.zg-b2b-addr-option' ).find( 'strong' ).first().text() );
	}

	function updateFields() {
		var choice = currentChoice();
		var saved = choice !== 'billing' && choice !== 'new';
		var show = choice === 'new' || ( saved && fieldsOpen );
		$( 'div.shipping_address' ).toggle( show );
		$edit.prop( 'hidden', ! saved || fieldsOpen );
		$done.prop( 'hidden', ! ( saved && fieldsOpen ) );

		var editing = saved && fieldsOpen;
		$( '.zg-b2b-shipping-inline' ).toggleClass( 'is-new', choice === 'new' );
		$head.find( '.zg-b2b-edit-head__title' ).text( cfg.newTitle || '' );
		$head.find( '.zg-b2b-edit-head__hint' ).text( cfg.newHint || '' );
		$head.find( '.zg-b2b-edit-head__close' ).prop( 'hidden', true );
		if ( editing ) {
			$done.find( '.zg-b2b-save-state' ).removeClass( 'is-ok is-error' ).text( cfg.autoSave || '' );
			openModal();
		} else {
			closeModal();
		}
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
			if ( $f.length && ( ! $recipient.length || contactKeys.indexOf( key ) === -1 ) ) {
				fields[ key ] = $f.val() || '';
			}
		} );
		var $state = $done.find( '.zg-b2b-save-state' ).removeClass( 'is-ok is-error' ).text( cfg.saving || '…' );
		$.post( cfg.ajaxUrl, { action: 'zg_b2b_save_contact', nonce: cfg.nonce, type: 'shipping', address: choice, fields: fields } ).done( function ( r ) {
			if ( r && r.success ) {
				cfg.addresses[ choice ] = r.data.fields;
				var $opt = $picker.find( 'input[name="zg_b2b_addr_choice"][value="' + choice + '"]' ).closest( '.zg-b2b-addr-option' );
				$opt.find( '.zg-b2b-addr-option__detail' ).text( r.data.inline );
				if ( r.data.label ) {
					$opt.find( 'strong' ).first().text( r.data.label );
					$modal.find( '.zg-b2b-addr-modal__title' ).text( ( cfg.editTitle || '%s' ).replace( '%s', r.data.label ) );
				}
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

	$edit.on( 'click', function () {
		fieldsOpen = true;
		updateFields();
	} );

	/* ---------- Kontaktní osoba (příjemce): vybraná ze seznamu nebo nová ---------- */
	function recipientChoice() {
		return $recipient.find( 'input[name="zg_b2b_contact"]:checked, input[type="hidden"][name="zg_b2b_contact"]' ).first().val() || 'new';
	}
	function applyRecipient() {
		if ( ! $recipient.length ) {
			return;
		}
		var choice = recipientChoice();
		var c = ( cfg.contacts && cfg.contacts[ choice ] ) || null;
		$recipient.find( '.zg-b2b-recipient__new' ).prop( 'hidden', choice !== 'new' );
		$.each( contactKeys, function ( i, key ) {
			var v = c ? c[ key ] : ( $( '#zg_b2b_contact_' + key ).val() || '' );
			var $f = $( '#shipping_' + key );
			if ( $f.length && $f.val() !== v ) {
				$f.val( v );
			}
		} );
	}
	if ( $recipient.length ) {
		$form.addClass( 'zg-b2b-recipient-active' );
		$recipient.on( 'change', 'input[name="zg_b2b_contact"]', function () {
			$recipient.find( '.zg-b2b-addr-option' ).removeClass( 'is-selected' );
			$( this ).closest( '.zg-b2b-addr-option' ).addClass( 'is-selected' );
			applyRecipient();
			if ( this.value === 'new' ) {
				$( '#zg_b2b_contact_first_name' ).trigger( 'focus' );
			}
		} );
		$recipient.on( 'input change', '.zg-b2b-recipient__new input', applyRecipient );
	}

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
	applyRecipient();
	updateFields();
} )( jQuery );
