<?php

session_start();
require_once __DIR__ . "/../backend/config/sesion_inactividad.php";

// ==========================================
// 1. COMPROBAR SESIÓN Y ROL
// ==========================================

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.html");
    exit;
}

if ($_SESSION["rol"] !== "SUBDIRECTOR") {
    die("Acceso no autorizado.");
}

require_once "../backend/config/database.php";
require_once "../backend/config/seguridad_csrf.php";
require_once "../backend/config/notificaciones.php";
require_once "../backend/config/correo.php";


// ==========================================
// 2. ACCIÓN: APROBAR / SOLICITAR CAMBIOS
//
// Siempre se actúa sobre la ÚLTIMA versión del envío
// (nunca sobre una versión anterior), y siempre validando
// que el envío exista antes de tocar la base de datos.
// ==========================================

$errorAccion = null;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["accion"], $_POST["id_envio"])) {

    csrf_verificar();

    $idEnvio = (int) $_POST["id_envio"];
    $accion = $_POST["accion"];

    $envio = $conexion->prepare("SELECT * FROM envios_trabajo WHERE id_envio = ?");
    $envio->execute([$idEnvio]);
    $envio = $envio->fetch();

    if (!$envio) {

        $errorAccion = "El envío indicado no existe.";

    } elseif (!in_array($envio["estado"], ["ENVIADO", "EN_REVISION", "CORREGIDO"], true)) {

        $errorAccion = "Este envío ya no está pendiente de revisión.";

    } else {

        $versionActual = $conexion->prepare("
            SELECT * FROM envios_trabajo_historial
            WHERE id_envio = ? AND version = ?
        ");
        $versionActual->execute([$idEnvio, $envio["version_actual"]]);
        $versionActual = $versionActual->fetch();

        if ($accion === "aprobar") {

            $conexion->prepare("UPDATE envios_trabajo SET estado = 'APROBADO' WHERE id_envio = ?")
                ->execute([$idEnvio]);

            $conexion->prepare("
                UPDATE envios_trabajo_historial
                SET estado = 'APROBADO', id_revisor = ?, revisado_en = NOW()
                WHERE id_version = ?
            ")->execute([$_SESSION["id_usuario"], $versionActual["id_version"]]);

            $profesorUsuario = $conexion->prepare("
                SELECT u.id_usuario, u.nombres, u.apellidos, u.correo FROM usuarios u
                INNER JOIN profesores p ON p.id_usuario = u.id_usuario
                WHERE p.id_profesor = ?
            ");
            $profesorUsuario->execute([$envio["id_profesor"]]);
            $profesorUsuario = $profesorUsuario->fetch();

            if ($profesorUsuario) {

                notificar_crear(
                    $conexion,
                    (int) $profesorUsuario["id_usuario"],
                    "TRABAJO_APROBADO",
                    "Subdirección aprobó tu trabajo \"" . $envio["titulo"] . "\".",
                    "../profesor/envios.php"
                );

                correo_enviar(
                    $profesorUsuario["correo"],
                    trim($profesorUsuario["nombres"] . " " . $profesorUsuario["apellidos"]),
                    "Tu trabajo fue aprobado - Intranet I.E.P. 88044",
                    correo_plantilla("Trabajo aprobado", "
                        <p>Hola " . htmlspecialchars($profesorUsuario["nombres"]) . ",</p>
                        <p>Subdirección aprobó el trabajo que enviaste:</p>
                        <p style=\"background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; padding:12px 16px; border-radius:8px; font-weight:bold;\">
                            " . htmlspecialchars($envio["titulo"]) . "
                        </p>
                        <p>No necesitas hacer nada más con este envío.</p>
                    ")
                );

            }

            // Invalida el cache de sesión del Dashboard del Subdirector
            // (dashboard_cache() en subdirector/dashboard.php) para que
            // el conteo y la lista de pendientes se vean al instante.
            unset($_SESSION["dashboard_cache"]["subdirector_resumen_envios"]);
            unset($_SESSION["dashboard_cache"]["subdirector_ultimos_pendientes"]);

            header("Location: revision.php?ok=aprobado");
            exit;

        } elseif ($accion === "solicitar_cambios") {

            $observacion = trim($_POST["observacion"] ?? "");

            if ($observacion === "") {

                $errorAccion = "Debe escribir una observación para solicitar cambios.";

            } else {

                $conexion->prepare("UPDATE envios_trabajo SET estado = 'REQUIERE_CAMBIOS' WHERE id_envio = ?")
                    ->execute([$idEnvio]);

                $conexion->prepare("
                    UPDATE envios_trabajo_historial
                    SET estado = 'REQUIERE_CAMBIOS', observacion = ?, id_revisor = ?, revisado_en = NOW()
                    WHERE id_version = ?
                ")->execute([$observacion, $_SESSION["id_usuario"], $versionActual["id_version"]]);

                $profesorUsuario = $conexion->prepare("
                    SELECT u.id_usuario, u.nombres, u.apellidos, u.correo FROM usuarios u
                    INNER JOIN profesores p ON p.id_usuario = u.id_usuario
                    WHERE p.id_profesor = ?
                ");
                $profesorUsuario->execute([$envio["id_profesor"]]);
                $profesorUsuario = $profesorUsuario->fetch();

                if ($profesorUsuario) {

                    notificar_crear(
                        $conexion,
                        (int) $profesorUsuario["id_usuario"],
                        "CORRECCION_SOLICITADA",
                        "Subdirección solicitó cambios en \"" . $envio["titulo"] . "\".",
                        "../profesor/envios.php"
                    );

                    correo_enviar(
                        $profesorUsuario["correo"],
                        trim($profesorUsuario["nombres"] . " " . $profesorUsuario["apellidos"]),
                        "Tu trabajo requiere cambios - Intranet I.E.P. 88044",
                        correo_plantilla("Trabajo con observaciones", "
                            <p>Hola " . htmlspecialchars($profesorUsuario["nombres"]) . ",</p>
                            <p>Subdirección revisó el trabajo que enviaste y solicita algunos cambios:</p>
                            <p style=\"background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:12px 16px; border-radius:8px; font-weight:bold;\">
                                " . htmlspecialchars($envio["titulo"]) . "
                            </p>
                            <p><strong>Observación de Subdirección:</strong><br>\"" . htmlspecialchars($observacion) . "\"</p>
                            <p>Ingresa al Intranet para subir la versión corregida.</p>
                        ")
                    );

                }

                unset($_SESSION["dashboard_cache"]["subdirector_resumen_envios"]);
                unset($_SESSION["dashboard_cache"]["subdirector_ultimos_pendientes"]);

                header("Location: revision.php?ok=cambios");
                exit;

            }

        }

    }

}


// ==========================================
// 3. DATOS DEL SUBDIRECTOR (para el header)
// ==========================================

$stmt = $conexion->prepare("
    SELECT u.nombres, u.apellidos
    FROM usuarios u
    WHERE u.id_usuario = ?
");
$stmt->execute([$_SESSION["id_usuario"]]);
$usuario = $stmt->fetch();


// ==========================================
// 4. LISTA DE ENVÍOS (con datos del profesor y la
//    última versión: archivo, fecha, observación previa)
// ==========================================

$envios = $conexion->query("
    SELECT
        e.id_envio, e.titulo, e.descripcion, e.estado, e.version_actual, e.creado_en,
        u.nombres, u.apellidos,
        h.nombre_archivo, h.extension, h.creado_en AS fecha_version, h.observacion AS observacion_anterior
    FROM envios_trabajo e
    INNER JOIN profesores p ON p.id_profesor = e.id_profesor
    INNER JOIN usuarios u ON u.id_usuario = p.id_usuario
    INNER JOIN envios_trabajo_historial h
        ON h.id_envio = e.id_envio AND h.version = e.version_actual
    ORDER BY
        (e.estado IN ('ENVIADO', 'EN_REVISION', 'CORREGIDO')) DESC,
        e.actualizado_en DESC
")->fetchAll();

$pendientes = array_filter($envios, fn($e) => in_array($e["estado"], ["ENVIADO", "EN_REVISION", "CORREGIDO"], true));

// Si se pide ver el historial completo de un envío
$historialDe = null;
$historialVersiones = [];

if (isset($_GET["historial"]) && (int) $_GET["historial"] > 0) {

    $idEnvio = (int) $_GET["historial"];

    $stmt = $conexion->prepare("SELECT titulo FROM envios_trabajo WHERE id_envio = ?");
    $stmt->execute([$idEnvio]);
    $historialDe = $stmt->fetch();

    if ($historialDe) {
        $stmt = $conexion->prepare("
            SELECT version, nombre_archivo, estado, observacion, creado_en, revisado_en
            FROM envios_trabajo_historial
            WHERE id_envio = ?
            ORDER BY version ASC
        ");
        $stmt->execute([$idEnvio]);
        $historialVersiones = $stmt->fetchAll();
    }

}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revisión de trabajos · Panel del Subdirector - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <h1>Revisión de trabajos</h1>
        <p>Panel de Supervisión Académica · I.E.P. 88044 Abraham Valdelomar</p>
    </div>
    <div class="header-actions">
        <a href="../backend/reportes/trabajos_pdf.php?descargar=1" target="_blank"><?= icon("file-text") ?> Descargar PDF</a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <?php if (isset($_GET["ok"])): ?>
        <div class="panel-alert panel-alert-success">
            <?= $_GET["ok"] === "aprobado" ? "Trabajo aprobado correctamente." : "Se solicitaron cambios y se notificó al profesor." ?>
        </div>
    <?php endif; ?>

    <?php if ($errorAccion): ?>
        <div class="panel-alert panel-alert-error"><?= htmlspecialchars($errorAccion) ?></div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-value"><?= count($pendientes) ?></span>
            <span class="stat-label">Pendientes de revisión</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= count(array_filter($envios, fn($e) => $e["estado"] === "REQUIERE_CAMBIOS")) ?></span>
            <span class="stat-label">Requieren cambios</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= count(array_filter($envios, fn($e) => $e["estado"] === "APROBADO")) ?></span>
            <span class="stat-label">Aprobados</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= count($envios) ?></span>
            <span class="stat-label">Total de envíos</span>
        </div>
    </div>

    <?php if ($historialDe): ?>

        <section>
            <h2>Historial de versiones · <?= htmlspecialchars($historialDe["titulo"]) ?></h2>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Versión</th><th>Archivo</th><th>Estado</th><th>Observación</th><th>Subido</th><th>Revisado</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historialVersiones as $v): ?>
                            <tr>
                                <td>v<?= (int) $v["version"] ?></td>
                                <td><?= htmlspecialchars($v["nombre_archivo"]) ?></td>
                                <td><span class="status-badge status-<?= strtolower($v["estado"]) ?>"><?= htmlspecialchars($v["estado"]) ?></span></td>
                                <td><?= htmlspecialchars($v["observacion"] ?? "—") ?></td>
                                <td><?= htmlspecialchars($v["creado_en"]) ?></td>
                                <td><?= htmlspecialchars($v["revisado_en"] ?? "—") ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p><a href="revision.php">&larr; Volver a la lista</a></p>
        </section>

    <?php endif; ?>

    <section>
        <h2>Trabajos enviados por profesores (<?= count($envios) ?>)</h2>

        <?php if (count($envios) === 0): ?>

            <p class="placeholder-text">Todavía no hay trabajos enviados por profesores.</p>

        <?php else: ?>

            <div class="trabajos-container">
                <?php foreach ($envios as $e): ?>
                    <div class="trabajo-card" id="envio-<?= (int) $e["id_envio"] ?>">
                        <h3><?= htmlspecialchars($e["titulo"]) ?></h3>

                        <p><strong>Profesor:</strong> <?= htmlspecialchars($e["nombres"] . " " . $e["apellidos"]) ?></p>
                        <p><strong>Archivo:</strong> <?= htmlspecialchars($e["nombre_archivo"]) ?> (v<?= (int) $e["version_actual"] ?>, .<?= htmlspecialchars($e["extension"]) ?>)</p>
                        <p><strong>Enviado:</strong> <?= htmlspecialchars($e["fecha_version"]) ?></p>

                        <?php if ($e["descripcion"]): ?>
                            <p><strong>Descripción:</strong> <?= htmlspecialchars($e["descripcion"]) ?></p>
                        <?php endif; ?>

                        <?php if ($e["estado"] === "REQUIERE_CAMBIOS" && $e["observacion_anterior"]): ?>
                            <p><strong>Observación enviada:</strong> <?= htmlspecialchars($e["observacion_anterior"]) ?></p>
                        <?php endif; ?>

                        <p>
                            <strong>Estado:</strong>
                            <span class="status-badge status-<?= strtolower($e["estado"]) ?>"><?= htmlspecialchars($e["estado"]) ?></span>
                        </p>

                        <div class="row-actions">
                            <a href="revision.php?historial=<?= (int) $e["id_envio"] ?>" class="btn-mini">Ver historial</a>

                            <?php if (in_array($e["estado"], ["ENVIADO", "EN_REVISION", "CORREGIDO"], true)): ?>

                                <form method="POST" onsubmit="return confirm('¿Aprobar este trabajo?');">
                                <?= csrf_field() ?>
                                    <input type="hidden" name="id_envio" value="<?= (int) $e["id_envio"] ?>">
                                    <input type="hidden" name="accion" value="aprobar">
                                    <button type="submit" class="btn-mini btn-mini-approve">Aprobar</button>
                                </form>

                                <button type="button" class="btn-mini btn-mini-reject" onclick="document.getElementById('obs-<?= (int) $e['id_envio'] ?>').classList.toggle('oculto')">
                                    Solicitar cambios
                                </button>

                            <?php else: ?>
                                <span class="placeholder-text">Resuelto</span>
                            <?php endif; ?>
                        </div>

                        <?php if (in_array($e["estado"], ["ENVIADO", "EN_REVISION", "CORREGIDO"], true)): ?>
                            <form method="POST" id="obs-<?= (int) $e['id_envio'] ?>" class="panel-form oculto" style="margin-top:12px;">
                            <?= csrf_field() ?>
                                <input type="hidden" name="id_envio" value="<?= (int) $e["id_envio"] ?>">
                                <input type="hidden" name="accion" value="solicitar_cambios">
                                <div class="field">
                                    <label>Observación para el profesor</label>
                                    <textarea name="observacion" required placeholder="Ej. Corregir el apartado 3 y actualizar la fecha del documento."></textarea>
                                </div>
                                <button type="submit" class="btn-submit">Enviar observación</button>
                            </form>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </section>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<style>.oculto{ display:none; }</style>
<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
