<?php
require_once __DIR__.'/../includes/helpers.php';
require_role(['ADMINISTRADOR','SUPERVISOR','MONITOR','CONSULTA']);
$pdo=db();
$loc=current_localidad_id();
$locationName='Todas las localidades';
if ($loc!==null) {$st=$pdo->prepare('SELECT nombre FROM localidades WHERE id=?');$st->execute([$loc]);$locationName=$st->fetchColumn()?:'Localidad';}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>VIVA · Monitor · <?=e($locationName)?></title><link rel="stylesheet" href="<?=app_url('assets/css/app.css')?>?v=11">
<script src="<?=app_url('assets/js/theme.js')?>?v=1"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"></head>
<body class="monitor-body"><button type="button" id="themeToggle" class="theme-toggle monitor-theme-toggle" onclick="toggleVivaTheme()" aria-label="Cambiar a modo oscuro"></button>
<div class="monitor-overlay">
  <header class="monitor-head">
    <div class="monitor-brand">
      <img class="monitor-logo" src="<?=app_url('assets/img/viva-logo.png')?>" alt="VIVA">
      <div class="monitor-location"><i class="bi bi-geo-alt-fill"></i> <?=e($locationName)?></div>
    </div>
    <div class="monitor-tools">
      <button id="soundBtn" class="monitor-sound" onclick="enableSound()"><i class="bi bi-volume-up-fill"></i><span>Activar sonido</span></button>
      <div class="monitor-clock"><small id="date"></small><strong id="clock"></strong></div>
    </div>
  </header>

  <main class="monitor-main">
    <section class="monitor-card current">
      <div class="monitor-section-title"><span><i class="bi bi-volume-up-fill"></i> Turno actual</span><span id="liveState" class="monitor-live"><i></i> En espera</span></div>
      <div class="current-body">
        <div class="current-ticket-wrap"><div class="current-label">AHORA ATENDIENDO</div><div id="ticket" class="ticket">—</div></div>
        <div class="current-info">
          <div class="current-service-label">SERVICIO</div>
          <div id="service" class="current-service">Esperando próximo llamado</div>
          <div class="station-box"><span>DIRÍJASE A</span><strong id="station">—</strong></div>
        </div>
      </div>
    </section>

    <section class="monitor-card monitor-table">
      <div class="monitor-section-title"><span><i class="bi bi-clock-history"></i> Últimos turnos llamados</span><small>Últimos 3</small></div>
      <table class="monitor-history"><thead><tr><th>Turno</th><th>Servicio</th><th>Estación</th><th>Hora</th></tr></thead><tbody id="history"></tbody></table>
    </section>
  </main>

  <section class="monitor-message"><div><strong>Estamos de tu lado</strong>
<span>Mantén tu turno a la mano. Te avisaremos cuando sea tu momento.</span></div><div class="monitor-location-mini"><i class="bi bi-geo-alt-fill">
</i> <?=e($locationName)?></div></section>
  <footer class="monitor-footer"><span><i class="bi bi-volume-up-fill"></i> Sistema de turnos VIVA</span><span>Gracias por tu preferencia.</span></footer>
</div>
<script>
let lastCall='';let soundEnabled=false;
function enableSound(){soundEnabled=true;
const u=new SpeechSynthesisUtterance('Sonido activado. Sistema de turnos VIVA listo.'); u.lang='es-DO'; u.rate=.9;
speechSynthesis.cancel(); speechSynthesis.speak(u);
document.getElementById('soundBtn').innerHTML='<i class="bi bi-volume-up-fill">
</i>
<span>Sonido activo</span>';document.getElementById('soundBtn').classList.add('active');document.getElementById('soundBtn').disabled=true;localStorage.setItem('viva_turnos_sound','1');}
function speak(t){if(!soundEnabled||!('speechSynthesis' in window))return; speechSynthesis.cancel();
const u=new SpeechSynthesisUtterance(t); u.lang='es-DO'; u.rate=.88; u.pitch=1; speechSynthesis.speak(u)}
function clock(){const d=new Date();
document.getElementById('date').textContent=d.toLocaleDateString('es-DO',{weekday:'long',day:'2-digit',month:'long',year:'numeric'});
document.getElementById('clock').textContent=d.toLocaleTimeString('es-DO',{hour:'2-digit',minute:'2-digit',second:'2-digit'})}
function escapeHtml(v){return String(v??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[m]))}
async function refresh(){try{const r=await fetch('<?=app_url('api/monitor.php')?>',{cache:'no-store'}),d=await r.json();
const state=document.getElementById('liveState');
if(d.current){document.getElementById('ticket').textContent=d.current.codigo;
document.getElementById('service').textContent=d.current.servicio;
document.getElementById('station').textContent=d.current.estacion||'Estación asignada'; state.innerHTML='<i>
</i> Llamando';state.classList.add('calling');const callKey=d.current.id+':'+(d.current.llamado_seq||1);if(lastCall!==''&&lastCall!==callKey)speak(`Turno ${d.current.codigo}. Diríjase a la ${d.current.estacion||'estación asignada'}.`);lastCall=callKey;}else{document.getElementById('ticket').textContent='—';document.getElementById('service').textContent='Esperando próximo llamado';document.getElementById('station').textContent='—';state.innerHTML='<i>
</i> En espera';state.classList.remove('calling');}document.getElementById('history').innerHTML=(d.history||[]).map(x=>`<tr><td>
<strong>${escapeHtml(x.codigo)}</strong></td><td>${escapeHtml(x.servicio)}</td><td>${escapeHtml(x.estacion||'—')}</td><td>
<strong>${escapeHtml(x.hora)}</strong></td></tr>`).join('')}catch(e){}}
clock();setInterval(clock,1000);refresh();setInterval(refresh,1800);
if(localStorage.getItem('viva_turnos_sound')==='1'){document.getElementById('soundBtn').innerHTML='<i class="bi bi-volume-up-fill"></i>
<span>Sonido activo</span>';document.getElementById('soundBtn').classList.add('active');soundEnabled=true;}
</script></body></html>
