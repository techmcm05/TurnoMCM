<?php
$title='Representante';
require_once __DIR__.'/../includes/header.php';
require_role(['ADMINISTRADOR','SUPERVISOR','REPRESENTANTE','DEALER']);
$pdo=db();
$uid=(int)user()['id'];
$role=user()['rol'];
$loc=current_localidad_id();

$st=$pdo->prepare("SELECT t.*,s.nombre servicio,s.prefijo,e.nombre estacion,l.nombre localidad,c.nombre clasificacion FROM turnos t JOIN servicios s ON s.id=t.servicio_id LEFT JOIN estaciones e ON e.id=t.estacion_id LEFT JOIN localidades l ON l.id=t.localidad_id LEFT JOIN clasificaciones c ON c.id=t.clasificacion_id WHERE t.atendiente_id=? AND t.estado='ATENDIENDO' ORDER BY t.hora_llamado DESC LIMIT 1");
$st->execute([$uid]); $current=$st->fetch();

$availabilityStmt=$pdo->prepare("SELECT estado,motivo,detalle,inicio FROM estados_personal WHERE usuario_id=? AND activo=1 ORDER BY inicio DESC LIMIT 1");
$availabilityStmt->execute([$uid]);
$availability=$availabilityStmt->fetch() ?: ['estado'=>'DISPONIBLE','motivo'=>'Disponible','detalle'=>null,'inicio'=>null];
$isReceiving = (($availability['estado'] ?? 'DISPONIBLE') === 'DISPONIBLE');

$where="t.fecha=? AND t.estado='ESPERANDO'"; $params=[date('Y-m-d')];
if ($loc!==null) {$where.=' AND t.localidad_id=?';$params[]=$loc;}
if ($role==='REPRESENTANTE') {$where .= " AND EXISTS(SELECT 1 FROM servicios sx WHERE sx.id=t.servicio_id AND sx.destino IN ('REPRESENTANTE','AMBOS'))";} elseif ($role==='DEALER') {$where .= " AND EXISTS(SELECT 1 FROM servicios sx WHERE sx.id=t.servicio_id AND sx.destino IN ('DEALER','AMBOS'))";}
$st=$pdo->prepare("SELECT t.*,s.nombre servicio FROM turnos t JOIN servicios s ON s.id=t.servicio_id WHERE $where ORDER BY t.prioridad DESC,t.hora_creacion LIMIT 15");$st->execute($params);$queue=$st->fetchAll();

$serviceWhere='t.fecha=? AND t.estado=\'ESPERANDO\''; $serviceParams=[date('Y-m-d')];
if ($loc!==null) {$serviceWhere.=' AND t.localidad_id=?';$serviceParams[]=$loc;}
$destFilter=$role==='DEALER'?['DEALER','AMBOS']:['REPRESENTANTE','AMBOS'];$destIn=implode(',',array_fill(0,count($destFilter),'?'));$serviceSql="SELECT s.id,s.nombre,s.prefijo,COUNT(t.id) esperando,MIN(t.hora_creacion) primera FROM servicios s LEFT JOIN turnos t ON t.servicio_id=s.id AND $serviceWhere WHERE s.activo=1 AND s.destino IN ($destIn) GROUP BY s.id,s.nombre,s.prefijo ORDER BY s.orden,s.nombre";
$ss=$pdo->prepare($serviceSql);$ss->execute(array_merge($serviceParams,$destFilter));$services=$ss->fetchAll();

$recent=[];
if ($loc!==null) {
  $rr=$pdo->prepare("SELECT t.*,s.nombre servicio FROM turnos t JOIN servicios s ON s.id=t.servicio_id WHERE t.localidad_id=? AND t.atendiente_id=? ORDER BY COALESCE(t.hora_finalizacion,t.hora_llamado,t.hora_creacion) DESC LIMIT 6");
  $rr->execute([$loc,$uid]);$recent=$rr->fetchAll();
}
$classifications=$pdo->query("SELECT * FROM clasificaciones WHERE activo=1 ORDER BY nombre")->fetchAll();
$transferDest=$role==='DEALER'?['DEALER','AMBOS']:['REPRESENTANTE','AMBOS'];$in=implode(',',array_fill(0,count($transferDest),'?'));$st=$pdo->prepare("SELECT id,nombre FROM servicios WHERE activo=1 AND destino IN ($in) ORDER BY orden,nombre");$st->execute($transferDest);$transferServices=$st->fetchAll();

