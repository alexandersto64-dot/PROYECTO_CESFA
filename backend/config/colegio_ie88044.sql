-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 22-08-2026 a las 06:02:33
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `colegio_ie88044`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `alumnos`
--

CREATE TABLE `alumnos` (
  `id_alumno` int(10) UNSIGNED NOT NULL,
  `nombres` varchar(100) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `dni` char(8) NOT NULL,
  `fecha_nacimiento` date DEFAULT NULL,
  `apoderado_nombre` varchar(150) DEFAULT NULL,
  `apoderado_telefono` varchar(20) DEFAULT NULL,
  `estado` enum('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `asignaciones_docentes`
--

CREATE TABLE `asignaciones_docentes` (
  `id_asignacion` int(10) UNSIGNED NOT NULL,
  `id_profesor` int(10) UNSIGNED NOT NULL,
  `id_grado_seccion` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `comunicados`
--

CREATE TABLE `comunicados` (
  `id_comunicado` int(10) UNSIGNED NOT NULL,
  `titulo` varchar(180) NOT NULL,
  `contenido` text NOT NULL,
  `id_usuario` int(10) UNSIGNED NOT NULL COMMENT 'Quién lo publicó',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `configuracion`
--

CREATE TABLE `configuracion` (
  `clave` varchar(60) NOT NULL,
  `valor` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `configuracion`
--

INSERT INTO `configuracion` (`clave`, `valor`) VALUES
('correo_contacto', ''),
('nombre_colegio', 'I.E.P. 88044 Abraham Valdelomar'),
('telefono_contacto', '');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cursos`
--

CREATE TABLE `cursos` (
  `id_curso` int(10) UNSIGNED NOT NULL,
  `nombre` varchar(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `documentos`
--

CREATE TABLE `documentos` (
  `id_documento` int(10) UNSIGNED NOT NULL,
  `titulo` varchar(180) NOT NULL,
  `categoria` varchar(80) NOT NULL,
  `url` varchar(255) DEFAULT NULL COMMENT 'Enlace o ruta al archivo, si ya existe',
  `id_usuario` int(10) UNSIGNED NOT NULL COMMENT 'Quién lo registró',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `grados_secciones`
--

CREATE TABLE `grados_secciones` (
  `id_grado_seccion` int(10) UNSIGNED NOT NULL,
  `nivel` enum('PRIMARIA','SECUNDARIA') NOT NULL,
  `grado` tinyint(3) UNSIGNED NOT NULL,
  `seccion` char(1) NOT NULL,
  `nombre` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `grados_secciones`
--

INSERT INTO `grados_secciones` (`id_grado_seccion`, `nivel`, `grado`, `seccion`, `nombre`) VALUES
(1, 'PRIMARIA', 1, 'A', '1ro A'),
(2, 'PRIMARIA', 1, 'B', '1ro B'),
(3, 'PRIMARIA', 1, 'C', '1ro C'),
(4, 'PRIMARIA', 2, 'A', '2do A'),
(5, 'PRIMARIA', 2, 'B', '2do B'),
(6, 'PRIMARIA', 2, 'C', '2do C'),
(7, 'PRIMARIA', 3, 'A', '3ro A'),
(8, 'PRIMARIA', 3, 'B', '3ro B'),
(9, 'PRIMARIA', 3, 'C', '3ro C'),
(10, 'PRIMARIA', 4, 'A', '4to A'),
(11, 'PRIMARIA', 4, 'B', '4to B'),
(12, 'PRIMARIA', 4, 'C', '4to C'),
(13, 'PRIMARIA', 5, 'A', '5to A'),
(14, 'PRIMARIA', 5, 'B', '5to B'),
(15, 'PRIMARIA', 5, 'C', '5to C'),
(16, 'PRIMARIA', 6, 'A', '6to A'),
(17, 'PRIMARIA', 6, 'B', '6to B'),
(18, 'PRIMARIA', 6, 'C', '6to C'),
(19, 'SECUNDARIA', 1, 'A', '1ro A'),
(20, 'SECUNDARIA', 1, 'B', '1ro B'),
(21, 'SECUNDARIA', 1, 'C', '1ro C'),
(22, 'SECUNDARIA', 2, 'A', '2do A'),
(23, 'SECUNDARIA', 2, 'B', '2do B'),
(24, 'SECUNDARIA', 2, 'C', '2do C'),
(25, 'SECUNDARIA', 3, 'A', '3ro A'),
(26, 'SECUNDARIA', 3, 'B', '3ro B'),
(27, 'SECUNDARIA', 3, 'C', '3ro C'),
(28, 'SECUNDARIA', 4, 'A', '4to A'),
(29, 'SECUNDARIA', 4, 'B', '4to B'),
(30, 'SECUNDARIA', 4, 'C', '4to C'),
(31, 'SECUNDARIA', 5, 'A', '5to A'),
(32, 'SECUNDARIA', 5, 'B', '5to B'),
(33, 'SECUNDARIA', 5, 'C', '5to C');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `matriculas`
--

CREATE TABLE `matriculas` (
  `id_matricula` int(10) UNSIGNED NOT NULL,
  `id_alumno` int(10) UNSIGNED NOT NULL,
  `id_grado_seccion` int(10) UNSIGNED NOT NULL,
  `id_periodo` int(10) UNSIGNED NOT NULL,
  `estado` enum('ACTIVA','RETIRADO','TRASLADADO') NOT NULL DEFAULT 'ACTIVA',
  `fecha_matricula` date NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `periodos_academicos`
--

CREATE TABLE `periodos_academicos` (
  `id_periodo` int(10) UNSIGNED NOT NULL,
  `nombre` varchar(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `profesores`
--

CREATE TABLE `profesores` (
  `id_profesor` int(10) UNSIGNED NOT NULL,
  `id_usuario` int(10) UNSIGNED NOT NULL,
  `especialidad` varchar(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `roles`
--

CREATE TABLE `roles` (
  `id_rol` int(10) UNSIGNED NOT NULL,
  `nombre` varchar(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `roles`
--

INSERT INTO `roles` (`id_rol`, `nombre`) VALUES
(1, 'ADMIN'),
(3, 'PROFESOR'),
(2, 'SUBDIRECTOR');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `solicitudes`
--

CREATE TABLE `solicitudes` (
  `id_solicitud` int(10) UNSIGNED NOT NULL,
  `tipo` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `estado` enum('PENDIENTE','APROBADA','RECHAZADA') NOT NULL DEFAULT 'PENDIENTE',
  `id_usuario` int(10) UNSIGNED NOT NULL COMMENT 'Quién solicita',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tipos_trabajo`
--

CREATE TABLE `tipos_trabajo` (
  `id_tipo_trabajo` int(10) UNSIGNED NOT NULL,
  `nombre` varchar(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `trabajos`
--

CREATE TABLE `trabajos` (
  `id_trabajo` int(10) UNSIGNED NOT NULL,
  `titulo` varchar(150) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `fecha_limite` date NOT NULL,
  `estado` varchar(30) NOT NULL DEFAULT 'PENDIENTE',
  `id_curso` int(10) UNSIGNED NOT NULL,
  `id_tipo_trabajo` int(10) UNSIGNED NOT NULL,
  `id_periodo` int(10) UNSIGNED NOT NULL,
  `id_profesor` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id_usuario` int(10) UNSIGNED NOT NULL,
  `nombres` varchar(100) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `correo` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'Hash generado con password_hash() — nunca texto plano',
  `estado` enum('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO',
  `id_rol` int(10) UNSIGNED NOT NULL,
  `ultimo_acceso` datetime DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `alumnos`
--
ALTER TABLE `alumnos`
  ADD PRIMARY KEY (`id_alumno`),
  ADD UNIQUE KEY `dni` (`dni`);

--
-- Indices de la tabla `asignaciones_docentes`
--
ALTER TABLE `asignaciones_docentes`
  ADD PRIMARY KEY (`id_asignacion`),
  ADD UNIQUE KEY `uq_profesor_grado_seccion` (`id_profesor`,`id_grado_seccion`),
  ADD KEY `fk_asignaciones_grado_seccion` (`id_grado_seccion`);

--
-- Indices de la tabla `comunicados`
--
ALTER TABLE `comunicados`
  ADD PRIMARY KEY (`id_comunicado`),
  ADD KEY `fk_comunicados_usuario` (`id_usuario`);

--
-- Indices de la tabla `configuracion`
--
ALTER TABLE `configuracion`
  ADD PRIMARY KEY (`clave`);

--
-- Indices de la tabla `cursos`
--
ALTER TABLE `cursos`
  ADD PRIMARY KEY (`id_curso`);

--
-- Indices de la tabla `documentos`
--
ALTER TABLE `documentos`
  ADD PRIMARY KEY (`id_documento`),
  ADD KEY `fk_documentos_usuario` (`id_usuario`);

--
-- Indices de la tabla `grados_secciones`
--
ALTER TABLE `grados_secciones`
  ADD PRIMARY KEY (`id_grado_seccion`),
  ADD UNIQUE KEY `uq_grado_seccion` (`nivel`,`grado`,`seccion`);

--
-- Indices de la tabla `matriculas`
--
ALTER TABLE `matriculas`
  ADD PRIMARY KEY (`id_matricula`),
  ADD UNIQUE KEY `uq_alumno_periodo` (`id_alumno`,`id_periodo`),
  ADD KEY `fk_matriculas_grado_seccion` (`id_grado_seccion`),
  ADD KEY `fk_matriculas_periodo` (`id_periodo`);

--
-- Indices de la tabla `periodos_academicos`
--
ALTER TABLE `periodos_academicos`
  ADD PRIMARY KEY (`id_periodo`);

--
-- Indices de la tabla `profesores`
--
ALTER TABLE `profesores`
  ADD PRIMARY KEY (`id_profesor`),
  ADD UNIQUE KEY `id_usuario` (`id_usuario`);

--
-- Indices de la tabla `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id_rol`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `solicitudes`
--
ALTER TABLE `solicitudes`
  ADD PRIMARY KEY (`id_solicitud`),
  ADD KEY `fk_solicitudes_usuario` (`id_usuario`);

--
-- Indices de la tabla `tipos_trabajo`
--
ALTER TABLE `tipos_trabajo`
  ADD PRIMARY KEY (`id_tipo_trabajo`);

--
-- Indices de la tabla `trabajos`
--
ALTER TABLE `trabajos`
  ADD PRIMARY KEY (`id_trabajo`),
  ADD KEY `fk_trabajos_curso` (`id_curso`),
  ADD KEY `fk_trabajos_tipo` (`id_tipo_trabajo`),
  ADD KEY `fk_trabajos_periodo` (`id_periodo`),
  ADD KEY `fk_trabajos_profesor` (`id_profesor`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id_usuario`),
  ADD UNIQUE KEY `correo` (`correo`),
  ADD KEY `fk_usuarios_rol` (`id_rol`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `alumnos`
--
ALTER TABLE `alumnos`
  MODIFY `id_alumno` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `asignaciones_docentes`
--
ALTER TABLE `asignaciones_docentes`
  MODIFY `id_asignacion` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `comunicados`
--
ALTER TABLE `comunicados`
  MODIFY `id_comunicado` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cursos`
--
ALTER TABLE `cursos`
  MODIFY `id_curso` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `documentos`
--
ALTER TABLE `documentos`
  MODIFY `id_documento` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `grados_secciones`
--
ALTER TABLE `grados_secciones`
  MODIFY `id_grado_seccion` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT de la tabla `matriculas`
--
ALTER TABLE `matriculas`
  MODIFY `id_matricula` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `periodos_academicos`
--
ALTER TABLE `periodos_academicos`
  MODIFY `id_periodo` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `profesores`
--
ALTER TABLE `profesores`
  MODIFY `id_profesor` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `roles`
--
ALTER TABLE `roles`
  MODIFY `id_rol` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `solicitudes`
--
ALTER TABLE `solicitudes`
  MODIFY `id_solicitud` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tipos_trabajo`
--
ALTER TABLE `tipos_trabajo`
  MODIFY `id_tipo_trabajo` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `trabajos`
--
ALTER TABLE `trabajos`
  MODIFY `id_trabajo` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id_usuario` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `asignaciones_docentes`
--
ALTER TABLE `asignaciones_docentes`
  ADD CONSTRAINT `fk_asignaciones_grado_seccion` FOREIGN KEY (`id_grado_seccion`) REFERENCES `grados_secciones` (`id_grado_seccion`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_asignaciones_profesor` FOREIGN KEY (`id_profesor`) REFERENCES `profesores` (`id_profesor`) ON DELETE CASCADE;

--
-- Filtros para la tabla `comunicados`
--
ALTER TABLE `comunicados`
  ADD CONSTRAINT `fk_comunicados_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`);

--
-- Filtros para la tabla `documentos`
--
ALTER TABLE `documentos`
  ADD CONSTRAINT `fk_documentos_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`);

--
-- Filtros para la tabla `matriculas`
--
ALTER TABLE `matriculas`
  ADD CONSTRAINT `fk_matriculas_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_matriculas_grado_seccion` FOREIGN KEY (`id_grado_seccion`) REFERENCES `grados_secciones` (`id_grado_seccion`),
  ADD CONSTRAINT `fk_matriculas_periodo` FOREIGN KEY (`id_periodo`) REFERENCES `periodos_academicos` (`id_periodo`);

--
-- Filtros para la tabla `profesores`
--
ALTER TABLE `profesores`
  ADD CONSTRAINT `fk_profesores_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE;

--
-- Filtros para la tabla `solicitudes`
--
ALTER TABLE `solicitudes`
  ADD CONSTRAINT `fk_solicitudes_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`);

--
-- Filtros para la tabla `trabajos`
--
ALTER TABLE `trabajos`
  ADD CONSTRAINT `fk_trabajos_curso` FOREIGN KEY (`id_curso`) REFERENCES `cursos` (`id_curso`),
  ADD CONSTRAINT `fk_trabajos_periodo` FOREIGN KEY (`id_periodo`) REFERENCES `periodos_academicos` (`id_periodo`),
  ADD CONSTRAINT `fk_trabajos_profesor` FOREIGN KEY (`id_profesor`) REFERENCES `profesores` (`id_profesor`),
  ADD CONSTRAINT `fk_trabajos_tipo` FOREIGN KEY (`id_tipo_trabajo`) REFERENCES `tipos_trabajo` (`id_tipo_trabajo`);

--
-- Filtros para la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD CONSTRAINT `fk_usuarios_rol` FOREIGN KEY (`id_rol`) REFERENCES `roles` (`id_rol`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
