<?php
require_once __DIR__.'/../includes/helpers.php';
require_role(['ADMINISTRADOR','SUPERVISOR','REPRESENTANTE','DEALER']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
try {
 $pdo=db(); $u=user(); $uid=(int)$u['id']; $role=$u['rol']; $loc=current_localidad_id();
 if (!in_array($role,['REPRESENTANTE','DEALER'],true)) {echo json_encode(['ok'=>true,'items'=>[]]);exit;}
 $where="t.fecha=? AND t.estado='ESPERANDO'"; $params=[date('Y-m-d')];
 if ($loc!==null) {$where.=' AND t.localidad_id=?';$params[]=$loc;}
 $avail=$pdo->prepare("SELECT estado FROM estados_personal WHERE usuario_id=? AND activo=1 ORDER BY inicio DESC LIMIT 1");$avail->execute([$uid]);$availability=$avail->fetchColumn();if ($availability && $availability!=='DISPONIBLE') {echo json_encode(['ok'=>true,'items'=>[],'paused'=>true]);exit;}
 if ($role==='REPRESENTANTE') {
   $where .= " AND EXISTS(SELECT 1 FROM servicios sx WHERE sx.id=t.servicio_id AND sx.destino IN ('REPRESENTANTE','AMBOS'))";
 } else {
   $where .= " AND EXISTS(SELECT 1 FROM servicios sx WHERE sx.id=t.servicio_id AND sx.destino IN ('DEALER','AMBOS'))";
 }
 $st=$pdo->prepare("SELECT t.id,t.numero,t.prefijo,t.hora_creacion,s.nombre servicio FROM turnos t JOIN servicios s ON s.id=t.servicio_id WHERE $where ORDER BY t.prioridad DESC,t.hora_creacion ASC LIMIT 5");
 $st->execute($params); $items=[]; foreach ($st->fetchAll() as $r) {$items[]=['codigo'=>ticket_code($r['prefijo'],$r['numero']),'servicio'=>$r['servicio'],'hora_creacion'=>$r['hora_creacion']];}
 echo json_encode(['ok'=>true,'items'=>$items]);
}catch (Throwable $e) {http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