function duration_seconds(?string $from, ?string $to=null): int { if (!$from)return 0; $a=strtotime($from);$b=$to?strtotime($to):time();return max(0,$b-$a); }
function duration_hms(int $s): string { $s=max(0,$s);return sprintf('%02d:%02d:%02d',floor($s/3600),floor(($s%3600)/60),$s%60); }
$wait=$current?duration_seconds($current['hora_creacion'],$current['hora_llamado']??$current['hora_inicio']):0;
$statusLabel=['FINALIZADO'=>'Completado','AUSENTE'=>'Ausente','CANCELADO'=>'Cancelado','ATENDIENDO'=>'En servicio'];
?>
<div class="rep-v9-head">
  <div class="rep-v9-title">
    <div class="rep-v9-title-icon"><i class="bi bi-headset"></i></div>
    <div><h1><?= $role==='DEALER' ? 'Dealer' : 'Representante' ?></h1><p>Gestión de turnos y atención al cliente</p></div>
  </div>
  <div class="rep-v9-context"><span class="rep-v9-online"><i></i> En línea</span>
<span class="rep-availability-pill <?= $isReceiving?'receiving':'paused' ?>">
<i class="bi <?= $isReceiving?'bi-person-check-fill':'bi-person-x-fill' ?>"></i> <?= $isReceiving?'Recibiendo clientes':'No recibiendo clientes' ?>
</span><span><i class="bi bi-geo-alt-fill"></i> <?=e(user()['localidad']??'Localidad')?></span><span><i class="bi bi-display">
</i> <?=e(user()['estacion']??'Estación')?></span></div>
</div>

<div class="rep-v9-actions">
  <?php if ($isReceiving): ?><button class="rep-v9-action next" type="button" onclick="siguiente()"><i class="bi bi-volume-up-fill"></i><span><b>Siguiente</b><small>Llamar próximo turno</small></span></button><?php else: ?><div class="rep-v9-action disabled"><i class="bi bi-pause-circle-fill"></i><span><b>Siguiente</b><small>No recibe nuevos turnos</small></span></div><?php endif; ?>
  <?php if ($current): ?>
  <button class="rep-v9-action repeat" type="button" onclick="accion(<?=$current['id']?>,'repetir')"><i class="bi bi-arrow-repeat"></i><span>
<b>Llamar otra vez</b><small>Repetir llamado</small></span></button>
  <button class="rep-v9-action absent" type="button" onclick="accion(<?=$current['id']?>,'ausente')"><i class="bi bi-person-dash-fill"></i><span>
<b>Ausente</b><small>Marcar cliente</small></span></button>
  <button class="rep-v9-action transfer" type="button" onclick="openTransfer()"><i class="bi bi-arrow-left-right"></i><span><b>Transferir</b>
<small>Enviar a otro servicio</small></span></button>
  <button class="rep-v9-action complete" type="button" onclick="accion(<?=$current['id']?>,'completar')"><i class="bi bi-check-circle-fill"></i><span>
<b>Completar</b><small>Finalizar atención</small></span></button>
  <?php else: ?>
  <div class="rep-v9-action disabled"><i class="bi bi-arrow-repeat"></i><span><b>Llamar otra vez</b><small>Sin turno activo</small></span></div>
  <div class="rep-v9-action disabled"><i class="bi bi-person-dash-fill"></i><span><b>Ausente</b><small>Sin turno activo</small></span></div>
  <div class="rep-v9-action disabled"><i class="bi bi-arrow-left-right"></i><span><b>Transferir</b><small>Sin turno activo</small></span></div>
  <div class="rep-v9-action disabled"><i class="bi bi-check-circle-fill"></i><span><b>Completar</b><small>Sin turno activo</small></span></div>
  <?php endif; ?>
  <?php if ($isReceiving): ?>
  <button class="rep-v9-action availability" type="button" onclick="openAvailability()"><i class="bi bi-pause-circle-fill"></i><span>
