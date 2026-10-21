<?php
require __DIR__.'/stub.php';
function current_user_can(){return true;} function check_admin_referer(){return true;} function wp_die($m){throw new Exception('DIE:'.$m);}
function sanitize_text_field($s){return trim(strip_tags((string)$s));} function sanitize_textarea_field($s){return $s;} function sanitize_key($s){return preg_replace('/[^a-z0-9_]/','',strtolower($s));} function wp_unslash($s){return $s;} function sanitize_email($s){return $s;}
function wp_safe_redirect($u){throw new Exception('redirect:'.$u);} function get_current_user_id(){return 1;} function get_transient($k){return $GLOBALS['tr'][$k]??false;} function set_transient($k,$v){$GLOBALS['tr'][$k]=$v;} function delete_transient($k){unset($GLOBALS['tr'][$k]);} function wp_is_mobile(){return false;} function get_users(){return [];} function add_query_arg(...$a){return is_array($a[0])?$a[1]."?".http_build_query($a[0]):$a[2]."?".$a[0]."=".$a[1];} function remove_query_arg($a){return '/portal';}
function wp_verify_nonce(){return true;} function wp_mail(){return true;} function is_user_logged_in(){return true;} function wp_nonce_field(){return '';} function submit_button(){} function selected($a,$b,$e=true){return '';} function wp_tempnam($n){return tempnam('/tmp','mv');} function get_permalink(){return '/portal';}
foreach(['class-mv-admin','class-mv-pdf','class-mv-brief','class-mv-kaution','class-mv-abrechnung','class-mv-bank','class-mv-erhoehung','class-mv-mietvertrag','class-mv-portal','class-mv-afa'] as $c) require_once __DIR__."/../includes/$c.php";
global $wpdb;
$wpdb->insert('wp_mv_objekte',['owner_id'=>1,'bezeichnung'=>'Haus','strasse'=>'Weg 1','plz'=>'90513','ort'=>'Zirndorf','heizung'=>'zentral','hk_verbrauchsanteil'=>70]);
$wpdb->insert('wp_mv_einheiten',['objekt_id'=>1,'bezeichnung'=>'EG','flaeche'=>60,'typ'=>'wohnung']);
$wpdb->insert('wp_mv_mieter',['owner_id'=>1,'vorname'=>'Anna','nachname'=>'Meier','email'=>'a@b.de','wp_user_id'=>1]);
$wpdb->insert('wp_mv_mietverhaeltnisse',['einheit_id'=>1,'mieter_id'=>1,'beginn'=>'2025-01-01','personen'=>2,'zahltag'=>3,'kaution'=>1950]);
$wpdb->insert('wp_mv_konditionen',['mv_id'=>1,'gueltig_ab'=>'2025-01-01','kaltmiete'=>650,'bk_vz'=>120,'hk_vz'=>90]);
$wpdb->insert('wp_mv_kostenarten',['owner_id'=>1,'name'=>'Grundsteuer','anlage_v'=>'grundsteuer','umlagefaehig'=>1,'schluessel'=>'flaeche']);
$wpdb->insert('wp_mv_belege',['owner_id'=>1,'objekt_id'=>1,'kostenart_id'=>1,'datum'=>'2025-03-01','steuerjahr'=>2025,'betrag'=>500,'zeitraum_von'=>'2025-01-01','zeitraum_bis'=>'2025-12-31']);
$wpdb->insert('wp_mv_zaehler',['objekt_id'=>1,'einheit_id'=>1,'typ'=>'kaltwasser','nummer'=>'K1']);
$wpdb->insert('wp_mv_zaehlerstaende',['zaehler_id'=>1,'datum'=>'2025-01-01','stand'=>100]);
function run($label,$fn,$check){ try{ $fn(); $r='(kein Redirect)'; }catch(Exception $e){ $r=$e->getMessage(); } $res=$check(); printf("%-26s %s  %s\n",$label,$res?'OK ':'ERR',$res?'':$r); }

