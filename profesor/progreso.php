<?php

require_once __DIR__ . "/../backend/partials/profesor_bootstrap.php";
require_once __DIR__ . "/../backend/config/profesor_tutoria.php";

// ==================================================
// "MI PROGRESO" — resumen de PCA + Unidades + Sesiones de TODOS
// los grados del profesor, más Tutoría (si tiene), en una sola
// pantalla. Antes había que entrar grado por grado (o a
// tutoria.php) para ver cada barra por separado.
//
// Mismo criterio de avance que ya usan profesor/dashboard.php y
// backend/reportes/avance_pdf.php — ninguna tabla ni columna
// nueva, solo lectura.
// ==================================================

$gradosAsignados = profesor_grados_asignados($conexion, $profesor["id_profesor"]);
$idProfesor = (int) $profesor["id_profesor"];

$avancePcaPorGrado = [];
$avanceUnidadesPorGrado = [];
$avanceSesionesPorGrado = [];
$totalCursosPorGrado = [];

foreach ($gradosAsignados as $ng) {
    $totalCursosPorGrado[(int) $ng["id_nivel_grado"]] = count($ng["cursos"]);
}

$idsNivelGradoProfesor = array_map(fn($ng) => (int) $ng["id_nivel_grado"], $gradosAsignados);

