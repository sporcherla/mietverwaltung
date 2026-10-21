<?php
require __DIR__.'/stub.php';
function current_user_can(){return true;} function check_admin_referer(){return true;} function wp_die($m){throw new Exception('DIE:'.$m);}
function sanitize_text_field($s){return trim(strip_tags((string)$s));} function sanitize_textarea_field($s){return $s;} function sanitize_key($s){return preg_replace('/[^a-z0-9_]/','',strtolower($s));} function wp_unslash($s){return $s;} function sanitize_email($s){return $s;}
function wp_safe_redirect($u){throw new Exception('redirect:'.$u);} function get_current_user_id(){return 1;} function get_transient($k){return $GLOBALS['tr'][$k]??false;} function set_transient($k,$v){$GLOBALS['tr'][$k]=$v;} function delete_transient($k){unset($GLOBALS['tr'][$k]);} function wp_is_mobile(){return false;} function get_users(){return [];} function add_query_arg(...$a){return is_array($a[0])?$a[1]."?".http_build_query($a[0]):$a[2]."?".$a[0]."=".$a[1];}
function checked($a,$b,$e=true){return $a==$b?' checked':'';} function wp_verify_nonce(){return true;} function sanitize_file_name($s){return $s;} function nocache_headers(){} function esc_textarea($s){return $s;} function selected($a,$b,$e=true){return '';} function wp_nonce_field(){return '';} function submit_button(){}
require_once __DIR__.'/../includes/class-mv-admin.php';
global $wpdb;
$wpdb->insert('wp_mv_objekte',['owner_id'=>1,'bezeichnung'=>'Haus','strasse'=>'Weg 1','plz'=>'90513','ort'=>'Zirndorf','heizung'=>'zentral']);
$wpdb->insert('wp_mv_einheiten',['objekt_id'=>1,'bezeichnung'=>'Wohnung EG','flaeche'=>60,'typ'=>'wohnung']);
$wpdb->insert('wp_mv_einheiten',['objekt_id'=>1,'bezeichnung'=>'Garage 1','flaeche'=>0,'typ'=>'garage']);
$wpdb->insert('wp_mv_einheiten',['objekt_id'=>1,'bezeichnung'=>'Wohnung OG','flaeche'=>50,'typ'=>'wohnung']);
$wpdb->insert('wp_mv_mieter',['owner_id'=>1,'anrede'=>'Frau','vorname'=>'Anna','nachname'=>'Meier']);
$wpdb->insert('wp_mv_mieter',['owner_id'=>1,'anrede'=>'Herr','vorname'=>'Bert','nachname'=>'Huber']);
function save($post){ $_POST=$post; $_FILES=[]; try{ Mietv_Admin::handle_actions(); }catch(Exception $e){ $r=$e->getMessage(); } $err=$GLOBALS['tr']['mietv_errors_1']??[]; unset($GLOBALS['tr']['mietv_errors_1']); return [$r,$err]; }
// 1 Wohnung EG + Garage 1 (50 €)
[$r,$err]=save(['mietv_entity'=>'mietverhaeltnisse','mietv_record_id'=>'0','einheit_id'=>'1','mieter_id'=>'1','beginn'=>'2026-01-01','ende'=>'','kaution'=>'1950','personen'=>'2','zahltag'=>'3','notiz'=>'','zusatzeinheiten_on'=>[2=>'1'],'zusatzeinheiten'=>[2=>'50,00',3=>'']]);
printf("MV mit Garage: %s %s | zusatz=%d anteil=%s\n", str_contains($r,'saved')?'OK':'ERR', implode('|',$err), $wpdb->get_var("SELECT COUNT(*) FROM wp_mv_mv_einheiten"), $wpdb->get_var("SELECT mietanteil FROM wp_mv_mv_einheiten"));
require_once __DIR__.'/../includes/class-mv-soll.php';
echo "Label: ".Mietv_Soll::mietv_label(Mietv_Soll::alle_mv()[0])."\n";
// 2 Zweiter Mieter will dieselbe Garage → Kollision
[$r,$err]=save(['mietv_entity'=>'mietverhaeltnisse','mietv_record_id'=>'0','einheit_id'=>'3','mieter_id'=>'2','beginn'=>'2026-03-01','ende'=>'','kaution'=>'0','personen'=>'1','zahltag'=>'3','notiz'=>'','zusatzeinheiten_on'=>[2=>'1'],'zusatzeinheiten'=>[2=>'']]);
printf("Garage doppelt: %s (%s)\n", $err?'korrekt abgelehnt':'FEHLER: angenommen', implode('|',$err));
// 3 Haupteinheit = Zusatz → Fehler
[$r,$err]=save(['mietv_entity'=>'mietverhaeltnisse','mietv_record_id'=>'0','einheit_id'=>'3','mieter_id'=>'2','beginn'=>'2026-03-01','ende'=>'','kaution'=>'0','personen'=>'1','zahltag'=>'3','notiz'=>'','zusatzeinheiten_on'=>[3=>'1'],'zusatzeinheiten'=>[3=>'']]);
printf("Haupt=Zusatz: %s\n", $err?'korrekt abgelehnt':'FEHLER');
// 4 Update: Garage abwählen
[$r,$err]=save(['mietv_entity'=>'mietverhaeltnisse','mietv_record_id'=>'1','einheit_id'=>'1','mieter_id'=>'1','beginn'=>'2026-01-01','ende'=>'','kaution'=>'1950','personen'=>'2','zahltag'=>'3','notiz'=>'','zusatzeinheiten'=>[2=>'50']]);
printf("Update ohne Garage: %s zusatz=%d\n", str_contains($r,'saved')?'OK':'ERR', $wpdb->get_var("SELECT COUNT(*) FROM wp_mv_mv_einheiten"));
// 5 Vertragstext mit Garage
save(['mietv_entity'=>'mietverhaeltnisse','mietv_record_id'=>'1','einheit_id'=>'1','mieter_id'=>'1','beginn'=>'2026-01-01','ende'=>'','kaution'=>'1950','personen'=>'2','zahltag'=>'3','notiz'=>'','zusatzeinheiten_on'=>[2=>'1'],'zusatzeinheiten'=>[2=>'50']]);
$wpdb->insert('wp_mv_konditionen',['mv_id'=>1,'gueltig_ab'=>'2026-01-01','kaltmiete'=>700,'bk_vz'=>120,'hk_vz'=>90]);
$GLOBALS['opt']['mietv_einstellungen']=['vermieter_name'=>'Markus Hirn','vermieter_adresse'=>"Im Pinderpark 2\n90513 Zirndorf"];
foreach(['class-mv-einstellungen','class-mv-pdf','class-mv-brief','class-mv-mietvertrag'] as $c) require_once __DIR__."/../includes/$c.php";
$_GET=['mv_id'=>1,'format'=>'html']; ob_start(); try{ Mietv_Mietvertrag::drucken(); }catch(Exception $e){} $html=ob_get_clean(); echo "Vertrag gerendert: ".strlen($html)." Zeichen\n";
foreach(['Garage Garage 1','insgesamt <strong>700,00 €</strong>','650,00 € auf die Wohnung','50,00 € Garage Garage 1','nur gemeinsam gekündigt'] as $needle) printf("Vertrag enthält „%s“: %s\n",$needle,str_contains($html,$needle)?'ja':'NEIN');
