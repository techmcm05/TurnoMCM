<?php
declare(strict_types=1);
require_once __DIR__.'/config/config.php';

$done=false; $error=null; $messages=[];

function column_exists(PDO $pdo,string $table,string $column): bool {
    $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([DB_NAME,$table,$column]);
    return (bool)$st->fetchColumn();
}
function table_exists(PDO $pdo,string $table): bool {
    $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $st->execute([DB_NAME,$table]);
    return (bool)$st->fetchColumn();
}
function add_column_if_missing(PDO $pdo,string $table,string $column,string $definition): void {
    if (!column_exists($pdo,$table,$column)) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}

function index_exists(PDO $pdo,string $table,string $index): bool {
    $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?");
    $st->execute([DB_NAME,$table,$index]);
    return (bool)$st->fetchColumn();
}
function single_column_unique_indexes(PDO $pdo,string $table,string $column): array {
    $st=$pdo->prepare("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND NON_UNIQUE=0 GROUP BY INDEX_NAME HAVING COUNT(*)=1 AND MAX(CASE WHEN COLUMN_NAME=? THEN 1 ELSE 0 END)=1");
    $st->execute([DB_NAME,$table,$column]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  $pdo=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $pdo->exec("CREATE TABLE IF NOT EXISTS localidades(id INT AUTO_INCREMENT PRIMARY KEY,nombre VARCHAR(120) NOT NULL UNIQUE,codigo VARCHAR(20) NOT NULL UNIQUE,activo TINYINT(1) NOT NULL DEFAULT 1,creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
  add_column_if_missing($pdo,'localidades','cantidad_estaciones','INT NOT NULL DEFAULT 0 AFTER activo');
  add_column_if_missing($pdo,'estaciones','localidad_id','INT NULL');
  // Permite repetir el nombre de una estación en localidades diferentes, pero no dentro de la misma localidad.
  foreach (single_column_unique_indexes($pdo,'estaciones','nombre') as $idx) {
      if ($idx!=='PRIMARY' && strcasecmp($idx,'uq_estaciones_localidad_nombre')!==0) {
          try {$pdo->exec('ALTER TABLE estaciones DROP INDEX `'.str_replace('`','``',$idx).'`');}catch (Throwable $e) {}
      }
  }
  if (!index_exists($pdo,'estaciones','uq_estaciones_localidad_nombre')) {
      $pdo->exec('ALTER TABLE estaciones ADD UNIQUE KEY uq_estaciones_localidad_nombre(localidad_id,nombre)');
  }
  $pdo->exec("UPDATE localidades l SET cantidad_estaciones=(SELECT COUNT(*) FROM estaciones e WHERE e.localidad_id=l.id AND e.activo=1)");
  $messages[]='Cantidad de estaciones por localidad sincronizada.';
  add_column_if_missing($pdo,'usuarios','localidad_id','INT NULL');
  add_column_if_missing($pdo,'usuarios','sesion_activa','TINYINT(1) NOT NULL DEFAULT 0');
  add_column_if_missing($pdo,'usuarios','ultima_actividad','DATETIME NULL');
  add_column_if_missing($pdo,'usuarios','session_token','VARCHAR(64) NULL');
  $pdo->exec("UPDATE usuarios SET sesion_activa=0, ultima_actividad=NULL, session_token=NULL WHERE session_token IS NULL");
  add_column_if_missing($pdo,'turnos','localidad_id','INT NULL');
  add_column_if_missing($pdo,'turnos','llamado_seq','INT NOT NULL DEFAULT 0');
  add_column_if_missing($pdo,'turnos','motivo_cierre','VARCHAR(180) NULL');
  // Asegura el estado VENCIDO para el cierre automático por límite de 2 horas.
  try {
      $pdo->exec("ALTER TABLE turnos MODIFY estado ENUM('ESPERANDO','LLAMADO','ATENDIENDO','FINALIZADO','AUSENTE','CANCELADO','VENCIDO') NOT NULL DEFAULT 'ESPERANDO'");
  } catch (Throwable $e) {}
  $pdo->exec("CREATE TABLE IF NOT EXISTS servicios(id INT AUTO_INCREMENT PRIMARY KEY,nombre VARCHAR(100) NOT NULL UNIQUE,prefijo VARCHAR(5) NOT NULL UNIQUE,descripcion VARCHAR(180) NOT NULL DEFAULT '',icono VARCHAR(80) NOT NULL DEFAULT 'bi-grid',destino ENUM('REPRESENTANTE','DEALER','AMBOS') NOT NULL DEFAULT 'REPRESENTANTE',orden INT NOT NULL DEFAULT 0,activo TINYINT(1) NOT NULL DEFAULT 1)");
  $pdo->exec("CREATE TABLE IF NOT EXISTS clasificaciones(id INT AUTO_INCREMENT PRIMARY KEY,nombre VARCHAR(120) NOT NULL UNIQUE,activo TINYINT(1) NOT NULL DEFAULT 1)");
  $pdo->exec("CREATE TABLE IF NOT EXISTS rol_servicio(rol_id INT NOT NULL,servicio_id INT NOT NULL,PRIMARY KEY(rol_id,servicio_id))");
  // Destino operativo del servicio: REPRESENTANTE, DEALER o AMBOS.
  $col=$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='servicios' AND COLUMN_NAME='destino'")->fetchColumn();
  if (!(int)$col) { $pdo->exec("ALTER TABLE servicios ADD COLUMN destino ENUM('REPRESENTANTE','DEALER','AMBOS') NOT NULL DEFAULT 'REPRESENTANTE' AFTER icono"); }
  // Sincroniza los servicios existentes con la distribución histórica del sistema.
  $pdo->exec("UPDATE servicios SET destino='DEALER' WHERE nombre IN ('Activaciones','Venta de Equipos')");
  $pdo->exec("UPDATE servicios SET destino='DEALER' WHERE nombre='Ventas'");
  try {$pdo->exec("ALTER TABLE estaciones ADD INDEX idx_estacion_localidad(localidad_id)");}catch (Throwable $e) {}
  try {$pdo->exec("ALTER TABLE usuarios ADD INDEX idx_usuario_localidad(localidad_id)");}catch (Throwable $e) {}
  try {$pdo->exec("ALTER TABLE turnos ADD INDEX idx_turno_localidad(fecha,localidad_id,hora_creacion)");}catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE estados_personal MODIFY estado ENUM('DISPONIBLE','NO_RECIBIENDO','ALMUERZO','BREAK','NO_DISPONIBLE','OTROS') NOT NULL DEFAULT 'DISPONIBLE'"); } catch (Throwable $e) {}
  $pdo->exec("CREATE TABLE IF NOT EXISTS estados_personal(
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      usuario_id INT NOT NULL,
      localidad_id INT NULL,
      estado ENUM('DISPONIBLE','NO_RECIBIENDO','ALMUERZO','BREAK','NO_DISPONIBLE','OTROS') NOT NULL,
      motivo VARCHAR(120) NULL,
      detalle VARCHAR(500) NULL,
      inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      fin DATETIME NULL,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
      FOREIGN KEY(localidad_id) REFERENCES localidades(id) ON DELETE SET NULL,
      INDEX idx_estado_usuario_activo(usuario_id,activo),
      INDEX idx_estado_localidad_activo(localidad_id,activo),
      INDEX idx_estado_inicio(inicio)
  )");
  $messages[]='Estados de disponibilidad del personal creados.';
  $messages[]='Sesiones únicas configuradas: una sesión por usuario, con confirmación para reemplazar otra.';
  $messages[]='Inactividad de 3 horas para usuarios operativos; MONITOR queda activo hasta cierre manual.';

  $messages[]='Estructura multi-localidad creada.';
  $messages[]='Nombres de estación independientes por localidad: la misma estación puede existir en distintas tiendas.';
  $messages[]='El administrador define la cantidad de estaciones por localidad; Estación 01, 02, etc. se crean o reactivan automáticamente.';
  $messages[]='Cierre automático de tickets: al superar 2 horas desde su creación quedan como VENCIDO, con motivo y duración total registrados.';

  // Rol Gerente: supervisa múltiples localidades asignadas por el Administrador.
  $pdo->exec("CREATE TABLE IF NOT EXISTS gerente_localidades(
      gerente_id INT NOT NULL,
      localidad_id INT NOT NULL,
      PRIMARY KEY(gerente_id,localidad_id),
      FOREIGN KEY(gerente_id) REFERENCES usuarios(id) ON DELETE CASCADE,
      FOREIGN KEY(localidad_id) REFERENCES localidades(id) ON DELETE CASCADE,
      INDEX idx_gerente_localidad(localidad_id)
  )");
  $messages[]='Rol GERENTE creado con asignación de múltiples localidades.';

  $roles=['ADMINISTRADOR','SUPERVISOR','GERENTE','RECEPCIONISTA','REPRESENTANTE','DEALER','CONSULTA','MONITOR'];
  $st=$pdo->prepare('INSERT IGNORE INTO roles(nombre) VALUES(?)'); foreach ($roles as $r)$st->execute([$r]);
  $pdo->exec("INSERT IGNORE INTO localidades(nombre,codigo,cantidad_estaciones) VALUES('Tienda Principal','TP',6)");
  $loc=(int)$pdo->query("SELECT id FROM localidades ORDER BY id LIMIT 1")->fetchColumn();

  // Assign existing demo records to the first locality when they are currently unassigned.
  $pdo->prepare("UPDATE estaciones SET localidad_id=? WHERE localidad_id IS NULL")->execute([$loc]);
  $pdo->prepare("UPDATE usuarios SET localidad_id=? WHERE localidad_id IS NULL AND rol_id IN (SELECT id FROM roles WHERE nombre IN ('SUPERVISOR','RECEPCIONISTA','REPRESENTANTE','DEALER','MONITOR'))")->execute([$loc]);
  $pdo->prepare("UPDATE turnos SET localidad_id=? WHERE localidad_id IS NULL AND recepcionista_id IS NOT NULL")->execute([$loc]);
  $pdo->exec("UPDATE localidades l SET cantidad_estaciones=(SELECT COUNT(*) FROM estaciones e WHERE e.localidad_id=l.id AND e.activo=1)");

  // Add services required for Dealer routing.
  $services=[['Activaciones','A','Nuevas activaciones de líneas y servicios','bi-phone-fill',7],['Venta de Equipos','E','Venta de equipos y accesorios','bi-phone-vibrate-fill',8]];
  $st=$pdo->prepare('INSERT IGNORE INTO servicios(nombre,prefijo,descripcion,icono,orden) VALUES(?,?,?,?,?)'); foreach ($services as $x)$st->execute($x);

  $dealerId=(int)$pdo->query("SELECT id FROM roles WHERE nombre='DEALER'")->fetchColumn();
  $repId=(int)$pdo->query("SELECT id FROM roles WHERE nombre='REPRESENTANTE'")->fetchColumn();
  $dealerServices=$pdo->query("SELECT id FROM servicios WHERE nombre IN ('Activaciones','Ventas','Venta de Equipos')")->fetchAll(PDO::FETCH_COLUMN);
  $repServices=$pdo->query("SELECT id FROM servicios WHERE nombre NOT IN ('Activaciones','Ventas','Venta de Equipos')")->fetchAll(PDO::FETCH_COLUMN);
  $map=$pdo->prepare('INSERT IGNORE INTO rol_servicio(rol_id,servicio_id) VALUES(?,?)');
  foreach ($dealerServices as $sid)$map->execute([$dealerId,$sid]);
  foreach ($repServices as $sid)$map->execute([$repId,$sid]);
  $dealerFallback=$pdo->query("SELECT id FROM servicios WHERE LOWER(nombre) LIKE '%activ%' OR LOWER(nombre) LIKE '%venta%' OR prefijo IN ('A','E','V')")->fetchAll(PDO::FETCH_COLUMN); foreach ($dealerFallback as $sid)$map->execute([$dealerId,$sid]);

  // Create a demo Dealer and Monitor only if they do not already exist.
  $hash=password_hash('Viva2026!',PASSWORD_DEFAULT);
  $role=$pdo->prepare('SELECT id FROM roles WHERE nombre=?');
  $role->execute(['DEALER']);$dealerRole=(int)$role->fetchColumn();
  $role->execute(['MONITOR']);$monitorRole=(int)$role->fetchColumn();
  $station=$pdo->query("SELECT id FROM estaciones WHERE localidad_id=$loc ORDER BY id LIMIT 1")->fetchColumn();
  $ins=$pdo->prepare('INSERT IGNORE INTO usuarios(nombre,usuario,password_hash,rol_id,estacion_id,localidad_id,activo) VALUES(?,?,?,?,?,?,1)');
  $ins->execute(['Dealer Demo','dealer',$hash,$dealerRole,$station?:null,$loc]);
  $ins->execute(['Monitor Tienda Principal','monitor',$hash,$monitorRole,null,$loc]);

  // Repair the foreign keys only when absent; MariaDB/InfinityFree accepts these ALTERs if not duplicated.
  try { $pdo->exec("ALTER TABLE estaciones ADD CONSTRAINT fk_estaciones_localidad FOREIGN KEY(localidad_id) REFERENCES localidades(id) ON DELETE SET NULL"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE usuarios ADD CONSTRAINT fk_usuarios_localidad FOREIGN KEY(localidad_id) REFERENCES localidades(id) ON DELETE SET NULL"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE turnos ADD CONSTRAINT fk_turnos_localidad FOREIGN KEY(localidad_id) REFERENCES localidades(id) ON DELETE SET NULL"); } catch (Throwable $e) {}

  $done=true;
 }catch (Throwable $e) {$error=$e->getMessage();}
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Actualización · VIVA TURNOS</title><link rel="stylesheet" href="<?=BASE_URL?>/assets/css/app.css"></head><body class="login">
<div class="login-card"><div class="login-brand"><img class="login-logo" src="<?=BASE_URL?>/assets/img/viva-logo.png" alt="VIVA · Estamos de tu lado">
<h1 class="login-title">Actualización VIVA TURNOS</h1></div><?php if($done):?><div class="card" style="background:#effaf0">
<strong>Actualización completada.</strong>
<p>El sistema ahora soporta localidades, monitores por tienda, Dealer, asignación automática de cola y estados de disponibilidad del personal.</p><ul>
<?php foreach($messages as $m):?><li><?=htmlspecialchars($m)?></li><?php endforeach;?></ul>
<a class="btn btn-primary" href="<?=BASE_URL?>/">Ir al inicio</a></div>
<p class="login-sub" style="margin-top:16px">Usuarios demo agregados si no existían: <strong>dealer</strong> y <strong>monitor</strong>. Contraseña inicial: <strong>Viva2026!</strong>
</p><?php else: if($error):?><div class="login-error"><strong>No se pudo actualizar.</strong><br><?=htmlspecialchars($error)?></div><?php endif;?>
<p class="login-sub">Esta operación conserva los usuarios y turnos existentes y agrega la estructura necesaria para las nuevas funciones.</p>
<form method="post"><button class="btn btn-primary" style="width:100%">Actualizar VIVA TURNOS</button></form><?php endif;?></div></body></html>
