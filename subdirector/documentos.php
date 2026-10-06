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

    $titulo = trim($_POST["titulo"] ?? "");
    $categoria = trim($_POST["categoria"] ?? "");
    $url = trim($_POST["url"] ?? "");

    if ($titulo === "" || $categoria === "") {

        $mensaje = "El título y la categoría son obligatorios.";
        $mensajeTipo = "error";

    } elseif ($url !== "" && !filter_var($url, FILTER_VALIDATE_URL)) {

        $mensaje = "El enlace ingresado no es una URL válida.";
        $mensajeTipo = "error";

    } else {

        $stmt = $conexion->prepare("INSERT INTO documentos (titulo, categoria, url, id_usuario) VALUES (?, ?, ?, ?)");
        $stmt->execute([$titulo, $categoria, $url !== "" ? $url : null, $_SESSION["id_usuario"]]);

        $mensaje = "Documento registrado correctamente.";
        $mensajeTipo = "success";

    }

    // Evita el reenvío del formulario al recargar (PRG)
    if ($mensaje !== null) {
        flash_set($mensaje, $mensajeTipo);
    }
    header("Location: documentos.php");
    exit;

}

$documentos = $conexion->query("
    SELECT d.id_documento, d.titulo, d.categoria, d.url, d.creado_en,
           u.nombres, u.apellidos
    FROM documentos d
    INNER JOIN usuarios u ON d.id_usuario = u.id_usuario
    ORDER BY d.creado_en DESC
")->fetchAll();

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentos · Panel del Subdirector - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">


<header>
    <div>
        <h1>Documentos institucionales</h1>
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
        <h3>Registrar documento</h3>
        <div class="form-row">
            <div class="field">
                <label for="titulo">Título</label>
                <input type="text" name="titulo" id="titulo" placeholder="Ej. Acta de reunión" required>
            </div>
            <div class="field">
                <label for="categoria">Categoría</label>
                <input type="text" name="categoria" id="categoria" placeholder="Ej. Actas" required>
            </div>
        </div>
        <div class="field">
            <label for="url">Enlace al archivo (opcional)</label>
            <input type="url" name="url" id="url" placeholder="https://...">
        </div>
        <button type="submit" class="btn-submit">Guardar documento</button>
    </form>

    <h2>Documentos registrados (<?= count($documentos) ?>)</h2>

    <?php if (count($documentos) === 0): ?>
        <p class="placeholder-text">Todavía no hay documentos registrados.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Título</th><th>Categoría</th><th>Enlace</th><th>Registrado por</th><th>Fecha</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($documentos as $d): ?>
                        <tr>
                            <td><?= htmlspecialchars($d['titulo']) ?></td>
                            <td><span class="role-badge"><?= htmlspecialchars($d['categoria']) ?></span></td>
                            <td>
                                <?php if ($d['url']): ?>
                                    <a href="<?= htmlspecialchars($d['url']) ?>" target="_blank" rel="noopener">Ver archivo</a>
                                <?php else: ?>
                                    <span class="placeholder-text">Sin enlace</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($d['nombres'] . ' ' . $d['apellidos']) ?></td>
                            <td><?= htmlspecialchars($d['creado_en']) ?></td>
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