if (count($idsNivelGradoProfesor) > 0) {

    $marcadores = implode(",", array_fill(0, count($idsNivelGradoProfesor), "?"));

    $stmt = $conexion->prepare("
        SELECT id_nivel_grado, COUNT(DISTINCT unidad) AS total
        FROM profesor_pca_archivos
        WHERE id_profesor = ? AND id_nivel_grado IN ($marcadores)
        GROUP BY id_nivel_grado
    ");
    $stmt->execute(array_merge([$idProfesor], $idsNivelGradoProfesor));
    foreach ($stmt->fetchAll() as $fila) {
        $avancePcaPorGrado[(int) $fila["id_nivel_grado"]] = (int) $fila["total"];
    }

    $stmt = $conexion->prepare("
        SELECT id_nivel_grado, COUNT(DISTINCT curso) AS total
        FROM profesor_unidad_archivos
        WHERE id_profesor = ? AND id_nivel_grado IN ($marcadores) AND unidad = 1 AND semana = 1
        GROUP BY id_nivel_grado
    ");
    $stmt->execute(array_merge([$idProfesor], $idsNivelGradoProfesor));
    foreach ($stmt->fetchAll() as $fila) {
        $avanceUnidadesPorGrado[(int) $fila["id_nivel_grado"]] = (int) $fila["total"];
    }

    $stmt = $conexion->prepare("
        SELECT id_nivel_grado, unidad, semana, COUNT(DISTINCT curso) AS cursos_cubiertos
        FROM profesor_sesion_archivos
        WHERE id_profesor = ? AND id_nivel_grado IN ($marcadores)
        GROUP BY id_nivel_grado, unidad, semana
    ");
    $stmt->execute(array_merge([$idProfesor], $idsNivelGradoProfesor));
    foreach ($stmt->fetchAll() as $fila) {
        $idNg = (int) $fila["id_nivel_grado"];
        $meta = $totalCursosPorGrado[$idNg] ?? 0;
        if ($meta > 0 && (int) $fila["cursos_cubiertos"] >= $meta) {
            $avanceSesionesPorGrado[$idNg] = ($avanceSesionesPorGrado[$idNg] ?? 0) + 1;
        }
    }

}

// ==================================================
// TUTORÍA (solo si tiene una asignación vigente este año)
// ==================================================

$tutoria = profesor_tutoria_actual($conexion, $idProfesor);
$avanceTutoria = null;

if ($tutoria) {

    $idGradoSeccion = (int) $tutoria["id_grado_seccion"];
    $idPeriodo = (int) $tutoria["id_periodo"];
    $totalTutoriaPca = pca_total_unidades($tutoria["nivel"]);
    $totalTutoriaUnidSes = materiales_total_unidades($tutoria["nivel"]) * materiales_total_semanas($tutoria["nivel"]);

    $stmt = $conexion->prepare("SELECT COUNT(DISTINCT unidad) FROM profesor_tutoria_pca_archivos WHERE id_profesor = ? AND id_grado_seccion = ? AND id_periodo = ?");
    $stmt->execute([$idProfesor, $idGradoSeccion, $idPeriodo]);
    $avanceTutoriaPca = (int) $stmt->fetchColumn();

    $stmt = $conexion->prepare("SELECT COUNT(*) FROM profesor_tutoria_unidad_archivos WHERE id_profesor = ? AND id_grado_seccion = ? AND id_periodo = ?");
    $stmt->execute([$idProfesor, $idGradoSeccion, $idPeriodo]);
    $avanceTutoriaUnidades = (int) $stmt->fetchColumn();

    $stmt = $conexion->prepare("SELECT COUNT(*) FROM profesor_tutoria_sesion_archivos WHERE id_profesor = ? AND id_grado_seccion = ? AND id_periodo = ?");
    $stmt->execute([$idProfesor, $idGradoSeccion, $idPeriodo]);
    $avanceTutoriaSesiones = (int) $stmt->fetchColumn();

    $avanceTutoria = [
        "pca" => $avanceTutoriaPca, "total_pca" => $totalTutoriaPca,
        "unidades" => $avanceTutoriaUnidades, "total_unidades" => $totalTutoriaUnidSes,
        "sesiones" => $avanceTutoriaSesiones, "total_sesiones" => $totalTutoriaUnidSes,
    ];

}

/**
 * Fila de progreso reutilizable: etiqueta + "avance/total" + barra
 * con el mismo semáforo que ya usa el resto del sistema.
 */
function progreso_barra(string $etiqueta, int $avance, int $total, string $unidadLabel): string {
    $pct = $total > 0 ? min(100, (int) round($avance / $total * 100)) : 0;
    $clase = materiales_color_progreso($pct);
    return '<div class="card-progress">'
        . '<div class="card-progress-head"><span>' . htmlspecialchars($etiqueta) . '</span>'
        . '<span>' . $avance . '/' . $total . ' ' . htmlspecialchars($unidadLabel) . '</span></div>'
        . '<div class="progress-bar"><div class="progress-bar-fill ' . $clase . '" style="width:' . $pct . '%"></div></div>'
        . '</div>';
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi progreso · Panel del Profesor - I.E.P. 88044 Abraham Valdelomar</title>
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
        <h1><?= icon("bar-chart") ?> Mi progreso</h1>
    </div>
    <div class="header-actions">
        <a href="../backend/reportes/avance_pdf.php?descargar=1" target="_blank" class="btn-secondary">
            <?= icon("file-text") ?> Descargar (PDF)
        </a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <section class="panel-section">
        <div class="panel-section-head">
            <h2>Todos tus grados, de un vistazo</h2>
            <p class="panel-section-sub">
                PCA, Unidades y Sesiones de cada grado que tienes asignado<?= $tutoria ? ", más tu Tutoría" : "" ?> — sin
                tener que entrar grado por grado.
            </p>
        </div>

        <?php if (count($gradosAsignados) === 0): ?>

            <div class="empty-state">
                <span class="empty-state-icon" aria-hidden="true"><?= icon("backpack") ?></span>
                <h2>Todavía no tienes grados asignados</h2>
                <p>Pide al administrador que te asigne un aula desde «Gestionar profesores».</p>
            </div>

        <?php else: ?>

            <div class="cards-grados">
                <?php foreach ($gradosAsignados as $ng): ?>
                    <?php
                        $idNg = (int) $ng["id_nivel_grado"];

                        $avancePca = $avancePcaPorGrado[$idNg] ?? 0;
                        $totalPca = pca_total_unidades($ng["nivel"]);

                        $avanceUnidades = $avanceUnidadesPorGrado[$idNg] ?? 0;
                        // Mismo criterio que dashboard.php: el total es el
                        // de cursos del NIVEL completo (7 Primaria / 10
                        // Secundaria, materiales_total_cursos()), no la
                        // cantidad de cursos que profesor_curso_grado tenga
                        // cargados para este profesor puntualmente.
                        $totalUnidadesGrado = materiales_total_cursos($ng["nivel"]);

                        $avanceSesiones = $avanceSesionesPorGrado[$idNg] ?? 0;
                        $totalSesionesGrado = materiales_total_unidades($ng["nivel"]) * materiales_total_semanas($ng["nivel"]);
                    ?>
                    <div class="card">
                        <h3><?= htmlspecialchars(strtoupper($ng["nombre"])) ?> <?= htmlspecialchars(strtoupper(ucfirst(strtolower($ng["nivel"])))) ?></h3>

                        <?= progreso_barra("PCA", $avancePca, $totalPca, "archivo") ?>
                        <?= progreso_barra("Unidades", $avanceUnidades, $totalUnidadesGrado, "cursos") ?>
                        <?= progreso_barra("Sesiones", $avanceSesiones, $totalSesionesGrado, "sesiones") ?>

                        <div class="card-foot">
                            <span class="placeholder-text"><?= count($ng["cursos"]) ?> <?= count($ng["cursos"]) === 1 ? "curso" : "cursos" ?></span>
                            <a href="grado.php?id_nivel_grado=<?= $idNg ?>">Ir al grado</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </section>

    <?php if ($avanceTutoria): ?>

        <section class="panel-section">
            <div class="panel-section-head">
                <h2><?= icon("graduation") ?> Tutoría</h2>
                <p class="panel-section-sub">
                    <?= htmlspecialchars(strtoupper($tutoria["nombre"])) ?> Secundaria · <?= htmlspecialchars($tutoria["nombre_periodo"]) ?>
                    — independiente de tus cursos y áreas normales.
                </p>
            </div>

            <div class="cards-grados">
                <div class="card">
                    <?= progreso_barra("PCA", $avanceTutoria["pca"], $avanceTutoria["total_pca"], "archivo") ?>
                    <?= progreso_barra("Unidades", $avanceTutoria["unidades"], $avanceTutoria["total_unidades"], "archivos") ?>
                    <?= progreso_barra("Sesiones", $avanceTutoria["sesiones"], $avanceTutoria["total_sesiones"], "archivos") ?>
                    <div class="card-foot">
                        <a href="tutoria.php">Ir a Tutoría</a>
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
