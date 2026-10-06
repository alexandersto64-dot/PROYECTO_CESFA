-- ============================================================
-- Migración: módulo "Sesiones" del Dashboard del Profesor
-- (Unidad 01 > Semana 01..05 > Sem-0X > Curso, solo PDF)
--
-- 100% ADITIVA: no modifica ni borra ninguna tabla existente,
-- ni las creadas en migracion_materiales.sql.
--
-- Importar después de migracion_materiales.sql:
--   mysql -u root -p colegio_ie88044 < backend/config/migracion_sesiones.sql
-- ============================================================

USE colegio_ie88044;

-- ------------------------------------------------------------
-- PROFESOR_SESIONES_ARCHIVOS
-- Mismo patrón que profesor_unidad_archivos, para el módulo
-- "Sesiones": Unidad (por ahora solo 1) + Semana (1..5) + Curso.
-- Solo acepta PDF (ver backend/config/materiales_archivos.php,
-- SESIONES_EXTENSIONES_PERMITIDAS).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS profesor_sesiones_archivos (
  id_material INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_profesor INT UNSIGNED NOT NULL,
  unidad TINYINT UNSIGNED NOT NULL COMMENT 'Por ahora solo 1 (Unidad 01)',
  semana TINYINT UNSIGNED NOT NULL COMMENT '1 a 5 (Semana 01..05)',
  curso VARCHAR(30) NOT NULL COMMENT 'Clave fija del curso (ver backend/config/materiales_cursos.php)',
  nombre_archivo VARCHAR(255) NOT NULL COMMENT 'Nombre original del archivo',
  ruta_archivo VARCHAR(255) NOT NULL COMMENT 'Ruta relativa en backend/uploads/materiales/sesiones/',
  extension VARCHAR(10) NOT NULL,
  tamano_bytes INT UNSIGNED NOT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_sesion_archivo_profesor
    FOREIGN KEY (id_profesor) REFERENCES profesores(id_profesor)
    ON DELETE CASCADE,

  CONSTRAINT chk_sesion_archivo_semana CHECK (semana BETWEEN 1 AND 5),

  UNIQUE KEY uq_sesion_profesor_unidad_semana_curso (id_profesor, unidad, semana, curso)
) ENGINE=InnoDB;

CREATE INDEX idx_sesion_archivo_profesor ON profesor_sesiones_archivos (id_profesor);
