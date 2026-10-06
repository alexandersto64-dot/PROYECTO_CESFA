<?php

// ==========================================
// Bootstrap compartido del módulo Padre.
// Mismo patrón que profesor_bootstrap.php: cada página del módulo
// (dashboard.php, notas.php, comportamiento.php, preinscripcion.php)
// lo incluye antes de imprimir cualquier HTML.
//
// Uso:
//   require_once __DIR__ . "/../backend/partials/padre_bootstrap.php";
//
// Deja disponibles: $conexion, $hijos (todos los hijos vinculados),
// $idAlumnoActivo y $alumnoActivo (el hijo seleccionado, ya
// verificado como propio).
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
// 2. COMPROBAR QUE SEA PADRE
// ==================================================

if ($_SESSION["rol"] !== "PADRE") {

    die("Acceso no autorizado.");

}


// ==================================================
// 3. CONECTAR CON LA BASE DE DATOS
// ==================================================

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/seguridad_csrf.php";
require_once __DIR__ . "/../config/flash.php";
require_once __DIR__ . "/../config/padres.php";
require_once __DIR__ . "/../config/politica_password.php";


// ==================================================
// 3b. CAMBIO DE CONTRASEÑA OBLIGATORIO (primer ingreso)
// Cuentas creadas en lote / con clave puesta por el Admin: hasta que
// el padre elija la suya solo puede ver padre/cambiar_password.php.
// ==================================================

if (basename($_SERVER["SCRIPT_NAME"] ?? "") !== "cambiar_password.php"
    && password_cambio_obligatorio($conexion, (int) $_SESSION["id_usuario"])) {

    flash_set("Por seguridad, elige una contraseña nueva antes de continuar.", "error");
    header("Location: cambiar_password.php");
    exit;

}


// ==================================================
// 4. HIJOS VINCULADOS A ESTE PADRE
// ==================================================

$hijos = padre_hijos($conexion, (int) $_SESSION["id_usuario"]);


// ==================================================
// 5. HIJO ACTIVO (selector del sidebar, cuando hay 2+)
//
// Igual que el selector de grado del Profesor: el id_alumno llega
// por GET y solo decide a qué se navega — cada página lo vuelve a
// validar contra padre_verificar_hijo() por su cuenta, así que esto
// nunca es la fuente de la autorización. Se guarda en sesión para
// que, al entrar directo a una página sin id_alumno en la URL
// (ej. desde un enlace del sidebar), se mantenga el último hijo
// elegido en vez de volver siempre al primero.
// ==================================================

$idAlumnoActivo = null;

if (isset($_GET["id_alumno"])) {

    $candidato = (int) $_GET["id_alumno"];

    foreach ($hijos as $h) {
        if ((int) $h["id_alumno"] === $candidato) {
            $idAlumnoActivo = $candidato;
            break;
        }
    }

}

if ($idAlumnoActivo === null && isset($_SESSION["padre_id_alumno_activo"])) {

    $candidato = (int) $_SESSION["padre_id_alumno_activo"];

    foreach ($hijos as $h) {
        if ((int) $h["id_alumno"] === $candidato) {
            $idAlumnoActivo = $candidato;
            break;
        }
    }

}

if ($idAlumnoActivo === null && count($hijos) > 0) {
    $idAlumnoActivo = (int) $hijos[0]["id_alumno"];
}

if ($idAlumnoActivo !== null) {
    $_SESSION["padre_id_alumno_activo"] = $idAlumnoActivo;
}

$alumnoActivo = null;

foreach ($hijos as $h) {
    if ((int) $h["id_alumno"] === $idAlumnoActivo) {
        $alumnoActivo = $h;
        break;
    }
}
