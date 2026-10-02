<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/helpers.php';
require_role(['ADMINISTRADOR']);
header('Content-Type: application/json; charset=utf-8');

try {
    csrf_check();
    $pdo = db();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        if ($nombre === '') throw new Exception('El nombre de la clasificación es obligatorio.');
        if (mb_strlen($nombre) > 120) throw new Exception('La clasificación no puede superar 120 caracteres.');

        $st = $pdo->prepare('SELECT COUNT(*) FROM clasificaciones WHERE nombre=?');
        $st->execute([$nombre]);
        if ((int)$st->fetchColumn() > 0) throw new Exception('Ya existe una clasificación con ese nombre.');

        $st = $pdo->prepare('INSERT INTO clasificaciones(nombre,activo) VALUES(?,1)');
        $st->execute([$nombre]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO auditoria(usuario_id,accion,detalle) VALUES(?,?,?)')->execute([(int)user()['id'],'CREAR_CLASIFICACION','Clasificación creada: '.$nombre]);
        echo json_encode(['ok'=>true,'id'=>$id,'mensaje'=>'Clasificación creada correctamente.']);
        exit;
    }

    if ($accion === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('Clasificación inválida.');
        $st = $pdo->prepare('SELECT id,nombre,activo FROM clasificaciones WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $class = $st->fetch();
        if (!$class) throw new Exception('La clasificación no existe.');
        $newState = (int)!((int)$class['activo']);
        $pdo->prepare('UPDATE clasificaciones SET activo=? WHERE id=?')->execute([$newState,$id]);
        $pdo->prepare('INSERT INTO auditoria(usuario_id,accion,detalle) VALUES(?,?,?)')->execute([(int)user()['id'],'CAMBIAR_ESTADO_CLASIFICACION','Clasificación '.$class['nombre'].' → '.($newState?'ACTIVA':'INACTIVA')]);
        echo json_encode(['ok'=>true,'activo'=>$newState]);
        exit;
    }

    if ($accion === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('Clasificación inválida.');
        $st = $pdo->prepare('SELECT id,nombre FROM clasificaciones WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $class = $st->fetch();
        if (!$class) throw new Exception('La clasificación no existe.');

        $st = $pdo->prepare('SELECT COUNT(*) FROM turnos WHERE clasificacion_id=?');
        $st->execute([$id]);
        $used = (int)$st->fetchColumn();

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO auditoria(usuario_id,accion,detalle) VALUES(?,?,?)')->execute([(int)user()['id'],'ELIMINAR_CLASIFICACION','Clasificación eliminada: '.$class['nombre'].($used ? ' · Tickets afectados: '.$used : '')]);
            // turnos.clasificacion_id usa ON DELETE SET NULL, por lo que el historial de los tickets se conserva.
            $del = $pdo->prepare('DELETE FROM clasificaciones WHERE id=? LIMIT 1');
            $del->execute([$id]);
            if ($del->rowCount() !== 1) throw new Exception('No fue posible eliminar la clasificación.');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        echo json_encode(['ok'=>true,'mensaje'=>'Clasificación eliminada correctamente.','tickets_afectados'=>$used]);
        exit;
    }

    throw new Exception('Acción no válida.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
