<?php
require_once __DIR__.'/includes/helpers.php';
if (is_logged()) redirect('dashboard.php');
$error=$_SESSION['login_error']??null; unset($_SESSION['login_error']);
$conflict=$_SESSION['login_conflict']??null;
$replaced=isset($_GET['replaced']);
$timedout=isset($_GET['timeout']);
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>VIVA TURNOS · Acceso</title><link rel="stylesheet" href="<?=app_url('assets/css/app.css')?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="<?=app_url('assets/js/theme.js')?>?v=1"></script></head>
<body class="login">
<button type="button" id="themeToggle" class="theme-toggle theme-toggle-login" onclick="toggleVivaTheme()" aria-label="Cambiar a modo oscuro">
</button><div class="login-card">
<div class="login-brand"><img class="login-logo" src="<?=app_url('assets/img/viva-logo.png')?>" alt="VIVA · Estamos de tu lado">
<h1 class="login-title">VIVA TURNOS</h1><div class="login-sub">Gestión inteligente de atención al cliente</div></div>
<?php if ($error): ?><div class="login-error"><?=e($error)?></div><?php endif; ?>
<?php if ($replaced): ?><div class="login-error"><i class="bi bi-shield-exclamation"></i> Esta sesión fue cerrada porque el usuario inició sesión en otro dispositivo.</div><?php elseif ($timedout): ?><div class="login-error"><i class="bi bi-clock-history"></i> Tu sesión expiró por inactividad.</div><?php endif; ?>
<form method="post" action="<?=app_url('auth/login.php')?>">
<label class="form-label">Usuario</label><input class="form-control" name="usuario" autocomplete="username" required>
<label class="form-label" style="margin-top:14px">Contraseña</label><input class="form-control" type="password" name="password" autocomplete="current-password" required>
<button class="btn btn-primary" style="width:100%;margin-top:20px;padding:14px"><i class="bi bi-box-arrow-in-right"></i> Ingresar al sistema</button>
</form>
<?php if ($conflict && !empty($conflict['challenge'])): ?>
<div class="modal-backdrop" style="display:flex;position:fixed;inset:0;z-index:9999">
  <div class="modal-box" style="max-width:520px;width:92%;background:#fff;border-radius:18px;padding:26px;box-shadow:0 25px 70px rgba(0,0,0,.25)">
    <div style="display:flex;gap:14px;align-items:flex-start">
      <div style="width:46px;height:46px;border-radius:14px;background:#fff4d6;color:#b77900;display:grid;place-items:center;font-size:22px">
<i class="bi bi-exclamation-triangle-fill"></i></div>
      <div><h2 style="margin:0 0 6px">Sesión ya activa</h2><p style="margin:0;color:#64748b">Este usuario tiene una sesión abierta en otro dispositivo o navegador.</p></div>
    </div>
    <div style="margin:20px 0;padding:15px;background:#f7faf8;border-radius:12px;line-height:1.8">
      <strong><?=e($conflict['nombre']??'Usuario')?></strong><br>
      Rol: <?=e($conflict['rol']??'')?> · <?=e($conflict['localidad']??'Sin localidad')?> · <?=e($conflict['estacion']??'Sin estación')?>
      <?php if (!empty($conflict['ultima_actividad'])): ?><br><small style="color:#64748b">Última actividad: <?=e($conflict['ultima_actividad'])?></small><?php endif; ?>
    </div>
    <p style="color:#334155">¿Deseas cerrar la sesión anterior e iniciar una nueva aquí?</p>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
      <a class="btn btn-light" href="<?=app_url('index.php')?>" style="text-decoration:none">Cancelar</a>
      <form method="post" action="<?=app_url('auth/login.php')?>" style="margin:0">
        <input type="hidden" name="confirmar_conflicto" value="1">
        <input type="hidden" name="challenge" value="<?=e($conflict['challenge'])?>">
        <button class="btn btn-primary" type="submit"><i class="bi bi-box-arrow-in-right"></i> Cerrar anterior y continuar</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<div style="text-align:center;color:#819087;font-size:11px;margin-top:20px">Plataforma de gestión de turnos · TECH M.C.M</div>
</div></body></html>
