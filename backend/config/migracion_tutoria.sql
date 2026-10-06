-- ============================================================
-- Migración: Tutoría (SOLO Secundaria, asignación dinámica por
-- año escolar / período académico) — versión verificada contra la
-- BD REAL (colegio_ie88044.sql proporcionado por el cliente).
--
-- 100% ADITIVA: no modifica ni borra ninguna tabla existente. No
-- toca `asignaciones_docentes`, `profesor_curso_grado`,
-- `profesor_pca_archivos`, `profesor_unidad_archivos` ni
-- `profesor_sesion_archivos` — Tutoría queda completamente separada
-- de los cursos normales, tal como lo pide el ticket.
--
-- AJUSTADO A LA BD REAL (no al dump histórico que traía antes este
-- repositorio):
--   - Sin restricciones CHECK: la BD real de este proyecto no usa
--     CHECK en ninguna tabla de materiales (profesor_pca_archivos,
--     profesor_unidad_archivos, profesor_sesion_archivos); el rango
--     de unidad/semana se documenta solo con COMMENT, igual que
--     ellas, y se valida en PHP (tutoria_pca.php/tutoria_unidades.php/
--     tutoria_sesiones.php), igual que ya hacen pca.php/unidades.php/
--     sesiones.php con sus propias tablas.
--   - Con triggers de validación de NIVEL (BEFORE INSERT/UPDATE),
--     igual patrón que `asignaciones_docentes`, `profesor_curso_grado`
--     y `profesor_pca_archivos`/`profesor_unidad_archivos`/
--     `profesor_sesion_archivos` ya usan en esta BD: impiden que se
--     asigne Tutoría sobre un aula que no sea de nivel SECUNDARIA
--     (la regla "Tutoría SOLO Secundaria" queda también protegida a
--     nivel de base de datos, no solo en PHP/admin).
--
-- "Año escolar actual": se resuelve en PHP
-- (backend/config/profesor_tutoria.php::periodo_academico_actual())
-- usando el período de `periodos_academicos` cuyo nombre empieza con
-- "Año Académico" (el mismo período que ya usa `matriculas` en esta
-- BD real, id_periodo=1="Año Académico 2026"), no simplemente el
-- id_periodo más alto — porque en esta BD real `periodos_academicos`
-- también contiene los 4 bimestres del año (ids 2 a 5), que NO
-- representan un año escolar completo.
--
-- Importar sobre la BD real ya existente:
--   mysql -u root -p colegio_ie88044 < backend/config/migracion_tutoria.sql
-- ============================================================

USE colegio_ie88044;

