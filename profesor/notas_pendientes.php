<?php

require_once __DIR__ . "/../backend/partials/profesor_bootstrap.php";
require_once __DIR__ . "/../backend/config/importacion_notas.php";
[$mensaje, $mensajeTipo] = flash_get();

$boletas = profesor_boletas_pendientes($conexion, (int) $profesor["id_profesor"]);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notas pendientes de revisión · Profesor - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <h1>Notas pendientes de revisión</h1>
        <p>Boletas importadas que incluyen tus cursos</p>
    </div>
</header>

<main>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <?php if (count($boletas) === 0): ?>
        <p class="placeholder-text">No tienes boletas pendientes de revisión por ahora.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Archivo</th><th>Subida el</th><th>Acción</th></tr></thead>
                <tbody>
                    <?php foreach ($boletas as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars($b["nombre_archivo_original"]) ?></td>
                            <td><?= htmlspecialchars($b["creado_en"]) ?></td>
                            <td><a href="revisar_boleta.php?id_boleta=<?= (int) $b["id_boleta"] ?>">Revisar mis filas</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</main>
</div>
</div>

</body>
</html>
