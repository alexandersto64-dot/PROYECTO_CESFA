<?php

// ==========================================
// Reporte PDF: Resumen general del profesor (alumnos, trabajos y
// envíos a Subdirección) — complementa a avance_pdf.php, que ya
// cubre PCA/Unidades/Sesiones. Pensado para que el profesor tenga
// una constancia rápida que entregar a Subdirección si se la piden,
// sin tener que entrar módulo por módulo.
//
// Solo PROFESOR: muestra su propio resumen, con las MISMAS consultas
// que ya usan profesor/dashboard.php y profesor/envios.php — ningún
// dato nuevo, ninguna cifra inventada.
// ==========================================

session_start();
require_once __DIR__ . "/../config/sesion_inactividad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../../login.html");
    exit;
}

$rolesPermitidos = ["PROFESOR", "PROFESOR_PRIMARIA", "PROFESOR_SECUNDARIO"];

if (!in_array($_SESSION["rol"], $rolesPermitidos, true)) {
    die("Acceso no autorizado.");
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/pdf.php";
require_once __DIR__ . "/../config/profesor_grados.php";

$generadoPor = trim(($_SESSION["nombres"] ?? "") . " " . ($_SESSION["apellidos"] ?? ""));

// No se usa backend/partials/profesor_bootstrap.php aquí: ese archivo
// asume que el script vive en profesor/ (rutas relativas de un
// nivel), y este vive en backend/reportes/ (dos niveles) — mismo
// patrón que ya usa avance_pdf.php.
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

$gradosAsignados = profesor_grados_asignados($conexion, $idProfesor);

// ==========================================
// ALUMNOS — misma consulta que profesor/dashboard.php
// ==========================================

$stmt = $conexion->prepare("
    SELECT COUNT(*) AS total
    FROM asignaciones_docentes ad
    INNER JOIN grados_secciones gs ON gs.id_grado_seccion = ad.id_grado_seccion
    INNER JOIN matriculas m ON m.id_grado_seccion = gs.id_grado_seccion AND m.estado = 'ACTIVA'
    INNER JOIN alumnos a ON a.id_alumno = m.id_alumno
    WHERE ad.id_profesor = ?
");
$stmt->execute([$idProfesor]);
$totalAlumnos = (int) $stmt->fetchColumn();

// ==========================================
// TRABAJOS — misma consulta que profesor/dashboard.php
// ==========================================

$stmt = $conexion->prepare("
    SELECT estado, COUNT(*) AS total
    FROM trabajos
    WHERE id_profesor = ?
    GROUP BY estado
");
$stmt->execute([$idProfesor]);
$trabajosPorEstado = [];
$totalTrabajos = 0;
foreach ($stmt->fetchAll() as $fila) {
    $trabajosPorEstado[$fila["estado"]] = (int) $fila["total"];
    $totalTrabajos += (int) $fila["total"];
}

// ==========================================
// ENVÍOS A SUBDIRECCIÓN — misma consulta que profesor/envios.php
// ==========================================

$stmt = $conexion->prepare("
    SELECT e.titulo, e.estado, h.observacion AS observacion_actual, h.creado_en AS fecha_version
    FROM envios_trabajo e
    INNER JOIN envios_trabajo_historial h
        ON h.id_envio = e.id_envio AND h.version = e.version_actual
    WHERE e.id_profesor = ?
    ORDER BY e.actualizado_en DESC
");
$stmt->execute([$idProfesor]);
$misEnvios = $stmt->fetchAll();

$etiquetasEstadoEnvio = [
    "ENVIADO"          => "Enviado",
    "EN_REVISION"      => "En revisión",
    "REQUIERE_CAMBIOS" => "Requiere cambios",
    "CORREGIDO"        => "Corregido",
    "APROBADO"         => "Aprobado",
];

$claseBadgeEnvio = [
    "ENVIADO"          => "badge-azul",
    "EN_REVISION"      => "badge-azul",
    "REQUIERE_CAMBIOS" => "badge-rojo",
    "CORREGIDO"        => "badge-azul",
    "APROBADO"         => "badge-verde",
];

// ==========================================
// ARMAR EL HTML DEL REPORTE
// ==========================================

$resumenHtml = '
    <table class="stats-resumen"><tr>
        <td><span class="valor">' . count($gradosAsignados) . '</span><span class="etiqueta">Grados asignados</span></td>
        <td><span class="valor">' . $totalAlumnos . '</span><span class="etiqueta">Alumnos</span></td>
        <td><span class="valor">' . $totalTrabajos . '</span><span class="etiqueta">Trabajos registrados</span></td>
        <td><span class="valor">' . count($misEnvios) . '</span><span class="etiqueta">Envíos a Subdirección</span></td>
    </tr></table>
';

$trabajosHtml = "<h2>Trabajos por estado</h2>";

if ($totalTrabajos === 0) {

    $trabajosHtml .= '<p class="placeholder-text">No hay trabajos registrados todavía.</p>';

} else {

    $trabajosHtml .= '<table class="reporte-tabla"><thead><tr><th>Estado</th><th>Cantidad</th></tr></thead><tbody>';

    foreach ($trabajosPorEstado as $estado => $cantidad) {
        $trabajosHtml .= "<tr><td>" . htmlspecialchars(ucfirst(strtolower($estado))) . "</td><td>{$cantidad}</td></tr>";
    }

    $trabajosHtml .= "</tbody></table>";

}

$enviosHtml = "<h2>Envíos a Subdirección</h2>";

if (count($misEnvios) === 0) {

    $enviosHtml .= '<p class="placeholder-text">Todavía no has enviado trabajos a Subdirección.</p>';

} else {

    $enviosHtml .= '<table class="reporte-tabla"><thead><tr><th>Título</th><th>Estado</th><th>Última actualización</th></tr></thead><tbody>';

    foreach ($misEnvios as $e) {

        $clase = $claseBadgeEnvio[$e["estado"]] ?? "badge-gris";
        $etiqueta = $etiquetasEstadoEnvio[$e["estado"]] ?? $e["estado"];

        $enviosHtml .= "<tr><td>" . htmlspecialchars($e["titulo"]) . "</td>"
            . "<td><span class=\"badge {$clase}\">" . htmlspecialchars($etiqueta) . "</span></td>"
            . "<td>" . htmlspecialchars($e["fecha_version"]) . "</td></tr>";

    }

    $enviosHtml .= "</tbody></table>";

}

$nombreCompletoProfesor = trim($profesor["nombres"] . " " . $profesor["apellidos"]);

$contenido = "<h2>Resumen general — " . htmlspecialchars($nombreCompletoProfesor) . "</h2>"
    . $resumenHtml . $trabajosHtml . $enviosHtml;

$html = pdf_plantilla("Resumen general (alumnos, trabajos y envíos)", "Panel del Profesor", $contenido, $generadoPor);

pdf_generar_y_enviar($html, "resumen-general", isset($_GET["descargar"]));
