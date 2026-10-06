-- ============================================================
-- Migración: estructura uniforme U1..U10 (PCA + Unidades + Sesiones)
-- para Primaria y Secundaria — versión verificada contra la BD REAL
-- (colegio_ie88044.sql proporcionado por el cliente).
--
-- HALLAZGO IMPORTANTE al comparar con la BD real:
-- `profesor_pca_archivos`, `profesor_unidad_archivos` y
-- `profesor_sesion_archivos` NO tienen ninguna restricción CHECK
-- sobre `unidad`/`semana` (a diferencia de lo que sugería el dump
-- histórico incluido antes en este mismo repositorio, que sí traía
-- CHECKs). En la BD real solo existen:
--   - PRIMARY KEY / UNIQUE KEY / índices normales, y
--   - triggers BEFORE INSERT/UPDATE que validan que el NIVEL
--     (PRIMARIA/SECUNDARIA) del profesor coincida con el del
--     curso/nivel_grado — nunca validan el rango numérico de
--     `unidad` ni `semana`.
--
-- Consecuencia: NO SE NECESITA NINGÚN CAMBIO ESTRUCTURAL para que
-- Secundaria pueda usar unidad = 9 y 10. El cambio real que habilita
-- U1..U10 en ambos niveles ya se hizo en PHP
-- (backend/config/materiales_cursos.php: materiales_total_unidades()
-- y pca_total_unidades() devuelven 10 para ambos niveles), y ese
-- cambio por sí solo es suficiente contra esta BD.
--
-- Este script queda solo como actualización COSMÉTICA de los
-- comentarios de columna (que en la BD real todavía dicen "1 a 8"),
-- para que la documentación de la BD no quede desactualizada. Es
-- 100% opcional y no cambia tipos, restricciones ni datos.
--
--   mysql -u root -p colegio_ie88044 < backend/config/migracion_pca_unidades_uniforme.sql
-- ============================================================

USE colegio_ie88044;

ALTER TABLE profesor_pca_archivos
  MODIFY unidad TINYINT(3) UNSIGNED NOT NULL COMMENT '1 a 10 (U1..U10)';

ALTER TABLE profesor_unidad_archivos
  MODIFY unidad TINYINT(3) UNSIGNED NOT NULL COMMENT '1 a 10 (U1..U10)';

ALTER TABLE profesor_sesion_archivos
  MODIFY unidad TINYINT(3) UNSIGNED NOT NULL COMMENT '1 a 10 (U1..U10)';

-- semana ya decía correctamente "1 a 5 (Semana 01..05)" en ambas
-- tablas — no requiere cambio.