-- ------------------------------------------------------------
-- PROFESOR_TUTORIA
-- Quién es tutor de qué aula (grados_secciones), en qué período
-- académico (año escolar). Un profesor puede tener 0, 1 o varias
-- filas a lo largo de los años, y puede no tener ninguna en el
-- período actual (Tutoría es opcional, nunca automática por nivel).
--
-- uq_tutoria_aula_periodo impide que dos profesores sean tutores de
-- la misma aula en el mismo año (un solo tutor por aula y período),
-- igual que confirma el Excel real de Secundaria (TUTORIA es 1 aula
-- por profesor, nunca compartida).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS profesor_tutoria (
  id_tutoria INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  id_profesor INT(10) UNSIGNED NOT NULL,
  id_grado_seccion INT(10) UNSIGNED NOT NULL,
  id_periodo INT(10) UNSIGNED NOT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_tutoria),
  UNIQUE KEY uq_tutoria_aula_periodo (id_grado_seccion, id_periodo),
  KEY idx_tutoria_profesor (id_profesor),
  KEY idx_tutoria_periodo (id_periodo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE profesor_tutoria
  ADD CONSTRAINT fk_tutoria_profesor
    FOREIGN KEY (id_profesor) REFERENCES profesores(id_profesor) ON DELETE CASCADE,
  ADD CONSTRAINT fk_tutoria_grado_seccion
    FOREIGN KEY (id_grado_seccion) REFERENCES grados_secciones(id_grado_seccion) ON DELETE CASCADE,
  ADD CONSTRAINT fk_tutoria_periodo
    FOREIGN KEY (id_periodo) REFERENCES periodos_academicos(id_periodo) ON DELETE CASCADE;

-- Validación de nivel (igual patrón que trg_asignaciones_valida_nivel_*
-- y trg_pcg_valida_nivel_* ya existentes en esta BD): la Tutoría solo
-- puede asignarse sobre un aula de SECUNDARIA. Si además el profesor
-- ya tiene nivel_educativo definido, también debe ser SECUNDARIA.
DELIMITER $$
CREATE TRIGGER `trg_tutoria_valida_nivel_bi` BEFORE INSERT ON `profesor_tutoria` FOR EACH ROW BEGIN
  DECLARE v_nivel_profesor ENUM('PRIMARIA','SECUNDARIA');
  DECLARE v_nivel_aula ENUM('PRIMARIA','SECUNDARIA');

  SELECT nivel_educativo INTO v_nivel_profesor FROM profesores WHERE id_profesor = NEW.id_profesor;
  SELECT nivel INTO v_nivel_aula FROM grados_secciones WHERE id_grado_seccion = NEW.id_grado_seccion;

  IF v_nivel_aula IS NULL OR v_nivel_aula <> 'SECUNDARIA' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Tutoría solo puede asignarse sobre un aula de SECUNDARIA';
  END IF;

  IF v_nivel_profesor IS NOT NULL AND v_nivel_profesor <> 'SECUNDARIA' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Tutoría solo puede asignarse a un profesor de SECUNDARIA';
  END IF;
END
$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER `trg_tutoria_valida_nivel_bu` BEFORE UPDATE ON `profesor_tutoria` FOR EACH ROW BEGIN
  DECLARE v_nivel_profesor ENUM('PRIMARIA','SECUNDARIA');
  DECLARE v_nivel_aula ENUM('PRIMARIA','SECUNDARIA');

  SELECT nivel_educativo INTO v_nivel_profesor FROM profesores WHERE id_profesor = NEW.id_profesor;
  SELECT nivel INTO v_nivel_aula FROM grados_secciones WHERE id_grado_seccion = NEW.id_grado_seccion;

  IF v_nivel_aula IS NULL OR v_nivel_aula <> 'SECUNDARIA' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Tutoría solo puede asignarse sobre un aula de SECUNDARIA';
  END IF;

  IF v_nivel_profesor IS NOT NULL AND v_nivel_profesor <> 'SECUNDARIA' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Tutoría solo puede asignarse a un profesor de SECUNDARIA';
  END IF;
END
$$
DELIMITER ;

-- ------------------------------------------------------------
-- PROFESOR_TUTORIA_PCA_ARCHIVOS
-- Un archivo por Profesor + Aula + Período + Unidad (U1..U10).
-- Igual que profesor_pca_archivos, pero para el aula/año de
-- Tutoría, no para un curso: contenido propio, nunca mezclado con
-- el PCA de las áreas normales.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS profesor_tutoria_pca_archivos (
  id_material INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  id_profesor INT(10) UNSIGNED NOT NULL,
  id_grado_seccion INT(10) UNSIGNED NOT NULL,
  id_periodo INT(10) UNSIGNED NOT NULL,
  unidad TINYINT(3) UNSIGNED NOT NULL COMMENT '1 a 10 (U1..U10)',
  nombre_archivo VARCHAR(255) NOT NULL COMMENT 'Nombre original del archivo',
  ruta_archivo VARCHAR(255) NOT NULL COMMENT 'Ruta relativa en backend/uploads/materiales/tutoria/pca/',
  extension VARCHAR(10) NOT NULL,
  tamano_bytes INT(10) UNSIGNED NOT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_material),
  UNIQUE KEY uq_tutoria_pca (id_profesor, id_grado_seccion, id_periodo, unidad),
  KEY idx_tutoria_pca_profesor (id_profesor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE profesor_tutoria_pca_archivos
  ADD CONSTRAINT fk_tutoria_pca_profesor
    FOREIGN KEY (id_profesor) REFERENCES profesores(id_profesor) ON DELETE CASCADE,
  ADD CONSTRAINT fk_tutoria_pca_grado_seccion
    FOREIGN KEY (id_grado_seccion) REFERENCES grados_secciones(id_grado_seccion) ON DELETE CASCADE,
  ADD CONSTRAINT fk_tutoria_pca_periodo
    FOREIGN KEY (id_periodo) REFERENCES periodos_academicos(id_periodo) ON DELETE CASCADE;

-- ------------------------------------------------------------
-- PROFESOR_TUTORIA_UNIDAD_ARCHIVOS
-- Un archivo por Profesor + Aula + Período + Unidad (1..10) +
-- Semana (1..5). Tutoría no tiene "cursos": una sola tarjeta por
-- Unidad > Sem-0X (a diferencia de Unidades de las áreas normales,
-- que sí listan un curso por tarjeta).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS profesor_tutoria_unidad_archivos (
  id_material INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  id_profesor INT(10) UNSIGNED NOT NULL,
  id_grado_seccion INT(10) UNSIGNED NOT NULL,
  id_periodo INT(10) UNSIGNED NOT NULL,
  unidad TINYINT(3) UNSIGNED NOT NULL COMMENT '1 a 10',
  semana TINYINT(3) UNSIGNED NOT NULL COMMENT '1 a 5 (Semana 01..05)',
  nombre_archivo VARCHAR(255) NOT NULL COMMENT 'Nombre original del archivo',
  ruta_archivo VARCHAR(255) NOT NULL COMMENT 'Ruta relativa en backend/uploads/materiales/tutoria/unidades/',
  extension VARCHAR(10) NOT NULL,
  tamano_bytes INT(10) UNSIGNED NOT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_material),
  UNIQUE KEY uq_tutoria_unidad (id_profesor, id_grado_seccion, id_periodo, unidad, semana),
  KEY idx_tutoria_unidad_profesor (id_profesor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE profesor_tutoria_unidad_archivos
  ADD CONSTRAINT fk_tutoria_unidad_profesor
    FOREIGN KEY (id_profesor) REFERENCES profesores(id_profesor) ON DELETE CASCADE,
  ADD CONSTRAINT fk_tutoria_unidad_grado_seccion
    FOREIGN KEY (id_grado_seccion) REFERENCES grados_secciones(id_grado_seccion) ON DELETE CASCADE,
  ADD CONSTRAINT fk_tutoria_unidad_periodo
    FOREIGN KEY (id_periodo) REFERENCES periodos_academicos(id_periodo) ON DELETE CASCADE;

-- ------------------------------------------------------------
-- PROFESOR_TUTORIA_SESION_ARCHIVOS
-- Misma estructura que profesor_tutoria_unidad_archivos, en tabla
-- aparte por ser un módulo distinto ("Sesiones" de Tutoría), igual
-- que ya ocurre entre profesor_unidad_archivos y
-- profesor_sesion_archivos para las áreas normales.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS profesor_tutoria_sesion_archivos (
  id_material INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  id_profesor INT(10) UNSIGNED NOT NULL,
  id_grado_seccion INT(10) UNSIGNED NOT NULL,
  id_periodo INT(10) UNSIGNED NOT NULL,
  unidad TINYINT(3) UNSIGNED NOT NULL COMMENT '1 a 10',
  semana TINYINT(3) UNSIGNED NOT NULL COMMENT '1 a 5 (Semana 01..05)',
  nombre_archivo VARCHAR(255) NOT NULL COMMENT 'Nombre original del archivo',
  ruta_archivo VARCHAR(255) NOT NULL COMMENT 'Ruta relativa en backend/uploads/materiales/tutoria/sesiones/',
  extension VARCHAR(10) NOT NULL,
  tamano_bytes INT(10) UNSIGNED NOT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_material),
  UNIQUE KEY uq_tutoria_sesion (id_profesor, id_grado_seccion, id_periodo, unidad, semana),
  KEY idx_tutoria_sesion_profesor (id_profesor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE profesor_tutoria_sesion_archivos
  ADD CONSTRAINT fk_tutoria_sesion_profesor
    FOREIGN KEY (id_profesor) REFERENCES profesores(id_profesor) ON DELETE CASCADE,
  ADD CONSTRAINT fk_tutoria_sesion_grado_seccion
    FOREIGN KEY (id_grado_seccion) REFERENCES grados_secciones(id_grado_seccion) ON DELETE CASCADE,
  ADD CONSTRAINT fk_tutoria_sesion_periodo
    FOREIGN KEY (id_periodo) REFERENCES periodos_academicos(id_periodo) ON DELETE CASCADE;
