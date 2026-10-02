<?php
$title='Reportes y Estadísticas';
require_once __DIR__.'/../includes/helpers.php';
require_role(['ADMINISTRADOR','SUPERVISOR','GERENTE']);

$pdo=db();
$isGlobal=is_global_role(); $isManager=is_manager(); $visibleIds=visible_localidad_ids();

function clean_date_rep(string $value, string $fallback): string {
    $d=DateTime::createFromFormat('Y-m-d',$value);
    return ($d && $d->format('Y-m-d')===$value) ? $value : $fallback;
}
function secfmt_rep($s): string {
    if ($s===null || $s==='') return '—';
    $s=max(0,(int)round((float)$s));
    $d=intdiv($s,86400); $s%=86400;
    $h=intdiv($s,3600); $m=intdiv($s%3600,60); $sec=$s%60;
    $base=str_pad((string)$m,2,'0',STR_PAD_LEFT).':'.str_pad((string)$sec,2,'0',STR_PAD_LEFT);
    if ($d>0) return $d.'d '.str_pad((string)$h,2,'0',STR_PAD_LEFT).':'.$base;
    if ($h>0) return $h.'h '.$base;
    return $base;
}
function pct_rep($value,$total): int { $total=(int)$total; return $total>0 ? (int)round(((int)$value/$total)*100) : 0; }

$defaultFrom=date('Y-m-01');
$defaultTo=date('Y-m-d');
$from=clean_date_rep((string)($_GET['desde']??$defaultFrom),$defaultFrom);
$to=clean_date_rep((string)($_GET['hasta']??$defaultTo),$defaultTo);
if ($from>$to) { [$from,$to]=[$to,$from]; }

if ($isGlobal) { $locations=$pdo->query("SELECT id,nombre,codigo FROM localidades WHERE activo=1 ORDER BY nombre")->fetchAll(); } else { $ids=$visibleIds?:[-1]; $ph=implode(',',array_fill(0,count($ids),'?')); $q=$pdo->prepare("SELECT id,nombre,codigo FROM localidades WHERE activo=1 AND id IN ($ph) ORDER BY nombre"); $q->execute($ids); $locations=$q->fetchAll(); }
$services=$pdo->query("SELECT id,nombre FROM servicios WHERE activo=1 ORDER BY orden,nombre")->fetchAll();

$selected=(int)($_GET['localidad_id']??0);
if (!$isGlobal) { if ($isManager) { if ($selected && !can_view_localidad($selected)) { http_response_code(403); exit('No autorizado.'); } } else { $selected=(int)current_localidad_id(); } }
$serviceId=(int)($_GET['servicio_id']??0);

$baseWhere='t.fecha BETWEEN ? AND ?';
$baseParams=[$from,$to];
if ($selected) {$baseWhere.=' AND t.localidad_id=?';$baseParams[]=$selected;} else if (!$isGlobal) { [$vw,$vp]=visible_localidad_where('t'); $baseWhere.=' AND '.$vw; $baseParams=array_merge($baseParams,$vp); }
if ($serviceId) {$baseWhere.=' AND t.servicio_id=?';$baseParams[]=$serviceId;}

