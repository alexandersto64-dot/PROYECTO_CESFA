<?php

require_once __DIR__ . "/../backend/partials/padre_bootstrap.php";

if (!$alumnoActivo) {
    header("Location: dashboard.php");
    exit;
}

$idAlumno = (int) $alumnoActivo["id_alumno"];

$comportamiento = padre_comportamiento($conexion, $idAlumno);
$conteo = padre_comportamiento_conteo($conexion, $idAlumno);

$etiquetasComportamiento = ["POSITIVO" => "Positivo", "NEGATIVO" => "Negativo", "NEUTRO" => "Neutro"];

?>



<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Comportamiento - I.E.P. 88044 Abraham Valdelomar
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
        <span class="header-eyebrow">Padre de familia</span>
        <h1>Comportamiento</h1>
    </div>
</header>

<main>

    <section class="panel-section">
        <div class="panel-section-head">
            <h2><?= icon("backpack") ?> <?= htmlspecialchars(trim($alumnoActivo["nombres"] . " " . $alumnoActivo["apellidos"])) ?></h2>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-value"><?= $conteo["POSITIVO"] ?></span>
                <span class="stat-label">Positivos</span>
            </div>
            <div class="stat-card<?= $conteo["NEGATIVO"] > 0 ? " stat-card-alerta" : "" ?>">
                <span class="stat-value"><?= $conteo["NEGATIVO"] ?></span>
                <span class="stat-label">Negativos</span>
            </div>
            <div class="stat-card">
                <span class="stat-value"><?= $conteo["NEUTRO"] ?></span>
                <span class="stat-label">Neutros</span>
            </div>
        </div>
    </section>

    <section class="panel-section">
        <div class="panel-section-head">
            <h2>Historial completo</h2>
        </div>

        <?php if (count($comportamiento) === 0): ?>

            <div class="empty-state">
                <span class="empty-state-icon" aria-hidden="true"><?= icon("smile") ?></span>
                <h2>Sin registros todavía</h2>
                <p>Cuando un profesor, auxiliar o subdirección registre una observación, aparecerá aquí.</p>
            </div>

        <?php else: ?>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Descripción</th>
                            <th>Registrado por</th>
                            <th>Periodo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($comportamiento as $c): ?>
                            <tr>
                                <td><span class="placeholder-text"><?= htmlspecialchars(date("d/m/Y", strtotime($c["creado_en"]))) ?></span></td>
                                <td><span class="status-badge status-<?= strtolower($c["tipo"]) ?>"><?= htmlspecialchars($etiquetasComportamiento[$c["tipo"]] ?? $c["tipo"]) ?></span></td>
                                <td><?= htmlspecialchars($c["descripcion"]) ?></td>
                                <td><?= htmlspecialchars(trim($c["registrado_por_nombres"] . " " . $c["registrado_por_apellidos"])) ?></td>
                                <td><span class="placeholder-text"><?= htmlspecialchars($c["periodo_nombre"]) ?></span></td>
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
