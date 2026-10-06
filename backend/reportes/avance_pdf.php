<?php

// ==========================================
// Reporte PDF CONSOLIDADO: avance de PCA + Unidades + Sesiones en un
// solo documento (Tutoría no se incluye todavía).
//
// Mismo criterio de avance que ya usa profesor/dashboard.php:
//   - PCA: 1 archivo por curso/grado (solo U1).
//   - Unidades: 1 archivo por CADA curso que el profesor dicta en
//     ese grado (lista plana, sin U1..U10 ni Semana).
//   - Sesiones: U1..U10 → Sem-01..Sem-05, "completa" cuando cubre
//     TODOS los cursos que el profesor dicta en ese grado.
//
// Dos modos, según el rol de quien lo pide (mismos permisos que ya
// existen en el resto del sistema, nada nuevo):
//
//   - PROFESOR: ve SOLO su propio avance, por cada grado que tiene
//     asignado — mismos datos que ya ve en profesor/dashboard.php.
//   - ADMIN / SUBDIRECTOR: ve el avance de TODOS los profesores
//     (vista institucional), igual que ya hace pca_pdf.php.
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
require_once __DIR__ . "/../config/materiales_cursos.php";

$esProfesor = in_array($_SESSION["rol"], ["PROFESOR", "PROFESOR_PRIMARIA", "PROFESOR_SECUNDARIO"], true);

$generadoPor = trim(($_SESSION["nombres"] ?? "") . " " . ($_SESSION["apellidos"] ?? ""));

/**
 * Celda de avance "X/Y — Z%" con una barra de progreso simple (para
 * PDF, sin depender de CSS externo ni JS), coloreada con el mismo
 * criterio de semáforo que ya usa el resto del sistema
 * (materiales_color_progreso(), rojo <40%, ámbar 40-79%, verde >=80%).
 */
function avance_pdf_celda(int $avance, int $total): string {

    $porcentaje = $total > 0 ? min(100, (int) round($avance / $total * 100)) : 0;
    $clase = $porcentaje === 0 ? "badge-gris" : ($porcentaje === 100 ? "badge-verde" : "badge-azul");

    return "<div>{$avance}/{$total}</div>"
        . "<span class=\"badge {$clase}\">{$porcentaje}%</span>";

}

// ==========================================
// MODO PROFESOR: solo su propio avance
// ==========================================

