<?php
// php tests/mensual-local.php
$source=file_get_contents(__DIR__.'/../kol-rrhh.php');
$start=strpos($source,'private function group_mensual_local_items(');
$end=strpos($source,'public function ajax_get_mensual_local(', $start);
eval('class MonthlyTest {'.str_replace('private function','public function',substr($source,$start,$end-$start)).'}');
function check($ok,$label){if(!$ok)throw new Exception($label);echo 'PASS: '.$label."\n";}
$items=[
 ['id'=>1,'legajo'=>10,'nombre'=>'Ana','area'=>'Dep','transferencia'=>100.10,'efectivo'=>200,'creditos'=>30],
 ['id'=>2,'legajo'=>10,'nombre'=>'Ana','area'=>'Dep','transferencia'=>50.20,'efectivo'=>0,'creditos'=>0],
 ['id'=>3,'legajo'=>10,'nombre'=>'Ana','area'=>'Sol','transferencia'=>20,'efectivo'=>0,'creditos'=>0],
 ['id'=>4,'legajo'=>11,'nombre'=>'Juan','area'=>'Dep','transferencia'=>0,'efectivo'=>0,'creditos'=>25],
];
$h=new MonthlyTest();
$rows=$h->group_mensual_local_items($items,[1=>['transferencia'=>1,'efectivo'=>2]]);
check(count($rows)===3,'Group by employee and area');
check($rows[0]['transferencia']==150.30 && $rows[0]['efectivo']==200 && $rows[0]['creditos']==30 && $rows[0]['monto']==380.30,'Separate amounts and exact cents');
check(!$rows[0]['pagos_haberes']['transferencia'],'Partially paid group is not fully paid');
check($rows[0]['pagos_haberes']['efectivo'],'Zero cash item does not prevent fully paid cash');
check(!$rows[1]['pagos_haberes']['transferencia'],'Payment does not spill into another area');
check(!$rows[2]['pagos_haberes']['efectivo'] && !$rows[2]['pagos_haberes']['transferencia'],'Zero amounts are not marked paid');
$rows=$h->group_mensual_local_items($items,[1=>['transferencia'=>1,'efectivo'=>2],2=>['transferencia'=>3]]);
check($rows[0]['pagos_haberes']['transferencia'],'All items paid marks aggregate paid');
$rows=$h->group_mensual_local_items($items,[]);
check(!$rows[0]['pagos_haberes']['efectivo'],'No payment records means unpaid');
check($h->group_mensual_local_items([],[])===[],'Empty month');
