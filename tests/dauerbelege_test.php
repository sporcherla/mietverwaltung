<?php
// Dauerbelege: Fälligkeiten je Intervall (inkl. Ende), Buchen (Beleg mit Quartalszeitraum), Doppelbuchung, offene(), Bankimport.
require __DIR__ . '/stub.php';
require_once dirname( __DIR__ ) . '/includes/class-mv-dauerbelege.php';
global $wpdb;

// Tabelle des Moduls anlegen (steht noch nicht in class-mv-db.php) – gleiche Übersetzung wie stub.php.
foreach ( Mietv_Dauerbelege::tabellen( '' ) as $sql ) {
	preg_match( '/CREATE TABLE (\w+) \((.*)\)\s*;?$/s', trim( $sql ), $m );
	$body = $m[2];
	$body = preg_replace( '/bigint\(20\) unsigned NOT NULL AUTO_INCREMENT/', 'INTEGER PRIMARY KEY AUTOINCREMENT', $body );
	$body = preg_replace( '/,\s*PRIMARY KEY\s+\(id\)/', '', $body );
	$body = preg_replace( '/,\s*(UNIQUE )?KEY \w+ \([\w,]+\)/', '', $body );
	$body = preg_replace( '/\b(bigint|smallint|tinyint|int)\(\d+\)( unsigned)?/', 'INTEGER', $body );
	$body = preg_replace( '/decimal\(\d+,\d+\)/', 'REAL', $body );
	$body = preg_replace( '/varchar\(\d+\)/', 'TEXT', $body );
	$wpdb->query( "CREATE TABLE {$m[1]} ($body)" );
}
// Spalte dauerbeleg_id in mv_belege (wird im Plugin vom Hauptentwickler ergänzt).
// Spalte dauerbeleg_id ist seit DB-Version 15 Teil des Schemas.

$wpdb->insert( mietv_table( 'objekte' ), [ 'bezeichnung' => 'MFH Test', 'strasse' => 'x', 'ort' => 'y' ] );
$oid = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'kostenarten' ), [ 'name' => 'Grundsteuer', 'anlage_v' => 'grundsteuer', 'umlagefaehig' => 1, 'sortierung' => 1 ] );
$kid = (int) $wpdb->insert_id;

$neu = function ( array $x ) use ( $wpdb, $oid, $kid ): object {
	$wpdb->insert(
		mietv_table( 'dauerbelege' ),
		array_merge(
			[
				'owner_id'          => 1,
				'objekt_id'         => $oid,
				'kostenart_id'      => $kid,
				'bezeichnung'       => 'Test',
				'lieferant'         => '',
				'iban'              => '',
				'betrag'            => 100,
				'intervall'         => 'jaehrlich',
				'erste_faelligkeit' => '2025-01-01',
				'ende'              => null,
				'aktiv'             => 1,
			],
			$x
		)
	);
	return Mietv_Dauerbelege::get( (int) $wpdb->insert_id );
};

// --- Fälligkeiten je Intervall ---
$gs = $neu( [ 'bezeichnung' => 'Grundsteuer', 'lieferant' => 'Stadt Fürth', 'iban' => 'DE12 3456', 'betrag' => 150, 'intervall' => 'vierteljaehrlich', 'erste_faelligkeit' => '2025-02-15' ] );
$f  = Mietv_Dauerbelege::faelligkeiten( $gs, '2025-12-31' );
assert( $f === [ '2025-02-15', '2025-05-15', '2025-08-15', '2025-11-15' ], 'vierteljährlich 15.2./15.5./15.8./15.11.' );
assert( Mietv_Dauerbelege::faelligkeiten( $gs, '2025-02-14' ) === [], 'vor erster Fälligkeit leer' );
assert( count( Mietv_Dauerbelege::faelligkeiten( $gs, '2026-12-31' ) ) === 8, 'zwei Jahre = 8 Termine' );

$mo = $neu( [ 'bezeichnung' => 'Konto', 'lieferant' => 'Sparkasse', 'betrag' => 5, 'intervall' => 'monatlich', 'erste_faelligkeit' => '2025-01-31', 'ende' => '2025-06-30' ] );
$f  = Mietv_Dauerbelege::faelligkeiten( $mo, '2025-12-31' );
assert( $f === [ '2025-01-31', '2025-02-28', '2025-03-31', '2025-04-30', '2025-05-31', '2025-06-30' ], 'monatlich mit Monatsletztem, Ende 30.6. – ' . implode( ',', $f ) );