<b>No recibir clientes</b><small>Seleccionar motivo</small></span></button>
  <?php else: ?>
  <button class="rep-v9-action availability paused-action" type="button" onclick="openAvailability()"><i class="bi bi-play-circle-fill"></i><span>
<b>Volver a recibir</b><small><?=e($availability['motivo']??'Pausa operativa')?></small></span></button>
  <?php endif; ?>
</div>

<div class="rep-v9-grid">
  <main class="rep-v9-main">
    <section class="rep-v9-panel current">
      <div class="rep-v9-panel-head"><div><i class="bi bi-ticket-perforated-fill"></i><strong>Turno actual</strong></div><?php if($current): ?>
<span class="rep-v9-status active">En servicio</span><?php else: ?><span class="rep-v9-status ready">Disponible</span><?php endif; ?></div>
      <?php if ($current): ?>
      <div class="rep-v9-current-row">
        <div class="rep-v9-ticket"><small>Turno</small><strong><?=e(ticket_code($current['prefijo'],$current['numero']))?></strong></div>
        <div class="rep-v9-metric wait"><i class="bi bi-hourglass-split"></i><div><small>Tiempo de espera</small>
<strong id="waitCustomer" title="Tiempo transcurrido desde la creación del turno"><?=e(duration_hms($wait))?></strong></div></div>
        <div class="rep-v9-metric service"><i class="bi bi-stopwatch-fill"></i><div><small>Tiempo en atención</small><strong id="serviceTimer">00:00:00</strong></div></div>
        <div class="rep-v9-meta"><small>Servicio</small><strong><?=e($current['servicio'])?></strong><small>Hora de llegada</small><b>
<?=e(date('h:i:s a',strtotime($current['hora_creacion'])))?></b></div>
      </div>
      <div class="rep-v9-detail-strip">
        <span><i class="bi bi-person-fill"></i> Cliente: <b>No identificado</b></span>
        <span><i class="bi bi-display"></i> <?=e($current['estacion']??'Sin estación')?></span>
        <span><i class="bi bi-geo-alt-fill"></i> <?=e($current['localidad']??'')?></span>
        <span class="good"><i class="bi bi-check-circle-fill"></i> En servicio</span>
      </div>
      <?php else: ?><?php if ($isReceiving): ?><div class="rep-v9-empty"><i class="bi bi-headset"></i><div><strong>Listo para atender</strong><span>La cola se asigna automáticamente. Pulsa Siguiente cuando quieras llamar al próximo cliente.</span></div><button type="button" onclick="siguiente()"><i class="bi bi-volume-up-fill"></i> Siguiente</button></div><?php else: ?><div class="rep-v9-empty paused-empty"><i class="bi bi-pause-circle-fill"></i><div><strong>No estás recibiendo clientes</strong><span>Motivo: <?=e($availability['motivo']??'Pausa operativa')?><?php if (!empty($availability['detalle'])): ?> · <?=e($availability['detalle'])?><?php endif; ?></span></div><button type="button" onclick="volverARecibir()"><i class="bi bi-play-fill"></i> Volver a recibir</button></div><?php endif; ?><?php endif; ?>
    </section>

    <section class="rep-v9-panel">
      <div class="rep-v9-panel-head"><div><i class="bi bi-pencil-square"></i><strong>Notas y clasificación</strong></div></div>
      <div class="rep-v9-notes-grid"><div><label>Notas de la atención</label>
<textarea id="notas" maxlength="500" rows="4" placeholder="Escribe aquí las notas del cliente..."><?=e($current['notas']??'')?></textarea>
<small>Máximo 500 caracteres</small></div><div><label>Clasificación <span style="color:#dc3545">*</span></label>
<select id="clasificacion" class="form-select" required><option value="">Seleccionar clasificación...</option>
<?php foreach($classifications as $c): ?>
<option value="<?=$c['id']?>" <?=$current && (int)$current['clasificacion_id']===(int)$c['id']?'selected':''?>><?=e($c['nombre'])?></option>
<?php endforeach; ?></select></div></div>
      <div class="rep-v9-chips"><b>Clasificaciones rápidas</b><?php foreach($classifications as $c): ?>
