-- ============================================================
-- Migración: Importación de notas desde PDF + notas por competencia
-- (Etapa 2 del módulo "Importación y Publicación de Notas")
--
-- 100% ADITIVA excepto dos ALTER TABLE sobre `notas` (agregar
-- columnas NULL, no se borra ni renombra nada existente).
-- No modifica `padre/notas.php` ni ninguna tabla de otro módulo.
--
-- Decisiones confirmadas para esta etapa:
--   1. Las boletas reales tienen texto seleccionable -> extracción
--      directa (pdfparser). OCR queda fuera de esta etapa (ver
--      backend/config/pdf_extraccion.php).
--   2. El portal de padres debe mostrar notas POR COMPETENCIA además
--      de la nota final del curso -> se agregan `competencias` y
--      `notas_competencias`.
--   3. APROBAR y PUBLICAR quedan como dos momentos distintos (el
--      Subdirector puede aprobar hoy y programar la publicación) ->
--      `boletas_importadas.estado` separa APROBADA de PUBLICADA, con
--      `fecha_publicacion_programada`.
--
-- CORRECCIÓN tras revisar las 3 boletas reales (Primaria.docx,
-- Secundaria1ro.docx, Secundaria-2-5.docx, formato oficial MINEDU
-- "Informe de Progreso del Aprendizaje"): el supuesto inicial de "un
-- PDF = un bimestre, de un alumno o de un salón completo" era
-- incorrecto. El formato real es: un PDF = UN alumno, con sus 4
-- bimestres del año en la misma tabla (una fila por Área Curricular,
-- con una columna de calificativo por bimestre, más una fila por
-- cada Competencia dentro de esa área). Por eso `bimestre` se movió
-- de `boletas_importadas` a `boletas_notas_extraidas` (una fila por
-- área+bimestre, no una boleta por bimestre).
--
-- Importar en este orden (requiere que `notas`, `alumnos`, `cursos`,
-- `matriculas`, `periodos_academicos`, `profesor_curso_grado` ya
-- existan):
--   mysql -u root -p colegio_ie88044 < migracion_boletas_notas.sql
--   mysql -u root -p colegio_ie88044 < seed_competencias.sql
-- ============================================================

USE colegio_ie88044;

