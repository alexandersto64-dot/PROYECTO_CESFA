<?php

require_once __DIR__ . "/../backend/partials/profesor_bootstrap.php";

// ==================================================
// MIS ALUMNOS (aulas asignadas al profesor)
// Misma consulta que antes vivía dentro de dashboard.php,
// solo movida a su propia página.
// ==================================================

$misAlumnos = $conexion->prepare("
    SELECT
        a.nombres, a.apellidos, a.dni, a.estado,
        gs.nombre AS grado_seccion, gs.nivel

    FROM asignaciones_docentes ad

    INNER JOIN grados_secciones gs ON gs.id_grado_seccion = ad.id_grado_seccion
    INNER JOIN matriculas m ON m.id_grado_seccion = gs.id_grado_seccion AND m.estado = 'ACTIVA'
    INNER JOIN alumnos a ON a.id_alumno = m.id_alumno

    WHERE ad.id_profesor = ?

    ORDER BY gs.nivel, gs.grado, gs.seccion, a.apellidos, a.nombres
");

$misAlumnos->execute([$profesor["id_profesor"]]);
$misAlumnos = $misAlumnos->fetchAll();

$gradosAsignados = profesor_grados_asignados($conexion, $profesor["id_profesor"]);

?>



<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Mis Alumnos - I.E.P. 88044 Abraham Valdelomar
    </title>

    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">

</head>


<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">


<header>

    <div>
        <span class="header-eyebrow">Seguimiento</span>
        <h1>Mis alumnos</h1>
    </div>

    <div class="header-actions">
        <div class="header-identity">
            <div class="header-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($profesor["nombres"], 0, 1)) . mb_strtoupper(mb_substr($profesor["apellidos"], 0, 1))) ?></div>
            <div class="header-identity-text">
                <strong><?= htmlspecialchars($profesor["nombres"]) ?> <?= htmlspecialchars($profesor["apellidos"]) ?></strong>
                <span>I.E.P. 88044</span>
            </div>
        </div>
    </div>

</header>


<main>

    <section class="panel-section">

        <div class="panel-section-head">
            <h2>
                <?= icon("users") ?> Mis alumnos (<?= count($misAlumnos) ?>)
            </h2>
            <p class="panel-section-sub">
                Alumnos matriculados en las aulas que tienes asignadas.
            </p>
        </div>

        <?php if (count($misAlumnos) === 0): ?>

            <div class="empty-state">
                <span class="empty-state-icon" aria-hidden="true"><?= icon("users") ?></span>
                <h2>Todavía no tienes alumnos registrados</h2>
                <p>
                    Puede ser porque no tienes aulas asignadas, o porque las aulas asignadas
                    todavía no tienen alumnos matriculados. Pide al administrador que te
                    asigne un aula desde «Gestionar profesores».
                </p>
            </div>

        <?php else: ?>

            <?php if (count($misAlumnos) > 6): ?>
                <div class="panel-search">
                    <input type="search" id="filtro-alumnos" placeholder="Buscar por nombre, DNI o aula…">
                </div>
            <?php endif; ?>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Nombre</th><th>DNI</th><th>Aula</th><th>Estado</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($misAlumnos as $al): ?>
                            <tr>
                                <td><?= htmlspecialchars($al["nombres"] . " " . $al["apellidos"]) ?></td>
                                <td><?= htmlspecialchars($al["dni"]) ?></td>
                                <td><?= htmlspecialchars(ucfirst(strtolower($al["nivel"]))) ?> · <?= htmlspecialchars($al["grado_seccion"]) ?></td>
                                <td>
                                    <span class="status-badge status-<?= strtolower($al["estado"]) ?>">
                                        <?= htmlspecialchars($al["estado"]) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    </section>

</main>


</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>

</html>
