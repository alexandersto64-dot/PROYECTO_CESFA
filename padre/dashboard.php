<?php

require_once __DIR__ . "/../backend/partials/padre_bootstrap.php";

[$mensajeFlash, $mensajeFlashTipo] = flash_get();

// ==================================================
// SIN HIJOS VINCULADOS TODAVÍA
//
// Puede pasar si Admin creó la cuenta del padre pero todavía no
// registró el vínculo en padres_alumnos (esa pantalla es de Admin,
// no existe todavía — Fase 2 la agrega junto con las demás páginas
// nuevas). Se muestra un estado vacío en vez de una pantalla rota.
// ==================================================

$sinHijos = count($hijos) === 0;

if (!$sinHijos && $alumnoActivo) {

    $idAlumno = (int) $alumnoActivo["id_alumno"];

    // --------------------------------------------------
    // NOTAS: resumen del último bimestre con notas registradas,
    // del periodo de la matrícula activa del hijo (si tiene).
    // --------------------------------------------------
    $notasHijo = padre_notas($conexion, $idAlumno, $alumnoActivo["id_periodo"] ? (int) $alumnoActivo["id_periodo"] : null);
    $ultimoBimestre = padre_ultimo_bimestre_con_notas($notasHijo);
    $notasUltimoBimestre = $ultimoBimestre !== null
        ? array_values(array_filter($notasHijo, fn($n) => (int) $n["bimestre"] === $ultimoBimestre))
        : [];

    // Promedio solo tiene sentido en Secundaria (nota_vigesimal). En
    // Primaria las notas son literales (AD/A/B/C) y no se promedian.
    $notasVigesimales = array_filter($notasUltimoBimestre, fn($n) => $n["nota_vigesimal"] !== null);
    $promedioBimestre = count($notasVigesimales) > 0
        ? round(array_sum(array_column($notasVigesimales, "nota_vigesimal")) / count($notasVigesimales), 1)
        : null;

    // --------------------------------------------------
    // COMPORTAMIENTO: conteo total + últimos 3 registros
    // --------------------------------------------------
    $comportamientoConteo = padre_comportamiento_conteo($conexion, $idAlumno);
    $comportamientoReciente = padre_comportamiento($conexion, $idAlumno, 3);

    // --------------------------------------------------
    // SOLICITUDES DE MATRÍCULA (preinscripción) hechas por este padre
    // --------------------------------------------------
    $solicitudesPadre = padre_solicitudes_matricula($conexion, (int) $_SESSION["id_usuario"]);
    $solicitudesPendientes = count(array_filter($solicitudesPadre, fn($s) => $s["estado"] === "PENDIENTE"));

}

$etiquetasNotaLiteral = ["AD" => "AD — Logro destacado", "A" => "A — Logro esperado", "B" => "B — En proceso", "C" => "C — En inicio"];
$etiquetasComportamiento = ["POSITIVO" => "Positivo", "NEGATIVO" => "Negativo", "NEUTRO" => "Neutro"];

/**
 * "hace X días" — mismo formato que ya usa el Dashboard del Profesor.
 */
function padre_tiempo_relativo(?string $fecha): ?string
{
    if (!$fecha) {
        return null;
    }

    $dias = (int) floor((time() - strtotime($fecha)) / 86400);

    if ($dias <= 0) {
        return "hoy";
    }
    if ($dias === 1) {
        return "hace 1 día";
    }
    if ($dias < 30) {
        return "hace {$dias} días";
    }

    $meses = (int) floor($dias / 30);
    return $meses === 1 ? "hace 1 mes" : "hace {$meses} meses";
}

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
        Panel del Padre de Familia - I.E.P. 88044 Abraham Valdelomar
    </title>

    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">

</head>


<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">



<!-- ==================================================
     ENCABEZADO
================================================== -->

<header>

    <div>
        <span class="header-eyebrow">Dashboard</span>
        <h1>Panel del Padre de Familia</h1>
    </div>

    <div class="header-actions">

        <div class="header-identity">
            <div class="header-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($_SESSION["nombres"], 0, 1)) . mb_strtoupper(mb_substr($_SESSION["apellidos"] ?? "", 0, 1))) ?></div>
            <div class="header-identity-text">
                <strong><?= htmlspecialchars($_SESSION["nombres"]) ?> <?= htmlspecialchars($_SESSION["apellidos"] ?? "") ?></strong>
                <span>I.E.P. 88044</span>
            </div>
        </div>

    </div>

</header>



<!-- ==================================================
     CONTENIDO PRINCIPAL
================================================== -->

