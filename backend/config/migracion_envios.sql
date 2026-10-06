-- ============================================================
-- Migración: módulo de envío/revisión de trabajos institucionales
-- (PROFESOR envía → SUBDIRECTOR revisa/aprueba/pide cambios)
-- + Notificaciones genéricas.
--
-- 100% ADITIVA: no modifica ni borra ninguna tabla existente.
-- No toca `trabajos` (esa tabla sigue siendo "tareas para
-- alumnos" que ya usa profesor/dashboard.php).
--
-- Importar después de database.sql y migracion_alumnos.sql:
--   mysql -u root -p colegio_ie88044 < backend/config/migracion_envios.sql
-- ============================================================

USE colegio_ie88044;

-- ------------------------------------------------------------
-- ENVIOS_TRABAJO
-- Un "envío" = un documento/trabajo institucional que un
-- PROFESOR sube para que SUBDIRECCIÓN lo revise (ej. planificación
-- anual, informe, etc.). El estado siempre refleja la versión
-- vigente (la última fila de envios_trabajo_historial).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS envios_trabajo (
  id_envio INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_profesor INT UNSIGNED NOT NULL,
  titulo VARCHAR(180) NOT NULL,
  descripcion TEXT NULL,
  estado ENUM('ENVIADO', 'EN_REVISION', 'REQUIERE_CAMBIOS', 'CORREGIDO', 'APROBADO')
    NOT NULL DEFAULT 'ENVIADO',
  version_actual INT UNSIGNED NOT NULL DEFAULT 1,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_envios_trabajo_profesor
    FOREIGN KEY (id_profesor) REFERENCES profesores(id_profesor)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- ENVIOS_TRABAJO_HISTORIAL
-- Una fila por cada versión del archivo subido. Nunca se borran
-- versiones anteriores (se conserva el historial completo).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS envios_trabajo_historial (
  id_version INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_envio INT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL,
  nombre_archivo VARCHAR(255) NOT NULL COMMENT 'Nombre original del archivo',
  ruta_archivo VARCHAR(255) NOT NULL COMMENT 'Ruta relativa donde se guardó (backend/uploads/envios/...)',
  extension VARCHAR(10) NOT NULL,
  tamano_bytes INT UNSIGNED NOT NULL,
  estado ENUM('ENVIADO', 'EN_REVISION', 'REQUIERE_CAMBIOS', 'CORREGIDO', 'APROBADO')
    NOT NULL DEFAULT 'ENVIADO' COMMENT 'Estado de ESTA versión',
  observacion TEXT NULL COMMENT 'Observación del Subdirector (solo cuando REQUIERE_CAMBIOS)',
  id_revisor INT UNSIGNED NULL COMMENT 'Usuario (SUBDIRECTOR) que revisó esta versión',
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de subida de esta versión',
  revisado_en TIMESTAMP NULL COMMENT 'Fecha en que se aprobó/pidió cambios',

  CONSTRAINT fk_historial_envio
    FOREIGN KEY (id_envio) REFERENCES envios_trabajo(id_envio)
    ON DELETE CASCADE,
  CONSTRAINT fk_historial_revisor
    FOREIGN KEY (id_revisor) REFERENCES usuarios(id_usuario),

  UNIQUE KEY uq_envio_version (id_envio, version)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- NOTIFICACIONES
-- Genérica y reutilizable: cualquier módulo puede insertar una
-- notificación para un usuario (por ahora se usa para el flujo
-- Subdirector <-> Profesor).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notificaciones (
  id_notificacion INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_usuario INT UNSIGNED NOT NULL COMMENT 'Destinatario',
  tipo VARCHAR(40) NOT NULL COMMENT 'Ej: CORRECCION_SOLICITADA, TRABAJO_APROBADO, NUEVA_VERSION',
  mensaje VARCHAR(255) NOT NULL,
  url VARCHAR(255) NULL COMMENT 'Enlace relativo para "Ver trabajo"',
  leido TINYINT(1) NOT NULL DEFAULT 0,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_notificaciones_usuario
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_notificaciones_usuario_leido ON notificaciones (id_usuario, leido);
CREATE INDEX idx_envios_trabajo_estado ON envios_trabajo (estado);
CREATE INDEX idx_envios_trabajo_profesor ON envios_trabajo (id_profesor);
