<?php
require __DIR__.'/stub.php';
function current_user_can(){return true;} function check_admin_referer(){return true;} function wp_unslash($s){return $s;}
function sanitize_text_field($s){return trim(strip_tags((string)$s));} function sanitize_textarea_field($s){return $s;} function sanitize_email($s){return $s;}
function wp_safe_redirect($u){throw new Exception('redirect:'.$u);} function get_current_user_id(){return 1;}
function get_transient($k){return $GLOBALS['tr'][$k]??false;} function set_transient($k,$v){$GLOBALS['tr'][$k]=$v;} function delete_transient($k){unset($GLOBALS['tr'][$k]);}
function wp_doing_ajax(){return false;} function selected($a,$b,$e=true){return $a==$b?' selected':'';} function wp_nonce_field(){} function esc_textarea($s){return $s;}
function add_menu_page(){} function add_submenu_page(){}
foreach(['class-mv-admin','class-mv-assistent'] as $c) require_once __DIR__."/../includes/$c.php";
function step(array $post){ $_POST=$post; try{ Mietv_Assistent::handle(); }catch(Exception $e){ return $e->getMessage(); } return 'kein redirect'; }
global $wpdb;
echo "1 ", step(['mietv_assistent_schritt'=>1,'vermieter_name'=>'Markus Hirn','vermieter_adresse'=>"Im Pinderpark 2\n90513 Zirndorf",'vermieter_email'=>'m@x.de','vermieter_iban'=>'DE02120300000000202051']),"\n";
echo "   Einstellungen: ", Mietv_Einstellungen::get('vermieter_name'),"\n";
echo "2 ", step(['mietv_assistent_schritt'=>2,'bezeichnung'=>'ETW Weg 1','strasse'=>'Weg 1','plz'=>'90762','ort'=>'Fürth','baujahr'=>'1995','heizung'=>'zentral']),"\n";
echo "3 ", step(['mietv_assistent_schritt'=>3,'einheit'=>'Wohnung EG','lage'=>'EG','flaeche'=>'78,5','zimmer'=>'3','garage'=>'Garage 4','garage_typ'=>'garage','garage_anteil'=>'50']),"\n";
echo "4 ", step(['mietv_assistent_schritt'=>4,'anrede'=>'Frau','vorname'=>'Anna','nachname'=>'Meier','email'=>'a@b.de','p_anrede'=>'Herr','p_vorname'=>'Max','p_nachname'=>'Meier','iban'=>'DE02 1203 0000 0000 2020 51']),"\n";
echo "5 ", step(['mietv_assistent_schritt'=>5,'beginn'=>'2026-01-01','kaltmiete'=>'700','bk_vz'=>'120','hk_vz'=>'90','kaution'=>'2100','personen'=>'2','zahltag'=>'3','mit_garage'=>'1']),"\n";
echo "   Stand: ", json_encode(Mietv_Assistent::stand()),"\n";
printf("   Tabellen: objekte=%d einheiten=%d mieter=%d mv=%d konditionen=%d zusatz=%d\n", ...array_map(fn($t)=>(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_mv_$t"), ['objekte','einheiten','mieter','mietverhaeltnisse','konditionen','mv_einheiten']));
echo "   Label: ", Mietv_Soll::mietv_label(Mietv_Soll::alle_mv()[0]),"\n";
// Fehlerfall
echo "5b ", step(['mietv_assistent_schritt'=>5,'beginn'=>'','kaltmiete'=>'0']), " fehler=", json_encode($GLOBALS['tr']['mietv_assistent_fehler_1']??null),"\n";
// Wiederholung Schritt 2 → Update statt Insert
step(['mietv_assistent_schritt'=>2,'bezeichnung'=>'ETW Weg 1a','strasse'=>'Weg 1','plz'=>'90762','ort'=>'Fürth','baujahr'=>'1995','heizung'=>'zentral']);
printf("   Objekte nach Wiederholung: %d (erwartet 1), Name=%s\n", $wpdb->get_var("SELECT COUNT(*) FROM wp_mv_objekte"), $wpdb->get_var("SELECT bezeichnung FROM wp_mv_objekte"));
// Seiten rendern
$_GET=['schritt'=>5]; ob_start(); Mietv_Assistent::page(); $h=ob_get_clean(); echo "   Seite 5: ", strlen($h), " Zeichen, Garage-Checkbox: ", str_contains($h,'mit_garage')?'ja':'nein', "\n";
$_GET=['schritt'=>6]; ob_start(); Mietv_Assistent::page(); $h=ob_get_clean(); echo "   Seite 6: ", str_contains($h,'abgeschlossen')?'Zusammenfassung ok':'FEHLT', "\n";
