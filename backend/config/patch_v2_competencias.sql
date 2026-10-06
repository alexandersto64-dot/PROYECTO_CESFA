-- ============================================================
-- PARCHE — solo para bases de datos que YA ejecutaron
-- migracion_boletas_notas.sql + seed_competencias.sql en su versión
-- anterior (si vas a instalar todo desde cero, IGNORA este archivo:
-- migracion_boletas_notas.sql y seed_competencias.sql ya vienen
-- corregidos y no necesitas este parche).
--
-- Corrige dos errores encontrados al revisar un dump real:
--
--   1. `competencias.nombre` era VARCHAR(150), pero la competencia
--      de Educación Religiosa mide 185 caracteres — se guardó
--      cortada ("...abierto al d"). Se amplía a VARCHAR(255) y se
--      corrigen las 2 filas ya truncadas (Primaria y Secundaria).
--
--   2. seed_competencias.sql usaba nombres de curso viejos ("CC.SS",
--      "Arte", "Religión") que no existen en tu tabla `cursos` real
--      (que tiene "Ciencias Sociales", "Arte y Cultura", "Educación
--      Religiosa") — por eso esos 3 cursos de Secundaria se quedaron
--      sin ninguna competencia sembrada, en silencio.
--
-- Orden de ejecución:
--   mysql -u root -p colegio_ie88044 < patch_v2_competencias.sql
--   mysql -u root -p colegio_ie88044 < seed_competencias.sql   (la versión nueva, ya corregida)
-- ============================================================

USE colegio_ie88044;

-- 1. Ampliar columnas que se quedaban cortas con textos oficiales largos
ALTER TABLE competencias
  MODIFY COLUMN nombre VARCHAR(255) NOT NULL;

ALTER TABLE boletas_competencias_extraidas
  MODIFY COLUMN texto_competencia_extraido VARCHAR(255) NOT NULL;

-- 1b. Solo Secundaria 2do-5to trae un total de área POR BIMESTRE
-- ("CALIFICATIVO DE AREA"); Primaria y Secundaria 1ro no lo traen
-- (confirmado contra los 3 formatos reales) — para esos dos, la fila
-- de notas_extraidas se crea igual (para que sus competencias tengan
-- dónde colgar), con el total en NULL. Por eso esta columna deja de
-- ser NOT NULL:
ALTER TABLE boletas_notas_extraidas
  MODIFY COLUMN valor_final_extraido VARCHAR(10) NULL;

-- 2. Reparar las filas que ya quedaron truncadas por el límite anterior
UPDATE competencias
SET nombre = 'Construye su identidad como persona humana, amada por Dios, digna, libre y trascendente, comprendiendo la doctrina de su propia religión, abierto al dialogo con las que le son cercanas.'
WHERE nombre LIKE 'Construye su identidad como persona humana%';

-- 3. Las competencias de Ciencias Sociales / Arte y Cultura / Educación
-- Religiosa (Secundaria) nunca se insertaron porque el nombre de curso
-- no coincidía. Corre seed_competencias.sql (versión corregida) justo
-- después de este parche — es seguro volver a correrlo completo: la
-- llave única (id_curso, nombre) hace que las filas que ya están bien
-- se ignoren (INSERT IGNORE) y solo entren las que faltaban.
