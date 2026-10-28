/* Druckansicht (Brief, Vertrag): Schaltflächen der Werkzeugleiste. */
document.addEventListener( 'click', function ( e ) {
	var b = e.target.closest( '[data-action]' );
	if ( ! b ) { return; }
	if ( b.getAttribute( 'data-action' ) === 'print' ) { window.print(); }
	if ( b.getAttribute( 'data-action' ) === 'close' ) { window.close(); }
} );
