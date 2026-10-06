<?php

require_once __DIR__ . "/../backend/partials/profesor_bootstrap.php";
require_once __DIR__ . "/../backend/config/profesor_tutoria.php";
require_once __DIR__ . "/../backend/config/calendario_academico.php";
require_once __DIR__ . "/../backend/config/materiales_historial.php";

$avisoCierreBimestre = calendario_banner_cierre($conexion);

// Confirmación visual (Nº6 de las propuestas de UX): si alguna
// página redirige aquí después de una acción (ej. corregir un
// envío), el mensaje de éxito/error se muestra arriba de todo en
// vez de que el cambio pase desapercibido. Mismo mecanismo
// flash_set()/flash_get() que ya usan trabajos.php, envios.php,
// subdirector/periodos.php, etc.
[$mensajeFlash, $mensajeFlashTipo] = flash_get();

// ==================================================
// CACHE SIMPLE EN SESIÓN (2.5 min) PARA CONTEOS DEL DASHBOARD
//
// Solo para los 3 conteos que se repiten en cada carga y no cambian
// segundo a segundo (alumnos matriculados, trabajos por estado,
// envíos a Subdirección). Vive en $_SESSION, por lo tanto nunca se
// mezcla entre profesores distintos (cada uno tiene su propia
// sesión). Se invalida sola al vencer el TTL; profesor/envios.php
// además la limpia manualmente justo después de enviar un trabajo o
// subir una corrección, para que el aviso de "requiere cambios" no
// se quede desactualizado hasta 2.5 minutos después de corregirlo.
//
// A propósito NO se usa para $ultimaActividadPorGrado ni para el
// feed de "Actividad reciente" (más abajo): ahí la información
// reciente es justamente el punto, cachearla le quitaría sentido.
// ==================================================

function dashboard_cache(string $clave, int $ttlSegundos, callable $calcular) {

    $ahora = time();

    if (
        isset($_SESSION["dashboard_cache"][$clave])
        && $_SESSION["dashboard_cache"][$clave]["expira"] > $ahora
    ) {
        return $_SESSION["dashboard_cache"][$clave]["valor"];
    }

    $valor = $calcular();
    $_SESSION["dashboard_cache"][$clave] = ["valor" => $valor, "expira" => $ahora + $ttlSegundos];

    return $valor;

}

const DASHBOARD_CACHE_TTL = 150; // 2.5 minutos

// ==================================================
// GRADOS (NIVEL + GRADO) ASIGNADOS AL PROFESOR
// ==================================================

$gradosAsignados = profesor_grados_asignados($conexion, $profesor["id_profesor"]);

// ==================================================
// TUTORÍA (SOLO Secundaria, opcional, año escolar actual)
//
// Nunca se asume por el nivel del profesor: solo existe si hay una
// fila real en profesor_tutoria para el período académico actual
// (ver profesor_tutoria_actual()). Se muestra en su propia sección,
// separada de "Grados asignados", para no mezclarla con los cursos
// normales.
// ==================================================

$tutoriaDashboard = profesor_tutoria_actual($conexion, $profesor["id_profesor"]);

// ==================================================
// DATOS DERIVADOS PARA EL RESUMEN DEL DASHBOARD
//
// Ninguno de estos valores dispara una consulta nueva: se calculan
// a partir de $gradosAsignados (ya trae cursos/secciones por grado).
// Nunca se usa profesores.nivel_educativo (columna aún sin poblar de
// forma confiable, ver profesor_grados.php) — el nivel que se
// muestra sale de los grados realmente asignados.
// ==================================================

$cursoIdsUnicos = [];
foreach ($gradosAsignados as $ng) {
    foreach ($ng["cursos"] as $c) {
        $cursoIdsUnicos[$c["id_curso"]] = true;
    }
}
$totalCursosProfesor = count($cursoIdsUnicos);

$nivelesProfesor = array_values(array_unique(array_column($gradosAsignados, "nivel")));
$nivelesProfesorLabel = implode(" y ", array_map(
    fn($n) => ucfirst(strtolower($n)),
    $nivelesProfesor
));

// ==================================================
// AVANCE DEL PCA Y ÚLTIMA ACTIVIDAD, POR GRADO
//
// Reutiliza exactamente las mismas tablas y el mismo filtro
// (id_profesor + id_nivel_grado) que ya usan pca.php/unidades.php/
// sesiones.php para leer y guardar archivos — aquí solo se agregan
// en 2 consultas de solo lectura (agrupadas por grado, no una por
// cada tarjeta) para no repetir accesos a BD. No se crea ninguna
// tabla ni columna nueva.
// ==================================================

$avancePcaPorGrado = [];     // id_nivel_grado => 1 si ya subió el (único) archivo de PCA, 0 si no
$avanceUnidadesPorGrado = []; // id_nivel_grado => nº de cursos con archivo de Unidades subido
$avanceSesionesPorGrado = []; // id_nivel_grado => nº de pares Unidad+Semana "completos"
$ultimaActividadPorGrado = []; // id_nivel_grado => fecha (string) del archivo más reciente

$idsNivelGradoProfesor = array_map(fn($ng) => (int) $ng["id_nivel_grado"], $gradosAsignados);

