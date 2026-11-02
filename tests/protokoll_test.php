<?php
// Änderungsprotokoll: diff() (nur Änderungen, IBAN maskiert), log() schreibt Zeile, aufbewahrung() löscht alte Einträge.
require __DIR__ . '/stub.php';
// Fehlende WP-Funktionen für dieses Modul (stub.php hat sie nicht).
if ( ! function_exists( 'wp_privacy_anonymize_ip' ) ) {
	function wp_privacy_anonymize_ip( $ip ) {
		return preg_replace( '/\.\d+$/', '.0', (string) $ip );
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return (object) [ 'ID' => 1, 'user_login' => 'tester' ];
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event() {}
	function wp_clear_scheduled_hook() {}
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect() {}
}
require_once dirname( __DIR__ ) . '/includes/class-mv-protokoll.php';

function protokoll_check( bool $ok, string $msg ): void {
	if ( ! $ok ) {
		fwrite( STDERR, "FEHLER: $msg\n" );
		exit( 1 );
	}
}

global $wpdb;
// Tabelle aus der Klasse anlegen (SQLite-Übersetzung wie in stub.php).
foreach ( Mietv_Protokoll::tabellen( '' ) as $sql ) {
	$sql = preg_replace( '/bigint\(20\) unsigned NOT NULL AUTO_INCREMENT/', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql );
	$sql = preg_replace( '/,\s*PRIMARY KEY\s+\(id\)/', '', $sql );
	$sql = preg_replace( '/,\s*(UNIQUE )?KEY \w+ \([\w, ]+\)/', '', $sql );
	$sql = preg_replace( '/\b(bigint|smallint|tinyint|int)\(\d+\)( unsigned)?/', 'INTEGER', $sql );
	$sql = preg_replace( '/varchar\(\d+\)/', 'TEXT', $sql );
	$sql = str_replace( [ 'datetime', 'longtext' ], [ 'TEXT', 'TEXT' ], $sql );
	$wpdb->query( trim( $sql, " ;\n" ) );
}
$_SERVER['REMOTE_ADDR'] = '192.168.2.104';

// diff(): nur geänderte Felder, Zahlen normalisiert, IBAN maskiert, Kennwort nie im Klartext.
$alt = [ 'id' => 5, 'vorname' => 'Anna', 'nachname' => 'Beispiel', 'iban' => 'DE02120300000000202051', 'kaltmiete' => '500.00', 'notiz' => null, 'passwort' => 'geheim' ];
$neu = [ 'vorname' => 'Anna', 'nachname' => 'Muster', 'iban' => 'DE02500105170137075030', 'kaltmiete' => 500.0, 'notiz' => '', 'passwort' => 'neu' ];
$d   = Mietv_Protokoll::diff( $alt, $neu );
protokoll_check( array_keys( $d ) === [ 'nachname', 'iban', 'passwort' ], 'diff liefert nur geänderte Felder: ' . implode( ',', array_keys( $d ) ) );
protokoll_check( $d['nachname'] === [ 'Beispiel', 'Muster' ], 'alt/neu Nachname' );
protokoll_check( $d['iban'][0] === '******************2051' && $d['iban'][1] === '******************5030', 'IBAN maskiert: ' . $d['iban'][0] . ' / ' . $d['iban'][1] );
protokoll_check( strpos( wp_json_encode( $d ), 'DE02' ) === false, 'keine IBAN im Klartext' );
protokoll_check( $d['passwort'] === [ '***', '***' ], 'Kennwort maskiert' );
protokoll_check( Mietv_Protokoll::diff( $alt, [ 'kaltmiete' => '500' ] ) === [], '500.00 == 500 ist keine Änderung' );

// log(): Zeile mit Benutzer, anonymisierter IP und JSON.
Mietv_Protokoll::log( 'aendern', 'mieter', 5, 'Tenant – Muster, Anna', $d );
$row = $wpdb->get_row( 'SELECT * FROM ' . mietv_table( 'protokoll' ) . ' ORDER BY id DESC LIMIT 1' );
protokoll_check( $row && $row->aktion === 'aendern' && $row->tabelle === 'mieter' && (int) $row->datensatz_id === 5, 'log schreibt Zeile' );
protokoll_check( $row->user_login === 'tester' && (int) $row->user_id === 1, 'Benutzer' );
protokoll_check( $row->ip === '192.168.2.0', 'IP anonymisiert: ' . $row->ip );
$j = json_decode( $row->aenderungen, true );
protokoll_check( $j['nachname'] === [ 'Beispiel', 'Muster' ] && $j['iban'][1] === '******************5030', 'JSON-Änderungen' );
protokoll_check( strlen( $row->zeit ) === 19, 'Zeitstempel' );

// Hook-Weg (do_action-Signatur) und Maskierung auch bei direktem log().
Mietv_Protokoll::log( 'anlegen', 'hausgeld', 7, 'Plan 01/2026', [ 'verwalter' => 'HV GmbH', 'iban' => 'DE89370400440532013000', 'betrag' => 300 ] );
$row = $wpdb->get_row( 'SELECT * FROM ' . mietv_table( 'protokoll' ) . ' ORDER BY id DESC LIMIT 1' );
$j   = json_decode( $row->aenderungen, true );
protokoll_check( $j['iban'] === '******************3000' && $j['verwalter'] === 'HV GmbH' && $j['betrag'] === '300', 'Maskierung bei anlegen: ' . $row->aenderungen );
protokoll_check( Mietv_Protokoll::anzahl( [ 'von' => '', 'bis' => '', 'aktion' => 'anlegen', 'tabelle' => '', 'user' => '', 'q' => '', 'paged' => 1 ] ) === 1, 'Filter nach Aktion' );

// Anbindung generisches Speichern: anlegen / aendern / loeschen.
$wpdb->insert( mietv_table( 'mieter' ), [ 'vorname' => 'Anna', 'nachname' => 'Beispiel', 'iban' => 'DE02120300000000202051' ] );
$mid = (int) $wpdb->insert_id;
Mietv_Protokoll::nach_speichern( 'mieter', $mid, [], [ 'vorname' => 'Anna', 'nachname' => 'Beispiel', 'iban' => 'DE02120300000000202051' ], 'Tenant' );
$row = $wpdb->get_row( 'SELECT * FROM ' . mietv_table( 'protokoll' ) . ' ORDER BY id DESC LIMIT 1' );
protokoll_check( $row->aktion === 'anlegen' && $row->bezeichnung === 'Tenant – Beispiel, Anna', 'anlegen mit Bezeichnung: ' . $row->bezeichnung );
$vor = Mietv_Protokoll::vor_speichern( 'mieter', $mid );
protokoll_check( $vor['nachname'] === 'Beispiel', 'vor_speichern lädt alten Datensatz' );
Mietv_Protokoll::nach_speichern( 'mieter', $mid, $vor, [ 'vorname' => 'Anna', 'nachname' => 'Beispiel' ], 'Tenant' );
protokoll_check( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mietv_table( 'protokoll' ) ) === 3, 'ohne Änderung kein Eintrag' );
Mietv_Protokoll::nach_loeschen( 'mieter', $mid, $vor, 'Tenant' );
$row = $wpdb->get_row( 'SELECT * FROM ' . mietv_table( 'protokoll' ) . ' ORDER BY id DESC LIMIT 1' );
protokoll_check( $row->aktion === 'loeschen' && strpos( $row->aenderungen, 'DE02' ) === false && strpos( $row->aenderungen, '2051' ) !== false, 'loeschen: alte Werte maskiert' );
protokoll_check( Mietv_Protokoll::bezeichnung( 'belege', [ 'datum' => '2026-03-12', 'lieferant' => 'Stadt', 'beschreibung' => 'Grundsteuer' ], 'Receipt' ) === 'Receipt – 12.03.2026 Stadt Grundsteuer', 'Bezeichnung Beleg' );

