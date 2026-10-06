-- ==========================================================
-- Migración: intentos de login fallidos guardados en la BD
-- Ejecutar UNA sola vez en phpMyAdmin (como root), base colegio_ie88044.
-- Sin esta tabla el login usa archivos temporales (funciona igual).
-- ==========================================================

CREATE TABLE IF NOT EXISTS `login_intentos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `clave` CHAR(64) NOT NULL COMMENT 'SHA-256 de "id:<usuario>" o "ip:<ip>" (nunca el dato en claro)',
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_intentos_clave` (`clave`, `creado_en`),
  KEY `idx_login_intentos_fecha` (`creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
