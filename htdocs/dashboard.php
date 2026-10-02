<?php
$title='Inicio';require_once __DIR__.'/includes/header.php';
$pdo=db();$today=date('Y-m-d');
[$scopeWhere,$scopeParams]=visible_localidad_where('x');
$where=$scopeWhere; $params=$scopeParams;
$counts=[];
foreach (['ESPERANDO','LLAMADO','ATENDIENDO','FINALIZADO'] as $s) {$st=$pdo->prepare("SELECT COUNT(*) c FROM turnos x WHERE x.fecha=? AND x.estado=? AND $where");$st->execute(array_merge([$today,$s],$params));$counts[$s]=(int)$st->fetch()['c'];}
?>
<div class="page-title"><div><h1>Buenos días, <?=e(explode(' ',user()['nombre'])[0])?> 👋</h1>
<p>Resumen operativo de VIVA TURNOS<?php if(!empty(user()['localidad'])):?> · <?=e(user()['localidad'])?>
<?php elseif(is_manager()):?> · Tus tiendas asignadas<?php else:?> · Todas las localidades<?php endif;?>.</p></div><div><?=date('d/m/Y · h:i A')?>
</div></div>
<div class="grid grid-4">
<?php foreach ([['ESPERANDO','En espera','bi-hourglass'],['LLAMADO','Llamados','bi-megaphone'],['ATENDIENDO','En atención','bi-person-workspace'],['FINALIZADO','Finalizados','bi-check2-circle']] as $x): ?>
<div class="card stat"><div><div class="label"><?=$x[1]?></div><div class="num"><?=$counts[$x[0]]?></div></div><div class="stat-icon"><i class="bi <?=$x[2]?>"></i></div></div>
<?php endforeach; ?>
</div>
<div class="grid grid-2" style="margin-top:18px">
<div class="card"><h3>Accesos rápidos</h3><div class="action-row">
<?php if (in_array(user()['rol'],['ADMINISTRADOR','SUPERVISOR','RECEPCIONISTA'],true)): ?><a class="btn btn-primary" href="<?=app_url('recepcion/')?>"><i class="bi bi-ticket-perforated"></i> Generar turno</a><?php endif; ?>
<?php if (in_array(user()['rol'],['ADMINISTRADOR','SUPERVISOR','REPRESENTANTE','DEALER'],true)): ?><a class="btn btn-dark" href="<?=app_url('representante/')?>"><i class="bi bi-headset"></i> Atención</a><?php endif; ?>
<?php if (!in_array(user()['rol'], ['REPRESENTANTE','RECEPCIONISTA','DEALER','GERENTE'], true)): ?><a class="btn btn-light" href="<?=app_url('monitor/')?>"><i class="bi bi-display"></i> Abrir monitor</a><?php endif; ?>
</div></div>
<?php if (user()['rol']!=='REPRESENTANTE'): ?>
<?php if (!in_array(user()['rol'], ['REPRESENTANTE','RECEPCIONISTA','DEALER'], true)): ?><div class="card"><h3>Regla operativa</h3><p style="color:var(--muted)">Cada usuario tiene un rol único. La estación se asigna por separado y determina desde dónde atiende el representante.</p></div><?php endif; ?>
<?php endif; ?>
</div>
<?php require __DIR__.'/includes/footer.php'; ?>