$st=$pdo->prepare("SELECT
  COUNT(*) total,
  COALESCE(SUM(t.estado='ESPERANDO'),0) espera,
  COALESCE(SUM(t.estado='ATENDIENDO'),0) atendiendo,
  COALESCE(SUM(t.estado='FINALIZADO'),0) finalizados,
  COALESCE(SUM(t.estado='AUSENTE'),0) ausentes,
  COALESCE(SUM(t.estado='CANCELADO'),0) cancelados,
  COALESCE(SUM(t.estado='VENCIDO'),0) vencidos,
  AVG(CASE WHEN t.hora_llamado IS NOT NULL THEN TIMESTAMPDIFF(SECOND,t.hora_creacion,t.hora_llamado) END) espera_prom,
  MAX(CASE WHEN t.hora_llamado IS NOT NULL THEN TIMESTAMPDIFF(SECOND,t.hora_creacion,t.hora_llamado) END) espera_max,
  AVG(CASE WHEN t.hora_inicio IS NOT NULL AND t.hora_finalizacion IS NOT NULL THEN TIMESTAMPDIFF(SECOND,t.hora_inicio,t.hora_finalizacion) END) atencion_prom
  FROM turnos t WHERE $baseWhere");
$st->execute($baseParams); $tot=$st->fetch() ?: [];

$lpWhere='t.fecha BETWEEN ? AND ?';$lp=[$from,$to];
if ($serviceId) {$lpWhere.=' AND t.servicio_id=?';$lp[]=$serviceId;}
if ($selected) {$lpWhere.=' AND l.id=?';$lp[]=$selected;} else if (!$isGlobal) { $ids=$visibleIds?:[-1]; $ph=implode(',',array_fill(0,count($ids),'?')); $lpWhere.=' AND l.id IN ('.$ph.')'; $lp=array_merge($lp,$ids); }
$st=$pdo->prepare("SELECT l.id,l.nombre localidad,l.codigo,
 COUNT(t.id) total,COALESCE(SUM(t.estado='ESPERANDO'),0) espera,COALESCE(SUM(t.estado='ATENDIENDO'),0) atendiendo,
 COALESCE(SUM(t.estado='FINALIZADO'),0) finalizados,COALESCE(SUM(t.estado='AUSENTE'),0) ausentes,COALESCE(SUM(t.estado='CANCELADO'),0) cancelados,
  COALESCE(SUM(t.estado='VENCIDO'),0) vencidos,
 AVG(CASE WHEN t.hora_llamado IS NOT NULL THEN TIMESTAMPDIFF(SECOND,t.hora_creacion,t.hora_llamado) END) espera_prom,
 MAX(CASE WHEN t.hora_llamado IS NOT NULL THEN TIMESTAMPDIFF(SECOND,t.hora_creacion,t.hora_llamado) END) espera_max,
 AVG(CASE WHEN t.hora_inicio IS NOT NULL AND t.hora_finalizacion IS NOT NULL THEN TIMESTAMPDIFF(SECOND,t.hora_inicio,t.hora_finalizacion) END) atencion_prom
 FROM localidades l LEFT JOIN turnos t ON t.localidad_id=l.id AND $lpWhere WHERE l.activo=1 GROUP BY l.id,l.nombre,l.codigo ORDER BY total DESC,l.nombre");
$st->execute($lp); $rows=$st->fetchAll();

$st=$pdo->prepare("SELECT s.id,s.nombre servicio,COUNT(t.id) total,COALESCE(SUM(t.estado='ESPERANDO'),0) espera,COALESCE(SUM(t.estado='ATENDIENDO'),0) atendiendo,COALESCE(SUM(t.estado='FINALIZADO'),0) finalizados,
COALESCE(SUM(t.estado='VENCIDO'),0) vencidos,
AVG(CASE WHEN t.hora_llamado IS NOT NULL THEN TIMESTAMPDIFF(SECOND,t.hora_creacion,t.hora_llamado) END) espera_prom
FROM turnos t JOIN servicios s ON s.id=t.servicio_id WHERE $baseWhere GROUP BY s.id,s.nombre ORDER BY total DESC,s.nombre");
$st->execute($baseParams); $serviceRows=$st->fetchAll();

$st=$pdo->prepare("SELECT t.fecha,COUNT(*) total FROM turnos t WHERE $baseWhere GROUP BY t.fecha ORDER BY t.fecha");
$st->execute($baseParams); $dailyRaw=$st->fetchAll();
$dailyMap=[]; foreach ($dailyRaw as $d) {$dailyMap[$d['fecha']]=(int)$d['total'];}

$st=$pdo->prepare("SELECT t.id,t.numero,t.prefijo,t.fecha,t.estado,t.hora_creacion,t.hora_llamado,t.hora_inicio,t.hora_finalizacion,t.motivo_cierre,
 CASE WHEN t.hora_finalizacion IS NOT NULL THEN TIMESTAMPDIFF(SECOND,t.hora_creacion,t.hora_finalizacion) END duracion_total,
 s.nombre servicio,
 COALESCE(CONCAT(u.nombre),'—') representante
 FROM turnos t
 JOIN servicios s ON s.id=t.servicio_id
 LEFT JOIN usuarios u ON u.id=t.atendiente_id
 WHERE $baseWhere ORDER BY t.id DESC LIMIT 8");
$st->execute($baseParams); $recentRows=$st->fetchAll();

if (isset($_GET['export']) && $_GET['export']==='csv') {
    if ($selected && !can_view_localidad($selected)) { http_response_code(403); exit('No autorizado.'); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="viva-reportes-'.date('Ymd-His').'.csv"');
    $out=fopen('php://output','w');
    fputcsv($out,['Localidad','Codigo','Tickets','En espera','En atencion','Finalizados','Ausentes','Cancelados','Vencidos','Espera promedio','Espera maxima','Atencion promedio']);
    foreach ($rows as $r) {fputcsv($out,[$r['localidad'],$r['codigo'],(int)$r['total'],(int)$r['espera'],(int)$r['atendiendo'],(int)$r['finalizados'],(int)$r['ausentes'],(int)$r['cancelados'],(int)($r['vencidos']??0),secfmt_rep($r['espera_prom']),secfmt_rep($r['espera_max']),secfmt_rep($r['atencion_prom'])]);}
    fclose($out); exit;
}

$kpiTotal=(int)($tot['total']??0);
$kpiWait=(int)($tot['espera']??0);
$kpiAtt=(int)($tot['finalizados']??0);
$kpiWaitAvg=secfmt_rep($tot['espera_prom']??null);
$kpiDone=(int)($tot['finalizados']??0);

$trendMax=max(1,...array_values($dailyMap?:[0]));
$trendStart=new DateTime($from); $trendEnd=new DateTime($to); $span=(int)$trendStart->diff($trendEnd)->days;
$trendPoints=[];
$step=1;
if ($span>90) {$step=7;}
for ($i=0;$i<=$span;$i+=$step) {
    $day=clone $trendStart; $day->modify('+'.$i.' days'); if ($day>$trendEnd)$day=clone $trendEnd;
    $key=$day->format('Y-m-d'); $trendPoints[$key]=$dailyMap[$key]??0;
}
if (!isset($trendPoints[$to]))$trendPoints[$to]=$dailyMap[$to]??0;

$chartW=720;$chartH=250;$padL=42;$padR=18;$padT=24;$padB=34;
$countPts=count($trendPoints); $innerW=$chartW-$padL-$padR; $innerH=$chartH-$padT-$padB;
$svgPts=[]; $areaPts=[];
$idx=0;
foreach ($trendPoints as $label=>$value) {$x=$countPts>1?$padL+($idx/($countPts-1))*$innerW:$padL+$innerW/2;$y=$padT+$innerH-(($value/$trendMax)*$innerH);$svgPts[]=round($x,1).','.round($y,1);$areaPts[]=round($x,1).','.round($y,1);$idx++;}
$area=' '.implode(' ',$areaPts).' '.($padL+$innerW).','.($padT+$innerH).' '.$padL.','.($padT+$innerH);
$statusTotal=$kpiTotal;
$statusParts=[
 ['label'=>'Finalizados','value'=>(int)$tot['finalizados'],'cls'=>'done'],
 ['label'=>'En espera','value'=>(int)$tot['espera'],'cls'=>'wait'],
 ['label'=>'En atención','value'=>(int)$tot['atendiendo'],'cls'=>'work'],
 ['label'=>'Cancelados','value'=>(int)$tot['cancelados'],'cls'=>'cancel'],
 ['label'=>'Vencidos','value'=>(int)($tot['vencidos']??0),'cls'=>'expired'],
 ['label'=>'Ausentes','value'=>(int)$tot['ausentes'],'cls'=>'absent'],
];
$segParts=[];$deg=0;$statusCss=[];
$statusColors=['done'=>'#27a94a','wait'=>'#ffb521','work'=>'#3f8fea','cancel'=>'#ef5350','expired'=>'#d94b3f','absent'=>'#93a0a7'];
foreach ($statusParts as $p) {$pct=$statusTotal>0?($p['value']/$statusTotal*100):0;$end=$deg+$pct;$statusCss[]=$statusColors[$p['cls']].' '.$deg.'deg '.$end.'deg';$deg=$end;}
$donutStyle='background:conic-gradient('.implode(',',$statusCss).')';
$serviceMax=1;foreach ($serviceRows as $s) {$serviceMax=max($serviceMax,(int)$s['total']);}

require_once __DIR__.'/../includes/header.php';
?>
<div class="reports-v2">
  <div class="reports-v2-head">
    <div class="reports-v2-title"><div class="reports-v2-icon"><i class="bi bi-bar-chart-fill"></i></div><div><h1>Reportes y Estadísticas</h1>
<p>Analiza el rendimiento de <?=e($isManager?'tus tiendas asignadas':'tu localidad')?> y el servicio de atención.</p></div></div>
    <div class="reports-v2-date"><i class="bi bi-calendar3"></i><span><?=e(date('d/m/Y',strtotime($to)))?></span><i class="bi bi-clock"></i>
<span id="repLiveClock">--:--:--</span></div>
  </div>

  <section class="reports-v2-filter">
    <div class="reports-v2-filter-title"><i class="bi bi-funnel-fill"></i><strong>Filtros de búsqueda</strong>
<small>Selecciona los parámetros para generar las estadísticas.</small></div>
    <form method="get" class="reports-v2-filter-grid">
      <div><label class="form-label">Localidad</label><select class="form-select" name="localidad_id" <?=(!$isGlobal && !$isManager)?'disabled':''?>>
<option value="0"><?=$isManager?'Todas mis localidades':'Todas las localidades'?></option><?php foreach($locations as $l):?>
<option value="<?=$l['id']?>" <?=$selected===(int)$l['id']?'selected':''?>><?=e($l['nombre'])?></option><?php endforeach;?></select>
<?php if(!$isGlobal && !$isManager):?><input type="hidden" name="localidad_id" value="<?=$selected?>"><?php endif;?></div>
      <div><label class="form-label">Desde</label><input class="form-control" type="date" name="desde" value="<?=e($from)?>"></div>
      <div><label class="form-label">Hasta</label><input class="form-control" type="date" name="hasta" value="<?=e($to)?>"></div>
      <div><label class="form-label">Servicio</label><select class="form-select" name="servicio_id"><option value="0">Todos los servicios</option>
<?php foreach($services as $s):?><option value="<?=$s['id']?>" <?=$serviceId===(int)$s['id']?'selected':''?>><?=e($s['nombre'])?></option>
<?php endforeach;?></select></div>
      <div class="reports-v2-filter-actions">
<a class="reports-v2-export csv" href="<?=e(app_url('reportes/?'.http_build_query(array_merge($_GET,['export'=>'csv']))))?>">
<i class="bi bi-filetype-csv"></i> CSV</a><button type="button" class="reports-v2-export pdf" onclick="window.print()">
<i class="bi bi-file-earmark-pdf"></i> PDF</button><button type="button" class="reports-v2-export print" onclick="window.print()">
<i class="bi bi-printer"></i> Imprimir</button><button class="reports-v2-update"><i class="bi bi-arrow-clockwise">
</i> Actualizar estadísticas</button></div>
    </form>
  </section>

  <section class="reports-v2-kpis">
    <div class="reports-v2-kpi green"><div class="kpi-ico"><i class="bi bi-ticket-perforated-fill"></i></div><div><span>Tickets generados</span>
<strong><?=$kpiTotal?></strong><small><i class="bi bi-arrow-up"></i> período seleccionado</small></div></div>
    <div class="reports-v2-kpi blue"><div class="kpi-ico"><i class="bi bi-people-fill"></i></div><div><span>Atendidos</span><strong><?=$kpiAtt?>
</strong><small><i class="bi bi-check2"></i> finalizados</small></div></div>
    <div class="reports-v2-kpi orange"><div class="kpi-ico"><i class="bi bi-clock-history"></i></div><div><span>En espera</span><strong><?=$kpiWait?>
</strong><small><i class="bi bi-hourglass-split"></i> en cola</small></div></div>
    <div class="reports-v2-kpi purple"><div class="kpi-ico"><i class="bi bi-stopwatch"></i></div><div><span>Espera promedio</span><strong>
<?=$kpiWaitAvg?></strong><small>tiempo registrado</small></div></div>
    <div class="reports-v2-kpi teal"><div class="kpi-ico"><i class="bi bi-check-circle-fill"></i></div><div><span>Finalizados</span><strong>
<?=$kpiDone?></strong><small><i class="bi bi-activity"></i> resultado</small></div></div>
  </section>

  <section class="reports-v2-grid-top">
    <div class="reports-v2-card reports-trend-card">
      <div class="reports-v2-card-head"><div><h2><i class="bi bi-graph-up-arrow"></i> Tendencia de tickets</h2>
<small>Cantidad de tickets generados durante el período seleccionado.</small></div><span class="reports-v2-tag">Por día</span></div>
      <div class="reports-chart-wrap">
        <svg class="reports-line-chart" viewBox="0 0 <?=$chartW?> <?=$chartH?>" role="img" aria-label="Tendencia de tickets">
          <defs><linearGradient id="repArea" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#70d11c" stop-opacity=".35"/>
<stop offset="100%" stop-color="#70d11c" stop-opacity="0"/></linearGradient></defs>
          <?php for ($g=0;$g<=4;$g++):$gy=$padT+($innerH/4)*$g;$gv=round($trendMax*(1-$g/4));?><line x1="<?=$padL?>" y1="<?=$gy?>" x2="<?=$padL+$innerW?>" y2="<?=$gy?>" stroke="#e6eee8" stroke-width="1"/><text x="<?=$padL-10?>" y="<?=$gy+4?>" font-size="10" text-anchor="end" fill="#809087"><?=$gv?></text><?php endfor; ?>
          <polygon points="<?=$area?>" fill="url(#repArea)"/>
          <polyline points="<?=implode(' ',$svgPts)?>" fill="none" stroke="#2cad47" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
          <?php $idx=0;foreach ($trendPoints as $label=>$value):$x=$countPts>1?$padL+($idx/($countPts-1))*$innerW:$padL+$innerW/2;$y=$padT+$innerH-(($value/$trendMax)*$innerH);$display=$label===array_key_first($trendPoints)||$idx==intdiv(max(0,$countPts-1),2)||$idx==$countPts-1?date('d M',strtotime($label)):'';?><circle cx="<?=round($x,1)?>" cy="<?=round($y,1)?>" r="4.8" fill="#fff" stroke="#2cad47" stroke-width="3"/><text x="<?=round($x,1)?>" y="<?=round($y-11,1)?>" font-size="10" text-anchor="middle" fill="#16843d" font-weight="800"><?=$value?></text><?php if ($display):?><text x="<?=round($x,1)?>" y="<?=$padT+$innerH+21?>" font-size="10" text-anchor="middle" fill="#76857e"><?=e($display)?></text><?php endif;$idx++;endforeach; ?>
        </svg>
      </div>
    </div>

    <div class="reports-v2-card reports-status-card">
      <div class="reports-v2-card-head"><div><h2><i class="bi bi-pie-chart-fill"></i> Estado de tickets</h2><small>Distribución de los tickets en el período.</small></div></div>
      <div class="status-flex"><div class="donut" style="<?=$donutStyle?>"><div class="donut-hole"><strong><?=$statusTotal?></strong>
<span>Total</span></div></div><div class="status-legend"><?php foreach($statusParts as $p):?><div><span class="dot <?=$p['cls']?>"></span><span>
<?=$p['label']?></span><b><?=$p['value']?></b><em><?=pct_rep($p['value'],$statusTotal)?>%</em></div><?php endforeach;?></div></div>
    </div>

    <div class="reports-v2-card reports-service-card">
      <div class="reports-v2-card-head"><div><h2><i class="bi bi-bar-chart-steps"></i> Tickets por servicio</h2><small>Cantidad de tickets por tipo de servicio.</small></div></div>
      <div class="service-bars"><?php if(!$serviceRows):?><div class="reports-empty">No hay datos para el período seleccionado.</div>
<?php else:foreach($serviceRows as $i=>$s):$pct=(int)round(((int)$s['total']/$serviceMax)*100);?><div class="service-bar-row">
<div class="service-label"><span class="service-dot s<?=$i%6?>"></span><span><?=e($s['servicio'])?></span></div><div class="bar-track">
<div class="bar-fill s<?=$i%6?>" style="width:<?=$pct?>%"></div></div><strong><?=e((string)(int)$s['total'])?></strong></div><?php endforeach;endif;?>
</div>
    </div>
  </section>

  <section class="reports-v2-grid-bottom">
    <div class="reports-v2-card reports-locality-card">
      <div class="reports-v2-card-head"><div><h2><i class="bi bi-building"></i> Resumen por localidad</h2>
<small>Comparativo de localidades en el período seleccionado.</small></div></div>
      <div class="table-scroll"><table class="reports-v2-table"><thead><tr><th>Localidad</th><th>Tickets</th><th>Atendidos</th><th>En espera</th>
<th>Finalizados</th><th>Ausentes</th><th>Cancelados</th><th>Vencidos</th><th>Espera prom.</th></tr></thead><tbody><?php if(!$rows):?><tr>
<td colspan="9" class="reports-empty-cell">No hay datos.</td></tr><?php else:foreach($rows as $i=>$r):?><tr><td><span class="local-dot d<?=$i%4?>">
</span><strong><?=e($r['localidad'])?></strong><small><?=e($r['codigo'])?></small></td><td><b><?=e((string)(int)$r['total'])?></b></td><td>
<?=e((string)(int)$r['finalizados'])?></td><td><span class="mini-pill wait"><?=e((string)(int)$r['espera'])?></span></td><td>
<?=e((string)(int)$r['finalizados'])?></td><td><?=e((string)(int)$r['ausentes'])?></td><td><?=e((string)(int)$r['cancelados'])?></td><td>
<span class="mini-pill expired"><?=e((string)(int)($r['vencidos']??0))?></span></td><td><?=e(secfmt_rep($r['espera_prom']))?></td></tr>
<?php endforeach;endif;?></tbody></table></div>
    </div>

    <div class="reports-v2-card reports-recent-card">
      <div class="reports-v2-card-head"><div><h2><i class="bi bi-receipt-cutoff"></i> Detalle de tickets recientes</h2>
<small>Últimos tickets generados en el período.</small></div><span class="reports-v2-tag light"><?=$kpiTotal?> total</span></div>
      <div class="table-scroll"><table class="reports-v2-table recent"><thead><tr><th>Ticket</th><th>Hora</th><th>Servicio</th><th>Estado</th>
<th>Duración</th><th>Cierre</th><th>Motivo / cierre</th><th>Representante</th></tr></thead><tbody><?php if(!$recentRows):?><tr>
<td colspan="8" class="reports-empty-cell">No hay tickets para el período.</td></tr>
<?php else:foreach($recentRows as $r):$statusMap=['FINALIZADO'=>['Finalizado','done'],'ATENDIENDO'=>['Atendido','work'],'ESPERANDO'=>['En espera','wait'],'AUSENTE'=>['Ausente','absent'],'CANCELADO'=>['Cancelado','cancel'],'VENCIDO'=>['Vencido por límite de tiempo','expired']];[$statusLabel,$statusCls]=$statusMap[$r['estado']]??[$r['estado'],'work'];?>
<tr><td><strong><?=e($r['prefijo'].str_pad((string)$r['numero'],3,'0',STR_PAD_LEFT))?></strong></td><td>
<?=e(date('h:i A',strtotime($r['hora_creacion'])))?></td><td><?=e($r['servicio'])?></td><td><span class="state-pill <?=$statusCls?>"><?=$statusLabel?>
</span></td><td><?=e(secfmt_rep($r['duracion_total']??null))?></td><td>
<?=e(!empty($r['hora_finalizacion'])?date('h:i:s A',strtotime($r['hora_finalizacion'])):'—')?></td><td><?=e($r['motivo_cierre']??'—')?></td><td>
<?=e($r['representante'])?></td></tr><?php endforeach;endif;?></tbody></table></div>
    </div>
  </section>

  <section class="reports-v2-grid-footer">
    <div class="reports-v2-card reports-times-card"><div class="reports-v2-card-head"><div><h2><i class="bi bi-stopwatch-fill">
</i> Tiempos de atención</h2><small>Promedios y registros del período.</small></div></div><div class="time-metrics"><div class="time-metric purple">
<span>Espera promedio</span><strong><?=e(secfmt_rep($tot['espera_prom']??null))?></strong></div><div class="time-metric blue">
<span>Atención promedio</span><strong><?=e(secfmt_rep($tot['atencion_prom']??null))?></strong></div><div class="time-metric red">
<span>Mayor espera registrada</span><strong><?=e(secfmt_rep($tot['espera_max']??null))?></strong></div></div></div>
    <div class="reports-v2-card reports-result-card"><div class="reports-v2-card-head"><div><h2><i class="bi bi-check2-circle">
</i> Resultado de atención</h2><small>Estado final de los tickets en el período.</small></div></div><div class="result-grid">
<div class="result-item done"><i class="bi bi-check-circle-fill"></i><span>Finalizados</span><strong><?=e((string)(int)$tot['finalizados'])?></strong>
</div><div class="result-item cancel"><i class="bi bi-x-circle-fill"></i><span>Cancelados</span><strong><?=e((string)(int)$tot['cancelados'])?>
</strong></div><div class="result-item absent"><i class="bi bi-person-x-fill"></i><span>Ausentes</span><strong><?=e((string)(int)$tot['ausentes'])?>
</strong></div><div class="result-item wait"><i class="bi bi-hourglass-split"></i><span>En espera</span><strong><?=e((string)(int)$tot['espera'])?>
</strong></div><div class="result-item expired"><i class="bi bi-alarm-fill"></i><span>Vencidos</span><strong>
<?=e((string)(int)($tot['vencidos']??0))?></strong></div></div></div>
  </section>
</div>
<script>
(function(){
  function updateReportClock(){const el=document.getElementById('repLiveClock'); if(!el)return; const d=new Date();
  el.textContent=d.toLocaleTimeString('es-DO',{hour:'2-digit',minute:'2-digit',second:'2-digit'}); }
  updateReportClock();setInterval(updateReportClock,1000);
})();
</script>
<?php require __DIR__.'/../includes/footer.php'; ?>
