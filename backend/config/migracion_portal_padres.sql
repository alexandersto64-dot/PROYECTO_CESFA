-- ============================================================
-- Migración: Portal de Padres + Auxiliar + Notas + Comportamiento
--            + preinscripción de matrícula
-- Solo agrega tablas y roles nuevos. No modifica ni borra nada
-- existente (alumnos, matriculas, profesores, cursos, etc. quedan
-- exactamente igual).
--
-- v2: ids de rol corregidos. La primera versión asumía 4 y 5
-- libres (según el schema revisado), pero la BD real ya los tiene
-- ocupados por PROFESOR_PRIMARIA(4) y PROFESOR_SECUNDARIO(5),
-- agregados antes por migracion_niveles.sql — de ahí el error
-- #1062 al importar. PADRE y AUXILIAR pasan a 6 y 7, que es
-- justo el siguiente AUTO_INCREMENT libre de `roles` en la BD real.
--
-- Importar después de colegio_ie88044.sql (y de migracion_alumnos.sql
-- / migracion_niveles.sql, ya aplicadas):
--   mysql -u root -p colegio_ie88044 < migracion_portal_padres.sql
-- ============================================================

USE colegio_ie88044;

-- ------------------------------------------------------------
-- ROLES NUEVOS
-- La tabla `roles` ya tiene ocupados 1 (ADMIN), 2 (SUBDIRECTOR),
-- 3 (PROFESOR), 4 (PROFESOR_PRIMARIA) y 5 (PROFESOR_SECUNDARIO).
-- PADRE y AUXILIAR usan 6 y 7. Si la migración se corre dos veces,
-- la comprobación NOT EXISTS evita duplicarlos (la tabla roles no
-- tiene UNIQUE en `nombre`, así que se valida a mano).
-- ------------------------------------------------------------
INSERT INTO roles (id_rol, nombre)
SELECT 6, 'PADRE' WHERE NOT EXISTS (SELECT 1 FROM roles WHERE nombre = 'PADRE');

INSERT INTO roles (id_rol, nombre)
SELECT 7, 'AUXILIAR' WHERE NOT EXISTS (SELECT 1 FROM roles WHERE nombre = 'AUXILIAR');

