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

if ($_SESSION["rol"] !== "SUBDIRECTOR") {

    die("Acceso no autorizado.");

}


// ==========================================
// 3. CONECTAR A MYSQL
// ==========================================

require_once "../backend/config/database.php";
require_once "../backend/config/calendario_academico.php";

$avisoCierreBimestre = calendario_banner_cierre($conexion);

// ==========================================
// CACHE SIMPLE EN SESIÓN (2.5 min) — mismo patrón que ya se usa en
// profesor/dashboard.php (dashboard_cache()), aplicado aquí a los 2
// conteos que se repiten en cada carga. Datos institucionales (no
// por usuario), así que la clave no lleva id_usuario.
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
 * "hace X días" a partir de una fecha de MySQL (misma función que ya
 * usa profesor/dashboard.php, copiada aquí porque cada script corre
 * en su propio proceso PHP — sin dependencias cruzadas entre paneles).
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
// 4. OBTENER DATOS DEL SUBDIRECTOR
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
// 6. RESUMEN DE TRABAJOS ENVIADOS POR PROFESORES
// (datos reales de envios_trabajo; no se inventan cifras)
// ==========================================

$resumenEnvios = dashboard_cache("subdirector_resumen_envios", DASHBOARD_CACHE_TTL, function () use ($conexion) {
    return $conexion->query("
        SELECT
            SUM(estado IN ('ENVIADO', 'EN_REVISION', 'CORREGIDO')) AS pendientes,
            SUM(estado = 'REQUIERE_CAMBIOS') AS requieren_cambios,
            SUM(estado = 'APROBADO') AS aprobados,
            COUNT(*) AS total
        FROM envios_trabajo
    ")->fetch();
});

// ORDER BY ... ASC (no DESC): el que lleva MÁS tiempo esperando
// primero, para priorizar la revisión por antigüedad, no por el
// último que llegó.
$ultimosPendientes = dashboard_cache("subdirector_ultimos_pendientes", DASHBOARD_CACHE_TTL, function () use ($conexion) {
    return $conexion->query("
        SELECT
            e.id_envio, e.titulo, e.estado,
            u.nombres, u.apellidos,
            h.creado_en AS fecha_version
        FROM envios_trabajo e
        INNER JOIN profesores p ON p.id_profesor = e.id_profesor
        INNER JOIN usuarios u ON u.id_usuario = p.id_usuario
        INNER JOIN envios_trabajo_historial h
            ON h.id_envio = e.id_envio AND h.version = e.version_actual
        WHERE e.estado IN ('ENVIADO', 'EN_REVISION', 'CORREGIDO')
        ORDER BY h.creado_en ASC
        LIMIT 5
    ")->fetchAll();
});

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
        Subdirector - I.E.P. 88044 Abraham Valdelomar
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
            Panel del Subdirector
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
            <span><?= htmlspecialchars($avisoCierreBimestre["texto"]) ?> Puedes revisar el avance de los profesores en «Reportes».</span>
        </div>
    <?php endif; ?>


    <section class="profile-card">

        <h2>
            Mi información
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
        Supervisión académica
    </h2>

    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-value"><?= (int) ($resumenEnvios["pendientes"] ?? 0) ?></span>
            <span class="stat-label">Pendientes de revisión</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= (int) ($resumenEnvios["requieren_cambios"] ?? 0) ?></span>
            <span class="stat-label">Requieren cambios</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= (int) ($resumenEnvios["aprobados"] ?? 0) ?></span>
            <span class="stat-label">Aprobados</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= (int) ($resumenEnvios["total"] ?? 0) ?></span>
            <span class="stat-label">Total de envíos</span>
        </div>
    </div>

    <section>
        <h3>Pendientes de revisión</h3>

        <?php if (count($ultimosPendientes) === 0): ?>
            <p class="placeholder-text">No hay trabajos pendientes de revisión.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Profesor</th><th>Trabajo</th><th>Esperando</th><th>Estado</th><th>Acción</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ultimosPendientes as $p): ?>
                            <?php
                                $diasEsperando = (int) floor((time() - strtotime($p["fecha_version"])) / 86400);
                                $esperaUrgente = $diasEsperando >= 5;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($p["nombres"] . " " . $p["apellidos"]) ?></td>
                                <td><?= htmlspecialchars($p["titulo"]) ?></td>
                                <td<?= $esperaUrgente ? ' class="texto-alerta-inactivo"' : '' ?>>
                                    <?php if ($esperaUrgente): ?><?= icon("alert-triangle") ?> <?php endif; ?>
                                    <?= htmlspecialchars(dashboard_tiempo_relativo($p["fecha_version"]) ?? "—") ?>
                                </td>
                                <td><span class="status-badge status-<?= strtolower($p["estado"]) ?>"><?= htmlspecialchars($p["estado"]) ?></span></td>
                                <td><a href="revision.php#envio-<?= (int) $p["id_envio"] ?>" class="btn-mini">Revisar</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p><a href="revision.php">Ver todos los trabajos enviados &rarr;</a></p>
        <?php endif; ?>
    </section>


    <h2>
        Gestión institucional
    </h2>


    <div class="cards">

        <div class="card">
            <h3><?= icon("graduation") ?> Alumnos</h3>
            <p>Consultar alumnos matriculados.</p>
            <a href="alumnos.php">Ver alumnos</a>
        </div>

        <div class="card">
            <h3><?= icon("file-text") ?> Documentos</h3>
            <p>Revisar y gestionar documentos institucionales.</p>
            <a href="documentos.php">Ver documentos</a>
        </div>

        <div class="card">
            <h3><?= icon("clipboard") ?> Revisión de trabajos</h3>
            <p>Revisar los trabajos enviados por los profesores, aprobar o solicitar correcciones.</p>
            <a href="revision.php">Ir a revisión</a>
        </div>

        <div class="card">
            <h3><?= icon("send") ?> Solicitudes</h3>
            <p>Revisar y gestionar solicitudes de los usuarios.</p>
            <a href="solicitudes.php">Ver solicitudes</a>
        </div>

        <div class="card">
            <h3><?= icon("users") ?> Profesores</h3>
            <p>Consultar información del personal docente.</p>
            <a href="profesores.php">Ver profesores</a>
        </div>

        <div class="card">
            <h3><?= icon("bar-chart") ?> Reportes</h3>
            <p>Consultar reportes institucionales.</p>
            <a href="reportes.php">Ver reportes</a>
        </div>

        <div class="card">
            <h3><?= icon("megaphone") ?> Comunicados</h3>
            <p>Gestionar comunicados institucionales.</p>
            <a href="comunicados.php">Ver comunicados</a>
        </div>

        <div class="card">
            <h3><?= icon("calendar") ?> Calendario académico</h3>
            <p>Exámenes, reuniones, feriados y actividades del colegio.</p>
            <a href="calendario.php">Ver calendario</a>
        </div>

        <div class="card">
            <h3><?= icon("calendar") ?> Periodos académicos</h3>
            <p>Consultar los periodos académicos registrados.</p>
            <a href="periodos.php">Ver periodos</a>
        </div>

    </div>

</main>


</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>

</html>
