CREATE TABLE IF NOT EXISTS roles(
 id INT AUTO_INCREMENT PRIMARY KEY,
 nombre VARCHAR(40) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS localidades(
 id INT AUTO_INCREMENT PRIMARY KEY,
 nombre VARCHAR(120) NOT NULL UNIQUE,
 codigo VARCHAR(20) NOT NULL UNIQUE,
 activo TINYINT(1) NOT NULL DEFAULT 1,
 cantidad_estaciones INT NOT NULL DEFAULT 0,
 sesion_activa TINYINT(1) NOT NULL DEFAULT 0,
 ultima_actividad DATETIME NULL,
 session_token VARCHAR(64) NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS estaciones(
 id INT AUTO_INCREMENT PRIMARY KEY,
 nombre VARCHAR(60) NOT NULL,
 localidad_id INT NULL,
 activo TINYINT(1) NOT NULL DEFAULT 1,
 FOREIGN KEY(localidad_id) REFERENCES localidades(id) ON DELETE SET NULL,
 INDEX idx_estacion_localidad(localidad_id),
 UNIQUE KEY uq_estaciones_localidad_nombre(localidad_id,nombre)
);

CREATE TABLE IF NOT EXISTS usuarios(
 id INT AUTO_INCREMENT PRIMARY KEY,
 nombre VARCHAR(120) NOT NULL,
 usuario VARCHAR(60) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 rol_id INT NOT NULL,
 estacion_id INT NULL,
 localidad_id INT NULL,
 activo TINYINT(1) NOT NULL DEFAULT 1,
 sesion_activa TINYINT(1) NOT NULL DEFAULT 0,
 ultima_actividad DATETIME NULL,
 session_token VARCHAR(64) NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(rol_id) REFERENCES roles(id),
 FOREIGN KEY(estacion_id) REFERENCES estaciones(id) ON DELETE SET NULL,
 FOREIGN KEY(localidad_id) REFERENCES localidades(id) ON DELETE SET NULL,
 INDEX idx_usuario_localidad(localidad_id),
 INDEX idx_usuario_rol_localidad(rol_id,localidad_id)
);

CREATE TABLE IF NOT EXISTS servicios(
 id INT AUTO_INCREMENT PRIMARY KEY,
 nombre VARCHAR(100) NOT NULL UNIQUE,
 prefijo VARCHAR(5) NOT NULL UNIQUE,
 descripcion VARCHAR(180) NOT NULL DEFAULT '',
 icono VARCHAR(80) NOT NULL DEFAULT 'bi-grid',
 destino ENUM('REPRESENTANTE','DEALER','AMBOS') NOT NULL DEFAULT 'REPRESENTANTE',
 orden INT NOT NULL DEFAULT 0,
 activo TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS clasificaciones(
 id INT AUTO_INCREMENT PRIMARY KEY,
 nombre VARCHAR(120) NOT NULL UNIQUE,
 activo TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS rol_servicio(
 rol_id INT NOT NULL,
 servicio_id INT NOT NULL,
 PRIMARY KEY(rol_id,servicio_id),
 FOREIGN KEY(rol_id) REFERENCES roles(id) ON DELETE CASCADE,
 FOREIGN KEY(servicio_id) REFERENCES servicios(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS turnos(
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 numero INT NOT NULL,
 prefijo VARCHAR(5) NOT NULL,
 servicio_id INT NOT NULL,
 localidad_id INT NULL,
 fecha DATE NOT NULL,
 estado ENUM('ESPERANDO','LLAMADO','ATENDIENDO','FINALIZADO','AUSENTE','CANCELADO','VENCIDO') NOT NULL DEFAULT 'ESPERANDO',
 prioridad TINYINT NOT NULL DEFAULT 0,
 hora_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 hora_llamado DATETIME NULL,
 llamado_seq INT NOT NULL DEFAULT 0,
 hora_inicio DATETIME NULL,
 hora_finalizacion DATETIME NULL,
 recepcionista_id INT NULL,
 atendiente_id INT NULL,
 estacion_id INT NULL,
 clasificacion_id INT NULL,
 notas TEXT NULL,
 motivo_cierre VARCHAR(180) NULL,
 FOREIGN KEY(servicio_id) REFERENCES servicios(id),
 FOREIGN KEY(localidad_id) REFERENCES localidades(id) ON DELETE SET NULL,
 FOREIGN KEY(recepcionista_id) REFERENCES usuarios(id) ON DELETE SET NULL,
 FOREIGN KEY(atendiente_id) REFERENCES usuarios(id) ON DELETE SET NULL,
 FOREIGN KEY(estacion_id) REFERENCES estaciones(id) ON DELETE SET NULL,
 FOREIGN KEY(clasificacion_id) REFERENCES clasificaciones(id) ON DELETE SET NULL,
 INDEX idx_cola(fecha,localidad_id,estado,hora_creacion),
 INDEX idx_servicio(fecha,servicio_id),
 INDEX idx_turno_localidad(fecha,localidad_id,hora_creacion)
);

CREATE TABLE IF NOT EXISTS auditoria(
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 usuario_id INT NULL,
 accion VARCHAR(80) NOT NULL,
 detalle VARCHAR(500) NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
 INDEX idx_auditoria_fecha(creado_en)
);


CREATE TABLE IF NOT EXISTS estados_personal(
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 usuario_id INT NOT NULL,
 localidad_id INT NULL,
 estado ENUM('DISPONIBLE','NO_RECIBIENDO','ALMUERZO','BREAK','NO_DISPONIBLE','OTROS') NOT NULL,
 motivo VARCHAR(120) NULL,
 detalle VARCHAR(500) NULL,
 inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 fin DATETIME NULL,
 activo TINYINT(1) NOT NULL DEFAULT 1,
 FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
 FOREIGN KEY(localidad_id) REFERENCES localidades(id) ON DELETE SET NULL,
 INDEX idx_estado_usuario_activo(usuario_id,activo),
 INDEX idx_estado_localidad_activo(localidad_id,activo),
 INDEX idx_estado_inicio(inicio)
);

CREATE TABLE IF NOT EXISTS gerente_localidades(
 gerente_id INT NOT NULL,
 localidad_id INT NOT NULL,
 PRIMARY KEY(gerente_id,localidad_id),
 FOREIGN KEY(gerente_id) REFERENCES usuarios(id) ON DELETE CASCADE,
 FOREIGN KEY(localidad_id) REFERENCES localidades(id) ON DELETE CASCADE,
 INDEX idx_gerente_localidad(localidad_id)
);
