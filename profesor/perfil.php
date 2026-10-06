<?php

require_once __DIR__ . "/../backend/partials/profesor_bootstrap.php";

$gradosAsignados = profesor_grados_asignados($conexion, $profesor["id_profesor"]);

$nivelesProfesor = array_values(array_unique(array_column($gradosAsignados, "nivel")));
$nivelesProfesorLabel = implode(" y ", array_map(
    fn($n) => ucfirst(strtolower($n)),
    $nivelesProfesor
));

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
        Mi Perfil - I.E.P. 88044 Abraham Valdelomar
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
        <span class="header-eyebrow">Cuenta</span>
        <h1>Mi información</h1>
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

    <section class="panel-section profile-card">

        <h2>
            Mi información
        </h2>

        <p>

            <strong>
                Nombre:
            </strong>

            <?= htmlspecialchars($profesor["nombres"]) ?>

            <?= htmlspecialchars($profesor["apellidos"]) ?>

        </p>


        <p>

            <strong>
                Correo:
            </strong>

            <?= htmlspecialchars($profesor["correo"]) ?>

        </p>


        <p>

            <strong>
                Especialidad:
            </strong>

            <?= htmlspecialchars($profesor["especialidad"]) ?>

        </p>

        <?php if ($nivelesProfesorLabel !== ""): ?>
        <p>

            <strong>
                Nivel:
            </strong>

            <?= htmlspecialchars($nivelesProfesorLabel) ?>

        </p>
        <?php endif; ?>

    </section>

</main>


</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>

</html>
