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
require_once "../backend/config/profesor_grados.php";
require_once "../backend/config/materiales_cursos.php";
require_once "../backend/config/materiales_historial.php";

$idProfesor = isset($_GET["id_profesor"]) ? (int) $_GET["id_profesor"] : 0;

$profesorSeleccionado = null;
if ($idProfesor > 0) {
    $stmt = $conexion->prepare("
        SELECT p.id_profesor, u.nombres, u.apellidos
        FROM profesores p
        INNER JOIN usuarios u ON p.id_usuario = u.id_usuario
        WHERE p.id_profesor = ?
    ");
    $stmt->execute([$idProfesor]);
    $profesorSeleccionado = $stmt->fetch();

    if (!$profesorSeleccionado) {
        $idProfesor = 0; // id inválido: se vuelve al selector, no se rompe la página
    }
}

$modulosValidos = ["PCA", "UNIDADES", "SESIONES"];
$moduloFiltro = $_GET["modulo"] ?? null;
if (!in_array($moduloFiltro, $modulosValidos, true)) {
    $moduloFiltro = null;
}

$idNivelGradoFiltro = isset($_GET["id_nivel_grado"]) && (int) $_GET["id_nivel_grado"] > 0
    ? (int) $_GET["id_nivel_grado"]
    : null;

$historial = [];
$gradosDelProfesor = [];

if ($profesorSeleccionado) {
    $gradosDelProfesor = profesor_grados_asignados($conexion, $idProfesor);
    $historial = materiales_historial_listar($conexion, $idProfesor, $moduloFiltro, $idNivelGradoFiltro, 100);
}

// Selector de profesor: solo se necesita si todavía no hay uno elegido.
$profesores = [];
if (!$profesorSeleccionado) {
    $profesores = $conexion->query("
        SELECT p.id_profesor, u.nombres, u.apellidos
        FROM profesores p
        INNER JOIN usuarios u ON p.id_usuario = u.id_usuario
        ORDER BY u.apellidos, u.nombres
    ")->fetchAll();
}

$etiquetasModulo = ["PCA" => "PCA", "UNIDADES" => "Unidades", "SESIONES" => "Sesiones"];
$etiquetasAccion = [
    "SUBIDO" => "Subido",
    "REEMPLAZADO" => "Reemplazado",
    "ELIMINADO" => "Eliminado",
];
$claseAccion = [
    "SUBIDO" => "status-aprobado",
    "REEMPLAZADO" => "status-requiere_cambios",
    "ELIMINADO" => "status-rechazado",
];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de materiales · Panel del Subdirector - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <h1>Historial de materiales</h1>
        <p>Panel del Subdirector · I.E.P. 88044 Abraham Valdelomar</p>
    </div>
    <div class="header-actions">
        <?php if ($profesorSeleccionado): ?>
            <a href="historial.php" class="btn-secondary">← Cambiar de profesor</a>
        <?php endif; ?>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <?php if (!$profesorSeleccionado): ?>

        <p class="placeholder-text" style="margin-bottom:20px;">
            Elige un profesor para ver el historial de subidas, reemplazos y eliminaciones de PCA, Unidades y Sesiones.
        </p>

        <?php if (count($profesores) === 0): ?>
            <p class="placeholder-text">Todavía no hay profesores registrados.</p>
        <?php else: ?>
            <div class="panel-form" style="margin-bottom:16px">
                <div class="form-row">
                    <div class="field">
                        <label for="buscar-profesor-historial">Buscar por nombre</label>
                        <input type="text" id="buscar-profesor-historial" placeholder="Ej. García">
                    </div>
                </div>
            </div>
            <p id="sin-resultados-profesores-historial" class="placeholder-text" style="display:none">
                Ningún profesor coincide con esa búsqueda.
            </p>
            <div class="table-wrap">
                <table class="data-table" id="tabla-profesores-historial">
                    <thead><tr><th>Profesor</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($profesores as $p): ?>
                            <tr data-nombre="<?= htmlspecialchars(mb_strtolower($p["nombres"] . " " . $p["apellidos"])) ?>">
                                <td><?= htmlspecialchars($p["nombres"] . " " . $p["apellidos"]) ?></td>
                                <td><a class="btn-mini" href="historial.php?id_profesor=<?= (int) $p["id_profesor"] ?>">Ver historial</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php else: ?>

        <h2><?= htmlspecialchars($profesorSeleccionado["nombres"] . " " . $profesorSeleccionado["apellidos"]) ?></h2>

        <p class="placeholder-text" style="margin-bottom:20px;">
            El archivo físico de una versión anterior ya no está disponible una vez reemplazado o
            eliminado; aquí solo queda el registro de qué se cambió y cuándo.
        </p>

        <form method="GET" class="panel-form" style="margin-bottom:20px;">
            <input type="hidden" name="id_profesor" value="<?= $idProfesor ?>">
            <div class="form-row">
                <div class="field">
                    <label for="modulo">Módulo</label>
                    <select name="modulo" id="modulo">
                        <option value="">Todos</option>
                        <?php foreach ($etiquetasModulo as $clave => $etiquetaModulo): ?>
                            <option value="<?= $clave ?>" <?= $moduloFiltro === $clave ? "selected" : "" ?>>
                                <?= htmlspecialchars($etiquetaModulo) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="id_nivel_grado">Grado</label>
                    <select name="id_nivel_grado" id="id_nivel_grado">
                        <option value="">Todos sus grados</option>
                        <?php foreach ($gradosDelProfesor as $ng): ?>
                            <option value="<?= (int) $ng["id_nivel_grado"] ?>" <?= $idNivelGradoFiltro === (int) $ng["id_nivel_grado"] ? "selected" : "" ?>>
                                <?= htmlspecialchars(nivel_grado_label($ng)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn-submit">Filtrar</button>
        </form>

        <?php if (count($historial) === 0): ?>

            <p class="placeholder-text">No hay cambios registrados todavía con este filtro.</p>

        <?php else: ?>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Módulo</th>
                            <th>Grado</th>
                            <th>Detalle</th>
                            <th>Archivo</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historial as $h): ?>
                            <?php
                                $detalle = "U" . (int) $h["unidad"];
                                if ($h["semana"] !== null) {
                                    $detalle .= " · Sem-0" . (int) $h["semana"];
                                }
                                if ($h["curso"] !== null) {
                                    $nombreCurso = materiales_curso_nombre($h["curso"], $h["nivel"]);
                                    $detalle .= " · " . ($nombreCurso ?? htmlspecialchars($h["curso"]));
                                }
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($h["creado_en"]) ?></td>
                                <td><?= htmlspecialchars($etiquetasModulo[$h["modulo"]] ?? $h["modulo"]) ?></td>
                                <td><?= htmlspecialchars($h["grado_nombre"]) ?> · <?= htmlspecialchars(ucfirst(strtolower($h["nivel"]))) ?></td>
                                <td><?= htmlspecialchars($detalle) ?></td>
                                <td title="<?= htmlspecialchars($h["nombre_archivo"]) ?>"><?= htmlspecialchars($h["nombre_archivo"]) ?></td>
                                <td>
                                    <span class="status-badge <?= $claseAccion[$h["accion"]] ?? "" ?>">
                                        <?= htmlspecialchars($etiquetasAccion[$h["accion"]] ?? $h["accion"]) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    <?php endif; ?>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>
<script>
// Buscador por nombre en la lista de profesores (solo esconde filas
// ya renderizadas, no toca el servidor ni la BD).
(function () {
    var input = document.getElementById("buscar-profesor-historial");
    var tabla = document.getElementById("tabla-profesores-historial");
    var sinResultados = document.getElementById("sin-resultados-profesores-historial");
    if (!input || !tabla) return;

    var filas = tabla.querySelectorAll("tbody tr");

    function aplicarFiltro() {
        var texto = (input.value || "").trim().toLowerCase();
        var visibles = 0;

        filas.forEach(function (fila) {
            var visible = !texto || fila.dataset.nombre.indexOf(texto) !== -1;
            fila.style.display = visible ? "" : "none";
            if (visible) visibles++;
        });

        sinResultados.style.display = visibles === 0 ? "" : "none";
        tabla.style.display = visibles === 0 ? "none" : "";
    }

    input.addEventListener("input", aplicarFiltro);
})();
</script>

</body>
</html>
