/* Mietverwaltung – Kaufvertrag im Browser auslesen (pdf.js). Nichts verlässt den Rechner. */
(function () {
	'use strict';
	var T = window.mietvI18n || {};
	function t( k, fb ) { return T[ k ] || fb; }

	var MONATE = { januar: 1, februar: 2, 'märz': 3, maerz: 3, april: 4, mai: 5, juni: 6, juli: 7, august: 8, september: 9, oktober: 10, november: 11, dezember: 12 };

	function pad( n ) { return ( n < 10 ? '0' : '' ) + n; }
	function iso( d, m, y ) { return y + '-' + pad( m ) + '-' + pad( d ); }
	function de( isoDate ) { var p = isoDate.split( '-' ); return p[2] + '.' + p[1] + '.' + p[0]; }
	function eur( n ) { return n.toLocaleString( 'de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) + ' €'; }
	function toNumber( s ) { return parseFloat( s.replace( /\./g, '' ).replace( ',', '.' ) ); }

	/** Beträge im Umkreis (davor und danach) eines Stichworts, nach Nähe sortiert. */
	function amountsNear( text, keyword, span ) {
		var out = [], re = new RegExp( keyword, 'gi' ), m;
		span = span || 200;
		while ( ( m = re.exec( text ) ) ) {
			var start = Math.max( 0, m.index - span ), win = text.substr( start, span * 2 + m[0].length );
			var ar = /(?:€|EUR|Euro)?\s*(\d{1,3}(?:\.\d{3})+|\d{4,9})(?:,(\d{2})|,--|,-)?\s*(?:€|EUR|Euro)?/g, a;
			while ( ( a = ar.exec( win ) ) ) {
				if ( ! /€|EUR|Euro/.test( a[0] ) ) { continue; }
				var v = toNumber( a[1] + ( a[2] ? ',' + a[2] : '' ) );
				if ( v >= 1000 ) { out.push( { v: v, d: Math.abs( start + a.index - m.index ) } ); }
			}
		}
		out.sort( function ( a, b ) { return a.d - b.d; } );
		return out.map( function ( o ) { return o.v; } );
	}

	/** Datumsangaben im Umkreis eines Stichworts, nach Nähe sortiert. */
	function datesNear( text, keyword, span ) {
		var out = [], re = new RegExp( keyword, 'gi' ), m;
		span = span || 200;
		while ( ( m = re.exec( text ) ) ) {
			var start = Math.max( 0, m.index - span ), win = text.substr( start, span * 2 + m[0].length ), d;
			var num = /(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{4})/g;
			while ( ( d = num.exec( win ) ) ) { out.push( { v: iso( +d[1], +d[2], +d[3] ), d: Math.abs( start + d.index - m.index ) } ); }
			var txt = /(\d{1,2})\.\s?(Januar|Februar|März|Maerz|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember)\s(\d{4})/gi;
			while ( ( d = txt.exec( win ) ) ) { out.push( { v: iso( +d[1], MONATE[ d[2].toLowerCase() ], +d[3] ), d: Math.abs( start + d.index - m.index ) } ); }
		}
		out.sort( function ( a, b ) { return a.d - b.d; } );
		return out.map( function ( o ) { return o.v; } );
	}

	function uniq( arr ) { return arr.filter( function ( v, i ) { return arr.indexOf( v ) === i; } ); }
	function mostCommon( arr ) {
		var c = {}, best = null;
		arr.forEach( function ( v ) { c[v] = ( c[v] || 0 ) + 1; if ( best === null || c[v] > c[best] ) { best = v; } } );
		return best;
	}

	function analyse( text ) {
		var t = text.replace( /\s+/g, ' ' );
		var r = { kaufpreis: [], grund: [], gebaeude: [], uebergang: [], vertrag: [], flaeche: [] };

		r.kaufpreis = uniq( amountsNear( t, 'Kaufpreis', 250 ) );
		r.grund     = uniq( amountsNear( t, 'Grund und Boden|Grundst(?:ü|ue)cks?(?:anteil|wert)|Bodenwert', 200 ) );
		r.gebaeude  = uniq( amountsNear( t, 'Geb(?:ä|ae)ude(?:anteil|wert)?|auf das Geb(?:ä|ae)ude', 200 ) );
		r.uebergang = uniq( datesNear( t, 'Nutzen und Lasten|Besitz(?:übergang|uebergang)?|(?:Ü|Ue)bergabe|(?:Ü|Ue)bergang', 300 ) );
		r.vertrag   = uniq( datesNear( t, 'Urkunden(?:rolle|nummer)|UR[-\\s]?Nr|Verhandelt|verhandelt am|Notar', 200 ) );
		var f = /Wohnfl(?:ä|ae)che[^0-9]{0,40}(\d{1,4}(?:,\d{1,2})?)\s*(?:m²|qm|m2)/i.exec( t );
		if ( f ) { r.flaeche = [ toNumber( f[1] ) ]; }

		// Bester Kaufpreis: Betrag direkt nach „Kaufpreis beträgt/von/in Höhe von“, sonst der nächste am Stichwort
		var direkt = amountsNear( t, 'Kaufpreis\\s+(?:betr(?:ä|ae)gt|von|in H(?:ö|oe)he von)', 80 );
		r.kaufpreisBest = direkt.length ? direkt[0] : ( r.kaufpreis.length ? r.kaufpreis[0] : null );
		if ( r.kaufpreisBest ) { r.kaufpreis = uniq( [ r.kaufpreisBest ].concat( r.kaufpreis ) ); }
		r.grundanteil = null;
		if ( r.grund.length && r.kaufpreisBest ) {
			var g = r.grund[0];
			if ( r.gebaeude.length && Math.abs( g + r.gebaeude[0] - r.kaufpreisBest ) < 1 ) {
				r.grundanteil = g / r.kaufpreisBest * 100;
			} else if ( g < r.kaufpreisBest ) {
				r.grundanteil = g / r.kaufpreisBest * 100;
			}
		}
		return r;
	}

	function setField( id, value ) {
		var el = document.getElementById( 'mietv_' + id );
		if ( ! el ) { return; }
		el.value = value;
		el.style.background = '#e6f4ea';
		setTimeout( function () { el.style.background = ''; }, 1500 );
	}

	function row( label, candidates, field, fmt, conv ) {
		if ( ! candidates.length ) {
			return '<tr><th>' + label + '</th><td><em>' + t( 'nicht', 'nicht gefunden' ) + '</em></td></tr>';
		}
		var btns = candidates.slice( 0, 6 ).map( function ( v ) {
			return '<button type="button" class="button button-small mv-apply" data-field="' + field + '" data-value="' + conv( v ) + '">' + fmt( v ) + '</button>';
		} ).join( ' ' );
		return '<tr><th>' + label + '</th><td>' + btns + '</td></tr>';
	}

	function render( panel, r, pages, hasText ) {
		if ( ! hasText ) {
			panel.innerHTML = '<p class="mv-extract-warn">' + t( 'kein_text', 'Kein Textlayer gefunden.' ) + '</p>';
			panel.hidden = false;
			return;
		}
		var num2 = function ( v ) { return v.toFixed( 2 ).replace( '.', ',' ); };
		var html = '<p><strong>' + t( 'erkannt', 'Erkannt' ) + '</strong> (' + pages + ' ' + t( 'seiten', 'Seiten' ) + ') – ' + t( 'uebernehmen', 'anklicken:' ) + '</p><table class="widefat mv-extract-table"><tbody>';
		html += row( t( 'kaufpreis', 'Kaufpreis' ), r.kaufpreis, 'ak', eur, num2 );
		html += row( t( 'uebergang', 'Übergang Nutzen/Lasten' ), r.uebergang, 'anschaffung', de, function ( v ) { return v; } );
		html += row( t( 'grund_betrag', 'Grund und Boden (Betrag)' ), r.grund, '', eur, num2 ).replace( /<button[^>]*>/g, '<span class="mv-pill">' ).replace( /<\/button>/g, '</span>' );
		html += row( t( 'grund_anteil', 'Anteil Grund und Boden' ), r.grundanteil !== null ? [ r.grundanteil ] : [], 'grundanteil', function ( v ) { return num2( v ) + ' %'; }, num2 );
		html += row( t( 'vertrag', 'Vertragsdatum' ), r.vertrag, '', de, function ( v ) { return v; } ).replace( /<button[^>]*>/g, '<span class="mv-pill">' ).replace( /<\/button>/g, '</span>' );
		html += row( t( 'flaeche', 'Wohnfläche' ), r.flaeche, '', function ( v ) { return num2( v ) + ' m²'; }, num2 ).replace( /<button[^>]*>/g, '<span class="mv-pill">' ).replace( /<\/button>/g, '</span>' );
		html += '</tbody></table><p class="description">' + t( 'hinweis', '' ) + '</p>';
		panel.innerHTML = html;
		panel.hidden = false;
		panel.querySelectorAll( '.mv-apply' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () { setField( b.dataset.field, b.dataset.value ); } );
		} );
	}

	function extract( file, panel ) {
		if ( ! window.pdfjsLib ) {
			panel.innerHTML = '<p class="mv-extract-warn">' + t( 'kein_pdfjs', 'pdf.js nicht geladen.' ) + '</p>';
			panel.hidden = false;
			return;
		}
		pdfjsLib.GlobalWorkerOptions.workerSrc = window.mvPdfWorker;
		panel.innerHTML = '<p>' + t( 'lese', 'Lese …' ) + '</p>';
		panel.hidden = false;
		var reader = new FileReader();
		reader.onload = function () {
			pdfjsLib.getDocument( { data: new Uint8Array( reader.result ) } ).promise.then( function ( pdf ) {
				var chain = Promise.resolve( '' ), n = Math.min( pdf.numPages, 60 );
				for ( var i = 1; i <= n; i++ ) {
					( function ( p ) {
						chain = chain.then( function ( acc ) {
							return pdf.getPage( p ).then( function ( page ) { return page.getTextContent(); } ).then( function ( tc ) {
								return acc + ' ' + tc.items.map( function ( it ) { return it.str; } ).join( ' ' );
							} );
						} );
					} )( i );
				}
				return chain.then( function ( text ) { render( panel, analyse( text ), pdf.numPages, text.replace( /\s/g, '' ).length > 200 ); } );
			} ).catch( function ( e ) {
				panel.innerHTML = '<p class="mv-extract-warn">' + t( 'fehler', 'Fehler:' ) + ' ' + ( e && e.message ? e.message : e ) + '</p>';
			} );
		};
		reader.readAsArrayBuffer( file );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var input = document.querySelector( 'input[type=file][data-mv-extract=kaufvertrag]' );
		var panel = document.getElementById( 'mv-extract-panel' );
		if ( ! input || ! panel ) { return; }
		input.addEventListener( 'change', function () {
			var f = input.files && input.files[0];
			if ( ! f ) { return; }
			if ( ! /pdf$/i.test( f.name ) && f.type !== 'application/pdf' ) {
				panel.innerHTML = '<p class="mv-extract-warn">' + t( 'nur_pdf', 'Nur PDF.' ) + '</p>';
				panel.hidden = false;
				return;
			}
			extract( f, panel );
		} );
	} );

	// für Tests exportieren
	window.mvAnalyseKaufvertrag = analyse;
})();
