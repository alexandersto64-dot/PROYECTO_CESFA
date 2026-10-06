-- ==========================================================
-- Migración: auditoría de seguridad (IP + eventos sin usuario)
-- Ejecutar UNA sola vez en phpMyAdmin (como root), base colegio_ie88044.
-- Amplía la tabla `auditoria` que ya existe. Sin esta migración el
-- registro sigue funcionando como antes (sin IP ni intentos fallidos).
-- ==========================================================

-- 1) Permitir eventos sin usuario (ej. intento de login con un usuario
--    que no existe) y que el historial NO se borre si se elimina al usuario.
ALTER TABLE `auditoria` DROP FOREIGN KEY `fk_auditoria_usuario`;

ALTER TABLE `auditoria`
  MODIFY `id_usuario` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Quién hizo la acción (NULL = visitante sin cuenta)',
  ADD COLUMN `ip` VARCHAR(45) NULL DEFAULT NULL AFTER `detalle`,
  ADD KEY `idx_auditoria_accion` (`accion`, `creado_en`),
  ADD KEY `idx_auditoria_usuario` (`id_usuario`, `creado_en`);

ALTER TABLE `auditoria`
  ADD CONSTRAINT `fk_auditoria_usuario`
    FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`)
    ON DELETE SET NULL;