if ($esProfesor) {

    // No se usa backend/partials/profesor_bootstrap.php aquí: ese
    // archivo asume que el script vive en profesor/ (rutas relativas
    // de un nivel), y este vive en backend/reportes/ (dos niveles) —
    // se repite, igual que en pca_pdf.php, la misma consulta de
    // "datos del profesor".
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
        $idProfesor = (int) $profesor["id_profesor"];

        $totalCursosPorGrado = [];
        foreach ($gradosAsignados as $ng) {
            $totalCursosPorGrado[(int) $ng["id_nivel_grado"]] = count($ng["cursos"]);
        }

        $avancePcaPorGrado = [];
        $stmt = $conexion->prepare("
            SELECT id_nivel_grado, COUNT(DISTINCT unidad) AS total
            FROM profesor_pca_archivos
            WHERE id_profesor = ? AND id_nivel_grado IN ($marcadores)
            GROUP BY id_nivel_grado
        ");
        $stmt->execute(array_merge([$idProfesor], $idsNivelGrado));
        foreach ($stmt->fetchAll() as $fila) {
            $avancePcaPorGrado[(int) $fila["id_nivel_grado"]] = (int) $fila["total"];
        }

        $avanceUnidadesPorGrado = [];
        $stmt = $conexion->prepare("
            SELECT id_nivel_grado, COUNT(DISTINCT curso) AS total
            FROM profesor_unidad_archivos
            WHERE id_profesor = ? AND id_nivel_grado IN ($marcadores) AND unidad = 1 AND semana = 1
            GROUP BY id_nivel_grado
        ");
        $stmt->execute(array_merge([$idProfesor], $idsNivelGrado));
        foreach ($stmt->fetchAll() as $fila) {
            $avanceUnidadesPorGrado[(int) $fila["id_nivel_grado"]] = (int) $fila["total"];
        }

        $avanceSesionesPorGrado = [];
        $stmt = $conexion->prepare("
            SELECT id_nivel_grado, unidad, semana, COUNT(DISTINCT curso) AS cursos_cubiertos
            FROM profesor_sesion_archivos
            WHERE id_profesor = ? AND id_nivel_grado IN ($marcadores)
            GROUP BY id_nivel_grado, unidad, semana
        ");
        $stmt->execute(array_merge([$idProfesor], $idsNivelGrado));
        foreach ($stmt->fetchAll() as $fila) {
            $idNg = (int) $fila["id_nivel_grado"];
            $meta = $totalCursosPorGrado[$idNg] ?? 0;
            if ($meta > 0 && (int) $fila["cursos_cubiertos"] >= $meta) {
                $avanceSesionesPorGrado[$idNg] = ($avanceSesionesPorGrado[$idNg] ?? 0) + 1;
            }
        }

        $filasHtml = '<table class="reporte-tabla"><thead><tr>'
            . "<th>Nivel</th><th>Grado</th><th>PCA</th><th>Unidades</th><th>Sesiones</th>"
            . "</tr></thead><tbody>";

        foreach ($gradosAsignados as $ng) {

            $idNg = (int) $ng["id_nivel_grado"];

            $totalPca = pca_total_unidades($ng["nivel"]);
            $totalUnidades = materiales_total_cursos($ng["nivel"]);
            $totalUnidadesSesiones = materiales_total_unidades($ng["nivel"]);
            $totalSesiones = $totalUnidadesSesiones * materiales_total_semanas($ng["nivel"]);

            $filasHtml .= "<tr>"
                . "<td>" . htmlspecialchars(ucfirst(strtolower($ng["nivel"]))) . "</td>"
                . "<td>" . htmlspecialchars($ng["nombre"]) . "</td>"
                . "<td>" . avance_pdf_celda($avancePcaPorGrado[$idNg] ?? 0, $totalPca) . "</td>"
                . "<td>" . avance_pdf_celda($avanceUnidadesPorGrado[$idNg] ?? 0, $totalUnidades) . "</td>"
                . "<td>" . avance_pdf_celda($avanceSesionesPorGrado[$idNg] ?? 0, $totalSesiones) . "</td>"
                . "</tr>";

        }

        $filasHtml .= "</tbody></table>";

    }

    $nombreCompletoProfesor = trim($profesor["nombres"] . " " . $profesor["apellidos"]);

    $contenido = "<h2>Avance por grado — " . htmlspecialchars($nombreCompletoProfesor) . "</h2>" . $filasHtml;

    $html = pdf_plantilla("Avance general (PCA + Unidades + Sesiones)", "Panel del Profesor", $contenido, $generadoPor);

    pdf_generar_y_enviar($html, "avance-general", isset($_GET["descargar"]));

    exit;

}


// ==========================================
// MODO ADMIN / SUBDIRECTOR: avance de todos los profesores
// ==========================================

$filasBase = $conexion->query("
    SELECT
        pr.id_profesor, u.nombres, u.apellidos,
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

// Cursos que cada profesor dicta en cada uno de sus grados (fuente:
// profesor_curso_grado, nunca asignaciones_docentes/secciones): es
// la "meta" para Unidades (total real de cursos) y para Sesiones
// (criterio de "completa" = cubre todos esos cursos), igual que ya
// usa profesor/dashboard.php.
$totalCursosPorProfesorGrado = [];
foreach ($conexion->query("
    SELECT id_profesor, id_nivel_grado, COUNT(DISTINCT id_curso) AS total
    FROM profesor_curso_grado
    GROUP BY id_profesor, id_nivel_grado
")->fetchAll() as $fila) {
    $totalCursosPorProfesorGrado[$fila["id_profesor"] . "-" . $fila["id_nivel_grado"]] = (int) $fila["total"];
}

// UNIDADES: lista plana (1 archivo por curso, unidad/semana fijos
// en 1) — el avance es directamente cuántos cursos distintos tienen
// archivo, sin exigir "completitud" (ya no hay nada que completar
// por unidad, cada curso es independiente).
$avanceUnidadesPorProfesorGrado = [];
foreach ($conexion->query("
    SELECT id_profesor, id_nivel_grado, COUNT(DISTINCT curso) AS total
    FROM profesor_unidad_archivos
    WHERE unidad = 1 AND semana = 1
    GROUP BY id_profesor, id_nivel_grado
")->fetchAll() as $fila) {
    $avanceUnidadesPorProfesorGrado[$fila["id_profesor"] . "-" . $fila["id_nivel_grado"]] = (int) $fila["total"];
}

// SESIONES: sin cambios — U1..U10 → Sem-01..Sem-05, "completa"
// cuando cubre todos los cursos que el profesor dicta en ese grado.
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

$totalFilas = count($filasBase);

// "Completo" = las 3 áreas al 100% en ese grado.
$totalCompletos = 0;
$sumaPromedios = 0;

foreach ($filasBase as $f) {

    $clave = $f["id_profesor"] . "-" . $f["id_nivel_grado"];

    $totalPca = pca_total_unidades($f["nivel"]);
    $pctPca = $totalPca > 0 ? min(100, (int) round((int) $f["avance_pca"] / $totalPca * 100)) : 0;

    $totalUnidades = materiales_total_cursos($f["nivel"]);
    $avanceUnidades = $avanceUnidadesPorProfesorGrado[$clave] ?? 0;
    $pctUnidades = $totalUnidades > 0 ? min(100, (int) round($avanceUnidades / $totalUnidades * 100)) : 0;

    $totalUnidadesSesiones = materiales_total_unidades($f["nivel"]);
    $totalSesiones = $totalUnidadesSesiones * materiales_total_semanas($f["nivel"]);
    $avanceSesiones = $avanceSesionesPorProfesorGrado[$clave] ?? 0;
    $pctSesiones = $totalSesiones > 0 ? min(100, (int) round($avanceSesiones / $totalSesiones * 100)) : 0;

    if ($pctPca === 100 && $pctUnidades === 100 && $pctSesiones === 100) {
        $totalCompletos++;
    }

    $sumaPromedios += ($pctPca + $pctUnidades + $pctSesiones) / 3;

}

$promedioAvance = $totalFilas > 0 ? (int) round($sumaPromedios / $totalFilas) : 0;

if ($totalFilas === 0) {

    $filasHtml = '<p class="placeholder-text">No hay profesores con grados asignados todavía.</p>';

} else {

    $filasHtml = '<table class="reporte-tabla"><thead><tr>'
        . "<th>Profesor</th><th>Nivel</th><th>Grado</th><th>PCA</th><th>Unidades</th><th>Sesiones</th>"
        . "</tr></thead><tbody>";

    foreach ($filasBase as $f) {

        $clave = $f["id_profesor"] . "-" . $f["id_nivel_grado"];

        $totalPca = pca_total_unidades($f["nivel"]);
        $totalUnidades = materiales_total_cursos($f["nivel"]);
        $totalUnidadesSesiones = materiales_total_unidades($f["nivel"]);
        $totalSesiones = $totalUnidadesSesiones * materiales_total_semanas($f["nivel"]);

        $filasHtml .= "<tr>"
            . "<td>" . htmlspecialchars($f["nombres"] . " " . $f["apellidos"]) . "</td>"
            . "<td>" . htmlspecialchars(ucfirst(strtolower($f["nivel"]))) . "</td>"
            . "<td>" . htmlspecialchars($f["grado_nombre"]) . "</td>"
            . "<td>" . avance_pdf_celda((int) $f["avance_pca"], $totalPca) . "</td>"
            . "<td>" . avance_pdf_celda($avanceUnidadesPorProfesorGrado[$clave] ?? 0, $totalUnidades) . "</td>"
            . "<td>" . avance_pdf_celda($avanceSesionesPorProfesorGrado[$clave] ?? 0, $totalSesiones) . "</td>"
            . "</tr>";

    }

    $filasHtml .= "</tbody></table>";

}

$resumenHtml = '
    <table class="stats-resumen"><tr>
        <td><span class="valor">' . $totalFilas . '</span><span class="etiqueta">Grados asignados</span></td>
        <td><span class="valor">' . $totalCompletos . '</span><span class="etiqueta">Las 3 áreas al 100%</span></td>
        <td><span class="valor">' . $promedioAvance . '%</span><span class="etiqueta">Avance promedio</span></td>
    </tr></table>
';

$contenido = $resumenHtml . "<h2>Avance por profesor y grado</h2>" . $filasHtml;

$subtitulo = $_SESSION["rol"] === "ADMIN" ? "Panel de Administración" : "Panel del Subdirector";

$html = pdf_plantilla("Avance institucional (PCA + Unidades + Sesiones)", $subtitulo, $contenido, $generadoPor);

pdf_generar_y_enviar($html, "avance-institucional", isset($_GET["descargar"]));
