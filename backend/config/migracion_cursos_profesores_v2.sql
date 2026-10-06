-- ============================================================
-- MIGRACIÓN V2 — CURSOS + PROFESORES + NIVEL + GRADO
-- Base de datos: colegio_ie88044
-- MariaDB 10.4+
--
-- OBJETIVO:
--   Separar correctamente Primaria / Secundaria y evitar
--   cruces incorrectos entre profesores, grados y cursos.
--
-- NO ELIMINA tablas existentes.
-- NO elimina datos de alumnos, matrículas, trabajos, etc.
--
-- EJECUTAR DESPUÉS DEL DUMP ACTUAL.
-- ============================================================

USE colegio_ie88044;

SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET FOREIGN_KEY_CHECKS = 0;

START TRANSACTION;


-- ============================================================
-- 1. ASEGURAR NIVEL DE LOS PROFESORES EXISTENTES
-- ============================================================
--
-- En la BD actual:
--
-- profesor 1 -> asignado a Primaria 1ro A/B
-- profesor 2 -> asignado a Primaria 1ro A/B
-- profesor 3 -> asignado a Secundaria 1ro A/B
--
-- Por tanto:
--   profesor 1 = PRIMARIA
--   profesor 2 = PRIMARIA
--   profesor 3 = SECUNDARIA
--
-- ============================================================

UPDATE profesores
SET nivel_educativo = 'PRIMARIA'
WHERE id_profesor IN (
    SELECT DISTINCT ad.id_profesor
    FROM asignaciones_docentes ad
    INNER JOIN grados_secciones gs
        ON gs.id_grado_seccion = ad.id_grado_seccion
    WHERE gs.nivel = 'PRIMARIA'
)
AND nivel_educativo IS NULL;


UPDATE profesores
SET nivel_educativo = 'SECUNDARIA'
WHERE id_profesor IN (
    SELECT DISTINCT ad.id_profesor
    FROM asignaciones_docentes ad
    INNER JOIN grados_secciones gs
        ON gs.id_grado_seccion = ad.id_grado_seccion
    WHERE gs.nivel = 'SECUNDARIA'
)
AND nivel_educativo IS NULL;


-- ============================================================
-- 2. CORREGIR ROLES DE LOS PROFESORES
-- ============================================================
--
-- Los usuarios 3,4,5 actualmente tienen rol PROFESOR.
--
-- Se cambia según profesores.nivel_educativo.
--
-- NO se toca ADMIN ni SUBDIRECTOR.
-- ============================================================

UPDATE usuarios u
INNER JOIN profesores p
    ON p.id_usuario = u.id_usuario
SET u.id_rol = (
    SELECT r.id_rol
    FROM roles r
    WHERE r.nombre = 'PROFESOR_PRIMARIA'
)
WHERE p.nivel_educativo = 'PRIMARIA';


UPDATE usuarios u
INNER JOIN profesores p
    ON p.id_usuario = u.id_usuario
SET u.id_rol = (
    SELECT r.id_rol
    FROM roles r
    WHERE r.nombre = 'PROFESOR_SECUNDARIO'
)
WHERE p.nivel_educativo = 'SECUNDARIA';


-- ============================================================
-- 3. HACER OBLIGATORIO EL NIVEL DEL PROFESOR
-- ============================================================
--
-- Antes:
--   nivel_educativo podía ser NULL.
--
-- Después:
--   todo profesor debe pertenecer a un nivel.
--
-- ============================================================

ALTER TABLE profesores
MODIFY nivel_educativo
ENUM('PRIMARIA','SECUNDARIA')
NOT NULL;


-- ============================================================
-- 4. CORREGIR TABLA CURSOS
-- ============================================================
--
-- Los 8 cursos existentes se consideran cursos de PRIMARIA
-- porque son:
--
-- Matemática
-- Comunicación
-- Ciencia y Tecnología
-- Personal Social
-- Inglés
-- Educación Física
-- Arte y Cultura
-- Computación
--
-- Después se agregan los cursos propios de Secundaria.
--
-- ============================================================

