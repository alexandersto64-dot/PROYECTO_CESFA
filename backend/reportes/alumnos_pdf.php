<?php

// ==========================================
// Reporte PDF: listado de alumnos.
//
// Mismos datos y el mismo permiso que ya usan admin/alumnos.php y
// subdirector/alumnos.php (ADMIN y SUBDIRECTOR pueden ver el
// listado completo de alumnos) — este script no expone nada que
// esos paneles no muestren ya en pantalla.
// ==========================================

session_start();
require_once __DIR__ . "/../config/sesion_inactividad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../../login.html");
    exit;
}

if (!in_array($_SESSION["rol"], ["ADMIN", "SUBDIRECTOR"], true)) {
    die("Acceso no autorizado.");
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/pdf.php";


// ==========================================
// FILTRO OPCIONAL: ?estado=ACTIVO|INACTIVO
// (mismo patrón simple que el resto del sistema; sin filtro
// devuelve todos, igual que las páginas de listado en pantalla)
// ==========================================

$estadoFiltro = $_GET["estado"] ?? "";
$condicionEstado = "";
$parametros = [];

if (in_array($estadoFiltro, ["ACTIVO", "INACTIVO"], true)) {
    $condicionEstado = "WHERE a.estado = ?";
    $parametros[] = $estadoFiltro;
}

$sql = "
    SELECT
        a.nombres, a.apellidos, a.dni, a.estado,
        gs.nombre AS grado_seccion, gs.nivel,
        p.nombre AS periodo

    FROM alumnos a

    LEFT JOIN matriculas m ON m.id_alumno = a.id_alumno AND m.estado = 'ACTIVA'
    LEFT JOIN grados_secciones gs ON gs.id_grado_seccion = m.id_grado_seccion
    LEFT JOIN periodos_academicos p ON p.id_periodo = m.id_periodo

    {$condicionEstado}

    ORDER BY a.apellidos, a.nombres
";

$stmt = $conexion->prepare($sql);
$stmt->execute($parametros);
$alumnos = $stmt->fetchAll();

$totalAlumnos = count($alumnos);
$totalActivos = count(array_filter($alumnos, fn($a) => $a["estado"] === "ACTIVO"));
$totalConMatricula = count(array_filter($alumnos, fn($a) => $a["grado_seccion"] !== null));
$totalSinMatricula = $totalAlumnos - $totalConMatricula;


// ==========================================
// ARMAR EL HTML DEL REPORTE
// ==========================================

$filasHtml = "";

if ($totalAlumnos === 0) {

    $filasHtml = '<p class="placeholder-text">No hay alumnos registrados' .
        ($condicionEstado !== "" ? " con estado " . htmlspecialchars($estadoFiltro) : "") .
        ".</p>";

} else {

    $filasHtml = '<table class="reporte-tabla"><thead><tr>'
        . "<th>Nombre</th><th>DNI</th><th>Nivel</th><th>Grado / Sección</th><th>Periodo</th><th>Estado</th>"
        . "</tr></thead><tbody>";

    foreach ($alumnos as $a) {

        $badgeClase = $a["estado"] === "ACTIVO" ? "badge-verde" : "badge-gris";

        $filasHtml .= "<tr>"
            . "<td>" . htmlspecialchars($a["apellidos"] . ", " . $a["nombres"]) . "</td>"
            . "<td>" . htmlspecialchars($a["dni"]) . "</td>"
            . "<td>" . htmlspecialchars($a["nivel"] ? ucfirst(strtolower($a["nivel"])) : "—") . "</td>"
            . "<td>" . htmlspecialchars($a["grado_seccion"] ?? "Sin matrícula activa") . "</td>"
            . "<td>" . htmlspecialchars($a["periodo"] ?? "—") . "</td>"
            . "<td><span class=\"badge {$badgeClase}\">" . htmlspecialchars($a["estado"]) . "</span></td>"
            . "</tr>";

    }

    $filasHtml .= "</tbody></table>";

}

$resumenHtml = '
    <table class="stats-resumen"><tr>
        <td><span class="valor">' . $totalAlumnos . '</span><span class="etiqueta">Total alumnos</span></td>
        <td><span class="valor">' . $totalActivos . '</span><span class="etiqueta">Activos</span></td>
        <td><span class="valor">' . $totalConMatricula . '</span><span class="etiqueta">Con matrícula activa</span></td>
        <td><span class="valor">' . $totalSinMatricula . '</span><span class="etiqueta">Sin matrícula</span></td>
    </tr></table>
';

$contenido = $resumenHtml . "<h2>Detalle de alumnos</h2>" . $filasHtml;

$subtitulo = $_SESSION["rol"] === "ADMIN" ? "Panel de Administración" : "Panel del Subdirector";
$generadoPor = trim(($_SESSION["nombres"] ?? "") . " " . ($_SESSION["apellidos"] ?? ""));

$html = pdf_plantilla("Listado de alumnos", $subtitulo, $contenido, $generadoPor);

pdf_generar_y_enviar($html, "listado-alumnos", isset($_GET["descargar"]));
