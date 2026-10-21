<?php
require __DIR__.'/stub.php';
global $wpdb;
// Objekt mit 2 Wohnungen, Zentralheizung 70/30
$wpdb->insert('wp_mv_objekte',['owner_id'=>1,'bezeichnung'=>'Haus','strasse'=>'Musterweg 1','plz'=>'90513','ort'=>'Zirndorf','heizung'=>'zentral','hk_verbrauchsanteil'=>70]);
$wpdb->insert('wp_mv_einheiten',['objekt_id'=>1,'bezeichnung'=>'EG','flaeche'=>60,'typ'=>'wohnung']);
$wpdb->insert('wp_mv_einheiten',['objekt_id'=>1,'bezeichnung'=>'OG','flaeche'=>40,'typ'=>'wohnung']);
$wpdb->insert('wp_mv_mieter',['owner_id'=>1,'vorname'=>'Anna','nachname'=>'Meier','iban'=>'DE02120300000000202051']);
$wpdb->insert('wp_mv_mieter',['owner_id'=>1,'vorname'=>'Bert','nachname'=>'Huber']);
$wpdb->insert('wp_mv_mietverhaeltnisse',['einheit_id'=>1,'mieter_id'=>1,'beginn'=>'2024-01-01','personen'=>2,'zahltag'=>3,'kaution'=>0]);
$wpdb->insert('wp_mv_mietverhaeltnisse',['einheit_id'=>2,'mieter_id'=>2,'beginn'=>'2025-07-01','personen'=>1,'zahltag'=>3,'kaution'=>0]); // halbes Jahr
$wpdb->insert('wp_mv_konditionen',['mv_id'=>1,'gueltig_ab'=>'2024-01-01','kaltmiete'=>600,'bk_vz'=>100,'hk_vz'=>80]);
$wpdb->insert('wp_mv_konditionen',['mv_id'=>2,'gueltig_ab'=>'2025-07-01','kaltmiete'=>450,'bk_vz'=>60,'hk_vz'=>50]);
// Kostenarten
$wpdb->insert('wp_mv_kostenarten',['owner_id'=>1,'name'=>'Grundsteuer','anlage_v'=>'grundsteuer','umlagefaehig'=>1,'schluessel'=>'flaeche','betrkv'=>'§ 2 Nr. 1','sortierung'=>1]);
$wpdb->insert('wp_mv_kostenarten',['owner_id'=>1,'name'=>'Müllabfuhr','anlage_v'=>'grundsteuer','umlagefaehig'=>1,'schluessel'=>'personen','betrkv'=>'§ 2 Nr. 8','sortierung'=>2]);
$wpdb->insert('wp_mv_kostenarten',['owner_id'=>1,'name'=>'Heizung Gas','anlage_v'=>'heizung','umlagefaehig'=>1,'schluessel'=>'verbrauch','betrkv'=>'§ 2 Nr. 4','sortierung'=>3]);
$wpdb->insert('wp_mv_kostenarten',['owner_id'=>1,'name'=>'Hausmeister','anlage_v'=>'versicherung','umlagefaehig'=>1,'schluessel'=>'flaeche','betrkv'=>'§ 2 Nr. 14','sortierung'=>4]);
$wpdb->insert('wp_mv_kostenarten',['owner_id'=>1,'name'=>'Verwaltung','anlage_v'=>'verwaltung','umlagefaehig'=>0,'schluessel'=>'','betrkv'=>'','sortierung'=>5]);
// Belege 2025
$b=fn($ka,$betrag,$von,$bis,$extra=[])=>$wpdb->insert('wp_mv_belege',array_merge(['owner_id'=>1,'objekt_id'=>1,'kostenart_id'=>$ka,'datum'=>$von,'steuerjahr'=>2025,'betrag'=>$betrag,'zeitraum_von'=>$von,'zeitraum_bis'=>$bis,'verteilung_jahre'=>1,'par35a'=>'','lohnanteil'=>0],$extra));
$b(1,500,'2025-01-01','2025-12-31');              // Grundsteuer 500 nach Fläche
$b(2,300,'2025-01-01','2025-12-31');              // Müll 300 nach Personen
$b(3,2000,'2025-01-01','2025-12-31');             // Heizung 2000: 70% Verbrauch, 30% Fläche
$b(4,1200,'2025-01-01','2025-12-31',['par35a'=>'haushalt','lohnanteil'=>1000]); // Hausmeister mit Lohnanteil
$b(5,999,'2025-01-01','2025-12-31');              // nicht umlagefähig
$b(1,1000,'2024-07-01','2025-06-30');             // Beleg über Jahreswechsel: 181/365 Tage in 2025
// Zähler Heizung
$wpdb->insert('wp_mv_zaehler',['objekt_id'=>1,'einheit_id'=>1,'typ'=>'heizung','nummer'=>'H1']);
$wpdb->insert('wp_mv_zaehler',['objekt_id'=>1,'einheit_id'=>2,'typ'=>'heizung','nummer'=>'H2']);
foreach([[1,'2025-01-01',1000],[1,'2025-12-31',4000],[2,'2025-07-01',500],[2,'2025-12-31',1500]] as [$z,$d,$s]) $wpdb->insert('wp_mv_zaehlerstaende',['zaehler_id'=>$z,'datum'=>$d,'stand'=>$s]);
$wpdb->insert('wp_mv_abrechnungen',['owner_id'=>1,'objekt_id'=>1,'von'=>'2025-01-01','bis'=>'2025-12-31','status'=>'entwurf']);
$r=Mietv_Abrechnung::berechnen(1);
foreach($r['hinweise'] as $h) echo "HINWEIS: $h\n";
foreach($r['ergebnisse'] as $id=>$e){
  printf("\nMV %d (%d Tage): BK %.2f HK %.2f VZ %.2f Saldo %.2f  §35a haushalt %.2f\n",$id,$e['mv']->tage,$e['kosten_bk'],$e['kosten_hk'],$e['vz_bk']+$e['vz_hk'],$e['saldo'],$e['par35a_haushalt']);
  foreach($e['positionen'] as $p) printf("   %-32s gesamt %8.2f  %-18s %10.2f / %10.2f  -> %8.2f\n",$p['name'],$p['gesamt'],$p['schluessel'],$p['basis_mv'],$p['basis_gesamt'],$p['anteil']);
}
echo "\n--- Erwartung ---\n";
echo "Grundsteuer gesamt 500 + 1000*181/365=495.89 -> 995.89; MV1 60% = 597.53; MV2 40%*184/365 = 200.81; Rest Leerstand-Anteil OG 1.1.-30.6. trägt Vermieter\n";
echo "Müll 300 Personentage: MV1 2*365=730, MV2 1*184=184 -> MV1 240.09, MV2 59.91\n";
echo "Heizung 2000: 1400 Verbrauch (3000:1000 -> 1050/350), 600 Fläche (MV1 360, MV2 120.99)\n";
echo "Hausmeister 1200 Fläche: MV1 720, MV2 241.97; Lohn 1000 anteilig: MV1 600, MV2 201.64\n";
echo "VZ MV1 180*12=2160; MV2 110*6=660\n";
// Bankparser
echo "\n--- Bank CSV ---\n";
$csv="\"Auftragskonto\";\"Buchungstag\";\"Valutadatum\";\"Buchungstext\";\"Verwendungszweck\";\"Beguenstigter/Zahlungspflichtiger\";\"Kontonummer/IBAN\";\"BIC\";\"Betrag\";\"Waehrung\";\"Info\"\n\"DE1\";\"03.03.2026\";\"03.03.2026\";\"GUTSCHRIFT\";\"Miete Maerz 2026 EG\";\"Anna Meier\";\"DE02120300000000202051\";\"BYLADEM1001\";\"780,00\";\"EUR\";\"\"\n\"DE1\";\"15.03.2026\";\"15.03.2026\";\"LASTSCHRIFT\";\"Abschlag Gas 03/2026\";\"Stadtwerke Zirndorf\";\"DE99\";\"\";\"-150,50\";\"EUR\";\"\"\n";
$rows=Mietv_Bank::parse($csv,'umsaetze.csv');
$wpdb->insert('wp_mv_bank_regeln',['owner_id'=>1,'muster'=>'Stadtwerke','typ'=>'beleg','objekt_id'=>1,'kostenart_id'=>3]);
foreach(Mietv_Bank::vorschlaege($rows) as $r) printf("%s %8.2f %-22s -> typ=%s mv=%d ka=%d monat=%s (%s)\n",$r['datum'],$r['betrag'],$r['name'],$r['typ'],$r['mv_id'],$r['kostenart_id'],$r['monat'],$r['grund']??'');
