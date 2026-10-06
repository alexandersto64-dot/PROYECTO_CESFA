<?php

// ==========================================
// Bootstrap compartido del módulo Profesor.
//
// Antes, cada página (dashboard.php) repetía esta misma
// comprobación de sesión + rol + conexión + datos del profesor.
// Al separar el Dashboard en varias páginas (alumnos.php,
// trabajos.php, envios.php, notificaciones.php, perfil.php) se
// extrajo aquí para no duplicar 6 veces exactamente el mismo
// bloque. La lógica es idéntica a la que ya tenía dashboard.php,
// solo movida de lugar.
//
// Uso, al inicio de cada página del profesor (antes de imprimir
// cualquier HTML):
//   require_once __DIR__ . "/../backend/partials/profesor_bootstrap.php";
//
// Deja disponibles: $conexion, $profesor (con id_profesor, nombres,
// apellidos, correo, especialidad).
// ==========================================

session_start();
require_once __DIR__ . "/../config/sesion_inactividad.php";


// ==================================================
// 1. COMPROBAR QUE EL USUARIO ESTÉ LOGUEADO
// ==================================================

if (!isset($_SESSION["id_usuario"])) {

    header("Location: ../login.html");
    exit;

}


// ==================================================
// 2. COMPROBAR QUE SEA PROFESOR
// ==================================================

if (!in_array($_SESSION["rol"], ["PROFESOR", "PROFESOR_PRIMARIA", "PROFESOR_SECUNDARIO"], true)) {

    die("Acceso no autorizado.");

}


// ==================================================
// 3. CONECTAR CON LA BASE DE DATOS
// ==================================================

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/seguridad_csrf.php";
require_once __DIR__ . "/../config/notificaciones.php";
require_once __DIR__ . "/../config/subida_archivos.php";
require_once __DIR__ . "/../config/flash.php";
require_once __DIR__ . "/../config/profesor_grados.php";
require_once __DIR__ . "/../config/materiales_cursos.php";

// ==================================================
// 4. OBTENER LOS DATOS DEL PROFESOR
// ==================================================

$sql = "
    SELECT
        p.id_profesor,
        u.nombres,
        u.apellidos,
        u.correo,
        p.especialidad

    FROM profesores p

    INNER JOIN usuarios u
        ON p.id_usuario = u.id_usuario

    WHERE p.id_usuario = ?
";


$stmt = $conexion->prepare($sql);

$stmt->execute([
    $_SESSION["id_usuario"]
]);

$profesor = $stmt->fetch();


// ==================================================
// 5. COMPROBAR QUE EXISTA EL PROFESOR
// ==================================================

if (!$profesor) {

    die("No se encontró el perfil del profesor.");

}


// ==================================================
// 6. NOTIFICACIONES AUTOMÁTICAS (MEJORA)
//
// Se generan "al vuelo" en cada carga de una página del profesor
// (dashboard, alumnos, envíos, notificaciones, trabajos, perfil).
// notificaciones_generar_avisos_vencimiento() ya evita duplicados
// internamente (revisa si la notificación de ese trabajo ya existe
// antes de insertar), así que es seguro llamarla en cada carga.
// ==================================================

notificaciones_generar_avisos_vencimiento($conexion, (int) $profesor["id_profesor"], (int) $_SESSION["id_usuario"]);
