<?php
require_once __DIR__.'/../includes/helpers.php';require_role(['ADMINISTRADOR','SUPERVISOR','REPRESENTANTE','DEALER']);
header('Content-Type: application/json; charset=utf-8');$pdo=db();
try {
 csrf_check();
 $uid=(int)user()['id']; $role=user()['rol']; $loc=current_localidad_id();
 // Las acciones manuales cuentan como actividad; la asignación automática no renueva el tiempo de inactividad.
 // Importante: la antigüedad del ticket NO participa en la validación de sesión.
 // Primero comprobamos el token actual y luego renovamos la actividad de esta sesión.
 if (in_array($role,['REPRESENTANTE','DEALER'],true) && $loc===null) throw new Exception('Este usuario no tiene una localidad asignada.');
 if (in_array($role,['REPRESENTANTE','DEALER'],true)) {
   $ss=$pdo->prepare("SELECT sesion_activa,session_token FROM usuarios WHERE id=? AND activo=1 LIMIT 1");
   $ss->execute([$uid]); $sr=$ss->fetch();
   $currentToken=(string)($_SESSION['session_token']??'');
   if (!$sr || !(int)$sr['sesion_activa'] || $currentToken==='' || empty($sr['session_token']) || !hash_equals((string)$sr['session_token'],$currentToken)) {
      throw new Exception('Tu sesión fue reemplazada o ya no está activa. Inicia sesión nuevamente.');
   }
   // La acción actual cuenta como actividad. No usamos la fecha de creación del ticket
   // ni una ultima_actividad antigua almacenada en BD para decidir si esta petición expira.
   if (($a=$_POST['accion']??'') !== 'siguiente_auto') {
      if (!touch_user_activity()) throw new Exception('Tu sesión fue reemplazada o ya no está activa. Inicia sesión nuevamente.');
   }
   $lastActivity=(int)($_SESSION['last_activity']??time());
   if ((time()-$lastActivity)>=SESSION_IDLE_TIMEOUT) {
      end_staff_session($uid);
      throw new Exception('Tu sesión expiró por inactividad. Inicia sesión nuevamente.');
   }
 } else {
   if (($a=$_POST['accion']??'') !== 'siguiente_auto') touch_user_activity();
 }
 if ($a==='siguiente' || $a==='siguiente_auto') {
   // Un usuario puede mantener su sesión activa y, al mismo tiempo, quedar temporalmente
   // fuera de la cola. En ese estado no puede recibir nuevos turnos.
   if (in_array($role,['REPRESENTANTE','DEALER'],true)) {
      $avail=$pdo->prepare("SELECT estado,motivo FROM estados_personal WHERE usuario_id=? AND activo=1 ORDER BY inicio DESC LIMIT 1");
      $avail->execute([$uid]); $currentAvailability=$avail->fetch();
      if ($currentAvailability && ($currentAvailability['estado']??'DISPONIBLE')!=='DISPONIBLE') {
         $mot=(string)($currentAvailability['motivo']??'Pausa operativa');
         if ($a==='siguiente_auto') { echo json_encode(['ok'=>true,'waiting'=>false,'paused'=>true,'motivo'=>$mot]); exit; }
         throw new Exception('No estás recibiendo clientes. Motivo: '.$mot.'. Vuelve a activar la recepción de clientes para llamar un nuevo turno.');
      }
   }
   // If the representative already has a turn, do not claim another one.
   $cur=$pdo->prepare("SELECT t.*,s.nombre servicio,e.nombre estacion FROM turnos t JOIN servicios s ON s.id=t.servicio_id LEFT JOIN estaciones e ON e.id=t.estacion_id WHERE t.atendiente_id=? AND t.estado='ATENDIENDO' ORDER BY t.hora_llamado DESC LIMIT 1");
   $cur->execute([$uid]);$existing=$cur->fetch();
   if ($existing) {echo json_encode(['ok'=>true,'existing'=>true,'codigo'=>ticket_code($existing['prefijo'],(int)$existing['numero']),'id'=>(int)$existing['id']]);exit;}
   $est=(int)(user()['estacion_id']??0);if (!$est) throw new Exception('Este usuario no tiene una estación asignada.');
   $es=$pdo->prepare("SELECT localidad_id FROM estaciones WHERE id=? AND activo=1");$es->execute([$est]);$estLoc=$es->fetchColumn();
   if ($loc!==null && (int)$estLoc !== $loc) throw new Exception('La estación asignada pertenece a otra localidad.');
   $pdo->beginTransaction();
   $sql="SELECT t.* FROM turnos t WHERE t.fecha=? AND t.estado='ESPERANDO'";
   $params=[date('Y-m-d')];
   if ($loc!==null) {$sql.=" AND t.localidad_id=?";$params[]=$loc;}
   if ($role==='REPRESENTANTE') {
      $sql .= " AND EXISTS(SELECT 1 FROM servicios sx WHERE sx.id=t.servicio_id AND sx.destino IN ('REPRESENTANTE','AMBOS'))";
   } elseif ($role==='DEALER') {
      $sql .= " AND EXISTS(SELECT 1 FROM servicios sx WHERE sx.id=t.servicio_id AND sx.destino IN ('DEALER','AMBOS'))";
   }
   $sql.=" ORDER BY t.prioridad DESC,t.hora_creacion ASC LIMIT 1 FOR UPDATE";
   $st=$pdo->prepare($sql);$st->execute($params);$t=$st->fetch();
   if (!$t) {$pdo->commit(); if ($a==='siguiente_auto') {echo json_encode(['ok'=>true,'waiting'=>false]);exit;} throw new Exception('No hay clientes en espera para este usuario.');}
   $up=$pdo->prepare("UPDATE turnos SET estado='ATENDIENDO',estacion_id=?,atendiente_id=?,hora_llamado=NOW(),hora_inicio=NOW(),llamado_seq=1 WHERE id=? AND estado='ESPERANDO'");$up->execute([$est,$uid,$t['id']]);$pdo->commit();
   echo json_encode(['ok'=>true,'existing'=>false,'waiting'=>true,'id'=>(int)$t['id'],'codigo'=>ticket_code($t['prefijo'],$t['numero']),'servicio'=>$t['servicio_id']]);exit;
 }
 $id=(int)($_POST['id']??0);$st=$pdo->prepare("SELECT * FROM turnos WHERE id=? AND atendiente_id=?");$st->execute([$id,$uid]);$t=$st->fetch();if (!$t)throw new Exception('Turno no encontrado.');
 if ($a==='completar') {
   // Al completar se guardan automáticamente las notas y clasificación de la atención.
   $cid=(int)($_POST['clasificacion']??0);
   if ($cid<=0) throw new Exception('Debes seleccionar una clasificación antes de completar el ticket.');
   $cs=$pdo->prepare("SELECT id,nombre FROM clasificaciones WHERE id=? AND activo=1 LIMIT 1");
   $cs->execute([$cid]); $classification=$cs->fetch();
   if (!$classification) throw new Exception('La clasificación seleccionada no es válida.');
   $notes=trim($_POST['notas']??'');
   $reason=trim($_POST['motivo_cierre']??'');
   if ($reason==='') $reason=(string)$classification['nombre'];
   $pdo->prepare("UPDATE turnos SET clasificacion_id=?,notas=?,motivo_cierre=?,estado='FINALIZADO',hora_finalizacion=NOW() WHERE id=?")->execute([$cid,$notes,$reason,$id]);
 }
 elseif ($a==='ausente') {$pdo->prepare("UPDATE turnos SET estado='AUSENTE',motivo_cierre='Cliente ausente',hora_finalizacion=NOW() WHERE id=?")->execute([$id]);}
 elseif ($a==='repetir') {$pdo->prepare("UPDATE turnos SET hora_llamado=NOW(),llamado_seq=COALESCE(llamado_seq,0)+1 WHERE id=?")->execute([$id]);}
 elseif ($a==='transferir') {
   $sid=(int)($_POST['servicio_id']??0);$sv=$pdo->prepare("SELECT id FROM servicios WHERE id=? AND activo=1");$sv->execute([$sid]);if (!$sv->fetchColumn())throw new Exception('Servicio de destino no válido.');
   $pdo->prepare("UPDATE turnos SET servicio_id=?,estado='ESPERANDO',atendiente_id=NULL,estacion_id=NULL,hora_llamado=NULL,hora_inicio=NULL,llamado_seq=0 WHERE id=?")->execute([$sid,$id]);
 }
 elseif ($a==='guardar') {$cid=(int)($_POST['clasificacion']??0);$notes=trim($_POST['notas']??'');$pdo->prepare("UPDATE turnos SET clasificacion_id=?,notas=? WHERE id=?")->execute([$cid?:null,$notes,$id]);}
 else throw new Exception('Acción no válida.');
 echo json_encode(['ok'=>true]);
}catch (Throwable $e) {if ($pdo->inTransaction())$pdo->rollBack();http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
