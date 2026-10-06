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
require_once "../backend/config/materiales_cursos.php";
require_once "../backend/config/seguridad_csrf.php";
require_once "../backend/config/flash.php";
require_once "../backend/config/subdireccion_seguimiento.php";
[$mensaje, $mensajeTipo] = flash_get();

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["accion"] ?? "") === "recordar") {

    csrf_verificar();

    $idUsuarioProfesor = (int) ($_POST["id_usuario_profesor"] ?? 0);
    $nombreProfesor = trim($_POST["nombre_profesor"] ?? "");
    $gradoTexto = trim($_POST["grado_texto"] ?? "");

    if ($idUsuarioProfesor > 0) {

        $mensajeRecordatorio = $gradoTexto !== ""
            ? "Subdirección te recuerda ponerte al día con PCA/Unidades/Sesiones de $gradoTexto."
            : "Subdirección te recuerda ponerte al día con tus materiales pendientes.";

        subdireccion_enviar_recordatorio($conexion, $idUsuarioProfesor, $mensajeRecordatorio, "dashboard.php");

        $mensaje = $nombreProfesor !== ""
            ? "Recordatorio enviado a $nombreProfesor."
            : "Recordatorio enviado.";
        $mensajeTipo = "success";

    } else {

        $mensaje = "No se pudo identificar al profesor.";
        $mensajeTipo = "error";

    }

    flash_set($mensaje, $mensajeTipo);
    header("Location: reportes.php");
    exit;

}

$totalProfesores = (int)$conexion->query("SELECT COUNT(*) FROM profesores")->fetchColumn();
$totalDocumentos = (int)$conexion->query("SELECT COUNT(*) FROM documentos")->fetchColumn();
$totalComunicados = (int)$conexion->query("SELECT COUNT(*) FROM comunicados")->fetchColumn();
$totalSolicitudesPendientes = (int)$conexion->query("SELECT COUNT(*) FROM solicitudes WHERE estado = 'PENDIENTE'")->fetchColumn();

