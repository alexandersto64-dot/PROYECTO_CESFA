<?php

require_once __DIR__ . "/../backend/partials/profesor_bootstrap.php";
require_once __DIR__ . "/../backend/config/subdireccion_seguimiento.php";

// ==================================================
// COMUNICADOS DEL PROFESOR — solo lectura.
//
// Al entrar a esta página, cada comunicado que se muestra queda
// marcado como leído por este usuario (comunicado_marcar_leido);
// así Subdirección ve en su panel quién ya lo vio sin que el
// profesor tenga que hacer ningún clic aparte. Ver
// subdirector/comunicados.php para el conteo "X/Y profesores".
// ==================================================

$comunicados = $conexion->query("
    SELECT c.id_comunicado, c.titulo, c.contenido, c.creado_en, u.nombres, u.apellidos
    FROM comunicados c
    INNER JOIN usuarios u ON c.id_usuario = u.id_usuario
    ORDER BY c.creado_en DESC
")->fetchAll();

foreach ($comunicados as $c) {
    comunicado_marcar_leido($conexion, (int) $c["id_comunicado"], (int) $_SESSION["id_usuario"]);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comunicados · Panel del Profesor - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <span class="header-eyebrow">Panel del Profesor</span>
        <h1><?= icon("megaphone") ?> Comunicados</h1>
    </div>
    <div class="header-actions">
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <h2>Comunicados de Subdirección (<?= count($comunicados) ?>)</h2>

    <?php if (count($comunicados) === 0): ?>
        <p class="placeholder-text">Todavía no hay comunicados publicados.</p>
    <?php else: ?>
        <div class="bitacora-lista">
            <?php foreach ($comunicados as $c): ?>
                <div class="bitacora-item">
                    <div class="bitacora-item-head">
                        <strong><?= htmlspecialchars($c["titulo"]) ?></strong>
                        <span><?= htmlspecialchars($c["creado_en"]) ?></span>
                    </div>
                    <p><?= htmlspecialchars($c["contenido"]) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
