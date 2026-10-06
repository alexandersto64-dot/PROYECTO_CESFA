-- ==========================================================
-- Calendario académico — fechas reales de inicio/cierre por
-- periodo (año o bimestre), para poder calcular "faltan X días
-- para cerrar el bimestre" en los dashboards.
--
-- 100% aditivo: dos columnas NULLABLE nuevas en una tabla que ya
-- existe. Los periodos que ya están registrados sin fecha simplemente
-- quedan con fecha_inicio/fecha_fin en NULL (no rompe nada de lo que
-- ya usa `periodos_academicos.nombre` como convención, ver
-- profesor_tutoria.php) hasta que Subdirección les ponga fecha desde
-- el formulario de "Periodos académicos".
--
-- Ejecutar UNA sola vez en phpMyAdmin sobre la BD real
-- `colegio_ie88044` (igual que las demás migraciones del proyecto).
-- ==========================================================

-- Nota: si tu versión de MySQL/MariaDB no soporta "IF NOT EXISTS"
-- en ADD COLUMN (versiones viejas de MySQL 5.7), quita esa parte;
-- si la migración ya se corrió antes, MySQL solo devolverá el error
-- "Duplicate column name", que se puede ignorar sin problema.

ALTER TABLE `periodos_academicos`
  ADD COLUMN `fecha_inicio` DATE NULL DEFAULT NULL AFTER `nombre`,
  ADD COLUMN `fecha_fin` DATE NULL DEFAULT NULL AFTER `fecha_inicio`;
