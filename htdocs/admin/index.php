<?php
$title='Administración';require_once __DIR__.'/../includes/header.php';require_role(['ADMINISTRADOR']);$pdo=db();
$users=$pdo->query("SELECT u.id,u.nombre,u.usuario,u.activo,u.estacion_id,u.localidad_id,r.id rol_id,r.nombre rol,e.nombre estacion,l.nombre localidad FROM usuarios u JOIN roles r ON r.id=u.rol_id LEFT JOIN estaciones e ON e.id=u.estacion_id LEFT JOIN localidades l ON l.id=u.localidad_id ORDER BY l.nombre,u.nombre")->fetchAll();
$roles=$pdo->query("SELECT id,nombre FROM roles ORDER BY id")->fetchAll();$managerMap=[];try {$mm=$pdo->query('SELECT gerente_id,localidad_id FROM gerente_localidades');foreach ($mm as $row) {$managerMap[(int)$row['gerente_id']][]=(int)$row['localidad_id'];}}catch (Throwable $e) {}$stations=$pdo->query("SELECT id,nombre,localidad_id,activo FROM estaciones WHERE activo=1 ORDER BY localidad_id,id")->fetchAll();$locations=$pdo->query("SELECT * FROM localidades ORDER BY nombre")->fetchAll();$services=$pdo->query("SELECT * FROM servicios ORDER BY orden,nombre")->fetchAll();$classes=$pdo->query("SELECT * FROM clasificaciones ORDER BY nombre")->fetchAll();
?>
<div class="page-title"><div><h1>Administración</h1><p>Usuarios, roles, estaciones y localidades de VIVA.</p></div><div class="action-row">
<button class="btn btn-dark" onclick="openLocation()"><i class="bi bi-geo-alt-fill"></i> Nueva localidad</button>
<button class="btn btn-blue" onclick="openService()"><i class="bi bi-diagram-3-fill"></i> Nuevo servicio</button>
<button class="btn btn-light" onclick="openClass()"><i class="bi bi-tags-fill"></i> Nueva clasificación</button>
<a class="btn btn-blue" href="<?=app_url('reportes/') ?>"><i class="bi bi-bar-chart-fill"></i> Estadísticas por localidad</a>
<button class="btn btn-primary" onclick="openCreate()"><i class="bi bi-person-plus-fill"></i> Nuevo usuario</button></div></div>
<div class="grid grid-2">
<div class="card" style="grid-column:span 2"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px"><div>
<h3 style="margin:0">Usuarios del sistema</h3>
<small style="color:var(--muted)">Cada usuario posee un único rol. La localidad determina su ámbito operativo.</small></div>
<span class="badge b-call"><?=count($users)?> usuarios</span></div><div style="overflow:auto"><table class="table"><thead><tr><th>Usuario</th>
<th>Nombre</th><th>Rol</th><th>Localidad</th><th>Estación</th><th>Estado</th><th>Acciones</th></tr></thead><tbody><?php foreach($users as $u):?><tr>
<td><strong><?=e($u['usuario'])?></strong></td><td><?=e($u['nombre'])?></td><td><span class="badge b-work"><?=e($u['rol'])?></span></td><td>
<?php if(($u['rol']??'')==='GERENTE'): $ids=$managerMap[(int)$u['id']]??[]; $names=[]; foreach($locations as $ll){if(in_array((int)$ll['id'],$ids,true))$names[]=$ll['nombre'];} ?>
<span class="badge b-work"><?=count($names)?> tienda(s)</span><br><small><?=e(implode(', ',$names))?></small><?php else:?>
<?=e($u['localidad']??'Todas / Global')?><?php endif;?></td><td><?=e($u['estacion']??'Sin asignar')?></td><td>
<span class="badge <?=$u['activo']?'b-call':'b-done'?>"><?=$u['activo']?'Activo':'Inactivo'?></span></td><td><div class="action-row">
<button class="btn btn-light" onclick='openEdit(<?=json_encode(array_merge($u,["manager_localidades"=>($managerMap[(int)$u["id"]]??[])]),JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>
<i class="bi bi-pencil"></i></button>
<button class="btn btn-blue" onclick='openPassword(<?=json_encode(["id"=>$u["id"],"nombre"=>$u["nombre"]],JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>
<i class="bi bi-key-fill"></i></button><?php if((int)$u['id'] !== (int)user()['id']):?>
<button class="btn <?=$u['activo']?'btn-danger':'btn-primary'?>" onclick="toggleUser(<?=$u['id']?>)" title="<?=$u['activo']?'Desactivar usuario':'Activar usuario'?>">
<i class="bi <?=$u['activo']?'bi-person-x':'bi-person-check'?>"></i></button>
<button class="btn btn-danger delete-user-btn" onclick='openDelete(<?=json_encode(["id"=>$u["id"],"nombre"=>$u["nombre"],"usuario"=>$u["usuario"]],JSON_HEX_APOS|JSON_HEX_QUOT)?>)' title="Eliminar usuario">
<i class="bi bi-trash3-fill"></i></button><?php endif;?></div></td></tr><?php endforeach;?></tbody></table></div></div>
<div class="card"><div style="display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:10px"><div>
<h3 style="margin:0">Localidades y estaciones</h3>
<p style="color:var(--muted);font-size:13px;margin:4px 0 0">Las estaciones se generan automáticamente por localidad. No necesitas crearlas una por una.</p>
</div><span class="badge b-call"><?=count($locations)?> localidades</span></div><div class="queue-list">
<?php foreach ($locations as $l): $stationCount=(int)($l['cantidad_estaciones']??0); ?><div class="queue-item" style="align-items:center;gap:16px"><div style="min-width:0;flex:1"><strong><?=e($l['nombre'])?></strong><br><small><?=e($l['codigo'])?></small></div><div class="action-row" style="gap:8px;align-items:center"><span class="badge <?=$l['activo']?'b-call':'b-done'?>"><?=$l['activo']?'Activa':'Inactiva'?></span><div class="station-stepper" data-locality="<?=$l['id']?>"><span style="font-size:12px;color:var(--muted);font-weight:700">Estaciones</span><button type="button" class="btn btn-light station-step-btn" onclick="changeStationCount(<?=$l['id']?>,-1)" aria-label="Reducir estaciones">−</button><input class="station-count-input" id="stationCount<?=$l['id']?>" type="number" min="0" max="50" value="<?=$stationCount?>" aria-label="Cantidad de estaciones de <?=e($l['nombre'])?>" onchange="setStationCount(<?=$l['id']?>,this.value)"><button type="button" class="btn btn-light station-step-btn" onclick="changeStationCount(<?=$l['id']?>,1)" aria-label="Aumentar estaciones">+</button></div><button class="btn btn-light" onclick="toggleLocation(<?=$l['id']?>)" title="Activar / desactivar"><i class="bi bi-power"></i></button><button class="btn btn-danger" onclick='openResourceDelete("localidad",<?=json_encode(["id"=>$l["id"],"nombre"=>$l["nombre"],"codigo"=>$l["codigo"]],JSON_HEX_APOS|JSON_HEX_QUOT)?>)' title="Eliminar localidad"><i class="bi bi-trash3-fill"></i></button></div></div><?php endforeach; ?></div><div style="margin-top:12px;padding:10px 12px;border-radius:10px;background:var(--mint);border:1px solid var(--line);font-size:12px;color:var(--muted)"><i class="bi bi-info-circle-fill" style="color:#48a83a"></i> El número indica las estaciones activas. Al aumentar, se crean o reactivan <strong>Estación 01, 02, 03...</strong>. Al reducir, se desactivan las últimas sin eliminar el historial.</div></div>
<div class="card"><h3>Roles únicos</h3>
<p style="color:var(--muted);font-size:13px">ADMINISTRADOR, SUPERVISOR, GERENTE, RECEPCIONISTA, REPRESENTANTE, DEALER, CONSULTA y MONITOR.</p>
<div class="queue-list"><?php foreach($roles as $r):?><div class="queue-item"><strong><?=e($r['nombre'])?></strong>
<i class="bi bi-shield-check" style="color:#39952a"></i></div><?php endforeach;?></div></div>
<div class="card"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px"><div>
<h3 style="margin:0">Servicios</h3>
<p style="color:var(--muted);font-size:13px;margin:4px 0 0">Define a qué tipo de personal se dirige cada servicio.</p></div>
<button class="btn btn-primary" onclick="openService()"><i class="bi bi-plus-lg"></i></button></div><div style="overflow:auto"><table class="table">
<thead><tr><th>Servicio</th><th>Prefijo</th><th>Dirigido a</th><th>Estado</th><th>Acciones</th></tr></thead><tbody><?php foreach($services as $s):?>
<tr><td><strong><?=e($s['nombre'])?></strong><br><small style="color:var(--muted)"><?=e($s['descripcion'])?></small></td><td>
<span class="badge b-work"><?=e($s['prefijo'])?></span></td><td>
<?php $dest=$s['destino']??'REPRESENTANTE'; $destLabel=$dest==='DEALER'?'Dealer':($dest==='AMBOS'?'Dealer + Representante':'Representante'); ?>
<span class="badge <?=$dest==='DEALER'?'b-call':($dest==='AMBOS'?'b-work':'b-done')?>"><?=e($destLabel)?></span></td><td>
<span class="badge <?=$s['activo']?'b-call':'b-done'?>"><?=$s['activo']?'Activo':'Inactivo'?></span></td><td><div class="action-row">
<button class="btn btn-light" onclick='openEditService(<?=json_encode($s,JSON_HEX_APOS|JSON_HEX_QUOT)?>)' title="Editar servicio">
<i class="bi bi-pencil"></i></button><button class="btn btn-light" onclick="toggleService(<?=$s['id']?>)" title="Activar / desactivar">
<i class="bi bi-power"></i></button>
<button class="btn btn-danger" onclick='openDeleteService(<?=json_encode(["id"=>$s["id"],"nombre"=>$s["nombre"],"prefijo"=>$s["prefijo"]],JSON_HEX_APOS|JSON_HEX_QUOT)?>)' title="Eliminar servicio">
<i class="bi bi-trash3-fill"></i></button></div></td></tr><?php endforeach;?></tbody></table></div></div>
<div class="card"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px"><div>
<h3 style="margin:0">Clasificaciones</h3>
<p style="color:var(--muted);font-size:13px;margin:4px 0 0">Opciones obligatorias que el personal utiliza al completar un ticket.</p></div>
<button class="btn btn-primary" onclick="openClass()"><i class="bi bi-plus-lg"></i></button></div><div style="overflow:auto"><table class="table">
<thead><tr><th>Clasificación</th><th>Estado</th><th>Acciones</th></tr></thead><tbody><?php foreach($classes as $c):?><tr><td><strong>
<?=e($c['nombre'])?></strong></td><td><span class="badge <?=$c['activo']?'b-call':'b-done'?>"><?=$c['activo']?'Activa':'Inactiva'?></span></td><td>
<div class="action-row"><button class="btn btn-light" onclick="toggleClass(<?=$c['id']?>)" title="Activar / desactivar"><i class="bi bi-power"></i>
</button>
<button class="btn btn-danger" onclick='openDeleteClass(<?=json_encode(["id"=>$c["id"],"nombre"=>$c["nombre"]],JSON_HEX_APOS|JSON_HEX_QUOT)?>)' title="Eliminar clasificación">
<i class="bi bi-trash3-fill"></i></button></div></td></tr><?php endforeach;?></tbody></table></div></div>
</div>
<div class="modal-backdrop" id="userModal"><div class="modal-box"><div class="modal-heading"><h2 id="modalTitle">Nuevo usuario</h2>
<button class="btn btn-light" onclick="closeModal()"><i class="bi bi-x-lg"></i></button></div><form id="userForm">
<input type="hidden" name="accion" id="accion" value="crear"><input type="hidden" name="id" id="userId"><div class="grid grid-2"><div>
<label class="form-label">Nombre completo *</label><input class="form-control" name="nombre" id="nombre" required></div><div>
<label class="form-label">Usuario *</label><input class="form-control" name="usuario" id="usuario" required></div></div>
<div id="passwordCreate" style="margin-top:14px"><label class="form-label">Contraseña *</label>
<input class="form-control" type="password" name="password" id="password" minlength="8"><small style="color:var(--muted)">Mínimo 8 caracteres.</small>
</div><div class="grid grid-2" style="margin-top:14px"><div><label class="form-label">Rol *</label>
<select class="form-select" name="rol_id" id="rol_id" required><?php foreach($roles as $r):?><option value="<?=$r['id']?>"><?=e($r['nombre'])?>
</option><?php endforeach;?></select></div><div><label class="form-label">Localidad / tienda</label>
<select class="form-select" name="localidad_id" id="localidad_id" onchange="filterStations()"><option value="">Todas / Global</option>
<?php foreach($locations as $l):?><option value="<?=$l['id']?>"><?=e($l['nombre'])?></option><?php endforeach;?></select></div></div>
<div id="managerLocationsBox" style="display:none;margin-top:14px;padding:14px;border:1px solid var(--line);border-radius:12px;background:var(--mint)">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
<label class="form-label" style="margin:0">Tiendas que puede visualizar *</label>
<button type="button" class="btn btn-light" onclick="toggleManagerLocations(true)">Seleccionar todas</button></div><div class="manager-location-grid">
<?php foreach($locations as $l): ?><label class="manager-location-option">
<input type="checkbox" name="gerente_localidades[]" value="<?=$l['id']?>" data-manager-location> <?=e($l['nombre'])?></label><?php endforeach; ?>
</div>
<small style="color:var(--muted)">El Gerente puede supervisar varias tiendas a la vez. La asignación controla exactamente qué localidades puede visualizar.</small>
</div><div style="margin-top:14px"><label class="form-label">Estación</label><select class="form-select" name="estacion_id" id="estacion_id">
<option value="">Sin asignar</option><?php foreach($stations as $s):?><option value="<?=$s['id']?>" data-location="<?=$s['localidad_id']?>">
<?=e($s['nombre'])?></option><?php endforeach;?></select></div><div id="activeBox" style="margin-top:14px;display:none"><label>
<input type="checkbox" name="activo" id="activo" checked> Usuario activo</label></div>
<div class="action-row" style="justify-content:flex-end;margin-top:22px">
<button type="button" class="btn btn-light" onclick="closeModal()">Cancelar</button><button class="btn btn-primary"><i class="bi bi-check2">
</i> Guardar</button></div></form></div></div>
<div class="modal-backdrop" id="passwordModal"><div class="modal-box" style="max-width:480px"><div class="modal-heading"><h2>Cambiar contraseña</h2>
<button class="btn btn-light" onclick="closePassword()"><i class="bi bi-x-lg"></i></button></div><p id="passwordUser" style="color:var(--muted)"></p>
<form id="passwordForm"><input type="hidden" name="accion" value="password"><input type="hidden" name="id" id="passwordId">
<label class="form-label">Nueva contraseña *</label><input class="form-control" type="password" name="password" minlength="8" required>
<small style="color:var(--muted)">Mínimo 8 caracteres.</small><div class="action-row" style="justify-content:flex-end;margin-top:22px">
<button type="button" class="btn btn-light" onclick="closePassword()">Cancelar</button><button class="btn btn-primary">Cambiar</button></div></form>
</div></div>
<div class="modal-backdrop" id="deleteUserModal"><div class="modal-box delete-user-modal" style="max-width:520px"><div class="modal-heading"><div>
<h2 style="margin:0;color:#a32727">Eliminar usuario</h2><small style="color:var(--muted)">Esta acción es permanente y no se puede deshacer.</small>
</div><button class="btn btn-light" onclick="closeDelete()"><i class="bi bi-x-lg"></i></button></div><div class="delete-warning">
<div class="delete-warning-icon"><i class="bi bi-exclamation-triangle-fill"></i></div><div><strong id="deleteUserName"></strong>
<div id="deleteUserLogin" style="color:var(--muted);margin-top:3px"></div>
<p style="margin:8px 0 0;font-size:13px;color:#6d4b47">La cuenta, sus credenciales y configuración serán eliminadas. Los tickets históricos se conservan; si este usuario tiene una atención en curso, el ticket se devuelve automáticamente a la cola para que otro agente pueda atenderlo.</p>
</div></div><form id="deleteUserForm"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" id="deleteUserId">
<label class="form-label">Para confirmar, escribe <strong>ELIMINAR</strong></label>
<input class="form-control" id="deleteConfirmText" autocomplete="off" placeholder="ELIMINAR" required>
<div class="action-row" style="justify-content:flex-end;margin-top:22px">
<button type="button" class="btn btn-light" onclick="closeDelete()">Cancelar</button>
<button class="btn btn-danger" id="deleteConfirmBtn" type="submit" disabled><i class="bi bi-trash3-fill"></i> Eliminar definitivamente</button></div>
</form></div></div>
<div class="modal-backdrop" id="locationModal"><div class="modal-box" style="max-width:500px"><div class="modal-heading"><div><h2>Nueva localidad</h2>
<small style="color:var(--muted)">Crea la tienda y define cuántas estaciones tendrá.</small></div>
<button class="btn btn-light" type="button" onclick="closeLocation()"><i class="bi bi-x-lg"></i></button></div><form id="locationForm">
<input type="hidden" name="accion" value="crear"><label class="form-label">Nombre de la tienda *</label>
<input class="form-control" name="nombre" required maxlength="120" placeholder="Ej. Tienda Herrera">
<label class="form-label" style="margin-top:14px">Código *</label>
<input class="form-control" name="codigo" maxlength="20" required placeholder="Ej. HERRERA">
<label class="form-label" style="margin-top:14px">Cantidad de estaciones *</label>
<input class="form-control" type="number" name="cantidad_estaciones" min="0" max="50" value="3" required>
<small style="color:var(--muted)">El sistema creará automáticamente Estación 01, Estación 02, etc.</small>
<div class="action-row" style="justify-content:flex-end;margin-top:22px">
<button type="button" class="btn btn-light" onclick="closeLocation()">Cancelar</button><button class="btn btn-primary"><i class="bi bi-building-add">
</i> Crear localidad</button></div></form></div></div>
<div class="modal-backdrop" id="resourceDeleteModal"><div class="modal-box resource-delete-modal" style="max-width:520px"><div class="modal-heading">
<div><h2 id="resourceDeleteTitle" style="margin:0;color:#a32727">Eliminar registro</h2>
<small style="color:var(--muted)">Esta acción es permanente.</small></div><button class="btn btn-light" type="button" onclick="closeResourceDelete()">
<i class="bi bi-x-lg"></i></button></div><div class="delete-warning"><div class="delete-warning-icon"><i class="bi bi-exclamation-triangle-fill"></i>
</div><div><strong id="resourceDeleteName"></strong><div id="resourceDeleteMeta" style="color:var(--muted);margin-top:3px"></div>
<p id="resourceDeleteText" style="margin:8px 0 0;font-size:13px;color:#6d4b47">La eliminación definitiva puede afectar relaciones operativas. Para registros con actividad asociada, el sistema puede exigir desactivarlos en lugar de borrarlos.</p>
</div></div><form id="resourceDeleteForm"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" id="resourceDeleteId">
<input type="hidden" name="tipo" id="resourceDeleteType"><label class="form-label">Para confirmar, escribe <strong>ELIMINAR</strong></label>
<input class="form-control" id="resourceDeleteConfirmText" autocomplete="off" placeholder="ELIMINAR" required>
<div class="action-row" style="justify-content:flex-end;margin-top:22px">
<button type="button" class="btn btn-light" onclick="closeResourceDelete()">Cancelar</button>
<button class="btn btn-danger" id="resourceDeleteConfirmBtn" type="submit" disabled><i class="bi bi-trash3-fill">
</i> Eliminar definitivamente</button></div></form></div></div>
<div class="modal-backdrop" id="serviceModal"><div class="modal-box" style="max-width:560px"><div class="modal-heading"><div>
<h2 id="serviceModalTitle">Nuevo servicio</h2><small style="color:var(--muted)">Configura el servicio y a quién debe llegar.</small></div>
<button class="btn btn-light" type="button" onclick="closeService()"><i class="bi bi-x-lg"></i></button></div><form id="serviceForm">
<input type="hidden" name="accion" value="crear"><input type="hidden" name="id" id="serviceId"><div class="grid grid-2"><div>
<label class="form-label">Nombre *</label><input class="form-control" name="nombre" required maxlength="100" placeholder="Ej. Migraciones"></div><div>
<label class="form-label">Prefijo *</label>
<input class="form-control" name="prefijo" required maxlength="5" pattern="[A-Za-z0-9]{1,5}" placeholder="Ej. M"></div></div>
<div style="margin-top:14px"><label class="form-label">Descripción</label>
<input class="form-control" name="descripcion" maxlength="180" placeholder="Descripción breve del servicio"></div>
<div class="grid grid-2" style="margin-top:14px"><div><label class="form-label">Dirigido a *</label>
<select class="form-select" name="destino" required><option value="REPRESENTANTE">Representante de tienda</option>
<option value="DEALER">Dealer</option><option value="AMBOS">Dealer + Representante</option></select></div><div><label class="form-label">Orden</label>
<input class="form-control" type="number" name="orden" min="0" value="0"></div></div><div style="margin-top:14px">
<label class="form-label">Icono Bootstrap (opcional)</label>
<input class="form-control" name="icono" maxlength="80" value="bi-grid" placeholder="Ej. bi-phone-fill"></div>
<div class="action-row" style="justify-content:flex-end;margin-top:22px">
<button type="button" class="btn btn-light" onclick="closeService()">Cancelar</button><button class="btn btn-primary" id="serviceSubmitBtn">
<i class="bi bi-check2"></i> Crear servicio</button></div></form></div></div>
<div class="modal-backdrop" id="classModal"><div class="modal-box" style="max-width:480px"><div class="modal-heading"><div>
<h2>Nueva clasificación</h2><small style="color:var(--muted)">Quedará disponible al completar tickets.</small></div>
<button class="btn btn-light" type="button" onclick="closeClass()"><i class="bi bi-x-lg"></i></button></div><form id="classForm">
<input type="hidden" name="accion" value="crear"><label class="form-label">Nombre de la clasificación *</label>
<input class="form-control" name="nombre" required maxlength="120" placeholder="Ej. Cambio de plan">
<div class="action-row" style="justify-content:flex-end;margin-top:22px">
<button type="button" class="btn btn-light" onclick="closeClass()">Cancelar</button><button class="btn btn-primary"><i class="bi bi-check2">
</i> Crear clasificación</button></div></form></div></div>

