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
[$mensaje, $mensajeTipo] = flash_get();

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verificar();

    $accion = $_POST["accion"] ?? "crear";

    if ($accion === "actualizar_fechas") {

        $id_periodo = (int) ($_POST["id_periodo"] ?? 0);
        $fecha_inicio = trim($_POST["fecha_inicio"] ?? "");
        $fecha_fin = trim($_POST["fecha_fin"] ?? "");

        if ($fecha_inicio !== "" && $fecha_fin !== "" && $fecha_fin < $fecha_inicio) {

            $mensaje = "La fecha de cierre no puede ser anterior a la fecha de inicio.";
            $mensajeTipo = "error";

        } else {

            $stmt = $conexion->prepare("UPDATE periodos_academicos SET fecha_inicio = ?, fecha_fin = ? WHERE id_periodo = ?");
            $stmt->execute([$fecha_inicio !== "" ? $fecha_inicio : null, $fecha_fin !== "" ? $fecha_fin : null, $id_periodo]);

            $mensaje = "Fechas del periodo actualizadas correctamente.";
            $mensajeTipo = "success";

        }

        if ($mensaje !== null) {
            flash_set($mensaje, $mensajeTipo);
        }
        header("Location: periodos.php");
        exit;

    }

    $nombre = trim($_POST["nombre"] ?? "");

    if ($nombre === "") {

        $mensaje = "El nombre del periodo es obligatorio.";
        $mensajeTipo = "error";

    } else {

        try {

            $stmt = $conexion->prepare("INSERT INTO periodos_academicos (nombre) VALUES (?)");
            $stmt->execute([$nombre]);

            $mensaje = "Periodo académico agregado correctamente.";
            $mensajeTipo = "success";

        } catch (PDOException $e) {

            $mensaje = "No se pudo agregar el periodo académico.";
            $mensajeTipo = "error";

        }

    }

    // Evita el reenvío del formulario al recargar (PRG)
    if ($mensaje !== null) {
        flash_set($mensaje, $mensajeTipo);
    }
    header("Location: periodos.php");
    exit;

}

$periodos = $conexion->query("SELECT id_periodo, nombre, fecha_inicio, fecha_fin FROM periodos_academicos ORDER BY id_periodo DESC")->fetchAll();

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Periodos académicos · Panel del Subdirector - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">


<header>
    <div>
        <h1>Periodos académicos</h1>
        <p>Panel del Subdirector · I.E.P. 88044 Abraham Valdelomar</p>
    </div>
    <div class="header-actions">
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <form class="panel-form" method="POST">
    <?= csrf_field() ?>
        <h3>Agregar periodo académico</h3>
        <div class="field">
            <label for="nombre">Nombre del periodo</label>
            <input type="text" name="nombre" id="nombre" placeholder="Ej. II Bimestre 2026" required>
        </div>
        <button type="submit" class="btn-submit">Guardar periodo</button>
    </form>

    <h2>Periodos registrados (<?= count($periodos) ?>)</h2>
    <p class="placeholder-text">
        Los años completos ("Año Académico ...") no necesitan fechas. Ponle fecha de inicio y cierre a los
        bimestres para que el sistema calcule automáticamente los días que faltan para cerrar cada uno.
    </p>

    <?php if (count($periodos) === 0): ?>
        <p class="placeholder-text">Todavía no hay periodos académicos registrados.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Periodo</th><th>Fecha inicio</th><th>Fecha cierre</th><th>Estado</th><th>Acciones</th></tr></thead>
                <tbody>
                    <?php foreach ($periodos as $p): ?>
                        <?php
                            $esAnual = strpos($p["nombre"], "Año Académico") === 0;
                            $hoy = date("Y-m-d");
                            $estado = "—";
                            if (!$esAnual && $p["fecha_fin"]) {
                                if ($p["fecha_fin"] < $hoy) {
                                    $estado = "Cerrado";
                                } else {
                                    $dias = (new DateTime($hoy))->diff(new DateTime($p["fecha_fin"]))->days;
                                    $estado = $dias === 0 ? "Cierra hoy" : "Faltan {$dias} día" . ($dias === 1 ? "" : "s");
                                }
                            }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($p['nombre']) ?></td>
                            <td><?= $p['fecha_inicio'] ? htmlspecialchars($p['fecha_inicio']) : "—" ?></td>
                            <td><?= $p['fecha_fin'] ? htmlspecialchars($p['fecha_fin']) : "—" ?></td>
                            <td><?= htmlspecialchars($estado) ?></td>
                            <td>
                                <?php if ($esAnual): ?>
                                    <span class="placeholder-text">No aplica</span>
                                <?php else: ?>
                                    <details>
                                        <summary class="btn-mini" style="display:inline-block;cursor:pointer">Editar fechas</summary>
                                        <form method="POST" style="margin-top:8px;max-width:220px">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="accion" value="actualizar_fechas">
                                            <input type="hidden" name="id_periodo" value="<?= (int) $p['id_periodo'] ?>">
                                            <div class="field">
                                                <label style="font-size:.8rem">Inicio</label>
                                                <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($p['fecha_inicio'] ?? '') ?>">
                                            </div>
                                            <div class="field">
                                                <label style="font-size:.8rem">Cierre</label>
                                                <input type="date" name="fecha_fin" value="<?= htmlspecialchars($p['fecha_fin'] ?? '') ?>">
                                            </div>
                                            <button type="submit" class="btn-mini">Guardar fechas</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
