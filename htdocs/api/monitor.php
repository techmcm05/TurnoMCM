<?php
require_once __DIR__.'/../includes/helpers.php';require_role(['ADMINISTRADOR','SUPERVISOR','MONITOR','CONSULTA']);
header('Content-Type: application/json; charset=utf-8');
$pdo=db();$today=date('Y-m-d');$loc=current_localidad_id();
$where='';$params=[$today];if ($loc!==null) {$where=' AND t.localidad_id=?';$params[]=$loc;}
$st=$pdo->prepare("SELECT t.id,t.prefijo,t.numero,s.nombre servicio,e.nombre estacion,t.hora_llamado,t.llamado_seq,l.nombre localidad FROM turnos t JOIN servicios s ON s.id=t.servicio_id LEFT JOIN estaciones e ON e.id=t.estacion_id LEFT JOIN localidades l ON l.id=t.localidad_id WHERE t.fecha=? $where AND t.estado IN ('ATENDIENDO','LLAMADO') ORDER BY t.hora_llamado DESC LIMIT 1");
$st->execute($params);$c=$st->fetch();
$params=[$today];if ($loc!==null)$params[]=$loc;
$st=$pdo->prepare("SELECT t.id,t.prefijo,t.numero,s.nombre servicio,e.nombre estacion,DATE_FORMAT(t.hora_llamado,'%h:%i:%s %p') hora,t.llamado_seq FROM turnos t JOIN servicios s ON s.id=t.servicio_id LEFT JOIN estaciones e ON e.id=t.estacion_id WHERE t.fecha=? $where AND t.hora_llamado IS NOT NULL ORDER BY t.hora_llamado DESC LIMIT 8");
$st->execute($params);$h=$st->fetchAll();
if ($c)$c['codigo']=ticket_code($c['prefijo'],(int)$c['numero']);foreach ($h as &$x)$x['codigo']=ticket_code($x['prefijo'],(int)$x['numero']);
echo json_encode(['current'=>$c,'history'=>$h],JSON_UNESCAPED_UNICODE);