UPDATE cursos
SET nivel = 'PRIMARIA'
WHERE nivel IS NULL;


ALTER TABLE cursos
MODIFY nivel
ENUM('PRIMARIA','SECUNDARIA')
NOT NULL;


-- ============================================================
-- 5. AGREGAR CÓDIGO A LOS CURSOS
-- ============================================================
--
-- Solo se crea si todavía no existe.
--
-- Si tu tabla ya tuviera codigo, esta parte puede producir
-- error al ejecutar. En el dump proporcionado NO existe,
-- por lo que es segura para esta BD.
-- ============================================================

ALTER TABLE cursos
ADD COLUMN codigo VARCHAR(30) DEFAULT NULL AFTER nivel;


-- ============================================================
-- 6. CÓDIGOS PARA CURSOS DE PRIMARIA
-- ============================================================

UPDATE cursos
SET codigo = CASE nombre
    WHEN 'Matemática' THEN 'PRI-MAT'
    WHEN 'Comunicación' THEN 'PRI-COM'
    WHEN 'Ciencia y Tecnología' THEN 'PRI-CYT'
    WHEN 'Personal Social' THEN 'PRI-PS'
    WHEN 'Inglés' THEN 'PRI-ING'
    WHEN 'Educación Física' THEN 'PRI-EF'
    WHEN 'Arte y Cultura' THEN 'PRI-AC'
    WHEN 'Computación' THEN 'PRI-COMP'
    ELSE codigo
END
WHERE nivel = 'PRIMARIA';


-- ============================================================
-- 7. CREAR CURSOS DE SECUNDARIA
-- ============================================================
--
-- Se utiliza INSERT IGNORE para no duplicarlos si el script
-- se ejecuta nuevamente.
--
-- ============================================================

INSERT IGNORE INTO cursos
    (nombre, nivel, codigo)
VALUES
    ('Matemática',              'SECUNDARIA', 'SEC-MAT'),
    ('Comunicación',            'SECUNDARIA', 'SEC-COM'),
    ('Ciencia y Tecnología',    'SECUNDARIA', 'SEC-CYT'),
    ('Ciencias Sociales',       'SECUNDARIA', 'SEC-CCSS'),
    ('DPCC',                    'SECUNDARIA', 'SEC-DPCC'),
    ('EPT',                     'SECUNDARIA', 'SEC-EPT'),
    ('Educación Física',        'SECUNDARIA', 'SEC-EF'),
    ('Arte y Cultura',          'SECUNDARIA', 'SEC-AC'),
    ('Educación Religiosa',     'SECUNDARIA', 'SEC-REL'),
    ('Inglés',                  'SECUNDARIA', 'SEC-ING');


-- ============================================================
-- 8. ÍNDICE ÚNICO NIVEL + NOMBRE
-- ============================================================
--
-- Permite:
--
--   Matemática + PRIMARIA
--   Matemática + SECUNDARIA
--
-- pero impide duplicar:
--
--   Matemática + PRIMARIA
--   Matemática + PRIMARIA
--
-- ============================================================

ALTER TABLE cursos
ADD UNIQUE KEY uq_curso_nivel_nombre (nivel, nombre);


-- ============================================================
-- 9. CREAR RELACIÓN PROFESOR + GRADO + CURSO
-- ============================================================
--
-- Esta es la tabla fundamental de la nueva estructura.
--
-- Ejemplo:
--
-- profesor 1
--   -> 1ro Primaria
--      -> Matemática
--
-- profesor 3
--   -> 1ro Secundaria
--      -> Ciencia y Tecnología
--
-- ============================================================

