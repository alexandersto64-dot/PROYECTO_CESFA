<?php

// ==========================================
// Reporte PDF: trabajos institucionales enviados por profesores
// (envios_trabajo), con su estado y versión vigente.
//
// Mismos datos y el mismo permiso que subdirector/revision.php
// (solo SUBDIRECTOR revisa/aprueba estos envíos).
// ==========================================

session_start();
require_once __DIR__ . "/../config/sesion_inactividad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../../login.html");
    exit;
}

if ($_SESSION["rol"] !== "SUBDIRECTOR") {
    die("Acceso no autorizado.");
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/pdf.php";


// ==========================================
// FILTRO OPCIONAL: ?estado=ENVIADO|EN_REVISION|REQUIERE_CAMBIOS|CORREGIDO|APROBADO
// ==========================================

$estadosValidos = ["ENVIADO", "EN_REVISION", "REQUIERE_CAMBIOS", "CORREGIDO", "APROBADO"];
$estadoFiltro = $_GET["estado"] ?? "";
$condicionEstado = "";
$parametros = [];

if (in_array($estadoFiltro, $estadosValidos, true)) {
    $condicionEstado = "WHERE e.estado = ?";
    $parametros[] = $estadoFiltro;
}

$sql = "
    SELECT
        e.id_envio, e.titulo, e.estado, e.version_actual, e.creado_en, e.actualizado_en,
        u.nombres, u.apellidos,
        h.creado_en AS fecha_version, h.observacion AS observacion_actual

    FROM envios_trabajo e

    INNER JOIN profesores p ON p.id_profesor = e.id_profesor
    INNER JOIN usuarios u ON u.id_usuario = p.id_usuario
    INNER JOIN envios_trabajo_historial h
        ON h.id_envio = e.id_envio AND h.version = e.version_actual

    {$condicionEstado}

    ORDER BY (e.estado IN ('ENVIADO', 'EN_REVISION', 'CORREGIDO')) DESC, e.actualizado_en DESC
";

$stmt = $conexion->prepare($sql);
$stmt->execute($parametros);
$envios = $stmt->fetchAll();

$totalEnvios = count($envios);
$totalPendientes = count(array_filter($envios, fn($e) => in_array($e["estado"], ["ENVIADO", "EN_REVISION", "CORREGIDO"], true)));
$totalCambios = count(array_filter($envios, fn($e) => $e["estado"] === "REQUIERE_CAMBIOS"));
$totalAprobados = count(array_filter($envios, fn($e) => $e["estado"] === "APROBADO"));

$etiquetasEstado = [
    "ENVIADO"          => ["Enviado", "badge-azul"],
    "EN_REVISION"      => ["En revisión", "badge-azul"],
    "REQUIERE_CAMBIOS" => ["Requiere cambios", "badge-rojo"],
    "CORREGIDO"        => ["Corregido", "badge-azul"],
    "APROBADO"         => ["Aprobado", "badge-verde"],
];


// ==========================================
// ARMAR EL HTML DEL REPORTE
// ==========================================

if ($totalEnvios === 0) {

    $filasHtml = '<p class="placeholder-text">No hay trabajos enviados'
        . ($condicionEstado !== "" ? " con estado " . htmlspecialchars($estadoFiltro) : "")
        . ".</p>";

} else {

    $filasHtml = '<table class="reporte-tabla"><thead><tr>'
        . "<th>Título</th><th>Profesor</th><th>Versión</th><th>Última actualización</th><th>Estado</th>"
        . "</tr></thead><tbody>";

    foreach ($envios as $e) {

        [$etiqueta, $claseBadge] = $etiquetasEstado[$e["estado"]] ?? [$e["estado"], "badge-gris"];

        $filasHtml .= "<tr>"
            . "<td>" . htmlspecialchars($e["titulo"]) . "</td>"
            . "<td>" . htmlspecialchars($e["nombres"] . " " . $e["apellidos"]) . "</td>"
            . "<td>v" . (int) $e["version_actual"] . "</td>"
            . "<td>" . htmlspecialchars(date("d/m/Y H:i", strtotime($e["actualizado_en"]))) . "</td>"
            . "<td><span class=\"badge {$claseBadge}\">" . htmlspecialchars($etiqueta) . "</span></td>"
            . "</tr>";

        if ($e["estado"] === "REQUIERE_CAMBIOS" && $e["observacion_actual"]) {
            $filasHtml .= '<tr><td colspan="5" style="font-size:9px; color:#4A5A6A; padding-top:0;">'
                . "Observación: " . htmlspecialchars($e["observacion_actual"]) . "</td></tr>";
        }

    }

    $filasHtml .= "</tbody></table>";

}

$resumenHtml = '
    <table class="stats-resumen"><tr>
        <td><span class="valor">' . $totalEnvios . '</span><span class="etiqueta">Total de envíos</span></td>
        <td><span class="valor">' . $totalPendientes . '</span><span class="etiqueta">Pendientes de revisión</span></td>
        <td><span class="valor">' . $totalCambios . '</span><span class="etiqueta">Requieren cambios</span></td>
        <td><span class="valor">' . $totalAprobados . '</span><span class="etiqueta">Aprobados</span></td>
    </tr></table>
';

$contenido = $resumenHtml . "<h2>Detalle de trabajos enviados</h2>" . $filasHtml;

$generadoPor = trim(($_SESSION["nombres"] ?? "") . " " . ($_SESSION["apellidos"] ?? ""));

$html = pdf_plantilla("Trabajos enviados a Subdirección", "Panel del Subdirector", $contenido, $generadoPor);

pdf_generar_y_enviar($html, "trabajos-subdireccion", isset($_GET["descargar"]));
