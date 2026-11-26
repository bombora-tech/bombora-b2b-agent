/* Bombora B2B Agent – Můj účet → Kontaktní osoby: pop-up přidání / úpravy */
( function ( $ ) {
	'use strict';
	var cfg = typeof zgB2bContacts !== 'undefined' ? zgB2bContacts : {};
	var $m = $( '#zg-b2b-contact-modal' );
	if ( ! $m.length ) {
		return;
	}
	var data = $m.data( 'contacts' ) || {};
	var $f = $m.find( 'form' );
	var $title = $m.find( '.zg-b2b-addr-modal__title' );
	var trigger = null;

	function open( id ) {
		var c = data[ id ] || { first_name: '', last_name: '', phone: '' };
		$f.find( '[name="zg_b2b_contact_id"]' ).val( data[ id ] ? id : '' );
		$.each( [ 'first_name', 'last_name', 'phone' ], function ( i, k ) {
			$f.find( '[name="zg_b2b_contact_' + k + '"]' ).val( c[ k ] || '' );
		} );
		$title.text( data[ id ] ? $title.data( 'title-edit' ) : $title.data( 'title-new' ) );
		$m.prop( 'hidden', false );
		$( 'html' ).addClass( 'zg-b2b-addr-modal-open' );
		window.setTimeout( function () { $f.find( '[name="zg_b2b_contact_first_name"]' ).trigger( 'focus' ); }, 30 );
	}
	function close() {
		$m.prop( 'hidden', true );
		$( 'html' ).removeClass( 'zg-b2b-addr-modal-open' );
		if ( trigger ) { trigger.focus(); }
	}
	$( document ).on( 'click', '[data-zg-contact-open]', function ( e ) {
		e.preventDefault();
		trigger = this;
		open( String( $( this ).data( 'zg-contact-open' ) ) );
	} );
	$m.on( 'click', '[data-zg-modal-close]', function ( e ) {
		e.preventDefault();
		close();
	} );
	$( document ).on( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && ! $m.prop( 'hidden' ) ) { close(); }
	} );
	$( document ).on( 'submit', '.zg-b2b-contact-delete', function ( e ) {
		if ( ! window.confirm( cfg.confirmDelete || '?' ) ) {
			e.preventDefault();
		}
	} );
} )( jQuery );
