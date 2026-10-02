<?php
require_once __DIR__ . '/../config/db.php';

function e(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never {
    header('Location: ' . BASE_URL . '/' . ltrim($path, '/'));
    exit;
}

function is_logged(): bool {
    return !empty($_SESSION['user']);
}

function user(): ?array {
    return $_SESSION['user'] ?? null;
}

function end_staff_session(int $userId): void {
    try {
        $pdo = db();
        $pdo->prepare("UPDATE usuarios SET sesion_activa=0, ultima_actividad=NULL, session_token=NULL WHERE id=?")->execute([$userId]);
        $pdo->prepare("UPDATE estados_personal SET activo=0,fin=NOW() WHERE usuario_id=? AND activo=1")->execute([$userId]);
    } catch (Throwable $e) {}
}

function invalidate_local_session(bool $clearServer=false): never {
    if ($clearServer && is_logged()) end_staff_session((int)$_SESSION['user']['id']);
    $_SESSION=[];
    if (ini_get('session.use_cookies')) {
        $params=session_get_cookie_params();
        setcookie(session_name(),'',time()-42000,$params['path'],$params['domain'],$params['secure'],$params['httponly']);
    }
    session_destroy();
    redirect('index.php?timeout=1');
}

function touch_user_activity(): bool {
    if (!is_logged()) return false;
    $now=time();
    $token=(string)($_SESSION['session_token']??'');
    if ($token==='') return false;
    try {
        $pdo=db();
        // No dependemos de rowCount(): en MySQL puede devolver 0 cuando el UPDATE
        // no cambia el valor almacenado aunque la sesión siga siendo válida.
        // Primero comprobamos que el token actual pertenece realmente a esta sesión.
        $check=$pdo->prepare("SELECT id,activo,sesion_activa,session_token FROM usuarios WHERE id=? LIMIT 1");
        $check->execute([(int)$_SESSION['user']['id']]);
        $current=$check->fetch();
        if (!$current || !(int)$current['activo'] || empty($current['session_token']) || !hash_equals((string)$current['session_token'],$token)) {
            return false;
        }

        $st=$pdo->prepare("UPDATE usuarios SET sesion_activa=1,ultima_actividad=NOW() WHERE id=? AND activo=1 AND session_token=?");
        $st->execute([(int)$_SESSION['user']['id'],$token]);

        // Verificación posterior: solo invalidamos la sesión si el token dejó de coincidir.
        $verify=$pdo->prepare("SELECT sesion_activa,session_token FROM usuarios WHERE id=? LIMIT 1");
        $verify->execute([(int)$_SESSION['user']['id']]);
        $fresh=$verify->fetch();
        if (!$fresh || !(int)$fresh['sesion_activa'] || empty($fresh['session_token']) || !hash_equals((string)$fresh['session_token'],$token)) {
            return false;
        }
        $_SESSION['last_activity']=$now;
        return true;
    } catch (Throwable $e) {
        // Un fallo temporal de BD no debe expulsar al usuario ni presentarlo como
        // una sesión reemplazada. La siguiente petición volverá a comprobarla.
        return true;
    }
}

function require_login(bool $touch=true): void {
    if (!is_logged()) redirect('index.php');
    $uid=(int)($_SESSION['user']['id']??0);
    $sessionRole=$_SESSION['user']['rol']??'';
    $isMonitor=($sessionRole==='MONITOR');
    $last=(int)($_SESSION['last_activity']??0);
    if (!$isMonitor && $last>0 && (time()-$last)>=SESSION_IDLE_TIMEOUT) {
        end_staff_session($uid); invalidate_local_session(false);
    }
    try {
        $pdo = db();
        expire_overdue_tickets($pdo);
        $st=$pdo->prepare("SELECT u.id,u.nombre,u.usuario,u.estacion_id,u.localidad_id,u.activo,u.sesion_activa,u.ultima_actividad,u.session_token,r.nombre rol,l.nombre localidad FROM usuarios u JOIN roles r ON r.id=u.rol_id LEFT JOIN localidades l ON l.id=u.localidad_id WHERE u.id=? LIMIT 1");
        $st->execute([$uid]); $fresh=$st->fetch();
        if (!$fresh || !(int)$fresh['activo']) {invalidate_local_session(false);}
        $currentToken=$_SESSION['session_token']??'';
        if (empty($currentToken) || empty($fresh['session_token']) || !hash_equals((string)$fresh['session_token'],(string)$currentToken)) {
            // Another login replaced this session. Do not clear the new server session.
            $_SESSION=[]; session_destroy(); redirect('index.php?replaced=1');
        }
        // Si otra sesión tomó el control de la cuenta, esta sesión queda invalidada
        // independientemente del rol. Así también funciona para ADMINISTRADOR,
        // SUPERVISOR, RECEPCIONISTA, CONSULTA y MONITOR.
        if (!(int)$fresh['sesion_activa']) {
            $_SESSION=[]; session_destroy(); redirect('index.php?replaced=1');
        }
        $_SESSION['user']=['id'=>(int)$fresh['id'],'nombre'=>$fresh['nombre'],'usuario'=>$fresh['usuario'],'rol'=>$fresh['rol'],'estacion_id'=>$fresh['estacion_id'],'localidad_id'=>$fresh['localidad_id'],'localidad'=>$fresh['localidad']];
        if ($last<=0) {$dbLast=!empty($fresh['ultima_actividad'])?strtotime($fresh['ultima_actividad']):0;$_SESSION['last_activity']=$dbLast>0?$dbLast:time();}
        if ($touch || $isMonitor) touch_user_activity();
    } catch (Throwable $e) {
        // Si la columna session_token aún no existe, se mantiene compatibilidad temporal.
        if ($isMonitor) { $_SESSION['last_activity']=time(); return; }
    }
}
function require_role(array $roles): void {
    require_login();
    $role = $_SESSION['user']['rol'] ?? '';
    if (!in_array($role, $roles, true)) {
        http_response_code(403);
        exit('Acceso no autorizado.');
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Token de seguridad inválido.');
    }
}

function ticket_code(string $prefijo, int $numero): string {
    return $prefijo . str_pad((string)$numero, 3, '0', STR_PAD_LEFT);
}

function app_url(string $path=''): string {
    return BASE_URL . '/' . ltrim($path, '/');
}

function current_localidad_id(): ?int {
    return isset($_SESSION['user']['localidad_id']) && $_SESSION['user']['localidad_id'] !== null ? (int)$_SESSION['user']['localidad_id'] : null;
}

function is_global_role(): bool {
    return in_array($_SESSION['user']['rol'] ?? '', ['ADMINISTRADOR','CONSULTA'], true);
}

function is_manager(): bool {
    return ($_SESSION['user']['rol'] ?? '') === 'GERENTE';
}

/**
 * Localidades que puede visualizar el usuario en funciones de supervisión/reportes.
 * null = acceso global; [] = ninguna; [ids...] = conjunto explícito.
 */
function visible_localidad_ids(): ?array {
    static $cache = null;
    static $loaded = false;
    if ($loaded) return $cache;
    $loaded = true;

    if (is_global_role()) return $cache = null;
    if (is_manager()) {
        try {
            $st = db()->prepare('SELECT localidad_id FROM gerente_localidades WHERE gerente_id=? ORDER BY localidad_id');
            $st->execute([(int)($_SESSION['user']['id'] ?? 0)]);
            return $cache = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return $cache = [];
        }
    }

    $loc = current_localidad_id();
    return $cache = $loc === null ? [] : [$loc];
}

function visible_localidad_where(string $alias='t'): array {
    $ids = visible_localidad_ids();
    if ($ids === null) return ['1=1', []];
    if (!$ids) return ['0=1', []];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    return [$alias.'.localidad_id IN ('.$ph.')', $ids];
}

function can_view_localidad(int $localidadId): bool {
    $ids = visible_localidad_ids();
    return $ids === null || in_array($localidadId, $ids, true);
}

function manager_localidad_names(): array {
    if (!is_manager()) return [];
    static $cache = null;
    if ($cache !== null) return $cache;
    $ids = visible_localidad_ids();
    if (!$ids) return $cache = [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare('SELECT id,nombre FROM localidades WHERE id IN ('.$ph.') ORDER BY nombre');
    $st->execute($ids);
    return $cache = $st->fetchAll();
}

function local_where(string $alias='t'): array {
    return visible_localidad_where($alias);
}


/**
 * Closes tickets automatically once their total lifetime reaches the configured
 * two-hour limit. The ticket is not deleted; it is marked VENCIDO and retains
 * its attendant/station when applicable for traceability.
 */
function expire_overdue_tickets(?PDO $pdo = null): int {
    static $running = false;
    if ($running) return 0;
    $running = true;
    $count = 0;
    try {
        $pdo = $pdo ?: db();
        // Ensure the migration has already added the enum value before attempting
        // the update. If not, the request continues normally and the next upgrade
        // can complete the schema change.
        $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='turnos' AND COLUMN_NAME='estado'");
        $check->execute();
        if (!(int)$check->fetchColumn()) return 0;

        $st = $pdo->query("SELECT id FROM turnos
            WHERE estado IN ('ESPERANDO','LLAMADO','ATENDIENDO')
              AND hora_creacion IS NOT NULL
              AND hora_creacion <= DATE_SUB(NOW(), INTERVAL 2 HOUR)
            ORDER BY hora_creacion ASC
            LIMIT 100");
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) return 0;

        $up = $pdo->prepare("UPDATE turnos
            SET estado='VENCIDO',
                hora_finalizacion=NOW(),
                motivo_cierre='Vencido por límite de tiempo'
            WHERE id=?
              AND estado IN ('ESPERANDO','LLAMADO','ATENDIENDO')");
        $audit = $pdo->prepare("INSERT INTO auditoria(usuario_id,accion,detalle) VALUES(NULL,'TICKET_VENCIDO',?)");
        foreach ($ids as $id) {
            $up->execute([(int)$id]);
            if ($up->rowCount() > 0) {
                $count += $up->rowCount();
                try { $audit->execute(['Ticket #'.(int)$id.' cerrado automáticamente por superar 2 horas de antigüedad.']); } catch (Throwable $e) {}
            }
        }
    } catch (Throwable $e) {
        // Automatic expiration must never make a normal request fail.
    } finally {
        $running = false;
    }
    return $count;
}
