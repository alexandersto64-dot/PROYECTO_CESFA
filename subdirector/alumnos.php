<?php

session_start();
require_once __DIR__ . "/../backend/config/sesion_inactividad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.html");
    exit;
}

if ($_SESSION["rol"] !== "SUBDIRECTOR") {
    die("Acceso no autorizado.");
}

require_once "../backend/config/database.php";

$alumnos = $conexion->query("
    SELECT
        a.nombres, a.apellidos, a.dni, a.estado,
        gs.nombre AS grado_seccion, gs.nivel,
        p.nombre AS periodo

    FROM alumnos a

    LEFT JOIN matriculas m ON m.id_alumno = a.id_alumno AND m.estado = 'ACTIVA'
    LEFT JOIN grados_secciones gs ON gs.id_grado_seccion = m.id_grado_seccion
    LEFT JOIN periodos_academicos p ON p.id_periodo = m.id_periodo

    ORDER BY a.apellidos, a.nombres
")->fetchAll();

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alumnos · Panel del Subdirector - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">


<header>
    <div>
        <h1>Consultar alumnos</h1>
        <p>Panel del Subdirector · I.E.P. 88044 Abraham Valdelomar</p>
    </div>
    <div class="header-actions">
        <a href="../backend/reportes/alumnos_pdf.php?descargar=1" target="_blank"><?= icon("file-text") ?> Descargar PDF</a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <h2>Alumnos registrados (<?= count($alumnos) ?>)</h2>

    <?php if (count($alumnos) === 0): ?>
        <p class="placeholder-text">Todavía no hay alumnos registrados.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Nombre</th><th>DNI</th><th>Grado / Sección</th><th>Período</th><th>Estado</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($alumnos as $a): ?>
                        <tr>
                            <td><?= htmlspecialchars($a['nombres'] . ' ' . $a['apellidos']) ?></td>
                            <td><?= htmlspecialchars($a['dni']) ?></td>
                            <td>
                                <?php if ($a['grado_seccion']): ?>
                                    <?= htmlspecialchars(ucfirst(strtolower($a['nivel']))) ?> · <?= htmlspecialchars($a['grado_seccion']) ?>
                                <?php else: ?>
                                    <span class="placeholder-text">Sin matrícula activa</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($a['periodo'] ?? '—') ?></td>
                            <td>
                                <span class="status-badge status-<?= strtolower($a['estado']) ?>">
                                    <?= htmlspecialchars($a['estado']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
