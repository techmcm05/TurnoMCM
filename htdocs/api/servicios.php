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
        $prefijo = strtoupper(trim((string)($_POST['prefijo'] ?? '')));
        $descripcion = trim((string)($_POST['descripcion'] ?? ''));
        $icono = trim((string)($_POST['icono'] ?? 'bi-grid')) ?: 'bi-grid';
        $destino = strtoupper(trim((string)($_POST['destino'] ?? 'REPRESENTANTE')));
        $orden = max(0, (int)($_POST['orden'] ?? 0));

        if ($nombre === '') throw new Exception('El nombre del servicio es obligatorio.');
        if (!preg_match('/^[A-Z0-9]{1,5}$/', $prefijo)) throw new Exception('El prefijo debe tener entre 1 y 5 letras o números, sin espacios.');
        if (!in_array($destino, ['REPRESENTANTE','DEALER','AMBOS'], true)) throw new Exception('El destino seleccionado no es válido.');

        $st = $pdo->prepare('SELECT COUNT(*) FROM servicios WHERE nombre=? OR prefijo=?');
        $st->execute([$nombre, $prefijo]);
        if ((int)$st->fetchColumn() > 0) throw new Exception('Ya existe un servicio con ese nombre o prefijo.');

        $st = $pdo->prepare('INSERT INTO servicios(nombre,prefijo,descripcion,icono,destino,orden,activo) VALUES(?,?,?,?,?,?,1)');
        $st->execute([$nombre, $prefijo, $descripcion, $icono, $destino, $orden]);
        $id = (int)$pdo->lastInsertId();

        $audit = $pdo->prepare('INSERT INTO auditoria(usuario_id,accion,detalle) VALUES(?,?,?)');
        $audit->execute([(int)user()['id'], 'CREAR_SERVICIO', 'Servicio creado: '.$nombre.' ('.$prefijo.') · Destino: '.$destino]);
        echo json_encode(['ok'=>true,'id'=>$id,'mensaje'=>'Servicio creado correctamente.']);
        exit;
    }

    if ($accion === 'editar') {
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $prefijo = strtoupper(trim((string)($_POST['prefijo'] ?? '')));
        $descripcion = trim((string)($_POST['descripcion'] ?? ''));
        $icono = trim((string)($_POST['icono'] ?? 'bi-grid')) ?: 'bi-grid';
        $destino = strtoupper(trim((string)($_POST['destino'] ?? 'REPRESENTANTE')));
        $orden = max(0, (int)($_POST['orden'] ?? 0));
        if (!$id) throw new Exception('Servicio inválido.');
        if ($nombre === '') throw new Exception('El nombre del servicio es obligatorio.');
        if (!preg_match('/^[A-Z0-9]{1,5}$/', $prefijo)) throw new Exception('El prefijo debe tener entre 1 y 5 letras o números, sin espacios.');
        if (!in_array($destino, ['REPRESENTANTE','DEALER','AMBOS'], true)) throw new Exception('El destino seleccionado no es válido.');
        $st=$pdo->prepare('SELECT id,nombre,prefijo FROM servicios WHERE id=? LIMIT 1'); $st->execute([$id]); $old=$st->fetch();
        if (!$old) throw new Exception('El servicio no existe.');
        $st=$pdo->prepare('SELECT COUNT(*) FROM servicios WHERE (nombre=? OR prefijo=?) AND id<>?'); $st->execute([$nombre,$prefijo,$id]);
        if ((int)$st->fetchColumn()>0) throw new Exception('Ya existe otro servicio con ese nombre o prefijo.');
        $pdo->prepare('UPDATE servicios SET nombre=?,prefijo=?,descripcion=?,icono=?,destino=?,orden=? WHERE id=?')->execute([$nombre,$prefijo,$descripcion,$icono,$destino,$orden,$id]);
        $pdo->prepare('INSERT INTO auditoria(usuario_id,accion,detalle) VALUES(?,?,?)')->execute([(int)user()['id'],'EDITAR_SERVICIO','Servicio actualizado: '.$nombre.' ('.$prefijo.') · Destino: '.$destino]);
        echo json_encode(['ok'=>true,'mensaje'=>'Servicio actualizado correctamente.']);
        exit;
    }

    if ($accion === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('Servicio inválido.');
        $st = $pdo->prepare('SELECT id,nombre,activo FROM servicios WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $service = $st->fetch();
        if (!$service) throw new Exception('El servicio no existe.');
        $newState = (int)!((int)$service['activo']);
        $pdo->prepare('UPDATE servicios SET activo=? WHERE id=?')->execute([$newState, $id]);
        $pdo->prepare('INSERT INTO auditoria(usuario_id,accion,detalle) VALUES(?,?,?)')->execute([(int)user()['id'],'CAMBIAR_ESTADO_SERVICIO','Servicio '.$service['nombre'].' → '.($newState?'ACTIVO':'INACTIVO')]);
        echo json_encode(['ok'=>true,'activo'=>$newState]);
        exit;
    }

    if ($accion === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('Servicio inválido.');
        $st = $pdo->prepare('SELECT id,nombre,prefijo FROM servicios WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $service = $st->fetch();
        if (!$service) throw new Exception('El servicio no existe.');

        $st = $pdo->prepare('SELECT COUNT(*) FROM turnos WHERE servicio_id=?');
        $st->execute([$id]);
        $turns = (int)$st->fetchColumn();
        if ($turns > 0) {
            throw new Exception('No puedes eliminar este servicio porque tiene '.$turns.' ticket(s) asociado(s). Puedes desactivarlo para conservar el historial.');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM rol_servicio WHERE servicio_id=?')->execute([$id]);
            $pdo->prepare('INSERT INTO auditoria(usuario_id,accion,detalle) VALUES(?,?,?)')->execute([(int)user()['id'],'ELIMINAR_SERVICIO','Servicio eliminado: '.$service['nombre'].' ('.$service['prefijo'].')']);
            $del = $pdo->prepare('DELETE FROM servicios WHERE id=? LIMIT 1');
            $del->execute([$id]);
            if ($del->rowCount() !== 1) throw new Exception('No fue posible eliminar el servicio.');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        echo json_encode(['ok'=>true,'mensaje'=>'Servicio eliminado correctamente.']);
        exit;
    }

    throw new Exception('Acción no válida.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