// aufbewahrung(): alte Einträge löschen, neue bleiben.
$wpdb->insert( mietv_table( 'protokoll' ), [ 'zeit' => '2020-01-01 10:00:00', 'user_id' => 1, 'user_login' => 'alt', 'aktion' => 'anlegen', 'tabelle' => 'belege', 'datensatz_id' => 1, 'bezeichnung' => 'alt', 'aenderungen' => '', 'ip' => '' ] );
$wpdb->insert( mietv_table( 'protokoll' ), [ 'zeit' => date( 'Y-m-d H:i:s', time() - 400 * 86400 ), 'user_id' => 1, 'user_login' => 'alt', 'aktion' => 'anlegen', 'tabelle' => 'belege', 'datensatz_id' => 2, 'bezeichnung' => 'alt2', 'aenderungen' => '', 'ip' => '' ] );
$vorher = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mietv_table( 'protokoll' ) );
protokoll_check( Mietv_Protokoll::tage() === 365, 'Standardfrist 365' );
$n = Mietv_Protokoll::aufbewahrung();
protokoll_check( $n === 2, 'zwei alte Einträge gelöscht: ' . $n );
protokoll_check( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mietv_table( 'protokoll' ) ) === $vorher - 2, 'neue Einträge bleiben' );
update_option( Mietv_Protokoll::OPTION, 30 );
$wpdb->insert( mietv_table( 'protokoll' ), [ 'zeit' => date( 'Y-m-d H:i:s', time() - 40 * 86400 ), 'user_id' => 1, 'user_login' => 'x', 'aktion' => 'export', 'tabelle' => '', 'datensatz_id' => 0, 'bezeichnung' => '40 Tage', 'aenderungen' => '', 'ip' => '' ] );
protokoll_check( Mietv_Protokoll::aufbewahrung() === 1, 'konfigurierte Frist 30 Tage greift' );

echo "Protokoll OK\n";
