<?php
require __DIR__.'/stub.php';
$menu=[]; function add_menu_page($t,$l,$c,$s,$cb,$icon='',$pos=null){ global $menu; $menu[]="TOP ".$s." icon=".substr($icon,0,30); } function add_submenu_page($p,$t,$l,$c,$s,$cb){ global $menu; $menu[]=strip_tags(preg_replace('/<span class="dashicons dashicons-([a-z-]+)"><\/span>/','[$1]',$l))." ($s)"; }
function current_user_can(){return true;}
require_once __DIR__.'/../includes/class-mv-admin.php';
Mietv_Admin::menu(); echo implode("\n",$menu)."\n";