-- ------------------------------------------------------------
-- 1. COMPETENCIAS (catálogo por curso)
--
-- Cada curso tiene sus propias competencias (ej. Matemática:
-- "Resuelve problemas de cantidad", "Resuelve problemas de forma,
-- movimiento y localización", etc.). No se comparten entre cursos,
-- por eso `id_curso` es parte de la clave única junto con `nombre`.
-- `orden` es solo para mostrar las competencias siempre en el mismo
-- orden en la boleta y en el portal del padre.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS competencias (
  id_competencia INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_curso INT UNSIGNED NOT NULL,
  nombre VARCHAR(255) NOT NULL,
  orden TINYINT UNSIGNED NOT NULL DEFAULT 1,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_competencias_curso
    FOREIGN KEY (id_curso) REFERENCES cursos(id_curso)
    ON DELETE CASCADE,

  UNIQUE KEY uq_competencia_curso_nombre (id_curso, nombre)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2. NOTAS POR COMPETENCIA
--
-- Cuelga de `notas` (la nota final del curso), no repite
-- alumno/curso/periodo/bimestre: esos datos ya están en la fila
-- padre de `notas`. Igual que `notas`, guarda ambos formatos
-- (vigesimal/literal) y quién la registró/corrigió, para el mismo
-- criterio de trazabilidad.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notas_competencias (
  id_nota_competencia INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_nota INT UNSIGNED NOT NULL,
  id_competencia INT UNSIGNED NOT NULL,
  nota_vigesimal TINYINT UNSIGNED NULL,
  nota_literal ENUM('AD','A','B','C') NULL,
  id_usuario_registro INT UNSIGNED NOT NULL,
  id_usuario_modifico INT UNSIGNED NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_notas_competencias_nota
    FOREIGN KEY (id_nota) REFERENCES notas(id_nota)
    ON DELETE CASCADE,
  CONSTRAINT fk_notas_competencias_competencia
    FOREIGN KEY (id_competencia) REFERENCES competencias(id_competencia)
    ON DELETE CASCADE,
  CONSTRAINT fk_notas_competencias_usuario_registro
    FOREIGN KEY (id_usuario_registro) REFERENCES usuarios(id_usuario),
  CONSTRAINT fk_notas_competencias_usuario_modifico
    FOREIGN KEY (id_usuario_modifico) REFERENCES usuarios(id_usuario),

  UNIQUE KEY uq_nota_competencia (id_nota, id_competencia)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. BOLETAS IMPORTADAS (la importación de un PDF, como unidad)
--
-- estado tiene 8 valores (APROBADA y PUBLICADA separados, decisión
-- confirmada #3). `fecha_publicacion_programada` es NULL cuando el
-- Subdirector aprueba y publica en el mismo acto; si la llena, el
-- botón "Publicar" de subdirector/notas_aprobacion.php queda
-- disponible recién a partir de esa fecha/hora.
-- `hash_archivo` evita procesar el mismo PDF dos veces por
-- accidente (UNIQUE).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS boletas_importadas (
  id_boleta INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre_archivo_original VARCHAR(255) NOT NULL,
  ruta_archivo VARCHAR(255) NOT NULL,
  hash_archivo CHAR(64) NOT NULL,
  id_periodo INT UNSIGNED NOT NULL,
  id_alumno INT UNSIGNED NULL COMMENT 'Alumno indicado al subir, como referencia — el matching real es por DNI leído del PDF',
  estado ENUM(
    'PENDIENTE','PROCESANDO','EN_REVISION','REVISADA',
    'APROBADA','PUBLICADA','RECHAZADA','ERROR'
  ) NOT NULL DEFAULT 'PENDIENTE',
  mensaje_error TEXT NULL,
  motivo_rechazo TEXT NULL,
  fecha_publicacion_programada DATETIME NULL,
  id_usuario_subio INT UNSIGNED NOT NULL,
  id_usuario_aprobo INT UNSIGNED NULL,
  aprobado_en TIMESTAMP NULL,
  id_usuario_publico INT UNSIGNED NULL,
  publicado_en TIMESTAMP NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_boletas_periodo
    FOREIGN KEY (id_periodo) REFERENCES periodos_academicos(id_periodo),
  CONSTRAINT fk_boletas_alumno
    FOREIGN KEY (id_alumno) REFERENCES alumnos(id_alumno)
    ON DELETE SET NULL,
  CONSTRAINT fk_boletas_usuario_subio
    FOREIGN KEY (id_usuario_subio) REFERENCES usuarios(id_usuario),
  CONSTRAINT fk_boletas_usuario_aprobo
    FOREIGN KEY (id_usuario_aprobo) REFERENCES usuarios(id_usuario),
  CONSTRAINT fk_boletas_usuario_publico
    FOREIGN KEY (id_usuario_publico) REFERENCES usuarios(id_usuario),

  UNIQUE KEY uq_boleta_hash (hash_archivo)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. FILAS EXTRAÍDAS (una por cada alumno+curso leído del PDF)
--
-- Nunca toca `notas` hasta la publicación. `id_nota_generada` se
-- llena recién en ese momento. `revisado_por_profesor` es la marca
-- individual que, agregada, decide cuándo la boleta completa pasa a
-- REVISADA (todas sus filas sin ERROR revisadas).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS boletas_notas_extraidas (
  id_extraccion INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_boleta INT UNSIGNED NOT NULL,
  bimestre TINYINT UNSIGNED NOT NULL COMMENT 'La boleta real trae los 4 bimestres del alumno en una sola tabla por área; cada bimestre con nota es una fila propia',
  texto_alumno_extraido VARCHAR(200) NULL,
  dni_extraido CHAR(8) NULL,
  id_alumno_resuelto INT UNSIGNED NULL,
  texto_curso_extraido VARCHAR(150) NOT NULL COMMENT 'Nombre del ÁREA CURRICULAR tal como aparece en el PDF (ej. "Ciencias Sociales"), no el nombre corto de `cursos`',
  id_curso_resuelto INT UNSIGNED NULL,
  valor_final_extraido VARCHAR(10) NULL COMMENT 'Texto crudo leído del PDF (ej. "16" o "AD"); NULL en Primaria/1ro cuando el área no trae total por bimestre (solo Secundaria 2do-5to lo trae) — la fila igual existe para que sus competencias tengan dónde colgar',
  nota_vigesimal_final TINYINT UNSIGNED NULL,
  nota_literal_final ENUM('AD','A','B','C') NULL,
  estado_fila ENUM('OK','ADVERTENCIA','ERROR') NOT NULL DEFAULT 'OK',
  mensaje_advertencia TEXT NULL,
  revisado_por_profesor TINYINT(1) NOT NULL DEFAULT 0,
  id_nota_generada INT UNSIGNED NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_extraidas_boleta
    FOREIGN KEY (id_boleta) REFERENCES boletas_importadas(id_boleta)
    ON DELETE CASCADE,
  CONSTRAINT fk_extraidas_alumno
    FOREIGN KEY (id_alumno_resuelto) REFERENCES alumnos(id_alumno),
  CONSTRAINT fk_extraidas_curso
    FOREIGN KEY (id_curso_resuelto) REFERENCES cursos(id_curso),
  CONSTRAINT fk_extraidas_nota_generada
    FOREIGN KEY (id_nota_generada) REFERENCES notas(id_nota)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. COMPETENCIAS EXTRAÍDAS (hijas de una fila de la tabla anterior)
--
-- Una fila por cada competencia que el PDF trae dentro de un curso.
-- Igual que su padre, nunca toca `notas_competencias` hasta publicar.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS boletas_competencias_extraidas (
  id_extraccion_competencia INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_extraccion INT UNSIGNED NOT NULL,
  texto_competencia_extraido VARCHAR(255) NOT NULL,
  id_competencia_resuelto INT UNSIGNED NULL,
  valor_extraido VARCHAR(10) NOT NULL,
  nota_vigesimal TINYINT UNSIGNED NULL,
  nota_literal ENUM('AD','A','B','C') NULL,
  estado_fila ENUM('OK','ADVERTENCIA','ERROR') NOT NULL DEFAULT 'OK',
  mensaje_advertencia TEXT NULL,
  id_nota_competencia_generada INT UNSIGNED NULL,

  CONSTRAINT fk_comp_extraidas_extraccion
    FOREIGN KEY (id_extraccion) REFERENCES boletas_notas_extraidas(id_extraccion)
    ON DELETE CASCADE,
  CONSTRAINT fk_comp_extraidas_competencia
    FOREIGN KEY (id_competencia_resuelto) REFERENCES competencias(id_competencia),
  CONSTRAINT fk_comp_extraidas_generada
    FOREIGN KEY (id_nota_competencia_generada) REFERENCES notas_competencias(id_nota_competencia)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 6. CAMBIOS EN `notas` (agregar columnas NULL, no se rompe nada)
-- ------------------------------------------------------------
ALTER TABLE notas
  ADD COLUMN id_matricula INT UNSIGNED NULL AFTER id_alumno,
  ADD COLUMN id_importacion_nota INT UNSIGNED NULL AFTER id_usuario_modifico;

ALTER TABLE notas
  ADD CONSTRAINT fk_notas_matricula
    FOREIGN KEY (id_matricula) REFERENCES matriculas(id_matricula),
  ADD CONSTRAINT fk_notas_importacion
    FOREIGN KEY (id_importacion_nota) REFERENCES boletas_importadas(id_boleta);

-- ------------------------------------------------------------
-- 7. SEMILLA DE COMPETENCIAS
--
-- Ver seed_competencias.sql (generado a partir de las 3 boletas
-- reales) — nombres oficiales MINEDU/CNEB, uno por curso, en el
-- mismo orden en que aparecen impresos en la boleta. No inventa
-- nada: son los nombres tal como los trae el PDF real del colegio.
-- ------------------------------------------------------------
