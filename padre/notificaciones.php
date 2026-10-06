<?php

require_once __DIR__ . "/../backend/partials/padre_bootstrap.php";
require_once __DIR__ . "/../backend/config/notificaciones.php";

$idUsuario = (int) $_SESSION["id_usuario"];

// "Marcar todas como leídas" (POST + CSRF, luego redirige para no reenviar el formulario al recargar).
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["accion"] ?? "") === "marcar_todas") {
    csrf_verificar();
    notificaciones_marcar_leidas($conexion, $idUsuario);
    header("Location: notificaciones.php" . ($idAlumnoActivo ? "?id_alumno=" . (int) $idAlumnoActivo : ""));
    exit;
}

$notificaciones = notificaciones_listar($conexion, $idUsuario, 50);
$noLeidas = notificaciones_no_leidas($conexion, $idUsuario);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificaciones - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <span class="header-eyebrow">Padre de familia</span>
        <h1>Notificaciones</h1>
    </div>
</header>

<main>

    <section class="panel-section">

        <div class="panel-section-head">
            <h2>
                <?= icon("bell") ?> Notificaciones
                <?php if ($noLeidas > 0): ?>
                    <span class="status-badge status-requiere_cambios"><?= $noLeidas ?> nueva(s)</span>
                <?php endif; ?>
            </h2>
            <?php if ($noLeidas > 0): ?>
                <form method="post" style="margin:0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="marcar_todas">
                    <button type="submit" class="btn-mini">Marcar todas como leídas</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (count($notificaciones) === 0): ?>

            <p class="placeholder-text">No tienes notificaciones todavía.</p>

        <?php else: ?>

            <?php foreach ($notificaciones as $n): ?>
                <div class="notif-item <?= $n["leido"] ? "leida" : "" ?>">
                    <a href="notificacion_abrir.php?id=<?= (int) $n["id_notificacion"] ?><?= $idAlumnoActivo ? "&amp;id_alumno=" . (int) $idAlumnoActivo : "" ?>">
                        <?= htmlspecialchars($n["mensaje"]) ?>
                    </a>
                    <span class="placeholder-text"> · <?= htmlspecialchars(date("d/m/Y H:i", strtotime($n["creado_en"]))) ?></span>
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
