<?php

require_once __DIR__ . "/../backend/partials/profesor_bootstrap.php";
require_once __DIR__ . "/../backend/config/eventos_institucionales.php";

// ==================================================
// CALENDARIO DEL PROFESOR — solo lectura.
//
// Muestra, en una sola vista:
//   1. Los eventos institucionales "de todo el colegio" (creados
//      por Admin/Subdirección sin un grado puntual) + los dirigidos
//      a cualquiera de los grados que este profesor tiene asignados
//      (ver eventos_listar_mes(), filtro por $idsNivelGradoProfesor).
//   2. Sus propios Trabajos (fecha_limite) como "entregas", leídos
//      en vivo de la tabla `trabajos` — la misma que ya usa
//      profesor/trabajos.php, sin duplicar nada.
//
// El profesor no puede crear ni eliminar eventos institucionales
// desde acá (eso es de Admin/Subdirección); sus Trabajos los sigue
// creando/editando en profesor/trabajos.php como siempre.
// ==================================================

$idProfesor = (int) $profesor["id_profesor"];
$gradosAsignados = profesor_grados_asignados($conexion, $idProfesor);
$idsNivelGradoProfesor = array_map(fn($ng) => (int) $ng["id_nivel_grado"], $gradosAsignados);

$hoy = new DateTimeImmutable("today");
$anio = (int) ($_GET["anio"] ?? $hoy->format("Y"));
$mes = (int) ($_GET["mes"] ?? $hoy->format("n"));

if ($mes < 1 || $mes > 12) {
    $mes = (int) $hoy->format("n");
    $anio = (int) $hoy->format("Y");
}

$primerDiaMes = new DateTimeImmutable(sprintf("%04d-%02d-01", $anio, $mes));
$mesAnterior = $primerDiaMes->modify("-1 month");
$mesSiguiente = $primerDiaMes->modify("+1 month");
$tituloMes = ucfirst(mes_nombre_es($mes)) . " de " . $anio;

$semanas = calendario_grid_semanas($anio, $mes);

$eventosInstitucionales = eventos_listar_mes($conexion, $anio, $mes, $idsNivelGradoProfesor);
$entregasTrabajos = eventos_trabajos_mes($conexion, $idProfesor, $anio, $mes);
$eventos = array_merge($eventosInstitucionales, $entregasTrabajos);

usort($eventos, fn($a, $b) => $a["fecha_inicio"] <=> $b["fecha_inicio"]);

$eventosPorDia = eventos_agrupar_por_dia($eventos);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendario · Panel del Profesor - I.E.P. 88044 Abraham Valdelomar</title>
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
        <h1><?= icon("calendar") ?> Calendario</h1>
    </div>
    <div class="header-actions">
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <?php if (count($gradosAsignados) === 0): ?>
        <div class="panel-alert panel-alert-error">Todavía no tienes ningún grado asignado, así que solo se muestran los eventos de todo el colegio.</div>
    <?php endif; ?>

    <div class="cal-toolbar">
        <h2><?= htmlspecialchars($tituloMes) ?></h2>
        <div class="cal-nav">
            <a class="cal-nav-btn" href="calendario.php?anio=<?= $mesAnterior->format("Y") ?>&mes=<?= (int) $mesAnterior->format("n") ?>" aria-label="Mes anterior"><?= icon("chevron-left") ?></a>
            <a class="cal-today-btn" href="calendario.php">Hoy</a>
            <a class="cal-nav-btn cal-next" href="calendario.php?anio=<?= $mesSiguiente->format("Y") ?>&mes=<?= (int) $mesSiguiente->format("n") ?>" aria-label="Mes siguiente"><?= icon("chevron-left") ?></a>
        </div>
    </div>

    <p class="placeholder-text">Exámenes, reuniones, feriados y actividades del colegio para tu(s) grado(s), más tus propias fechas de entrega de Trabajos.</p>

    <div class="cal-legend">
        <?php foreach (["EXAMEN", "REUNION", "FERIADO", "ACTIVIDAD", "ENTREGA", "OTRO"] as $t): $m = eventos_tipo_meta($t); ?>
            <span class="cal-legend-pill <?= $m["clase"] ?>"><?= htmlspecialchars($m["label"]) ?></span>
        <?php endforeach; ?>
    </div>

    <div class="cal-grid">
        <?php foreach (["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"] as $diaNombre): ?>
            <div class="cal-weekday"><?= $diaNombre ?></div>
        <?php endforeach; ?>

        <?php foreach ($semanas as $semana): foreach ($semana as $dia): ?>
            <?php if ($dia === null): ?>
                <div class="cal-day is-empty"></div>
            <?php else:
                $clave = $dia->format("Y-m-d");
                $esHoy = $clave === $hoy->format("Y-m-d");
                $eventosDelDia = $eventosPorDia[$clave] ?? [];
            ?>
                <div class="cal-day<?= $esHoy ? " is-today" : "" ?>">
                    <span class="cal-day-num"><?= (int) $dia->format("j") ?></span>
                    <div class="cal-day-events">
                        <?php foreach (array_slice($eventosDelDia, 0, 3) as $ev): $m = eventos_tipo_meta($ev["tipo"]); ?>
                            <span class="cal-event <?= $m["clase"] ?>" title="<?= htmlspecialchars($ev["titulo"]) ?>"><?= htmlspecialchars($ev["titulo"]) ?></span>
                        <?php endforeach; ?>
                        <?php if (count($eventosDelDia) > 3): ?>
                            <span class="cal-event-more">+<?= count($eventosDelDia) - 3 ?> más</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; endforeach; ?>
    </div>

    <h2>Eventos de <?= htmlspecialchars($tituloMes) ?> (<?= count($eventos) ?>)</h2>

    <?php if (count($eventos) === 0): ?>
        <p class="placeholder-text">No tienes eventos ni entregas para este mes.</p>
    <?php else: ?>
        <div class="cal-agenda">
            <?php foreach ($eventos as $ev): $m = eventos_tipo_meta($ev["tipo"]); $fIni = new DateTimeImmutable($ev["fecha_inicio"]); ?>
                <div class="cal-agenda-item">
                    <div class="cal-agenda-date">
                        <strong><?= $fIni->format("d") ?></strong>
                        <span><?= mes_abrev_es((int) $fIni->format("n")) ?></span>
                    </div>
                    <div class="cal-agenda-body">
                        <h4><?= htmlspecialchars($ev["titulo"]) ?></h4>
                        <?php if (!empty($ev["descripcion"])): ?>
                            <p><?= htmlspecialchars($ev["descripcion"]) ?></p>
                        <?php endif; ?>
                        <div class="cal-agenda-meta">
                            <span class="cal-agenda-tipo <?= $m["clase"] ?>"><?= htmlspecialchars($m["label"]) ?></span>
                            <?php if (empty($ev["es_trabajo"])): ?>
                                <span class="cal-agenda-grado"><?= $ev["id_nivel_grado"] ? htmlspecialchars(nivel_grado_label(["nombre" => $ev["grado_nombre"], "nivel" => $ev["grado_nivel"]])) : "Todo el colegio" ?></span>
                            <?php else: ?>
                                <a href="trabajos.php" class="btn-mini">Ver en Mis Trabajos</a>
                            <?php endif; ?>
                        </div>
                    </div>
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
