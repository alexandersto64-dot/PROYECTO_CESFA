<?php

// Abre una notificación: la marca como leída (solo si es del padre
// logueado) y lo lleva a su destino. Así el contador de la campanita
// baja en cuanto el padre presiona el aviso.

require_once __DIR__ . "/../backend/partials/padre_bootstrap.php";
require_once __DIR__ . "/../backend/config/notificaciones.php";

$idNotificacion = (int) ($_GET["id"] ?? 0);
$url = $idNotificacion > 0
    ? notificacion_marcar_leida($conexion, (int) $_SESSION["id_usuario"], $idNotificacion)
    : null;

// Solo se redirige a páginas internas del módulo Padre (nunca a URLs externas).
$destino = "notificaciones.php";

if ($url !== null && $url !== "") {
    $u = str_replace("../padre/", "", $url);
    if (preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $u)) {
        $destino = $u;
    }
}

header("Location: " . $destino);
exit;
