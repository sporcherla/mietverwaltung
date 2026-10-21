<?php
define('ABSPATH', '/'); 
function mietv_table($n){return $n;}
require __DIR__.'/../includes/class-mv-afa.php';
$g = (object)['typ'=>'gebaeude','anschaffung'=>'2021-04-15','ak'=>300000,'nebenkosten'=>30000,'grundanteil'=>25,'methode'=>'linear','satz'=>2,'nutzungsdauer'=>null,'ende'=>null];
foreach([2020,2021,2022,2023] as $j){ $r=Mietv_Afa::jahr($g,$j); printf("%d linear: AfA %.2f Rest %.2f Monate %d\n",$j,$r['afa'],$r['restwert'],$r['monate']); }
$d = (object)['typ'=>'gebaeude','anschaffung'=>'2024-10-01','ak'=>400000,'nebenkosten'=>0,'grundanteil'=>20,'methode'=>'degressiv','satz'=>5,'nutzungsdauer'=>null,'ende'=>null];
foreach([2024,2025,2026] as $j){ $r=Mietv_Afa::jahr($d,$j); printf("%d degressiv: AfA %.2f Rest %.2f\n",$j,$r['afa'],$r['restwert']); }
$k = (object)['typ'=>'beweglich','anschaffung'=>'2022-07-01','ak'=>6000,'nebenkosten'=>0,'grundanteil'=>0,'methode'=>'linear','satz'=>0,'nutzungsdauer'=>10,'ende'=>'2025-03-31'];
foreach([2022,2023,2025,2026] as $j){ $r=Mietv_Afa::jahr($k,$j); printf("%d küche: AfA %.2f Rest %.2f Monate %d\n",$j,$r['afa'],$r['restwert'],$r['monate']); }
