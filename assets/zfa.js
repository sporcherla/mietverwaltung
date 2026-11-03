/* Zwei-Faktor-Einrichtung: QR-Code für die Authenticator-App zeichnen. */
( function () {
	'use strict';
	var el = document.getElementById( 'mv-zfa-qr' );
	if ( ! el || typeof qrcode !== 'function' ) { return; }
	try {
		var qr = qrcode( 0, 'M' );
		qr.addData( el.getAttribute( 'data-otpauth' ) );
		qr.make();
		el.innerHTML = qr.createSvgTag( { cellSize: 4, margin: 0, scalable: true } );
	} catch ( e ) {
		el.textContent = '';
	}
}() );
