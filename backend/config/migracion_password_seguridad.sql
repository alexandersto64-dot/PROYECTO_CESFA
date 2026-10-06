-- ==========================================================
-- Migración: cambio de contraseña obligatorio en el primer ingreso
-- Ejecutar UNA sola vez en phpMyAdmin (como root), base colegio_ie88044.
-- El sistema funciona aunque no la ejecutes, pero sin ella las cuentas
-- de padres creadas en lote NO serán obligadas a cambiar su clave.
-- ==========================================================

ALTER TABLE usuarios
  ADD COLUMN debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 0
  AFTER password;

-- OPCIONAL (quita los dos "--" del inicio para ejecutarlo):
-- obliga a cambiar la clave a las cuentas familiares YA creadas.
-- UPDATE usuarios SET debe_cambiar_password = 1
--  WHERE correo LIKE '%@padres.ie88044.invalid';
