/* Bombora B2B Agent – Můj účet → Adresy: pop-up pro doručovací adresy */
( function ( $ ) {
	'use strict';

	var cfg = typeof zgB2bAddrAccount !== 'undefined' ? zgB2bAddrAccount : {};

	// Potvrzení smazání adresy / odebrání firmy.
	$( document ).on( 'submit', '.zg-b2b-addr-inline-form', function ( e ) {
		if ( ! $( this ).find( '.zg-b2b-addr-btn--danger' ).length ) {
			return;
		}
		var isCompany = $( this ).find( '[name="zg_b2b_company_action"]' ).length;
		if ( ! window.confirm( isCompany ? cfg.confirmRemove : cfg.confirmDelete ) ) {
			e.preventDefault();
		}
	} );

	/* ---------- Pop-up „Přidat firmu“ ---------- */
	( function () {
		var $cm = $( '#zg-b2b-company-modal' );
		if ( ! $cm.length ) {
			return;
		}
		var $cf = $cm.find( 'form' );
		var $msg = $cm.find( '.zg-b2b-company-lookup-msg' );
		var trigger = null;

		function toggleCountry() {
			var cz = $cf.find( '[name="zg_company_country"]' ).val() === 'CZ';
			$cm.find( '.zg-b2b-company-ares, .zg-b2b-company-cz-only' ).toggle( cz );
			$cm.find( '.zg-b2b-company-eu-only' ).toggle( ! cz );
			$cm.find( '#zg_company_ico_field' ).toggleClass( 'validate-required', cz );
			$cm.find( '#zg_company_dic_field' ).toggleClass( 'validate-required', ! cz );
		}
		function openCm() {
			$cm.prop( 'hidden', false );
			$( 'html' ).addClass( 'zg-b2b-addr-modal-open' );
			$( document.body ).trigger( 'country_to_state_changed' );
			toggleCountry();
			window.setTimeout( function () { $cf.find( '[name="zg_company_ico"]' ).trigger( 'focus' ); }, 30 );
		}
		function closeCm() {
			$cm.prop( 'hidden', true );
			$( 'html' ).removeClass( 'zg-b2b-addr-modal-open' );
			if ( trigger ) { trigger.focus(); }
		}
		$( document ).on( 'click', '[data-zg-company-open]', function ( e ) {
			e.preventDefault();
			trigger = this;
			openCm();
		} );
		$cm.on( 'click', '[data-zg-modal-close]', function ( e ) {
			e.preventDefault();
			closeCm();
		} );
		$( document ).on( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && ! $cm.prop( 'hidden' ) ) { closeCm(); }
		} );
		$cf.on( 'change', '[name="zg_company_country"]', toggleCountry );

		// ARES (CZ) / VIES (EU) – stejné AJAX volání jako registrace.
		$cm.on( 'click', '.zg-b2b-company-ares, .zg-b2b-company-vies', function () {
			var type = $( this ).data( 'type' );
			var data = { action: 'zg_b2b_lookup', type: type };
			if ( type === 'ares' ) {
				data.ico = $cf.find( '[name="zg_company_ico"]' ).val();
			} else {
				data.vat = $cf.find( '[name="zg_company_dic"]' ).val();
				data.country = $cf.find( '[name="zg_company_country"]' ).val();
			}
			$msg.removeClass( 'is-error is-ok' ).text( cfg.loading || '…' );
			$.post( cfg.ajaxUrl, data ).done( function ( r ) {
				if ( r && r.success && r.data ) {
					var d = r.data;
					var set = function ( k, v ) { if ( v ) { $cf.find( '[name="zg_company_' + k + '"]' ).val( v ); } };
					set( 'company', d.company );
					set( 'address_1', d.street );
					set( 'city', d.city );
					set( 'postcode', d.postcode );
					set( 'dic', d.dic );
					$msg.addClass( 'is-ok' ).text( cfg.filled );
				} else {
					$msg.addClass( 'is-error' ).text( r && r.data && r.data.message ? r.data.message : cfg.error );
				}
			} ).fail( function () {
				$msg.addClass( 'is-error' ).text( cfg.error );
			} );
		} );

		$cf.on( 'submit', function ( e ) {
			var first = null;
			$cf.find( '.validate-required' ).each( function () {
				var $row = $( this ), $in = $row.find( 'input, select' ).first();
				var empty = $in.length && ! $.trim( $in.val() || '' );
				$row.toggleClass( 'woocommerce-invalid woocommerce-invalid-required-field', empty );
				if ( empty && ! first ) { first = $in; }
			} );
			if ( first ) { e.preventDefault(); first.trigger( 'focus' ); }
		} );

		if ( $cm.data( 'reopen' ) ) {
			var $n = $( '.woocommerce-error, .woocommerce-NoticeGroup' ).first();
			if ( $n.length ) {
				$( '<div class="zg-b2b-addr-modal__notices"></div>' ).append( $n ).prependTo( $cm.find( '.zg-b2b-addr-modal__body' ) );
			}
			openCm();
		}
	} )();

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
		$( document.body ).trigger( 'country_to_state_changed' ); // selectWoo pro výběr země (jen viditelná pole).
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
