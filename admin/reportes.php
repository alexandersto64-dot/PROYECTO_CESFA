<?php

session_start();
require_once __DIR__ . "/../backend/config/sesion_inactividad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.html");
    exit;
}

if ($_SESSION["rol"] !== "ADMIN") {
    die("Acceso no autorizado.");
}

require_once "../backend/config/database.php";

$totalUsuarios = (int)$conexion->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
$totalActivos = (int)$conexion->query("SELECT COUNT(*) FROM usuarios WHERE estado = 'ACTIVO'")->fetchColumn();
$totalProfesores = (int)$conexion->query("SELECT COUNT(*) FROM profesores")->fetchColumn();
$totalCursos = (int)$conexion->query("SELECT COUNT(*) FROM cursos")->fetchColumn();
$totalDocumentos = (int)$conexion->query("SELECT COUNT(*) FROM documentos")->fetchColumn();
$totalTrabajos = (int)$conexion->query("SELECT COUNT(*) FROM trabajos")->fetchColumn();

$usuariosPorRol = $conexion->query("
    SELECT r.nombre AS rol, COUNT(*) AS total
    FROM usuarios u
    INNER JOIN roles r ON u.id_rol = r.id_rol
    GROUP BY r.nombre
")->fetchAll();

$trabajosPorEstado = $conexion->query("
    SELECT estado, COUNT(*) AS total
    FROM trabajos
    GROUP BY estado
")->fetchAll();

// ==================================================
// AVANCE DE PCA / UNIDADES / SESIONES POR PROFESOR Y GRADO
//
// Mismo criterio y las mismas tablas que ya usa el Dashboard del
// Profesor (ver profesor/dashboard.php) y subdirector/reportes.php,
// solo que aquí se trae de TODOS los profesores a la vez (sin
// "WHERE id_profesor = ?") para que Admin vea de un vistazo quién va
// atrasado sin entrar profesor por profesor. Todo por grado, nunca
// un contador global del profesor.
// ==================================================

require_once "../backend/config/materiales_cursos.php";

// Grados que EXISTEN por nivel (Primaria 1°-6°, Secundaria 1°-5°) para el filtro en cascada.
$gradosPorNivel = [];
foreach ($conexion->query("SELECT nivel, grado, nombre FROM niveles_grados ORDER BY nivel, grado")->fetchAll() as $fila) {
    $gradosPorNivel[$fila["nivel"]][] = ["v" => (int) $fila["grado"], "t" => $fila["nombre"]];
}

$filasAvanceBase = $conexion->query("
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

// Cuántas filas (grados) tiene cada profesor en $filasAvanceBase, para agrupar
// visualmente su nombre con rowspan en vez de repetirlo en cada fila.
$filasPorProfesor = [];
foreach ($filasAvanceBase as $f) {
    $filasPorProfesor[$f["id_profesor"]] = ($filasPorProfesor[$f["id_profesor"]] ?? 0) + 1;
}

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
// cuántos cursos distintos tienen archivo.
$avanceUnidadesPorProfesorGrado = [];
foreach ($conexion->query("
    SELECT id_profesor, id_nivel_grado, COUNT(DISTINCT curso) AS total
    FROM profesor_unidad_archivos
    WHERE unidad = 1 AND semana = 1
    GROUP BY id_profesor, id_nivel_grado
")->fetchAll() as $fila) {
    $avanceUnidadesPorProfesorGrado[$fila["id_profesor"] . "-" . $fila["id_nivel_grado"]] = (int) $fila["total"];
}

// SESIONES: U1..U10 → Sem-01..Sem-05, "completa" cuando cubre todos
// los cursos que el profesor dicta en ese grado.
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

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes · Panel de Administración - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">


<header>
    <div>
        <h1>Reportes del sistema</h1>
        <p>Panel de Administración · I.E.P. 88044 Abraham Valdelomar</p>
    </div>
    <div class="header-actions">
        <a href="../backend/reportes/alumnos_pdf.php?descargar=1" target="_blank"><?= icon("file-text") ?> Alumnos (PDF)</a>
        <a href="../backend/reportes/pca_pdf.php?descargar=1" target="_blank"><?= icon("file-text") ?> Avance PCA (PDF)</a>
        <a href="../backend/reportes/avance_pdf.php?descargar=1" target="_blank"><?= icon("file-text") ?> Avance general (PDF)</a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-value"><?= $totalUsuarios ?></span>
            <span class="stat-label">Usuarios totales</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= $totalActivos ?></span>
            <span class="stat-label">Usuarios activos</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= $totalProfesores ?></span>
            <span class="stat-label">Profesores</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= $totalCursos ?></span>
            <span class="stat-label">Cursos</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= $totalDocumentos ?></span>
            <span class="stat-label">Documentos</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= $totalTrabajos ?></span>
            <span class="stat-label">Trabajos asignados</span>
        </div>
    </div>

    <h2>Usuarios por rol</h2>
    <?php if (count($usuariosPorRol) === 0): ?>
        <p class="placeholder-text">Sin datos todavía.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Rol</th><th>Cantidad</th></tr></thead>
                <tbody>
                    <?php foreach ($usuariosPorRol as $r): ?>
                        <tr><td><span class="role-badge"><?= htmlspecialchars($r['rol']) ?></span></td><td><?= (int)$r['total'] ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2>Avance de PCA, Unidades y Sesiones por profesor</h2>
    <?php if (count($filasAvanceBase) === 0): ?>
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
                    <select id="filtro-grado-avance" disabled>
                        <option value="">Primero elige el nivel</option>
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
                    </tr>
                </thead>
                <tbody>
                    <?php $__profesorAnterior = null; ?>
                    <?php foreach ($filasAvanceBase as $f): ?>
                        <?php
                            $clave = $f["id_profesor"] . "-" . $f["id_nivel_grado"];
                            $esPrimeraFilaDelProfesor = $f["id_profesor"] !== $__profesorAnterior;
                            $__profesorAnterior = $f["id_profesor"];

                            $avancePca = (int) $f["avance_pca"];
                            $totalPca = pca_total_unidades($f["nivel"]);
                            $pctPca = $totalPca > 0 ? min(100, (int) round($avancePca / $totalPca * 100)) : 0;

                            $avanceUnidades = $avanceUnidadesPorProfesorGrado[$clave] ?? 0;
                            $totalUnidades = $totalCursosPorProfesorGrado[$clave] ?? 0;
                            $pctUnidades = $totalUnidades > 0 ? min(100, (int) round($avanceUnidades / $totalUnidades * 100)) : 0;

                            $avanceSesiones = $avanceSesionesPorProfesorGrado[$clave] ?? 0;
                            $totalUnidadesSesiones = materiales_total_unidades($f["nivel"]);
                            $totalSesiones = $totalUnidadesSesiones * materiales_total_semanas($f["nivel"]);
                            $pctSesiones = $totalSesiones > 0 ? min(100, (int) round($avanceSesiones / $totalSesiones * 100)) : 0;
                        ?>
                        <tr class="<?= $esPrimeraFilaDelProfesor ? "reportes-profesor-inicio" : "" ?>" data-nivel="<?= htmlspecialchars($f["nivel"]) ?>" data-grado="<?= (int) $f["grado"] ?>">
                            <?php if ($esPrimeraFilaDelProfesor): ?>
                                <td class="reportes-profesor-celda" rowspan="<?= $filasPorProfesor[$f["id_profesor"]] ?>">
                                    <?= htmlspecialchars($f["nombres"] . " " . $f["apellidos"]) ?>
                                    <?php if ($filasPorProfesor[$f["id_profesor"]] > 1): ?>
                                        <span class="reportes-grados-count"><?= $filasPorProfesor[$f["id_profesor"]] ?> grados</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars($f["grado_nombre"]) ?> · <?= htmlspecialchars(ucfirst(strtolower($f["nivel"]))) ?></td>
                            <td>
                                <div class="card-progress-head"><span><?= $avancePca ?>/<?= $totalPca ?></span><span><?= $pctPca ?>%</span></div>
                                <div class="progress-bar"><div class="progress-bar-fill <?= materiales_color_progreso($pctPca) ?>" style="width:<?= $pctPca ?>%"></div></div>
                            </td>
                            <td>
                                <div class="card-progress-head"><span><?= $avanceUnidades ?>/<?= $totalUnidades ?></span><span><?= $pctUnidades ?>%</span></div>
                                <div class="progress-bar"><div class="progress-bar-fill <?= materiales_color_progreso($pctUnidades) ?>" style="width:<?= $pctUnidades ?>%"></div></div>
                            </td>
                            <td>
                                <div class="card-progress-head"><span><?= $avanceSesiones ?>/<?= $totalSesiones ?></span><span><?= $pctSesiones ?>%</span></div>
                                <div class="progress-bar"><div class="progress-bar-fill <?= materiales_color_progreso($pctSesiones) ?>" style="width:<?= $pctSesiones ?>%"></div></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2>Trabajos por estado</h2>
    <?php if (count($trabajosPorEstado) === 0): ?>
        <p class="placeholder-text">Sin datos todavía.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Estado</th><th>Cantidad</th></tr></thead>
                <tbody>
                    <?php foreach ($trabajosPorEstado as $t): ?>
                        <tr><td><?= htmlspecialchars($t['estado']) ?></td><td><?= (int)$t['total'] ?></td></tr>
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

    var GRADOS = <?= json_encode($gradosPorNivel, JSON_UNESCAPED_UNICODE) ?>;

    // Grado depende del Nivel: solo los grados que existen en ese nivel.
    function llenarGrados() {
        var n = filtroNivel.value;
        filtroGrado.innerHTML = "";
        var o = document.createElement("option");
        o.value = "";
        if (!n) {
            o.textContent = "Primero elige el nivel";
            filtroGrado.appendChild(o);
            filtroGrado.disabled = true;
            return;
        }
        o.textContent = "Todos los grados";
        filtroGrado.appendChild(o);
        (GRADOS[n] || []).forEach(function (g) {
            var op = document.createElement("option");
            op.value = String(g.v); op.textContent = g.t;
            filtroGrado.appendChild(op);
        });
        filtroGrado.disabled = false;
    }

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

    filtroNivel.addEventListener("change", function () { llenarGrados(); aplicarFiltro(); });
    filtroGrado.addEventListener("change", aplicarFiltro);

    // Las celdas con el nombre del profesor se sacan de la tabla al iniciar (arriba);
    // hay que volver a colocarlas aunque no se haya tocado ningún filtro, si no, la
    // columna "Profesor" queda vacía y todas las columnas se corren a la izquierda.
    llenarGrados();
    aplicarFiltro();
})();
</script>

</body>
</html>
