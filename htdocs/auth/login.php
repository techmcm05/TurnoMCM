<?php
require_once __DIR__.'/../includes/helpers.php';
$u=trim($_POST['usuario']??'');
$p=$_POST['password']??'';
$confirm=(($_POST['confirmar_conflicto']??'')==='1');
$challenge=$_POST['challenge']??'';

function establish_login(array $row): never {
    global $pdo;
    $newToken=bin2hex(random_bytes(32));

    // La nueva sesión reemplaza de forma atómica a cualquier sesión anterior.
    // El dispositivo anterior queda invalidado porque conserva un token distinto.
    $st=$pdo->prepare("UPDATE usuarios SET sesion_activa=1, ultima_actividad=NOW(), session_token=? WHERE id=? AND activo=1");
    $st->execute([$newToken,(int)$row['id']]);
    if ($st->rowCount() < 1) {
        throw new RuntimeException('No fue posible reemplazar la sesión activa.');
    }

    // Si el usuario inicia sesión en el mismo navegador, renovamos el ID de sesión.
    if (session_status()===PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['user']=['id'=>(int)$row['id'],'nombre'=>$row['nombre'],'usuario'=>$row['usuario'],'rol'=>$row['rol'],'estacion_id'=>$row['estacion_id'],'localidad_id'=>$row['localidad_id'],'localidad'=>$row['localidad']];
    $_SESSION['session_token']=$newToken;
    $_SESSION['csrf']=bin2hex(random_bytes(32));
    $_SESSION['last_activity']=time();
    unset($_SESSION['login_conflict'], $_SESSION['login_error']);

    try {
        if (in_array($row['rol'],['REPRESENTANTE','DEALER'],true)) {
            $pdo->prepare("UPDATE estados_personal SET activo=0,fin=NOW() WHERE usuario_id=? AND activo=1")->execute([(int)$row['id']]);
            $pdo->prepare("INSERT INTO estados_personal(usuario_id,localidad_id,estado,motivo,detalle) VALUES(?,?,?,?,?)")->execute([(int)$row['id'],$row['localidad_id']??null,'DISPONIBLE','Disponible',null]);
        }
    } catch (Throwable $e) {
        // El registro de disponibilidad no debe impedir el inicio de sesión.
    }
    redirect('dashboard.php');
}

if (!$confirm && ($u==='' || $p==='')) {$_SESSION['login_error']='Complete usuario y contraseña.';redirect('index.php');}
try {
    $pdo=db();
    if ($confirm) {
        $pending=$_SESSION['login_conflict']??null;
        if (!$pending || empty($pending['challenge']) || !hash_equals($pending['challenge'],$challenge) || (int)$pending['expires_at']<time()) {
            unset($_SESSION['login_conflict']); $_SESSION['login_error']='La confirmación de sesión expiró. Intenta iniciar sesión nuevamente.'; redirect('index.php');
        }
        $st=$pdo->prepare("SELECT u.*,r.nombre rol,l.nombre localidad FROM usuarios u JOIN roles r ON r.id=u.rol_id LEFT JOIN localidades l ON l.id=u.localidad_id WHERE u.id=? AND u.activo=1 LIMIT 1");
        $st->execute([(int)$pending['user_id']]); $row=$st->fetch();
        if (!$row) {unset($_SESSION['login_conflict']);$_SESSION['login_error']='El usuario ya no está disponible.';redirect('index.php');}
        establish_login($row);
    }
    $st=$pdo->prepare("SELECT u.*,r.nombre rol,l.nombre localidad,e.nombre estacion_nombre FROM usuarios u JOIN roles r ON r.id=u.rol_id LEFT JOIN localidades l ON l.id=u.localidad_id LEFT JOIN estaciones e ON e.id=u.estacion_id WHERE u.usuario=? AND u.activo=1 LIMIT 1");
    $st->execute([$u]);$row=$st->fetch();
    if (!$row || !password_verify($p,$row['password_hash'])) {$_SESSION['login_error']='Usuario o contraseña incorrectos.';redirect('index.php');}

    $active=(int)($row['sesion_activa']??0)===1 && !empty($row['session_token']);
    if ($active) {
        $conflictScope=$row['localidad']?:'Sin localidad';
        if ($row['rol']==='GERENTE') {
            try { $ms=$pdo->prepare('SELECT COUNT(*) FROM gerente_localidades WHERE gerente_id=?'); $ms->execute([(int)$row['id']]); $n=(int)$ms->fetchColumn(); $conflictScope=$n.' tienda(s) asignada(s)'; }catch (Throwable $e) {}
        }
        $_SESSION['login_conflict']=[
            'user_id'=>(int)$row['id'],
            'nombre'=>$row['nombre'],
            'rol'=>$row['rol'],
            'localidad'=>$conflictScope,
            'estacion'=>$row['estacion_nombre']?:'Sin estación',
            'ultima_actividad'=>$row['ultima_actividad'],
            'challenge'=>bin2hex(random_bytes(24)),
            'expires_at'=>time()+300
        ];
        redirect('index.php?conflict=1');
    }
    establish_login($row);
}catch (Throwable $e) {unset($_SESSION['login_conflict']);$_SESSION['login_error']='No se pudo reemplazar la sesión activa. Verifica la conexión con la base de datos e inténtalo nuevamente.';redirect('index.php');}
