-- ==========================================================
-- Calendario Académico Institucional — eventos reales (exámenes,
-- reuniones, feriados, actividades) creados por Admin o Subdirección
-- y visibles para todo el colegio o para un nivel+grado puntual.
--
-- 100% aditivo: una tabla nueva, no toca ninguna tabla existente.
-- Las "entregas" de Trabajos NO se duplican acá: ese calendario las
-- lee en vivo de `trabajos.fecha_limite` (ver
-- backend/config/eventos_institucionales.php, función
-- eventos_trabajos_mes()), así que nunca pueden quedar
-- desincronizadas con lo que el profesor ya registró en "Trabajos".
--
-- Ejecutar UNA sola vez en phpMyAdmin sobre la BD real
-- `colegio_ie88044` (igual que las demás migraciones del proyecto).
-- ==========================================================

USE colegio_ie88044;

CREATE TABLE IF NOT EXISTS `eventos_institucionales` (
  `id_evento` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `titulo` VARCHAR(150) NOT NULL,
  `descripcion` TEXT NULL DEFAULT NULL,
  `tipo` ENUM('EXAMEN','REUNION','FERIADO','ACTIVIDAD','OTRO') NOT NULL DEFAULT 'OTRO',
  `fecha_inicio` DATE NOT NULL,
  `fecha_fin` DATE NULL DEFAULT NULL COMMENT 'NULL = evento de un solo día (igual a fecha_inicio)',
  `id_nivel_grado` INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = todo el colegio',
  `id_usuario` INT UNSIGNED NOT NULL COMMENT 'Quién lo creó (Admin o Subdirección)',
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_evento`),
  KEY `idx_eventos_fecha_inicio` (`fecha_inicio`),
  KEY `idx_eventos_nivel_grado` (`id_nivel_grado`),
  CONSTRAINT `fk_eventos_usuario`
    FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_eventos_nivel_grado`
    FOREIGN KEY (`id_nivel_grado`) REFERENCES `niveles_grados` (`id_nivel_grado`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
