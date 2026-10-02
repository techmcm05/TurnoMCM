<?php require_once __DIR__ . '/helpers.php'; require_login(); ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($title ?? APP_NAME)?></title>
<link rel="stylesheet" href="<?=app_url('assets/css/app.css')?>?v=11">
<script src="<?=app_url('assets/js/theme.js')?>?v=1"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="app-shell">
<header class="topbar">
  <div class="brand"><img class="brand-logo" src="<?=app_url('assets/img/viva-logo.png')?>" alt="VIVA · Estamos de tu lado"></div>
  <div class="top-user">
    <button type="button" id="themeToggle" class="theme-toggle" onclick="toggleVivaTheme()" aria-label="Cambiar a modo oscuro"></button>
    <span><?=e(user()['nombre'] ?? '')?></span>
    <span class="role-pill"><?=e(user()['rol'] ?? '')?></span><?php if(!empty(user()['localidad'])): ?><span class="location-pill">
<i class="bi bi-geo-alt-fill"></i> <?=e(user()['localidad'])?></span><?php elseif((user()['rol']??'')==='GERENTE'): ?><span class="location-pill">
<i class="bi bi-buildings-fill"></i> <?=count(visible_localidad_ids()??[])?> tiendas</span><?php endif; ?>
    <?php if (in_array(user()['rol'],['REPRESENTANTE','DEALER'],true)): ?>
    <a href="#" onclick="event.preventDefault();openLogoutModal();"><i class="bi bi-box-arrow-right"></i> Salir</a>
    <?php else: ?>
    <a href="<?=app_url('auth/logout.php')?>"><i class="bi bi-box-arrow-right"></i> Salir</a>
    <?php endif; ?>
  </div>
</header>
<div class="layout">
<aside class="sidebar">
  <a href="<?=app_url('dashboard.php')?>" class="side-item"><i class="bi bi-grid-1x2-fill"></i> Inicio</a>
  <?php if (in_array(user()['rol'],['ADMINISTRADOR','SUPERVISOR','RECEPCIONISTA'],true)): ?>
  <a href="<?=app_url('recepcion/')?>" class="side-item"><i class="bi bi-ticket-perforated-fill"></i> Recepción</a>
  <?php endif; ?>
  <?php if (in_array(user()['rol'],['ADMINISTRADOR','SUPERVISOR','REPRESENTANTE','DEALER'],true)): ?>
  <a href="<?=app_url('representante/')?>" class="side-item"><i class="bi bi-headset"></i> Atención</a>
  <?php endif; ?>
  <?php if (in_array(user()['rol'],['ADMINISTRADOR','SUPERVISOR','GERENTE'],true)): ?>
  <a href="<?=app_url('supervisor/')?>" class="side-item"><i class="bi bi-bar-chart-fill"></i> Supervisor</a>
  <a href="<?=app_url('reportes/')?>" class="side-item"><i class="bi bi-file-earmark-bar-graph-fill"></i> Reportes</a>
  <?php endif; ?>
  <?php if (user()['rol']==='ADMINISTRADOR'): ?>
  <a href="<?=app_url('admin/')?>" class="side-item"><i class="bi bi-gear-fill"></i> Administración</a>
  <?php endif; ?>
  <?php if (in_array(user()['rol'],['ADMINISTRADOR','SUPERVISOR','MONITOR','CONSULTA'],true)): ?><a href="<?=app_url('monitor/')?>" class="side-item"><i class="bi bi-display"></i> Monitor</a><?php endif; ?>
</aside>
<?php if (in_array(user()['rol'],['REPRESENTANTE','DEALER'],true)): ?>
<div class="modal-backdrop" id="logoutModal">
  <div class="modal-box" style="max-width:520px">
    <div class="modal-heading">
      <div><h2 style="margin:0">Salir de la jornada</h2><small style="color:var(--muted)">Indica el motivo para que el supervisor conozca tu disponibilidad.</small></div>
      <button type="button" class="btn btn-light" onclick="closeLogoutModal()"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="post" action="<?=app_url('auth/logout.php')?>" id="logoutForm" style="margin-top:20px">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <label class="form-label">Motivo de salida *</label>
      <select class="form-select" name="motivo" id="logoutMotivo" required onchange="toggleLogoutDetail()">
        <option value="ALMUERZO">Almuerzo</option>
        <option value="BREAK">Break</option>
        <option value="NO_DISPONIBLE">No disponible</option>
        <option value="OTROS">Otros</option>
      </select>
      <div id="logoutDetailBox" style="display:none;margin-top:14px">
        <label class="form-label">Detalle</label>
        <textarea class="form-control" name="detalle" id="logoutDetalle" rows="3" maxlength="500" placeholder="Indica brevemente el motivo..."></textarea>
      </div>
      <div class="action-row" style="justify-content:flex-end;margin-top:22px">
        <button type="button" class="btn btn-light" onclick="closeLogoutModal()">Cancelar</button>
        <button class="btn btn-danger" type="submit"><i class="bi bi-box-arrow-right"></i> Confirmar salida</button>
      </div>
    </form>
  </div>
</div>
<script>
function openLogoutModal(){document.getElementById('logoutModal').style.display='flex';document.getElementById('logoutMotivo').focus();}
function closeLogoutModal(){document.getElementById('logoutModal').style.display='none';}
function toggleLogoutDetail(){const m=document.getElementById('logoutMotivo').value,b=document.getElementById('logoutDetailBox'),d=document.getElementById('logoutDetalle');
b.style.display=m==='OTROS'?'block':'none'; if(m!=='OTROS')d.value=''; }
</script>
<?php endif; ?>
<main class="main-content">