// Cuántos cursos dicta ESTE profesor en cada uno de sus grados (ya
// viene calculado en $gradosAsignados vía profesor_cursos_por_grado()
// — fuente: profesor_curso_grado). Se usa como "meta" para considerar
// una Unidad/Sesión "completa": que el profesor haya subido material
// de TODOS los cursos que él dicta ahí, no solo de uno. Nunca se usa
// el catálogo completo de cursos del nivel, porque en Secundaria un
// profesor normalmente dicta un solo curso y jamás llegaría a 100%
// si se le exigiera cubrir los cursos de sus colegas.
$totalCursosPorGrado = [];
foreach ($gradosAsignados as $ng) {
    $totalCursosPorGrado[(int) $ng["id_nivel_grado"]] = count($ng["cursos"]);
}

if (count($idsNivelGradoProfesor) > 0) {

    $marcadores = implode(",", array_fill(0, count($idsNivelGradoProfesor), "?"));

    $stmtAvance = $conexion->prepare("
        SELECT id_nivel_grado, COUNT(DISTINCT unidad) AS total
        FROM profesor_pca_archivos
        WHERE id_profesor = ? AND id_nivel_grado IN ($marcadores)
        GROUP BY id_nivel_grado
    ");
    $stmtAvance->execute(array_merge([$profesor["id_profesor"]], $idsNivelGradoProfesor));
    foreach ($stmtAvance->fetchAll() as $fila) {
        $avancePcaPorGrado[(int) $fila["id_nivel_grado"]] = (int) $fila["total"];
    }

    // UNIDADES: ya no se organiza por Unidad/Semana (U1..U10); es 1
    // archivo por curso (ver profesor/unidades.php). El avance es
    // simplemente cuántos de los cursos que el profesor dicta en ese
    // grado ya tienen archivo subido, sobre el total de cursos que
    // dicta — mismo criterio de "meta" que ya usaba antes, solo que
    // ahora se cuenta directo por curso, sin la capa de unidad.
    $stmtUnidadesCursos = $conexion->prepare("
        SELECT id_nivel_grado, COUNT(DISTINCT curso) AS cursos_con_archivo
        FROM profesor_unidad_archivos
        WHERE id_profesor = ? AND id_nivel_grado IN ($marcadores) AND unidad = 1 AND semana = 1
        GROUP BY id_nivel_grado
    ");
    $stmtUnidadesCursos->execute(array_merge([$profesor["id_profesor"]], $idsNivelGradoProfesor));
    foreach ($stmtUnidadesCursos->fetchAll() as $fila) {
        $avanceUnidadesPorGrado[(int) $fila["id_nivel_grado"]] = (int) $fila["cursos_con_archivo"];
    }

    // SESIONES: mismo criterio que Unidades, pero por cada par
    // Unidad+Semana (Sem-01..Sem-05) en vez de por unidad completa.
    $stmtSesionesCursos = $conexion->prepare("
        SELECT id_nivel_grado, unidad, semana, COUNT(DISTINCT curso) AS cursos_cubiertos
        FROM profesor_sesion_archivos
        WHERE id_profesor = ? AND id_nivel_grado IN ($marcadores)
        GROUP BY id_nivel_grado, unidad, semana
    ");
    $stmtSesionesCursos->execute(array_merge([$profesor["id_profesor"]], $idsNivelGradoProfesor));
    foreach ($stmtSesionesCursos->fetchAll() as $fila) {
        $idNg = (int) $fila["id_nivel_grado"];
        $metaCursos = $totalCursosPorGrado[$idNg] ?? 0;
        if ($metaCursos > 0 && (int) $fila["cursos_cubiertos"] >= $metaCursos) {
            $avanceSesionesPorGrado[$idNg] = ($avanceSesionesPorGrado[$idNg] ?? 0) + 1;
        }
    }

    $stmtActividad = $conexion->prepare("
        SELECT id_nivel_grado, MAX(creado_en) AS ultimo FROM (
            SELECT id_nivel_grado, creado_en FROM profesor_pca_archivos WHERE id_profesor = ? AND id_nivel_grado IN ($marcadores)
            UNION ALL
            SELECT id_nivel_grado, creado_en FROM profesor_unidad_archivos WHERE id_profesor = ? AND id_nivel_grado IN ($marcadores)
            UNION ALL
            SELECT id_nivel_grado, creado_en FROM profesor_sesion_archivos WHERE id_profesor = ? AND id_nivel_grado IN ($marcadores)
        ) actividad
        GROUP BY id_nivel_grado
    ");
    $stmtActividad->execute(array_merge(
        [$profesor["id_profesor"]], $idsNivelGradoProfesor,
        [$profesor["id_profesor"]], $idsNivelGradoProfesor,
        [$profesor["id_profesor"]], $idsNivelGradoProfesor
    ));
    foreach ($stmtActividad->fetchAll() as $fila) {
        $ultimaActividadPorGrado[(int) $fila["id_nivel_grado"]] = $fila["ultimo"];
    }

}

/**
 * "hace X días" a partir de una fecha de MySQL. Puramente un formato
 * de presentación sobre una fecha que ya existe en BD (creado_en);
 * no calcula ni asume nada que no esté guardado.
 */
function dashboard_tiempo_relativo(?string $fecha): ?string {

    if (!$fecha) {
        return null;
    }

    $dias = (int) floor((time() - strtotime($fecha)) / 86400);

    if ($dias <= 0) {
        return "hoy";
    }
    if ($dias === 1) {
        return "hace 1 día";
    }
    if ($dias < 30) {
        return "hace {$dias} días";
    }

    $meses = (int) floor($dias / 30);
    return $meses === 1 ? "hace 1 mes" : "hace {$meses} meses";

}

// ==================================================
// ACTIVIDAD RECIENTE (últimos 5 archivos subidos/reemplazados)
//
// Reutiliza materiales_historial_listar(), la misma función que ya
// usa profesor/historial.php (tabla materiales_historial, que ya
// registra cada SUBIDO/REEMPLAZADO/ELIMINADO de PCA/Unidades/
// Sesiones) — nada nuevo en BD, solo se muestra un adelanto de 5
// filas aquí para no obligar al profesor a entrar módulo por módulo
// o a la página completa de Historial para ver qué subió último.
//
// A propósito SIN cache: es justamente el dato que más importa ver
// fresco en cada carga.
// ==================================================

$actividadReciente = materiales_historial_listar($conexion, $profesor["id_profesor"], null, null, 5);

$actividadEtiquetasModulo = ["PCA" => "PCA", "UNIDADES" => "Unidades", "SESIONES" => "Sesiones"];
$actividadEtiquetasAccion = [
    "SUBIDO" => "Subido",
    "REEMPLAZADO" => "Reemplazado",
    "ELIMINADO" => "Eliminado",
];

// ==================================================
// CONTEOS PARA LAS TARJETAS RESUMEN
//
// El Dashboard ya no muestra las listas/tablas completas de
// alumnos, trabajos, envíos y notificaciones (cada una vive ahora
// en su propia página: alumnos.php, trabajos.php, envios.php,
// notificaciones.php). Aquí solo se necesitan los conteos, así que
// se reutilizan las mismas consultas de siempre, solo que ya no se
// listan sus resultados fila por fila en esta pantalla.
// ==================================================

$idProfesorActual = (int) $profesor["id_profesor"];

$totalAlumnos = dashboard_cache("total_alumnos_{$idProfesorActual}", DASHBOARD_CACHE_TTL, function () use ($conexion, $idProfesorActual) {
    $stmt = $conexion->prepare("
        SELECT COUNT(*) AS total
        FROM asignaciones_docentes ad
        INNER JOIN grados_secciones gs ON gs.id_grado_seccion = ad.id_grado_seccion
        INNER JOIN matriculas m ON m.id_grado_seccion = gs.id_grado_seccion AND m.estado = 'ACTIVA'
        INNER JOIN alumnos a ON a.id_alumno = m.id_alumno
        WHERE ad.id_profesor = ?
    ");
    $stmt->execute([$idProfesorActual]);
    return (int) $stmt->fetchColumn();
});

// Trabajos por estado + total + cuántos vencen esta semana (Lunes a
// Domingo, hora del servidor), en una sola consulta/cache porque las
// 3 cifras salen de la misma tabla `trabajos`. "Esta semana" usa
// trabajos.fecha_limite (única fecha académica real que existe en
// BD, igual que ya se documentó para las notificaciones de
// vencimiento) y solo cuenta los que siguen PENDIENTE — uno ya
// ENTREGADO o CERRADO no necesita avisar aunque su fecha caiga esta
// semana.
$datosTrabajos = dashboard_cache("trabajos_resumen_{$idProfesorActual}", DASHBOARD_CACHE_TTL, function () use ($conexion, $idProfesorActual) {

    $stmt = $conexion->prepare("
        SELECT estado, COUNT(*) AS total
        FROM trabajos
        WHERE id_profesor = ?
        GROUP BY estado
    ");
    $stmt->execute([$idProfesorActual]);

    $porEstado = [];
    $total = 0;
    foreach ($stmt->fetchAll() as $fila) {
        $porEstado[$fila["estado"]] = (int) $fila["total"];
        $total += (int) $fila["total"];
    }

    $inicioSemana = new DateTimeImmutable("monday this week");
    $finSemana = new DateTimeImmutable("sunday this week");

    $stmtSemana = $conexion->prepare("
        SELECT COUNT(*) AS total
        FROM trabajos
        WHERE id_profesor = ? AND estado = 'PENDIENTE' AND fecha_limite BETWEEN ? AND ?
    ");
    $stmtSemana->execute([$idProfesorActual, $inicioSemana->format("Y-m-d"), $finSemana->format("Y-m-d")]);

    return [
        "por_estado" => $porEstado,
        "total" => $total,
        "esta_semana" => (int) $stmtSemana->fetchColumn(),
    ];

});

$trabajosPorEstado = $datosTrabajos["por_estado"];
$totalTrabajos = $datosTrabajos["total"];
$trabajosEstaSemana = $datosTrabajos["esta_semana"];

// Se trae también id_envio + titulo + observación de la versión
// actual (misma consulta que ya usa envios.php) para poder mostrar
// en el Resumen CUÁL envío requiere cambios y por qué, en vez de
// solo el conteo genérico de antes. profesor/envios.php limpia esta
// misma clave de caché justo después de enviar/corregir un trabajo,
// para no dejar un aviso desactualizado hasta que venza el TTL.
$misEnvios = dashboard_cache("mis_envios_{$idProfesorActual}", DASHBOARD_CACHE_TTL, function () use ($conexion, $idProfesorActual) {
    $stmt = $conexion->prepare("
        SELECT
            e.id_envio, e.titulo, e.estado,
            h.observacion AS observacion_actual
        FROM envios_trabajo e
        INNER JOIN envios_trabajo_historial h
            ON h.id_envio = e.id_envio AND h.version = e.version_actual
        WHERE e.id_profesor = ?
    ");
    $stmt->execute([$idProfesorActual]);
    return $stmt->fetchAll();
});

$enviosPendientes = count(array_filter($misEnvios, fn($e) => in_array($e["estado"], ["ENVIADO", "EN_REVISION", "CORREGIDO"], true)));
$enviosQueRequierenCambios = array_values(array_filter($misEnvios, fn($e) => $e["estado"] === "REQUIERE_CAMBIOS"));
$enviosRequierenCambios = count($enviosQueRequierenCambios);
$enviosAprobados = count(array_filter($misEnvios, fn($e) => $e["estado"] === "APROBADO"));

$notificacionesNoLeidas = notificaciones_no_leidas($conexion, $_SESSION["id_usuario"]);

// Vista previa para el dropdown de la campanita (Nº5): las últimas 3
// SIN LEER. Reutiliza notificaciones_listar(), la misma función que
// ya usa profesor/notificaciones.php — sin consulta nueva a la BD,
// solo se filtra a "no leídas" y se recorta a 3.
$notificacionesPreview = array_slice(
    array_values(array_filter(
        notificaciones_listar($conexion, $_SESSION["id_usuario"], 10),
        fn($n) => (int) $n["leido"] === 0
    )),
    0,
    3
);

?>



<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Panel del Profesor - I.E.P. 88044 Abraham Valdelomar
    </title>

    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">

</head>


<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">



<!-- ==================================================
     ENCABEZADO
================================================== -->

<header>

    <div>
        <span class="header-eyebrow">Dashboard</span>
        <h1>Panel del Profesor</h1>
    </div>

    <div class="header-actions">

        <?php if (count($gradosAsignados) === 1): ?>
            <span class="header-grade-chip">
                <?= icon("backpack") ?>
                <?= htmlspecialchars(nivel_grado_label($gradosAsignados[0])) ?>
            </span>
        <?php endif; ?>

        <details class="notif-dropdown">
            <summary class="notif-bell" title="Notificaciones"
               aria-label="<?= $notificacionesNoLeidas > 0 ? "Notificaciones, {$notificacionesNoLeidas} sin leer" : "Notificaciones, sin pendientes" ?>">
                <?= icon("bell", "icon") ?>
                <?php if ($notificacionesNoLeidas > 0): ?>
                    <span class="notif-count" aria-hidden="true"><?= $notificacionesNoLeidas ?></span>
                <?php endif; ?>
            </summary>

            <div class="notif-dropdown-panel">
                <?php if (count($notificacionesPreview) === 0): ?>
                    <p class="placeholder-text" style="padding:16px;">
                        No tienes notificaciones sin leer.
                    </p>
                <?php else: ?>
                    <ul class="notif-dropdown-lista">
                        <?php foreach ($notificacionesPreview as $n): ?>
                            <li>
                                <p><?= htmlspecialchars($n["mensaje"]) ?></p>
                                <span class="notif-dropdown-fecha"><?= htmlspecialchars(dashboard_tiempo_relativo($n["creado_en"]) ?? "") ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <a href="notificaciones.php" class="notif-dropdown-ver-todas">Ver todo →</a>
            </div>
        </details>

        <details class="reportes-dropdown">
            <summary class="btn-secondary">
                <?= icon("file-text") ?> Reportes
            </summary>
            <div class="reportes-dropdown-panel">
                <a href="../backend/reportes/avance_pdf.php?descargar=1" target="_blank">
                    Mi avance (PDF)
                    <span>PCA, Unidades y Sesiones</span>
                </a>
                <a href="../backend/reportes/resumen_pdf.php?descargar=1" target="_blank">
                    Resumen general (PDF)
                    <span>Alumnos, trabajos y envíos</span>
                </a>
            </div>
        </details>

        <div class="header-identity">
            <div class="header-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($profesor["nombres"], 0, 1)) . mb_strtoupper(mb_substr($profesor["apellidos"], 0, 1))) ?></div>
            <div class="header-identity-text">
                <strong><?= htmlspecialchars($profesor["nombres"]) ?> <?= htmlspecialchars($profesor["apellidos"]) ?></strong>
                <span><?= $nivelesProfesorLabel !== "" ? htmlspecialchars($nivelesProfesorLabel) . " · " : "" ?>I.E.P. 88044</span>
            </div>
        </div>

    </div>

</header>



<!-- ==================================================
     CONTENIDO PRINCIPAL
================================================== -->

<main>

    <?php if ($mensajeFlash): ?>
        <div class="panel-alert panel-alert-<?= htmlspecialchars($mensajeFlashTipo) ?>">
            <?= icon($mensajeFlashTipo === "success" ? "check-circle" : "alert-triangle") ?>
            <?= htmlspecialchars($mensajeFlash) ?>
        </div>
    <?php endif; ?>

    <!-- ==================================================
         BIENVENIDA
    ================================================== -->

    <section class="panel-section">
        <div class="panel-section-head">
            <h2>Bienvenido, <?= htmlspecialchars($profesor["nombres"]) ?></h2>
            <p class="panel-section-sub">
                Resumen de tu actividad académica.
            </p>
        </div>

        <?php if ($avisoCierreBimestre): ?>
            <div class="resumen-alert<?= $avisoCierreBimestre["urgente"] ? "" : " is-info" ?>">
                <?= icon("clock") ?>
                <span><?= htmlspecialchars($avisoCierreBimestre["texto"]) ?> Sube tus PCA/Unidades/Sesiones pendientes antes de esa fecha.</span>
            </div>
        <?php endif; ?>
    </section>


    <!-- ==================================================
         NECESITA TU ATENCIÓN
         Único lugar del Dashboard que junta todo lo accionable
         (antes estaba repartido entre Resumen y Mis módulos):
         envíos que requieren cambios, trabajos con entrega esta
         semana y notificaciones sin leer. Solo aparece si hay
         al menos un aviso — no ocupa espacio cuando todo está al día.
    ================================================== -->

    <?php $hayAvisos = $enviosRequierenCambios > 0 || $trabajosEstaSemana > 0 || $notificacionesNoLeidas > 0; ?>

    <?php if ($hayAvisos): ?>

        <section class="panel-section">
            <div class="panel-section-head">
                <h2><?= icon("alert-triangle") ?> Necesita tu atención</h2>
            </div>

            <?php foreach ($enviosQueRequierenCambios as $e): ?>
                <a href="envios.php#envio-<?= (int) $e["id_envio"] ?>" class="resumen-alert">
                    <?= icon("alert-triangle") ?>
                    <span>
                        <strong><?= htmlspecialchars($e["titulo"]) ?></strong> requiere cambios
                        <?php if (!empty($e["observacion_actual"])): ?>
                            <span class="obs-text"> — "<?= htmlspecialchars(mb_strimwidth($e["observacion_actual"], 0, 90, "…")) ?>"</span>
                        <?php endif; ?>
                    </span>
                </a>
            <?php endforeach; ?>

            <?php if ($trabajosEstaSemana > 0): ?>
                <a href="trabajos.php" class="resumen-alert">
                    <?= icon("briefcase") ?>
                    <span>
                        <strong><?= $trabajosEstaSemana ?></strong>
                        <?= $trabajosEstaSemana === 1 ? "trabajo tiene" : "trabajos tienen" ?> entrega esta semana
                    </span>
                </a>
            <?php endif; ?>

            <?php if ($notificacionesNoLeidas > 0): ?>
                <a href="notificaciones.php" class="resumen-alert is-info">
                    <?= icon("bell") ?>
                    <span>
                        <strong><?= $notificacionesNoLeidas ?></strong>
                        <?= $notificacionesNoLeidas === 1 ? "notificación nueva sin leer" : "notificaciones nuevas sin leer" ?>
                    </span>
                </a>
            <?php endif; ?>
        </section>

    <?php endif; ?>


    <!-- ==================================================
         RESUMEN
    ================================================== -->

    <section class="panel-section">
        <div class="panel-section-head">
            <h2>Resumen</h2>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-value"><?= count($gradosAsignados) ?></span>
                <span class="stat-label">Grados asignados</span>
            </div>
            <div class="stat-card">
                <span class="stat-value"><?= $totalCursosProfesor ?></span>
                <span class="stat-label">Cursos a cargo</span>
            </div>
            <div class="stat-card">
                <span class="stat-value"><?= $totalAlumnos ?></span>
                <span class="stat-label">Alumnos</span>
            </div>
            <div class="stat-card">
                <span class="stat-value"><?= $totalTrabajos ?></span>
                <span class="stat-label">Trabajos registrados</span>
            </div>
            <div class="stat-card<?= $trabajosEstaSemana > 0 ? " stat-card-alerta" : "" ?>">
                <span class="stat-value"><?= $trabajosEstaSemana ?></span>
                <span class="stat-label">Con entrega esta semana</span>
            </div>
        </div>
        <p class="placeholder-text" style="margin-top:10px;">
            <a href="calendario.php">Ver el detalle en el Calendario →</a>
        </p>
    </section>


    <!-- ==================================================
         GRADOS ASIGNADOS
    ================================================== -->

    <section class="panel-section">

        <div class="panel-section-head">
            <h2>Grados asignados</h2>
            <?php if (count($gradosAsignados) > 0): ?>
                <p class="panel-section-sub">
                    Selecciona un grado para organizar su PCA, Unidades, Sesiones y
                    Documentos Institucionales.
                </p>
            <?php endif; ?>
        </div>

        <?php if (count($gradosAsignados) > 0): ?>
            <!-- Leyenda: explica de una sola vez los 2 códigos visuales que
                 se repiten en varias partes del dashboard (semáforo de
                 color y los 3 términos PCA/Unidades/Sesiones), para no
                 tener que adivinarlos por contexto. -->
            <details class="dashboard-leyenda">
                <summary>¿Qué significan los colores y las siglas?</summary>
                <div class="dashboard-leyenda-body">
                    <div>
                        <strong>Colores de avance</strong>
                        <ul>
                            <li><span class="leyenda-punto is-green"></span> Completo</li>
                            <li><span class="leyenda-punto is-amber"></span> En progreso</li>
                            <li><span class="leyenda-punto is-red"></span> Sin empezar / muy atrasado</li>
                        </ul>
                    </div>
                    <div>
                        <strong>Términos</strong>
                        <ul>
                            <li><strong>PCA:</strong> Programación Curricular Anual — un archivo único por grado.</li>
                            <li><strong>Unidades:</strong> un archivo por cada curso del grado.</li>
                            <li><strong>Sesiones:</strong> el material de clase, por semana y curso.</li>
                        </ul>
                    </div>
                </div>
            </details>
        <?php endif; ?>

        <?php
            // "Primeros pasos": aparece solo si el profesor ya tiene
            // grado(s) asignado(s) pero todavía no subió NADA en
            // ningún módulo (ni PCA, ni Unidades, ni Sesiones) —
            // reemplaza la sensación de "pantalla vacía" por una ruta
            // clara de 3 pasos.
            $sinNingunaActividad = count($gradosAsignados) > 0
                && empty($avancePcaPorGrado)
                && empty($avanceUnidadesPorGrado)
                && empty($avanceSesionesPorGrado);
        ?>

        <?php if ($sinNingunaActividad): ?>
            <?php $idPrimerGrado = (int) $gradosAsignados[0]["id_nivel_grado"]; ?>
            <div class="primeros-pasos">
                <h3><?= icon("backpack") ?> Primeros pasos</h3>
                <p>Todavía no subiste ningún archivo. Empieza por acá:</p>
                <ol>
                    <li><a href="pca.php?id_nivel_grado=<?= $idPrimerGrado ?>">Sube tu PCA</a> (un solo archivo por grado)</li>
                    <li><a href="unidades.php?id_nivel_grado=<?= $idPrimerGrado ?>">Sube tus Unidades</a> (un archivo por curso)</li>
                    <li><a href="sesiones.php?id_nivel_grado=<?= $idPrimerGrado ?>">Sube tus Sesiones</a> (por semana y curso)</li>
                </ol>
            </div>
        <?php endif; ?>

        <?php if (count($gradosAsignados) === 0): ?>

            <div class="empty-state">
                <span class="empty-state-icon" aria-hidden="true"><?= icon("backpack") ?></span>
                <h2>Todavía no tienes grados asignados</h2>
                <p>
                    Pide al administrador que te asigne un aula desde «Gestionar profesores»
                    para poder organizar tu PCA, Unidades, Sesiones y Documentos Institucionales.
                </p>
            </div>

        <?php else: ?>

            <?php if (count($gradosAsignados) > 1): ?>

                <!-- ==================================================
                     COMPARATIVA ENTRE GRADOS
                     Solo aparece con 2+ grados asignados. Reutiliza los
                     mismos arreglos ya calculados arriba (avancePorGrado,
                     etc.) — ninguna consulta nueva a la BD, solo la misma
                     aritmética de porcentaje que ya usa cada tarjeta, para
                     poder comparar de un vistazo en qué grado falta subir
                     material.
                ================================================== -->
                <?php
                    $comparativaGrados = [];
                    $gradosConPcaCompleto = 0;
                    $gradosConUnidadesCompletas = 0;

                    foreach ($gradosAsignados as $ng) {
                        $idNgComp = (int) $ng["id_nivel_grado"];

                        $avanceComp = $avancePcaPorGrado[$idNgComp] ?? 0;
                        $totalPcaComp = pca_total_unidades($ng["nivel"]);
                        $pctPcaComp = $totalPcaComp > 0 ? min(100, (int) round($avanceComp / $totalPcaComp * 100)) : 0;

                        $avanceUnidComp = $avanceUnidadesPorGrado[$idNgComp] ?? 0;
                        $totalUnidComp = materiales_total_cursos($ng["nivel"]);
                        $pctUnidComp = $totalUnidComp > 0 ? min(100, (int) round($avanceUnidComp / $totalUnidComp * 100)) : 0;

                        $avanceSesComp = $avanceSesionesPorGrado[$idNgComp] ?? 0;
                        $totalUnidSesComp = materiales_total_unidades($ng["nivel"]);
                        $totalSesComp = $totalUnidSesComp * materiales_total_semanas($ng["nivel"]);
                        $pctSesComp = $totalSesComp > 0 ? min(100, (int) round($avanceSesComp / $totalSesComp * 100)) : 0;

                        if ($pctPcaComp >= 100) { $gradosConPcaCompleto++; }
                        if ($pctUnidComp >= 100) { $gradosConUnidadesCompletas++; }

                        $comparativaGrados[] = [
                            "id_nivel_grado" => $idNgComp,
                            "label" => nivel_grado_label($ng),
                            "pct_pca" => $pctPcaComp,
                            "pct_unidades" => $pctUnidComp,
                            "pct_sesiones" => $pctSesComp,
                            "pct_promedio" => ($pctPcaComp + $pctUnidComp + $pctSesComp) / 3,
                        ];
                    }

                    // El grado más atrasado primero, para que salte a la
                    // vista sin tener que leer fila por fila.
                    usort($comparativaGrados, fn($a, $b) => $a["pct_promedio"] <=> $b["pct_promedio"]);
                ?>

                <div class="comparativa-grados">

                    <div class="comparativa-grados-resumen">
                        <span><?= $gradosConPcaCompleto ?>/<?= count($gradosAsignados) ?> grados con PCA completo</span>
                        <span><?= $gradosConUnidadesCompletas ?>/<?= count($gradosAsignados) ?> grados con Unidades completas</span>
                    </div>

                    <div class="comparativa-grado-fila comparativa-grados-leyenda" aria-hidden="true">
                        <span></span>
                        <span>PCA</span>
                        <span>Unidades</span>
                        <span>Sesiones</span>
                    </div>

                    <?php foreach ($comparativaGrados as $r): ?>
                        <a href="grado.php?id_nivel_grado=<?= $r["id_nivel_grado"] ?>" class="comparativa-grado-fila">
                            <span class="comparativa-grado-nombre"><?= htmlspecialchars($r["label"]) ?></span>

                            <span class="comparativa-mini-barra" role="img" aria-label="PCA: <?= $r["pct_pca"] ?>%" title="PCA: <?= $r["pct_pca"] ?>%">
                                <span class="comparativa-mini-barra-fill <?= materiales_color_progreso($r["pct_pca"]) ?>" style="width:<?= $r["pct_pca"] ?>%"></span>
                            </span>

                            <span class="comparativa-mini-barra" role="img" aria-label="Unidades: <?= $r["pct_unidades"] ?>%" title="Unidades: <?= $r["pct_unidades"] ?>%">
                                <span class="comparativa-mini-barra-fill <?= materiales_color_progreso($r["pct_unidades"]) ?>" style="width:<?= $r["pct_unidades"] ?>%"></span>
                            </span>

                            <span class="comparativa-mini-barra" role="img" aria-label="Sesiones: <?= $r["pct_sesiones"] ?>%" title="Sesiones: <?= $r["pct_sesiones"] ?>%">
                                <span class="comparativa-mini-barra-fill <?= materiales_color_progreso($r["pct_sesiones"]) ?>" style="width:<?= $r["pct_sesiones"] ?>%"></span>
                            </span>
                        </a>
                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

            <div class="cards-grados">
                <?php foreach ($gradosAsignados as $ng): ?>
                    <?php
                        $idNg = (int) $ng["id_nivel_grado"];

                        $avance = $avancePcaPorGrado[$idNg] ?? 0;
                        $totalUnidadesPca = pca_total_unidades($ng["nivel"]);
                        $pctPca = $totalUnidadesPca > 0 ? min(100, (int) round($avance / $totalUnidadesPca * 100)) : 0;

                        // Unidades: ya no es una escala U1..U10; es 1 archivo
                        // por curso (ver profesor/unidades.php). El total ya
                        // NO se basa en profesor_curso_grado (cuántos cursos
                        // le "asignaron" a este profesor puntualmente en la
                        // BD, que muchas veces está incompleto porque esa
                        // tabla se carga a mano en phpMyAdmin) — se usa el
                        // total de cursos del NIVEL completo (7 en Primaria,
                        // 10 en Secundaria, ver materiales_total_cursos()).
                        // OJO: materiales_total_unidades() es la escala
                        // pedagógica U1..U10 (vale 10 para ambos niveles),
                        // no la cantidad de cursos — no confundir.
                        $avanceUnidades = $avanceUnidadesPorGrado[$idNg] ?? 0;
                        $totalUnidadesGrado = materiales_total_cursos($ng["nivel"]);
                        $pctUnidades = $totalUnidadesGrado > 0 ? min(100, (int) round($avanceUnidades / $totalUnidadesGrado * 100)) : 0;

                        // Sesiones: sigue con la escala U1..U10 → Sem-01..Sem-05
                        // (sin cambios), con el mismo criterio de completitud
                        // de "todos los cursos del profesor en ese grado".
                        $avanceSesiones = $avanceSesionesPorGrado[$idNg] ?? 0;
                        $totalUnidadesSesiones = materiales_total_unidades($ng["nivel"]);
                        $totalSesionesGrado = $totalUnidadesSesiones * materiales_total_semanas($ng["nivel"]);
                        $pctSesiones = $totalSesionesGrado > 0 ? min(100, (int) round($avanceSesiones / $totalSesionesGrado * 100)) : 0;

                        $ultima = dashboard_tiempo_relativo($ultimaActividadPorGrado[$idNg] ?? null);

                        // Aviso de inactividad: 10+ días sin ningún
                        // archivo subido (o directamente ninguno subido
                        // todavía) resalta la tarjeta, para que un grado
                        // "abandonado" no se pase por alto entre varios.
                        $fechaUltimaActividad = $ultimaActividadPorGrado[$idNg] ?? null;
                        $diasSinActividad = $fechaUltimaActividad
                            ? (int) floor((time() - strtotime($fechaUltimaActividad)) / 86400)
                            : null;
                        $gradoInactivo = $diasSinActividad === null || $diasSinActividad >= 10;
                    ?>
                    <div class="card<?= $gradoInactivo ? " card-alerta-inactivo" : "" ?>">
                        <h3><?= htmlspecialchars(strtoupper($ng["nombre"])) ?> <?= htmlspecialchars(strtoupper(ucfirst(strtolower($ng["nivel"])))) ?></h3>

                        <?php if (count($ng["cursos"]) === 0): ?>
                            <p class="placeholder-text">Sin curso asignado todavía.</p>
                        <?php else: ?>
                            <div class="card-tags">
                                <?php foreach ($ng["cursos"] as $c): ?>
                                    <span class="card-tag"><?= icon("book") ?> <?= htmlspecialchars($c["nombre"]) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="card-progress">
                            <div class="card-progress-head">
                                <span>PCA</span>
                                <span><?= $avance ?>/<?= $totalUnidadesPca ?> archivo</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-bar-fill <?= materiales_color_progreso($pctPca) ?>" style="width:<?= $pctPca ?>%"></div>
                            </div>
                        </div>

                        <div class="card-progress">
                            <div class="card-progress-head">
                                <span>Unidades</span>
                                <span><?= $avanceUnidades ?>/<?= $totalUnidadesGrado ?> cursos</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-bar-fill <?= materiales_color_progreso($pctUnidades) ?>" style="width:<?= $pctUnidades ?>%"></div>
                            </div>
                        </div>

                        <div class="card-progress">
                            <div class="card-progress-head">
                                <span>Sesiones</span>
                                <span><?= $avanceSesiones ?>/<?= $totalSesionesGrado ?> sesiones</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-bar-fill <?= materiales_color_progreso($pctSesiones) ?>" style="width:<?= $pctSesiones ?>%"></div>
                            </div>
                        </div>

                        <p class="placeholder-text<?= $gradoInactivo ? " texto-alerta-inactivo" : "" ?>" style="margin:10px 0 0;">
                            <?php if ($diasSinActividad === null): ?>
                                <?= icon("alert-triangle") ?> Sin archivos subidos todavía
                            <?php elseif ($gradoInactivo): ?>
                                <?= icon("alert-triangle") ?> Sin actividad hace <?= $diasSinActividad ?> días
                            <?php else: ?>
                                Última actividad: <?= htmlspecialchars($ultima) ?>
                            <?php endif; ?>
                        </p>

                        <div class="card-foot">
                            <span class="placeholder-text">
                                <?= count($ng["secciones"]) ?> <?= count($ng["secciones"]) === 1 ? "sección" : "secciones" ?>
                            </span>
                            <a href="grado.php?id_nivel_grado=<?= $idNg ?>">
                                Ingresar
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </section>


    <!-- ==================================================
         ACTIVIDAD RECIENTE
         Adelanto de las últimas 5 filas de materiales_historial
         (mismo dato que ya muestra profesor/historial.php completo,
         con filtros). Solo aparece si hay al menos 1 registro.
    ================================================== -->

    <?php if (count($actividadReciente) > 0): ?>

        <section class="panel-section">

            <div class="panel-section-head">
                <h2><?= icon("clock") ?> Actividad reciente</h2>
                <p class="panel-section-sub">
                    Últimos archivos subidos, reemplazados o eliminados.
                </p>
            </div>

            <ul class="actividad-reciente-lista">
                <?php foreach ($actividadReciente as $h): ?>
                    <?php
                        $detalleActividad = "U" . (int) $h["unidad"];
                        if ($h["semana"] !== null) {
                            $detalleActividad .= " · Sem-0" . (int) $h["semana"];
                        }
                        if ($h["curso"] !== null) {
                            $nombreCursoActividad = materiales_curso_nombre($h["curso"], $h["nivel"]);
                            $detalleActividad .= " · " . ($nombreCursoActividad ?? $h["curso"]);
                        }
                    ?>
                    <li class="actividad-reciente-item">
                        <span class="actividad-reciente-modulo"><?= htmlspecialchars($actividadEtiquetasModulo[$h["modulo"]] ?? $h["modulo"]) ?></span>
                        <span class="actividad-reciente-detalle">
                            <?= htmlspecialchars($actividadEtiquetasAccion[$h["accion"]] ?? $h["accion"]) ?>
                            en <strong><?= htmlspecialchars($h["grado_nombre"]) ?> <?= htmlspecialchars(ucfirst(strtolower($h["nivel"]))) ?></strong>
                            — <?= htmlspecialchars($detalleActividad) ?>
                        </span>
                        <span class="actividad-reciente-fecha"><?= htmlspecialchars(dashboard_tiempo_relativo($h["creado_en"])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="card-foot" style="justify-content:flex-end; margin-top:6px;">
                <a href="historial.php">Ver todo →</a>
            </div>

        </section>

    <?php endif; ?>

    <?php if ($tutoriaDashboard): ?>

        <!-- ==================================================
             TUTORÍA (SOLO Secundaria, opcional, año escolar actual)
             Sección propia, separada de "Grados asignados": nunca se
             mezcla con los cursos/áreas normales. Solo aparece si
             $tutoriaDashboard tiene una asignación real para el
             período académico actual.
        ================================================== -->

        <section class="panel-section panel-section-compact">
            <div class="panel-section-head">
                <h2><?= icon("graduation") ?> Tutoría</h2>
                <p class="panel-section-sub">
                    Independiente de tus cursos y áreas normales — asignación del año escolar actual.
                </p>
            </div>

            <div class="cards">
                <div class="card">
                    <h3><?= htmlspecialchars(strtoupper($tutoriaDashboard["nombre"])) ?> Secundaria</h3>
                    <p><?= htmlspecialchars($tutoriaDashboard["nombre_periodo"]) ?></p>
                    <div class="card-foot" style="justify-content:flex-end;">
                        <a href="tutoria.php">Ingresar</a>
                    </div>
                </div>
            </div>
        </section>

    <?php endif; ?>

    <!--
        ACCESOS RÁPIDOS y MIS MÓDULOS (secciones antiguas) se quitaron
        de aquí: ambas repetían enlaces que ya están en el sidebar
        (PCA/Unidades/Sesiones/Documentos, Mis Alumnos, Mis Trabajos,
        Envíos a Subdirección, Notificaciones, Perfil), sin agregar
        información nueva. Lo accionable que sí aportaban (envíos que
        requieren cambios, entregas de la semana, notificaciones sin
        leer) ahora vive en el bloque "Necesita tu atención" de arriba.
    -->


</main>


</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>

</html>
