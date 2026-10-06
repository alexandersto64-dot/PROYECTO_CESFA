<?php

// ==========================================
// Cierre de sesión por inactividad.
//
// Se incluye justo después de session_start() en cada página que
// exige haber iniciado sesión (Admin, Subdirector, Profesor, Padre y
// los endpoints de PDF/archivos). NO va en login/recuperar contraseña.
//
// Si pasaron más de N segundos desde la última petición del usuario,
// se destruye la sesión y se lo manda al login con un aviso.
//   - Personal del colegio (Admin/Subdirector/Profesor): 30 min
//   - Padres de familia: 60 min
// Cada petición válida renueva el contador.
// ==========================================

const SESION_INACTIVIDAD_PERSONAL_SEG = 1800; // 30 minutos
const SESION_INACTIVIDAD_PADRE_SEG    = 3600; // 60 minutos

if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION["id_usuario"])) {

    $limiteSeg = (($_SESSION["rol"] ?? "") === "PADRE")
        ? SESION_INACTIVIDAD_PADRE_SEG
        : SESION_INACTIVIDAD_PERSONAL_SEG;

    $ahora  = time();
    $ultima = $_SESSION["ultima_actividad"] ?? null;

    if ($ultima !== null && ($ahora - (int) $ultima) > $limiteSeg) {

        // 1. Destruir la sesión y su cookie
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $p = session_get_cookie_params();
            setcookie(session_name(), "", time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
        }

        session_destroy();

        // 2. Volver al login. La ruta relativa se calcula según la
        //    profundidad de la página que se pidió (admin/ → ../,
        //    backend/reportes/ → ../../), así funciona igual en
        //    localhost/IE.88044/ que en un dominio propio.
        $raizProyecto = str_replace("\\", "/", (string) realpath(__DIR__ . "/../.."));
        $dirScript    = str_replace("\\", "/", dirname((string) realpath($_SERVER["SCRIPT_FILENAME"] ?? "")));
        $relativo     = trim(substr($dirScript, strlen($raizProyecto)), "/");
        $prefijo      = $relativo === "" ? "" : str_repeat("../", substr_count($relativo, "/") + 1);

        header("Location: " . $prefijo . "login.html?error=" . urlencode("Tu sesión se cerró por inactividad. Vuelve a ingresar."));
        exit;

    }

    $_SESSION["ultima_actividad"] = $ahora;

}
