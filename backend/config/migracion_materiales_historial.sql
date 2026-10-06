-- ============================================================
-- Migración: historial de cambios de materiales (PCA/Unidades/Sesiones)
--
-- Motivo: hoy, al reemplazar o eliminar un archivo de PCA/Unidades/
-- Sesiones, la fila de profesor_pca_archivos / profesor_unidad_archivos
-- / profesor_sesion_archivos se sobrescribe o se borra, y el archivo
-- físico anterior se elimina del disco (ver
-- backend/config/materiales_archivos.php::materiales_eliminar_archivo_fisico).
-- No queda ningún rastro de "qué había antes y cuándo se cambió".
--
-- Esta migración agrega UNA sola tabla nueva, reutilizada por los 3
-- módulos (para no triplicar la misma estructura), que registra cada
-- REEMPLAZO y cada ELIMINACIÓN (y, para que el historial quede
-- completo desde la primera subida, también cada SUBIDA inicial).
--
-- IMPORTANTE — esto es un registro de auditoría, no un backup de
-- archivos: el archivo físico anterior YA se borra del disco al
-- reemplazar/eliminar (eso no cambia). Lo que se guarda aquí es el
-- nombre, tamaño, módulo, unidad/semana/curso y fecha del cambio,
-- para poder responder "¿qué había antes y cuándo lo cambiaron?" sin
-- tener que conservar cada versión del archivo en disco.
--
-- 100% ADITIVA: no modifica ni borra ninguna tabla existente.
--
--   mysql -u root -p colegio_ie88044 < backend/config/migracion_materiales_historial.sql
-- ============================================================

USE colegio_ie88044;

CREATE TABLE IF NOT EXISTS materiales_historial (
  id_historial INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  modulo ENUM('PCA', 'UNIDADES', 'SESIONES') NOT NULL,

  id_profesor INT UNSIGNED NOT NULL,
  id_nivel_grado INT UNSIGNED NOT NULL,

  unidad TINYINT UNSIGNED NOT NULL,
  semana TINYINT UNSIGNED NULL COMMENT 'NULL en PCA (no aplica)',
  curso VARCHAR(30) NULL COMMENT 'NULL en PCA (no aplica); clave fija del curso en Unidades/Sesiones',

  accion ENUM('SUBIDO', 'REEMPLAZADO', 'ELIMINADO') NOT NULL,

  -- Datos del archivo AL MOMENTO de esta acción (para SUBIDO y
  -- REEMPLAZADO es el archivo nuevo que quedó vigente; para
  -- ELIMINADO es el archivo que se acaba de borrar). El propio
  -- historial ya distingue la secuencia completa por profesor +
  -- grado + módulo + unidad/semana/curso, ordenada por fecha.
  nombre_archivo VARCHAR(255) NOT NULL,
  ruta_archivo VARCHAR(255) NOT NULL COMMENT 'Referencia informativa: el archivo físico ya no existe en disco tras un reemplazo/eliminación',
  extension VARCHAR(10) NOT NULL,
  tamano_bytes INT UNSIGNED NOT NULL,

  id_usuario_accion INT UNSIGNED NOT NULL COMMENT 'Quién hizo el cambio (normalmente el propio profesor de la sesión activa)',

  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_historial_profesor
    FOREIGN KEY (id_profesor) REFERENCES profesores(id_profesor)
    ON DELETE CASCADE,

  CONSTRAINT fk_historial_usuario
    FOREIGN KEY (id_usuario_accion) REFERENCES usuarios(id_usuario)
    ON DELETE CASCADE

) ENGINE=InnoDB;

CREATE INDEX idx_historial_consulta
  ON materiales_historial (id_profesor, id_nivel_grado, modulo, creado_en);
