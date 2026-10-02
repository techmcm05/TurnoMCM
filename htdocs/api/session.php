<?php
require_once __DIR__.'/../includes/helpers.php';
header('Content-Type: application/json; charset=utf-8');
if (!is_logged()) {http_response_code(401);echo json_encode(['ok'=>false,'logged'=>false]);exit;}
try {
 $uid=(int)user()['id']; $role=user()['rol']??''; $isMonitor=$role==='MONITOR';
 $st=db()->prepare("SELECT u.activo,u.sesion_activa,u.ultima_actividad,u.session_token FROM usuarios u WHERE u.id=? LIMIT 1");$st->execute([$uid]);$row=$st->fetch();
 $token=$_SESSION['session_token']??'';
 if (!$row || !(int)$row['activo'] || !(int)$row['sesion_activa'] || empty($token) || empty($row['session_token']) || !hash_equals((string)$row['session_token'],(string)$token)) {
   $_SESSION=[];session_destroy();http_response_code(401);echo json_encode(['ok'=>false,'logged'=>false,'replaced'=>true]);exit;
 }
 $last=(int)($_SESSION['last_activity']??0);if ($last<=0 && !empty($row['ultima_actividad']))$last=strtotime($row['ultima_actividad']);
 if (!$isMonitor && $last>0 && time()-$last>=SESSION_IDLE_TIMEOUT) {end_staff_session($uid);$_SESSION=[];session_destroy();http_response_code(401);echo json_encode(['ok'=>false,'logged'=>false,'timeout'=>true]);exit;}
 if (!$isMonitor && in_array($role,['REPRESENTANTE','DEALER'],true) && !(int)$row['sesion_activa']) {$_SESSION=[];session_destroy();http_response_code(401);echo json_encode(['ok'=>false,'logged'=>false]);exit;}
 if (($_POST['action']??'')==='touch') {
   if (!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')) {http_response_code(419);echo json_encode(['ok'=>false]);exit;}
   if (!touch_user_activity()) {
     $_SESSION=[]; session_destroy(); http_response_code(401); echo json_encode(['ok'=>false,'logged'=>false,'replaced'=>true]); exit;
   }
 } elseif ($isMonitor) {
   if (!touch_user_activity()) {
     $_SESSION=[]; session_destroy(); http_response_code(401); echo json_encode(['ok'=>false,'logged'=>false,'replaced'=>true]); exit;
   }
 }
 echo json_encode(['ok'=>true,'logged'=>true,'monitor'=>$isMonitor,'timeout_seconds'=>$isMonitor?null:SESSION_IDLE_TIMEOUT,'last_activity'=>$_SESSION['last_activity']??$last]);
}catch (Throwable $e) {http_response_code(500);echo json_encode(['ok'=>false,'logged'=>false]);}