$hj = $neu( [ 'bezeichnung' => 'Schornsteinfeger', 'lieferant' => 'Kaminkehrer Huber', 'betrag' => 60, 'intervall' => 'halbjaehrlich', 'erste_faelligkeit' => '2024-04-01' ] );
$f  = Mietv_Dauerbelege::faelligkeiten( $hj, '2025-12-31' );
assert( $f === [ '2024-04-01', '2024-10-01', '2025-04-01', '2025-10-01' ], 'halbjährlich' );

$ja = $neu( [ 'bezeichnung' => 'Gebäudeversicherung', 'lieferant' => 'Allianz', 'betrag' => 800, 'intervall' => 'jaehrlich', 'erste_faelligkeit' => '2023-07-01', 'ende' => '2026-06-30' ] );
$f  = Mietv_Dauerbelege::faelligkeiten( $ja, '2030-12-31' );
assert( $f === [ '2023-07-01', '2024-07-01', '2025-07-01' ], 'jährlich, Ende begrenzt' );

// --- Buchen: Beleg mit Quartalszeitraum ---
$b1 = Mietv_Dauerbelege::buchen( (int) $gs->id, '2025-05-15', '2025-05-20' );
assert( $b1 > 0, 'gebucht' );
$bel = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mietv_table( 'belege' ) . ' WHERE id = %d', $b1 ) );
assert( (int) $bel->objekt_id === $oid && (int) $bel->kostenart_id === $kid, 'Objekt/Kostenart' );
assert( $bel->datum === '2025-05-20' && (int) $bel->steuerjahr === 2025, 'Zahlungsdatum/Steuerjahr' );
assert( $bel->zeitraum_von === '2025-05-15' && $bel->zeitraum_bis === '2025-08-14', 'Zeitraum Quartal 15.5.–14.8.' );
assert( abs( (float) $bel->betrag - 150.0 ) < 0.005, 'Betrag' );
assert( $bel->lieferant === 'Stadt Fürth' && $bel->belegnr === 'DB-' . $gs->id . '-2025-05-15', 'Lieferant/Belegnr' );
assert( strpos( $bel->beschreibung, 'Grundsteuer' ) === 0 && (int) $bel->dauerbeleg_id === (int) $gs->id && (int) $bel->verteilung_jahre === 1, 'Beschreibung/dauerbeleg_id' );
// Jahreswechsel im Steuerjahr: Fälligkeit 15.11.2025, gezahlt 2.1.2026
$b2  = Mietv_Dauerbelege::buchen( (int) $gs->id, '2025-11-15', '2026-01-02' );
$bel = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mietv_table( 'belege' ) . ' WHERE id = %d', $b2 ) );
assert( (int) $bel->steuerjahr === 2026 && $bel->zeitraum_von === '2025-11-15' && $bel->zeitraum_bis === '2026-02-14', 'Abfluss 2026, Leistungszeitraum ab 15.11.' );

// Doppelbuchung verhindert, fremde Termine abgelehnt
assert( Mietv_Dauerbelege::buchen( (int) $gs->id, '2025-05-15', '2025-05-21' ) === 0, 'Doppelbuchung verhindert' );
assert( Mietv_Dauerbelege::buchen( (int) $gs->id, '2025-05-16', '2025-05-21' ) === 0, 'Datum außerhalb des Plans abgelehnt' );
assert( Mietv_Dauerbelege::buchen( (int) $ja->id, '2026-07-01', '2026-07-01' ) === 0, 'nach Ende abgelehnt' );
$g = Mietv_Dauerbelege::gebucht( (int) $gs->id );
assert( array_keys( $g ) === [ '2025-05-15', '2025-11-15' ], 'gebucht() nach Fälligkeit' );

