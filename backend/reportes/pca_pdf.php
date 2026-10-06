<?php

// ==========================================
// Reporte PDF: avance del PCA (un único archivo, U1, por curso/grado).
//
// Dos modos, según el rol de quien lo pide (mismos permisos que ya
// existen en el resto del sistema, nada nuevo):
//
//   - PROFESOR: ve SOLO su propio avance, por cada grado que tiene
//     asignado — mismos datos que ya ve en profesor/dashboard.php.
//   - ADMIN / SUBDIRECTOR: ve el avance de TODOS los profesores
//     (vista institucional), ya que ambos roles ya pueden ver a
//     todos los profesores en admin/profesores.php y
//     subdirector/profesores.php.
// ==========================================

session_start();
require_once __DIR__ . "/../config/sesion_inactividad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../../login.html");
    exit;
}

$rolesPermitidos = ["ADMIN", "SUBDIRECTOR", "PROFESOR", "PROFESOR_PRIMARIA", "PROFESOR_SECUNDARIO"];

if (!in_array($_SESSION["rol"], $rolesPermitidos, true)) {
    die("Acceso no autorizado.");
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/pdf.php";
require_once __DIR__ . "/../config/materiales_cursos.php"; // pca_total_unidades(), materiales_total_unidades()

$esProfesor = in_array($_SESSION["rol"], ["PROFESOR", "PROFESOR_PRIMARIA", "PROFESOR_SECUNDARIO"], true);

$generadoPor = trim(($_SESSION["nombres"] ?? "") . " " . ($_SESSION["apellidos"] ?? ""));


// ==========================================
// MODO PROFESOR: solo su propio avance
// ==========================================

if ($esProfesor) {

    // No se usa backend/partials/profesor_bootstrap.php aquí: ese
    // archivo hace su propio session_start() y sus redirecciones
    // asumen que el script vive en profesor/ (un nivel de
    // profundidad respecto a la raíz del proyecto) — este script
    // vive en backend/reportes/ (dos niveles), así que esas rutas
    // relativas apuntarían mal. Se repite aquí, en su lugar, la
    // misma consulta de "datos del profesor" que ya usa
    // profesor_bootstrap.php.
    require_once __DIR__ . "/../config/profesor_grados.php";

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

    $gradosAsignados = profesor_grados_asignados($conexion, $profesor["id_profesor"]);

    $filasHtml = "";

    if (count($gradosAsignados) === 0) {

        $filasHtml = '<p class="placeholder-text">Todavía no tienes ningún grado asignado.</p>';

    } else {

        $idsNivelGrado = array_map(fn($ng) => (int) $ng["id_nivel_grado"], $gradosAsignados);
        $marcadores = implode(",", array_fill(0, count($idsNivelGrado), "?"));

        $avancePorGrado = [];
        $stmtAvance = $conexion->prepare("
            SELECT id_nivel_grado, COUNT(DISTINCT unidad) AS total
            FROM profesor_pca_archivos
            WHERE id_profesor = ? AND id_nivel_grado IN ($marcadores)
            GROUP BY id_nivel_grado
        ");
        $stmtAvance->execute(array_merge([$profesor["id_profesor"]], $idsNivelGrado));
        foreach ($stmtAvance->fetchAll() as $fila) {
            $avancePorGrado[(int) $fila["id_nivel_grado"]] = (int) $fila["total"];
        }

        $filasHtml = '<table class="reporte-tabla"><thead><tr>'
            . "<th>Nivel</th><th>Grado</th><th>Unidades completadas</th><th>Avance</th>"
            . "</tr></thead><tbody>";

        foreach ($gradosAsignados as $ng) {

            $avance = $avancePorGrado[(int) $ng["id_nivel_grado"]] ?? 0;
            $totalUnidadesPca = pca_total_unidades($ng["nivel"]);
            $porcentaje = (int) round($avance / $totalUnidadesPca * 100);
            $claseBadge = $porcentaje === 100 ? "badge-verde" : ($porcentaje === 0 ? "badge-gris" : "badge-azul");

            $filasHtml .= "<tr>"
                . "<td>" . htmlspecialchars(ucfirst(strtolower($ng["nivel"]))) . "</td>"
                . "<td>" . htmlspecialchars($ng["nombre"]) . "</td>"
                . "<td>{$avance} / {$totalUnidadesPca}</td>"
                . "<td><span class=\"badge {$claseBadge}\">{$porcentaje}%</span></td>"
                . "</tr>";

        }

        $filasHtml .= "</tbody></table>";

    }

    $nombreCompletoProfesor = trim($profesor["nombres"] . " " . $profesor["apellidos"]);

    $contenido = "<h2>Avance por grado — " . htmlspecialchars($nombreCompletoProfesor) . "</h2>" . $filasHtml;

    $html = pdf_plantilla("Avance del PCA", "Panel del Profesor", $contenido, $generadoPor);

    pdf_generar_y_enviar($html, "avance-pca", isset($_GET["descargar"]));

    exit;

}


// ==========================================
// MODO ADMIN / SUBDIRECTOR: avance de todos los profesores
// ==========================================

$filas = $conexion->query("
    SELECT
        pr.id_profesor, u.nombres, u.apellidos,
        ng.id_nivel_grado, ng.nivel, ng.grado, ng.nombre AS grado_nombre,
        COUNT(DISTINCT ppa.unidad) AS avance

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

$totalFilas = count($filas);
$totalCompletos = count(array_filter(
    $filas,
    fn($f) => (int) $f["avance"] >= pca_total_unidades($f["nivel"])
));
$totalSinIniciar = count(array_filter($filas, fn($f) => (int) $f["avance"] === 0));

// Promedio de avance: aunque ahora ambos niveles comparten el mismo
// total de unidades de PCA (1, solo U1), se conserva el cálculo por
// fila (promedio de los % de cada fila, vía pca_total_unidades($f["nivel"]))
// en vez de una sola razón global, para no depender de que los
// niveles sigan teniendo el mismo total si esto cambiara a futuro.
$promedioAvance = $totalFilas > 0
    ? (int) round(array_sum(array_map(
        fn($f) => min(100, (int) $f["avance"] / pca_total_unidades($f["nivel"]) * 100),
        $filas
    )) / $totalFilas)
    : 0;

if ($totalFilas === 0) {

    $filasHtml = '<p class="placeholder-text">No hay profesores con grados asignados todavía.</p>';

} else {

    $filasHtml = '<table class="reporte-tabla"><thead><tr>'
        . "<th>Profesor</th><th>Nivel</th><th>Grado</th><th>Unidades completadas</th><th>Avance</th>"
        . "</tr></thead><tbody>";

    foreach ($filas as $f) {

        $avance = (int) $f["avance"];
        $totalUnidadesPca = pca_total_unidades($f["nivel"]);
        $porcentaje = (int) round($avance / $totalUnidadesPca * 100);
        $claseBadge = $porcentaje === 100 ? "badge-verde" : ($porcentaje === 0 ? "badge-gris" : "badge-azul");

        $filasHtml .= "<tr>"
            . "<td>" . htmlspecialchars($f["nombres"] . " " . $f["apellidos"]) . "</td>"
            . "<td>" . htmlspecialchars(ucfirst(strtolower($f["nivel"]))) . "</td>"
            . "<td>" . htmlspecialchars($f["grado_nombre"]) . "</td>"
            . "<td>{$avance} / {$totalUnidadesPca}</td>"
            . "<td><span class=\"badge {$claseBadge}\">{$porcentaje}%</span></td>"
            . "</tr>";

    }

    $filasHtml .= "</tbody></table>";

}

$resumenHtml = '
    <table class="stats-resumen"><tr>
        <td><span class="valor">' . $totalFilas . '</span><span class="etiqueta">Grados asignados</span></td>
        <td><span class="valor">' . $totalCompletos . '</span><span class="etiqueta">PCA completo (100%)</span></td>
        <td><span class="valor">' . $totalSinIniciar . '</span><span class="etiqueta">Sin iniciar</span></td>
        <td><span class="valor">' . $promedioAvance . '%</span><span class="etiqueta">Avance promedio</span></td>
    </tr></table>
';

$contenido = $resumenHtml . "<h2>Avance por profesor y grado</h2>" . $filasHtml;

$subtitulo = $_SESSION["rol"] === "ADMIN" ? "Panel de Administración" : "Panel del Subdirector";

$html = pdf_plantilla("Avance institucional del PCA", $subtitulo, $contenido, $generadoPor);

pdf_generar_y_enviar($html, "avance-pca-institucional", isset($_GET["descargar"]));