$solicitudesPorEstado = $conexion->query("
    SELECT estado, COUNT(*) AS total FROM solicitudes GROUP BY estado
")->fetchAll();

// ==================================================
// AVANCE DE PCA / UNIDADES / SESIONES POR PROFESOR Y GRADO
//
// Mismo criterio y las mismas tablas que ya usa el Dashboard del
// Profesor (ver profesor/dashboard.php) y el reporte de PCA en modo
// Admin/Subdirector (backend/reportes/pca_pdf.php), solo que aquí se
// trae de TODOS los profesores a la vez (sin "WHERE id_profesor = ?")
// para que Subdirección vea de un vistazo quién va atrasado sin
// entrar profesor por profesor. Todo por grado, nunca un contador
// global del profesor.
// ==================================================

$filasBase = $conexion->query("
    SELECT
        pr.id_profesor, u.id_usuario AS id_usuario_profesor, u.nombres, u.apellidos,
        ng.id_nivel_grado, ng.nivel, ng.grado, ng.nombre AS grado_nombre,
        COUNT(DISTINCT ppa.unidad) AS avance_pca
    FROM profesores pr
    INNER JOIN usuarios u ON u.id_usuario = pr.id_usuario
    INNER JOIN asignaciones_docentes ad ON ad.id_profesor = pr.id_profesor
    INNER JOIN grados_secciones gs ON gs.id_grado_seccion = ad.id_grado_seccion
    INNER JOIN niveles_grados ng
        ON ng.nivel = gs.nivel COLLATE utf8mb4_unicode_ci AND ng.grado = gs.grado
    LEFT JOIN profesor_pca_archivos ppa
        ON ppa.id_profesor = pr.id_profesor AND ppa.id_nivel_grado = ng.id_nivel_grado
    GROUP BY pr.id_profesor, ng.id_nivel_grado
    ORDER BY u.apellidos, u.nombres, ng.nivel, ng.grado
")->fetchAll();

// Cuántas filas (grados) tiene cada profesor en $filasBase, para agrupar
// visualmente su nombre con rowspan en vez de repetirlo en cada fila.
$filasPorProfesor = [];
foreach ($filasBase as $f) {
    $filasPorProfesor[$f["id_profesor"]] = ($filasPorProfesor[$f["id_profesor"]] ?? 0) + 1;
}

// Cursos que cada profesor dicta en cada uno de sus grados (fuente:
// profesor_curso_grado, nunca asignaciones_docentes/secciones) — es
// la "meta" para considerar una Unidad/Sesión completa: mismo
// criterio que profesor/dashboard.php.
$totalCursosPorProfesorGrado = [];
foreach ($conexion->query("
    SELECT id_profesor, id_nivel_grado, COUNT(DISTINCT id_curso) AS total
    FROM profesor_curso_grado
    GROUP BY id_profesor, id_nivel_grado
")->fetchAll() as $fila) {
    $totalCursosPorProfesorGrado[$fila["id_profesor"] . "-" . $fila["id_nivel_grado"]] = (int) $fila["total"];
}

// UNIDADES: lista plana (1 archivo por curso, unidad/semana fijos
// en 1, ver profesor/unidades.php) — el avance es directamente
// cuántos cursos distintos tienen archivo, sin exigir
// "completitud por unidad" (ya no hay nada que completar por
// unidad: cada curso es independiente).
$avanceUnidadesPorProfesorGrado = [];
foreach ($conexion->query("
    SELECT id_profesor, id_nivel_grado, COUNT(DISTINCT curso) AS total
    FROM profesor_unidad_archivos
    WHERE unidad = 1 AND semana = 1
    GROUP BY id_profesor, id_nivel_grado
")->fetchAll() as $fila) {
    $avanceUnidadesPorProfesorGrado[$fila["id_profesor"] . "-" . $fila["id_nivel_grado"]] = (int) $fila["total"];
}

$avanceSesionesPorProfesorGrado = [];
foreach ($conexion->query("
    SELECT id_profesor, id_nivel_grado, unidad, semana, COUNT(DISTINCT curso) AS cursos_cubiertos
    FROM profesor_sesion_archivos
    GROUP BY id_profesor, id_nivel_grado, unidad, semana
")->fetchAll() as $fila) {
    $clave = $fila["id_profesor"] . "-" . $fila["id_nivel_grado"];
    $meta = $totalCursosPorProfesorGrado[$clave] ?? 0;
    if ($meta > 0 && (int) $fila["cursos_cubiertos"] >= $meta) {
        $avanceSesionesPorProfesorGrado[$clave] = ($avanceSesionesPorProfesorGrado[$clave] ?? 0) + 1;
    }
}

// ==================================================
// PRECÁLCULO: porcentaje + color de semáforo por cada fila
// profesor×grado, UNA sola vez acá (antes se calculaba inline dentro
// del loop de la tabla). Reutilizado tanto por el semáforo como por
// la tabla detallada, para que nunca puedan mostrar números
// distintos por estar calculados dos veces.
// ==================================================
foreach ($filasBase as &$f) {

    $clave = $f["id_profesor"] . "-" . $f["id_nivel_grado"];

    $f["avance_pca"] = (int) $f["avance_pca"];
    $f["total_pca"] = pca_total_unidades($f["nivel"]);
    $f["pct_pca"] = $f["total_pca"] > 0 ? min(100, (int) round($f["avance_pca"] / $f["total_pca"] * 100)) : 0;

    $f["avance_unidades"] = $avanceUnidadesPorProfesorGrado[$clave] ?? 0;
    $f["total_unidades"] = materiales_total_cursos($f["nivel"]);
    $f["pct_unidades"] = $f["total_unidades"] > 0 ? min(100, (int) round($f["avance_unidades"] / $f["total_unidades"] * 100)) : 0;

    $f["avance_sesiones"] = $avanceSesionesPorProfesorGrado[$clave] ?? 0;
    $f["total_sesiones"] = materiales_total_unidades($f["nivel"]) * materiales_total_semanas($f["nivel"]);
    $f["pct_sesiones"] = $f["total_sesiones"] > 0 ? min(100, (int) round($f["avance_sesiones"] / $f["total_sesiones"] * 100)) : 0;

    $f["color_semaforo"] = semaforo_color_fila($f["pct_pca"], $f["pct_unidades"], $f["pct_sesiones"]);

}
unset($f);

// Resumen por profesor (para las tarjetas del semáforo): el color
// más urgente entre todos sus grados, y si tiene AL MENOS un grado
// en rojo/ámbar (para decidir si mostrarle el botón "Recordar").
$resumenPorProfesor = [];
foreach ($filasBase as $f) {
    $idProf = $f["id_profesor"];
    if (!isset($resumenPorProfesor[$idProf])) {
        $resumenPorProfesor[$idProf] = [
            "id_profesor" => $idProf,
            "id_usuario_profesor" => $f["id_usuario_profesor"],
            "nombre" => $f["nombres"] . " " . $f["apellidos"],
            "grados" => [],
            "peor_color" => "is-green",
        ];
    }
    $resumenPorProfesor[$idProf]["grados"][] = $f["grado_nombre"] . " · " . ucfirst(strtolower($f["nivel"]));
    $orden = ["is-red" => 0, "is-amber" => 1, "is-green" => 2];
    if ($orden[$f["color_semaforo"]] < $orden[$resumenPorProfesor[$idProf]["peor_color"]]) {
        $resumenPorProfesor[$idProf]["peor_color"] = $f["color_semaforo"];
    }
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes · Panel del Subdirector - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">


<header>
    <div>
        <h1>Reportes institucionales</h1>
        <p>Panel del Subdirector · I.E.P. 88044 Abraham Valdelomar</p>
    </div>
    <div class="header-actions">
        <a href="../backend/reportes/alumnos_pdf.php?descargar=1" target="_blank"><?= icon("file-text") ?> Alumnos (PDF)</a>
        <a href="../backend/reportes/trabajos_pdf.php?descargar=1" target="_blank"><?= icon("file-text") ?> Trabajos (PDF)</a>
        <a href="../backend/reportes/pca_pdf.php?descargar=1" target="_blank"><?= icon("file-text") ?> Avance PCA (PDF)</a>
        <a href="../backend/reportes/avance_pdf.php?descargar=1" target="_blank"><?= icon("file-text") ?> Avance general (PDF)</a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-value"><?= $totalProfesores ?></span>
            <span class="stat-label">Profesores</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= $totalDocumentos ?></span>
            <span class="stat-label">Documentos</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= $totalComunicados ?></span>
            <span class="stat-label">Comunicados</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= $totalSolicitudesPendientes ?></span>
            <span class="stat-label">Solicitudes pendientes</span>
        </div>
    </div>

    <h2>Semáforo de cumplimiento</h2>
    <p class="placeholder-text">Un vistazo rápido por profesor: verde = al día, ámbar = avanzando, rojo = atrasado en al menos un grado (PCA, Unidades o Sesiones).</p>
    <?php if (count($resumenPorProfesor) === 0): ?>
        <p class="placeholder-text">Sin profesores con grados asignados todavía.</p>
    <?php else: ?>
        <div class="semaforo-grid">
            <?php foreach ($resumenPorProfesor as $rp): ?>
                <div class="semaforo-card">
                    <div class="semaforo-card-head">
                        <span class="leyenda-punto <?= $rp["peor_color"] ?>"></span>
                        <strong><?= htmlspecialchars($rp["nombre"]) ?></strong>
                    </div>
                    <p><?= htmlspecialchars(implode(" · ", $rp["grados"])) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2>Avance de PCA, Unidades y Sesiones por profesor</h2>
    <?php if (count($filasBase) === 0): ?>
        <p class="placeholder-text">Sin profesores con grados asignados todavía.</p>
    <?php else: ?>
        <div class="panel-form" style="margin-bottom:16px">
            <div class="form-row">
                <div class="field">
                    <label for="filtro-nivel-avance">Nivel</label>
                    <select id="filtro-nivel-avance">
                        <option value="">Todos</option>
                        <option value="PRIMARIA">Primaria</option>
                        <option value="SECUNDARIA">Secundaria</option>
                    </select>
                </div>
                <div class="field">
                    <label for="filtro-grado-avance">Grado</label>
                    <select id="filtro-grado-avance">
                        <option value="">Todos</option>
                        <option value="1">1°</option>
                        <option value="2">2°</option>
                        <option value="3">3°</option>
                        <option value="4">4°</option>
                        <option value="5">5°</option>
                        <option value="6">6°</option>
                    </select>
                </div>
            </div>
        </div>
        <p id="sin-resultados-avance" class="placeholder-text" style="display:none">
            Ningún profesor coincide con ese filtro.
        </p>
        <div class="table-wrap">
            <table class="data-table" id="tabla-avance">
                <thead>
                    <tr>
                        <th>Profesor</th>
                        <th>Nivel · Grado</th>
                        <th>PCA</th>
                        <th>Unidades</th>
                        <th>Sesiones</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__profesorAnterior = null; ?>
                    <?php foreach ($filasBase as $f): ?>
                        <?php
                            $esPrimeraFilaDelProfesor = $f["id_profesor"] !== $__profesorAnterior;
                            $__profesorAnterior = $f["id_profesor"];
                        ?>
                        <tr id="fila-<?= (int) $f['id_profesor'] ?>-<?= (int) $f['id_nivel_grado'] ?>" class="<?= $esPrimeraFilaDelProfesor ? "reportes-profesor-inicio" : "" ?>" data-nivel="<?= htmlspecialchars($f["nivel"]) ?>" data-grado="<?= (int) $f["grado"] ?>">
                            <?php if ($esPrimeraFilaDelProfesor): ?>
                                <td class="reportes-profesor-celda" rowspan="<?= $filasPorProfesor[$f["id_profesor"]] ?>">
                                    <?= htmlspecialchars($f["nombres"] . " " . $f["apellidos"]) ?>
                                    <?php if ($filasPorProfesor[$f["id_profesor"]] > 1): ?>
                                        <span class="reportes-grados-count"><?= $filasPorProfesor[$f["id_profesor"]] ?> grados</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td>
                                <span class="leyenda-punto <?= $f["color_semaforo"] ?>" title="Semáforo de cumplimiento"></span>
                                <?= htmlspecialchars($f["grado_nombre"]) ?> · <?= htmlspecialchars(ucfirst(strtolower($f["nivel"]))) ?>
                            </td>
                            <td>
                                <div class="card-progress-head"><span><?= $f["avance_pca"] ?>/<?= $f["total_pca"] ?></span><span><?= $f["pct_pca"] ?>%</span></div>
                                <div class="progress-bar"><div class="progress-bar-fill <?= materiales_color_progreso($f["pct_pca"]) ?>" style="width:<?= $f["pct_pca"] ?>%"></div></div>
                            </td>
                            <td>
                                <div class="card-progress-head"><span><?= $f["avance_unidades"] ?>/<?= $f["total_unidades"] ?></span><span><?= $f["pct_unidades"] ?>%</span></div>
                                <div class="progress-bar"><div class="progress-bar-fill <?= materiales_color_progreso($f["pct_unidades"]) ?>" style="width:<?= $f["pct_unidades"] ?>%"></div></div>
                            </td>
                            <td>
                                <div class="card-progress-head"><span><?= $f["avance_sesiones"] ?>/<?= $f["total_sesiones"] ?></span><span><?= $f["pct_sesiones"] ?>%</span></div>
                                <div class="progress-bar"><div class="progress-bar-fill <?= materiales_color_progreso($f["pct_sesiones"]) ?>" style="width:<?= $f["pct_sesiones"] ?>%"></div></div>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <a class="btn-mini" href="historial.php?id_profesor=<?= (int) $f['id_profesor'] ?>&id_nivel_grado=<?= (int) $f['id_nivel_grado'] ?>">Historial</a>
                                    <a class="btn-mini" href="bitacora.php?id_profesor=<?= (int) $f['id_profesor'] ?>">Bitácora</a>
                                    <?php if ($f["color_semaforo"] !== "is-green"): ?>
                                        <form method="POST" onsubmit="return confirm('¿Enviar un recordatorio a <?= htmlspecialchars(addslashes($f['nombres'] . ' ' . $f['apellidos'])) ?>?');">
                                        <?= csrf_field() ?>
                                            <input type="hidden" name="accion" value="recordar">
                                            <input type="hidden" name="id_usuario_profesor" value="<?= (int) $f['id_usuario_profesor'] ?>">
                                            <input type="hidden" name="nombre_profesor" value="<?= htmlspecialchars($f['nombres'] . ' ' . $f['apellidos']) ?>">
                                            <input type="hidden" name="grado_texto" value="<?= htmlspecialchars($f['grado_nombre'] . ' ' . ucfirst(strtolower($f['nivel']))) ?>">
                                            <button type="submit" class="btn-mini btn-mini-reject"><?= icon("bell") ?> Recordar</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2>Solicitudes por estado</h2>
    <?php if (count($solicitudesPorEstado) === 0): ?>
        <p class="placeholder-text">Sin datos todavía.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Estado</th><th>Cantidad</th></tr></thead>
                <tbody>
                    <?php foreach ($solicitudesPorEstado as $s): ?>
                        <tr><td><?= htmlspecialchars($s['estado']) ?></td><td><?= (int)$s['total'] ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>
<script>
// Filtro Nivel/Grado de la tabla de Avance (solo esconde filas ya
// renderizadas, no toca el servidor ni la BD). Como el nombre del
// profesor usa rowspan, cuando el filtro deja visible solo parte de
// las filas de un profesor, esa celda se reubica en la primera fila
// que siga visible y su rowspan se recalcula.
(function () {
    var filtroNivel = document.getElementById("filtro-nivel-avance");
    var filtroGrado = document.getElementById("filtro-grado-avance");
    var tabla = document.getElementById("tabla-avance");
    var sinResultados = document.getElementById("sin-resultados-avance");
    if (!tabla || !filtroNivel) return;

    var tbody = tabla.querySelector("tbody");
    var filas = Array.prototype.slice.call(tbody.querySelectorAll("tr"));

    var grupos = [];
    var grupoActual = null;
    filas.forEach(function (fila) {
        var celdaNombre = fila.querySelector(".reportes-profesor-celda");
        if (celdaNombre) {
            celdaNombre.parentNode.removeChild(celdaNombre);
            grupoActual = { celda: celdaNombre, filas: [] };
            grupos.push(grupoActual);
        }
        grupoActual.filas.push(fila);
    });

    function aplicarFiltro() {
        var nivel = filtroNivel.value;
        var grado = filtroGrado.value;
        var totalVisibles = 0;

        grupos.forEach(function (grupo) {
            var visibles = grupo.filas.filter(function (fila) {
                var coincideNivel = !nivel || fila.dataset.nivel === nivel;
                var coincideGrado = !grado || fila.dataset.grado === grado;
                return coincideNivel && coincideGrado;
            });

            grupo.filas.forEach(function (fila) {
                var visible = visibles.indexOf(fila) !== -1;
                fila.style.display = visible ? "" : "none";
                if (fila.contains(grupo.celda)) {
                    fila.removeChild(grupo.celda);
                }
            });

            if (visibles.length > 0) {
                grupo.celda.setAttribute("rowspan", visibles.length);
                var contador = grupo.celda.querySelector(".reportes-grados-count");
                if (visibles.length > 1 && !contador) {
                    contador = document.createElement("span");
                    contador.className = "reportes-grados-count";
                    grupo.celda.appendChild(contador);
                }
                if (contador) {
                    contador.textContent = visibles.length + " grados";
                    contador.style.display = visibles.length > 1 ? "" : "none";
                }
                visibles[0].insertBefore(grupo.celda, visibles[0].firstChild);
                totalVisibles += visibles.length;
            }
        });

        sinResultados.style.display = totalVisibles === 0 ? "" : "none";
        tabla.style.display = totalVisibles === 0 ? "none" : "";
    }

    filtroNivel.addEventListener("change", aplicarFiltro);
    filtroGrado.addEventListener("change", aplicarFiltro);
})();
</script>

</body>
</html>
