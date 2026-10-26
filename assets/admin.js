/* Mietverwaltung – Seitenkopf, Karten und kleine Komfortfunktionen für alle Plugin-Seiten. */
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

		/* Seitenkopf: Icon + Titel + Aktionen (page-title-action) */
		var h1 = wrap.querySelector( ':scope > h1' );
		if ( h1 ) {
			var head = document.createElement( 'header' );
			head.className = 'mv-page-head';
			var icon = document.createElement( 'div' );
			icon.className = 'mv-page-icon';
			icon.innerHTML = '<span class="dashicons dashicons-' + ( ui.icon || 'admin-home' ) + '"></span>';
			var title = document.createElement( 'div' );
			title.className = 'mv-page-title';
			var crumb = document.createElement( 'div' );
			crumb.className = 'mv-crumb';
			crumb.innerHTML = '<a href="' + ( ui.uebersicht || '#' ) + '">' + ( ui.plugin || 'Mietverwaltung' ) + '</a>';
			var actions = document.createElement( 'div' );
			actions.className = 'mv-page-actions';
			wrap.insertBefore( head, h1 );
			head.appendChild( icon );
			head.appendChild( title );
			title.appendChild( crumb );
			title.appendChild( h1 );
			head.appendChild( actions );
			// Aktions-Links, die direkt nach dem Titel stehen, in den Kopf verschieben
			var el = head.nextSibling;
			while ( el ) {
				var next = el.nextSibling;
				if ( el.nodeType === 1 && el.classList.contains( 'page-title-action' ) ) {
					el.classList.add( 'button' );
					actions.appendChild( el );
				} else if ( el.nodeType === 1 && el.classList.contains( 'wp-header-end' ) ) {
					el.remove();
				} else if ( el.nodeType === 3 && ! el.textContent.trim() ) {
					el.remove();
				} else {
					break;
				}
				el = next;
			}
			if ( ! actions.children.length ) { actions.remove(); }
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
			var card = t.closest( '.mv-card' );
			if ( card ) { return; }
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
				var z = row.querySelector( '.mv-z-zahlung' ), b = row.querySelector( '.mv-z-beleg' );
				if ( z ) { z.style.display = sel.value === 'zahlung' ? '' : 'none'; }
				if ( b ) { b.style.display = sel.value === 'beleg' ? '' : 'none'; }
			};
			sel.addEventListener( 'change', sync );
			sync();
		} );

		/* Zahlen rechtsbündig markieren */
		wrap.querySelectorAll( 'table.widefat td' ).forEach( function ( td ) {
			if ( /^-?[\d.]+,\d{2}\s?€$/.test( td.textContent.trim() ) ) { td.classList.add( 'mv-td-num' ); }
		} );
	} );
}() );
