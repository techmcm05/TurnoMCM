<?php
require_once __DIR__.'/../includes/helpers.php';require_role(['ADMINISTRADOR','SUPERVISOR','RECEPCIONISTA']);
header('Content-Type: application/json; charset=utf-8');
try {
 csrf_check();$sid=(int)($_POST['servicio_id']??0);$pdo=db();
 $loc=current_localidad_id();
 if ($loc===null) throw new Exception('La recepción debe tener una localidad asignada.');
 $st=$pdo->prepare("SELECT * FROM servicios WHERE id=? AND activo=1");$st->execute([$sid]);$s=$st->fetch();
 if (!$s) throw new Exception('Servicio inválido.');
 $pdo->beginTransaction();
 $st=$pdo->prepare("SELECT COALESCE(MAX(numero),0)+1 n FROM turnos WHERE fecha=? AND servicio_id=? AND localidad_id=? FOR UPDATE");$st->execute([date('Y-m-d'),$sid,$loc]);$n=(int)$st->fetch()['n'];
 $st=$pdo->prepare("INSERT INTO turnos(numero,prefijo,servicio_id,localidad_id,fecha,recepcionista_id,estado,llamado_seq) VALUES(?,?,?,?,?,?,'ESPERANDO',0)");
 $st->execute([$n,$s['prefijo'],$sid,$loc,date('Y-m-d'),user()['id']]);$id=(int)$pdo->lastInsertId();$pdo->commit();
 echo json_encode(['ok'=>true,'id'=>$id,'codigo'=>ticket_code($s['prefijo'],$n),'servicio'=>$s['nombre']]);
}catch (Throwable $e) {if (db()->inTransaction())db()->rollBack();http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
