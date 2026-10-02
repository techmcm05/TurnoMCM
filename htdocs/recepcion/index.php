<?php $title='Recepción';require_once __DIR__.'/../includes/header.php';$pdo=db();$services=$pdo->query("SELECT * FROM servicios WHERE activo=1 ORDER BY orden,nombre")->fetchAll(); ?>
<div class="page-title"><div><h1>Recepción</h1><p>Registra la gestión del cliente y genera su turno.</p></div><span class="badge b-call">Recepción activa</span></div>
<div class="grid grid-3">
<?php foreach ($services as $s): ?><button class="service-card" onclick="generarTurno(<?=$s['id']?>)"><i class="bi <?=e($s['icono'])?>"></i><h3><?=e($s['nombre'])?></h3><small><?=e($s['descripcion'])?></small></button><?php endforeach;?>
</div>
<div id="ticketResult" style="margin-top:22px"></div>
<script>
async function generarTurno(id){
 const r=await fetch('<?=app_url('api/generar_turno.php')?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'servicio_id='+id+'&csrf=<?=csrf_token()?>'});
 const d=await r.json(); if(!d.ok){alert(d.error);return;}
 document.getElementById('ticketResult').innerHTML=`<div class="card" style="text-align:center"><div style="color:#718078">Turno generado</div>
<div class="turno-big">${d.codigo}</div><strong>${d.servicio}</strong><p style="color:#718078">El cliente debe conservar este ticket.</p>
<div class="action-row" style="justify-content:center">
<a class="btn btn-primary" href="<?=app_url('ticket/imprimir.php')?>?id=${d.id}" target="_blank"><i class="bi bi-printer"></i> Imprimir ticket</a>
<button class="btn btn-light" onclick="location.reload()">Nuevo turno</button></div></div>`;
}
</script>
<?php require __DIR__.'/../includes/footer.php'; ?>
