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

$idBoleta = (int) ($_GET["id_boleta"] ?? $_POST["id_boleta"] ?? 0);
$boleta = boleta_obtener($conexion, $idBoleta);

if (!$boleta) {
    die("Boleta no encontrada.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verificar();

    try {

        $accion = $_POST["accion"] ?? "";

        if ($accion === "corregir_alumno") {
            boleta_corregir_alumno($conexion, $idBoleta, (int) $_POST["id_alumno"], (int) $_SESSION["id_usuario"]);
            flash_set("Alumno corregido en todas las filas de la boleta.", "success");
        } elseif ($accion === "corregir_curso") {
            boleta_fila_corregir_curso($conexion, (int) $_POST["id_extraccion"], (int) $_POST["id_curso"], (int) $_SESSION["id_usuario"]);
            flash_set("Curso corregido.", "success");
        } elseif ($accion === "marcar_revisada") {
            boleta_marcar_revisada_admin($conexion, $idBoleta, (int) $_SESSION["id_usuario"]);
            flash_set("Boleta enviada a aprobación del Subdirector.", "success");
        } elseif ($accion === "rechazar") {
            $motivo = trim($_POST["motivo"] ?? "");
            if ($motivo === "") {
                throw new RuntimeException("Indica un motivo de rechazo.");
            }
            boleta_rechazar($conexion, $idBoleta, $motivo, (int) $_SESSION["id_usuario"]);
            flash_set("Boleta rechazada.", "success");
            header("Location: importaciones_pendientes.php");
            exit;
        }

        header("Location: revisar_importacion.php?id_boleta=$idBoleta");
        exit;

    } catch (\Throwable $e) {
        flash_set($e->getMessage(), "error");
        header("Location: revisar_importacion.php?id_boleta=$idBoleta");
        exit;
    }

}

$filas = boleta_filas($conexion, $idBoleta);
$alumnos = $conexion->query("SELECT id_alumno, nombres, apellidos, dni FROM alumnos ORDER BY apellidos, nombres")->fetchAll();
$cursos = $conexion->query("SELECT id_curso, nombre, nivel FROM cursos ORDER BY nivel, nombre")->fetchAll();

$etiquetasEstadoFila = ["OK" => "OK", "ADVERTENCIA" => "Advertencia", "ERROR" => "Error"];

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revisar boleta · Panel de Administración - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = "importaciones_pendientes.php"; include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <h1>Revisar boleta: <?= htmlspecialchars($boleta["nombre_archivo_original"]) ?></h1>
        <p>Estado actual: <span class="role-badge"><?= htmlspecialchars($boleta["estado"]) ?></span></p>
    </div>
    <div class="header-actions">
        <a href="importaciones_pendientes.php">Volver al historial</a>
    </div>
</header>

<main>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <?php if ($boleta["estado"] === "ERROR"): ?>
        <div class="panel-alert panel-alert-error">
            No se pudo procesar: <?= htmlspecialchars($boleta["mensaje_error"] ?? "Error desconocido.") ?>
        </div>
    <?php endif; ?>

    <?php if ($boleta["estado"] === "EN_REVISION"): ?>
        <form method="POST" onsubmit="return confirm('¿Enviar esta boleta a aprobación del Subdirector? Ya no pasará por revisión de profesores.');" style="margin-bottom: 12px;">
            <?= csrf_field() ?>
            <input type="hidden" name="id_boleta" value="<?= $idBoleta ?>">
            <input type="hidden" name="accion" value="marcar_revisada">
            <button type="submit" class="btn-submit">Enviar a aprobación (marcar como revisada)</button>
        </form>
        <p class="placeholder-text" style="margin-top: -8px; margin-bottom: 12px;">
            Revisa primero que el alumno y cada área/curso estén bien resueltos abajo. Si alguna fila queda en Error (sin match), no podrás enviarla hasta corregirla.
        </p>
    <?php endif; ?>

    <?php if (in_array($boleta["estado"], ["EN_REVISION", "REVISADA"], true)): ?>
        <form method="POST" onsubmit="return confirm('¿Rechazar toda esta importación?');" style="margin-bottom: 20px;">
            <?= csrf_field() ?>
            <input type="hidden" name="id_boleta" value="<?= $idBoleta ?>">
            <input type="hidden" name="accion" value="rechazar">
            <div class="form-row">
                <input type="text" name="motivo" placeholder="Motivo del rechazo" required style="flex: 1;">
                <button type="submit" class="btn-mini btn-mini-reject">Rechazar importación completa</button>
            </div>
        </form>
    <?php endif; ?>

    <?php
        // El PDF es de UN solo alumno: se corrige una vez para toda la
        // boleta, no fila por fila (a diferencia del curso, que sí puede
        // variar de una fila a otra).
        $primeraFila = $filas[0] ?? null;
        $filasPorCurso = [];
        foreach ($filas as $fila) {
            $filasPorCurso[$fila["texto_curso_extraido"]][] = $fila;
        }
    ?>

    <?php if ($primeraFila): ?>
        <div class="panel-form" style="margin-bottom: 20px;">
            <h3>Alumno de esta boleta</h3>
            <p>
                Leído del PDF: <strong><?= htmlspecialchars($primeraFila["texto_alumno_extraido"] ?? "(sin nombre leído)") ?></strong>
                <?php if ($primeraFila["dni_extraido"]): ?> · DNI <?= htmlspecialchars($primeraFila["dni_extraido"]) ?><?php endif; ?>
            </p>
            <p>
                Resuelto: <?= $primeraFila["id_alumno_resuelto"]
                    ? "<strong>" . htmlspecialchars($primeraFila["alumno_apellidos"] . ", " . $primeraFila["alumno_nombres"]) . "</strong>"
                    : '<span class="placeholder-text">sin resolver</span>' ?>
            </p>
            <form method="POST" class="form-row">
                <?= csrf_field() ?>
                <input type="hidden" name="id_boleta" value="<?= $idBoleta ?>">
                <input type="hidden" name="accion" value="corregir_alumno">
                <select name="id_alumno" required style="flex: 1;">
                    <option value="">— corregir alumno (aplica a toda la boleta) —</option>
                    <?php foreach ($alumnos as $a): ?>
                        <option value="<?= (int) $a["id_alumno"] ?>" <?= (int) $primeraFila["id_alumno_resuelto"] === (int) $a["id_alumno"] ? "selected" : "" ?>>
                            <?= htmlspecialchars($a["apellidos"] . ", " . $a["nombres"] . " (" . $a["dni"] . ")") ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-mini">Corregir</button>
            </form>
        </div>
    <?php endif; ?>

    <h2>Áreas extraídas (<?= count($filasPorCurso) ?>, <?= count($filas) ?> filas bimestre×área)</h2>
    <p class="placeholder-text">
        Aquí solo se corrige a qué curso pertenece cada área (matching técnico). Los valores de nota
        ya vienen completos y confirmados desde la boleta que subiste — revísalos igual antes de enviar.
    </p>

    <?php foreach ($filasPorCurso as $textoCurso => $filasCurso): $primera = $filasCurso[0]; ?>
        <div class="panel-form" style="margin-bottom: 16px;">
            <div class="form-row" style="justify-content: space-between; align-items: center;">
                <strong><?= htmlspecialchars($textoCurso) ?></strong>
            </div>

            <div class="field">
                <label>Curso resuelto</label>
                <?php if ($primera["id_curso_resuelto"]): ?>
                    <p><?= htmlspecialchars($primera["curso_nombre"]) ?></p>
                <?php endif; ?>
                <form method="POST" class="form-row">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id_boleta" value="<?= $idBoleta ?>">
                    <input type="hidden" name="accion" value="corregir_curso">
                    <input type="hidden" name="id_extraccion" value="<?= (int) $primera["id_extraccion"] ?>">
                    <select name="id_curso" required style="flex: 1;">
                        <option value="">— corregir curso —</option>
                        <?php foreach ($cursos as $c): ?>
                            <option value="<?= (int) $c["id_curso"] ?>" <?= (int) $primera["id_curso_resuelto"] === (int) $c["id_curso"] ? "selected" : "" ?>>
                                <?= htmlspecialchars($c["nombre"] . " (" . $c["nivel"] . ")") ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-mini">Corregir (solo este bimestre — repetir si hay varios)</button>
                </form>
                <p class="placeholder-text">Nota: si el curso está mal para un área, suele estarlo en los 4 bimestres; corrige cada fila listada abajo.</p>
            </div>

            <div class="table-wrap" style="margin-top: 8px;">
            <table class="data-table">
                <thead><tr><th>Bimestre</th><th>Nota final del área</th><th>Estado</th><th>Revisado por profesor</th></tr></thead>
                <tbody>
                    <?php foreach ($filasCurso as $fila): ?>
                        <tr>
                            <td>Bim. <?= (int) $fila["bimestre"] ?></td>
                            <td><?= $fila["valor_final_extraido"] !== null ? htmlspecialchars($fila["valor_final_extraido"]) : '<span class="placeholder-text">— (este nivel no trae total de área por bimestre)</span>' ?></td>
                            <td>
                                <span class="role-badge" style="<?= $fila["estado_fila"] === "ERROR" ? "background:#fde2e2;color:#a11;" : ($fila["estado_fila"] === "ADVERTENCIA" ? "background:#fff3cd;color:#8a6500;" : "") ?>">
                                    <?= $etiquetasEstadoFila[$fila["estado_fila"]] ?>
                                </span>
                                <?php if ($fila["mensaje_advertencia"]): ?>
                                    <div class="placeholder-text">⚠ <?= htmlspecialchars($fila["mensaje_advertencia"]) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= $fila["revisado_por_profesor"] ? "Sí" : "Pendiente" ?></td>
                        </tr>
                        <?php if (count($fila["competencias"]) > 0): ?>
                            <tr>
                                <td colspan="4" style="padding-top: 0;">
                                    <div class="table-wrap" style="margin-top: 4px;">
                                    <table class="data-table">
                                        <thead><tr><th>Competencia (leída)</th><th>Competencia (catálogo)</th><th>Valor</th><th>Estado</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($fila["competencias"] as $c): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($c["texto_competencia_extraido"]) ?></td>
                                                    <td><?= $c["competencia_nombre"] ? htmlspecialchars($c["competencia_nombre"]) : '<span class="placeholder-text">sin match</span>' ?></td>
                                                    <td><?= htmlspecialchars($c["valor_extraido"]) ?></td>
                                                    <td><?= $etiquetasEstadoFila[$c["estado_fila"]] ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endforeach; ?>

</main>
</div>
</div>

</body>
</html>
