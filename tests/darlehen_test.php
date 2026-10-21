<?php
require __DIR__.'/stub.php';
function current_user_can(){return true;} function wp_die($m){throw new Exception('DIE:'.$m);} function sanitize_text_field($s){return trim((string)$s);} function wp_verify_nonce(){return true;} function wp_safe_redirect($u){throw new Exception('redirect:'.$u);} function esc_js($s){return $s;} function selected($a,$b,$e=true){return '';} function wp_nonce_field(){return '';}
require_once __DIR__.'/../includes/class-mv-admin.php'; require_once __DIR__.'/../includes/class-mv-darlehen.php'; require_once __DIR__.'/../includes/class-mv-steuer.php'; require_once __DIR__.'/../includes/class-mv-soll.php'; require_once __DIR__.'/../includes/class-mv-afa.php';
global $wpdb;
$wpdb->insert('wp_mv_objekte',['owner_id'=>1,'bezeichnung'=>'Haus']);
$wpdb->insert('wp_mv_darlehen',['owner_id'=>1,'objekt_id'=>1,'bank'=>'DKB','betrag'=>200000,'auszahlung'=>'2025-01-15','zinssatz'=>3.0,'rate'=>900,'tilgung'=>2.4,'status'=>'aktiv']);
$d=$wpdb->get_row("SELECT * FROM wp_mv_darlehen WHERE id=1");
$plan=MV_Darlehen::plan($d,'2025-12-01');
printf("Plan 2025: %d offene Monate, erste Rate %s: Zins %.2f Tilgung %.2f Rest %.2f (erwartet Zins 500,00 Tilgung 400,00 Rest 199.600,00)\n",count($plan),$plan[0]['monat'],$plan[0]['zins'],$plan[0]['tilgung'],$plan[0]['restschuld']);
// Alle 2025-Monate abhaken (Reihenfolge absichtlich gemischt)
$monate=array_column($plan,'monat'); shuffle($monate);
$_POST=['mv_darlehen_nonce'=>'x','darlehen_id'=>'1','monat'=>$monate];
try{ MV_Darlehen::handle(); }catch(Exception $e){ echo $e->getMessage()."\n"; }
$jw=MV_Darlehen::jahreswerte(1,2025);
printf("Jahreswerte 2025: Zinsen %.2f Tilgung %.2f Restschuld %.2f (%d Raten)\n",$jw['zinsen'],$jw['tilgung'],$jw['restschuld'],$jw['anzahl']);
// Kontrolle: unabhängige Annuitätenrechnung
$r=200000; $z=0; $t=0; for($i=0;$i<11;$i++){ $zi=round($r*0.03/12,2); $ti=round(900-$zi,2); $z+=$zi; $t+=$ti; $r-=$ti; } printf("Kontrolle:        Zinsen %.2f Tilgung %.2f Restschuld %.2f\n",$z,$t,$r);
// Doppelt abhaken
$_POST=['mv_darlehen_nonce'=>'x','darlehen_id'=>'1','monat'=>['2025-03-01']]; try{ MV_Darlehen::handle(); }catch(Exception $e){ echo "Doppelt: ".substr($e->getMessage(),-4)." (n=0 erwartet)\n"; }
// Sondertilgung
$_POST=['mv_darlehen_nonce'=>'x','darlehen_id'=>'1','sondertilgung'=>'1','st_datum'=>'2025-12-20','st_betrag'=>'10.000,00']; try{ MV_Darlehen::handle(); }catch(Exception $e){}
printf("Nach Sondertilgung: Restschuld %.2f (erwartet %.2f)\n",MV_Darlehen::restschuld($d),$r-10000);
// Nächste Rate rechnet mit reduzierter Restschuld
$plan=MV_Darlehen::plan($d,'2026-01-01'); $n=end($plan); printf("Rate 01/2026: Zins %.2f (erwartet %.2f)\n",$n['zins'],round(($r-10000)*0.03/12,2));
// Steuer-Fallback
$rows=MV_Steuer::darlehen_jahr(2025); printf("Steuer 2025: %s Zinsen %.2f\n",$rows[0]->quelle,$rows[0]->zinsen);
$wpdb->insert('wp_mv_darlehen_jahre',['darlehen_id'=>1,'jahr'=>2025,'zinsen'=>5490.12,'tilgung'=>0,'restschuld'=>0]);
$rows=MV_Steuer::darlehen_jahr(2025); printf("Steuer 2025 mit Bescheinigung: %s Zinsen %.2f (Bescheinigung hat Vorrang)\n",$rows[0]->quelle,$rows[0]->zinsen);