-- ------------------------------------------------------------
-- PADRES ↔ ALUMNOS
-- Relación muchos-a-muchos: un padre puede tener varios hijos
-- matriculados, y un alumno puede tener más de un apoderado con
-- cuenta propia (ej. papá y mamá). No se crea una tabla "padres"
-- aparte (como si existe "profesores" para PROFESOR) porque no hay
-- ningún dato adicional que guardar del padre más allá de lo que ya
-- tiene `usuarios` (nombres, apellidos, email, etc.) — mismo criterio
-- que ya se usa para SUBDIRECTOR y ADMIN, que tampoco tienen tabla propia.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS padres_alumnos (
  id_padre_alumno INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_usuario_padre INT UNSIGNED NOT NULL,
  id_alumno INT UNSIGNED NOT NULL,

  CONSTRAINT fk_padres_alumnos_usuario
    FOREIGN KEY (id_usuario_padre) REFERENCES usuarios(id_usuario)
    ON DELETE CASCADE,
  CONSTRAINT fk_padres_alumnos_alumno
    FOREIGN KEY (id_alumno) REFERENCES alumnos(id_alumno)
    ON DELETE CASCADE,

  UNIQUE KEY uq_padre_alumno (id_usuario_padre, id_alumno)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- NOTAS
-- Por alumno + curso + periodo + bimestre. Se guardan los dos
-- formatos posibles (vigesimal para Secundaria, literal para
-- Primaria) en columnas separadas -solo una de las dos se llena,
-- según el nivel del alumno (mismo dato que ya usa
-- nivel_grado_label() vía su matrícula → grados_secciones.nivel)-
-- para no forzar todo a un solo tipo de dato.
--
-- id_usuario_registro: quién la registró originalmente (Profesor,
-- Auxiliar o Subdirección, según lo conversado). actualizado_en +
-- id_usuario_modifico: para cuando Admin corrige una nota ya puesta
-- -además de la traza que ya deja `auditoria` (reutilizada, no se
-- crea ninguna tabla de auditoría nueva)-.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notas (
  id_nota INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_alumno INT UNSIGNED NOT NULL,
  id_curso INT UNSIGNED NOT NULL,
  id_periodo INT UNSIGNED NOT NULL,
  bimestre TINYINT UNSIGNED NOT NULL,
  nota_vigesimal TINYINT UNSIGNED NULL,
  nota_literal ENUM('AD','A','B','C') NULL,
  id_usuario_registro INT UNSIGNED NOT NULL,
  id_usuario_modifico INT UNSIGNED NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_notas_alumno
    FOREIGN KEY (id_alumno) REFERENCES alumnos(id_alumno)
    ON DELETE CASCADE,
  CONSTRAINT fk_notas_curso
    FOREIGN KEY (id_curso) REFERENCES cursos(id_curso)
    ON DELETE CASCADE,
  CONSTRAINT fk_notas_periodo
    FOREIGN KEY (id_periodo) REFERENCES periodos_academicos(id_periodo)
    ON DELETE CASCADE,
  CONSTRAINT fk_notas_usuario_registro
    FOREIGN KEY (id_usuario_registro) REFERENCES usuarios(id_usuario),
  CONSTRAINT fk_notas_usuario_modifico
    FOREIGN KEY (id_usuario_modifico) REFERENCES usuarios(id_usuario),

  UNIQUE KEY uq_nota_alumno_curso_periodo_bimestre (id_alumno, id_curso, id_periodo, bimestre)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- COMPORTAMIENTO
-- Por alumno + periodo. El Auxiliar aplica a TODO el alumnado, sin
-- importar nivel/grado (confirmado), así que no necesita ninguna
-- tabla de asignación como sí la tiene el Profesor
-- (asignaciones_docentes) — cualquier usuario con rol AUXILIAR,
-- PROFESOR o SUBDIRECTOR puede registrar, y ADMIN puede corregir.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS comportamiento (
  id_comportamiento INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_alumno INT UNSIGNED NOT NULL,
  id_periodo INT UNSIGNED NOT NULL,
  tipo ENUM('POSITIVO','NEGATIVO','NEUTRO') NOT NULL,
  descripcion TEXT NOT NULL,
  id_usuario_registro INT UNSIGNED NOT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_comportamiento_alumno
    FOREIGN KEY (id_alumno) REFERENCES alumnos(id_alumno)
    ON DELETE CASCADE,
  CONSTRAINT fk_comportamiento_periodo
    FOREIGN KEY (id_periodo) REFERENCES periodos_academicos(id_periodo)
    ON DELETE CASCADE,
  CONSTRAINT fk_comportamiento_usuario_registro
    FOREIGN KEY (id_usuario_registro) REFERENCES usuarios(id_usuario)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SOLICITUDES DE MATRÍCULA (preinscripción iniciada por el padre)
-- No reutiliza la tabla genérica `solicitudes` (tipo/descripcion en
-- texto libre) porque acá se necesitan campos estructurados del
-- alumno y del grado deseado -los mismos que ya pide
-- admin/matriculas.php al crear una matrícula real-. Al aprobar,
-- Admin crea la fila en `matriculas` a mano con esos datos (igual
-- que hoy), y esta tabla queda como el registro del trámite.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS solicitudes_matricula (
  id_solicitud_matricula INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_usuario_padre INT UNSIGNED NOT NULL,
  alumno_nombres VARCHAR(100) NOT NULL,
  alumno_apellidos VARCHAR(100) NOT NULL,
  alumno_dni CHAR(8) NOT NULL,
  alumno_fecha_nacimiento DATE NULL,
  id_grado_seccion_deseado INT UNSIGNED NOT NULL,
  id_periodo INT UNSIGNED NOT NULL,
  estado ENUM('PENDIENTE','APROBADA','RECHAZADA') NOT NULL DEFAULT 'PENDIENTE',
  observacion_admin TEXT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_solicitud_matricula_padre
    FOREIGN KEY (id_usuario_padre) REFERENCES usuarios(id_usuario)
    ON DELETE CASCADE,
  CONSTRAINT fk_solicitud_matricula_grado_seccion
    FOREIGN KEY (id_grado_seccion_deseado) REFERENCES grados_secciones(id_grado_seccion),
  CONSTRAINT fk_solicitud_matricula_periodo
    FOREIGN KEY (id_periodo) REFERENCES periodos_academicos(id_periodo)
) ENGINE=InnoDB;
