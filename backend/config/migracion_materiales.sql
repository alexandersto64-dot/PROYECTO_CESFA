-- ============================================================
-- Migración: materiales académicos del Dashboard del Profesor
-- (PCA por unidad + Unidades > Semanas > Cursos)
--
-- 100% ADITIVA: no modifica ni borra ninguna tabla existente.
-- No toca `trabajos`, `documentos`, `envios_trabajo` ni ninguna
-- otra tabla ya usada por Admin/Subdirector/Profesor.
--
-- Importar después de database.sql, migracion_alumnos.sql y
-- migracion_envios.sql:
--   mysql -u root -p colegio_ie88044 < backend/config/migracion_materiales.sql
-- ============================================================

USE colegio_ie88044;

-- ------------------------------------------------------------
-- PROFESOR_PCA_ARCHIVOS
-- Un archivo por Profesor + Unidad (U1..U8) dentro de "PCA".
-- Al reemplazar, se actualiza la misma fila (no se acumulan
-- versiones): PCA es documentación vigente, no un historial.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS profesor_pca_archivos (
  id_material INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_profesor INT UNSIGNED NOT NULL,
  unidad TINYINT UNSIGNED NOT NULL COMMENT '1 a 8 (U1..U8)',
  nombre_archivo VARCHAR(255) NOT NULL COMMENT 'Nombre original del archivo',
  ruta_archivo VARCHAR(255) NOT NULL COMMENT 'Ruta relativa en backend/uploads/materiales/pca/',
  extension VARCHAR(10) NOT NULL,
  tamano_bytes INT UNSIGNED NOT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_pca_profesor
    FOREIGN KEY (id_profesor) REFERENCES profesores(id_profesor)
    ON DELETE CASCADE,

  CONSTRAINT chk_pca_unidad CHECK (unidad BETWEEN 1 AND 8),

  UNIQUE KEY uq_pca_profesor_unidad (id_profesor, unidad)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- PROFESOR_UNIDAD_ARCHIVOS
-- Un archivo por Profesor + Unidad (1..8) + Semana (1..5) +
-- Curso, dentro de "Unidades > Semana 0X > Sem-0X > Curso".
-- El campo `curso` guarda una clave fija (no id de la tabla
-- `cursos`, porque la lista de 11 cursos de Sem-01 fue definida
-- explícitamente y no necesariamente coincide con esa tabla).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS profesor_unidad_archivos (
  id_material INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_profesor INT UNSIGNED NOT NULL,
  unidad TINYINT UNSIGNED NOT NULL COMMENT '1 a 8',
  semana TINYINT UNSIGNED NOT NULL COMMENT '1 a 5 (Semana 01..05)',
  curso VARCHAR(30) NOT NULL COMMENT 'Clave fija del curso (ver profesor/config_cursos.php)',
  nombre_archivo VARCHAR(255) NOT NULL COMMENT 'Nombre original del archivo',
  ruta_archivo VARCHAR(255) NOT NULL COMMENT 'Ruta relativa en backend/uploads/materiales/unidades/',
  extension VARCHAR(10) NOT NULL,
  tamano_bytes INT UNSIGNED NOT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_unidad_archivo_profesor
    FOREIGN KEY (id_profesor) REFERENCES profesores(id_profesor)
    ON DELETE CASCADE,

  CONSTRAINT chk_unidad_archivo_unidad CHECK (unidad BETWEEN 1 AND 8),
  CONSTRAINT chk_unidad_archivo_semana CHECK (semana BETWEEN 1 AND 5),

  UNIQUE KEY uq_unidad_profesor_unidad_semana_curso (id_profesor, unidad, semana, curso)
) ENGINE=InnoDB;

CREATE INDEX idx_pca_profesor ON profesor_pca_archivos (id_profesor);
CREATE INDEX idx_unidad_archivo_profesor ON profesor_unidad_archivos (id_profesor);

-- ------------------------------------------------------------
-- PROFESOR_SESION_ARCHIVOS
-- Misma estructura que profesor_unidad_archivos (Unidad 1..8 >
-- Semana 1..5 > Sem-0X > Curso), pero en tabla aparte porque es
-- un módulo distinto ("Sesiones") con su propio contenido.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS profesor_sesion_archivos (
  id_material INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_profesor INT UNSIGNED NOT NULL,
  unidad TINYINT UNSIGNED NOT NULL COMMENT '1 a 8',
  semana TINYINT UNSIGNED NOT NULL COMMENT '1 a 5 (Semana 01..05)',
  curso VARCHAR(30) NOT NULL COMMENT 'Clave fija del curso (ver profesor/config_cursos.php)',
  nombre_archivo VARCHAR(255) NOT NULL COMMENT 'Nombre original del archivo',
  ruta_archivo VARCHAR(255) NOT NULL COMMENT 'Ruta relativa en backend/uploads/materiales/sesiones/',
  extension VARCHAR(10) NOT NULL,
  tamano_bytes INT UNSIGNED NOT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_sesion_archivo_profesor
    FOREIGN KEY (id_profesor) REFERENCES profesores(id_profesor)
    ON DELETE CASCADE,

  CONSTRAINT chk_sesion_archivo_unidad CHECK (unidad BETWEEN 1 AND 8),
  CONSTRAINT chk_sesion_archivo_semana CHECK (semana BETWEEN 1 AND 5),

  UNIQUE KEY uq_sesion_profesor_unidad_semana_curso (id_profesor, unidad, semana, curso)
) ENGINE=InnoDB;

CREATE INDEX idx_sesion_archivo_profesor ON profesor_sesion_archivos (id_profesor);
