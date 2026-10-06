-- ============================================================
-- Migración: módulo Alumnos / Matrículas
-- Solo agrega tablas nuevas. No modifica ni borra nada existente.
-- Importar después de database.sql:
--   mysql -u root -p colegio_ie88044 < backend/config/migracion_alumnos.sql
-- ============================================================

USE colegio_ie88044;

-- ------------------------------------------------------------
-- GRADOS Y SECCIONES (coincide con las páginas ya publicadas:
-- primaria/1ro-a.html ... 6to-c.html, secundaria/1ro-a.html ... 5to-c.html)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS grados_secciones (
  id_grado_seccion INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nivel ENUM('PRIMARIA', 'SECUNDARIA') NOT NULL,
  grado TINYINT UNSIGNED NOT NULL,
  seccion CHAR(1) NOT NULL,
  nombre VARCHAR(20) NOT NULL,

  UNIQUE KEY uq_grado_seccion (nivel, grado, seccion)
) ENGINE=InnoDB;

INSERT INTO grados_secciones (nivel, grado, seccion, nombre) VALUES
  ('PRIMARIA',1,'A','1ro A'),('PRIMARIA',1,'B','1ro B'),('PRIMARIA',1,'C','1ro C'),
  ('PRIMARIA',2,'A','2do A'),('PRIMARIA',2,'B','2do B'),('PRIMARIA',2,'C','2do C'),
  ('PRIMARIA',3,'A','3ro A'),('PRIMARIA',3,'B','3ro B'),('PRIMARIA',3,'C','3ro C'),
  ('PRIMARIA',4,'A','4to A'),('PRIMARIA',4,'B','4to B'),('PRIMARIA',4,'C','4to C'),
  ('PRIMARIA',5,'A','5to A'),('PRIMARIA',5,'B','5to B'),('PRIMARIA',5,'C','5to C'),
  ('PRIMARIA',6,'A','6to A'),('PRIMARIA',6,'B','6to B'),('PRIMARIA',6,'C','6to C'),
  ('SECUNDARIA',1,'A','1ro A'),('SECUNDARIA',1,'B','1ro B'),('SECUNDARIA',1,'C','1ro C'),
  ('SECUNDARIA',2,'A','2do A'),('SECUNDARIA',2,'B','2do B'),('SECUNDARIA',2,'C','2do C'),
  ('SECUNDARIA',3,'A','3ro A'),('SECUNDARIA',3,'B','3ro B'),('SECUNDARIA',3,'C','3ro C'),
  ('SECUNDARIA',4,'A','4to A'),('SECUNDARIA',4,'B','4to B'),('SECUNDARIA',4,'C','4to C'),
  ('SECUNDARIA',5,'A','5to A'),('SECUNDARIA',5,'B','5to B'),('SECUNDARIA',5,'C','5to C')
ON DUPLICATE KEY UPDATE nombre = nombre;

-- ------------------------------------------------------------
-- ALUMNOS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alumnos (
  id_alumno INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombres VARCHAR(100) NOT NULL,
  apellidos VARCHAR(100) NOT NULL,
  dni CHAR(8) NOT NULL UNIQUE,
  fecha_nacimiento DATE NULL,
  apoderado_nombre VARCHAR(150) NULL,
  apoderado_telefono VARCHAR(20) NULL,
  estado ENUM('ACTIVO', 'INACTIVO') NOT NULL DEFAULT 'ACTIVO',
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- ASIGNACIONES DOCENTES (qué grado/sección tiene a cargo
-- cada profesor, para que "Mis alumnos" muestre datos reales)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS asignaciones_docentes (
  id_asignacion INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_profesor INT UNSIGNED NOT NULL,
  id_grado_seccion INT UNSIGNED NOT NULL,

  CONSTRAINT fk_asignaciones_profesor
    FOREIGN KEY (id_profesor) REFERENCES profesores(id_profesor)
    ON DELETE CASCADE,
  CONSTRAINT fk_asignaciones_grado_seccion
    FOREIGN KEY (id_grado_seccion) REFERENCES grados_secciones(id_grado_seccion)
    ON DELETE CASCADE,

  UNIQUE KEY uq_profesor_grado_seccion (id_profesor, id_grado_seccion)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS matriculas (
  id_matricula INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_alumno INT UNSIGNED NOT NULL,
  id_grado_seccion INT UNSIGNED NOT NULL,
  id_periodo INT UNSIGNED NOT NULL,
  estado ENUM('ACTIVA', 'RETIRADO', 'TRASLADADO') NOT NULL DEFAULT 'ACTIVA',
  fecha_matricula DATE NOT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_matriculas_alumno
    FOREIGN KEY (id_alumno) REFERENCES alumnos(id_alumno)
    ON DELETE CASCADE,
  CONSTRAINT fk_matriculas_grado_seccion
    FOREIGN KEY (id_grado_seccion) REFERENCES grados_secciones(id_grado_seccion),
  CONSTRAINT fk_matriculas_periodo
    FOREIGN KEY (id_periodo) REFERENCES periodos_academicos(id_periodo),

  UNIQUE KEY uq_alumno_periodo (id_alumno, id_periodo)
) ENGINE=InnoDB;
