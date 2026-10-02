<?php require_once __DIR__.'/../includes/helpers.php';require_login();$id=(int)($_GET['id']??0);$st=db()->prepare("SELECT t.*,s.nombre servicio,e.nombre estacion FROM turnos t JOIN servicios s ON s.id=t.servicio_id LEFT JOIN estaciones e ON e.id=t.estacion_id WHERE t.id=?");$st->execute([$id]);$t=$st->fetch();if (!$t)exit('Ticket no encontrado.'); ?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Ticket <?=e(ticket_code($t['prefijo'],$t['numero']))?></title><style>
@page{size:80mm auto;margin:3mm}body{font-family:Arial,sans-serif;text-align:center;width:72mm;margin:auto;color:#173329}.logo-img{display:block;width:46mm;max-width:100%;height:auto;margin:0 auto 3mm}.line{border-top:1px dashed #888;margin:10px 0}.turn{font-size:50px;font-weight:900;margin:10px}.svc{font-size:17px;font-weight:700}.small{font-size:11px;color:#666}@media print{button{display:none}}
</style></head><body><img class="logo-img" src="<?=app_url('assets/img/viva-logo.png')?>" alt="VIVA · Estamos de tu lado"><div class="line"></div>
<div class="small">SU TURNO</div><div class="turn"><?=e(ticket_code($t['prefijo'],$t['numero']))?></div><div class="svc"><?=e($t['servicio'])?></div>
<div class="line"></div><div class="small"><?=date('d/m/Y h:i A',strtotime($t['hora_creacion']))?></div>
<p class="small">Espere su llamado en el monitor.</p><p class="small">VIVA TURNOS</p><button onclick="window.print()">Imprimir</button>
<script>window.onload=()=>setTimeout(()=>window.print(),250)</script></body></html>
