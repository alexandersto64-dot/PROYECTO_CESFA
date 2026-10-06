-- ==========================================================
-- Mejoras de Subdirección:
--   1) Bitácora de observaciones por profesor (seguimiento
--      cualitativo, aparte de la aprobación puntual de un envío).
--   2) Confirmación de lectura de Comunicados (qué profesores ya
--      lo vieron).
--
-- El "Recordatorio directo a un profesor" y el "Semáforo de
-- cumplimiento" NO necesitan tabla nueva: el recordatorio reutiliza
-- la tabla `notificaciones` que ya existe (misma que usa todo el
-- sistema de avisos), y el semáforo es 100% calculado en vivo sobre
-- los datos que YA lee subdirector/reportes.php — nada que migrar
-- para esos dos.
--
-- Ejecutar UNA sola vez en phpMyAdmin sobre la BD real
-- `colegio_ie88044` (igual que las demás migraciones del proyecto).
-- ==========================================================

USE colegio_ie88044;

CREATE TABLE IF NOT EXISTS `observaciones_profesor` (
  `id_observacion` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_profesor` INT UNSIGNED NOT NULL,
  `id_usuario_autor` INT UNSIGNED NOT NULL COMMENT 'Quién la escribió (Subdirección)',
  `contenido` TEXT NOT NULL,
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_observacion`),
  KEY `idx_obs_profesor` (`id_profesor`),
  CONSTRAINT `fk_obs_profesor`
    FOREIGN KEY (`id_profesor`) REFERENCES `profesores` (`id_profesor`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_obs_autor`
    FOREIGN KEY (`id_usuario_autor`) REFERENCES `usuarios` (`id_usuario`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `comunicados_lecturas` (
  `id_comunicado` INT UNSIGNED NOT NULL,
  `id_usuario` INT UNSIGNED NOT NULL,
  `leido_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_comunicado`, `id_usuario`),
  CONSTRAINT `fk_lecturas_comunicado`
    FOREIGN KEY (`id_comunicado`) REFERENCES `comunicados` (`id_comunicado`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_lecturas_usuario`
    FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
