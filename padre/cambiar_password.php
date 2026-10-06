<?php

require_once __DIR__ . "/../backend/partials/padre_bootstrap.php";
require_once __DIR__ . "/../backend/config/admin_seguimiento.php";

// ==========================================
// El padre cambia SU PROPIA contraseña.
// Necesario cuando la cuenta se creó con una contraseña temporal
// (cuentas familiares por código de estudiante). Solo actualiza
// usuarios.password del usuario en sesión; no toca nada más.
// ==========================================

$idUsuario = (int) $_SESSION["id_usuario"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verificar();

    $actual = (string) ($_POST["actual"] ?? "");
    $nueva = (string) ($_POST["nueva"] ?? "");
    $confirmar = (string) ($_POST["confirmar"] ?? "");

    $error = null;

    $stmt = $conexion->prepare("SELECT password FROM usuarios WHERE id_usuario = ? AND estado = 'ACTIVO' AND eliminado_en IS NULL LIMIT 1");
    $stmt->execute([$idUsuario]);
    $hashActual = $stmt->fetchColumn();

    if ($actual === "" || $nueva === "" || $confirmar === "") {
        $error = "Completa todos los campos.";
    } elseif (!$hashActual || !password_verify($actual, (string) $hashActual)) {
        $error = "La contraseña actual no es correcta.";
    } elseif (($errorPolitica = password_validar_politica($nueva)) !== null) {
        $error = $errorPolitica;
    } elseif ($nueva !== $confirmar) {
        $error = "La confirmación no coincide con la nueva contraseña.";
    } elseif ($nueva === $actual) {
        $error = "La nueva contraseña debe ser distinta de la actual.";
    }

    if ($error !== null) {
        flash_set($error, "error");
    } else {
        $conexion->prepare("UPDATE usuarios SET password = ? WHERE id_usuario = ?")
            ->execute([password_hash($nueva, PASSWORD_DEFAULT), $idUsuario]);
        password_marcar_cambio_obligatorio($conexion, $idUsuario, false);
        auditoria_evento($conexion, $idUsuario, "CAMBIAR_PASSWORD", "USUARIO", $idUsuario, "Cambió su propia contraseña");
        session_regenerate_id(true);
        flash_set("Contraseña actualizada correctamente.", "success");
    }

    header("Location: cambiar_password.php" . ($idAlumnoActivo ? "?id_alumno=" . (int) $idAlumnoActivo : ""));
    exit;

}

[$mensaje, $mensajeTipo] = flash_get();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambiar contraseña - I.E.P. 88044 Abraham Valdelomar</title>
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
        <h1>Cambiar contraseña</h1>
    </div>
</header>

<main>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= htmlspecialchars($mensajeTipo) ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <form class="panel-form" method="POST" autocomplete="off">
        <?= csrf_field() ?>
        <h3>Elige una contraseña nueva</h3>
        <p class="placeholder-text">Usa al menos 10 caracteres, con letras y números. No la compartas con nadie fuera de tu familia.</p>

        <div class="field">
            <label for="actual">Contraseña actual</label>
            <input type="password" name="actual" id="actual" autocomplete="current-password" required>
        </div>

        <div class="field">
            <label for="nueva">Contraseña nueva</label>
            <input type="password" name="nueva" id="nueva" minlength="10" maxlength="72" autocomplete="new-password" required>
        </div>

        <div class="field">
            <label for="confirmar">Repite la contraseña nueva</label>
            <input type="password" name="confirmar" id="confirmar" minlength="10" maxlength="72" autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn-submit">Guardar contraseña</button>
    </form>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