<button type="button" data-id="<?=$c['id']?>" onclick="selectClass(<?=$c['id']?>)"><?=e($c['nombre'])?></button><?php endforeach; ?></div>
    </section>

    <section class="rep-v9-panel upcoming-panel">
      <div class="rep-v9-panel-head"><div><i class="bi bi-list-ol"></i><strong>Próximos turnos que podrían asignarse</strong></div>
<span class="upcoming-note">Se actualiza automáticamente</span></div>
      <div id="upcomingQueue" class="rep-v9-upcoming">
        <?php if ($queue): foreach (array_slice($queue,0,5) as $q): ?>
          <div class="upcoming-row"><div><strong><?=e(ticket_code($q['prefijo'],$q['numero']))?></strong><span><?=e($q['servicio'])?></span></div>
<div><small>Espera</small><b class="upcoming-wait" data-created="<?=e($q['hora_creacion'])?>">00:00:00</b></div></div>
        <?php endforeach; else: ?><div class="rep-v9-muted">No hay turnos compatibles esperando en este momento.</div><?php endif; ?>
      </div>
    </section>

  </main>

  <aside class="rep-v9-side">
    <section class="rep-v9-panel"><div class="rep-v9-panel-head"><div><i class="bi bi-clock-history"></i><strong>Mis últimos turnos</strong></div>
<a href="#">Ver todos</a></div><div class="rep-v9-table recent"><div class="head"><span>Turno</span><span>Servicio</span><span>Estado</span>
<span>Hora</span></div><?php foreach($recent as $r): ?><div class="row"><strong><?=e(ticket_code($r['prefijo'],$r['numero']))?></strong><span>
<?=e($r['servicio'])?></span><em class="<?=strtolower($r['estado'])?>"><?=e($statusLabel[$r['estado']]??$r['estado'])?></em><span>
<?=e(date('H:i',strtotime($r['hora_finalizacion']??$r['hora_llamado']??$r['hora_creacion'])))?></span></div><?php endforeach; if(!$recent): ?>
<div class="rep-v9-muted">Aún no tienes turnos recientes.</div><?php endif; ?></div></section>
    <section class="rep-v9-panel"><div class="rep-v9-panel-head"><div><i class="bi bi-hourglass-split"></i><strong>Próximos turnos</strong></div>
</div><div id="queueList" class="rep-v9-queue"><?php foreach($queue as $q): ?><div><span><b><?=e(ticket_code($q['prefijo'],$q['numero']))?></b><small>
<?=e($q['servicio'])?></small></span><em class="wait-time" data-created="<?=e($q['hora_creacion'])?>">00:00:00</em></div>
<?php endforeach; if(!$queue): ?><div class="rep-v9-muted">No hay turnos esperando.</div><?php endif; ?></div></section>
  </aside>
</div>

