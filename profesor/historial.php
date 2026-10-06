<?php

session_start();
require_once __DIR__ . "/../backend/config/sesion_inactividad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.html");
    exit;
}

if (!in_array($_SESSION["rol"], ["PROFESOR", "PROFESOR_PRIMARIA", "PROFESOR_SECUNDARIO"], true)) {
    die("Acceso no autorizado.");
}

require_once "../backend/config/database.php";
require_once "../backend/config/profesor_grados.php";
require_once "../backend/config/materiales_cursos.php";
require_once "../backend/config/materiales_historial.php";

$stmt = $conexion->prepare("
    SELECT p.id_profesor, u.nombres, u.apellidos
    FROM profesores p
    INNER JOIN usuarios u ON p.id_usuario = u.id_usuario
    WHERE p.id_usuario = ?
");
$stmt->execute([$_SESSION["id_usuario"]]);
$profesor = $stmt->fetch();

if (!$profesor) {
    die("No se encontró el perfil del profesor.");
}

$idProfesor = (int) $profesor["id_profesor"];

// ==================================================
// FILTROS (opcionales): módulo y grado.
// Si viene id_nivel_grado, se verifica que sea un aula realmente
// asignada a este profesor (mismo patrón de seguridad que
// pca.php/unidades.php/sesiones.php) — nunca se confía en el valor
// de la URL.
// ==================================================

$modulosValidos = ["PCA", "UNIDADES", "SESIONES"];
$moduloFiltro = $_GET["modulo"] ?? null;
if (!in_array($moduloFiltro, $modulosValidos, true)) {
    $moduloFiltro = null;
}

$idNivelGradoFiltro = isset($_GET["id_nivel_grado"]) ? (int) $_GET["id_nivel_grado"] : null;
$nivelGradoFiltro = null;

if ($idNivelGradoFiltro) {
    $nivelGradoFiltro = profesor_verificar_grado($conexion, $idProfesor, $idNivelGradoFiltro);
    if (!$nivelGradoFiltro) {
        $idNivelGradoFiltro = null; // grado que no le pertenece: se ignora el filtro, no se rechaza la página
    }
}

$gradosAsignados = profesor_grados_asignados($conexion, $idProfesor);

$historial = materiales_historial_listar($conexion, $idProfesor, $moduloFiltro, $idNivelGradoFiltro, 100);

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
    <title>Historial de materiales · Panel del Profesor - I.E.P. 88044 Abraham Valdelomar</title>
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
        <h1><?= icon("clock") ?> Historial de materiales</h1>
    </div>
    <div class="header-actions">
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <p class="placeholder-text" style="margin-bottom:20px;">
        Registro de subidas, reemplazos y eliminaciones de archivos de PCA, Unidades y Sesiones.
        El archivo físico de una versión anterior ya no está disponible una vez reemplazado o
        eliminado; aquí solo queda el registro de qué se cambió y cuándo.
    </p>

    <form method="GET" class="panel-form" style="margin-bottom:20px;">
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
                    <option value="">Todos mis grados</option>
                    <?php foreach ($gradosAsignados as $ng): ?>
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

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
