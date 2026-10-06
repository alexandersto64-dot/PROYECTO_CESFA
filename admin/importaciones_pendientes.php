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
require_once "../backend/config/seguridad_csrf.php";
require_once "../backend/config/flash.php";
require_once "../backend/config/admin_seguimiento.php";
require_once "../backend/config/importacion_notas.php";
[$mensaje, $mensajeTipo] = flash_get();

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["accion"] ?? "") === "enviar_lote") {
    csrf_verificar();
    $r = boletas_enviar_a_aprobacion_lote($conexion, (int) $_SESSION["id_usuario"]);
    flash_set(
        $r["enviadas"] . " boleta(s) enviadas a aprobación de Subdirección." .
        ($r["con_error"] > 0 ? " " . $r["con_error"] . " se quedaron en revisión porque tienen filas con error (ábrelas con \"Ver / corregir\")." : ""),
        $r["con_error"] > 0 ? "error" : "success"
    );
    header("Location: importaciones_pendientes.php");
    exit;
}


$filtroEstado = $_GET["estado"] ?? null;
$boletas = boletas_listar($conexion, $filtroEstado ?: null);

$etiquetasEstado = [
    "PENDIENTE" => "Pendiente", "PROCESANDO" => "Procesando", "EN_REVISION" => "En revisión",
    "REVISADA" => "Revisada", "APROBADA" => "Aprobada", "PUBLICADA" => "Publicada",
    "RECHAZADA" => "Rechazada", "ERROR" => "Error",
];

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importaciones de notas · Panel de Administración - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <h1>Importaciones de notas</h1>
        <p>Panel de Administración · I.E.P. 88044 Abraham Valdelomar</p>
    </div>
    <div class="header-actions">
        <a href="importar_notas.php">Subir boletas</a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <form method="POST" style="margin: 0 0 1rem;" onsubmit="return confirm('¿Enviar a aprobación todas las boletas En revisión que no tengan errores?');">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="enviar_lote">
        <button type="submit" class="btn-submit">Enviar a aprobación todas las que estén sin errores</button>
    </form>

    <div class="field" style="max-width: 260px;">
        <label for="estado">Filtrar por estado</label>
        <select id="estado" onchange="location.href = this.value ? '?estado=' + this.value : 'importaciones_pendientes.php';">
            <option value="">Todos los estados</option>
            <?php foreach ($etiquetasEstado as $valor => $etiqueta): ?>
                <option value="<?= $valor ?>" <?= $filtroEstado === $valor ? "selected" : "" ?>><?= $etiqueta ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <h2>Boletas (<?= count($boletas) ?>)</h2>

    <?php if (count($boletas) === 0): ?>
        <p class="placeholder-text">No hay boletas importadas todavía.</p>
    <?php else: ?>
        <?php $cols = "1.5fr 1.4fr .9fr .9fr 1fr 1.1fr 1fr"; ?>
        <div class="list-rows">
            <div class="list-rows-head" style="grid-template-columns: <?= $cols ?>;">
                <span>Archivo</span><span>Alumno</span><span>Periodo / Bimestre</span>
                <span>Filas</span><span>Estado</span><span>Fecha</span><span>Acciones</span>
            </div>
            <?php foreach ($boletas as $b): ?>
                <div class="list-row" style="grid-template-columns: <?= $cols ?>;">
                    <div><span class="list-cell-label">Archivo</span><?= htmlspecialchars($b["nombre_archivo_original"]) ?></div>
                    <div>
                        <span class="list-cell-label">Alumno</span>
                        <?php if (!empty($b["alumno_resuelto"])): ?>
                            <?= htmlspecialchars($b["alumno_resuelto"]) ?>
                        <?php elseif (in_array($b["estado"], ["EN_REVISION", "REVISADA"], true)): ?>
                            <span class="role-badge" style="background:#fff3cd;color:#8a6d00;">Sin asignar</span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </div>
                    <div><span class="list-cell-label">Periodo / Bimestre</span><?= htmlspecialchars($b["periodo_nombre"]) ?><?= !empty($b["bimestres"]) ? " · B" . htmlspecialchars($b["bimestres"]) : "" ?></div>
                    <div>
                        <span class="list-cell-label">Filas</span>
                        <?= (int) $b["total_filas"] ?>
                        <?php if ($b["filas_error"] > 0): ?>
                            <span class="role-badge" style="background:#fde2e2;color:#a11;"><?= (int) $b["filas_error"] ?> con error</span>
                        <?php endif; ?>
                    </div>
                    <div><span class="list-cell-label">Estado</span><span class="role-badge"><?= $etiquetasEstado[$b["estado"]] ?? $b["estado"] ?></span></div>
                    <div><span class="list-cell-label">Fecha</span><?= htmlspecialchars($b["creado_en"]) ?></div>
                    <div>
                        <span class="list-cell-label">Acciones</span>
                        <?php if ($b["estado"] === "ERROR"): ?>
                            <span class="placeholder-text" title="<?= htmlspecialchars($b["mensaje_error"] ?? "") ?>">Ver error</span>
                        <?php elseif ($b["estado"] === "RECHAZADA"): ?>
                            <span class="placeholder-text" title="<?= htmlspecialchars($b["motivo_rechazo"] ?? "") ?>">Ver motivo</span>
                        <?php else: ?>
                            <a href="revisar_importacion.php?id_boleta=<?= (int) $b["id_boleta"] ?>">Ver / corregir</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>
</div>
</div>

</body>
</html>
