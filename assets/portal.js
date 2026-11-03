/* Mietfux – Mieterportal (ohne jQuery). */
( function () {
	'use strict';

	function markieren( el ) {
		if ( ! el || ! window.getSelection ) {
			return;
		}
		var range = document.createRange();
		range.selectNodeContents( el );
		var sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange( range );
	}

	function fertig( btn ) {
		var alt = btn.textContent;
		btn.textContent = btn.getAttribute( 'data-done' ) || alt;
		btn.disabled = true;
		window.setTimeout( function () {
			btn.textContent = alt;
			btn.disabled = false;
		}, 2000 );
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '.mv-portal' ), function ( root ) {
			root.classList.add( 'mv-js' );
		} );

		// IBAN kopieren (Fallback: Text markieren).
		Array.prototype.forEach.call( document.querySelectorAll( '.mv-portal [data-copy]' ), function ( btn ) {
			btn.addEventListener( 'click', function () {
				var text   = btn.getAttribute( 'data-copy' ) || '';
				var target = document.getElementById( btn.getAttribute( 'data-target' ) || '' );
				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( text ).then( function () {
						fertig( btn );
					}, function () {
						markieren( target );
					} );
				} else {
					markieren( target );
					try {
						if ( document.execCommand( 'copy' ) ) {
							fertig( btn );
						}
					} catch ( e ) {
						// Markierung bleibt bestehen – Nutzer kopiert selbst.
					}
				}
			} );
		} );

		// Mietkonto: alle Monate / weniger anzeigen.
		Array.prototype.forEach.call( document.querySelectorAll( '.mv-portal [data-toggle-rows]' ), function ( btn ) {
			var card = btn.closest( '.mv-card' );
			var mehr = btn.textContent;
			btn.addEventListener( 'click', function () {
				var offen = card.classList.toggle( 'mv-show-all' );
				btn.textContent = offen ? ( btn.getAttribute( 'data-less' ) || mehr ) : mehr;
				btn.setAttribute( 'aria-expanded', offen ? 'true' : 'false' );
			} );
		} );

		// „+ neue Meldung“ öffnet die Karte und springt hin.
		Array.prototype.forEach.call( document.querySelectorAll( '.mv-portal [data-open]' ), function ( link ) {
			link.addEventListener( 'click', function ( ev ) {
				var det = document.getElementById( link.getAttribute( 'data-open' ) || '' );
				if ( ! det ) {
					return;
				}
				ev.preventDefault();
				det.open = true;
				det.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				var feld = det.querySelector( 'input[type=text], textarea' );
				if ( feld ) {
					window.setTimeout( function () {
						feld.focus();
					}, 300 );
				}
			} );
		} );

		// Formular nach Fehler/Erfolg wieder geöffnet lassen, wenn per Anker angesprungen.
		if ( window.location.hash ) {
			var ziel = document.getElementById( window.location.hash.substring( 1 ) );
			if ( ziel && ziel.tagName === 'DETAILS' ) {
				ziel.open = true;
			}
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
