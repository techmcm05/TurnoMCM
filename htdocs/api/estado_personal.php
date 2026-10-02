<?php
require_once __DIR__.'/../includes/helpers.php';
require_role(['REPRESENTANTE','DEALER']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $pdo = db();
    $uid = (int)user()['id'];
    $loc = current_localidad_id();
    if ($loc === null) throw new Exception('Este usuario no tiene una localidad asignada.');

    $session = $pdo->prepare("SELECT activo,sesion_activa,session_token FROM usuarios WHERE id=? LIMIT 1");
    $session->execute([$uid]);
    $sr = $session->fetch();
    $token = (string)($_SESSION['session_token'] ?? '');
    if (!$sr || !(int)$sr['activo'] || !(int)$sr['sesion_activa'] || $token === '' || empty($sr['session_token']) || !hash_equals((string)$sr['session_token'], $token)) {
        throw new Exception('Tu sesión ya no está activa. Inicia sesión nuevamente.');
    }

    $accion = $_POST['accion'] ?? 'estado';
    if ($accion !== 'estado') {
        if (!touch_user_activity()) throw new Exception('Tu sesión fue reemplazada o ya no está activa. Inicia sesión nuevamente.');
    }

    $currentStmt = $pdo->prepare("SELECT id,estado,motivo,detalle,inicio FROM estados_personal WHERE usuario_id=? AND activo=1 ORDER BY inicio DESC LIMIT 1");
    $currentStmt->execute([$uid]);
    $current = $currentStmt->fetch() ?: null;

    if ($accion === 'estado') {
        echo json_encode([
            'ok' => true,
            'estado' => $current['estado'] ?? 'DISPONIBLE',
            'motivo' => $current['motivo'] ?? 'Disponible',
            'detalle' => $current['detalle'] ?? null,
            'inicio' => $current['inicio'] ?? null
        ]);
        exit;
    }

    if ($accion === 'activar') {
        $motivo = trim($_POST['motivo'] ?? '');
        $allowed = ['Cambio a Recepción','Soporte Administrativo','Almuerzo','Break','Reunión','No disponible','Otros'];
        if (!in_array($motivo, $allowed, true)) throw new Exception('Selecciona un motivo válido.');

        $detalle = trim($_POST['detalle'] ?? '');
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE estados_personal SET activo=0,fin=NOW() WHERE usuario_id=? AND activo=1")->execute([$uid]);
        $pdo->prepare("INSERT INTO estados_personal(usuario_id,localidad_id,estado,motivo,detalle,inicio,activo) VALUES(?,?,?,?,?,NOW(),1)")->execute([$uid,$loc,'NO_RECIBIENDO',$motivo,$detalle !== '' ? $detalle : null]);
        try { $pdo->prepare("INSERT INTO auditoria(usuario_id,accion,detalle) VALUES(?,?,?)")->execute([$uid,'CAMBIO_DISPONIBILIDAD','No recibiendo clientes: '.$motivo.($detalle!==''?' · '.$detalle:'')]); } catch (Throwable $e) {}
        $pdo->commit();

        echo json_encode(['ok'=>true,'estado'=>'NO_RECIBIENDO','motivo'=>$motivo]);
        exit;
    }

    if ($accion === 'disponible') {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE estados_personal SET activo=0,fin=NOW() WHERE usuario_id=? AND activo=1")->execute([$uid]);
        $pdo->prepare("INSERT INTO estados_personal(usuario_id,localidad_id,estado,motivo,detalle,inicio,activo) VALUES(?,?,?,?,?,NOW(),1)")->execute([$uid,$loc,'DISPONIBLE','Disponible',null]);
        try { $pdo->prepare("INSERT INTO auditoria(usuario_id,accion,detalle) VALUES(?,?,?)")->execute([$uid,'CAMBIO_DISPONIBILIDAD','Volvió a recibir clientes.']); } catch (Throwable $e) {}
        $pdo->commit();
        echo json_encode(['ok'=>true,'estado'=>'DISPONIBLE','motivo'=>'Disponible']);
        exit;
    }

    throw new Exception('Acción no válida.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
