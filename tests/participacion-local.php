<?php
// php tests/participacion-local.php
// Run production aggregation and validation with a controlled database adapter.
define('ARRAY_A','ARRAY_A');
function wp_send_json_error($data){ throw new RuntimeException($data['message']); }
$source=file_get_contents(__DIR__.'/../kol-rrhh.php');
$start=strpos($source,'private function participacion_local_mes(');
$end=strpos($source,'public function ajax_get_participacion_total(', $start);
$guardStart=strpos($source,'    $participationTotals=$this->participacion_local_mes(');
$guardEnd=strpos($source,'  } catch(Exception $e)', $guardStart);
eval('class ParticipationHarness {'.substr($source,$start,$end-$start).
 'private function sueldos_items_table(){return "wp_sueldos";}'.
 'public function validate($area,$periodo_inicio,$id,$participacion){'.substr($source,$guardStart,$guardEnd-$guardStart).'} }');
class ParticipationDatabase {
 public $last_error='';
 public $rows=[];
 public function prepare($query,...$args){
   if(strpos($query,'area = %s AND periodo_inicio >= %s AND periodo_inicio < %s')===false) throw new Exception('Missing area/month bounds');
   if(strpos($query,'CASE WHEN id <> %d')===false) throw new Exception('Missing edit exclusion');
   return $args;
 }
 public function get_row($args,$mode){
   list($exclude,$area,$from,$until)=$args;
   $total=0;$other=0;
   foreach($this->rows as $r) if($r[1]===$area && $r[2]>=$from && $r[2]<$until){
     $total+=$r[3];if($r[0]!==$exclude)$other+=$r[3];
   }
   return ['total'=>$total,'others'=>$other];
 }
}
$wpdb=new ParticipationDatabase();
$wpdb->rows=[[1,'Dep','2026-09-01',0.40],[2,'Dep','2026-09-15',0.20],[3,'Otro','2026-09-01',1],[4,'Dep','2026-08-01',1],[5,'Dep','2026-10-01',1]];
$h=new ParticipationHarness();
function accepts($label,$area,$date,$id,$part,$expected){
 global $h;
 $accepted=true;
 try{$h->validate($area,$date,$id,$part);}catch(Exception $e){$accepted=false;}
 if($accepted!==$expected)throw new Exception('FAIL: '.$label);
 echo 'PASS: '.$label."\n";
}
accepts('Exactly 1 across employees','Dep','2026-09-20',0,.40,true);
accepts('Reject excess','Dep','2026-09-20',0,.45,false);
accepts('Editing excludes previous participation','Dep','2026-09-01',1,.80,true);
accepts('Editing still enforces limit','Dep','2026-09-01',1,.85,false);
accepts('Different local is independent','Nuevo','2026-09-01',0,1,true);
accepts('Different month is independent','Dep','2026-11-01',0,1,true);
accepts('Moving into a full month','Dep','2026-10-01',1,.05,false);
$wpdb->last_error='Query failure';
accepts('Database failure blocks save','Dep','2026-09-01',0,.10,false);
