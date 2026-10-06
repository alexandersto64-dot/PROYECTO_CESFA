-- ==========================================================
-- Quita la tabla del sistema "simple" de boletines PDF, que ya no se usa.
-- Solo borra `boletines_pdf`. NO toca boletas_importadas,
-- boletas_notas_extraidas, boletas_competencias_extraidas,
-- competencias ni notas_competencias (son las del módulo de
-- importación con análisis, que sigue en uso).
--
-- Ejecutar una sola vez (haz un respaldo antes):
--   mysql -u root colegio_ie88044 < eliminar_boletines_pdf.sql
-- ==========================================================

DROP TABLE IF EXISTS `boletines_pdf`;
