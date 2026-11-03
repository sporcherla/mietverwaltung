<?php
// Abrechnung: Heizung durch Ablesedienst, Wasser ohne Wohnungszähler.
require __DIR__.'/stub.php';
global $wpdb;
$wpdb->insert('wp_mv_objekte',['owner_id'=>1,'bezeichnung'=>'Haus','strasse'=>'Musterweg 1','plz'=>'90513','ort'=>'Zirndorf','heizung'=>'zentral','hk_verbrauchsanteil'=>70,'heizung_ablesung'=>'ablesedienst','wasser_verteilung'=>'personen']);
$wpdb->insert('wp_mv_einheiten',['objekt_id'=>1,'bezeichnung'=>'EG','flaeche'=>60,'typ'=>'wohnung']);
$wpdb->insert('wp_mv_einheiten',['objekt_id'=>1,'bezeichnung'=>'OG','flaeche'=>40,'typ'=>'wohnung']);
$wpdb->insert('wp_mv_mieter',['owner_id'=>1,'vorname'=>'Anna','nachname'=>'Meier']);
$wpdb->insert('wp_mv_mieter',['owner_id'=>1,'vorname'=>'Bert','nachname'=>'Huber']);
$wpdb->insert('wp_mv_mietverhaeltnisse',['einheit_id'=>1,'mieter_id'=>1,'beginn'=>'2024-01-01','personen'=>2,'zahltag'=>3,'kaution'=>0]);
$wpdb->insert('wp_mv_mietverhaeltnisse',['einheit_id'=>2,'mieter_id'=>2,'beginn'=>'2024-01-01','personen'=>1,'zahltag'=>3,'kaution'=>0]);
$wpdb->insert('wp_mv_konditionen',['mv_id'=>1,'gueltig_ab'=>'2024-01-01','kaltmiete'=>600,'bk_vz'=>100,'hk_vz'=>80]);
$wpdb->insert('wp_mv_konditionen',['mv_id'=>2,'gueltig_ab'=>'2024-01-01','kaltmiete'=>450,'bk_vz'=>60,'hk_vz'=>50]);
$wpdb->insert('wp_mv_kostenarten',['owner_id'=>1,'name'=>'Heizung Gas','anlage_v'=>'heizung','umlagefaehig'=>1,'schluessel'=>'verbrauch','betrkv'=>'§ 2 Nr. 4','sortierung'=>1]);
$wpdb->insert('wp_mv_kostenarten',['owner_id'=>1,'name'=>'Wasser/Abwasser','anlage_v'=>'grundsteuer','umlagefaehig'=>1,'schluessel'=>'verbrauch','betrkv'=>'§ 2 Nr. 2','sortierung'=>2]);
$b=fn($ka,$betrag)=>$wpdb->insert('wp_mv_belege',['owner_id'=>1,'objekt_id'=>1,'kostenart_id'=>$ka,'datum'=>'2025-01-01','steuerjahr'=>2025,'betrag'=>$betrag,'zeitraum_von'=>'2025-01-01','zeitraum_bis'=>'2025-12-31','verteilung_jahre'=>1,'par35a'=>'','lohnanteil'=>0]);
$b(1,2000); $b(2,900);
$wpdb->insert('wp_mv_abrechnungen',['owner_id'=>1,'objekt_id'=>1,'von'=>'2025-01-01','bis'=>'2025-12-31','status'=>'entwurf']);

// 1) Ohne erfasste Ablesedienst-Werte: Hinweis, HK = 0, Wasser nach Personen ohne Zähler-Warnung
$r=Mietv_Abrechnung::berechnen(1);
$txt=implode("\n",$r['hinweise']);
assert(strpos($txt,'reading service')!==false, 'Hinweis Ablesedienst fehlt');
assert(strpos($txt,'no (complete) meter readings')===false, 'Zähler-Warnung darf nicht erscheinen');
assert(abs($r['ergebnisse'][1]['kosten_hk'])<0.005 && abs($r['ergebnisse'][2]['kosten_hk'])<0.005);
assert(abs($r['ergebnisse'][1]['kosten_bk']-600)<0.01, 'Wasser MV1 2/3 von 900: '.$r['ergebnisse'][1]['kosten_bk']);
assert(abs($r['ergebnisse'][2]['kosten_bk']-300)<0.01, 'Wasser MV2 1/3 von 900');

// 2) Ablesedienst-Werte erfassen
$wpdb->insert('wp_mv_abrechnung_ablesedienst',['abrechnung_id'=>1,'mv_id'=>1,'heizung'=>1100,'warmwasser'=>200]);
$wpdb->insert('wp_mv_abrechnung_ablesedienst',['abrechnung_id'=>1,'mv_id'=>2,'heizung'=>600,'warmwasser'=>100]);
$r=Mietv_Abrechnung::berechnen(1);
$txt=implode("\n",$r['hinweise']);
assert(strpos($txt,'enter the heating')===false, 'Hinweis darf nicht mehr erscheinen: '.$txt);
assert(strpos($txt,'Check:')===false, 'Summe stimmt (2000) – kein Prüfhinweis: '.$txt);
assert(abs($r['ergebnisse'][1]['kosten_hk']-1300)<0.01);
assert(abs($r['ergebnisse'][2]['kosten_hk']-700)<0.01);
assert(abs($r['ergebnisse'][1]['saldo']-(2160-600-1300))<0.01, 'Saldo MV1 '.$r['ergebnisse'][1]['saldo']);
$namen=array_column($r['ergebnisse'][1]['positionen'],'name');
assert(in_array('Heizkosten laut Ablesedienst',$namen,true) && in_array('Warmwasserkosten laut Ablesedienst',$namen,true));

// 3) Abweichende Summe -> Prüfhinweis
$wpdb->update('wp_mv_abrechnung_ablesedienst',['heizung'=>500],['mv_id'=>2]);
$r=Mietv_Abrechnung::berechnen(1);
assert(strpos(implode("\n",$r['hinweise']),'Check:')!==false, 'Prüfhinweis bei Abweichung fehlt');
echo "ablesedienst_test OK\n";
