<?php

require_once __DIR__ . "/../backend/partials/padre_bootstrap.php";

if (!$alumnoActivo) {
    header("Location: dashboard.php");
    exit;
}

$idAlumno = (int) $alumnoActivo["id_alumno"];

// ==================================================
// La boleta ya NO se muestra como tabla: el padre ve el PDF oficial
// (el que el Admin subió y Subdirección aprobó/publicó) y puede
// descargarlo. Solo cuentan las boletas en estado PUBLICADA que
// pertenezcan a este alumno (el resuelto por DNI al importar).
// El archivo se sirve por boleta_pdf.php, que vuelve a validar todo.
// ==================================================

$stmtBoletas = $conexion->prepare("
    SELECT b.id_boleta, b.id_periodo, per.nombre AS periodo_nombre,
           b.publicado_en, b.nombre_archivo_original,
           GROUP_CONCAT(DISTINCT e.bimestre ORDER BY e.bimestre SEPARATOR ',') AS bimestres,
           MAX(e.bimestre) AS ultimo_bimestre
    FROM boletas_importadas b
    INNER JOIN boletas_notas_extraidas e
            ON e.id_boleta = b.id_boleta AND e.id_alumno_resuelto = ?
    INNER JOIN periodos_academicos per ON per.id_periodo = b.id_periodo
    WHERE b.estado = 'PUBLICADA'
    GROUP BY b.id_boleta, b.id_periodo, per.nombre, b.publicado_en, b.nombre_archivo_original
    ORDER BY b.id_periodo DESC, ultimo_bimestre DESC, b.publicado_en DESC, b.id_boleta DESC
");
$stmtBoletas->execute([$idAlumno]);
$boletas = $stmtBoletas->fetchAll();

// Periodos que tienen boleta publicada
$periodosConBoleta = [];
foreach ($boletas as $b) {
    $periodosConBoleta[(int) $b["id_periodo"]] = $b["periodo_nombre"];
}

$idPeriodoSeleccionado = null;
if (isset($_GET["id_periodo"]) && isset($periodosConBoleta[(int) $_GET["id_periodo"]])) {
    $idPeriodoSeleccionado = (int) $_GET["id_periodo"];
}

// Si llegan con id_boleta (ej. desde la notificación "Ya están disponibles las notas..."),
// se abre esa boleta y su periodo, siempre que sea de este alumno y esté publicada.
$boletaSeleccionada = null;
if (isset($_GET["id_boleta"])) {
    foreach ($boletas as $b) {
        if ((int) $b["id_boleta"] === (int) $_GET["id_boleta"]) {
            $boletaSeleccionada = $b;
            $idPeriodoSeleccionado = (int) $b["id_periodo"];
            break;
        }
    }
}

if ($idPeriodoSeleccionado === null && count($boletas) > 0) {
    $idPeriodoSeleccionado = (int) $boletas[0]["id_periodo"];
}

$boletasDelPeriodo = array_values(array_filter($boletas, fn($b) => (int) $b["id_periodo"] === $idPeriodoSeleccionado));

if ($boletaSeleccionada === null && count($boletasDelPeriodo) > 0) {
    $boletaSeleccionada = $boletasDelPeriodo[0]; // la más reciente del periodo
}

function boleta_etiqueta_bimestres(string $csv): string {
    $b = array_filter(explode(",", $csv), "strlen");
    if (count($b) === 0) return "Boleta";
    return count($b) === 1 ? "Bimestre " . $b[0] : "Bimestres " . implode(", ", $b);
}

$qsBase = "id_alumno=" . (int) $idAlumno;
$urlPdf = $boletaSeleccionada
    ? "boleta_pdf.php?" . $qsBase . "&id_boleta=" . (int) $boletaSeleccionada["id_boleta"]
    : null;

?>



<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Boleta de notas - I.E.P. 88044 Abraham Valdelomar
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
        <h1>Boleta de notas</h1>
    </div>
</header>

<main>

    <section class="panel-section">
        <div class="panel-section-head">
            <h2><?= icon("backpack") ?> <?= htmlspecialchars(trim($alumnoActivo["nombres"] . " " . $alumnoActivo["apellidos"])) ?></h2>
            <p class="panel-section-sub">
                <?= $alumnoActivo["grado_nombre"] ? htmlspecialchars($alumnoActivo["grado_nombre"] . " " . ucfirst(strtolower($alumnoActivo["nivel"]))) : "Sin matrícula activa" ?>
            </p>
        </div>

        <?php if (count($periodosConBoleta) > 1): ?>
            <form method="get" class="filtro-inline">
                <input type="hidden" name="id_alumno" value="<?= (int) $idAlumno ?>">
                <label for="id_periodo">Periodo académico</label>
                <select name="id_periodo" id="id_periodo" onchange="this.form.submit()">
                    <?php foreach ($periodosConBoleta as $idP => $nombreP): ?>
                        <option value="<?= (int) $idP ?>" <?= (int) $idP === $idPeriodoSeleccionado ? "selected" : "" ?>>
                            <?= htmlspecialchars($nombreP) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </section>

    <?php if (!$boletaSeleccionada): ?>

        <div class="empty-state">
            <span class="empty-state-icon" aria-hidden="true"><?= icon("clipboard") ?></span>
            <h2>Boleta aún no disponible</h2>
            <p>Cuando el colegio publique la boleta de notas, podrás verla y descargarla aquí.</p>
        </div>

    <?php else: ?>

        <section class="panel-section">

            <div class="panel-section-head" style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px;">
                <div>
                    <h2><?= htmlspecialchars(boleta_etiqueta_bimestres($boletaSeleccionada["bimestres"])) ?></h2>
                    <p class="panel-section-sub">
                        <?= htmlspecialchars($boletaSeleccionada["periodo_nombre"]) ?>
                        <?php if ($boletaSeleccionada["publicado_en"]): ?>
                            · Publicada el <?= htmlspecialchars(date("d/m/Y", strtotime($boletaSeleccionada["publicado_en"]))) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div style="display:flex; flex-wrap:wrap; gap:8px;">
                    <a class="btn btn-primary" href="<?= htmlspecialchars($urlPdf . "&accion=descargar") ?>">Descargar PDF</a>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars($urlPdf . "&accion=ver") ?>" target="_blank" rel="noopener">Abrir en otra pestaña</a>
                </div>
            </div>

            <?php if (count($boletasDelPeriodo) > 1): ?>
                <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px;">
                    <?php foreach ($boletasDelPeriodo as $b): ?>
                        <?php $activa = (int) $b["id_boleta"] === (int) $boletaSeleccionada["id_boleta"]; ?>
                        <a class="btn <?= $activa ? "btn-primary" : "btn-secondary" ?>"
                           href="notas.php?<?= $qsBase ?>&id_boleta=<?= (int) $b["id_boleta"] ?>">
                            <?= htmlspecialchars(boleta_etiqueta_bimestres($b["bimestres"])) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="border:1px solid #d7e0f0; border-radius:12px; overflow:hidden; background:#fff;">
                <iframe
                    src="<?= htmlspecialchars($urlPdf . "&accion=ver") ?>#view=FitH"
                    title="Boleta de notas en PDF"
                    style="width:100%; height:80vh; min-height:520px; border:0; display:block;">
                </iframe>
            </div>

            <p class="placeholder-text" style="margin-top:10px;">
                Si el PDF no se ve en tu celular, usa <strong>Descargar PDF</strong> o <strong>Abrir en otra pestaña</strong>.
            </p>

        </section>

    <?php endif; ?>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>

</html>
