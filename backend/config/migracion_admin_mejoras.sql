-- ==========================================================
-- Mejoras de Administración:
--   1) Unidades y Sesiones configurables por nivel (Admin > Config).
--   2) Registro de auditoría de acciones administrativas.
--   3) Papelera (soft-delete/restaurar) para Alumnos, Usuarios y Cursos.
--
-- Ejecutar UNA sola vez en phpMyAdmin sobre la BD real
-- `colegio_ie88044` (igual que las demás migraciones del proyecto).
-- ==========================================================

USE colegio_ie88044;

-- ----------------------------------------------------------
-- 1) Unidades/Sesiones configurables — reutiliza la tabla
-- `configuracion` (clave/valor) que ya existe. Si una fila ya
-- existiera (no debería) no se pisa: INSERT IGNORE.
-- ----------------------------------------------------------
INSERT IGNORE INTO `configuracion` (`clave`, `valor`) VALUES
    ('total_unidades_primaria', '10'),
    ('total_unidades_secundaria', '10'),
    ('total_semanas_primaria', '5'),
    ('total_semanas_secundaria', '5');

-- ----------------------------------------------------------
-- 2) Registro de auditoría — una fila por cada acción administrativa
-- relevante (crear/editar/eliminar/restaurar en Usuarios, Alumnos,
-- Cursos, Configuración). `detalle` es texto libre pensado para
-- lectura humana, no para reconstruir el dato (no reemplaza a un
-- backup real).
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `auditoria` (
  `id_auditoria` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_usuario` INT UNSIGNED NOT NULL COMMENT 'Quién hizo la acción',
  `accion` VARCHAR(40) NOT NULL COMMENT 'Ej: CREAR, EDITAR, ELIMINAR, RESTAURAR',
  `entidad` VARCHAR(40) NOT NULL COMMENT 'Ej: USUARIO, ALUMNO, CURSO, CONFIGURACION',
  `id_entidad` INT UNSIGNED NULL DEFAULT NULL,
  `detalle` VARCHAR(255) NOT NULL,
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_auditoria`),
  KEY `idx_auditoria_fecha` (`creado_en`),
  KEY `idx_auditoria_entidad` (`entidad`, `id_entidad`),
  CONSTRAINT `fk_auditoria_usuario`
    FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 3) Papelera — columnas de "borrado suave" en vez de DELETE directo.
-- `eliminado_en` NULL = activo/visible; con fecha = está en la
-- papelera (se sigue guardando en la tabla, solo se oculta de las
-- listas normales). Restaurar = volver a poner NULL.
--
-- Sin "IF NOT EXISTS" en el ADD COLUMN a propósito (MySQL 5.7, que
-- usan varias instalaciones locales de XAMPP, no lo soporta ahí). Si
-- ya corriste esta migración antes, comenta estas 3 sentencias antes
-- de volver a correr el archivo.
-- ----------------------------------------------------------
ALTER TABLE `usuarios`
  ADD COLUMN `eliminado_en` TIMESTAMP NULL DEFAULT NULL AFTER `creado_en`;

ALTER TABLE `alumnos`
  ADD COLUMN `eliminado_en` TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE `cursos`
  ADD COLUMN `eliminado_en` TIMESTAMP NULL DEFAULT NULL;
