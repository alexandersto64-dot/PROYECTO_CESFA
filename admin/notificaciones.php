<?php

session_start();
require_once __DIR__ . "/../backend/config/sesion_inactividad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.html");
    exit;
}

if ($_SESSION["rol"] !== "ADMIN") {
    die("Acceso no autorizado.");
}

require_once "../backend/config/database.php";
require_once "../backend/config/notificaciones.php";

// ==================================================
// NOTIFICACIONES DE ADMINISTRADOR
// Mismo patrón (listar, contar no leídas, marcar como leídas al
// entrar) que ya usan profesor/notificaciones.php y
// subdirector/notificaciones.php, reutilizando las mismas funciones
// de backend/config/notificaciones.php — no se agrega ninguna tabla
// ni consulta nueva. Administrador ahora recibe el mismo aviso que
// Subdirección cuando un profesor completa el 100% de un módulo
// (ver notificaciones_verificar_modulo_completo en
// backend/config/notificaciones.php), antes solo veía el contador
// en el sidebar sin poder abrir el listado (y ese contador nunca
// bajaba porque nada le generaba avisos todavía).
//
// Se lee el estado actual primero para poder mostrar en esta misma
// carga cuáles eran nuevas, y recién después se marcan como leídas
// — así la campanita del sidebar (más abajo) ya sale en 0 en esta
// misma visita.
// ==================================================

$notificacionesAdmin = notificaciones_listar($conexion, (int) $_SESSION["id_usuario"], 20);
$notificacionesNoLeidas = notificaciones_no_leidas($conexion, (int) $_SESSION["id_usuario"]);

if ($notificacionesNoLeidas > 0) {
    notificaciones_marcar_leidas($conexion, (int) $_SESSION["id_usuario"]);
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificaciones · Panel del Administrador - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">


<header>
    <div>
        <h1>Notificaciones</h1>
        <p>Panel del Administrador · I.E.P. 88044 Abraham Valdelomar</p>
    </div>
    <div class="header-actions">
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>


<main>

    <section class="panel-section">

        <div class="panel-section-head">
            <h2>
                <?= icon("bell") ?> Notificaciones
                <?php if ($notificacionesNoLeidas > 0): ?>
                    <span class="status-badge status-requiere_cambios"><?= $notificacionesNoLeidas ?> nueva(s)</span>
                <?php endif; ?>
            </h2>
        </div>

        <?php if (count($notificacionesAdmin) === 0): ?>

            <p class="placeholder-text">No hay notificaciones todavía.</p>

        <?php else: ?>

            <?php foreach ($notificacionesAdmin as $n): ?>
                <div class="notif-item <?= $n["leido"] ? "leida" : "" ?>">
                    <?php if (!empty($n["url"])): ?>
                        <a href="<?= htmlspecialchars($n["url"]) ?>"><?= htmlspecialchars($n["mensaje"]) ?></a>
                    <?php else: ?>
                        <?= htmlspecialchars($n["mensaje"]) ?>
                    <?php endif; ?>
                    <span class="placeholder-text"> · <?= htmlspecialchars($n["creado_en"]) ?></span>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>

    </section>

</main>


</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
