-- ============================================================
-- Migración: tabla `profesor_curso_grado`
-- (curso que cada profesor dicta en cada nivel+grado, Fase 2)
--
-- 100% ADITIVA: solo crea esta tabla. No modifica ni borra
-- ninguna tabla existente. Requiere que ya existan `profesores`,
-- `cursos` y `niveles_grados` (Fase 1).
--
-- Importar:
--   mysql -u root -p colegio_ie88044 < backend/config/migracion_profesor_curso_grado.sql
-- o desde phpMyAdmin: Importar -> seleccionar este archivo.
-- ============================================================

CREATE TABLE IF NOT EXISTS `profesor_curso_grado` (
  `id_profesor_curso_grado` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_profesor` int(10) UNSIGNED NOT NULL,
  `id_nivel_grado` int(10) UNSIGNED NOT NULL,
  `id_curso` int(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`id_profesor_curso_grado`),
  UNIQUE KEY `uq_profesor_grado_curso` (`id_profesor`,`id_nivel_grado`,`id_curso`),
  KEY `idx_pcg_profesor` (`id_profesor`),
  KEY `idx_pcg_grado` (`id_nivel_grado`),
  KEY `idx_pcg_curso` (`id_curso`),
  CONSTRAINT `fk_pcg_curso` FOREIGN KEY (`id_curso`) REFERENCES `cursos` (`id_curso`) ON DELETE CASCADE,
  CONSTRAINT `fk_pcg_grado` FOREIGN KEY (`id_nivel_grado`) REFERENCES `niveles_grados` (`id_nivel_grado`) ON DELETE CASCADE,
  CONSTRAINT `fk_pcg_profesor` FOREIGN KEY (`id_profesor`) REFERENCES `profesores` (`id_profesor`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos de ejemplo ya existentes en el dump validado
-- (profesor 1 -> Matemática en 1ro Primaria; profesor 2 -> Comunicación
-- en 1ro Primaria; profesor 3/Luis -> Ciencia y Tecnología en 1ro Secundaria).
-- Si tu BD ya tiene otros id_profesor/id_curso/id_nivel_grado distintos,
-- ajusta o quita este INSERT y asigna los cursos desde tu panel de Admin.
INSERT IGNORE INTO `profesor_curso_grado` (`id_profesor_curso_grado`, `id_profesor`, `id_nivel_grado`, `id_curso`) VALUES
(1, 1, 1, 1),
(2, 2, 1, 2),
(3, 3, 7, 11);
