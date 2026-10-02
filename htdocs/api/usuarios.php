<?php
require_once __DIR__.'/../includes/helpers.php';
require_role(['ADMINISTRADOR']);
header('Content-Type: application/json; charset=utf-8');

try {
    csrf_check();
    $pdo = db();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        $nombre = trim($_POST['nombre'] ?? '');
        $usuario = trim($_POST['usuario'] ?? '');
        $password = $_POST['password'] ?? '';
        $rol_id = (int)($_POST['rol_id'] ?? 0);
        $estacion_id = ($_POST['estacion_id'] ?? '') !== '' ? (int)$_POST['estacion_id'] : null;
        $localidad_id = ($_POST['localidad_id'] ?? '') !== '' ? (int)$_POST['localidad_id'] : null;
        $roleSt = $pdo->prepare('SELECT nombre FROM roles WHERE id=? LIMIT 1'); $roleSt->execute([$rol_id]); $roleName = (string)$roleSt->fetchColumn();
        $managerLocations = array_values(array_unique(array_filter(array_map('intval', $_POST['gerente_localidades'] ?? []))));
        if ($roleName === 'GERENTE') { $localidad_id = null; $estacion_id = null; if (!$managerLocations) throw new Exception('Un Gerente debe tener al menos una tienda asignada.'); } else { $managerLocations=[]; }

        if ($nombre === '' || $usuario === '' || strlen($password) < 8 || !$rol_id) {
            throw new Exception('Complete los campos obligatorios. La contraseña debe tener al menos 8 caracteres.');
        }

        $st = $pdo->prepare("SELECT id FROM usuarios WHERE usuario=? LIMIT 1");
        $st->execute([$usuario]);
        if ($st->fetch()) throw new Exception('Ese nombre de usuario ya existe.');

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("INSERT INTO usuarios(nombre,usuario,password_hash,rol_id,estacion_id,localidad_id,activo) VALUES(?,?,?,?,?,?,1)");
            $st->execute([$nombre, $usuario, password_hash($password, PASSWORD_DEFAULT), $rol_id, $estacion_id, $localidad_id]);
            $newId=(int)$pdo->lastInsertId();
            if ($roleName === 'GERENTE') {
                $map=$pdo->prepare('INSERT INTO gerente_localidades(gerente_id,localidad_id) VALUES(?,?)');
                foreach ($managerLocations as $lid) {
                    $chk=$pdo->prepare('SELECT id FROM localidades WHERE id=? AND activo=1');
                    $chk->execute([$lid]);
                    if (!$chk->fetch()) throw new Exception('Una de las localidades asignadas no existe o está inactiva.');
                    $map->execute([$newId,$lid]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction())$pdo->rollBack(); throw $e; }
        echo json_encode(['ok'=>true, 'mensaje'=>'Usuario creado correctamente.']);
        exit;
    }

    if ($accion === 'editar') {
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $usuario = trim($_POST['usuario'] ?? '');
        $rol_id = (int)($_POST['rol_id'] ?? 0);
        $estacion_id = ($_POST['estacion_id'] ?? '') !== '' ? (int)$_POST['estacion_id'] : null;
        $localidad_id = ($_POST['localidad_id'] ?? '') !== '' ? (int)$_POST['localidad_id'] : null;
        $activo = isset($_POST['activo']) ? 1 : 0;
        $roleSt = $pdo->prepare('SELECT nombre FROM roles WHERE id=? LIMIT 1'); $roleSt->execute([$rol_id]); $roleName = (string)$roleSt->fetchColumn();
        $managerLocations = array_values(array_unique(array_filter(array_map('intval', $_POST['gerente_localidades'] ?? []))));
        if ($roleName === 'GERENTE') { $localidad_id = null; $estacion_id = null; if (!$managerLocations) throw new Exception('Un Gerente debe tener al menos una tienda asignada.'); } else { $managerLocations=[]; }

        if (!$id || $nombre === '' || $usuario === '' || !$rol_id) {
            throw new Exception('Datos incompletos.');
        }

        $st = $pdo->prepare("SELECT id FROM usuarios WHERE usuario=? AND id<>? LIMIT 1");
        $st->execute([$usuario, $id]);
        if ($st->fetch()) throw new Exception('Ese nombre de usuario ya existe.');

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("UPDATE usuarios SET nombre=?,usuario=?,rol_id=?,estacion_id=?,localidad_id=?,activo=? WHERE id=?");
            $st->execute([$nombre, $usuario, $rol_id, $estacion_id, $localidad_id, $activo, $id]);
            $pdo->prepare('DELETE FROM gerente_localidades WHERE gerente_id=?')->execute([$id]);
            if ($roleName === 'GERENTE') {
                $map=$pdo->prepare('INSERT INTO gerente_localidades(gerente_id,localidad_id) VALUES(?,?)');
                foreach ($managerLocations as $lid) { $chk=$pdo->prepare('SELECT id FROM localidades WHERE id=? AND activo=1'); $chk->execute([$lid]); if (!$chk->fetch()) throw new Exception('Una de las localidades asignadas no existe o está inactiva.'); $map->execute([$id,$lid]); }
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction())$pdo->rollBack(); throw $e; }

        if ((int)user()['id'] === $id) {
            $st = $pdo->prepare("SELECT u.*,r.nombre rol FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=?");
            $st->execute([$id]);
            $me = $st->fetch();
            $_SESSION['user'] = [
                'id'=>(int)$me['id'], 'nombre'=>$me['nombre'], 'usuario'=>$me['usuario'],
                'rol'=>$me['rol'], 'estacion_id'=>$me['estacion_id'], 'localidad_id'=>$me['localidad_id'], 'localidad'=>($pdo->query("SELECT nombre FROM localidades WHERE id=".(int)$me['localidad_id'])->fetchColumn() ?: null)
            ];
        }

        echo json_encode(['ok'=>true, 'mensaje'=>'Usuario actualizado.']);
        exit;
    }

    if ($accion === 'password') {
        $id = (int)($_POST['id'] ?? 0);
        $password = $_POST['password'] ?? '';
        if (!$id || strlen($password) < 8) throw new Exception('La contraseña debe tener al menos 8 caracteres.');

        $st = $pdo->prepare("UPDATE usuarios SET password_hash=? WHERE id=?");
        $st->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        echo json_encode(['ok'=>true, 'mensaje'=>'Contraseña cambiada correctamente.']);
        exit;
    }

    if ($accion === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('Usuario inválido.');
        if ($id === (int)user()['id']) throw new Exception('No puedes desactivar tu propio usuario desde esta pantalla.');

        $st = $pdo->prepare("UPDATE usuarios SET activo=1-activo WHERE id=?");
        $st->execute([$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($accion === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('Usuario inválido.');
        if ($id === (int)user()['id']) throw new Exception('Por seguridad, no puedes eliminar tu propio usuario.');

        $st = $pdo->prepare("SELECT id,nombre,usuario,activo,rol_id FROM usuarios WHERE id=? LIMIT 1");
        $st->execute([$id]);
        $target = $st->fetch();
        if (!$target) throw new Exception('El usuario ya no existe.');

        // Evita dejar el sistema sin ningún administrador activo.
        $st = $pdo->query("SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE r.nombre='ADMINISTRADOR' AND u.activo=1");
        $activeAdmins = (int)$st->fetchColumn();
        $targetIsAdmin = false;
        $st = $pdo->prepare("SELECT nombre FROM roles WHERE id=? LIMIT 1");
        $st->execute([(int)$target['rol_id']]);
        $targetIsAdmin = strtoupper((string)$st->fetchColumn()) === 'ADMINISTRADOR';
        if ($targetIsAdmin && (int)$target['activo'] === 1 && $activeAdmins <= 1) {
            throw new Exception('No puedes eliminar al último administrador activo del sistema.');
        }

        // Se permite eliminar al usuario sin cerrar tickets. Los tickets históricos
        // quedan conservados y las FK con ON DELETE SET NULL desvinculan al usuario.
        // Si tenía una atención activa, se devuelve a la cola para que otro usuario
        // pueda asumirla; no se marca como finalizada ni se pierde el ticket.
        $pdo->beginTransaction();
        try {
            $requeue = $pdo->prepare("UPDATE turnos
                SET estado='ESPERANDO', atendiente_id=NULL, estacion_id=NULL,
                    hora_llamado=NULL, hora_inicio=NULL, llamado_seq=0
                WHERE atendiente_id=? AND estado IN ('LLAMADO','ATENDIENDO')");
            $requeue->execute([$id]);
            $requeued = $requeue->rowCount();

            // Guarda quién realizó la eliminación antes de borrar la cuenta.
            $detalle = 'Usuario eliminado: '.($target['nombre']??'').' ('.($target['usuario']??'').')';
            if ($requeued > 0) $detalle .= ' | '.$requeued.' ticket(s) devuelto(s) a la cola.';
            $audit = $pdo->prepare("INSERT INTO auditoria(usuario_id,accion,detalle) VALUES(?,?,?)");
            $audit->execute([(int)user()['id'],'ELIMINAR_USUARIO',$detalle]);

            // Revoca la sesión antes de eliminar la cuenta. Las FK existentes
            // conservan los tickets históricos y desvinculan al usuario cuando corresponde.
            $pdo->prepare("UPDATE usuarios SET sesion_activa=0, session_token=NULL, ultima_actividad=NULL WHERE id=?")->execute([$id]);
            $del = $pdo->prepare("DELETE FROM usuarios WHERE id=? LIMIT 1");
            $del->execute([$id]);
            if ($del->rowCount() !== 1) throw new Exception('No fue posible eliminar el usuario.');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        echo json_encode(['ok'=>true, 'mensaje'=>'Usuario eliminado correctamente.']);
        exit;
    }

    throw new Exception('Acción no válida.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
