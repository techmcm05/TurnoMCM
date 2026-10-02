<?php
$title='Supervisor';
require_once __DIR__.'/../includes/header.php';
require_role(['ADMINISTRADOR','SUPERVISOR','GERENTE']);
$pdo=db();
$today=date('Y-m-d');

$loc=current_localidad_id();
$isManager=is_manager();
[$scopeWhere,$scopeParams]=visible_localidad_where('t');
$where='t.fecha=? AND '.$scopeWhere;
$params=array_merge([$today],$scopeParams);
$visibleIds=visible_localidad_ids();

function secfmt_sup($s) {
    if ($s===null) return '—';
    $s=max(0,(int)$s);
    $h=intdiv($s,3600); $m=intdiv($s%3600,60); $ss=$s%60;
    return ($h?$h.':':'').str_pad((string)$m,2,'0',STR_PAD_LEFT).':'.str_pad((string)$ss,2,'0',STR_PAD_LEFT);
}
function wait_level_class($s) { $s=(int)$s; if ($s>=600) return 'wait-critical'; if ($s>=300) return 'wait-warning'; return 'wait-ok'; }
$waitStmt=$pdo->prepare("SELECT t.id,t.prefijo,t.numero,t.hora_creacion,t.prioridad,s.nombre servicio,l.nombre localidad
    FROM turnos t JOIN servicios s ON s.id=t.servicio_id LEFT JOIN localidades l ON l.id=t.localidad_id
    WHERE $where AND t.estado='ESPERANDO'
    ORDER BY t.prioridad DESC,t.hora_creacion ASC");
$waitStmt->execute($params); $wait=$waitStmt->fetchAll();

$summaryStmt=$pdo->prepare("SELECT
    SUM(t.estado='ESPERANDO') espera,
    SUM(t.estado='ATENDIENDO') atendiendo,
    SUM(t.estado='FINALIZADO') finalizados,
    SUM(t.estado='AUSENTE') ausentes,
    SUM(t.estado='CANCELADO') cancelados,
    SUM(t.estado='VENCIDO') vencidos,
    AVG(CASE WHEN t.hora_llamado IS NOT NULL THEN TIMESTAMPDIFF(SECOND,t.hora_creacion,t.hora_llamado) END) espera_prom,
    AVG(CASE WHEN t.hora_inicio IS NOT NULL AND t.hora_finalizacion IS NOT NULL THEN TIMESTAMPDIFF(SECOND,t.hora_inicio,t.hora_finalizacion) END) atencion_prom
    FROM turnos t WHERE $where");
$summaryStmt->execute($params); $sum=$summaryStmt->fetch() ?: [];

// Solo personal con una atención activa. El supervisor puede ver claramente quién está atendiendo ahora.
[$userScopeWhere,$userScopeParams]=visible_localidad_where('u');
$staffStmt=$pdo->prepare("SELECT u.id,u.nombre,r.nombre rol,e.nombre estacion,l.nombre localidad,
    t.id turno_id,t.prefijo,t.numero,s.nombre servicio,t.hora_creacion,t.hora_inicio,t.notas
    FROM usuarios u
    JOIN roles r ON r.id=u.rol_id
    JOIN turnos t ON t.atendiente_id=u.id AND t.fecha=? AND t.estado='ATENDIENDO'
    JOIN servicios s ON s.id=t.servicio_id
    LEFT JOIN estaciones e ON e.id=u.estacion_id
    LEFT JOIN localidades l ON l.id=u.localidad_id
    WHERE r.nombre IN ('REPRESENTANTE','DEALER') AND u.activo=1
    AND $userScopeWhere
    ORDER BY t.hora_inicio ASC,u.nombre");
$staffStmt->execute(array_merge([$today],$userScopeParams)); $staff=$staffStmt->fetchAll();

// Estado de los representantes: conectado/desconectado y estado operativo actual.
$repStmt=$pdo->prepare("SELECT u.id,u.nombre,u.sesion_activa,u.ultima_actividad, l.nombre localidad,
    ep.estado,ep.motivo,ep.detalle,ep.inicio
    FROM usuarios u JOIN roles r ON r.id=u.rol_id
    LEFT JOIN localidades l ON l.id=u.localidad_id
    LEFT JOIN estados_personal ep ON ep.id=(SELECT epx.id FROM estados_personal epx WHERE epx.usuario_id=u.id AND epx.activo=1 ORDER BY epx.inicio DESC LIMIT 1)
    WHERE r.nombre IN ('REPRESENTANTE','DEALER') AND u.activo=1
    AND $userScopeWhere
    ORDER BY (u.sesion_activa=1) DESC,u.nombre ASC");
$repStmt->execute($userScopeParams); $representantes=$repStmt->fetchAll();

$histStmt=$pdo->prepare("SELECT t.id,t.prefijo,t.numero,t.hora_creacion,t.hora_llamado,t.hora_inicio,t.hora_finalizacion,
    t.estado,t.notas,t.motivo_cierre,s.nombre servicio,l.nombre localidad,u.nombre atendiente,e.nombre estacion,c.nombre clasificacion
    FROM turnos t JOIN servicios s ON s.id=t.servicio_id
    LEFT JOIN localidades l ON l.id=t.localidad_id
    LEFT JOIN usuarios u ON u.id=t.atendiente_id
    LEFT JOIN estaciones e ON e.id=t.estacion_id
    LEFT JOIN clasificaciones c ON c.id=t.clasificacion_id
    WHERE $where AND t.estado IN ('FINALIZADO','AUSENTE','CANCELADO','VENCIDO')
    ORDER BY COALESCE(t.hora_finalizacion,t.hora_creacion) DESC LIMIT 100");
$histStmt->execute($params); $history=$histStmt->fetchAll();

$servicesStmt=$pdo->prepare("SELECT s.nombre,
    COUNT(t.id) total,
    SUM(t.estado='ESPERANDO') espera,
    SUM(t.estado='FINALIZADO') finalizados
    FROM servicios s LEFT JOIN turnos t ON t.servicio_id=s.id AND $where
    GROUP BY s.id ORDER BY s.orden,s.nombre");
$servicesStmt->execute($params); $services=$servicesStmt->fetchAll();
?>

<div class="page-title supervisor-page-title">
    <div>
        <h1>Supervisión</h1>
        <p>Control operativo de <?=e($isManager?'tus tiendas asignadas':($loc?'tu localidad':'todas las localidades'))?> en tiempo real.</p>
    </div>
    <a class="btn btn-primary" href="<?=app_url('reportes/') ?>"><i class="bi bi-file-earmark-bar-graph"></i> Reportes</a>
</div>

<div class="supervisor-layout">
    <!-- Atención actual: protagonista del panel -->
    <section class="card supervisor-section attention-section">
        <div class="supervisor-section-head">
            <div>
                <div class="supervisor-kicker"><span class="live-dot"></span> EN TIEMPO REAL</div>
                <h2>Atención actual</h2>
                <p>Clientes que están siendo atendidos en este momento.</p>
            </div>
            <div class="supervisor-count"><strong><?=e((string)(int)($sum['atendiendo']??0))?></strong> en atención</div>
        </div>
        <div class="table-wrap">
            <table class="table supervisor-table attention-table">
                <thead><tr>
                    <th>Personal</th><?php if($isManager):?><th>Localidad</th><?php endif;?><th>Servicio</th><th>Estación</th><th>Turno</th>
<th>Espera</th><th>Atención</th><th>Comentario</th>
                </tr></thead>
                <tbody>
                <?php foreach ($staff as $x):
                    $waitSec=$x['hora_creacion']?max(0,time()-strtotime($x['hora_creacion'])):0;
                    $waitClass=wait_level_class($waitSec);
                ?>
                    <tr>
                        <td><div class="person-cell"><span class="person-avatar"><?=e(strtoupper(substr($x['nombre'],0,1)))?></span><div><strong>
<?=e($x['nombre'])?></strong><small><?=e($x['rol'])?></small></div></div></td>
                        <?php if ($isManager):?><td><?=e($x['localidad']??'—')?></td><?php endif;?>
                        <td><span class="service-pill"><?=e($x['servicio'])?></span></td>
                        <td><?=e($x['estacion']??'Sin asignar')?></td>
                        <td><strong class="ticket-code"><?=e(ticket_code($x['prefijo'],$x['numero']))?></strong></td>
                        <td><span class="time-badge <?=e($waitClass)?> live-wait" data-created="<?=e($x['hora_creacion'])?>">00:00</span></td>
                        <td><span class="time-badge attention-time live-duration" data-start="<?=e($x['hora_inicio'])?>">00:00</span></td>
                        <td><?php if(!empty($x['notas'])):?>
<button class="comment-link" onclick='showNote(<?=json_encode($x['notas'],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>)'>Ver comentario</button>
<?php else:?><span class="muted-dash">—</span><?php endif;?></td>
                    </tr>
                <?php endforeach; if (!$staff):?>
                    <tr><td colspan="<?=$isManager?8:7?>" class="empty-state"><i class="bi bi-headset"></i>
<strong>No hay clientes en atención</strong><span>Cuando un representante o dealer tome un ticket, aparecerá aquí.</span></td></tr>
                <?php endif;?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Cola actual -->
    <section class="card supervisor-section waiting-section">
        <div class="supervisor-section-head compact-head">
            <div><h2>Tickets en espera</h2><p>Clientes aguardando atención.</p></div>
            <div class="queue-number"><?=count($wait)?></div>
        </div>
        <div class="table-wrap">
            <table class="table supervisor-table waiting-table">
                <thead><tr><th>Turno</th><?php if($isManager):?><th>Localidad</th><?php endif;?><th>Servicio</th><th>Espera</th></tr></thead>
                <tbody>
                <?php foreach ($wait as $w):?>
                    <tr>
                        <td><strong class="ticket-code"><?=e(ticket_code($w['prefijo'],$w['numero']))?></strong><?php if((int)$w['prioridad']>0):?>
<span class="priority-dot" title="Prioridad"></span><?php endif;?></td>
                        <?php if ($isManager):?><td><?=e($w['localidad']??'—')?></td><?php endif;?>
                        <td><?=e($w['servicio'])?></td>
                        <td>
<span class="time-badge <?=e(wait_level_class(max(0,time()-strtotime($w['hora_creacion']))) )?> wait-time" data-created="<?=e($w['hora_creacion'])?>">00:00</span>
</td>
                    </tr>
                <?php endforeach; if (!$wait):?>
                    <tr><td colspan="<?=$isManager?4:3?>" class="empty-state small"><i class="bi bi-check-circle"></i><strong>Cola vacía</strong>
<span>No hay clientes esperando.</span></td></tr>
                <?php endif;?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Estado del personal: vista horizontal -->
    <?php
    $connectedCount=0; $availableCount=0; $pausedCount=0;
    foreach ($representantes as $r) {
        $connected=(int)$r['sesion_activa']===1;
        $last=$r['ultima_actividad']?strtotime($r['ultima_actividad']):0;
        if ($connected && $last>0 && (time()-$last)>=SESSION_IDLE_TIMEOUT) {$connected=false;}
        if ($connected) $connectedCount++;
        $state=$r['estado']?:($connected?'DISPONIBLE':'NO_DISPONIBLE');
        if ($state==='DISPONIBLE' && $connected) $availableCount++;
        if ($state==='NO_RECIBIENDO') $pausedCount++;
    }
    ?>
    <section class="card supervisor-section personnel-section">
        <div class="supervisor-section-head personnel-section-head">
            <div>
                <div class="supervisor-kicker"><i class="bi bi-people"></i> EQUIPO</div>
                <h2>Estado del personal</h2>
                <p>Disponibilidad y estado operativo del equipo en tiempo real.</p>
            </div>
            <div class="personnel-summary" id="personnelSummary">
                <span class="personnel-summary-item connected"><b id="personnelConnectedCount"><?=$connectedCount?></b> conectados</span>
                <span class="personnel-summary-item available"><b id="personnelAvailableCount"><?=$availableCount?></b> disponibles</span>
                <span class="personnel-summary-item paused"><b id="personnelPausedCount"><?=$pausedCount?></b> no reciben clientes</span>
            </div>
        </div>
        <div class="personnel-table-wrap">
            <div class="personnel-grid personnel-grid-head">
                <div>Personal</div>
                <?php if ($isManager):?><div>Localidad</div><?php endif;?>
                <div>Estado</div>
                <div>Motivo</div>
                <div>Desde</div>
                <div>Tiempo en estado</div>
                <div>Conexión</div>
            </div>
            <div class="personnel-grid-body" id="personnelList">
                <?php foreach ($representantes as $r):
                    $connected=(int)$r['sesion_activa']===1;
                    $last=$r['ultima_actividad']?strtotime($r['ultima_actividad']):0;
                    if ($connected && $last>0 && (time()-$last)>=SESSION_IDLE_TIMEOUT) {$connected=false;}
                    $state=$r['estado']?:($connected?'DISPONIBLE':'NO_DISPONIBLE');
                    $stateLabel=['DISPONIBLE'=>'Disponible','NO_RECIBIENDO'=>'No recibe clientes','ALMUERZO'=>'Almuerzo','BREAK'=>'Break','NO_DISPONIBLE'=>'No disponible','OTROS'=>'Otros'][$state]??ucfirst(strtolower($state));
                    $stateClass=$state==='NO_RECIBIENDO'?'person-state-paused':($state==='DISPONIBLE'?'person-state-ready':'person-state-other');
                    $stateSince=$r['inicio']?strtotime($r['inicio']):0;
                    $motive=$state==='NO_RECIBIENDO'?($r['motivo']?:'Pausa operativa'):'';
                    $detail=$state==='NO_RECIBIENDO'?($r['detalle']?:''):'';
                ?>
                    <div class="personnel-grid personnel-row" data-person-id="<?=e((string)$r['id'])?>">
                        <div class="person-cell personnel-person">
                            <span class="person-avatar"><?=e(strtoupper(substr($r['nombre'],0,1)))?></span>
                            <div><strong><?=e($r['nombre'])?></strong><small><?=e($r['rol'])?></small></div>
                        </div>
                        <?php if ($isManager):?><div class="personnel-locality"><?=e($r['localidad']??'—')?></div><?php endif;?>
                        <div><span class="person-state-pill <?=e($stateClass)?>"><?=e($stateLabel)?></span></div>
                        <div class="personnel-motive">
                            <?php if ($motive): ?><span class="person-state-reason"><i class="bi bi-info-circle"></i> <?=e($motive)?></span><?php else: ?><span class="personnel-dash">—</span><?php endif; ?>
                            <?php if ($detail): ?><span class="person-state-detail" title="<?=e($detail)?>"><?=e($detail)?></span><?php endif; ?>
                        </div>
                        <div><span class="person-state-since" data-since="<?=e($r['inicio']??'')?>"><?= $stateSince ? e(date('h:i A',$stateSince)) : '—' ?></span></div>
                        <div><span class="personnel-elapsed" data-since="<?=e($r['inicio']??'')?>"><?= $stateSince ? e(secfmt_sup(max(0,time()-$stateSince))) : '—' ?></span></div>
                        <div class="personnel-status-wrap"><span class="presence-dot <?= $connected?'presence-on':'presence-off'?>"></span>
<span class="presence-label <?= $connected?'is-on':'is-off'?>"><?= $connected?'Activo':'No activo'?></span></div>
                    </div>
                <?php endforeach; if (!$representantes):?>
                    <div class="personnel-empty">No hay representantes ni dealers registrados en las localidades visibles.</div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Historial reciente -->
    <section class="card supervisor-section history-section">
        <div class="supervisor-section-head compact-head">
            <div><h2>Historial reciente</h2><p>Últimos tickets finalizados y su resultado.</p></div>
            <span class="subtle-count"><?=count($history)?> registros</span>
        </div>
        <div class="table-wrap">
            <table class="table supervisor-table history-table">
                <thead><tr><th>Turno</th><?php if($isManager):?><th>Localidad</th><?php endif;?><th>Personal</th><th>Servicio</th><th>Espera</th>
<th>Atención</th><th>Cierre</th><th>Resultado</th></tr></thead>
                <tbody>
                <?php foreach ($history as $h):
                    $waitSec=$h['hora_llamado']?strtotime($h['hora_llamado'])-strtotime($h['hora_creacion']):null;
                    $attSec=($h['hora_inicio']&&$h['hora_finalizacion'])?strtotime($h['hora_finalizacion'])-strtotime($h['hora_inicio']):null;
                    $result=$h['motivo_cierre']?:$h['clasificacion']?:$h['estado'];
                ?>
                    <tr>
                        <td><strong class="ticket-code"><?=e(ticket_code($h['prefijo'],$h['numero']))?></strong></td>
                        <?php if ($isManager):?><td><?=e($h['localidad']??'—')?></td><?php endif;?>
                        <td><strong><?=e($h['atendiente']??'—')?></strong><small class="table-sub"><?=e($h['estacion']??'')?></small></td>
                        <td><?=e($h['servicio'])?></td>
                        <td><span class="history-time <?=e(wait_level_class((int)($waitSec??0)))?>"><?=e(secfmt_sup($waitSec))?></span></td>
                        <td><?=e(secfmt_sup($attSec))?></td>
                        <td><?=e($h['hora_finalizacion']?date('H:i',strtotime($h['hora_finalizacion'])):'—')?></td>
                        <td><?php if($h['notas']):?>
<button class="result-pill" onclick='showNote(<?=json_encode($h['notas'],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>)'>
<?=e($result)?> <i class="bi bi-chat-left-text"></i></button><?php else:?><span class="result-pill"><?=e($result)?></span><?php endif;?></td>
                    </tr>
                <?php endforeach; if (!$history):?>
                    <tr><td colspan="<?=$isManager?8:7?>" class="empty-state small"><i class="bi bi-clock-history"></i>
<strong>Sin historial todavía</strong><span>Los tickets cerrados aparecerán aquí.</span></td></tr>
                <?php endif;?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Cola por servicio -->
    <section class="card supervisor-section services-section">
        <div class="supervisor-section-head compact-head">
            <div><h2>Cola por servicio</h2><p>Tickets actualmente en espera.</p></div>
            <span class="subtle-count">Hoy</span>
        </div>
        <div class="service-queue-list">
            <?php foreach ($services as $s): $n=(int)$s['espera']; $totalWait=max(1,count($wait)); $pct=min(100,round(($n/$totalWait)*100)); ?>
                <div class="service-queue-row">
                    <div class="service-queue-name"><span class="service-mini-icon"></span><span><?=e($s['nombre'])?></span></div>
                    <div class="service-bar"><span style="width:<?=$pct?>%"></span></div>
                    <strong><?=$n?></strong>
                </div>
            <?php endforeach;?>
            <?php if (!$services):?><div class="empty-service">No hay servicios configurados.</div><?php endif;?>
        </div>
    </section>
</div>

<div class="modal-backdrop" id="noteModal"><div class="modal-box supervisor-note-modal"><div class="modal-heading"><h2>Comentario de la atención</h2>
<button class="btn btn-light" onclick="document.getElementById('noteModal').style.display='none'"><i class="bi bi-x-lg"></i></button></div>
<div id="noteText" class="note-content"></div></div></div>

<script>
function fmt(sec){sec=Math.max(0,Math.floor(sec)); const h=Math.floor(sec/3600),m=Math.floor(sec%3600/60),s=sec%60;
return (h?String(h).padStart(2,'0')+':':'')+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0')}
function level(sec){if(sec>=600)return 'wait-critical';if(sec>=300)return 'wait-warning';return 'wait-ok'}
function paint(el,sec){el.classList.remove('wait-ok','wait-warning','wait-critical');el.classList.add(level(sec))}
function updateLive(){
    document.querySelectorAll('.wait-time,.live-wait').forEach(el=>{
        const created=el.dataset.created;
        const sec=created?Math.floor((Date.now()-new Date(created.replace(' ','T')).getTime())/1000):0;
        el.textContent=fmt(sec); paint(el,sec);
    });
    document.querySelectorAll('.live-duration').forEach(el=>{
        const start=el.dataset.start;
        const sec=start?Math.floor((Date.now()-new Date(start.replace(' ','T')).getTime())/1000):0;
        el.textContent=fmt(sec);
    });
}
updateLive(); setInterval(updateLive,1000); setInterval(()=>location.reload(),15000);
function showNote(t){document.getElementById('noteText').textContent=t;document.getElementById('noteModal').style.display='flex'}
function escHtml(v){return String(v??'').replace(/[&<>\"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#039;'}[m]))}
function fmtSince(iso){if(!iso)return ''; const d=new Date(String(iso).replace(' ','T'));
if(Number.isNaN(d.getTime()))return ''; return d.toLocaleTimeString('es-DO',{hour:'2-digit',minute:'2-digit',hour12:true})}
function fmtElapsed(sec){sec=Math.max(0,Math.floor(sec||0)); const d=Math.floor(sec/86400); sec%=86400;
const h=Math.floor(sec/3600); sec%=3600; const m=Math.floor(sec/60); const s=sec%60;
if(d>0)return `${d}d ${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`}
function stateClass(state){return state==='NO_RECIBIENDO'?'person-state-paused':(state==='DISPONIBLE'?'person-state-ready':'person-state-other')}
function buildPersonnelRow(r){
    const connected=!!r.conectado;
    const state=r.estado||'NO_DISPONIBLE';
    const motive=state==='NO_RECIBIENDO'?(r.motivo||'Pausa operativa'):'';
    const detail=state==='NO_RECIBIENDO'?(r.detalle||''):'';
    const since=r.inicio||'';
    const locality=<?= $isManager ? 'true' : 'false' ?> && r.localidad?`<div class="personnel-locality">${escHtml(r.localidad)}</div>`:'';
    const motiveHtml=motive?`<span class="person-state-reason"><i class="bi bi-info-circle"></i> ${escHtml(motive)}</span>`:'<span class="personnel-dash">—</span>';
    const detailHtml=detail?`<span class="person-state-detail" title="${escHtml(detail)}">${escHtml(detail)}</span>`:'';
    return `<div class="personnel-grid personnel-row" data-person-id="${escHtml(r.id)}">
      <div class="person-cell personnel-person"><span class="person-avatar">${escHtml((r.nombre||'?').charAt(0).toUpperCase())}</span><div>
<strong>${escHtml(r.nombre)}</strong><small>${escHtml(r.rol)}</small></div></div>
      ${locality}
      <div><span class="person-state-pill ${stateClass(state)}">${escHtml(r.estado_label||'No disponible')}</span></div>
      <div class="personnel-motive">${motiveHtml}${detailHtml}</div>
      <div><span class="person-state-since" data-since="${escHtml(since)}">${since?escHtml(fmtSince(since)):'—'}</span></div>
      <div>
<span class="personnel-elapsed" data-since="${escHtml(since)}">${since?fmtElapsed(Math.max(0,(Date.now()-new Date(String(since).replace(' ','T')).getTime())/1000)):'—'}</span>
</div>
      <div class="personnel-status-wrap"><span class="presence-dot ${connected?'presence-on':'presence-off'}"></span>
<span class="presence-label ${connected?'is-on':'is-off'}">${connected?'Activo':'No activo'}</span></div>
    </div>`;
}
function updatePersonnelTimers(){
    document.querySelectorAll('.personnel-elapsed[data-since]').forEach(el=>{
        const v=el.dataset.since;if(!v){el.textContent='—';return;}
        const ms=new Date(String(v).replace(' ','T')).getTime();
        if(Number.isNaN(ms)){el.textContent='—';return;}
        el.textContent=fmtElapsed(Math.max(0,(Date.now()-ms)/1000));
    });
}
function updatePersonnelSummary(items){
    const connected=items.filter(x=>x.conectado).length;
    const available=items.filter(x=>x.conectado && (x.estado||'DISPONIBLE')==='DISPONIBLE').length;
    const paused=items.filter(x=>(x.estado||'')==='NO_RECIBIENDO').length;
    const a=document.getElementById('personnelConnectedCount');if(a)a.textContent=connected;
    const b=document.getElementById('personnelAvailableCount');if(b)b.textContent=available;
    const c=document.getElementById('personnelPausedCount');if(c)c.textContent=paused;
}
async function refreshPersonnel(){
    try{
        const res=await fetch('<?=app_url('api/personal_estados.php')?>',{cache:'no-store',credentials:'same-origin'});
        if(!res.ok)return;
        const d=await res.json();
        if(!d.ok||!Array.isArray(d.items))return;
        const list=document.getElementById('personnelList');if(!list)return;
        updatePersonnelSummary(d.items);
        list.innerHTML=d.items.length?d.items.map(buildPersonnelRow).join(''):'<div class="personnel-empty">No hay representantes ni dealers registrados en las localidades visibles.</div>';
        updatePersonnelTimers();
    }catch(e){}
}
refreshPersonnel();
setInterval(refreshPersonnel,5000);
setInterval(updatePersonnelTimers,1000);
</script>

<style>
.supervisor-page-title{margin-bottom:18px}
.supervisor-layout{display:grid;grid-template-columns:minmax(0,1.9fr) minmax(330px,.9fr);gap:18px;align-items:start}
.supervisor-section{border:1px solid rgba(15,23,42,.07);box-shadow:0 10px 28px rgba(15,23,42,.055);overflow:hidden;background:rgba(255,255,255,.96)}
.attention-section{grid-column:1;min-width:0}
.waiting-section{grid-column:2;min-width:0}
.history-section{grid-column:1;min-width:0}
.personnel-section{grid-column:1/-1;min-width:0}
.personnel-section-head{align-items:flex-start}
.personnel-section-head .supervisor-kicker{margin-bottom:5px}
.personnel-summary{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.personnel-summary-item{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:7px 10px;font-size:11px;font-weight:700;border:1px solid #e0e9e3;background:#f7faf8;color:#64746b;white-space:nowrap}
.personnel-summary-item b{font-size:13px;color:#193329}
.personnel-summary-item.connected{background:#eef8f2;border-color:#d5ecdc;color:#2d7650}
.personnel-summary-item.available{background:#f1f8ec;border-color:#dceccf;color:#4d7d37}
.personnel-summary-item.paused{background:#fff6e7;border-color:#f0dfbd;color:#9b6b16}
.personnel-table-wrap{padding:0 14px 14px;overflow:auto}
.personnel-grid{display:grid;grid-template-columns:minmax(210px,1.35fr) <?php if($isManager): ?>minmax(145px,.95fr) <?php endif; ?>minmax(150px,.95fr) minmax(190px,1.15fr) minmax(110px,.7fr) minmax(135px,.82fr) minmax(100px,.62fr);align-items:center;column-gap:10px}
.personnel-grid-head{padding:10px 12px;background:#f3f7f5;border-radius:10px 10px 0 0;color:#617069;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.personnel-grid-body{border:1px solid #edf1ef;border-top:0;border-radius:0 0 12px 12px;overflow:hidden;min-width:820px}
.personnel-row{padding:10px 12px;border-bottom:1px solid #edf1ef;min-height:56px;background:#fff}
.personnel-row:last-child{border-bottom:0}
.personnel-row:hover{background:#fafcfb}
.personnel-locality{font-size:12px;color:#46584f;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.personnel-person{min-width:0}
.personnel-person .person-avatar{width:34px;height:34px}
.personnel-motive{display:flex;align-items:center;gap:6px;min-width:0;flex-wrap:wrap}
.personnel-motive .person-state-reason{overflow:hidden;text-overflow:ellipsis}
.personnel-dash{color:#a5afaa}
.personnel-elapsed{font-size:11px;font-weight:800;color:#44574d;font-variant-numeric:tabular-nums;white-space:nowrap}
.personnel-status-wrap{justify-content:flex-start}
.personnel-empty{text-align:center;padding:24px;color:#8b9791;font-size:12px}
.waiting-table{width:100%;min-width:0;table-layout:fixed}
.waiting-table th,.waiting-table td{padding-left:7px;padding-right:7px}
.waiting-table th:nth-child(1),.waiting-table td:nth-child(1){width:31%}
.waiting-table th:nth-child(2),.waiting-table td:nth-child(2){width:43%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.waiting-table th:nth-child(3),.waiting-table td:nth-child(3){width:26%;text-align:right}
.supervisor-note-modal{max-width:560px}
.note-content{background:#f6f8f7;border:1px solid #e8eeeb;border-radius:12px;padding:15px;white-space:pre-wrap;color:#304139;line-height:1.55;font-size:13px}
@media(max-width:1050px){.supervisor-layout{grid-template-columns:1fr}.attention-section,.waiting-section,.personnel-section,.history-section,.services-section{grid-column:1}.attention-table{min-width:780px}.personnel-grid-head,.personnel-row{grid-template-columns:minmax(200px,1.3fr) minmax(145px,.95fr) minmax(180px,1.15fr) minmax(100px,.65fr) minmax(125px,.8fr) minmax(100px,.6fr)}}
@media(max-width:700px){.supervisor-section-head{padding:16px 15px 12px}.supervisor-section-head h2{font-size:18px}.supervisor-table{min-width:620px}.waiting-table{min-width:500px}.supervisor-page-title .btn{display:none}.personnel-section-head{display:block}.personnel-summary{justify-content:flex-start;margin-top:12px}.personnel-grid-head,.personnel-row{grid-template-columns:minmax(180px,1.1fr) minmax(140px,.9fr) minmax(180px,1.1fr) minmax(100px,.65fr) minmax(120px,.8fr) minmax(90px,.6fr)}}


/* Supervisión visual polish v49 */
.supervisor-section{background:linear-gradient(180deg,#ffffff 0%,#fbfefc 100%);border-color:#dce9e1;border-radius:20px}
.supervisor-section-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:20px 20px 14px;position:relative}
.supervisor-section-head:after{content:"";position:absolute;left:20px;right:20px;bottom:0;height:1px;background:#edf3ef}
.supervisor-section-head h2{margin:0;color:#17372b;font-size:21px;line-height:1.15;letter-spacing:-.02em}
.supervisor-section-head p{margin:6px 0 0;color:#74837b;font-size:12px}
.supervisor-kicker{display:inline-flex;align-items:center;gap:7px;color:#238746;font-size:10px;font-weight:900;letter-spacing:.05em;margin-bottom:6px}
.live-dot{width:8px;height:8px;border-radius:50%;background:#2eb35c;box-shadow:0 0 0 4px rgba(46,179,92,.13);animation:pulse 1.8s infinite}
.supervisor-count{display:inline-flex;align-items:baseline;gap:5px;background:#eef9f1;border:1px solid #d4ecd9;color:#277443;padding:9px 13px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}
.supervisor-count strong{font-size:18px;color:#175d35}
.compact-head{align-items:center}
.compact-head:after{display:none}
.queue-number{min-width:48px;height:48px;padding:0 12px;display:flex;align-items:center;justify-content:center;border-radius:15px;background:linear-gradient(135deg,#edf8f0,#e0f2e6);border:1px solid #d2e8d7;color:#236e3c;font-size:21px;font-weight:900}
.table-wrap{overflow:auto}
.supervisor-table{margin:0}
.supervisor-table th{background:#eef6f1;color:#60746a;font-size:10px;padding:10px 12px;border-bottom:1px solid #dfeae3}
.supervisor-table td{padding:11px 12px;font-size:12px;color:#44574e;vertical-align:middle}
.supervisor-table tbody tr{transition:background .15s ease}
.supervisor-table tbody tr:hover{background:#f7fbf8}
.person-cell{display:flex;align-items:center;gap:10px;min-width:0}
.person-avatar{width:36px;height:36px;flex:0 0 36px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(135deg,#eaf3ff,#dcecff);color:#2b69a6;font-size:13px;font-weight:900;border:1px solid #d5e6f8}
.person-cell>div{min-width:0}
.person-cell strong{display:block;color:#18382a;font-size:12px;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.person-cell small,.table-sub{display:block;color:#839089;font-size:9px;margin-top:2px}
.service-pill{display:inline-flex;align-items:center;max-width:100%;padding:7px 10px;border-radius:999px;background:#edf8ef;border:1px solid #d5ead9;color:#257641;font-weight:800;font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ticket-code{color:#173b2a;font-size:14px;letter-spacing:.01em}
.time-badge{display:inline-flex;align-items:center;justify-content:center;min-width:72px;padding:7px 9px;border-radius:9px;font-variant-numeric:tabular-nums;font-weight:900;font-size:11px;border:1px solid transparent}
.time-badge.wait-ok,.history-time.wait-ok{background:#eaf7ed;color:#28763f;border-color:#d2ead8}
.time-badge.wait-warning,.history-time.wait-warning{background:#fff3dd;color:#a36a00;border-color:#f0dcaf}
.time-badge.wait-critical,.history-time.wait-critical{background:#ffe8e8;color:#c03a33;border-color:#f2caca}
.attention-time{background:#edf4ff;color:#2c68a6;border-color:#dce9fa}
.comment-link{border:0;background:#f2f7f4;color:#297246;border-radius:999px;padding:6px 9px;font-size:10px;font-weight:800;cursor:pointer}
.comment-link:hover{background:#e4f2e9}
.muted-dash,.personnel-dash{color:#a2aaa6}
.waiting-section .supervisor-table th,.waiting-section .supervisor-table td{padding-left:10px;padding-right:10px}
.priority-dot{display:inline-block;width:7px;height:7px;margin-left:5px;border-radius:50%;background:#f3a51a;box-shadow:0 0 0 3px rgba(243,165,26,.12);vertical-align:middle}
.personnel-section{margin-top:0}
.personnel-section-head{padding-bottom:16px}
.personnel-summary-item{transition:transform .15s ease,box-shadow .15s ease}
.personnel-summary-item:hover{transform:translateY(-1px);box-shadow:0 6px 14px rgba(25,72,45,.06)}
.personnel-table-wrap{padding:0 16px 16px;overflow-x:auto}
.personnel-grid{column-gap:12px}
.personnel-grid-head{background:#f0f7f3;border:1px solid #e2eee7;border-bottom:0;color:#60736a;font-size:10px;padding:10px 12px}
.personnel-grid-body{border-color:#e2eee7;box-shadow:inset 0 1px 0 rgba(255,255,255,.6)}
.personnel-row{background:rgba(255,255,255,.92);padding:12px;min-height:64px}
.personnel-row:hover{background:#f8fcf9}
.personnel-row:nth-child(even){background:#fcfefd}
.personnel-row:nth-child(even):hover{background:#f7fbf8}
.personnel-locality{color:#466058;font-weight:700}
.person-state-pill{display:inline-flex;align-items:center;gap:5px;padding:7px 10px;border-radius:999px;border:1px solid transparent;font-size:10px;font-weight:900;white-space:nowrap}
.person-state-ready{background:#eaf8ee;color:#267742;border-color:#d2ead9}
.person-state-paused{background:#fff3df;color:#a26c00;border-color:#efd9ac}
.person-state-other{background:#edf1f0;color:#62726b;border-color:#dce5e0}
.person-state-reason{display:inline-flex;align-items:center;gap:5px;max-width:100%;padding:6px 8px;background:#fff8eb;color:#986900;border:1px solid #f1dfbb;border-radius:8px;font-size:9px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.person-state-reason i{font-size:10px}
.person-state-detail{display:block;max-width:190px;color:#84918b;font-size:9px;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.person-state-since{font-weight:800;color:#50655a;font-size:10px;white-space:nowrap}
.personnel-elapsed{display:inline-flex;padding:6px 8px;background:#f2f6f4;border:1px solid #e2e9e5;border-radius:8px;font-size:10px}
.personnel-status-wrap{display:flex;align-items:center;gap:7px}
.presence-dot{width:9px;height:9px;border-radius:50%;display:inline-block}
.presence-on{background:#27ad54;box-shadow:0 0 0 4px rgba(39,173,84,.12)}
.presence-off{background:#a5aea9;box-shadow:0 0 0 4px rgba(165,174,169,.12)}
.presence-label{font-size:10px;font-weight:900}.presence-label.is-on{color:#2e7c49}.presence-label.is-off{color:#7a8781}
.history-time{display:inline-flex;padding:6px 8px;border-radius:8px;font-variant-numeric:tabular-nums;font-size:10px;font-weight:900;border:1px solid transparent;white-space:nowrap}
.result-pill{display:inline-flex;align-items:center;gap:5px;border:1px solid #dbe8df;background:#edf7f0;color:#297143;border-radius:999px;padding:6px 9px;font-size:10px;font-weight:900;cursor:pointer}
.result-pill:hover{background:#e2f1e6}
.service-queue-list{padding:4px 18px 18px;display:grid;gap:9px}
.service-queue-row{display:grid;grid-template-columns:minmax(150px,1.2fr) minmax(120px,1.8fr) 26px;gap:10px;align-items:center;padding:4px 0}
.service-queue-name{display:flex;align-items:center;gap:8px;color:#3f574c;font-size:11px;font-weight:700;min-width:0}.service-queue-name span:last-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.service-mini-icon{width:20px;height:20px;border-radius:7px;background:#e8f6ec;border:1px solid #d5eadd;position:relative;flex:0 0 20px}.service-mini-icon:after{content:"";position:absolute;inset:5px;border-radius:3px;background:#52bf62}
.service-bar{height:9px;background:#edf3ef;border-radius:999px;overflow:hidden}.service-bar>span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#78d000,#49b75c);box-shadow:0 2px 8px rgba(65,154,83,.12)}
.subtle-count{display:inline-flex;align-items:center;padding:6px 9px;background:#f2f6f4;border:1px solid #e3ebe6;border-radius:999px;color:#6b7b73;font-size:10px;font-weight:800}
.empty-state{padding:30px!important;text-align:center;color:#8b9891!important}.empty-state i{display:block;width:42px;height:42px;margin:0 auto 9px;border-radius:12px;background:#eef7f1;color:#5aaa6a;display:grid;place-items:center;font-size:20px}.empty-state strong{display:block;color:#496057;font-size:12px}.empty-state span{display:block;margin-top:3px;font-size:10px}.empty-state.small{padding:22px!important}
.note-content{background:#f4f8f5;border-color:#e0eae4}
@media(max-width:1050px){.supervisor-layout{grid-template-columns:1fr}.waiting-section{grid-column:1}.personnel-grid-body{min-width:0}.personnel-grid{min-width:860px}}
@media(max-width:700px){.supervisor-section-head{padding:16px 15px 13px}.supervisor-count{padding:7px 10px}.queue-number{min-width:42px;height:42px}.personnel-summary-item{font-size:10px}.personnel-grid{min-width:860px}}

html[data-theme="dark"] .supervisor-section{background:linear-gradient(180deg,#18251f 0%,#15211c 100%);border-color:#2b4337}
html[data-theme="dark"] .supervisor-section-head:after{background:#2b4337}
html[data-theme="dark"] .supervisor-section-head h2{color:#edf7f1}
html[data-theme="dark"] .supervisor-section-head p{color:#9caea6}
html[data-theme="dark"] .supervisor-count{background:#1b3525;border-color:#315b3d;color:#9ee1b0}html[data-theme="dark"] .supervisor-count strong{color:#b6efc5}
html[data-theme="dark"] .queue-number{background:#1c3526;border-color:#31583d;color:#a9e3b4}
html[data-theme="dark"] .supervisor-table th{background:#20342a;color:#aebfb6;border-color:#2e473c}
html[data-theme="dark"] .supervisor-table td{color:#d3e0d8;border-color:#2a4237}
html[data-theme="dark"] .supervisor-table tbody tr:hover{background:#1c2d24}
html[data-theme="dark"] .person-cell strong,html[data-theme="dark"] .ticket-code{color:#edf7f1}
html[data-theme="dark"] .person-cell small,html[data-theme="dark"] .table-sub,html[data-theme="dark"] .personnel-empty{color:#91a49a}
html[data-theme="dark"] .person-avatar{background:#203449;border-color:#2d4e66;color:#9bcfff}
html[data-theme="dark"] .service-pill{background:#1b3525;border-color:#315b3d;color:#9ee1b0}
html[data-theme="dark"] .attention-time{background:#1f3043;color:#9fc9f2;border-color:#315071}
html[data-theme="dark"] .comment-link{background:#20342a;color:#9ee1b0}.comment-link:hover{background:#294538}
html[data-theme="dark"] .muted-dash,html[data-theme="dark"] .personnel-dash{color:#758980}
html[data-theme="dark"] .personnel-grid-head{background:#20342a;border-color:#2e473c;color:#aebfb6}
html[data-theme="dark"] .personnel-grid-body{border-color:#2e473c}
html[data-theme="dark"] .personnel-row,html[data-theme="dark"] .personnel-row:nth-child(even){background:#17241e;border-color:#2a4237}
html[data-theme="dark"] .personnel-row:hover,html[data-theme="dark"] .personnel-row:nth-child(even):hover{background:#1b2d24}
html[data-theme="dark"] .personnel-locality,html[data-theme="dark"] .person-state-since{color:#c8d9d0}
html[data-theme="dark"] .person-state-ready{background:#1c3526;color:#a7dfb4;border-color:#31583d}
html[data-theme="dark"] .person-state-paused{background:#3b301d;color:#f2d47b;border-color:#624e23}
html[data-theme="dark"] .person-state-other{background:#24312c;color:#bdcbc4;border-color:#35483f}
html[data-theme="dark"] .person-state-reason{background:#3b301d;color:#f1d37d;border-color:#624e23}
html[data-theme="dark"] .person-state-detail{color:#90a29a}
html[data-theme="dark"] .personnel-elapsed,.html[data-theme="dark"] .subtle-count{background:#1f2d26;border-color:#30473d;color:#aebeb6}
html[data-theme="dark"] .result-pill{background:#1d3626;border-color:#315b3d;color:#9ee1b0}
html[data-theme="dark"] .service-queue-name{color:#c8d7cf}
html[data-theme="dark"] .service-mini-icon{background:#1d3526;border-color:#315b3d}
html[data-theme="dark"] .service-bar{background:#263a30}
html[data-theme="dark"] .note-content{background:#1e2c25;border-color:#30473c;color:#d6e2db}

</style>
<?php require __DIR__.'/../includes/footer.php'; ?>