// 1 Einstellungen
$_POST=['mv_einstellungen_nonce'=>'x','vermieter_name'=>'Markus Hirn','vermieter_adresse'=>"Im Pinderpark 2\n90513 Zirndorf",'vermieter_iban'=>'DE02120300000000202051','erinnerung_aktiv'=>'1','aufbewahrung_jahre'=>'10','angespannter_markt'=>'0','mahn_tage_1'=>'7','mahn_tage_2'=>'10','mahn_tage_3'=>'7','loeschen_bei_deinstallation'=>'0']; $_FILES=[];
run('Einstellungen', fn()=>MV_Einstellungen::handle(), fn()=>MV_Einstellungen::get('vermieter_name')==='Markus Hirn');
// 2 Kaution: Buchung + Zins
$_POST=['mv_kaution_nonce'=>'x','mv_id'=>'1','datum'=>'2025-01-05','art'=>'einzahlung','betrag'=>'650,00','notiz'=>'1. Rate'];
run('Kaution Buchung', fn()=>MV_Kaution::handle(), fn()=>(float)$wpdb->get_var("SELECT SUM(betrag) FROM wp_mv_kaution")==650);
$_POST=['mv_kaution_nonce'=>'x','mv_id'=>'1','zins_berechnen'=>'1','zinssatz'=>'1,00','stichtag'=>'2025-12-31'];
run('Kaution Zins', fn()=>MV_Kaution::handle(), fn()=>$wpdb->get_var("SELECT COUNT(*) FROM wp_mv_kaution WHERE art='zins'")==1 && abs($wpdb->get_var("SELECT betrag FROM wp_mv_kaution WHERE art='zins'")-650*0.01*360/365)<0.02);
// 3 Abrechnung anlegen + berechnen + fertig
$_POST=['mv_abr_action'=>'neu','objekt_id'=>'1','jahr'=>'2025','von'=>'2025-01-01','bis'=>'2025-12-31']; $_REQUEST=$_POST;
run('Abrechnung neu', fn()=>MV_Abrechnung::handle(), fn()=>$wpdb->get_var("SELECT COUNT(*) FROM wp_mv_abrechnung_ergebnis")==1 && abs($wpdb->get_var("SELECT kosten_bk FROM wp_mv_abrechnung_ergebnis")-500)<0.01);
$_REQUEST=['mv_abr_action'=>'fertig','id'=>'1']; $_GET=$_REQUEST; $_POST=[];
run('Abrechnung freigeben', fn()=>MV_Abrechnung::handle(), fn()=>$wpdb->get_var("SELECT freigegeben FROM wp_mv_abrechnung_ergebnis")==1);
$_REQUEST=['mv_abr_action'=>'vz_anpassen','id'=>'1','erg'=>'1']; $_GET=$_REQUEST;
run('Abrechnung VZ anpassen', fn()=>MV_Abrechnung::handle(), fn()=>$wpdb->get_var("SELECT COUNT(*) FROM wp_mv_konditionen")==2);
// 4 Bankimport Übernahme
$rows=MV_Bank::vorschlaege(MV_Bank::parse("Buchungstag;Betrag;Verwendungszweck;Beguenstigter/Zahlungspflichtiger;IBAN\n03.02.2025;860,00;Miete 02/2025;Anna Meier;DE1\n","x.csv"));
set_transient('mv_bank_1',$rows);
$_POST=['mv_bank_nonce'=>'x','mv_bank_uebernehmen'=>'1','zeile'=>[0=>['ok'=>'1','typ'=>'zahlung','mv_id'=>'1','monat'=>'2025-02']]]; $_FILES=[];
run('Bankimport Zahlung', fn()=>MV_Bank::handle(), fn()=>$wpdb->get_var("SELECT COUNT(*) FROM wp_mv_zahlungen WHERE quelle='bank' AND monat='2025-02-01'")==1 && $wpdb->get_var("SELECT COUNT(*) FROM wp_mv_bank_import")==1);
// 5 Erhöhung (Vergleichsmiete, Sperrfrist erfüllt: Beginn 2025-01-01, Zugang 2026-02-01)
$GLOBALS['opt']['mv_einstellungen']['angespannter_markt']='0';
$_POST=['mv_erh_nonce'=>'x','uebernehmen'=>'1','mv_id'=>'1','art'=>'vergleich','zugang'=>'2026-02-01','flaeche'=>'60','vergleich_qm'=>'12','neu'=>'','begruendung'=>'Mietspiegel'];
run('Mieterhöhung speichern', fn()=>MV_Erhoehung::handle(), fn()=>$wpdb->get_var("SELECT COUNT(*) FROM wp_mv_erhoehungen")==1 && $wpdb->get_var("SELECT neu FROM wp_mv_erhoehungen")==720 && $wpdb->get_var("SELECT COUNT(*) FROM wp_mv_konditionen")==3);
// 6 Vertragsoptionen
$_POST=['mv_vertrag_nonce'=>'x','mv_id'=>'1','raeume'=>'3 Zimmer','mietart'=>'standard','kaution_raten'=>'3','kleinrep_einzel'=>'120','kleinrep_jahr'=>'8','schoenheitsrep'=>'renoviert','tiere'=>'erlaubnis','untervermietung'=>'erlaubnis','kuendigungsverzicht'=>'0','rauchen'=>'nein','hausordnung'=>'1'];
run('Vertragsoptionen', fn()=>MV_Mietvertrag::handle(), fn()=>$wpdb->get_var("SELECT COUNT(*) FROM wp_mv_vertraege")==1 && str_contains($wpdb->get_var("SELECT optionen FROM wp_mv_vertraege"),'3 Zimmer'));
// 7 Portal Meldung
$_POST=['mv_meldung_nonce'=>'x','mv_id'=>'1','betreff'=>'Heizung kalt','text'=>'seit gestern'];
run('Portal Meldung', fn()=>MV_Portal::handle_meldung(), fn()=>$wpdb->get_var("SELECT COUNT(*) FROM wp_mv_meldungen WHERE status='offen'")==1);
// 8 Portal Zählerstand
$_POST=['mv_zaehler_nonce'=>'x','zaehler_id'=>'1','stand'=>'123,4','datum'=>date('Y-m-d')]; $_FILES=[];
run('Portal Zählerstand', fn()=>MV_Portal::handle_zaehler(), fn()=>$wpdb->get_var("SELECT COUNT(*) FROM wp_mv_zaehlerstaende WHERE quelle='mieter' AND stand=123.4")==1);
// 9 Steuer Zeilen
$_POST=['mv_zeilen_nonce'=>'x','jahr'=>'2025','zeile'=>['afa'=>'33','grundsteuer'=>'46']];
require_once __DIR__."/../includes/class-mv-steuer.php";
run('Anlage-V-Zeilen', fn()=>MV_Steuer::zeilen_speichern(), fn()=>(MV_Steuer::zeilen(2025)['afa']??'')==='33');
