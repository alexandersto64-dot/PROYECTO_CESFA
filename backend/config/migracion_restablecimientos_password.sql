-- ============================================================
-- Migración: recuperación de contraseña por correo electrónico.
--
-- 100% ADITIVA: no modifica ni borra ninguna tabla existente,
-- no toca ninguna columna de `usuarios`. Solo agrega la tabla
-- necesaria para guardar el token de un solo uso (nunca la
-- contraseña ni el token en texto plano — se guarda su hash).
--
-- Importar después de database.sql:
--   mysql -u root -p colegio_ie88044 < backend/config/migracion_restablecimientos_password.sql
-- ============================================================

USE colegio_ie88044;

-- ------------------------------------------------------------
-- RESTABLECIMIENTOS_PASSWORD
-- Un registro = un enlace de "recuperar contraseña" enviado por
-- correo. `token_hash` es sha256() del token real que va en el
-- enlace (el token en texto plano nunca se guarda en BD, solo
-- viaja por correo — igual que `usuarios.password` nunca guarda
-- la contraseña en texto plano). `usado` evita que el mismo
-- enlace sirva dos veces; `expira_en` le da una vida corta
-- (1 hora, ver backend/config/correo.php).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS restablecimientos_password (
  id_restablecimiento INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_usuario INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en DATETIME NOT NULL,
  usado TINYINT(1) NOT NULL DEFAULT 0,

  CONSTRAINT fk_restablecimientos_usuario
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
    ON DELETE CASCADE,

  INDEX idx_restablecimientos_token (token_hash)
) ENGINE=InnoDB;
