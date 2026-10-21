<?php
require __DIR__.'/stub.php';
function current_user_can(){return true;} function check_admin_referer(){return true;} function wp_die($m){throw new Exception($m);}
function sanitize_text_field($s){return trim(strip_tags((string)$s));} function sanitize_textarea_field($s){return $s;} function sanitize_key($s){return preg_replace('/[^a-z0-9_]/','',strtolower($s));} function wp_unslash($s){return $s;} function sanitize_email($s){return $s;}
function wp_safe_redirect($u){throw new Exception('redirect:'.$u);} function get_current_user_id(){return 1;} function get_transient($k){return $GLOBALS['tr'][$k]??false;} function set_transient($k,$v){$GLOBALS['tr'][$k]=$v;} function delete_transient($k){unset($GLOBALS['tr'][$k]);} function wp_is_mobile(){return false;} function get_users(){return [];} function add_query_arg($a,$u){return $u.'?'.http_build_query($a);}
require __DIR__.'/../includes/class-mv-admin.php';
$GLOBALS["dbg"]=1; $cases = [
 'objekte'           => ['bezeichnung'=>'Haus','strasse'=>'Weg 1','plz'=>'90513','ort'=>'Zirndorf','baujahr'=>'','heizung'=>'zentral','hk_verbrauchsanteil'=>'','notiz'=>''],
 'einheiten'         => ['objekt_id'=>'1','bezeichnung'=>'EG','lage'=>'EG','flaeche'=>'60,5','zimmer'=>'','typ'=>'wohnung'],
 'mieter'            => ['anrede'=>'','vorname'=>'Anna','nachname'=>'Meier','email'=>'a@b.de','telefon'=>'','iban'=>'DE02120300000000202051','wp_user_id'=>'','notiz'=>'','p_anrede'=>'Herr','p_vorname'=>'Max','p_nachname'=>'Meier','p_geburtsdatum'=>'1980-05-01','p_email'=>'m@b.de'],
 'mietverhaeltnisse' => ['einheit_id'=>'1','mieter_id'=>'1','beginn'=>'2026-01-01','ende'=>'','kaution'=>'1950','personen'=>'','zahltag'=>'','notiz'=>''],
 'konditionen'       => ['mv_id'=>'1','gueltig_ab'=>'2026-01-01','kaltmiete'=>'650','bk_vz'=>'120','hk_vz'=>'90'],
 'zahlungen'         => ['mv_id'=>'1','datum'=>'2026-01-03','betrag'=>'860','monat'=>'2026-01','verwendungszweck'=>'Miete'],
 'meldungen'         => ['mv_id'=>'1','datum'=>'2026-01-05T10:00','betreff'=>'Tropfender Hahn','text'=>'Bad','status'=>'offen'],
 'kostenarten'       => ['name'=>'Grundsteuer','anlage_v'=>'grundsteuer','umlagefaehig'=>'1','schluessel'=>'','betrkv'=>'','sortierung'=>''],
 'belege'            => ['objekt_id'=>'1','kostenart_id'=>'1','datum'=>'2026-02-01','steuerjahr'=>'','betrag'=>'500','lieferant'=>'Stadt','belegnr'=>'1','zeitraum_von'=>'','zeitraum_bis'=>'','verteilung_jahre'=>'1','par35a'=>'','lohnanteil'=>'0','beschreibung'=>''],
 'zaehler'           => ['objekt_id'=>'1','einheit_id'=>'1','typ'=>'kaltwasser','nummer'=>'KW1','notiz'=>''],
 'zaehlerstaende'    => ['zaehler_id'=>'1','datum'=>'2026-01-01','stand'=>'100,5','quelle'=>'vermieter'],
 'anlagegueter'      => ['objekt_id'=>'1','bezeichnung'=>'Gebäude','typ'=>'gebaeude','anschaffung'=>'2020-01-01','ak'=>'300000','nk_grunderwerbsteuer'=>'10.500','nk_notar'=>'3.000','nk_grundbuch'=>'1.200','nk_makler'=>'10.710','nk_sonstige'=>'','nebenkosten'=>'','nk_finanzierung'=>'900','grundanteil'=>'25','methode'=>'linear','satz'=>'','nutzungsdauer'=>'50','ende'=>'','notiz'=>''],
 'darlehen'          => ['objekt_id'=>'1','bank'=>'DKB','bezeichnung'=>'','betrag'=>'200000','auszahlung'=>'2020-01-01','zinssatz'=>'1,5','zinsbindung_bis'=>'2030-01-01','rate'=>'800','tilgung'=>'2','status'=>'aktiv','notiz'=>''],
 'darlehen_jahre'    => ['darlehen_id'=>'1','jahr'=>'2025','zinsen'=>'2900','tilgung'=>'6700','restschuld'=>'170000'],
 'dokumente'         => ['objekt_id'=>'1','mv_id'=>'1','typ'=>'mietvertrag','bezeichnung'=>'Vertrag','datum'=>'2026-01-01','mieter_sichtbar'=>'1','notiz'=>''],
 'bank_regeln'       => ['muster'=>'Stadtwerke','typ'=>'beleg','objekt_id'=>'1','kostenart_id'=>'1','mv_id'=>''],
];
global $wpdb; $ok=0;
foreach($cases as $ent=>$post){
  $_POST=array_merge(['mietv_entity'=>$ent,'mietv_record_id'=>'0'],$post); $_FILES=[];
  try{ Mietv_Admin::handle_actions(); }catch(Exception $e){ $r=$e->getMessage(); }
  $n=(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_mv_$ent");
  $err=$GLOBALS['tr']['mietv_errors_1']??null; unset($GLOBALS['tr']['mietv_errors_1']);
  printf("%-18s %s  Zeilen=%d %s\n",$ent,strpos($r,'saved')!==false?'OK ':'ERR',$n,$err?implode(' | ',$err):'');
  if($n>0 && !$err) $ok++;
}
// Update-Pfad: Konditionen Datensatz 1 ändern
$_POST=['mietv_entity'=>'konditionen','mietv_record_id'=>'1','mv_id'=>'1','gueltig_ab'=>'2026-01-01','kaltmiete'=>'700','bk_vz'=>'120','hk_vz'=>'90'];
try{ Mietv_Admin::handle_actions(); }catch(Exception $e){}
echo "Update Kondition: kaltmiete=".$wpdb->get_var("SELECT kaltmiete FROM wp_mv_konditionen WHERE id=1")." (erwartet 700), Zeilen=".$wpdb->get_var("SELECT COUNT(*) FROM wp_mv_konditionen")." (erwartet 1)\n";
echo "$ok/".count($cases)." Entitäten OK\n";
printf("Anlagegut: Nebenkosten-Summe %.2f (erwartet 25410.00), Finanzierungsbeleg %d (erwartet 1, Betrag %.2f)\n",$wpdb->get_var("SELECT nebenkosten FROM wp_mv_anlagegueter WHERE id=1"),$wpdb->get_var("SELECT COUNT(*) FROM wp_mv_belege b JOIN wp_mv_kostenarten k ON k.id=b.kostenart_id WHERE k.name LIKE '%Geldbeschaffung%'"),(float)$wpdb->get_var("SELECT betrag FROM wp_mv_belege b JOIN wp_mv_kostenarten k ON k.id=b.kostenart_id WHERE k.name LIKE '%Geldbeschaffung%'"));

// ---- Update-Pfad für alle Entitäten: Datensatz 1 mit denselben (teils leeren) Werten erneut speichern ----
echo "\n--- Update-Pfad ---\n"; $okU=0;
foreach($cases as $ent=>$post){
  if($ent==='dokumente') continue;
  $_POST=array_merge(['mietv_entity'=>$ent,'mietv_record_id'=>'1'],$post); $_FILES=[]; $r='';
  try{ Mietv_Admin::handle_actions(); }catch(Exception $e){ $r=$e->getMessage(); }
  $err=$GLOBALS['tr']['mietv_errors_1']??null; unset($GLOBALS['tr']['mietv_errors_1']);
  $n=(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_mv_$ent");
  $erw=in_array($ent,["kostenarten","belege"])?2:1; $ok=strpos($r,"saved")!==false && !$err && $n===$erw; if($ok)$okU++;
  printf("%-18s %s Zeilen=%d %s\n",$ent,$ok?'OK ':'ERR',$n,$err?implode(' | ',$err):'');
}
echo "$okU/15 Updates OK\n";
// Kontrolle: NOT-NULL-Spalten nach Update mit leerem Feld
echo "personen nach Update mit leer: ".var_export($wpdb->get_var("SELECT personen FROM wp_mv_mietverhaeltnisse WHERE id=1"),true)." (Default 1 erwartet)\n";
echo "sortierung nach Update mit leer: ".var_export($wpdb->get_var("SELECT sortierung FROM wp_mv_kostenarten WHERE id=1"),true)." (Default 0 erwartet)\n";
