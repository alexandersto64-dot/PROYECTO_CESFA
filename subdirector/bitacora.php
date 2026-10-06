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
require_once "../backend/config/subdireccion_seguimiento.php";
[$mensaje, $mensajeTipo] = flash_get();

$idProfesor = (int) ($_GET["id_profesor"] ?? 0);

$stmt = $conexion->prepare("
    SELECT p.id_profesor, u.nombres, u.apellidos, p.especialidad
    FROM profesores p
    INNER JOIN usuarios u ON u.id_usuario = p.id_usuario
    WHERE p.id_profesor = ?
");
$stmt->execute([$idProfesor]);
$profesor = $stmt->fetch();

if (!$profesor) {
    die("Profesor no encontrado.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verificar();

    $accion = $_POST["accion"] ?? "crear";

    if ($accion === "crear") {

        $contenido = trim($_POST["contenido"] ?? "");

        if ($contenido === "") {
            $mensaje = "Escribe una observación antes de guardar.";
            $mensajeTipo = "error";
        } else {
            observacion_crear($conexion, $idProfesor, (int) $_SESSION["id_usuario"], $contenido);
            $mensaje = "Observación agregada a la bitácora.";
            $mensajeTipo = "success";
        }

    } elseif ($accion === "eliminar") {

        observacion_eliminar($conexion, (int) ($_POST["id_observacion"] ?? 0));
        $mensaje = "Observación eliminada.";
        $mensajeTipo = "success";

    }

    if ($mensaje !== null) {
        flash_set($mensaje, $mensajeTipo);
    }
    header("Location: bitacora.php?id_profesor=$idProfesor");
    exit;

}

$observaciones = observaciones_listar($conexion, $idProfesor);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bitácora · Panel del Subdirector - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">


<header>
    <div>
        <h1>Bitácora de <?= htmlspecialchars($profesor["nombres"] . " " . $profesor["apellidos"]) ?></h1>
        <p>Panel del Subdirector · I.E.P. 88044 Abraham Valdelomar · <?= htmlspecialchars($profesor["especialidad"]) ?></p>
    </div>
    <div class="header-actions">
        <a href="reportes.php" class="btn-secondary">← Volver a Reportes</a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <form class="panel-form" method="POST">
    <?= csrf_field() ?>
        <h3>Nueva observación</h3>
        <input type="hidden" name="accion" value="crear">
        <div class="field">
            <label for="contenido">Seguimiento cualitativo — no está ligado a un envío puntual, queda como historial general del profesor.</label>
            <textarea name="contenido" id="contenido" placeholder="Ej. Buena disposición en la reunión de coordinación del 12/09. Pendiente reforzar puntualidad en la entrega de Sesiones…" required></textarea>
        </div>
        <button type="submit" class="btn-submit">Guardar observación</button>
    </form>

    <h2>Historial (<?= count($observaciones) ?>)</h2>

    <?php if (count($observaciones) === 0): ?>
        <p class="placeholder-text">Todavía no hay observaciones registradas para este profesor.</p>
    <?php else: ?>
        <div class="bitacora-lista">
            <?php foreach ($observaciones as $o): ?>
                <div class="bitacora-item">
                    <div class="bitacora-item-head">
                        <strong><?= htmlspecialchars($o["nombres"] . " " . $o["apellidos"]) ?></strong>
                        <span><?= htmlspecialchars($o["creado_en"]) ?></span>
                    </div>
                    <p><?= htmlspecialchars($o["contenido"]) ?></p>
                    <form method="POST" onsubmit="return confirm('¿Eliminar esta observación?');" style="margin-top:8px;">
                    <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="id_observacion" value="<?= (int) $o["id_observacion"] ?>">
                        <button type="submit" class="btn-mini btn-mini-reject"><?= icon("trash") ?> Eliminar</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
