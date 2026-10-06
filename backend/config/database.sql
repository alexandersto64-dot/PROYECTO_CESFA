-- ============================================================
-- I.E.P. 88044 Abraham Valdelomar — Base de datos del Intranet
-- ============================================================
-- Contiene exactamente las tablas y columnas que ya usa el
-- código PHP existente (backend/auth/login.php, admin/dashboard.php,
-- subdirector/dashboard.php, profesor/dashboard.php). No se
-- inventaron datos: solo la estructura necesaria para que el
-- sistema funcione. Los datos de ejemplo (usuarios, cursos, etc.)
-- están comentados al final para no cargar información ficticia
-- por accidente.
--
-- Uso:
--   1. Crear la base de datos (coincide con backend/config/database.php):
--        CREATE DATABASE colegio_ie88044 CHARACTER SET utf8mb4;
--   2. Importar este archivo:
--        mysql -u root -p colegio_ie88044 < backend/config/database.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS colegio_ie88044
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE colegio_ie88044;

-- ------------------------------------------------------------
-- ROLES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
  id_rol INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(30) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- Los 3 roles que ya reconoce backend/auth/login.php
-- (ver el switch() al final de ese archivo).
INSERT INTO roles (nombre) VALUES
  ('ADMIN'),
  ('SUBDIRECTOR'),
  ('PROFESOR')
ON DUPLICATE KEY UPDATE nombre = nombre;

-- ------------------------------------------------------------
-- USUARIOS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
  id_usuario INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombres VARCHAR(100) NOT NULL,
  apellidos VARCHAR(100) NOT NULL,
  correo VARCHAR(150) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL COMMENT 'Hash generado con password_hash() — nunca texto plano',
  estado ENUM('ACTIVO', 'INACTIVO') NOT NULL DEFAULT 'ACTIVO',
  id_rol INT UNSIGNED NOT NULL,
  ultimo_acceso DATETIME NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_usuarios_rol
    FOREIGN KEY (id_rol) REFERENCES roles(id_rol)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- PROFESORES (perfil adicional para usuarios con rol PROFESOR)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS profesores (
  id_profesor INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_usuario INT UNSIGNED NOT NULL UNIQUE,
  especialidad VARCHAR(150) NOT NULL,

  CONSTRAINT fk_profesores_usuario
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- CURSOS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cursos (
  id_curso INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TIPOS DE TRABAJO (tarea, examen, proyecto, etc.)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tipos_trabajo (
  id_tipo_trabajo INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- PERIODOS ACADÉMICOS (bimestre, trimestre, etc.)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS periodos_academicos (
  id_periodo INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TRABAJOS (usada por profesor/dashboard.php → "Mis trabajos")
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trabajos (
  id_trabajo INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(150) NOT NULL,
  descripcion TEXT NULL,
  fecha_limite DATE NOT NULL,
  estado VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE',
  id_curso INT UNSIGNED NOT NULL,
  id_tipo_trabajo INT UNSIGNED NOT NULL,
  id_periodo INT UNSIGNED NOT NULL,
  id_profesor INT UNSIGNED NOT NULL,

  CONSTRAINT fk_trabajos_curso
    FOREIGN KEY (id_curso) REFERENCES cursos(id_curso),
  CONSTRAINT fk_trabajos_tipo
    FOREIGN KEY (id_tipo_trabajo) REFERENCES tipos_trabajo(id_tipo_trabajo),
  CONSTRAINT fk_trabajos_periodo
    FOREIGN KEY (id_periodo) REFERENCES periodos_academicos(id_periodo),
  CONSTRAINT fk_trabajos_profesor
    FOREIGN KEY (id_profesor) REFERENCES profesores(id_profesor)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- DOCUMENTOS INSTITUCIONALES (admin/documentos.php,
-- subdirector/documentos.php)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documentos (
  id_documento INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(180) NOT NULL,
  categoria VARCHAR(80) NOT NULL,
  url VARCHAR(255) NULL COMMENT 'Enlace o ruta al archivo, si ya existe',
  id_usuario INT UNSIGNED NOT NULL COMMENT 'Quién lo registró',
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_documentos_usuario
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SOLICITUDES (subdirector/solicitudes.php)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS solicitudes (
  id_solicitud INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo VARCHAR(100) NOT NULL,
  descripcion TEXT NULL,
  estado ENUM('PENDIENTE', 'APROBADA', 'RECHAZADA') NOT NULL DEFAULT 'PENDIENTE',
  id_usuario INT UNSIGNED NOT NULL COMMENT 'Quién solicita',
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_solicitudes_usuario
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- COMUNICADOS (subdirector/comunicados.php)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS comunicados (
  id_comunicado INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(180) NOT NULL,
  contenido TEXT NOT NULL,
  id_usuario INT UNSIGNED NOT NULL COMMENT 'Quién lo publicó',
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_comunicados_usuario
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- CONFIGURACIÓN (admin/configuracion.php) — pares clave/valor
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS configuracion (
  clave VARCHAR(60) PRIMARY KEY,
  valor VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

INSERT INTO configuracion (clave, valor) VALUES
  ('nombre_colegio', 'I.E.P. 88044 Abraham Valdelomar'),
  ('correo_contacto', ''),
  ('telefono_contacto', '')
ON DUPLICATE KEY UPDATE clave = clave;

-- ============================================================
-- CREAR EL PRIMER USUARIO ADMINISTRADOR (obligatorio)
-- ============================================================
-- No se inserta ningún usuario/contraseña real aquí — debes
-- crear el tuyo. La forma más segura es generar el hash con PHP
-- y pegarlo en el INSERT de abajo (reemplaza los valores):
--
--   php -r "echo password_hash('TU_CONTRASEÑA_SEGURA', PASSWORD_DEFAULT);"
--
-- Luego:
--
--   INSERT INTO usuarios (nombres, apellidos, correo, password, estado, id_rol)
--   VALUES (
--     'Nombre',
--     'Apellido',
--     'correo@ie88044.edu.pe',
--     '$2y$10$PEGA_AQUI_EL_HASH_GENERADO',
--     'ACTIVO',
--     (SELECT id_rol FROM roles WHERE nombre = 'ADMIN')
--   );
--
-- Repite el proceso para SUBDIRECTOR y PROFESOR. Para un usuario
-- PROFESOR, además inserta su fila correspondiente en `profesores`:
--
--   INSERT INTO profesores (id_usuario, especialidad)
--   VALUES (LAST_INSERT_ID(), 'Especialidad del profesor');