// --- offene() ---
$off = Mietv_Dauerbelege::offene();
assert( isset( $off[ (int) $gs->id ] ) && in_array( '2025-02-15', $off[ (int) $gs->id ], true ) && in_array( '2025-08-15', $off[ (int) $gs->id ], true ), 'offene Grundsteuer-Termine' );
assert( ! in_array( '2025-05-15', $off[ (int) $gs->id ], true ), 'gebuchter Termin nicht offen' );
assert( isset( $off[ (int) $ja->id ] ) && $off[ (int) $ja->id ] === [ '2023-07-01', '2024-07-01', '2025-07-01' ], 'Versicherung offen bis Ende' );
$wpdb->update( mietv_table( 'dauerbelege' ), [ 'aktiv' => 0 ], [ 'id' => (int) $hj->id ] );
assert( ! isset( Mietv_Dauerbelege::offene()[ (int) $hj->id ] ), 'inaktiver Dauerbeleg ohne Hinweise' );

// --- Löschen einer Buchung ---
Mietv_Dauerbelege::loeschen_buchung( $b2 );
assert( ! isset( Mietv_Dauerbelege::gebucht( (int) $gs->id )['2025-11-15'] ), 'Buchung gelöscht' );
$wpdb->insert( mietv_table( 'belege' ), [ 'objekt_id' => $oid, 'kostenart_id' => $kid, 'datum' => '2025-01-01', 'steuerjahr' => 2025, 'betrag' => 1 ] );
$fremd = (int) $wpdb->insert_id;
Mietv_Dauerbelege::loeschen_buchung( $fremd );
assert( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . mietv_table( 'belege' ) . ' WHERE id = %d', $fremd ) ) === 1, 'fremder Beleg bleibt' );

// --- Bankimport ---
$row = [ 'iban' => 'DE123456', 'betrag' => -150.0, 'name' => 'Stadtkasse', 'zweck' => 'Grundsteuer B 2025' ];
$e   = Mietv_Dauerbelege::erkennen( $row, mb_strtolower( $row['name'] . ' ' . $row['zweck'] ) );
assert( $e && (int) $e->id === (int) $gs->id, 'Erkennung über IBAN' );
$e = Mietv_Dauerbelege::erkennen( [ 'iban' => '', 'betrag' => -5 ], 'sparkasse kontoführung' );
assert( $e && (int) $e->id === (int) $mo->id, 'Erkennung über Lieferantenname' );
assert( Mietv_Dauerbelege::erkennen( [ 'iban' => 'DE123456', 'betrag' => 150 ], 'stadt fürth' ) === null, 'Eingang wird nicht erkannt' );
assert( Mietv_Dauerbelege::erkennen( [ 'iban' => '', 'betrag' => -9 ], 'irgendwas' ) === null, 'kein Treffer' );

// buchen_aus_bank: 15.2. offen, 15.5. gebucht → Zahlung am 12.8. bucht 15.2. (älteste offene ≤ 22.8.)
$b3  = Mietv_Dauerbelege::buchen_aus_bank( $gs, '2025-08-12', -150.0 );
$bel = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mietv_table( 'belege' ) . ' WHERE id = %d', $b3 ) );
assert( $b3 > 0 && $bel->zeitraum_von === '2025-02-15' && $bel->datum === '2025-08-12' && (float) $bel->betrag === 150.0, 'Bank: älteste offene Fälligkeit' );
// jetzt: 15.8. offen → Zahlung am 12.8. (Fälligkeit liegt bis 10 Tage in der Zukunft) bucht 15.8. mit abweichendem Betrag
$b4  = Mietv_Dauerbelege::buchen_aus_bank( $gs, '2025-08-12', -155.5 );
$bel = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mietv_table( 'belege' ) . ' WHERE id = %d', $b4 ) );
assert( $b4 > 0 && $bel->zeitraum_von === '2025-08-15' && abs( (float) $bel->betrag - 155.5 ) < 0.005, 'Bank: Fälligkeit ≤ Datum + 10 Tage, Betrag aus Bank' );
// 15.11. liegt > 10 Tage nach dem 12.8. → nichts zu buchen
assert( Mietv_Dauerbelege::buchen_aus_bank( $gs, '2025-08-12', -150.0 ) === 0, 'Bank: keine Fälligkeit im Fenster' );

echo "Dauerbelege OK\n";
