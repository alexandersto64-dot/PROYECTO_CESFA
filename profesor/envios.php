<?php

require_once __DIR__ . "/../backend/partials/profesor_bootstrap.php";

// ==================================================
// ENVÍO DE TRABAJOS A SUBDIRECCIÓN
// Misma lógica que antes vivía en dashboard.php, solo movida a su
// propia página.
// ==================================================

$mensajeEnvio = null;
$mensajeEnvioTipo = null;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["accion"])) {

    csrf_verificar();

    $accion = $_POST["accion"];

    if ($accion === "enviar_trabajo") {

        $titulo = trim($_POST["envio_titulo"] ?? "");
        $descripcion = trim($_POST["envio_descripcion"] ?? "");

        if ($titulo === "") {

            $mensajeEnvio = "Debe indicar un título para el trabajo que envía a Subdirección.";
            $mensajeEnvioTipo = "error";

        } else {

            try {

                $archivoInfo = envios_guardar_archivo($_FILES["envio_archivo"] ?? []);

                $conexion->beginTransaction();

                $stmt = $conexion->prepare("
                    INSERT INTO envios_trabajo (id_profesor, titulo, descripcion, estado, version_actual)
                    VALUES (?, ?, ?, 'ENVIADO', 1)
                ");
                $stmt->execute([$profesor["id_profesor"], $titulo, $descripcion !== "" ? $descripcion : null]);
                $idEnvio = (int) $conexion->lastInsertId();

                $stmt = $conexion->prepare("
                    INSERT INTO envios_trabajo_historial
                        (id_envio, version, nombre_archivo, ruta_archivo, extension, tamano_bytes, estado)
                    VALUES (?, 1, ?, ?, ?, ?, 'ENVIADO')
                ");
                $stmt->execute([
                    $idEnvio,
                    $archivoInfo["nombre_archivo"],
                    $archivoInfo["ruta_archivo"],
                    $archivoInfo["extension"],
                    $archivoInfo["tamano_bytes"],
                ]);

                $conexion->commit();

                $mensajeEnvio = "Trabajo enviado a Subdirección correctamente.";
                $mensajeEnvioTipo = "success";

                // Invalida el cache de "mis envíos" del Dashboard (ver
                // profesor/dashboard.php::dashboard_cache) para que el
                // conteo de envíos se vea al instante, sin esperar el
                // TTL de 2.5 min.
                unset($_SESSION["dashboard_cache"]["mis_envios_" . $profesor["id_profesor"]]);

            } catch (RuntimeException $e) {

                if ($conexion->inTransaction()) {
                    $conexion->rollBack();
                }

                $mensajeEnvio = $e->getMessage();
                $mensajeEnvioTipo = "error";

            }

        }

        flash_set($mensajeEnvio, $mensajeEnvioTipo);
        header("Location: envios.php");
        exit;

    } elseif ($accion === "subir_correccion") {

        $idEnvio = (int) ($_POST["id_envio"] ?? 0);

        $envio = $conexion->prepare("SELECT * FROM envios_trabajo WHERE id_envio = ? AND id_profesor = ?");
        $envio->execute([$idEnvio, $profesor["id_profesor"]]);
        $envio = $envio->fetch();

        if (!$envio) {

            $mensajeEnvio = "No se pudo subir la corrección: el trabajo no existe o no le pertenece.";
            $mensajeEnvioTipo = "error";

        } elseif ($envio["estado"] !== "REQUIERE_CAMBIOS") {

            $mensajeEnvio = "Este trabajo ya no está esperando una corrección.";
            $mensajeEnvioTipo = "error";

        } else {

            try {

                $archivoInfo = envios_guardar_archivo($_FILES["correccion_archivo"] ?? []);
                $nuevaVersion = (int) $envio["version_actual"] + 1;

                $conexion->beginTransaction();

                $conexion->prepare("
                    UPDATE envios_trabajo SET estado = 'CORREGIDO', version_actual = ? WHERE id_envio = ?
                ")->execute([$nuevaVersion, $idEnvio]);

                $stmt = $conexion->prepare("
                    INSERT INTO envios_trabajo_historial
                        (id_envio, version, nombre_archivo, ruta_archivo, extension, tamano_bytes, estado)
                    VALUES (?, ?, ?, ?, ?, ?, 'CORREGIDO')
                ");
                $stmt->execute([
                    $idEnvio,
                    $nuevaVersion,
                    $archivoInfo["nombre_archivo"],
                    $archivoInfo["ruta_archivo"],
                    $archivoInfo["extension"],
                    $archivoInfo["tamano_bytes"],
                ]);

                $conexion->commit();

                $mensajeEnvio = "Versión corregida enviada a Subdirección.";
                $mensajeEnvioTipo = "success";

                // Mismo motivo que en "enviar_trabajo": refrescar de
                // inmediato el aviso de "requiere cambios" del Dashboard
                // en vez de dejarlo desactualizado hasta que venza el TTL.
                unset($_SESSION["dashboard_cache"]["mis_envios_" . $profesor["id_profesor"]]);

            } catch (RuntimeException $e) {

                if ($conexion->inTransaction()) {
                    $conexion->rollBack();
                }

                $mensajeEnvio = $e->getMessage();
                $mensajeEnvioTipo = "error";

            }

        }

        flash_set($mensajeEnvio, $mensajeEnvioTipo);
        header("Location: envios.php");
        exit;

    }

    header("Location: envios.php");
    exit;

}

if ($mensajeEnvio === null) {
    [$mensajeEnvio, $mensajeEnvioTipo] = flash_get();
}


// ==================================================
// MIS ENVÍOS A SUBDIRECCIÓN (envios_trabajo)
// ==================================================

$misEnvios = $conexion->prepare("
    SELECT
        e.id_envio, e.titulo, e.descripcion, e.estado, e.version_actual,
        h.nombre_archivo, h.extension, h.creado_en AS fecha_version,
        h.observacion AS observacion_actual
    FROM envios_trabajo e
    INNER JOIN envios_trabajo_historial h
        ON h.id_envio = e.id_envio AND h.version = e.version_actual
    WHERE e.id_profesor = ?
    ORDER BY e.actualizado_en DESC
");
$misEnvios->execute([$profesor["id_profesor"]]);
$misEnvios = $misEnvios->fetchAll();

$gradosAsignados = profesor_grados_asignados($conexion, $profesor["id_profesor"]);

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
        Envíos a Subdirección - I.E.P. 88044 Abraham Valdelomar
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
        <span class="header-eyebrow">Seguimiento</span>
        <h1>Envíos a Subdirección</h1>
    </div>

    <div class="header-actions">
        <div class="header-identity">
            <div class="header-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($profesor["nombres"], 0, 1)) . mb_strtoupper(mb_substr($profesor["apellidos"], 0, 1))) ?></div>
            <div class="header-identity-text">
                <strong><?= htmlspecialchars($profesor["nombres"]) ?> <?= htmlspecialchars($profesor["apellidos"]) ?></strong>
                <span>I.E.P. 88044</span>
            </div>
        </div>
    </div>

</header>


<main>

    <section class="panel-section">

        <div class="panel-section-head">
            <h2>
                <?= icon("send") ?> Envío de trabajos a Subdirección
            </h2>
        </div>

        <?php if ($mensajeEnvio): ?>
            <div class="panel-alert panel-alert-<?= $mensajeEnvioTipo ?>"><?= htmlspecialchars($mensajeEnvio) ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-value"><?= count($misEnvios) ?></span>
                <span class="stat-label">Trabajos enviados</span>
            </div>
            <div class="stat-card">
                <span class="stat-value"><?= count(array_filter($misEnvios, fn($e) => in_array($e["estado"], ["ENVIADO", "EN_REVISION", "CORREGIDO"], true))) ?></span>
                <span class="stat-label">Pendientes de revisión</span>
            </div>
            <div class="stat-card">
                <span class="stat-value"><?= count(array_filter($misEnvios, fn($e) => $e["estado"] === "REQUIERE_CAMBIOS")) ?></span>
                <span class="stat-label">Requieren cambios</span>
            </div>
            <div class="stat-card">
                <span class="stat-value"><?= count(array_filter($misEnvios, fn($e) => $e["estado"] === "APROBADO")) ?></span>
                <span class="stat-label">Aprobados</span>
            </div>
        </div>

        <form class="panel-form material-upload-form" method="POST" enctype="multipart/form-data"
              data-max-mb="20" data-extensiones="<?= implode(',', ENVIOS_EXTENSIONES_PERMITIDAS) ?>">
        <?= csrf_field() ?>
            <h3>Subir nuevo trabajo</h3>
            <input type="hidden" name="accion" value="enviar_trabajo">
            <div class="field">
                <label for="envio_titulo">Título</label>
                <input type="text" name="envio_titulo" id="envio_titulo" placeholder="Ej. Planificación Anual" required>
            </div>
            <div class="field">
                <label for="envio_descripcion">Descripción (opcional)</label>
                <textarea name="envio_descripcion" id="envio_descripcion" placeholder="Detalle del trabajo"></textarea>
            </div>
            <div class="field">
                <label for="envio_archivo">Archivo</label>
                <input type="file" name="envio_archivo" id="envio_archivo" accept="<?= implode(',', array_map(fn($e) => '.' . $e, ENVIOS_EXTENSIONES_PERMITIDAS)) ?>" required>
                <p class="placeholder-text">Formatos permitidos: PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, TXT, ZIP, RAR, JPG, PNG. Tamaño máximo 20&nbsp;MB.</p>
            </div>
            <button type="submit" class="btn-submit">Enviar a Subdirección</button>
        </form>

        <?php if (count($misEnvios) === 0): ?>

            <p class="placeholder-text">Todavía no has enviado trabajos a Subdirección.</p>

        <?php else: ?>

            <div class="trabajos-container">
                <?php foreach ($misEnvios as $e): ?>
                    <div class="trabajo-card" id="envio-<?= (int) $e["id_envio"] ?>">
                        <h3><?= htmlspecialchars($e["titulo"]) ?></h3>

                        <p><strong>Archivo:</strong> <?= htmlspecialchars($e["nombre_archivo"]) ?> (v<?= (int) $e["version_actual"] ?>)</p>
                        <p><strong>Enviado:</strong> <?= htmlspecialchars($e["fecha_version"]) ?></p>

                        <p>
                            <strong>Estado:</strong>
                            <span class="status-badge status-<?= strtolower($e["estado"]) ?>"><?= htmlspecialchars($e["estado"]) ?></span>
                        </p>

                        <?php if ($e["estado"] === "REQUIERE_CAMBIOS"): ?>

                            <div class="panel-alert panel-alert-error">
                                <?= icon("alert-triangle") ?>
                                <span>Se requieren cambios<br>
                                <strong>Observación de Subdirección:</strong>
                                "<?= htmlspecialchars($e["observacion_actual"] ?? "") ?>"</span>
                            </div>

                            <form method="POST" enctype="multipart/form-data" class="panel-form material-upload-form" style="margin-top:10px;"
                                  data-max-mb="20" data-extensiones="<?= implode(',', ENVIOS_EXTENSIONES_PERMITIDAS) ?>">
                            <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="subir_correccion">
                                <input type="hidden" name="id_envio" value="<?= (int) $e["id_envio"] ?>">
                                <div class="field">
                                    <label>Subir versión corregida</label>
                                    <input type="file" name="correccion_archivo" accept="<?= implode(',', array_map(fn($ext) => '.' . $ext, ENVIOS_EXTENSIONES_PERMITIDAS)) ?>" required>
                                </div>
                                <button type="submit" class="btn-submit">Subir corrección</button>
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

<script src="../js/panel.js?v=202609211901"></script>

</body>

</html>
