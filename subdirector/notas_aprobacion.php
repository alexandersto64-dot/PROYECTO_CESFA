<?php

session_start();
require_once __DIR__ . "/../backend/config/sesion_inactividad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.html");
    exit;
}

if ($_SESSION["rol"] !== "SUBDIRECTOR") {
    die("Acceso no autorizado.");
}

require_once "../backend/config/database.php";
require_once "../backend/config/seguridad_csrf.php";
require_once "../backend/config/flash.php";
require_once "../backend/config/admin_seguimiento.php";
require_once "../backend/config/importacion_notas.php";
[$mensaje, $mensajeTipo] = flash_get();

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verificar();

    try {

        $accion = $_POST["accion"] ?? "";
        if ($accion === "lote") {
            $r = boletas_aprobar_y_publicar_lote($conexion, (int) $_SESSION["id_usuario"]);
            flash_set(
                $r["aprobadas"] . " aprobada(s), " . $r["publicadas"] . " publicada(s)" .
                ($r["fallidas"] > 0 ? ", " . $r["fallidas"] . " con problema (revísalas en las listas de abajo)." : ". Los padres ya pueden ver esas notas."),
                $r["fallidas"] > 0 ? "error" : "success"
            );
            header("Location: notas_aprobacion.php");
            exit;
        }

        $idBoleta = (int) ($_POST["id_boleta"] ?? 0);

        if ($accion === "aprobar") {
            $fechaProgramada = trim($_POST["fecha_publicacion_programada"] ?? "");
            boleta_aprobar($conexion, $idBoleta, (int) $_SESSION["id_usuario"], $fechaProgramada !== "" ? $fechaProgramada : null);
            flash_set(
                $fechaProgramada !== ""
                    ? "Boleta aprobada. Se publicará automáticamente a partir del $fechaProgramada."
                    : "Boleta aprobada. Ya puedes publicarla.",
                "success"
            );
        } elseif ($accion === "publicar") {
            boleta_publicar($conexion, $idBoleta, (int) $_SESSION["id_usuario"]);
            flash_set("Boleta publicada. Los padres ya pueden ver estas notas.", "success");
        } elseif ($accion === "rechazar") {
            $motivo = trim($_POST["motivo"] ?? "");
            if ($motivo === "") {
                throw new RuntimeException("Indica un motivo de rechazo.");
            }
            boleta_rechazar($conexion, $idBoleta, $motivo, (int) $_SESSION["id_usuario"]);
            flash_set("Boleta rechazada.", "success");
        }

    } catch (\Throwable $e) {
        flash_set($e->getMessage(), "error");
    }

    header("Location: notas_aprobacion.php");
    exit;

}

$pendientesAprobar = boletas_listar($conexion, "REVISADA");
$pendientesPublicar = boletas_listas_para_publicar($conexion);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aprobación y publicación de notas · Subdirección - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <h1>Aprobación y publicación de notas</h1>
        <p>Subdirección · I.E.P. 88044 Abraham Valdelomar</p>
    </div>
    <div class="header-actions">
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <form method="POST" style="margin: 0 0 1.5rem;" onsubmit="return confirm('Se aprobarán y publicarán TODAS las boletas revisadas (las programadas para otra fecha se respetan). Los padres serán notificados. ¿Continuar?');">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="lote">
        <button type="submit" class="btn-submit">Aprobar y publicar todas las revisadas</button>
    </form>

    <h2>Revisadas por el profesor, pendientes de aprobar (<?= count($pendientesAprobar) ?>)</h2>
    <?php if (count($pendientesAprobar) === 0): ?>
        <p class="placeholder-text">No hay boletas esperando aprobación.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Archivo</th><th>Periodo / Bimestre</th><th>Filas</th><th>Aprobar</th><th>Rechazar</th></tr></thead>
                <tbody>
                    <?php foreach ($pendientesAprobar as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars($b["nombre_archivo_original"]) ?></td>
                            <td><?= htmlspecialchars($b["periodo_nombre"]) ?><?= !empty($b["bimestres"]) ? " · B" . htmlspecialchars($b["bimestres"]) : "" ?></td>
                            <td><?= (int) $b["total_filas"] ?></td>
                            <td>
                                <form method="POST" class="form-row">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id_boleta" value="<?= (int) $b["id_boleta"] ?>">
                                    <input type="hidden" name="accion" value="aprobar">
                                    <input type="datetime-local" name="fecha_publicacion_programada" title="Dejar vacío para publicar de inmediato después de aprobar">
                                    <button type="submit" class="btn-mini">Aprobar</button>
                                </form>
                            </td>
                            <td>
                                <form method="POST" class="form-row" onsubmit="return confirm('¿Rechazar esta importación?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id_boleta" value="<?= (int) $b["id_boleta"] ?>">
                                    <input type="hidden" name="accion" value="rechazar">
                                    <input type="text" name="motivo" placeholder="Motivo" required>
                                    <button type="submit" class="btn-mini btn-mini-reject">Rechazar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2 style="margin-top: 32px;">Aprobadas (<?= count($pendientesPublicar) ?>)</h2>
    <?php if (count($pendientesPublicar) === 0): ?>
        <p class="placeholder-text">No hay boletas aprobadas esperando publicación.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Archivo</th><th>Bimestre</th><th>Aprobada el</th><th>Publicación programada</th><th>Acción</th></tr></thead>
                <tbody>
                    <?php foreach ($pendientesPublicar as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars($b["nombre_archivo_original"]) ?></td>
                            <td><?= !empty($b["bimestres"]) ? "B" . htmlspecialchars($b["bimestres"]) : "—" ?></td>
                            <td><?= htmlspecialchars($b["aprobado_en"]) ?></td>
                            <td>
                                <?= $b["fecha_publicacion_programada"] ? htmlspecialchars($b["fecha_publicacion_programada"]) : '<span class="placeholder-text">Inmediata</span>' ?>
                            </td>
                            <td>
                                <?php if ($b["lista_ahora"]): ?>
                                    <form method="POST" onsubmit="return confirm('¿Publicar esta boleta? Los padres podrán verla de inmediato.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id_boleta" value="<?= (int) $b["id_boleta"] ?>">
                                        <input type="hidden" name="accion" value="publicar">
                                        <button type="submit" class="btn-submit">Publicar ahora</button>
                                    </form>
                                <?php else: ?>
                                    <span class="placeholder-text">Se habilitará el <?= htmlspecialchars($b["fecha_publicacion_programada"]) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</main>
</div>
</div>

</body>
</html>