<div class="modal-backdrop" id="availabilityModal"><div class="modal-box rep-modal availability-modal"><div class="modal-heading"><div>
<h2 id="availabilityTitle"><?= $isReceiving ? 'No recibir clientes' : 'Volver a recibir clientes' ?></h2><small style="color:var(--muted)">
<?= $isReceiving ? 'Selecciona el motivo por el que temporalmente no recibirás nuevos turnos.' : 'Al confirmar volverás a estar disponible para recibir nuevos turnos.' ?>
</small><?php if($isReceiving && $current): ?><div class="availability-inline-warning"><i class="bi bi-info-circle-fill">
</i> Tu atención actual continuará normalmente. Al terminar no se te asignará un nuevo turno hasta volver a estar disponible.</div><?php endif; ?>
</div><button type="button" class="btn btn-light" onclick="closeAvailability()"><i class="bi bi-x-lg"></i></button></div><?php if($isReceiving): ?>
<div class="availability-reasons"><button type="button" onclick="setAvailabilityReason(this,'Cambio a Recepción')"><i class="bi bi-arrow-left-right">
</i>Cambio a Recepción</button><button type="button" onclick="setAvailabilityReason(this,'Soporte Administrativo')"><i class="bi bi-briefcase-fill">
</i>Soporte Administrativo</button><button type="button" onclick="setAvailabilityReason(this,'Almuerzo')"><i class="bi bi-cup-hot-fill">
</i>Almuerzo</button><button type="button" onclick="setAvailabilityReason(this,'Break')"><i class="bi bi-clock-fill"></i>Break</button>
<button type="button" onclick="setAvailabilityReason(this,'Reunión')"><i class="bi bi-people-fill"></i>Reunión</button>
<button type="button" onclick="setAvailabilityReason(this,'No disponible')"><i class="bi bi-person-x-fill"></i>No disponible</button>
<button type="button" onclick="setAvailabilityReason(this,'Otros')"><i class="bi bi-three-dots"></i>Otros</button></div>
<input type="hidden" id="availabilityReason" value=""><div><label class="form-label">Detalle (opcional)</label>
<textarea id="availabilityDetail" class="form-control" rows="3" maxlength="500" placeholder="Agrega un detalle si es necesario..."></textarea></div>
<div class="action-row" style="justify-content:flex-end;margin-top:18px">
<button type="button" class="btn btn-light" onclick="closeAvailability()">Cancelar</button>
<button type="button" class="btn btn-primary" id="availabilityConfirm" onclick="confirmNoReceiving()" disabled>Confirmar estado</button></div>
<?php else: ?><div class="availability-current"><span class="status-icon paused"><i class="bi bi-pause-circle-fill"></i></span><div>
<strong>No recibiendo clientes</strong><p>Motivo actual: <b><?=e($availability['motivo']??'Pausa operativa')?></b>
<?php if(!empty($availability['detalle'])): ?><br><?=e($availability['detalle'])?><?php endif; ?></p></div></div>
<div class="action-row" style="justify-content:flex-end;margin-top:18px">
<button type="button" class="btn btn-light" onclick="closeAvailability()">Cancelar</button>
<button type="button" class="btn btn-primary" onclick="volverARecibir()"><i class="bi bi-play-fill"></i> Volver a recibir clientes</button></div>
<?php endif; ?></div></div>
<?php if ($current): ?><div class="modal-backdrop" id="transferModal"><div class="modal-box rep-modal"><div class="modal-heading"><div><h2>Transferir turno</h2><small style="color:var(--muted)">Selecciona el servicio al que continuará el cliente.</small></div><button type="button" class="btn btn-light" onclick="closeTransfer()"><i class="bi bi-x-lg"></i></button></div><div class="transfer-grid"><?php foreach ($transferServices as $s): ?><button type="button" class="transfer-option" onclick="transferir(<?=$s['id']?>)"><i class="bi bi-arrow-right-circle"></i><?=e($s['nombre'])?></button><?php endforeach; ?></div></div></div><?php endif; ?>
<script>
async function post(data){const r=await fetch('<?=app_url('api/atencion.php')?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({...data,csrf:'<?=csrf_token()?>'})});
return r.json(); }
async function siguiente(){const d=await post({accion:'siguiente'});if(!d.ok)alert(d.error||'No fue posible asignar el turno.');else location.reload();}
async function autoAsignar(){<?php if(!$isReceiving): ?>return; <?php endif;
?>try{const d=await post({accion:'siguiente_auto'}); if(d.ok&&d.waiting&&!d.existing)location.reload(); }catch(e){}}
async function accion(id,a){
  const data={accion:a,id};
  if(a==='completar'){
    const classification=document.getElementById('clasificacion');
    data.clasificacion=classification?.value||'';
    data.notas=document.getElementById('notas')?.value||'';
    if(!data.clasificacion){
      classification?.focus();
      if(classification) classification.classList.add('is-invalid');
      alert('Para completar el ticket debes seleccionar una clasificación.');
      return;
    }
    classification?.classList.remove('is-invalid');
  }
  const d=await post(data);
  if(!d.ok)alert(d.error||'No fue posible completar la acción.');else location.reload();
}
function selectClass(id){document.getElementById('clasificacion').value=id;
document.getElementById('clasificacion')?.classList.remove('is-invalid');
document.querySelectorAll('.rep-v9-chips button').forEach(x=>x.classList.remove('active'));
const b=document.querySelector('.rep-v9-chips button[data-id="'+id+'"]'); if(b)b.classList.add('active'); }
function openTransfer(){const m=document.getElementById('transferModal');
if(m)m.style.display='flex'}function closeTransfer(){const m=document.getElementById('transferModal');
if(m)m.style.display='none'}
function openAvailability(){const m=document.getElementById('availabilityModal');if(m)m.style.display='flex'}
function closeAvailability(){const m=document.getElementById('availabilityModal');if(m)m.style.display='none'}
function setAvailabilityReason(btn,reason){document.getElementById('availabilityReason').value=reason;
document.querySelectorAll('.availability-reasons button').forEach(x=>x.classList.remove('active'));
btn.classList.add('active'); const ok=document.getElementById('availabilityConfirm'); if(ok)ok.disabled=false}
async function confirmNoReceiving(){const reason=document.getElementById('availabilityReason')?.value||''; if(!reason)return;
const detail=document.getElementById('availabilityDetail')?.value||'';
const r=await fetch('<?=app_url('api/estado_personal.php')?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({accion:'activar',motivo:reason,detalle:detail,csrf:'<?=csrf_token()?>'})});
const d=await r.json(); if(!d.ok)return alert(d.error||'No fue posible cambiar tu disponibilidad.'); location.reload()}
async function volverARecibir(){const r=await fetch('<?=app_url('api/estado_personal.php')?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({accion:'disponible',csrf:'<?=csrf_token()?>'})});
const d=await r.json(); if(!d.ok)return alert(d.error||'No fue posible volver a recibir clientes.'); location.reload()}
async function transferir(servicio){const d=await post({accion:'transferir',id:<?=$current['id']??0?>,servicio_id:servicio});
if(!d.ok)alert(d.error||'No fue posible transferir.'); else location.reload(); }
function formatDuration(sec){sec=Math.max(0,Math.floor(sec));
const h=Math.floor(sec/3600),m=Math.floor((sec%3600)/60),s=sec%60;
return String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0'); }
function parseDbDate(v){if(!v)return NaN;const t=new Date(String(v).replace(' ','T'));return t.getTime();}
async function refreshUpcoming(){try{const r=await fetch('<?=app_url('api/proximos.php')?>?t='+Date.now(),{cache:'no-store'});
const d=await r.json(); const box=document.getElementById('upcomingQueue'); if(!box)return; if(!d.ok){return;
}if(!d.items.length){box.innerHTML='<div class="rep-v9-muted">No hay turnos compatibles esperando en este momento.</div>';
return; }box.innerHTML=d.items.map(x=>`<div class="upcoming-row">
<div><strong>${escapeHtml(x.codigo)}</strong><span>${escapeHtml(x.servicio)}</span></div><div><small>Espera</small>
<b class="upcoming-wait" data-created="${escapeHtml(x.hora_creacion)}">00:00:00</b></div></div>`).join('');updateUpcomingWait();}catch(e){}}
function escapeHtml(v){return String(v??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;',"\"":'&quot;'}[m]));}
function updateUpcomingWait(){document.querySelectorAll('.upcoming-wait').forEach(el=>{const t=parseDbDate(el.dataset.created);if(!Number.isNaN(t))el.textContent=formatDuration((Date.now()-t)/1000)});}
function updateWait(){document.querySelectorAll('.wait-time').forEach(el=>{const t=parseDbDate(el.dataset.created);if(!Number.isNaN(t))el.textContent=formatDuration((Date.now()-t)/1000)});
updateUpcomingWait(); }
function updateCurrentWait(){const el=document.getElementById('waitCustomer'); if(!el)return;
const created=parseDbDate('<?=e($current['hora_creacion']??'')?>');
if(!Number.isNaN(created))el.textContent=formatDuration((Date.now()-created)/1000)}
function updateServiceTimer(){const el=document.getElementById('serviceTimer'); if(!el)return;
const t=parseDbDate('<?=e($current['hora_inicio']??$current['hora_llamado']??'')?>');
if(!Number.isNaN(t))el.textContent=formatDuration((Date.now()-t)/1000)}
updateWait(); updateCurrentWait(); updateServiceTimer(); refreshUpcoming(); setInterval(updateWait,1000);
setInterval(updateCurrentWait,1000); setInterval(updateServiceTimer,1000); setInterval(refreshUpcoming,3000);
setInterval(autoAsignar,2500); autoAsignar();
</script>
<?php require __DIR__.'/../includes/footer.php'; ?>
