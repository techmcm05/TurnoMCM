<?php
require_once __DIR__.'/../includes/helpers.php';

if (is_logged()) {
    $u = user();
    $motivos = ['ALMUERZO','BREAK','NO_DISPONIBLE','OTROS'];
    $motivo = strtoupper(trim($_POST['motivo'] ?? 'OTROS'));
    $detalle = trim($_POST['detalle'] ?? '');

    // Solo representantes y dealers registran un motivo de salida para supervisión.
    if (in_array($u['rol'] ?? '', ['REPRESENTANTE','DEALER'], true)) {
        if (!in_array($motivo, $motivos, true)) $motivo = 'OTROS';
        if ($motivo !== 'OTROS') $detalle = '';
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE estados_personal SET activo=0,fin=NOW() WHERE usuario_id=? AND activo=1")->execute([(int)$u['id']]);
            $pdo->prepare("INSERT INTO estados_personal(usuario_id,localidad_id,estado,motivo,detalle) VALUES(?,?,?,?,?)")
                ->execute([(int)$u['id'],$u['localidad_id']??null,$motivo,$motivo==='OTROS' ? ($detalle ?: 'Salida') : ucfirst(strtolower(str_replace('_',' ',$motivo))),$detalle ?: null]);
            $pdo->commit();
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        }
    }
}

try { db()->prepare("UPDATE usuarios SET sesion_activa=0,ultima_actividad=NULL,session_token=NULL WHERE id=?")->execute([(int)($u['id']??0)]); } catch (Throwable $e) {}

$_SESSION=[];
if (ini_get('session.use_cookies')) {
    $params=session_get_cookie_params();
    setcookie(session_name(),'',time()-42000,$params['path'],$params['domain'],$params['secure'],$params['httponly']);
}
session_destroy();
header('Location: '.BASE_URL.'/');
exit;
