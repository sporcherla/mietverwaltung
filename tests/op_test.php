<?php
require __DIR__.'/stub.php';
function current_user_can(){return true;} function check_admin_referer(){return true;} function wp_die($m){throw new Exception('DIE:'.$m);}
function sanitize_text_field($s){return trim(strip_tags((string)$s));} function sanitize_textarea_field($s){return $s;} function sanitize_key($s){return preg_replace('/[^a-z0-9_]/','',strtolower($s));} function wp_unslash($s){return $s;} function sanitize_email($s){return $s;}
function wp_safe_redirect($u){throw new Exception('redirect:'.$u);} function get_current_user_id(){return 1;} function get_transient($k){return $GLOBALS['tr'][$k]??false;} function set_transient($k,$v){$GLOBALS['tr'][$k]=$v;} function delete_transient($k){unset($GLOBALS['tr'][$k]);} function wp_is_mobile(){return false;} function get_users(){return [];} function add_query_arg(...$a){return is_array($a[0])?$a[1]."?".http_build_query($a[0]):$a[2]."?".$a[0]."=".$a[1];}
function wp_verify_nonce(){return true;} function wp_nonce_field(){return '';} function esc_js($s){return $s;} function esc_textarea($s){return $s;} function selected($a,$b,$e=true){return '';} function checked($a,$b,$e=true){return '';} function submit_button(){}
require_once __DIR__.'/../includes/class-mv-admin.php'; require_once __DIR__.'/../includes/class-mv-soll.php';
global $wpdb;
$wpdb->insert('wp_mv_objekte',['owner_id'=>1,'bezeichnung'=>'Haus','heizung'=>'zentral']);
$wpdb->insert('wp_mv_einheiten',['objekt_id'=>1,'bezeichnung'=>'EG','flaeche'=>60,'typ'=>'wohnung']);
$wpdb->insert('wp_mv_mieter',['owner_id'=>1,'vorname'=>'Anna','nachname'=>'Meier']);
$wpdb->insert('wp_mv_mietverhaeltnisse',['einheit_id'=>1,'mieter_id'=>1,'beginn'=>date('Y-m-01',strtotime('-2 months')),'personen'=>2,'zahltag'=>3,'kaution'=>0]);
$wpdb->insert('wp_mv_konditionen',['mv_id'=>1,'gueltig_ab'=>date('Y-m-01',strtotime('-2 months')),'kaltmiete'=>650,'bk_vz'=>120,'hk_vz'=>90]);
$m0=date('Y-m-01',strtotime('-2 months')); $m1=date('Y-m-01',strtotime('-1 month')); $m2=date('Y-m-01');
$wpdb->insert('wp_mv_zahlungen',['mv_id'=>1,'datum'=>$m1,'betrag'=>500,'monat'=>$m1,'quelle'=>'manuell']); // Teilzahlung
$_POST=['mietv_op_nonce'=>'x','op'=>["1|$m0","1|$m1","1|$m2","1|2020-01-01","9|$m0"]];
try{ Mietv_Admin::handle_op_buchen(); }catch(Exception $e){ echo $e->getMessage()."\n"; }
foreach($wpdb->get_results("SELECT datum,betrag,monat,quelle,verwendungszweck FROM wp_mv_zahlungen ORDER BY monat") as $z) printf("%s %7.2f %s %-9s %s\n",$z->datum,$z->betrag,$z->monat,$z->quelle,$z->verwendungszweck);
$op=Mietv_Soll::op_liste(Mietv_Soll::alle_mv()[0]); printf("Saldo danach: %.2f (0 erwartet)\n",$op['saldo']);
// Doppelt abhaken darf nichts buchen
$_POST=['mietv_op_nonce'=>'x','op'=>["1|$m0"]]; try{ Mietv_Admin::handle_op_buchen(); }catch(Exception $e){ echo $e->getMessage()."\n"; }
echo "Zahlungen gesamt: ".$wpdb->get_var("SELECT COUNT(*) FROM wp_mv_zahlungen")." (4 erwartet)\n";
