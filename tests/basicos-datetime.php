<?php
// Run: php tests/basicos-datetime.php
// Exercise the production migration without loading WordPress or touching a DB.
define('ARRAY_A', 'ARRAY_A');
$source=file_get_contents(__DIR__.'/../kol-rrhh.php');
$start=strpos($source, '  private function ensure_basicos_datetime(');
$end=strpos($source, '  private function basicos_current(', $start);
eval('class BasicosMigration {'.substr($source,$start,$end-$start).'}');
class SchemaDatabase {
  public $last_error='';
  public $fail=false;
  public $queries=[];
  public $columns=[];
  public function get_results($sql,$mode){ return $this->columns; }
  public function query($sql){ $this->queries[]=$sql; return $this->fail ? false : 0; }
}
function check($condition,$message){ if(!$condition) throw new Exception($message); }
$wpdb=new SchemaDatabase();
$wpdb->columns=[
 ['Field'=>'vigente_desde','Type'=>'date','Null'=>'NO'],
 ['Field'=>'vigente_hasta','Type'=>'date','Null'=>'YES'],
 ['Field'=>'created_at','Type'=>'datetime','Null'=>'NO'],
];
$method=new ReflectionMethod('BasicosMigration','ensure_basicos_datetime');
$method->setAccessible(true);
$run=function() use ($method){ $method->invoke(new BasicosMigration(), 'wp_kol_rrhh_basicos', ['start'=>'vigente_desde','end'=>'vigente_hasta']); };
$run();
check(count($wpdb->queries)===1,'Expected a single atomic ALTER');
check(strpos($wpdb->queries[0],'`vigente_desde` DATETIME(6) NOT NULL')!==false,'Start precision');
check(strpos($wpdb->queries[0],'`vigente_hasta` DATETIME(6) NULL DEFAULT NULL')!==false,'Nullable end preserved');
check(strpos($wpdb->queries[0],'created_at')===false,'Unrelated columns preserved');
check(strpos($wpdb->queries[0],'DROP')===false,'No index or historical row removal');
foreach($wpdb->columns as &$column) if($column['Type']==='date') $column['Type']='datetime(6)';
unset($column);
$wpdb->queries=[];
$run();
check(!$wpdb->queries,'Migration must be idempotent');
$wpdb->columns[0]['Type']='datetime';
$run();
check(count($wpdb->queries)===1,'Upgrade second-only precision too');
$wpdb->fail=true;
$failed=false;
try { $run(); } catch(Exception $e){ $failed=true; }
check($failed,'Failed DDL must prevent continuing');
echo "PASS: date migration, nullability, index preservation, idempotency, precision upgrade and failure handling.\n";
