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
require_once "../backend/config/admin_seguimiento.php";
$mensaje = null;
$mensajeTipo = null;

$camposTexto = ["nombre_colegio", "correo_contacto", "telefono_contacto"];
$camposNumericos = ["total_unidades_primaria", "total_unidades_secundaria", "total_semanas_primaria", "total_semanas_secundaria"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verificar();

    $stmtTexto = $conexion->prepare("UPDATE configuracion SET valor = ? WHERE clave = ?");
    foreach ($camposTexto as $clave) {
        $valor = trim($_POST[$clave] ?? "");
        $stmtTexto->execute([$valor, $clave]);
    }

    // Los numéricos van aparte porque, a diferencia del nombre del
    // colegio o el correo, un valor inválido acá (vacío, texto, 0,
    // negativo) rompería la escala U1..U10/Sem-0X de TODO el
    // sistema (Unidades, Sesiones, Dashboard, Reportes, PDF). Un
    // valor inválido se ignora en silencio y se conserva el que ya
    // había, en vez de guardar algo que después rompe otra pantalla.
    $stmtNumerico = $conexion->prepare("
        INSERT INTO configuracion (clave, valor) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE valor = VALUES(valor)
    ");
    $huboValorInvalido = false;

    foreach ($camposNumericos as $clave) {
        $valor = trim($_POST[$clave] ?? "");
        if (ctype_digit($valor) && (int) $valor >= 1 && (int) $valor <= 30) {
            $stmtNumerico->execute([$clave, (int) $valor]);
        } else {
            $huboValorInvalido = true;
        }
    }

    auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], "EDITAR", "CONFIGURACION", null, "Actualizó la configuración del sistema");

    if ($huboValorInvalido) {
        $mensaje = "Se guardó lo demás, pero algún número de Unidades/Sesiones no era válido (debe ser un entero entre 1 y 30) y no se cambió.";
        $mensajeTipo = "error";
    } else {
        $mensaje = "Configuración guardada correctamente.";
        $mensajeTipo = "success";
    }

}

$filas = $conexion->query("SELECT clave, valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración · Panel de Administración - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">


<header>
    <div>
        <h1>Configuración</h1>
        <p>Panel de Administración · I.E.P. 88044 Abraham Valdelomar</p>
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
        <h3>Datos institucionales</h3>

        <div class="field">
            <label for="nombre_colegio">Nombre del colegio</label>
            <input type="text" name="nombre_colegio" id="nombre_colegio"
                   value="<?= htmlspecialchars($filas['nombre_colegio'] ?? '') ?>">
        </div>

        <div class="form-row">
            <div class="field">
                <label for="correo_contacto">Correo de contacto</label>
                <input type="email" name="correo_contacto" id="correo_contacto"
                       value="<?= htmlspecialchars($filas['correo_contacto'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="telefono_contacto">Teléfono de contacto</label>
                <input type="text" name="telefono_contacto" id="telefono_contacto"
                       value="<?= htmlspecialchars($filas['telefono_contacto'] ?? '') ?>">
            </div>
        </div>

        <button type="submit" class="btn-submit">Guardar cambios</button>

        <h3 style="margin-top:28px;">Unidades y Sesiones</h3>
        <p class="placeholder-text">Cuántas Unidades (U1..U..) y cuántas Semanas/Sesiones por Unidad debe subir cada profesor — separado por nivel. Cambiar esto afecta de inmediato el Dashboard, Unidades, Sesiones y los Reportes de todo el colegio.</p>

        <div class="form-row">
            <div class="field">
                <label for="total_unidades_primaria">Unidades — Primaria</label>
                <input type="number" min="1" max="30" name="total_unidades_primaria" id="total_unidades_primaria"
                       value="<?= htmlspecialchars($filas['total_unidades_primaria'] ?? '10') ?>">
            </div>
            <div class="field">
                <label for="total_unidades_secundaria">Unidades — Secundaria</label>
                <input type="number" min="1" max="30" name="total_unidades_secundaria" id="total_unidades_secundaria"
                       value="<?= htmlspecialchars($filas['total_unidades_secundaria'] ?? '10') ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="field">
                <label for="total_semanas_primaria">Semanas por Unidad — Primaria</label>
                <input type="number" min="1" max="30" name="total_semanas_primaria" id="total_semanas_primaria"
                       value="<?= htmlspecialchars($filas['total_semanas_primaria'] ?? '5') ?>">
            </div>
            <div class="field">
                <label for="total_semanas_secundaria">Sesiones por Unidad — Secundaria</label>
                <input type="number" min="1" max="30" name="total_semanas_secundaria" id="total_semanas_secundaria"
                       value="<?= htmlspecialchars($filas['total_semanas_secundaria'] ?? '5') ?>">
            </div>
        </div>

        <button type="submit" class="btn-submit">Guardar cambios</button>
    </form>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