CREATE TABLE IF NOT EXISTS profesor_curso_grado (
    id_profesor_curso_grado INT UNSIGNED NOT NULL AUTO_INCREMENT,

    id_profesor INT UNSIGNED NOT NULL,
    id_nivel_grado INT UNSIGNED NOT NULL,
    id_curso INT UNSIGNED NOT NULL,

    PRIMARY KEY (id_profesor_curso_grado),

    UNIQUE KEY uq_profesor_grado_curso
        (id_profesor, id_nivel_grado, id_curso),

    KEY idx_pcg_profesor
        (id_profesor),

    KEY idx_pcg_grado
        (id_nivel_grado),

    KEY idx_pcg_curso
        (id_curso),

    CONSTRAINT fk_pcg_profesor
        FOREIGN KEY (id_profesor)
        REFERENCES profesores(id_profesor)
        ON DELETE CASCADE,

    CONSTRAINT fk_pcg_grado
        FOREIGN KEY (id_nivel_grado)
        REFERENCES niveles_grados(id_nivel_grado)
        ON DELETE CASCADE,

    CONSTRAINT fk_pcg_curso
        FOREIGN KEY (id_curso)
        REFERENCES cursos(id_curso)
        ON DELETE CASCADE

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 10. MIGRAR ASIGNACIONES ACTUALES
-- ============================================================
--
-- Se toman las asignaciones_docentes existentes.
--
-- Como actualmente:
--
-- profesor 1 -> 1ro Primaria
-- profesor 2 -> 1ro Primaria
-- profesor 3 -> 1ro Secundaria
--
-- se crea automáticamente la relación correspondiente
-- con su especialidad.
--
-- ============================================================

INSERT IGNORE INTO profesor_curso_grado
    (id_profesor, id_nivel_grado, id_curso)

SELECT DISTINCT
    p.id_profesor,
    ng.id_nivel_grado,
    c.id_curso

FROM profesores p

INNER JOIN asignaciones_docentes ad
    ON ad.id_profesor = p.id_profesor

INNER JOIN grados_secciones gs
    ON gs.id_grado_seccion = ad.id_grado_seccion

INNER JOIN niveles_grados ng
    ON ng.nivel = gs.nivel
    AND ng.grado = gs.grado

INNER JOIN cursos c
    ON c.nombre = p.especialidad
    AND c.nivel = p.nivel_educativo

WHERE p.nivel_educativo IS NOT NULL;


-- ============================================================
-- 11. MOSTRAR RELACIONES CREADAS
-- ============================================================

-- No es obligatorio para la migración, pero sirve para
-- comprobar inmediatamente qué relaciones se generaron.

SELECT
    p.id_profesor,
    CONCAT(u.nombres, ' ', u.apellidos) AS profesor,
    p.nivel_educativo,
    ng.nombre AS grado,
    c.nombre AS curso,
    c.nivel AS nivel_curso
FROM profesor_curso_grado pcg

INNER JOIN profesores p
    ON p.id_profesor = pcg.id_profesor

INNER JOIN usuarios u
    ON u.id_usuario = p.id_usuario

INNER JOIN niveles_grados ng
    ON ng.id_nivel_grado = pcg.id_nivel_grado

INNER JOIN cursos c
    ON c.id_curso = pcg.id_curso

ORDER BY
    p.nivel_educativo,
    ng.grado,
    profesor,
    c.nombre;


-- ============================================================
-- 12. PREPARAR profesor_unidad_archivos
-- ============================================================
--
-- Antes:
--
--   curso VARCHAR(30)
--
-- Después:
--
--   id_curso INT
--
-- Esto elimina cursos escritos manualmente.
--
-- ============================================================

ALTER TABLE profesor_unidad_archivos
ADD COLUMN id_curso INT UNSIGNED NULL AFTER semana;


-- ============================================================
-- 13. MIGRAR CURSOS EXISTENTES DE UNIDADES
-- ============================================================
--
-- Si ya existen archivos, se intenta determinar el curso
-- utilizando profesor + nivel.
--
-- Si no existen registros, simplemente no modifica nada.
--
-- ============================================================

UPDATE profesor_unidad_archivos u
INNER JOIN profesores p
    ON p.id_profesor = u.id_profesor

INNER JOIN cursos c
    ON c.nombre = u.curso
    AND c.nivel = p.nivel_educativo

SET u.id_curso = c.id_curso

WHERE u.id_curso IS NULL;


-- ============================================================
-- 14. PREPARAR profesor_sesion_archivos
-- ============================================================

ALTER TABLE profesor_sesion_archivos
ADD COLUMN id_curso INT UNSIGNED NULL AFTER semana;


-- ============================================================
-- 15. MIGRAR CURSOS EXISTENTES DE SESIONES
-- ============================================================

UPDATE profesor_sesion_archivos s
INNER JOIN profesores p
    ON p.id_profesor = s.id_profesor

INNER JOIN cursos c
    ON c.nombre = s.curso
    AND c.nivel = p.nivel_educativo

SET s.id_curso = c.id_curso

WHERE s.id_curso IS NULL;


-- ============================================================
-- 16. COMPROBACIÓN DE ARCHIVOS SIN CURSO
-- ============================================================
--
-- Si estas consultas devuelven filas, significa que existen
-- archivos antiguos cuyo curso no pudo determinarse.
--
-- NO se fuerza NOT NULL en esta migración para no destruir
-- información existente.
--
-- ============================================================

SELECT
    'UNIDADES SIN CURSO' AS problema,
    COUNT(*) AS cantidad
FROM profesor_unidad_archivos
WHERE id_curso IS NULL;

SELECT
    'SESIONES SIN CURSO' AS problema,
    COUNT(*) AS cantidad
FROM profesor_sesion_archivos
WHERE id_curso IS NULL;


-- ============================================================
-- 17. AGREGAR FOREIGN KEY A UNIDADES
-- ============================================================

ALTER TABLE profesor_unidad_archivos
ADD KEY idx_unidad_curso (id_curso);

ALTER TABLE profesor_unidad_archivos
ADD CONSTRAINT fk_unidad_archivo_curso
FOREIGN KEY (id_curso)
REFERENCES cursos(id_curso)
ON DELETE RESTRICT;


-- ============================================================
-- 18. AGREGAR FOREIGN KEY A SESIONES
-- ============================================================

ALTER TABLE profesor_sesion_archivos
ADD KEY idx_sesion_curso (id_curso);

ALTER TABLE profesor_sesion_archivos
ADD CONSTRAINT fk_sesion_archivo_curso
FOREIGN KEY (id_curso)
REFERENCES cursos(id_curso)
ON DELETE RESTRICT;


-- ============================================================
-- 19. VALIDACIÓN: PROFESOR + CURSO + GRADO
-- ============================================================
--
-- Impide insertar una relación incompatible.
--
-- Ejemplo rechazado:
--
-- profesor PRIMARIA
-- +
-- grado SECUNDARIA
--
-- También:
--
-- profesor PRIMARIA
-- +
-- curso SECUNDARIA
--
-- ============================================================

DELIMITER $$

DROP TRIGGER IF EXISTS trg_pcg_valida_nivel_bi$$

CREATE TRIGGER trg_pcg_valida_nivel_bi
BEFORE INSERT ON profesor_curso_grado
FOR EACH ROW
BEGIN

    DECLARE v_nivel_profesor ENUM('PRIMARIA','SECUNDARIA');
    DECLARE v_nivel_grado ENUM('PRIMARIA','SECUNDARIA');
    DECLARE v_nivel_curso ENUM('PRIMARIA','SECUNDARIA');

    SELECT nivel_educativo
    INTO v_nivel_profesor
    FROM profesores
    WHERE id_profesor = NEW.id_profesor;

    SELECT nivel
    INTO v_nivel_grado
    FROM niveles_grados
    WHERE id_nivel_grado = NEW.id_nivel_grado;

    SELECT nivel
    INTO v_nivel_curso
    FROM cursos
    WHERE id_curso = NEW.id_curso;

    IF v_nivel_profesor IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
        'El profesor no tiene nivel educativo definido';
    END IF;

    IF v_nivel_profesor <> v_nivel_grado THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
        'El profesor y el grado pertenecen a niveles diferentes';
    END IF;

    IF v_nivel_profesor <> v_nivel_curso THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
        'El profesor y el curso pertenecen a niveles diferentes';
    END IF;

END$$


DROP TRIGGER IF EXISTS trg_pcg_valida_nivel_bu$$

CREATE TRIGGER trg_pcg_valida_nivel_bu
BEFORE UPDATE ON profesor_curso_grado
FOR EACH ROW
BEGIN

    DECLARE v_nivel_profesor ENUM('PRIMARIA','SECUNDARIA');
    DECLARE v_nivel_grado ENUM('PRIMARIA','SECUNDARIA');
    DECLARE v_nivel_curso ENUM('PRIMARIA','SECUNDARIA');

    SELECT nivel_educativo
    INTO v_nivel_profesor
    FROM profesores
    WHERE id_profesor = NEW.id_profesor;

    SELECT nivel
    INTO v_nivel_grado
    FROM niveles_grados
    WHERE id_nivel_grado = NEW.id_nivel_grado;

    SELECT nivel
    INTO v_nivel_curso
    FROM cursos
    WHERE id_curso = NEW.id_curso;

    IF v_nivel_profesor <> v_nivel_grado THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
        'El profesor y el grado pertenecen a niveles diferentes';
    END IF;

    IF v_nivel_profesor <> v_nivel_curso THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
        'El profesor y el curso pertenecen a niveles diferentes';
    END IF;

END$$

DELIMITER ;


-- ============================================================
-- 20. VALIDAR NIVEL DE CURSO EN UNIDADES
-- ============================================================

DELIMITER $$

DROP TRIGGER IF EXISTS trg_unidad_valida_curso_nivel_bi$$

CREATE TRIGGER trg_unidad_valida_curso_nivel_bi
BEFORE INSERT ON profesor_unidad_archivos
FOR EACH ROW
BEGIN

    DECLARE v_nivel_profesor ENUM('PRIMARIA','SECUNDARIA');
    DECLARE v_nivel_curso ENUM('PRIMARIA','SECUNDARIA');

    SELECT nivel_educativo
    INTO v_nivel_profesor
    FROM profesores
    WHERE id_profesor = NEW.id_profesor;

    SELECT nivel
    INTO v_nivel_curso
    FROM cursos
    WHERE id_curso = NEW.id_curso;

    IF v_nivel_profesor <> v_nivel_curso THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
        'El curso de la unidad no pertenece al nivel del profesor';
    END IF;

END$$


DROP TRIGGER IF EXISTS trg_unidad_valida_curso_nivel_bu$$

CREATE TRIGGER trg_unidad_valida_curso_nivel_bu
BEFORE UPDATE ON profesor_unidad_archivos
FOR EACH ROW
BEGIN

    DECLARE v_nivel_profesor ENUM('PRIMARIA','SECUNDARIA');
    DECLARE v_nivel_curso ENUM('PRIMARIA','SECUNDARIA');

    SELECT nivel_educativo
    INTO v_nivel_profesor
    FROM profesores
    WHERE id_profesor = NEW.id_profesor;

    SELECT nivel
    INTO v_nivel_curso
    FROM cursos
    WHERE id_curso = NEW.id_curso;

    IF v_nivel_profesor <> v_nivel_curso THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
        'El curso de la unidad no pertenece al nivel del profesor';
    END IF;

END$$

DELIMITER ;


-- ============================================================
-- 21. VALIDAR NIVEL DE CURSO EN SESIONES
-- ============================================================

DELIMITER $$

DROP TRIGGER IF EXISTS trg_sesion_valida_curso_nivel_bi$$

CREATE TRIGGER trg_sesion_valida_curso_nivel_bi
BEFORE INSERT ON profesor_sesion_archivos
FOR EACH ROW
BEGIN

    DECLARE v_nivel_profesor ENUM('PRIMARIA','SECUNDARIA');
    DECLARE v_nivel_curso ENUM('PRIMARIA','SECUNDARIA');

    SELECT nivel_educativo
    INTO v_nivel_profesor
    FROM profesores
    WHERE id_profesor = NEW.id_profesor;

    SELECT nivel
    INTO v_nivel_curso
    FROM cursos
    WHERE id_curso = NEW.id_curso;

    IF v_nivel_profesor <> v_nivel_curso THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
        'El curso de la sesión no pertenece al nivel del profesor';
    END IF;

END$$


DROP TRIGGER IF EXISTS trg_sesion_valida_curso_nivel_bu$$

CREATE TRIGGER trg_sesion_valida_curso_nivel_bu
BEFORE UPDATE ON profesor_sesion_archivos
FOR EACH ROW
BEGIN

    DECLARE v_nivel_profesor ENUM('PRIMARIA','SECUNDARIA');
    DECLARE v_nivel_curso ENUM('PRIMARIA','SECUNDARIA');

    SELECT nivel_educativo
    INTO v_nivel_profesor
    FROM profesores
    WHERE id_profesor = NEW.id_profesor;

    SELECT nivel
    INTO v_nivel_curso
    FROM cursos
    WHERE id_curso = NEW.id_curso;

    IF v_nivel_profesor <> v_nivel_curso THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
        'El curso de la sesión no pertenece al nivel del profesor';
    END IF;

END$$

DELIMITER ;


-- ============================================================
-- 22. ÍNDICES PARA CONSULTAS DEL DASHBOARD
-- ============================================================

ALTER TABLE profesor_curso_grado
ADD KEY idx_pcg_profesor_grado
(id_profesor, id_nivel_grado);


-- ============================================================
-- 23. AUDITORÍA FINAL
-- ============================================================

SELECT
    'PROFESORES' AS tabla,
    COUNT(*) AS registros
FROM profesores;

SELECT
    'CURSOS' AS tabla,
    COUNT(*) AS registros
FROM cursos;

SELECT
    nivel,
    COUNT(*) AS cantidad
FROM cursos
GROUP BY nivel;

SELECT
    p.id_profesor,
    CONCAT(u.nombres, ' ', u.apellidos) AS profesor,
    p.nivel_educativo,
    r.nombre AS rol
FROM profesores p
INNER JOIN usuarios u
    ON u.id_usuario = p.id_usuario
INNER JOIN roles r
    ON r.id_rol = u.id_rol
ORDER BY p.id_profesor;


-- ============================================================
-- 24. AUDITORÍA DE INCOMPATIBILIDADES
-- ============================================================
--
-- Esta consulta debe devolver 0 filas.
--
-- ============================================================

SELECT
    pcg.id_profesor_curso_grado,
    pcg.id_profesor,
    p.nivel_educativo AS nivel_profesor,
    ng.nombre AS grado,
    ng.nivel AS nivel_grado,
    c.nombre AS curso,
    c.nivel AS nivel_curso

FROM profesor_curso_grado pcg

INNER JOIN profesores p
    ON p.id_profesor = pcg.id_profesor

INNER JOIN niveles_grados ng
    ON ng.id_nivel_grado = pcg.id_nivel_grado

INNER JOIN cursos c
    ON c.id_curso = pcg.id_curso

WHERE
    p.nivel_educativo <> ng.nivel
    OR
    p.nivel_educativo <> c.nivel;


-- ============================================================
-- FINAL
-- ============================================================

COMMIT;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FIN DE MIGRACIÓN
-- ============================================================