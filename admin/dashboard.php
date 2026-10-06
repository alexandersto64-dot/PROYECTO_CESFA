<?php

session_start();
require_once __DIR__ . "/../backend/config/sesion_inactividad.php";


// ==========================================
// 1. COMPROBAR SESIÓN
// ==========================================

if (!isset($_SESSION["id_usuario"])) {

    header("Location: ../login.html");
    exit;

}


// ==========================================
// 2. COMPROBAR ROL
// ==========================================

if ($_SESSION["rol"] !== "ADMIN") {

    die("Acceso no autorizado.");

}


// ==========================================
// 3. CONECTAR A MYSQL
// ==========================================

require_once "../backend/config/database.php";
require_once "../backend/config/calendario_academico.php";
require_once "../backend/config/materiales_historial.php";
require_once "../backend/config/materiales_cursos.php";
require_once "../backend/config/notificaciones.php";

$avisoCierreBimestre = calendario_banner_cierre($conexion);

notificaciones_generar_avisos_solicitudes_pendientes($conexion);

// ==========================================
// CACHE SIMPLE EN SESIÓN (2.5 min) — mismo patrón que ya se usa en
// profesor/dashboard.php y subdirector/dashboard.php.
// ==========================================

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

/**
 * "hace X días" (misma función que profesor/dashboard.php, copiada
 * aquí porque cada script corre en su propio proceso PHP).
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


// ==========================================
// 4. OBTENER DATOS DEL ADMINISTRADOR
// ==========================================

$sql = "
    SELECT
        u.id_usuario,
        u.nombres,
        u.apellidos,
        u.correo,
        r.nombre AS rol

    FROM usuarios u

    INNER JOIN roles r
        ON u.id_rol = r.id_rol

    WHERE u.id_usuario = ?
";

$stmt = $conexion->prepare($sql);

$stmt->execute([
    $_SESSION["id_usuario"]
]);

$usuario = $stmt->fetch();


// ==========================================
// 5. COMPROBAR USUARIO
// ==========================================

if (!$usuario) {

    die("No se encontró el usuario.");

}


// ==========================================
// 6. ESTADÍSTICAS REALES DEL SISTEMA
// (todas las cifras salen de consultas directas; si una tabla
// no existiera, la consulta fallaría en vez de mostrar un dato
// inventado)
// ==========================================

$statsEstructura = dashboard_cache("admin_stats_estructura", DASHBOARD_CACHE_TTL, function () use ($conexion) {
    return [
        "usuarios"    => (int) $conexion->query("SELECT COUNT(*) FROM usuarios")->fetchColumn(),
        "profesores"  => (int) $conexion->query("SELECT COUNT(*) FROM profesores")->fetchColumn(),
        "alumnos"     => (int) $conexion->query("SELECT COUNT(*) FROM alumnos")->fetchColumn(),
        "cursos"      => (int) $conexion->query("SELECT COUNT(*) FROM cursos")->fetchColumn(),
        "matriculas"  => (int) $conexion->query("SELECT COUNT(*) FROM matriculas")->fetchColumn(),
        "documentos"  => (int) $conexion->query("SELECT COUNT(*) FROM documentos")->fetchColumn(),
    ];
});

$totalUsuarios   = $statsEstructura["usuarios"];
$totalProfesores = $statsEstructura["profesores"];
$totalAlumnos    = $statsEstructura["alumnos"];
$totalCursos     = $statsEstructura["cursos"];
$totalMatriculas = $statsEstructura["matriculas"];
$totalDocumentos = $statsEstructura["documentos"];

$resumenTrabajos = dashboard_cache("admin_resumen_trabajos", DASHBOARD_CACHE_TTL, function () use ($conexion) {
    return $conexion->query("
        SELECT
            SUM(estado IN ('ENVIADO', 'EN_REVISION', 'CORREGIDO')) AS pendientes,
            SUM(estado = 'APROBADO') AS aprobados,
            SUM(estado = 'REQUIERE_CAMBIOS') AS requieren_cambios
        FROM envios_trabajo
    ")->fetch();
});

$ultimosTrabajos = dashboard_cache("admin_ultimos_trabajos", DASHBOARD_CACHE_TTL, function () use ($conexion) {
    return $conexion->query("
        SELECT e.titulo, e.estado, h.creado_en, u.nombres, u.apellidos
        FROM envios_trabajo e
        INNER JOIN profesores p ON p.id_profesor = e.id_profesor
        INNER JOIN usuarios u ON u.id_usuario = p.id_usuario
        INNER JOIN envios_trabajo_historial h ON h.id_envio = e.id_envio AND h.version = e.version_actual
        ORDER BY h.creado_en DESC
        LIMIT 5
    ")->fetchAll();
});

// ==========================================
// 7. ALERTAS ACCIONABLES
// Datos operativos que el administrador necesita ver primero, no
// solo cifras estructurales. Ambos conteos son consultas reales
// (NOT EXISTS sobre tablas que ya existen) — cero datos inventados.
// ==========================================

$alertasAdmin = dashboard_cache("admin_alertas", DASHBOARD_CACHE_TTL, function () use ($conexion) {

    $profesoresSinGrado = (int) $conexion->query("
        SELECT COUNT(*) FROM profesores p
        WHERE NOT EXISTS (
            SELECT 1 FROM profesor_curso_grado pcg WHERE pcg.id_profesor = p.id_profesor
        )
    ")->fetchColumn();

    $alumnosSinMatriculaActiva = (int) $conexion->query("
        SELECT COUNT(*) FROM alumnos a
        WHERE NOT EXISTS (
            SELECT 1 FROM matriculas m WHERE m.id_alumno = a.id_alumno AND m.estado = 'ACTIVA'
        )
    ")->fetchColumn();

    return [
        "profesores_sin_grado" => $profesoresSinGrado,
        "alumnos_sin_matricula" => $alumnosSinMatriculaActiva,
    ];

});

// ==========================================
// 8. ACTIVIDAD RECIENTE (institucional, todos los profesores)
// Mismo feed que ya tiene el Dashboard del Profesor, pero sin
// filtrar por profesor — ver materiales_historial_listar_global().
// ==========================================

$actividadRecienteAdmin = materiales_historial_listar_global($conexion, 5);
$actividadEtiquetasModulo = ["PCA" => "PCA", "UNIDADES" => "Unidades", "SESIONES" => "Sesiones"];
$actividadEtiquetasAccion = [
    "SUBIDO" => "Subido",
    "REEMPLAZADO" => "Reemplazado",
    "ELIMINADO" => "Eliminado",
];

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
        Administrador - I.E.P. 88044 Abraham Valdelomar
    </title>

    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">

</head>


<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">



<header>

    <div>

        <h1>
            Panel de Administración
        </h1>

        <p>

            Bienvenido,

            <?= htmlspecialchars($usuario["nombres"]) ?>

            <?= htmlspecialchars($usuario["apellidos"]) ?>

        </p>

    </div>


    <a href="../backend/auth/logout.php">
        Cerrar sesión
    </a>

</header>



<main>

    <?php if ($avisoCierreBimestre): ?>
        <div class="resumen-alert<?= $avisoCierreBimestre["urgente"] ? "" : " is-info" ?>">
            <?= icon("clock") ?>
            <span><?= htmlspecialchars($avisoCierreBimestre["texto"]) ?></span>
        </div>
    <?php endif; ?>


    <section class="profile-card">

        <h2>
            Mi cuenta
        </h2>

        <p>

            <strong>
                Nombre:
            </strong>

            <?= htmlspecialchars($usuario["nombres"]) ?>

            <?= htmlspecialchars($usuario["apellidos"]) ?>

        </p>


        <p>

            <strong>
                Correo:
            </strong>

            <?= htmlspecialchars($usuario["correo"]) ?>

        </p>


        <p>

            <strong>
                Rol:
            </strong>

            <?= htmlspecialchars($usuario["rol"]) ?>

        </p>

    </section>


    <h2>
        Panel de Administración Institucional
    </h2>

    <?php if ($alertasAdmin["profesores_sin_grado"] > 0 || $alertasAdmin["alumnos_sin_matricula"] > 0): ?>
        <?php if ($alertasAdmin["profesores_sin_grado"] > 0): ?>
            <a href="profesores.php" class="resumen-alert">
                <?= icon("alert-triangle") ?>
                <span>
                    <?= $alertasAdmin["profesores_sin_grado"] ?>
                    <?= $alertasAdmin["profesores_sin_grado"] === 1 ? "profesor no tiene" : "profesores no tienen" ?>
                    ningún grado asignado.
                </span>
            </a>
        <?php endif; ?>
        <?php if ($alertasAdmin["alumnos_sin_matricula"] > 0): ?>
            <a href="alumnos.php" class="resumen-alert">
                <?= icon("alert-triangle") ?>
                <span>
                    <?= $alertasAdmin["alumnos_sin_matricula"] ?>
                    <?= $alertasAdmin["alumnos_sin_matricula"] === 1 ? "alumno no tiene" : "alumnos no tienen" ?>
                    matrícula activa.
                </span>
            </a>
        <?php endif; ?>
    <?php endif; ?>

    <h3 class="stats-grupo-titulo">Operativo</h3>
    <div class="stats-grid">
        <div class="stat-card<?= (int) ($resumenTrabajos["pendientes"] ?? 0) > 0 ? " stat-card-alerta" : "" ?>">
            <span class="stat-value"><?= (int) ($resumenTrabajos["pendientes"] ?? 0) ?></span>
            <span class="stat-label">Trabajos pendientes</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= (int) ($resumenTrabajos["aprobados"] ?? 0) ?></span>
            <span class="stat-label">Trabajos aprobados</span>
        </div>
        <div class="stat-card<?= (int) ($resumenTrabajos["requieren_cambios"] ?? 0) > 0 ? " stat-card-alerta" : "" ?>">
            <span class="stat-value"><?= (int) ($resumenTrabajos["requieren_cambios"] ?? 0) ?></span>
            <span class="stat-label">Requieren cambios</span>
        </div>
    </div>

    <h3 class="stats-grupo-titulo">Estructura institucional</h3>
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-value"><?= $totalUsuarios ?></span>
            <span class="stat-label">Usuarios</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= $totalProfesores ?></span>
            <span class="stat-label">Profesores</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= $totalAlumnos ?></span>
            <span class="stat-label">Alumnos</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= $totalCursos ?></span>
            <span class="stat-label">Cursos</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= $totalMatriculas ?></span>
            <span class="stat-label">Matrículas</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= $totalDocumentos ?></span>
            <span class="stat-label">Documentos</span>
        </div>
    </div>

    <section>
        <h3>Últimos trabajos enviados</h3>

        <?php if (count($ultimosTrabajos) === 0): ?>
            <p class="placeholder-text">Todavía no hay trabajos enviados en el sistema.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Profesor</th><th>Trabajo</th><th>Fecha</th><th>Estado</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ultimosTrabajos as $t): ?>
                            <tr>
                                <td><?= htmlspecialchars($t["nombres"] . " " . $t["apellidos"]) ?></td>
                                <td><?= htmlspecialchars($t["titulo"]) ?></td>
                                <td><?= htmlspecialchars($t["creado_en"]) ?></td>
                                <td><span class="status-badge status-<?= strtolower($t["estado"]) ?>"><?= htmlspecialchars($t["estado"]) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if (count($actividadRecienteAdmin) > 0): ?>
        <section>
            <h3><?= icon("clock") ?> Actividad reciente</h3>
            <ul class="actividad-reciente-lista">
                <?php foreach ($actividadRecienteAdmin as $h): ?>
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
                            por <strong><?= htmlspecialchars($h["usuario_nombres"] . " " . $h["usuario_apellidos"]) ?></strong>
                            en <?= htmlspecialchars($h["grado_nombre"]) ?> <?= htmlspecialchars(ucfirst(strtolower($h["nivel"]))) ?>
                            — <?= htmlspecialchars($detalleActividad) ?>
                        </span>
                        <span class="actividad-reciente-fecha"><?= htmlspecialchars(dashboard_tiempo_relativo($h["creado_en"])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>


    <h2>
        Administración
    </h2>


    <div class="cards">

        <div class="card">
            <h3><?= icon("users") ?> Usuarios</h3>
            <p>Crear, editar y administrar usuarios del sistema.</p>
            <a href="usuarios.php">Gestionar usuarios</a>
        </div>

        <div class="card">
            <h3><?= icon("graduation") ?> Alumnos</h3>
            <p>Registrar alumnos y gestionar sus matrículas.</p>
            <a href="alumnos.php">Gestionar alumnos</a>
        </div>

        <div class="card">
            <h3><?= icon("briefcase") ?> Profesores</h3>
            <p>Administrar la información de los profesores.</p>
            <a href="profesores.php">Gestionar profesores</a>
        </div>

        <div class="card">
            <h3><?= icon("file-text") ?> Documentos</h3>
            <p>Administrar los documentos institucionales.</p>
            <a href="documentos.php">Ver documentos</a>
        </div>

        <div class="card">
            <h3><?= icon("bar-chart") ?> Reportes</h3>
            <p>Consultar reportes del sistema.</p>
            <a href="reportes.php">Ver reportes</a>
        </div>

        <div class="card">
            <h3><?= icon("book") ?> Cursos</h3>
            <p>Administrar cursos académicos.</p>
            <a href="cursos.php">Gestionar cursos</a>
        </div>

        <div class="card">
            <h3><?= icon("calendar") ?> Calendario académico</h3>
            <p>Exámenes, reuniones, feriados y actividades del colegio.</p>
            <a href="calendario.php">Ver calendario</a>
        </div>

        <div class="card">
            <h3><?= icon("settings") ?> Configuración</h3>
            <p>Configurar opciones del sistema.</p>
            <a href="configuracion.php">Configuración</a>
        </div>

        <div class="card">
            <h3><?= icon("trash") ?> Papelera</h3>
            <p>Restaurar usuarios, alumnos o cursos eliminados.</p>
            <a href="papelera.php">Ver papelera</a>
        </div>

        <div class="card">
            <h3><?= icon("history") ?> Auditoría</h3>
            <p>Ver el registro de acciones administrativas.</p>
            <a href="auditoria.php">Ver auditoría</a>
        </div>

        <div class="card">
            <h3><?= icon("hard-drive") ?> Salud del sistema</h3>
            <p>Espacio en disco, archivos subidos y registros de la base de datos.</p>
            <a href="salud.php">Ver salud del sistema</a>
        </div>

    </div>

</main>


</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>

</html>
