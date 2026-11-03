/* Mietfux – Kopfzeile, Karten und kleine Komfortfunktionen für alle Plugin-Seiten (ohne Abhängigkeiten). */
( function () {
	'use strict';
	var ui = window.mietvUI || {};

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) { fn(); } else { document.addEventListener( 'DOMContentLoaded', fn ); }
	}

	ready( function () {
		var wrap = document.querySelector( '.mv-wrap' );
		if ( ! wrap ) { return; }
		wrap.classList.add( 'mv-ui' );

		var topbar = document.getElementById( 'mv-topbar' );
		var h1 = wrap.querySelector( ':scope > h1' );

		if ( topbar && h1 ) {
			/* Seitentitel und Aktions-Links (page-title-action) in die Sticky-Kopfzeile verschieben */
			var slot = topbar.querySelector( '.mv-topbar-h1' );
			var actions = topbar.querySelector( '.mv-topbar-actions' );
			var el = h1.nextSibling;
			while ( el ) {
				var next = el.nextSibling;
				if ( el.nodeType === 1 && el.classList.contains( 'page-title-action' ) ) {
					if ( actions ) { actions.appendChild( el ); }
				} else if ( el.nodeType === 1 && el.classList.contains( 'wp-header-end' ) ) {
					el.remove();
				} else if ( el.nodeType === 3 && ! el.textContent.trim() ) {
					el.remove();
				} else {
					break;
				}
				el = next;
			}
			if ( slot ) {
				h1.classList.add( 'mv-topbar-h1' );
				h1.classList.remove( 'wp-heading-inline' );
				slot.parentNode.replaceChild( h1, slot );
			}
		} else if ( h1 && ! topbar ) {
			/* Fallback ohne Kopfzeile: Seitenkopf als Karte */
			var head = document.createElement( 'header' );
			head.className = 'mv-page-head';
			var icon = document.createElement( 'div' );
			icon.className = 'mv-topbar-icon';
			icon.innerHTML = '<span class="dashicons dashicons-' + ( ui.icon || 'admin-home' ) + '"></span>';
			wrap.insertBefore( head, h1 );
			head.appendChild( icon );
			head.appendChild( h1 );
		}

		/* Sticky-Zustand der Kopfzeile (Schatten) */
		if ( topbar ) {
			var sentinel = document.createElement( 'div' );
			sentinel.className = 'mv-topbar-sentinel';
			topbar.parentNode.insertBefore( sentinel, topbar );
			if ( 'IntersectionObserver' in window ) {
				new IntersectionObserver( function ( entries ) {
					topbar.classList.toggle( 'mv-stuck', ! entries[ 0 ].isIntersecting );
				}, { threshold: [ 1 ] } ).observe( sentinel );
			}
		}

		/* Tabellen in scrollbare Karten packen */
		wrap.querySelectorAll( 'table.widefat' ).forEach( function ( t ) {
			if ( t.closest( '.mv-tablewrap' ) ) { return; }
			var d = document.createElement( 'div' );
			d.className = 'mv-tablewrap';
			t.parentNode.insertBefore( d, t );
			d.appendChild( t );
		} );

		/* Formulare (form-table) als Karte */
		wrap.querySelectorAll( 'table.form-table' ).forEach( function ( t ) {
			if ( t.closest( '.mv-card' ) ) { return; }
			var d = document.createElement( 'div' );
			d.className = 'mv-card mv-formcard';
			t.parentNode.insertBefore( d, t );
			d.appendChild( t );
		} );

		/* Bankimport: Zuordnungsfelder passend zum Typ zeigen */
		wrap.querySelectorAll( 'select[name$="[typ]"]' ).forEach( function ( sel ) {
			var row = sel.closest( 'tr' );
			if ( ! row ) { return; }
			var sync = function () {
				var z = row.querySelector( '.mv-z-zahlung' ), b = row.querySelector( '.mv-z-beleg' ), h = row.querySelector( '.mv-z-hausgeld' ), d = row.querySelector( '.mv-z-dauerbeleg' );
				if ( z ) { z.style.display = sel.value === 'zahlung' ? '' : 'none'; }
				if ( b ) { b.style.display = sel.value === 'beleg' ? '' : 'none'; }
				if ( h ) { h.style.display = sel.value === 'hausgeld' ? '' : 'none'; }
				if ( d ) { d.style.display = sel.value === 'dauerbeleg' ? '' : 'none'; }
			};
			sel.addEventListener( 'change', sync );
			sync();
		} );

		/* Zahlen rechtsbündig markieren */
		wrap.querySelectorAll( 'table.widefat td' ).forEach( function ( td ) {
			if ( /^-?[\d.]+,\d{2}\s?€$/.test( td.textContent.trim() ) ) { td.classList.add( 'mv-td-num' ); }
		} );
	} );

	/* Dropdown („+ Neu“): bei Klick außerhalb oder Escape schließen */
	document.addEventListener( 'click', function ( e ) {
		document.querySelectorAll( 'details.mv-dropdown[open]' ).forEach( function ( d ) {
			if ( ! d.contains( e.target ) ) { d.removeAttribute( 'open' ); }
		} );
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			document.querySelectorAll( 'details.mv-dropdown[open]' ).forEach( function ( d ) {
				d.removeAttribute( 'open' );
				var s = d.querySelector( 'summary' );
				if ( s ) { s.focus(); }
			} );
		}
		/* Tastenkürzel „/“: Suchfeld fokussieren */
		if ( e.key === '/' && ! e.ctrlKey && ! e.metaKey && ! e.altKey ) {
			var t = e.target;
			var tippt = t && ( t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test( t.tagName ) );
			var s2 = document.getElementById( 'mv-search' );
			if ( s2 && ! tippt ) {
				e.preventDefault();
				s2.focus();
				s2.select();
			}
		}
	} );

	/* Bestätigungsabfragen, Alle-auswählen-Kästchen, Pflichtauswahl und Drucken (ohne Inline-JS) */
	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest( '[data-confirm], [data-require-checked], [data-action="print"]' );
		if ( ! el ) { return; }
		if ( el.hasAttribute( 'data-confirm' ) && ! window.confirm( el.getAttribute( 'data-confirm' ) ) ) {
			e.preventDefault();
			return;
		}
		if ( el.hasAttribute( 'data-require-checked' ) ) {
			var form = el.closest( 'form' ) || document;
			if ( ! form.querySelector( el.getAttribute( 'data-require-checked' ) + ':checked' ) ) {
				window.alert( el.getAttribute( 'data-require-msg' ) || '' );
				e.preventDefault();
				return;
			}
		}
		if ( el.getAttribute( 'data-action' ) === 'print' ) { window.print(); }
	} );
	document.addEventListener( 'change', function ( e ) {
		var el = e.target;
		if ( ! el.matches || ! el.matches( '[data-check-all]' ) ) { return; }
		var table = el.closest( 'table' );
		if ( ! table ) { return; }
		table.querySelectorAll( el.getAttribute( 'data-check-all' ) ).forEach( function ( c ) {
			if ( ! c.disabled ) { c.checked = el.checked; }
		} );
	} );
	document.addEventListener( 'change', function ( e ) {
		var el = e.target;
		if ( ! el.matches ) { return; }
		if ( el.matches( '[data-autosubmit]' ) && el.form ) { el.form.submit(); }
		if ( el.matches( '[data-disable-target]' ) ) {
			var t = document.getElementById( el.getAttribute( 'data-disable-target' ) );
			if ( t ) { t.disabled = !! ( el.files && el.files.length ); }
		}
	} );
}() );
