-- ============================================================
-- CESFA — FASE 2 (aditiva): programas, ciclos, módulos y matrícula por programa
-- ============================================================
-- Se importa DESPUÉS de importar en `cesfa` la estructura exportada
-- de la BD actual (ver LEEME-CESFA.md). NO modifica ni elimina
-- ninguna tabla/columna existente; solo crea tablas nuevas.
-- Es repetible (IF NOT EXISTS / INSERT IGNORE).
--
-- Requiere que existan `alumnos` (con id_alumno INT UNSIGNED).
--   mysql -u root -p cesfa < backend/config/fase2_programas_modulos.sql
--
-- No se inventó información académica: ciclos, módulos, horas y
-- duración quedan vacíos hasta que CESFA los confirme (se cargan
-- desde Administración → Programas y módulos).
-- ============================================================

CREATE TABLE IF NOT EXISTS programas (
  id_programa INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL UNIQUE,
  duracion VARCHAR(80) NULL COMMENT 'Texto libre (pendiente de confirmar con CESFA)',
  estado ENUM('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO',
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ciclos (
  id_ciclo INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_programa INT UNSIGNED NOT NULL,
  nombre VARCHAR(80) NOT NULL,
  orden INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_ciclo_programa (id_programa, nombre),
  CONSTRAINT fk_ciclos_programa FOREIGN KEY (id_programa) REFERENCES programas(id_programa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS modulos (
  id_modulo INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_ciclo INT UNSIGNED NOT NULL,
  nombre VARCHAR(150) NOT NULL,
  horas SMALLINT UNSIGNED NULL,
  orden INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_modulo_ciclo (id_ciclo, nombre),
  CONSTRAINT fk_modulos_ciclo FOREIGN KEY (id_ciclo) REFERENCES ciclos(id_ciclo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS matriculas_programa (
  id_matricula_programa INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_alumno INT UNSIGNED NOT NULL,
  id_programa INT UNSIGNED NOT NULL,
  id_ciclo INT UNSIGNED NULL,
  turno VARCHAR(30) NULL COMMENT 'Texto libre (pendiente de confirmar con CESFA)',
  estado ENUM('ACTIVA','RETIRADA','EGRESADA') NOT NULL DEFAULT 'ACTIVA',
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mp_alumno   FOREIGN KEY (id_alumno)   REFERENCES alumnos(id_alumno),
  CONSTRAINT fk_mp_programa FOREIGN KEY (id_programa) REFERENCES programas(id_programa),
  CONSTRAINT fk_mp_ciclo    FOREIGN KEY (id_ciclo)    REFERENCES ciclos(id_ciclo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Solo los nombres de las 6 carreras que ya figuran en la web pública.
INSERT IGNORE INTO programas (nombre) VALUES
  ('Cocina'),
  ('Computación e Informática'),
  ('Electrónica'),
  ('Estilismo'),
  ('Fabricación de Prendas de Vestir'),
  ('Secretariado Ejecutivo Computarizado');
