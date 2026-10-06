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
require_once "../backend/config/admin_seguimiento.php";

$carpetaUploads = __DIR__ . "/../backend/uploads";
$uso = salud_tamano_carpeta($carpetaUploads);

$espacioLibre = @disk_free_space($carpetaUploads);
$espacioTotal = @disk_total_space($carpetaUploads);
$pctUsoDisco = ($espacioTotal && $espacioTotal > 0) ? round((($espacioTotal - $espacioLibre) / $espacioTotal) * 100) : null;

$conteos = salud_conteos_tablas($conexion);

// Umbral simple de alerta: menos de 500 MB libres en el disco.
$alertaEspacio = $espacioLibre !== false && $espacioLibre < (500 * 1024 * 1024);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salud del sistema · Panel de Administración - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <h1><?= icon("hard-drive") ?> Salud del sistema</h1>
        <p>Panel de Administración · I.E.P. 88044 Abraham Valdelomar</p>
    </div>
    <div class="header-actions">
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <?php if ($alertaEspacio): ?>
        <div class="panel-alert panel-alert-error">
            <?= icon("alert-triangle") ?> Queda poco espacio libre en el servidor (<?= salud_formato_bytes((int) $espacioLibre) ?>). Conviene liberar espacio pronto.
        </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-value"><?= salud_formato_bytes($uso["bytes"]) ?></span>
            <span class="stat-label">Ocupado por documentos subidos</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= $uso["archivos"] ?></span>
            <span class="stat-label">Archivos en el servidor</span>
        </div>
        <div class="stat-card<?= $alertaEspacio ? " stat-card-alerta" : "" ?>">
            <span class="stat-value"><?= $espacioLibre !== false ? salud_formato_bytes((int) $espacioLibre) : "—" ?></span>
            <span class="stat-label">Espacio libre en el disco</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= $pctUsoDisco !== null ? "$pctUsoDisco%" : "—" ?></span>
            <span class="stat-label">Uso total del disco</span>
        </div>
    </div>

    <h2>Registros en la base de datos</h2>
    <div class="semaforo-grid">
        <?php foreach ($conteos as $c): ?>
            <div class="semaforo-card">
                <div class="semaforo-card-head">
                    <strong><?= $c["total"] !== null ? $c["total"] : "—" ?></strong>
                </div>
                <p><?= htmlspecialchars($c["etiqueta"]) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <p class="placeholder-text">Los documentos subidos se guardan en <code>backend/uploads</code> dentro del propio servidor — no hay copia de seguridad automática. Se recomienda respaldar esa carpeta y un volcado de la base de datos periódicamente por fuera del sistema.</p>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
