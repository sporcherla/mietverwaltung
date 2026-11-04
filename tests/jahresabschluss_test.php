<?php
// Jahresabschluss: Statusermittlung der Schritte (Dauerbelege, Hausgeld, Rückstände, Zähler, Abrechnung, Zinsen) für ein Jahr.
require __DIR__ . '/stub.php';
require_once dirname( __DIR__ ) . '/includes/class-mv-hausgeld.php';
require_once dirname( __DIR__ ) . '/includes/class-mv-dauerbelege.php';
require_once dirname( __DIR__ ) . '/includes/class-mv-darlehen.php';
require_once dirname( __DIR__ ) . '/includes/class-mv-jahresabschluss.php';
if ( ! class_exists( 'Mietv_Admin' ) ) {
	class Mietv_Admin {
		const CAP = 'mietv_verwalten';
	}
}
global $wpdb;
foreach ( Mietv_Dauerbelege::tabellen( '' ) as $sql ) {
	$body = preg_replace( '/^CREATE TABLE \S+ \(/', '', trim( $sql ) );
	$body = preg_replace( '/\)\s*;?$/', '', $body );
	$body = preg_replace( '/bigint\(20\) unsigned NOT NULL AUTO_INCREMENT/', 'INTEGER PRIMARY KEY AUTOINCREMENT', $body );
	$body = preg_replace( '/,\s*PRIMARY KEY\s+\(id\)/', '', $body );
	$body = preg_replace( '/,\s*(UNIQUE )?KEY \w+ \([\w,]+\)/', '', $body );
	$body = preg_replace( '/\b(bigint|smallint|tinyint|int)\(\d+\)( unsigned)?/', 'INTEGER', $body );
	$body = preg_replace( '/decimal\(\d+,\d+\)/', 'REAL', $body );
	$body = preg_replace( '/varchar\(\d+\)/', 'TEXT', $body );
	$wpdb->query( 'CREATE TABLE wp_mv_dauerbelege (' . $body . ')' );
}

$jahr = (int) date( 'Y' ) - 1;
$wpdb->insert( mietv_table( 'objekte' ), [ 'bezeichnung' => 'Haus A', 'strasse' => 'x', 'ort' => 'y', 'heizung' => 'zentral', 'heizung_ablesung' => 'eigen' ] );
$oid = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'einheiten' ), [ 'objekt_id' => $oid, 'bezeichnung' => 'EG', 'flaeche' => 60, 'typ' => 'wohnung' ] );
$eid = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'mieter' ), [ 'vorname' => 'Max', 'nachname' => 'Muster' ] );
$mid = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'mietverhaeltnisse' ), [ 'einheit_id' => $eid, 'mieter_id' => $mid, 'beginn' => $jahr . '-01-01', 'personen' => 1, 'zahltag' => 3 ] );
$mvid = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'konditionen' ), [ 'mv_id' => $mvid, 'gueltig_ab' => $jahr . '-01-01', 'kaltmiete' => 500, 'bk_vz' => 100, 'hk_vz' => 50 ] );
for ( $m = 1; $m <= 11; $m++ ) {
	$monat = sprintf( '%04d-%02d-01', $jahr, $m );
	$wpdb->insert( mietv_table( 'zahlungen' ), [ 'mv_id' => $mvid, 'datum' => substr( $monat, 0, 8 ) . '03', 'betrag' => 650, 'monat' => $monat ] );
}
$wpdb->insert( mietv_table( 'kostenarten' ), [ 'name' => 'Grundsteuer', 'anlage_v' => 'grundsteuer', 'umlagefaehig' => 1, 'sortierung' => 1 ] );
$kid = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'dauerbelege' ), [ 'objekt_id' => $oid, 'kostenart_id' => $kid, 'bezeichnung' => 'Grundsteuer', 'lieferant' => 'Stadt', 'betrag' => 100, 'intervall' => 'vierteljaehrlich', 'erste_faelligkeit' => $jahr . '-02-15', 'aktiv' => 1 ] );
$did = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'zaehler' ), [ 'objekt_id' => $oid, 'einheit_id' => $eid, 'typ' => 'kaltwasser', 'nummer' => 'KW1', 'ablesung' => 'mieter' ] );
$zid = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'zaehler' ), [ 'objekt_id' => $oid, 'einheit_id' => $eid, 'typ' => 'waerme', 'nummer' => 'HK1', 'ablesung' => 'ablesedienst' ] );
$wpdb->insert( mietv_table( 'darlehen' ), [ 'objekt_id' => $oid, 'bank' => 'Bank', 'bezeichnung' => 'D1', 'betrag' => 100000, 'auszahlung' => ( $jahr - 1 ) . '-06-01', 'zinssatz' => 3, 'rate' => 500, 'tilgung' => 2, 'status' => 'aktiv' ] );
$dlid = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'darlehen_raten' ), [ 'darlehen_id' => $dlid, 'datum' => $jahr . '-03-01', 'rate' => 500, 'zins' => 250, 'tilgung' => 250, 'art' => 'rate' ] );