<main>

    <?php if ($mensajeFlash): ?>
        <div class="panel-alert panel-alert-<?= htmlspecialchars($mensajeFlashTipo) ?>">
            <?= icon($mensajeFlashTipo === "success" ? "check-circle" : "alert-triangle") ?>
            <?= htmlspecialchars($mensajeFlash) ?>
        </div>
    <?php endif; ?>

    <!-- ==================================================
         BIENVENIDA
    ================================================== -->

    <section class="panel-section">
        <div class="panel-section-head">
            <h2>Bienvenido(a), <?= htmlspecialchars($_SESSION["nombres"]) ?></h2>
            <p class="panel-section-sub">
                <?= $sinHijos
                    ? "Todavía no tienes hijos vinculados a tu cuenta."
                    : (count($hijos) === 1
                        ? "Resumen académico de tu hijo(a)."
                        : "Resumen académico — usa el selector del menú para cambiar de hijo(a).") ?>
            </p>
        </div>
    </section>


    <?php if ($sinHijos): ?>

        <div class="empty-state">
            <span class="empty-state-icon" aria-hidden="true"><?= icon("smile") ?></span>
            <h2>Sin hijos vinculados todavía</h2>
            <p>
                Pide a la Dirección/Administración del colegio que vincule a tu(s) hijo(a/s)
                a tu cuenta para poder ver sus notas, comportamiento y hacer trámites de matrícula.
            </p>
        </div>

    <?php else: ?>

        <!-- ==================================================
             NECESITA TU ATENCIÓN
        ================================================== -->

        <?php $hayAvisos = $solicitudesPendientes > 0 || $comportamientoConteo["NEGATIVO"] > 0; ?>

        <?php if ($hayAvisos): ?>

            <section class="panel-section">
                <div class="panel-section-head">
                    <h2><?= icon("alert-triangle") ?> Necesita tu atención</h2>
                </div>

                <?php if ($solicitudesPendientes > 0): ?>
                    <a href="preinscripcion.php" class="resumen-alert is-info">
                        <?= icon("file-plus") ?>
                        <span>
                            <strong><?= $solicitudesPendientes ?></strong>
                            <?= $solicitudesPendientes === 1 ? "solicitud de matrícula está pendiente" : "solicitudes de matrícula están pendientes" ?>
                            de revisión
                        </span>
                    </a>
                <?php endif; ?>

                <?php if ($comportamientoConteo["NEGATIVO"] > 0): ?>
                    <a href="comportamiento.php<?= $sufijoHijo ?? "" ?>" class="resumen-alert">
                        <?= icon("alert-triangle") ?>
                        <span>
                            <strong><?= $comportamientoConteo["NEGATIVO"] ?></strong>
                            <?= $comportamientoConteo["NEGATIVO"] === 1 ? "registro de comportamiento negativo" : "registros de comportamiento negativo" ?>
                            en el historial de <?= htmlspecialchars($alumnoActivo["nombres"]) ?>
                        </span>
                    </a>
                <?php endif; ?>
            </section>

        <?php endif; ?>


        <!-- ==================================================
             DATOS DEL HIJO / MATRÍCULA
        ================================================== -->

        <section class="panel-section">
            <div class="panel-section-head">
                <h2><?= icon("backpack") ?> <?= htmlspecialchars(trim($alumnoActivo["nombres"] . " " . $alumnoActivo["apellidos"])) ?></h2>
            </div>

            <?php if (!$alumnoActivo["id_matricula"]): ?>
                <p class="placeholder-text">
                    <?= icon("alert-triangle") ?> No tiene una matrícula activa registrada en el sistema.
                    Si crees que esto es un error, comunícate con Secretaría.
                </p>
            <?php else: ?>
                <div class="stats-grid">
                    <div class="stat-card">
                        <span class="stat-value"><?= htmlspecialchars($alumnoActivo["grado_nombre"]) ?></span>
                        <span class="stat-label"><?= htmlspecialchars(ucfirst(strtolower($alumnoActivo["nivel"]))) ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-value"><?= htmlspecialchars($alumnoActivo["periodo_nombre"]) ?></span>
                        <span class="stat-label">Periodo académico</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-value"><?= $promedioBimestre ?? "—" ?></span>
                        <span class="stat-label">Promedio bimestre <?= $ultimoBimestre ?? "—" ?></span>
                    </div>
                    <div class="stat-card<?= $comportamientoConteo["NEGATIVO"] > 0 ? " stat-card-alerta" : "" ?>">
                        <span class="stat-value"><?= $comportamientoConteo["POSITIVO"] ?> / <?= $comportamientoConteo["NEGATIVO"] ?></span>
                        <span class="stat-label">Comportamiento (positivo / negativo)</span>
                    </div>
                </div>
            <?php endif; ?>
        </section>


        <!-- ==================================================
             NOTAS DEL ÚLTIMO BIMESTRE
        ================================================== -->

        <section class="panel-section">
            <div class="panel-section-head">
                <h2><?= icon("clipboard") ?> Notas<?= $ultimoBimestre !== null ? " — Bimestre {$ultimoBimestre}" : "" ?></h2>
                <p class="panel-section-sub">
                    <?= $ultimoBimestre !== null ? "Curso por curso, del bimestre más reciente con notas registradas." : "Todavía no hay notas registradas." ?>
                </p>
            </div>

            <?php if (count($notasUltimoBimestre) === 0): ?>
                <p class="placeholder-text"><?= icon("clock") ?> Sin notas registradas todavía.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Curso</th>
                                <th>Nota</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notasUltimoBimestre as $n): ?>
                                <tr>
                                    <td><?= htmlspecialchars($n["curso_nombre"]) ?></td>
                                    <td>
                                        <?php if ($n["nota_vigesimal"] !== null): ?>
                                            <span class="status-badge <?= (int) $n["nota_vigesimal"] < 11 ? "status-inactivo" : "status-activo" ?>">
                                                <?= (int) $n["nota_vigesimal"] ?>
                                            </span>
                                        <?php elseif ($n["nota_literal"] !== null): ?>
                                            <span class="status-badge status-nota-<?= strtolower($n["nota_literal"]) ?>" title="<?= htmlspecialchars($etiquetasNotaLiteral[$n["nota_literal"]] ?? "") ?>">
                                                <?= htmlspecialchars($n["nota_literal"]) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="placeholder-text">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <p class="placeholder-text" style="margin-top:10px;">
                <a href="notas.php<?= $sufijoHijo ?? "" ?>">Ver la boleta completa →</a>
            </p>
        </section>


        <!-- ==================================================
             COMPORTAMIENTO RECIENTE
        ================================================== -->

        <section class="panel-section">
            <div class="panel-section-head">
                <h2><?= icon("smile") ?> Comportamiento reciente</h2>
            </div>

            <?php if (count($comportamientoReciente) === 0): ?>
                <p class="placeholder-text"><?= icon("clock") ?> Sin registros de comportamiento todavía.</p>
            <?php else: ?>
                <ul class="actividad-reciente-lista">
                    <?php foreach ($comportamientoReciente as $c): ?>
                        <li class="actividad-reciente-item">
                            <span class="status-badge status-<?= strtolower($c["tipo"]) ?>"><?= htmlspecialchars($etiquetasComportamiento[$c["tipo"]] ?? $c["tipo"]) ?></span>
                            <span class="actividad-reciente-detalle">
                                <?= htmlspecialchars(mb_strimwidth($c["descripcion"], 0, 110, "…")) ?>
                                <span class="obs-text"> — registrado por <?= htmlspecialchars(trim($c["registrado_por_nombres"] . " " . $c["registrado_por_apellidos"])) ?></span>
                            </span>
                            <span class="actividad-reciente-fecha"><?= htmlspecialchars(padre_tiempo_relativo($c["creado_en"])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p class="placeholder-text" style="margin-top:10px;">
                <a href="comportamiento.php<?= $sufijoHijo ?? "" ?>">Ver historial completo →</a>
            </p>
        </section>


        <!-- ==================================================
             ACCESOS RÁPIDOS
        ================================================== -->

        <section class="panel-section">
            <div class="panel-section-head">
                <h2>Accesos rápidos</h2>
            </div>

            <div class="cards">
                <div class="card">
                    <h3><?= icon("clipboard") ?> Boleta de notas</h3>
                    <p>Notas de <?= htmlspecialchars($alumnoActivo["nombres"]) ?> por curso y bimestre.</p>
                    <div class="card-foot" style="justify-content:flex-end;">
                        <a href="notas.php<?= $sufijoHijo ?? "" ?>">Ingresar</a>
                    </div>
                </div>
                <div class="card">
                    <h3><?= icon("smile") ?> Comportamiento</h3>
                    <p>Historial completo de registros de comportamiento.</p>
                    <div class="card-foot" style="justify-content:flex-end;">
                        <a href="comportamiento.php<?= $sufijoHijo ?? "" ?>">Ingresar</a>
                    </div>
                </div>
                <div class="card">
                    <h3><?= icon("file-plus") ?> Preinscripción de matrícula</h3>
                    <p>
                        <?= $solicitudesPendientes > 0
                            ? "Tienes {$solicitudesPendientes} " . ($solicitudesPendientes === 1 ? "solicitud pendiente" : "solicitudes pendientes") . " de revisión."
                            : "Inicia el trámite de matrícula para un nuevo alumno." ?>
                    </p>
                    <div class="card-foot" style="justify-content:flex-end;">
                        <a href="preinscripcion.php">Ingresar</a>
                    </div>
                </div>
            </div>
        </section>

    <?php endif; ?>

</main>


</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>

</html>
