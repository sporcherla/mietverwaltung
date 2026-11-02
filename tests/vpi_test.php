<?php
// VPI: setzen/wert/letzter, Anpassungsrechnung, ffcsv-Parser, Sperrfrist, Indexmieten-Übersicht.
require __DIR__ . '/stub.php';
require_once dirname( __DIR__ ) . '/includes/class-mv-vpi.php';
global $wpdb;

// Eigene Tabelle wie der Stub für SQLite umschreiben.
foreach ( Mietv_Vpi::tabellen( '' ) as $sql ) {
	$sql = preg_replace( '/decimal\(\d+,\d+\)/', 'REAL', $sql );
	$sql = preg_replace( '/varchar\(\d+\)/', 'TEXT', $sql );
	$sql = str_replace( [ 'datetime', 'PRIMARY KEY  (monat)' ], [ 'TEXT', 'PRIMARY KEY (monat)' ], $sql );
	$wpdb->query( $sql );
}

// setzen / wert / letzter / reihe
assert( Mietv_Vpi::wert( '2025-01' ) === null, 'leer' );
assert( Mietv_Vpi::setzen( '2025-01', 115.3, 'manuell' ), 'setzen' );
assert( Mietv_Vpi::setzen( '2025-02-01', 115.9 ), 'setzen 2' );
assert( Mietv_Vpi::setzen( '2026-01', 120.2, 'destatis' ), 'setzen 3' );
assert( ! Mietv_Vpi::setzen( '2025-13', 1 ) && ! Mietv_Vpi::setzen( '2025-01', 0 ), 'ungültig abgelehnt' );
assert( Mietv_Vpi::wert( '2025-01-15' ) === 115.3, 'wert normalisiert' );
assert( Mietv_Vpi::setzen( '2025-01', 115.4, 'destatis' ) && Mietv_Vpi::wert( '2025-01' ) === 115.4, 'ändern statt duplizieren' );
assert( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_mv_vpi' ) === 3, 'drei Zeilen' );
$l = Mietv_Vpi::letzter();
assert( $l['monat'] === '2026-01-01' && $l['wert'] === 120.2, 'letzter' );
$r = Mietv_Vpi::reihe( 2026 );
assert( count( $r ) === 1 && $r['2026-01-01']->quelle === 'destatis', 'reihe' );
Mietv_Vpi::loeschen( '2025-02' );
assert( Mietv_Vpi::wert( '2025-02' ) === null, 'löschen' );

// Anpassung: 700 € bei 115,3 → 120,2 = 729,75 €, +4,25 %
$a = Mietv_Vpi::anpassung( 700, 115.3, 120.2 );
assert( $a['neu'] === 729.75 && $a['prozent'] === 4.25, 'Anpassung ' . wp_json_encode( $a ) );
$a0 = Mietv_Vpi::anpassung( 700, 0, 120.2 );
assert( $a0['neu'] === 700.0 && $a0['prozent'] === 0.0, 'ungültiger Index → unverändert' );

// Sperrfrist (12 Monate nach letzter Festlegung)
assert( Mietv_Vpi::sperrfrist_ende( '2025-03-01' ) === '2026-03-01', 'Sperrfristende' );
assert( ! Mietv_Vpi::anpassung_moeglich( '2025-03-01', '2026-02-28' ), 'vor Ablauf gesperrt' );
assert( Mietv_Vpi::anpassung_moeglich( '2025-03-01', '2026-03-01' ), 'am Tag des Ablaufs frei' );
assert( Mietv_Vpi::anpassung_moeglich( '2025-03-01', '2026-09-15' ), 'nach Ablauf frei' );

// Begründung einer Index-Erhöhung
assert( Mietv_Vpi::vpi_aus_begruendung( "Indexmiete, VPI 115,3 → 120,2\nCPI ..." ) === [ 115.3, 120.2 ], 'VPI aus Begründung' );
assert( Mietv_Vpi::vpi_aus_begruendung( 'Mietspiegel' ) === null, 'keine VPI-Angabe' );

// Parser: GENESIS-ffcsv Langformat (seit 2024: value / value_variable_code)
$csv_lang = "statistics_code;statistics_label;time_code;time_label;time;1_variable_code;1_variable_label;1_variable_attribute_code;1_variable_attribute_label;2_variable_code;2_variable_label;2_variable_attribute_code;2_variable_attribute_label;value;value_unit;value_variable_code;value_variable_label\n"
	. "61111;Verbraucherpreisindex für Deutschland;JAHR;Jahr;2025;DINSG;Deutschland insgesamt;DG;Deutschland;MONAT;Monate;MONAT01;Januar;120,3;2020=100;PREIS1;Verbraucherpreisindex\n"
	. "61111;Verbraucherpreisindex für Deutschland;JAHR;Jahr;2025;DINSG;Deutschland insgesamt;DG;Deutschland;MONAT;Monate;MONAT01;Januar;2,3;Prozent;CH0004;Veränderung zum Vorjahresmonat\n"
	. "61111;Verbraucherpreisindex für Deutschland;JAHR;Jahr;2025;DINSG;Deutschland insgesamt;DG;Deutschland;MONAT;Monate;MONAT02;Februar;120,8;2020=100;PREIS1;Verbraucherpreisindex\n"
	. "61111;Verbraucherpreisindex für Deutschland;JAHR;Jahr;2025;DINSG;Deutschland insgesamt;DG;Deutschland;MONAT;Monate;MONAT03;März;121,2;2020=100;PREIS1;Verbraucherpreisindex\n"
	. "61111;Verbraucherpreisindex für Deutschland;JAHR;Jahr;2025;DINSG;Deutschland insgesamt;DG;Deutschland;MONAT;Monate;MONAT04;April;...;2020=100;PREIS1;Verbraucherpreisindex\n";
$p        = Mietv_Vpi::parse_csv( "\xEF\xBB\xBF" . $csv_lang );
assert( $p === [ '2025-01-01' => 120.3, '2025-02-01' => 120.8, '2025-03-01' => 121.2 ], 'Parser Langformat ' . wp_json_encode( $p ) );

// Parser: Breitformat (Spalte PREIS1__Verbraucherpreisindex__2020=100 + Veränderungsraten)
$csv_breit = "Statistik_Code;Statistik_Label;Zeit_Code;Zeit_Label;Zeit;1_Merkmal_Code;1_Merkmal_Label;1_Auspraegung_Code;1_Auspraegung_Label;2_Merkmal_Code;2_Merkmal_Label;2_Auspraegung_Code;2_Auspraegung_Label;PREIS1__Verbraucherpreisindex__2020=100;CH0004__Veraenderung_zum_Vorjahresmonat__Prozent;CH0005__Veraenderung_zum_Vormonat__Prozent\n"
	. "61111;Verbraucherpreisindex für Deutschland;JAHR;Jahr;2024;DINSG;Deutschland insgesamt;DG;Deutschland;MONAT;Monate;MONAT10;Oktober;119,3;2,0;0,4\n"
	. "61111;Verbraucherpreisindex für Deutschland;JAHR;Jahr;2024;DINSG;Deutschland insgesamt;DG;Deutschland;MONAT;Monate;MONAT11;November;119,1;2,2;-0,2\n"
	. "61111;Verbraucherpreisindex für Deutschland;JAHR;Jahr;2024;DINSG;Deutschland insgesamt;DG;Deutschland;MONAT;Monate;MONAT12;Dezember;119,8;2,6;0,5\n";
$p         = Mietv_Vpi::parse_csv( $csv_breit );
assert( $p === [ '2024-10-01' => 119.3, '2024-11-01' => 119.1, '2024-12-01' => 119.8 ], 'Parser Breitformat ' . wp_json_encode( $p ) );
assert( Mietv_Vpi::parse_csv( 'nur eine Zeile' ) === [] && Mietv_Vpi::parse_csv( "a;b\nx;y\n" ) === [], 'Parser leer' );
assert( Mietv_Vpi::importieren( $p, 'destatis' ) === 3 && Mietv_Vpi::wert( '2024-12' ) === 119.8, 'importieren' );

// Indexmieten-Übersicht: Mietverhältnis mit Vertragsoption mietart = index, Beginn vor > 12 Monaten
$wpdb->insert( mietv_table( 'objekte' ), [ 'bezeichnung' => 'Haus', 'strasse' => 'x', 'ort' => 'y' ] );
$oid = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'einheiten' ), [ 'objekt_id' => $oid, 'bezeichnung' => 'EG', 'flaeche' => 60 ] );
$eid = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'mieter' ), [ 'vorname' => 'Max', 'nachname' => 'Muster' ] );
$mid = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'mietverhaeltnisse' ), [ 'einheit_id' => $eid, 'mieter_id' => $mid, 'beginn' => '2025-01-01' ] );
$mv_id = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'konditionen' ), [ 'mv_id' => $mv_id, 'gueltig_ab' => '2025-01-01', 'kaltmiete' => 700, 'bk_vz' => 100, 'hk_vz' => 50 ] );
assert( Mietv_Vpi::indexmieten() === [], 'ohne Option keine Indexmiete' );
$wpdb->insert( mietv_table( 'vertraege' ), [ 'mv_id' => $mv_id, 'optionen' => wp_json_encode( [ 'mietart' => 'index' ] ) ] );
$wpdb->delete( mietv_table( 'vpi' ), [ 'monat' => '2024-12-01' ] );
$wpdb->delete( mietv_table( 'vpi' ), [ 'monat' => '2024-11-01' ] );
$wpdb->delete( mietv_table( 'vpi' ), [ 'monat' => '2024-10-01' ] );
Mietv_Vpi::setzen( '2025-01', 115.3 ); // VPI im Monat des Mietbeginns
$im = Mietv_Vpi::indexmieten();
assert( count( $im ) === 1 && $im[0]['festlegung'] === '2025-01-01' && $im[0]['vpi_alt'] === 115.3 && $im[0]['vpi_neu'] === 120.2, 'Indexmiete erkannt ' . wp_json_encode( $im[0]['vpi_alt'] ) );
assert( $im[0]['neu'] === 729.75 && $im[0]['prozent'] === 4.25 && $im[0]['sperrfrist_ende'] === '2026-01-01', 'Berechnung in Übersicht' );
assert( $im[0]['moeglich'] === ( Mietv_Vpi::sperrfrist_ende( '2025-01-01' ) <= current_time( 'Y-m-d' ) ), 'moeglich folgt Sperrfrist' );
$hw = Mietv_Vpi::hinweise();
assert( count( $hw ) === ( $im[0]['moeglich'] ? 1 : 0 ) && ( ! $hw || ( $hw[0]['typ'] === 'info' && strpos( $hw[0]['text'], '729,75' ) !== false ) ), 'Hinweis ' . wp_json_encode( $hw ) );
// Index-Erhöhung erfasst: VPI alt kommt aus der Begründung (vpi_neu der Erhöhung), Festlegung = neue Kondition
$wpdb->insert( mietv_table( 'konditionen' ), [ 'mv_id' => $mv_id, 'gueltig_ab' => '2025-06-01', 'kaltmiete' => 720, 'bk_vz' => 100, 'hk_vz' => 50 ] );
$wpdb->insert( mietv_table( 'erhoehungen' ), [ 'mv_id' => $mv_id, 'art' => 'index', 'zugang' => '2025-04-10', 'wirksam_ab' => '2025-06-01', 'alt' => 700, 'neu' => 720, 'begruendung' => "Indexmiete, VPI 115,3 → 118,6\nCPI 115,3 → 118,6" ] );
$im = Mietv_Vpi::indexmieten();
assert( $im[0]['festlegung'] === '2025-06-01' && $im[0]['vpi_alt'] === 118.6 && $im[0]['vpi_alt_monat'] === '2025-06-01' && $im[0]['miete'] === 720.0, 'VPI alt aus Erhöhung ' . wp_json_encode( [ $im[0]['festlegung'], $im[0]['vpi_alt'] ] ) );
assert( $im[0]['sperrfrist_ende'] === '2026-06-01', 'Sperrfrist nach Erhöhung' );
assert( isset( Mietv_Vpi::hilfe()['vpi'] ), 'Hilfe' );
echo "VPI OK\n";
