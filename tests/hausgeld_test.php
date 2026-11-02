<?php
// Hausgeld: Plan, Monatsbuchung (Belege je Kostenart), Jahresabrechnung (Korrekturbelege), Löschen.
require __DIR__ . '/stub.php';
require_once dirname( __DIR__ ) . '/includes/class-mv-hausgeld.php';
global $wpdb;
$wpdb->insert( mietv_table( 'objekte' ), [ 'bezeichnung' => 'ETW Test', 'strasse' => 'x', 'ort' => 'y' ] );
$oid = (int) $wpdb->insert_id;
$ka  = [];
foreach ( [ [ 'Wasser', 'wasser', 1 ], [ 'Verwaltung', 'verwaltung', 0 ], [ 'Rücklage', 'ruecklage', 0 ] ] as $i => $k ) {
	$wpdb->insert( mietv_table( 'kostenarten' ), [ 'name' => $k[0], 'anlage_v' => $k[1], 'umlagefaehig' => $k[2], 'sortierung' => $i ] );
	$ka[ $k[0] ] = (int) $wpdb->insert_id;
}
$wpdb->insert( mietv_table( 'hausgeld' ), [ 'objekt_id' => $oid, 'verwalter' => 'HV', 'iban' => 'DE00', 'betrag' => 300, 'gueltig_ab' => '2025-01-01', 'verteilung' => wp_json_encode( [ $ka['Wasser'] => 150, $ka['Verwaltung'] => 100, $ka['Rücklage'] => 50 ] ) ] );
$plan = Mietv_Hausgeld::plan_fuer( $oid, '2025-06-01' );
assert( $plan && (float) $plan->betrag === 300.0, 'Plan gefunden' );
assert( Mietv_Hausgeld::plan_fuer( $oid, '2024-12-01' ) === null, 'kein Plan vor gueltig_ab' );
$t = Mietv_Hausgeld::aufteilung( $plan );
assert( $t['umlagefaehig'] === 150.0 && $t['werbungskosten'] === 100.0 && $t['ruecklage'] === 50.0, 'Aufteilung' );
$b1 = Mietv_Hausgeld::buchen_monat( $oid, '2025-01-01', '2025-01-03' );
assert( $b1 > 0, 'Monat gebucht' );
assert( Mietv_Hausgeld::buchen_monat( $oid, '2025-01-01', '2025-01-03' ) === 0, 'Doppelbuchung verhindert' );
$bel = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . mietv_table( 'belege' ) . ' WHERE hausgeld_id = %d ORDER BY kostenart_id', $b1 ) );
assert( count( $bel ) === 3 && (float) array_sum( array_column( $bel, 'betrag' ) ) === 300.0, '3 Belege, Summe 300' );
assert( $bel[0]->zeitraum_von === '2025-01-01' && $bel[0]->zeitraum_bis === '2025-01-31' && (int) $bel[0]->steuerjahr === 2025, 'Zeitraum/Steuerjahr' );
// Abweichender Zahlbetrag wird anteilig verteilt
$b2 = Mietv_Hausgeld::buchen_monat( $oid, '2025-02-01', '2025-02-03', 330.0 );
$s2 = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(betrag) FROM ' . mietv_table( 'belege' ) . ' WHERE hausgeld_id = %d', $b2 ) );
assert( abs( $s2 - 330.0 ) < 0.005, 'anteilige Verteilung 330' );
$g = Mietv_Hausgeld::gebucht_je_kostenart( $oid, 2025 );
assert( abs( $g[ $ka['Wasser'] ] - 315.0 ) < 0.01, 'Wasser 150+165' );
// Jahresabrechnung: Wasser tatsächlich 400 (+85), Verwaltung 200 (−10), Rücklage unverändert
$a = Mietv_Hausgeld::buchen_abrechnung( $oid, 2025, [ $ka['Wasser'] => 400, $ka['Verwaltung'] => 200, $ka['Rücklage'] => 110 ], '2026-04-10' );
assert( $a > 0, 'Abrechnung gebucht' );
$kb = $wpdb->get_results( $wpdb->prepare( 'SELECT kostenart_id, betrag, steuerjahr, zeitraum_von, zeitraum_bis FROM ' . mietv_table( 'belege' ) . ' WHERE hausgeld_id = %d', $a ), OBJECT_K );
assert( count( $kb ) === 2, 'nur Differenzen gebucht' );
assert( abs( (float) $kb[ $ka['Wasser'] ]->betrag - 85.0 ) < 0.01 && abs( (float) $kb[ $ka['Verwaltung'] ]->betrag + 10.0 ) < 0.01, 'Differenzen 85 / −10' );
assert( (int) $kb[ $ka['Wasser'] ]->steuerjahr === 2026 && $kb[ $ka['Wasser'] ]->zeitraum_von === '2025-01-01' && $kb[ $ka['Wasser'] ]->zeitraum_bis === '2025-12-31', 'Abfluss 2026, Leistungszeitraum 2025' );
assert( Mietv_Hausgeld::buchen_abrechnung( $oid, 2025, [ $ka['Wasser'] => 999 ], '2026-04-10' ) === 0, 'zweite Abrechnung verhindert' );
$bu = Mietv_Hausgeld::buchungen( $oid, 2025 );
assert( isset( $bu['2025-01-01'], $bu['2025-02-01'], $bu['abrechnung'] ) && abs( (float) $bu['abrechnung']->betrag - 75.0 ) < 0.01, 'Buchungen inkl. Abrechnung 75' );
Mietv_Hausgeld::loeschen( $a );
assert( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . mietv_table( 'belege' ) . ' WHERE hausgeld_id = %d', $a ) ) === 0, 'Belege der Abrechnung gelöscht' );
echo "Hausgeld OK\n";
