<?php
require __DIR__.'/stub.php';
require_once __DIR__.'/../includes/class-mv-afa.php';
global $wpdb;
$wpdb->insert('wp_mv_objekte',['owner_id'=>1,'bezeichnung'=>'ETW','baujahr'=>1988,'heizung'=>'zentral']);
// So wie ein Nutzer es eingibt: Satz leer, Nutzungsdauer leer, Grundanteil 20
$wpdb->insert('wp_mv_anlagegueter',['owner_id'=>1,'objekt_id'=>1,'bezeichnung'=>'Wohnung','typ'=>'gebaeude','anschaffung'=>'2024-06-01','ak'=>250000,'nebenkosten'=>20000,'grundanteil'=>20,'methode'=>'linear','satz'=>0,'nutzungsdauer'=>null]);
foreach(Mietv_Afa::uebersicht(2025) as $r) printf("Satz leer:   Basis %.2f Satz %.2f AfA 2025 = %.2f\n",$r['basis'],$r['satz'],$r['afa']);
$wpdb->update('wp_mv_anlagegueter',['satz'=>2],['id'=>1]);
foreach(Mietv_Afa::uebersicht(2025) as $r) printf("Satz 2 %%:    Basis %.2f Satz %.2f AfA 2025 = %.2f (erwartet 4.320,00)\n",$r['basis'],$r['satz'],$r['afa']);
foreach([[1910,2.5],[1988,2.0],[2024,3.0],[0,2.0]] as [$bj,$erw]){
  $wpdb->update('wp_mv_objekte',['baujahr'=>$bj?:null],['id'=>1]); $wpdb->update('wp_mv_anlagegueter',['satz'=>0],['id'=>1]);
  $r=Mietv_Afa::uebersicht(2025)[0]; printf("Baujahr %-4s -> Satz %.1f %% (erwartet %.1f) %s\n",$bj?:'leer',$r['satz'],$erw,$r['satz']==$erw?'OK':'FEHLER');
}
printf("Jahr vor Anschaffung (2023): %d Einträge (0 erwartet)\n", count(Mietv_Afa::uebersicht(2023)));
printf("Anschaffungsjahr 2024 (Juni): AfA %.2f (erwartet 7/12 von 4320 = 2.520,00)\n", Mietv_Afa::uebersicht(2024)[0]['afa']);
