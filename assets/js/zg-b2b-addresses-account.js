/* Bombora B2B Agent – Můj účet → Adresy: pop-up pro doručovací adresy */
( function ( $ ) {
	'use strict';

	// Potvrzení smazání.
	$( document ).on( 'submit', '.zg-b2b-addr-inline-form', function ( e ) {
		if ( $( this ).find( '.zg-b2b-addr-btn--danger' ).length && typeof zgB2bAddrAccount !== 'undefined' ) {
			if ( ! window.confirm( zgB2bAddrAccount.confirmDelete ) ) {
				e.preventDefault();
			}
		}
	} );

	var $modal = $( '#zg-b2b-addr-modal' );
	if ( ! $modal.length ) {
		return;
	}

	var addresses = $modal.data( 'addresses' ) || {};
	var baseCountry = $modal.data( 'base-country' ) || '';
	var $form = $modal.find( 'form.zg-b2b-addr-form' );
	var $title = $modal.find( '.zg-b2b-addr-modal__title' );
	var lastTrigger = null;

	function setField( key, value ) {
		var $f = $form.find( '[name="shipping_' + key + '"]' );
		if ( ! $f.length ) {
			return;
		}
		$f.val( value == null ? '' : value );
		if ( key === 'country' ) {
			$f.trigger( 'change' ); // country-select.js + address-i18n přestaví pole Kraj/PSČ.
		}
	}

	function fill( id ) {
		var a = addresses[ id ];
		var fields = a ? a.fields : {};
		$form.find( '[name="zg_b2b_addr_id"]' ).val( a ? id : '' );
		$form.find( '[name="zg_b2b_addr_label"]' ).val( a ? a.label : '' );
		$form.find( '[name="zg_b2b_addr_default"]' ).prop( 'checked', !! ( a && a.default ) );
		setField( 'country', fields.country || baseCountry );
		$form.find( '[name^="shipping_"]' ).each( function () {
			var key = this.name.replace( /^shipping_/, '' );
			if ( key !== 'country' ) {
				setField( key, fields[ key ] );
			}
		} );
		$form.find( '.woocommerce-invalid' ).removeClass( 'woocommerce-invalid woocommerce-invalid-required-field' );
		$modal.find( '.zg-b2b-addr-modal__notices' ).remove();
	}

	function open( id, keepValues ) {
		var isEdit = id && id !== 'new' && addresses[ id ];
		$title.text( isEdit ? $title.data( 'title-edit' ) : $title.data( 'title-new' ) );
		if ( keepValues ) {
			$form.find( '[name="zg_b2b_addr_id"]' ).val( isEdit ? id : '' );
		} else {
			fill( isEdit ? id : null );
		}
		$modal.prop( 'hidden', false );
		$( 'html' ).addClass( 'zg-b2b-addr-modal-open' );
		window.setTimeout( function () {
			$form.find( '[name="zg_b2b_addr_label"]' ).trigger( 'focus' );
		}, 30 );
	}

	function close() {
		$modal.prop( 'hidden', true );
		$( 'html' ).removeClass( 'zg-b2b-addr-modal-open' );
		if ( lastTrigger ) {
			lastTrigger.focus();
		}
	}

	$( document ).on( 'click', '[data-zg-addr-open]', function ( e ) {
		e.preventDefault();
		lastTrigger = this;
		open( String( $( this ).data( 'zg-addr-open' ) ), false );
	} );

	$modal.on( 'click', '[data-zg-addr-close]', function ( e ) {
		e.preventDefault();
		close();
	} );

	$( document ).on( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && ! $modal.prop( 'hidden' ) ) {
			close();
		}
	} );

	// Jednoduchá kontrola povinných polí ještě před odesláním.
	$form.on( 'submit', function ( e ) {
		var firstInvalid = null;
		$form.find( '.validate-required' ).each( function () {
			var $row = $( this );
			var $input = $row.find( 'input, select' ).first();
			if ( $input.length && ! $input.is( ':disabled' ) && $row.is( ':visible' ) && ! $.trim( $input.val() || '' ) ) {
				$row.addClass( 'woocommerce-invalid woocommerce-invalid-required-field' );
				firstInvalid = firstInvalid || $input;
			} else {
				$row.removeClass( 'woocommerce-invalid woocommerce-invalid-required-field' );
			}
		} );
		if ( firstInvalid ) {
			e.preventDefault();
			firstInvalid.trigger( 'focus' );
		}
	} );

	// Chyba při uložení (validace na serveru) → okno znovu otevřít s odeslanými údaji a chybami.
	var reopen = $modal.data( 'reopen' );
	if ( reopen ) {
		var $notices = $( '.woocommerce-error, .woocommerce-NoticeGroup' ).first();
		if ( $notices.length ) {
			$( '<div class="zg-b2b-addr-modal__notices"></div>' ).append( $notices ).prependTo( $modal.find( '.zg-b2b-addr-modal__body' ) );
		}
		open( String( reopen ), true );
	}
} )( jQuery );
