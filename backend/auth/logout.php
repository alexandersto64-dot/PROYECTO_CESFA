<?php

session_start();

// Se registra el cierre de sesión antes de vaciar la sesión (si se puede).
if (isset($_SESSION["id_usuario"])) {
    try {
        require_once __DIR__ . "/../config/database.php";
        require_once __DIR__ . "/../config/admin_seguimiento.php";
        auditoria_evento($conexion, (int) $_SESSION["id_usuario"], "LOGOUT", "SESION", (int) $_SESSION["id_usuario"], "Cierre de sesión");
    } catch (\Throwable $e) {
        // cerrar sesión nunca debe fallar por la auditoría
    }
}


// ==========================================
// CERRAR TODAS LAS VARIABLES DE SESIÓN
// ==========================================

$_SESSION = [];


// ==========================================
// ELIMINAR LA COOKIE DE SESIÓN
// ==========================================

if (ini_get("session.use_cookies")) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}


// ==========================================
// DESTRUIR LA SESIÓN
// ==========================================

session_destroy();


// ==========================================
// VOLVER AL LOGIN
// ==========================================

header("Location: ../../login.html");

exit;
