<?php
require_once __DIR__.'/../includes/helpers.php';require_role(['ADMINISTRADOR','SUPERVISOR','GERENTE']);
header('Content-Type: application/json; charset=utf-8');$pdo=db();$d=date('Y-m-d');$out=[];[$scopeWhere,$scopeParams]=visible_localidad_where('t');
foreach (['ESPERANDO','ATENDIENDO','FINALIZADO','AUSENTE','VENCIDO'] as $s) {$sql="SELECT COUNT(*) FROM turnos t WHERE t.fecha=? AND t.estado=? AND $scopeWhere"; $params=array_merge([$d,$s],$scopeParams); $st=$pdo->prepare($sql);$st->execute($params);$out[$s]=(int)$st->fetchColumn();}
echo json_encode($out);
