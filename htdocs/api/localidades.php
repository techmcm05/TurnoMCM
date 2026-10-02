<?php
require_once __DIR__.'/../includes/helpers.php';
require_role(['ADMINISTRADOR']);
header('Content-Type: application/json; charset=utf-8');

try {
    csrf_check();
    $pdo = db();
    $a = $_POST['accion'] ?? '';

    if ($a === 'crear') {
        $nombre = trim($_POST['nombre'] ?? '');
        $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
        $cantidad = (int)($_POST['cantidad_estaciones'] ?? 0);

        if ($nombre === '' || $codigo === '') throw new Exception('Nombre y código son obligatorios.');
        if ($cantidad < 0 || $cantidad > 50) throw new Exception('La cantidad de estaciones debe estar entre 0 y 50.');

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('INSERT INTO localidades(nombre,codigo,activo,cantidad_estaciones) VALUES(?,?,1,?)');
            $st->execute([$nombre, $codigo, $cantidad]);
            $id = (int)$pdo->lastInsertId();

            for ($i = 1; $i <= $cantidad; $i++) {
                $n = 'Estación ' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
                $ins = $pdo->prepare('INSERT INTO estaciones(nombre,localidad_id,activo) VALUES(?,?,1)');
                $ins->execute([$n, $id]);
            }

            $audit = $pdo->prepare('INSERT INTO auditoria(usuario_id,accion,detalle) VALUES(?,?,?)');
            $audit->execute([(int)user()['id'], 'CREAR_LOCALIDAD', 'Localidad creada: ' . $nombre . ' (' . $codigo . ') con ' . $cantidad . ' estación(es).']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        echo json_encode(['ok' => true, 'mensaje' => 'Localidad creada con ' . $cantidad . ' estación(es).']);
        exit;
    }

    if ($a === 'cantidad_estaciones') {
        $id = (int)($_POST['id'] ?? 0);
        $cantidad = (int)($_POST['cantidad_estaciones'] ?? 0);
        if (!$id) throw new Exception('Localidad inválida.');
        if ($cantidad < 0 || $cantidad > 50) throw new Exception('La cantidad de estaciones debe estar entre 0 y 50.');

        $q = $pdo->prepare('SELECT id,nombre FROM localidades WHERE id=? LIMIT 1');
        $q->execute([$id]);
        $loc = $q->fetch();
        if (!$loc) throw new Exception('La localidad no existe.');

        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT id,nombre,activo FROM estaciones WHERE localidad_id=? ORDER BY id');
            $q->execute([$id]);
            $rows = $q->fetchAll();
            $active = array_values(array_filter($rows, fn($r) => (int)$r['activo'] === 1));
            $activeCount = count($active);
            $byName = [];
            foreach ($rows as $r) $byName[$r['nombre']] = (int)$r['id'];

            if ($activeCount < $cantidad) {
                for ($i = 1; $i <= $cantidad && $activeCount < $cantidad; $i++) {
                    $name = 'Estación ' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
                    if (isset($byName[$name])) {
                        $pdo->prepare('UPDATE estaciones SET activo=1 WHERE id=?')->execute([$byName[$name]]);
                    } else {
                        $ins = $pdo->prepare('INSERT INTO estaciones(nombre,localidad_id,activo) VALUES(?,?,1)');
                        $ins->execute([$name, $id]);
                        $byName[$name] = (int)$pdo->lastInsertId();
                    }
                    $activeCount++;
                }
            } elseif ($activeCount > $cantidad) {
                $numbered = [];
                $other = [];
                foreach ($active as $r) {
                    if (preg_match('/Estación\s+(\d+)/u', $r['nombre'], $m)) {
                        $numbered[] = ['row' => $r, 'n' => (int)$m[1]];
                    } else {
                        $other[] = $r;
                    }
                }
                usort($numbered, fn($a, $b) => $b['n'] <=> $a['n']);
                usort($other, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
                $candidates = array_map(fn($x) => $x['row'], $numbered);
                $candidates = array_merge($candidates, $other);

                $need = $activeCount - $cantidad;
                $deactivated = 0;
                foreach ($candidates as $r) {
                    if ($deactivated >= $need) break;
                    $sid = (int)$r['id'];
                    $q = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE estacion_id=? AND activo=1');
                    $q->execute([$sid]);
                    $assigned = (int)$q->fetchColumn();
                    $q = $pdo->prepare("SELECT COUNT(*) FROM turnos WHERE estacion_id=? AND estado IN ('LLAMADO','ATENDIENDO')");
                    $q->execute([$sid]);
                    $busy = (int)$q->fetchColumn();
                    if ($assigned > 0 || $busy > 0) continue;
                    $pdo->prepare('UPDATE estaciones SET activo=0 WHERE id=?')->execute([$sid]);
                    $deactivated++;
                }
                if ($deactivated < $need) {
                    throw new Exception('No se puede reducir la localidad a ' . $cantidad . ' estación(es) porque algunas estaciones tienen personal activo asignado o tickets en atención. Reasigna o finaliza esas atenciones primero.');
                }
            }

            $pdo->prepare('UPDATE localidades SET cantidad_estaciones=? WHERE id=?')->execute([$cantidad, $id]);
            $audit = $pdo->prepare('INSERT INTO auditoria(usuario_id,accion,detalle) VALUES(?,?,?)');
            $audit->execute([(int)user()['id'], 'AJUSTAR_ESTACIONES', 'Localidad: ' . $loc['nombre'] . ' · estaciones activas: ' . $cantidad]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        echo json_encode(['ok' => true, 'cantidad_estaciones' => $cantidad, 'mensaje' => 'La localidad ahora tiene ' . $cantidad . ' estación(es) activa(s).']);
        exit;
    }

    if ($a === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('Localidad inválida.');
        $st = $pdo->prepare('UPDATE localidades SET activo=1-activo WHERE id=?');
        $st->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($a === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('Localidad inválida.');

        $st = $pdo->prepare('SELECT id,nombre,codigo FROM localidades WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $loc = $st->fetch();
        if (!$loc) throw new Exception('La localidad no existe.');

        $st = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE localidad_id=?');
        $st->execute([$id]);
        $users = (int)$st->fetchColumn();
        if ($users > 0) throw new Exception('No puedes eliminar esta localidad porque tiene ' . $users . ' usuario(s) asociado(s). Desactívala o reasigna esos usuarios primero.');

        $st = $pdo->prepare('SELECT COUNT(*) FROM estaciones WHERE localidad_id=? AND activo=1');
        $st->execute([$id]);
        $activeStations = (int)$st->fetchColumn();
        if ($activeStations > 0) throw new Exception('No puedes eliminar esta localidad mientras tenga estaciones activas. Reduce la cantidad de estaciones a 0 primero y vuelve a intentarlo.');

        $st = $pdo->prepare('SELECT COUNT(*) FROM turnos WHERE localidad_id=?');
        $st->execute([$id]);
        $turns = (int)$st->fetchColumn();
        if ($turns > 0) throw new Exception('No puedes eliminar una localidad con tickets históricos o actuales. Desactívala para conservar los reportes y el historial.');

        $st = $pdo->prepare('SELECT COUNT(*) FROM estados_personal WHERE localidad_id=?');
        $st->execute([$id]);
        $states = (int)$st->fetchColumn();
        if ($states > 0) throw new Exception('No puedes eliminar esta localidad porque tiene historial de estados del personal. Desactívala para conservar la trazabilidad.');

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO auditoria(usuario_id,accion,detalle) VALUES(?,?,?)')->execute([(int)user()['id'], 'ELIMINAR_LOCALIDAD', 'Localidad eliminada: ' . $loc['nombre'] . ' (' . $loc['codigo'] . ')']);
            // Solo quedan estaciones inactivas sin usuarios ni tickets cuando se llega a este punto.
            $pdo->prepare('DELETE FROM estaciones WHERE localidad_id=?')->execute([$id]);
            $del = $pdo->prepare('DELETE FROM localidades WHERE id=? LIMIT 1');
            $del->execute([$id]);
            if ($del->rowCount() !== 1) throw new Exception('No fue posible eliminar la localidad.');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        echo json_encode(['ok' => true, 'mensaje' => 'Localidad eliminada correctamente.']);
        exit;
    }

    throw new Exception('Acción no válida.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