<script>
const csrf='<?=csrf_token()?>'; document.getElementById('rol_id').addEventListener('change',()=>{syncRoleFields();filterStations();});
function show(id){document.getElementById(id).style.display='flex'}function hide(id){document.getElementById(id).style.display='none'}
function filterStations(){const loc=document.getElementById('localidad_id').value;
document.querySelectorAll('#estacion_id option[data-location]').forEach(o=>o.hidden=!!loc&&o.dataset.location!==loc);
if(document.querySelector('#estacion_id option:checked')?.hidden)document.getElementById('estacion_id').value=''; }
function roleIsManager(){return document.getElementById('rol_id').selectedOptions[0]?.textContent.trim()==='GERENTE'}
function syncRoleFields(){const manager=roleIsManager();
document.getElementById('managerLocationsBox').style.display=manager?'block':'none';
document.getElementById('localidad_id').disabled=manager; document.getElementById('estacion_id').disabled=manager;
if(manager){document.getElementById('localidad_id').value=''; document.getElementById('estacion_id').value=''; }}
function toggleManagerLocations(all){document.querySelectorAll('[data-manager-location]').forEach(c=>c.checked=all)}
function openCreate(){document.getElementById('userForm').reset(); document.getElementById('accion').value='crear';
document.getElementById('modalTitle').textContent='Nuevo usuario';
document.getElementById('passwordCreate').style.display='block'; document.getElementById('activeBox').style.display='none';
document.querySelectorAll('[data-manager-location]').forEach(c=>c.checked=false); syncRoleFields(); filterStations();
show('userModal')}
function openEdit(u){document.getElementById('userForm').reset(); document.getElementById('accion').value='editar';
document.getElementById('userId').value=u.id; document.getElementById('nombre').value=u.nombre;
document.getElementById('usuario').value=u.usuario; document.getElementById('rol_id').value=u.rol_id;
document.getElementById('localidad_id').value=u.localidad_id||'';
document.getElementById('estacion_id').value=u.estacion_id||''; document.getElementById('activo').checked=!!Number(u.activo);
document.getElementById('modalTitle').textContent='Editar usuario';
document.getElementById('passwordCreate').style.display='none'; document.getElementById('activeBox').style.display='block';
document.querySelectorAll('[data-manager-location]').forEach(c=>c.checked=(u.manager_localidades||[]).map(Number).includes(Number(c.value)));
syncRoleFields(); filterStations(); document.getElementById('estacion_id').value=u.estacion_id||''; show('userModal')}
function closeModal(){hide('userModal')}
function openPassword(u){document.getElementById('passwordId').value=u.id;
document.getElementById('passwordUser').textContent='Usuario: '+u.nombre;
show('passwordModal')}function closePassword(){hide('passwordModal')}
function openLocation(){document.getElementById('locationForm').reset();show('locationModal')}function closeLocation(){hide('locationModal')}
async function send(form,endpoint){const body=new URLSearchParams(new FormData(form)); body.set('csrf',csrf);
const r=await fetch('<?=app_url('api/') ?>'+endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
return r.json()}
document.getElementById('userForm').addEventListener('submit',async e=>{e.preventDefault();const d=await send(e.target,'usuarios.php');if(!d.ok)return alert(d.error);location.reload()});
document.getElementById('serviceForm').addEventListener('submit',async e=>{e.preventDefault();const d=await send(e.target,'servicios.php');if(!d.ok)return alert(d.error);closeService();alert(d.mensaje||'Servicio creado correctamente.');location.reload()});
document.getElementById('classForm').addEventListener('submit',async e=>{e.preventDefault();const d=await send(e.target,'clasificaciones.php');if(!d.ok)return alert(d.error);closeClass();alert(d.mensaje||'Clasificación creada correctamente.');location.reload()});
document.getElementById('passwordForm').addEventListener('submit',async e=>{e.preventDefault();const d=await send(e.target,'usuarios.php');if(!d.ok)return alert(d.error);closePassword();alert(d.mensaje);});
document.getElementById('locationForm').addEventListener('submit',async e=>{e.preventDefault();const d=await send(e.target,'localidades.php');if(!d.ok)return alert(d.error);closeLocation();alert(d.mensaje||'Localidad creada correctamente.');location.reload()});
function openDelete(u){document.getElementById('deleteUserId').value=u.id;
document.getElementById('deleteUserName').textContent=u.nombre;
document.getElementById('deleteUserLogin').textContent='Usuario: '+u.usuario;
document.getElementById('deleteConfirmText').value=''; document.getElementById('deleteConfirmBtn').disabled=true;
show('deleteUserModal');
setTimeout(()=>document.getElementById('deleteConfirmText').focus(),50)}function closeDelete(){hide('deleteUserModal')}
document.getElementById('deleteConfirmText').addEventListener('input',function(){document.getElementById('deleteConfirmBtn').disabled=this.value.trim()!=='ELIMINAR'});
document.getElementById('deleteUserForm').addEventListener('submit',async e=>{e.preventDefault();const typed=document.getElementById('deleteConfirmText').value.trim();if(typed!=='ELIMINAR')return;const body=new URLSearchParams(new FormData(e.target));body.set('csrf',csrf);const r=await fetch('<?=app_url('api/usuarios.php')?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});const d=await r.json();if(!d.ok)return alert(d.error);closeDelete();alert(d.mensaje||'Usuario eliminado correctamente.');location.reload()});
async function toggleUser(id){const b=new URLSearchParams({accion:'toggle',id,csrf});
const d=await fetch('<?=app_url('api/usuarios.php')?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b}).then(r=>r.json());
if(!d.ok)return alert(d.error); location.reload()}
async function setStationCount(id,value){const count=Math.max(0,Math.min(50,parseInt(value,10)||0));
const input=document.getElementById('stationCount'+id); if(input)input.value=count;
const b=new URLSearchParams({accion:'cantidad_estaciones',id,cantidad_estaciones:String(count),csrf});
try{const d=await fetch('<?=app_url('api/localidades.php')?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b}).then(r=>r.json());
if(!d.ok){if(input)input.value=d.actual!==undefined?d.actual:count;
return alert(d.error)}if(input)input.value=d.cantidad_estaciones;
}catch(err){alert('No fue posible actualizar la cantidad de estaciones. Verifica la conexión.'); location.reload(); }}
function changeStationCount(id,delta){const input=document.getElementById('stationCount'+id); if(!input)return;
const current=parseInt(input.value,10)||0; setStationCount(id,current+delta)}
async function toggleStation(id){const b=new URLSearchParams({accion:'toggle',id,csrf});
const d=await fetch('<?=app_url('api/estaciones.php')?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b}).then(r=>r.json());
if(!d.ok)return alert(d.error); location.reload()}
async function toggleLocation(id){const b=new URLSearchParams({accion:'toggle',id,csrf});
const d=await fetch('<?=app_url('api/localidades.php')?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b}).then(r=>r.json());
if(!d.ok)return alert(d.error); location.reload()}
function openService(){document.getElementById('serviceForm').reset(); document.getElementById('serviceId').value='';
document.getElementById('serviceForm').querySelector('[name="accion"]').value='crear';
document.getElementById('serviceModalTitle').textContent='Nuevo servicio';
document.getElementById('serviceSubmitBtn').innerHTML='<i class="bi bi-check2">
</i> Crear servicio';document.getElementById('serviceForm').querySelector('[name="icono"]').value='bi-grid';document.getElementById('serviceForm').querySelector('[name="destino"]').value='REPRESENTANTE';document.getElementById('serviceForm').querySelector('[name="orden"]').value='0';show('serviceModal')}function openEditService(s){document.getElementById('serviceForm').reset();document.getElementById('serviceId').value=s.id;document.getElementById('serviceForm').querySelector('[name="accion"]').value='editar';document.getElementById('serviceModalTitle').textContent='Editar servicio';document.getElementById('serviceSubmitBtn').innerHTML='<i class="bi bi-save2">
</i> Guardar cambios';document.getElementById('serviceForm').querySelector('[name="nombre"]').value=s.nombre||'';document.getElementById('serviceForm').querySelector('[name="prefijo"]').value=s.prefijo||'';document.getElementById('serviceForm').querySelector('[name="descripcion"]').value=s.descripcion||'';document.getElementById('serviceForm').querySelector('[name="icono"]').value=s.icono||'bi-grid';document.getElementById('serviceForm').querySelector('[name="destino"]').value=s.destino||'REPRESENTANTE';document.getElementById('serviceForm').querySelector('[name="orden"]').value=s.orden||0;show('serviceModal')}function closeService(){hide('serviceModal')}
function openClass(){document.getElementById('classForm').reset();show('classModal')}function closeClass(){hide('classModal')}
async function toggleService(id){const b=new URLSearchParams({accion:'toggle',id,csrf});
const d=await fetch('<?=app_url('api/servicios.php')?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b}).then(r=>r.json());
if(!d.ok)return alert(d.error); location.reload()}
async function toggleClass(id){const b=new URLSearchParams({accion:'toggle',id,csrf});
const d=await fetch('<?=app_url('api/clasificaciones.php')?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b}).then(r=>r.json());
if(!d.ok)return alert(d.error); location.reload()}
function openDeleteService(s){openResourceDelete('servicio',s)}
function openDeleteClass(c){openResourceDelete('clasificacion',c)}
function openResourceDelete(type,item){const title=type==='localidad'?'Eliminar localidad':(type==='estacion'?'Eliminar estación':(type==='servicio'?'Eliminar servicio':'Eliminar clasificación'));
document.getElementById('resourceDeleteTitle').textContent=title;
document.getElementById('resourceDeleteName').textContent=item.nombre;
document.getElementById('resourceDeleteMeta').textContent=type==='localidad'?('Código: '+(item.codigo||'—')):(type==='servicio'?('Prefijo: '+(item.prefijo||'—')):(type==='estacion'?'Estación del sistema':'Clasificación del sistema'));
document.getElementById('resourceDeleteId').value=item.id; document.getElementById('resourceDeleteType').value=type;
document.getElementById('resourceDeleteConfirmText').value='';
document.getElementById('resourceDeleteConfirmBtn').disabled=true; show('resourceDeleteModal');
setTimeout(()=>document.getElementById('resourceDeleteConfirmText').focus(),50)}
function closeResourceDelete(){hide('resourceDeleteModal')}
document.getElementById('resourceDeleteConfirmText').addEventListener('input',function(){document.getElementById('resourceDeleteConfirmBtn').disabled=this.value.trim()!=='ELIMINAR'});
document.getElementById('resourceDeleteForm').addEventListener('submit',async e=>{e.preventDefault();if(document.getElementById('resourceDeleteConfirmText').value.trim()!=='ELIMINAR')return;const body=new URLSearchParams(new FormData(e.target));body.set('csrf',csrf);const type=document.getElementById('resourceDeleteType').value;const endpoint=type==='localidad'?'localidades.php':(type==='estacion'?'estaciones.php':(type==='servicio'?'servicios.php':'clasificaciones.php'));const d=await fetch('<?=app_url('api/') ?>'+endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body}).then(r=>r.json());if(!d.ok)return alert(d.error);closeResourceDelete();alert(d.mensaje||'Eliminado correctamente.');location.reload()});
</script>
<style>.manager-location-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.manager-location-option{display:flex;align-items:center;gap:7px;padding:8px 10px;border:1px solid var(--line);border-radius:9px;background:var(--surface);font-size:12px}.manager-location-option input{accent-color:#70c718}@media(max-width:700px){.manager-location-grid{grid-template-columns:1fr}}</style>
<?php require __DIR__.'/../includes/footer.php'; ?>
