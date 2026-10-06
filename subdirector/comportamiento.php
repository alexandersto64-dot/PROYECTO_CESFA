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
require_once "../backend/config/comportamiento.php";
require_once "../backend/config/padres.php";
[$mensaje, $mensajeTipo] = flash_get();


// ==========================================
// REGISTRAR
// ==========================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["accion"] ?? "") === "registrar") {

    csrf_verificar();

    $idAlumno = (int) ($_POST["id_alumno"] ?? 0);
    $idPeriodo = (int) ($_POST["id_periodo"] ?? 0);
    $tipo = $_POST["tipo"] ?? "";
    $descripcion = trim($_POST["descripcion"] ?? "");

    try {

        if ($idAlumno === 0 || $idPeriodo === 0) {
            throw new RuntimeException("Selecciona un alumno y un periodo.");
        }

        comportamiento_registrar($conexion, $idAlumno, $idPeriodo, $tipo, $descripcion, (int) $_SESSION["id_usuario"]);

        $mensaje = "Comportamiento registrado. Se avisó al padre vinculado (si tiene).";
        $mensajeTipo = "success";

    } catch (Throwable $e) {
        $mensaje = $e->getMessage();
        $mensajeTipo = "error";
    }

    flash_set($mensaje, $mensajeTipo);
    header("Location: comportamiento.php" . ($idAlumno > 0 ? "?id_alumno=$idAlumno" : ""));
    exit;

}


// ==========================================
// DATOS PARA LA VISTA
// ==========================================

$alumnos = $conexion->query("SELECT id_alumno, nombres, apellidos, dni FROM alumnos WHERE estado = 'ACTIVO' ORDER BY apellidos, nombres")->fetchAll();
$periodos = $conexion->query("SELECT id_periodo, nombre FROM periodos_academicos ORDER BY id_periodo DESC")->fetchAll();

$idAlumnoSeleccionado = (int) ($_GET["id_alumno"] ?? 0);
$historial = $idAlumnoSeleccionado > 0 ? padre_comportamiento($conexion, $idAlumnoSeleccionado) : [];

$etiquetasTipo = ["POSITIVO" => "Positivo", "NEGATIVO" => "Negativo", "NEUTRO" => "Neutro"];

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comportamiento · Subdirección - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = "comportamiento.php"; include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <h1>Comportamiento</h1>
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

    <form class="panel-form" method="POST" style="margin-bottom: 24px;">
        <?= csrf_field() ?>
        <h3>Registrar comportamiento</h3>
        <input type="hidden" name="accion" value="registrar">
        <div class="form-row">
            <div class="field">
                <label for="id_alumno">Alumno</label>
                <select name="id_alumno" id="id_alumno" required>
                    <option value="">Seleccione…</option>
                    <?php foreach ($alumnos as $a): ?>
                        <option value="<?= (int) $a["id_alumno"] ?>" <?= $idAlumnoSeleccionado === (int) $a["id_alumno"] ? "selected" : "" ?>>
                            <?= htmlspecialchars($a["apellidos"] . ", " . $a["nombres"] . " (DNI " . $a["dni"] . ")") ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="id_periodo">Periodo</label>
                <select name="id_periodo" id="id_periodo" required>
                    <?php foreach ($periodos as $p): ?>
                        <option value="<?= (int) $p["id_periodo"] ?>"><?= htmlspecialchars($p["nombre"]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="tipo">Tipo</label>
                <select name="tipo" id="tipo" required>
                    <option value="POSITIVO">Positivo</option>
                    <option value="NEGATIVO">Negativo</option>
                    <option value="NEUTRO">Neutro</option>
                </select>
            </div>
        </div>
        <div class="field">
            <label for="descripcion">Descripción</label>
            <textarea name="descripcion" id="descripcion" rows="3" required></textarea>
        </div>
        <button type="submit" class="btn-submit">Registrar</button>
    </form>

    <p class="placeholder-text">
        Para ver el historial de un alumno, selecciónalo arriba (se recarga la página con su historial abajo).
    </p>
    <script>
        document.getElementById("id_alumno").addEventListener("change", function () {
            if (this.value) {
                window.location.href = "comportamiento.php?id_alumno=" + this.value;
            }
        });
    </script>

    <?php if ($idAlumnoSeleccionado > 0): ?>
        <h2>Historial (<?= count($historial) ?>)</h2>
        <?php if (count($historial) === 0): ?>
            <p class="placeholder-text">Este alumno todavía no tiene comportamiento registrado.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Registrado por</th><th>Periodo</th></tr></thead>
                    <tbody>
                        <?php foreach ($historial as $h): ?>
                            <tr>
                                <td><?= htmlspecialchars(date("d/m/Y", strtotime($h["creado_en"]))) ?></td>
                                <td><span class="status-badge <?= ["POSITIVO" => "status-activo", "NEGATIVO" => "status-inactivo", "NEUTRO" => "status-neutro"][$h["tipo"]] ?>"><?= $etiquetasTipo[$h["tipo"]] ?></span></td>
                                <td><?= htmlspecialchars($h["descripcion"]) ?></td>
                                <td><?= htmlspecialchars($h["registrado_por_nombres"] . " " . $h["registrado_por_apellidos"]) ?></td>
                                <td><?= htmlspecialchars($h["periodo_nombre"]) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</main>
</div>
</div>

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
