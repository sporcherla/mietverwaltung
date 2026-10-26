/* Druckansicht: nach dem Laden automatisch den Druckdialog öffnen. */
window.addEventListener( 'load', function () {
	setTimeout( function () { window.print(); }, 300 );
} );
