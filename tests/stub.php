<?php
// Minimaler WordPress-Stub mit SQLite für Rechentests
define('ABSPATH','/'); define('MV_OWNER_ID',1); define('MV_VERSION','test'); define('HOUR_IN_SECONDS',3600);
class WPDB {
  public $prefix='wp_'; public $insert_id=0; private $pdo;
  function __construct(){ $this->pdo=new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);}
  function exec($sql){ $this->pdo->exec($sql); }
  function prepare($q,...$a){ if(is_array($a[0]??null)&&count($a)==1)$a=$a[0]; $q=str_replace(['%d','%s','%f'],['?','?','?'],$q); $i=0; return [$q,$a]; }
  private function run($q){ if(is_array($q)){ [$sql,$a]=$q; $sql=str_replace('CURDATE()',"date('now')",$sql); $st=$this->pdo->prepare($sql); $st->execute($a);} else { $q=str_replace('CURDATE()',"date('now')",$q); $st=$this->pdo->query($q);} return $st; }
  function get_results($q,$t=OBJECT){
    if(is_string($q) && preg_match('/^SHOW COLUMNS FROM `?(\w+)`?/',$q,$m)){ $rows=[]; foreach($this->pdo->query("PRAGMA table_info($m[1])") as $c){ $d=$c['dflt_value']; if($d!==null)$d=trim($d,"'"); $rows[]=['Field'=>$c['name'],'Null'=>$c['notnull']?'NO':'YES','Default'=>$d]; } return $rows; }
    $st=$this->run($q); $rows=$st->fetchAll($t==='ARRAY_A'?PDO::FETCH_ASSOC:PDO::FETCH_OBJ); if($t==='OBJECT_K'){$o=[];foreach($rows as $r)$o[$r->id]=$r;return $o;} return $rows; }
  function get_row($q,$t=OBJECT){ $st=$this->run($q); $r=$st->fetch($t==='ARRAY_A'?PDO::FETCH_ASSOC:PDO::FETCH_OBJ); return $r===false?null:$r; }
  function get_var($q){ $st=$this->run($q); $v=$st->fetchColumn(); return $v===false?null:$v; }
  function get_col($q){ $st=$this->run($q); return $st->fetchAll(PDO::FETCH_COLUMN); }
  public $last_error='';
  function insert($t,$d){ $k=array_keys($d); $sql="INSERT INTO $t (".implode(',',$k).") VALUES (".implode(',',array_fill(0,count($k),'?')).")"; try{ $st=$this->pdo->prepare($sql); $st->execute(array_values($d)); }catch(PDOException $e){ $this->last_error=$e->getMessage(); return false; } $this->insert_id=$this->pdo->lastInsertId(); return true; }
  function replace($t,$d){ return $this->insert($t,$d); }
  function update($t,$d,$w){ $set=implode(',',array_map(fn($k)=>"$k=?",array_keys($d))); $wh=implode(' AND ',array_map(fn($k)=>"$k=?",array_keys($w))); try{ $st=$this->pdo->prepare("UPDATE $t SET $set WHERE $wh"); $st->execute(array_merge(array_values($d),array_values($w))); }catch(PDOException $e){ $this->last_error=$e->getMessage(); return false; } return $st->rowCount(); }
  function delete($t,$w){ $wh=implode(' AND ',array_map(fn($k)=>"$k=?",array_keys($w))); $st=$this->pdo->prepare("DELETE FROM $t WHERE $wh"); $st->execute(array_values($w)); }
  function query($q){ $this->run($q); }
}

define('OBJECT','OBJECT'); define('OBJECT_K','OBJECT_K'); define('ARRAY_A','ARRAY_A');
$wpdb=new WPDB; $GLOBALS['wpdb']=$wpdb;
if ( ! function_exists('__') ) { function __($s,$d=null){return $s;} function esc_html__($s,$d=null){return htmlspecialchars($s);} function esc_attr__($s,$d=null){return htmlspecialchars($s);} function _e($s,$d=null){echo $s;} function esc_html_e($s,$d=null){echo htmlspecialchars($s);} } function _n($a,$b,$n,$d=null){return $n==1?$a:$b;}
function current_time($f){ return $f==='mysql'?date('Y-m-d H:i:s'):date($f); }
function date_i18n($f,$t){ return date($f,$t); }
function wp_json_encode($d,$f=0){ return json_encode($d,$f); }
function get_option($k,$d=false){ return $GLOBALS['opt'][$k]??$d; } function update_option($k,$v){ $GLOBALS['opt'][$k]=$v; }
function add_action(){} function add_shortcode(){} function admin_url($p=''){return '/wp-admin/'.$p;} function wp_next_scheduled(){return true;} function esc_html($s){return htmlspecialchars((string)$s);} function esc_attr($s){return htmlspecialchars((string)$s);} function esc_url($s){return $s;} function wp_nonce_url($u){return $u;} function is_admin(){return false;}
require __DIR__.'/../includes/helpers.php';
require __DIR__.'/../includes/class-mv-db.php';
require __DIR__.'/../includes/class-mv-soll.php';
require __DIR__.'/../includes/class-mv-einstellungen.php';
require __DIR__.'/../includes/class-mv-abrechnung.php';
require __DIR__.'/../includes/class-mv-bank.php';
// Tabellen aus DB-Klasse ableiten: CREATE-Statements extrahieren und für SQLite anpassen
$src=file_get_contents(__DIR__.'/../includes/class-mv-db.php');
preg_match_all('/[\'"]CREATE TABLE [\'"] \. mv_table\( \'(\w+)\' \) \. " \((.*?)\) \$charset;"/s',$src,$m,PREG_SET_ORDER);
foreach($m as $t){ $body=$t[2]; $body=preg_replace('/bigint\(20\) unsigned NOT NULL AUTO_INCREMENT/','INTEGER PRIMARY KEY AUTOINCREMENT',$body); $body=preg_replace('/,\s*PRIMARY KEY\s+\(id\)/','',$body); $body=preg_replace('/,\s*(UNIQUE )?KEY \w+ \([\w,]+\)/','',$body); $body=preg_replace('/\b(bigint|smallint|tinyint|int)\(\d+\)( unsigned)?/','INTEGER',$body); $body=preg_replace('/decimal\(\d+,\d+\)/','REAL',$body); $body=preg_replace('/varchar\(\d+\)/','TEXT',$body); $body=str_replace(['datetime','longtext','char(32)'],['TEXT','TEXT','TEXT'],$body); $wpdb->exec("CREATE TABLE wp_mv_{$t[1]} ($body)"); }
