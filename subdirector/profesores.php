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

$profesores = $conexion->query("
    SELECT u.nombres, u.apellidos, u.correo, u.estado, p.especialidad
    FROM profesores p
    INNER JOIN usuarios u ON p.id_usuario = u.id_usuario
    ORDER BY u.apellidos, u.nombres
")->fetchAll();

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profesores · Panel del Subdirector - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">


<header>
    <div>
        <h1>Personal docente</h1>
        <p>Panel del Subdirector · I.E.P. 88044 Abraham Valdelomar</p>
    </div>
    <div class="header-actions">
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <h2>Profesores registrados (<?= count($profesores) ?>)</h2>

    <?php if (count($profesores) === 0): ?>
        <p class="placeholder-text">Todavía no hay profesores registrados.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Nombre</th><th>Correo</th><th>Especialidad</th><th>Estado</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($profesores as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['nombres'] . ' ' . $p['apellidos']) ?></td>
                            <td><?= htmlspecialchars($p['correo']) ?></td>
                            <td><?= htmlspecialchars($p['especialidad']) ?></td>
                            <td>
                                <span class="status-badge status-<?= strtolower($p['estado']) ?>">
                                    <?= htmlspecialchars($p['estado']) ?>
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