// Ausgangslage: alles offen
$db = Mietv_Jahresabschluss::dauerbelege_offen( $jahr );
assert( isset( $db[ $did ] ) && count( $db[ $did ] ) === 4, 'vier offene Grundsteuer-Termine: ' . wp_json_encode( $db ) );
assert( Mietv_Jahresabschluss::dauerbelege_offen( $jahr - 1 ) === [], 'Vorjahr ohne Termine' );
$rs = Mietv_Jahresabschluss::rueckstaende( $jahr );
assert( isset( $rs[ $mvid ] ) && abs( $rs[ $mvid ]['saldo'] + 650.0 ) < 0.01, 'Rückstand Dezember 650: ' . wp_json_encode( $rs ) );
$zf = Mietv_Jahresabschluss::zaehler_fehlend( $jahr );
assert( $zf['anzahl'] === 1 && count( $zf['fehlt'] ) === 1 && strpos( $zf['fehlt'][0], 'KW1' ) !== false, 'Kaltwasserzähler ohne Jahresendstand, Ablesedienst-Zähler ignoriert: ' . wp_json_encode( $zf ) );
$ab = Mietv_Jahresabschluss::abrechnungen( $jahr );
assert( isset( $ab[ $oid ] ) && $ab[ $oid ]['abrechnung'] === null, 'Objekt ohne Abrechnung' );
$zb = Mietv_Jahresabschluss::zinsbescheinigungen( $jahr );
assert( isset( $zb[ $dlid ] ) && $zb[ $dlid ]['bescheinigung'] === null && abs( $zb[ $dlid ]['berechnet']['zinsen'] - 250.0 ) < 0.01, 'berechnete Zinsen 250: ' . wp_json_encode( $zb ) );
$hg = Mietv_Jahresabschluss::hausgeld_offen( $jahr );
assert( $hg['objekte'] === [], 'kein Hausgeld-Plan' );

$s = Mietv_Jahresabschluss::schritte( $jahr );
$by = array_column( $s, null, 'key' );
assert( count( $s ) === 6, 'sechs Schritte ohne Steuer/Sicherung: ' . count( $s ) );
assert( $by['dauerbelege']['status'] === 'offen' && ! empty( $by['dauerbelege']['aktion'] ), 'Dauerbelege offen mit Aktion' );
assert( $by['hausgeld']['status'] === 'na', 'Hausgeld nicht zutreffend' );
assert( $by['rueckstaende']['status'] === 'offen', 'Rückstände offen' );
assert( $by['zaehler']['status'] === 'offen', 'Zähler offen' );
assert( $by['abrechnung']['status'] === 'offen' && count( $by['abrechnung']['aktionen'] ) === 1, 'Abrechnung offen mit Erstellen-Aktion' );
assert( $by['zinsen']['status'] === 'offen' && count( $by['zinsen']['aktionen'] ) === 1, 'Zinsen offen mit Übernahme-Aktion' );
[ $fertig, $gesamt, $offen ] = Mietv_Jahresabschluss::fortschritt( $s );
assert( $fertig === 1 && $gesamt === 6 && $offen === 5, "Fortschritt 1/6, 5 offen: $fertig/$gesamt/$offen" );

// Alles erledigen
foreach ( $db[ $did ] as $t ) {
	Mietv_Dauerbelege::buchen( $did, $t, $t );
}
$wpdb->insert( mietv_table( 'zahlungen' ), [ 'mv_id' => $mvid, 'datum' => ( $jahr + 1 ) . '-01-03', 'betrag' => 650, 'monat' => $jahr . '-12-01' ] );
$wpdb->insert( mietv_table( 'zaehlerstaende' ), [ 'zaehler_id' => $zid, 'datum' => $jahr . '-12-31', 'stand' => 123 ] );
$wpdb->insert( mietv_table( 'abrechnungen' ), [ 'objekt_id' => $oid, 'von' => $jahr . '-01-01', 'bis' => $jahr . '-12-31', 'status' => 'fertig' ] );
$wpdb->insert( mietv_table( 'darlehen_jahre' ), [ 'darlehen_id' => $dlid, 'jahr' => $jahr, 'zinsen' => 251, 'tilgung' => 249, 'restschuld' => 99000 ] );

$s = Mietv_Jahresabschluss::schritte( $jahr );
$by = array_column( $s, null, 'key' );
foreach ( [ 'dauerbelege', 'rueckstaende', 'zaehler', 'abrechnung', 'zinsen' ] as $k ) {
	assert( $by[ $k ]['status'] === 'erledigt', "$k erledigt: " . $by[ $k ]['status'] . ' ' . wp_json_encode( $by[ $k ]['details'] ) );
}
[ $fertig, $gesamt, $offen ] = Mietv_Jahresabschluss::fortschritt( $s );
assert( $fertig === 6 && $offen === 0, "alles erledigt: $fertig/$gesamt/$offen" );
echo "Jahresabschluss OK\n";
