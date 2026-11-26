/* Bombora B2B Agent – Můj účet: obecné pop-upy (fakturační údaje, detaily účtu) */
( function ( $ ) {
	'use strict';
	var trigger = null;

	function open( $m, focus ) {
		if ( ! $m.length ) {
			return false;
		}
		$m.prop( 'hidden', false );
		$( 'html' ).addClass( 'zg-b2b-addr-modal-open' );
		$( document.body ).trigger( 'country_to_state_changed' );
		window.setTimeout( function () {
			var $f = focus ? $m.find( focus ) : $();
			if ( ! $f.length ) {
				$f = $m.find( 'input:not([type=hidden]):not([readonly]):not([disabled]), select:not([disabled])' ).first();
			}
			$f.trigger( 'focus' );
		}, 30 );
		return true;
	}
	function close( $m ) {
		$m.prop( 'hidden', true );
		if ( ! $( '.zg-b2b-addr-modal:not([hidden])' ).length ) {
			$( 'html' ).removeClass( 'zg-b2b-addr-modal-open' );
		}
		if ( trigger ) {
			trigger.focus();
		}
	}

	$( document ).on( 'click', '[data-zg-modal-open]', function ( e ) {
		var $btn = $( this );
		if ( open( $( '#' + $btn.data( 'zg-modal-open' ) ), $btn.data( 'zg-modal-focus' ) ) ) {
			e.preventDefault();
			trigger = this;
		}
	} );
	$( document ).on( 'click', '.zg-b2b-gmodal [data-zg-gmodal-close]', function ( e ) {
		e.preventDefault();
		close( $( this ).closest( '.zg-b2b-gmodal' ) );
	} );
	$( document ).on( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			$( '.zg-b2b-gmodal:not([hidden])' ).each( function () {
				close( $( this ) );
			} );
		}
	} );
	$( function () {
		$( '.zg-b2b-gmodal[data-autoopen]' ).first().each( function () {
			open( $( this ) );
		} );
	} );
} )( jQuery );
