<?php

require_once __DIR__ . "/../backend/partials/padre_bootstrap.php";

$gradosSecciones = padre_grados_secciones_disponibles($conexion);
$periodoActual = padre_periodo_actual($conexion);

$errores = [];

// ==================================================
// PROCESAR ENVÍO DEL FORMULARIO (POST-Redirect-GET)
// ==================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verificar();

    $alumnoNombres = trim($_POST["alumno_nombres"] ?? "");
    $alumnoApellidos = trim($_POST["alumno_apellidos"] ?? "");
    $alumnoDni = trim($_POST["alumno_dni"] ?? "");
    $alumnoFechaNacimiento = trim($_POST["alumno_fecha_nacimiento"] ?? "");
    $idGradoSeccion = (int) ($_POST["id_grado_seccion_deseado"] ?? 0);

    if ($alumnoNombres === "" || $alumnoApellidos === "") {
        $errores[] = "Los nombres y apellidos del alumno son obligatorios.";
    }

    if (!preg_match('/^\d{8}$/', $alumnoDni)) {
        $errores[] = "El DNI debe tener exactamente 8 dígitos.";
    }

    $gradoValido = false;
    foreach ($gradosSecciones as $gs) {
        if ((int) $gs["id_grado_seccion"] === $idGradoSeccion) { $gradoValido = true; break; }
    }
    if (!$gradoValido) {
        $errores[] = "Selecciona el grado y sección deseado.";
    }

    if (!$periodoActual) {
        $errores[] = "No hay un periodo académico configurado. Comunícate con Secretaría.";
    }

    if (count($errores) === 0) {

        $stmt = $conexion->prepare("
            INSERT INTO solicitudes_matricula
                (id_usuario_padre, alumno_nombres, alumno_apellidos, alumno_dni,
                 alumno_fecha_nacimiento, id_grado_seccion_deseado, id_periodo, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDIENTE')
        ");

        $stmt->execute([
            $_SESSION["id_usuario"],
            $alumnoNombres,
            $alumnoApellidos,
            $alumnoDni,
            $alumnoFechaNacimiento !== "" ? $alumnoFechaNacimiento : null,
            $idGradoSeccion,
            $periodoActual["id_periodo"],
        ]);

        flash_set("Solicitud de matrícula enviada. Administración la revisará y te notificará el resultado.", "success");
        header("Location: preinscripcion.php");
        exit;

    }

}

[$mensajeFlash, $mensajeFlashTipo] = flash_get();

$solicitudes = padre_solicitudes_matricula($conexion, (int) $_SESSION["id_usuario"]);

$etiquetasEstado = ["PENDIENTE" => "Pendiente", "APROBADA" => "Aprobada", "RECHAZADA" => "Rechazada"];

?>



<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Preinscripción de matrícula - I.E.P. 88044 Abraham Valdelomar
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
        <span class="header-eyebrow">Padre de familia</span>
        <h1>Preinscripción de matrícula</h1>
    </div>
</header>

<main>

    <?php if ($mensajeFlash): ?>
        <div class="panel-alert panel-alert-<?= htmlspecialchars($mensajeFlashTipo) ?>">
            <?= icon($mensajeFlashTipo === "success" ? "check-circle" : "alert-triangle") ?>
            <?= htmlspecialchars($mensajeFlash) ?>
        </div>
    <?php endif; ?>

    <?php if (count($errores) > 0): ?>
        <div class="panel-alert panel-alert-error">
            <?= icon("alert-triangle") ?>
            <ul style="margin:0; padding-left:18px;">
                <?php foreach ($errores as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="panel-section">
        <div class="panel-section-head">
            <h2><?= icon("file-plus") ?> Nueva solicitud</h2>
            <p class="panel-section-sub">
                Registra los datos del alumno y el grado deseado. Administración revisará
                la solicitud y, al aprobarla, creará la matrícula.
                <?= $periodoActual ? " Periodo: <strong>" . htmlspecialchars($periodoActual["nombre"]) . "</strong>." : "" ?>
            </p>
        </div>

        <form method="post" class="panel-form">
            <?= csrf_field() ?>

            <div class="form-row">
                <div class="field">
                    <label for="alumno_nombres">Nombres del alumno</label>
                    <input type="text" id="alumno_nombres" name="alumno_nombres" required maxlength="100"
                           value="<?= htmlspecialchars($_POST["alumno_nombres"] ?? "") ?>">
                </div>

                <div class="field">
                    <label for="alumno_apellidos">Apellidos del alumno</label>
                    <input type="text" id="alumno_apellidos" name="alumno_apellidos" required maxlength="100"
                           value="<?= htmlspecialchars($_POST["alumno_apellidos"] ?? "") ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label for="alumno_dni">DNI del alumno</label>
                    <input type="text" id="alumno_dni" name="alumno_dni" required maxlength="8" pattern="\d{8}"
                           inputmode="numeric" title="8 dígitos"
                           value="<?= htmlspecialchars($_POST["alumno_dni"] ?? "") ?>">
                </div>

                <div class="field">
                    <label for="alumno_fecha_nacimiento">Fecha de nacimiento (opcional)</label>
                    <input type="date" id="alumno_fecha_nacimiento" name="alumno_fecha_nacimiento"
                           value="<?= htmlspecialchars($_POST["alumno_fecha_nacimiento"] ?? "") ?>">
                </div>
            </div>

            <?php
            $opcionesCascada = array_map(fn($gs) => [
                "id" => (int) $gs["id_grado_seccion"], "nivel" => $gs["nivel"],
                "grado" => (int) $gs["grado"], "seccion" => $gs["seccion"],
            ], $gradosSecciones);
            ?>
            <div data-cascada-grados
                 data-name="id_grado_seccion_deseado"
                 data-valor="<?= (int) ($_POST["id_grado_seccion_deseado"] ?? 0) ?>"
                 data-opciones="<?= htmlspecialchars(json_encode($opcionesCascada), ENT_QUOTES) ?>"></div>

            <button type="submit" class="btn-submit"><?= icon("send") ?> Enviar solicitud</button>
        </form>
    </section>

    <section class="panel-section">
        <div class="panel-section-head">
            <h2>Mis solicitudes</h2>
        </div>

        <?php if (count($solicitudes) === 0): ?>
            <p class="placeholder-text"><?= icon("clock") ?> Todavía no enviaste ninguna solicitud.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Alumno</th>
                            <th>Grado deseado</th>
                            <th>Periodo</th>
                            <th>Estado</th>
                            <th>Observación</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($solicitudes as $s): ?>
                            <tr>
                                <td><?= htmlspecialchars(trim($s["alumno_nombres"] . " " . $s["alumno_apellidos"])) ?></td>
                                <td><?= htmlspecialchars($s["grado_nombre"] . " " . ucfirst(strtolower($s["nivel"]))) ?></td>
                                <td><span class="placeholder-text"><?= htmlspecialchars($s["periodo_nombre"]) ?></span></td>
                                <td><span class="status-badge status-<?= strtolower($s["estado"]) ?>"><?= htmlspecialchars($etiquetasEstado[$s["estado"]] ?? $s["estado"]) ?></span></td>
                                <td><?= $s["observacion_admin"] ? htmlspecialchars($s["observacion_admin"]) : '<span class="placeholder-text">—</span>' ?></td>
                                <td><span class="placeholder-text"><?= htmlspecialchars(date("d/m/Y", strtotime($s["creado_en"]))) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>
<script src="../js/cascada-grados.js?v=1"></script>

</body>

</html>
