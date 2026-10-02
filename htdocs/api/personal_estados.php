<?php
require_once __DIR__.'/../includes/helpers.php';
require_role(['ADMINISTRADOR','SUPERVISOR','GERENTE']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $pdo = db();
    $today = date('Y-m-d');
    [$where,$params] = visible_localidad_where('u');

    $sql = "SELECT u.id,u.nombre,u.sesion_activa,u.ultima_actividad,
                   l.nombre localidad,
                   r.nombre rol,
                   ep.estado,ep.motivo,ep.detalle,ep.inicio,
                   (SELECT COUNT(*) FROM turnos tx WHERE tx.atendiente_id=u.id AND tx.fecha=? AND tx.estado='ATENDIENDO') AS atendiendo
            FROM usuarios u
            JOIN roles r ON r.id=u.rol_id
            LEFT JOIN localidades l ON l.id=u.localidad_id
            LEFT JOIN estados_personal ep ON ep.id=(
                SELECT epx.id FROM estados_personal epx
                WHERE epx.usuario_id=u.id AND epx.activo=1
                ORDER BY epx.inicio DESC LIMIT 1
            )
            WHERE r.nombre IN ('REPRESENTANTE','DEALER') AND u.activo=1 AND $where
            ORDER BY l.nombre,u.nombre";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$today],$params));
    $rows = $st->fetchAll();

    $now = time();
    foreach ($rows as &$r) {
        $last = !empty($r['ultima_actividad']) ? strtotime($r['ultima_actividad']) : 0;
        $connected = (int)$r['sesion_activa'] === 1 && $last > 0 && ($now - $last) < SESSION_IDLE_TIMEOUT;
        $state = $r['estado'] ?: ($connected ? 'DISPONIBLE' : 'NO_DISPONIBLE');
        $labels = [
            'DISPONIBLE'=>'Disponible',
            'NO_RECIBIENDO'=>'No recibiendo clientes',
            'ALMUERZO'=>'Almuerzo',
            'BREAK'=>'Break',
            'NO_DISPONIBLE'=>'No disponible',
            'OTROS'=>'Otros'
        ];
        $r['conectado'] = $connected;
        $r['estado'] = $state;
        $r['estado_label'] = $labels[$state] ?? ucwords(strtolower(str_replace('_',' ',$state)));
        $r['motivo'] = $r['motivo'] ?: null;
        $r['detalle'] = $r['detalle'] ?: null;
        $r['inicio'] = $r['inicio'] ?: null;
        $r['atendiendo'] = (int)$r['atendiendo'];
    }
    unset($r);

    echo json_encode(['ok'=>true,'items'=>$rows,'server_time'=>date('c')], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'No fue posible consultar el estado del personal.']);
}
