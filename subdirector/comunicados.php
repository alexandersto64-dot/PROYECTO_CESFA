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
require_once "../backend/config/notificaciones.php";
require_once "../backend/config/subdireccion_seguimiento.php";
[$mensaje, $mensajeTipo] = flash_get();

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verificar();

    $titulo = trim($_POST["titulo"] ?? "");
    $contenido = trim($_POST["contenido"] ?? "");

    if ($titulo === "" || $contenido === "") {

        $mensaje = "El título y el contenido son obligatorios.";
        $mensajeTipo = "error";

    } else {

        $stmt = $conexion->prepare("INSERT INTO comunicados (titulo, contenido, id_usuario) VALUES (?, ?, ?)");
        $stmt->execute([$titulo, $contenido, $_SESSION["id_usuario"]]);

        // Avisa a todos los profesores activos (misma campana de
        // notificaciones de siempre) para que el comunicado no dependa
        // de que entren a revisar por su cuenta — y para que la
        // confirmación de lectura de abajo tenga sentido: sin este
        // aviso, nadie tendría motivo para visitar profesor/comunicados.php.
        $profesoresActivos = $conexion->query("
            SELECT u.id_usuario
            FROM profesores p
            INNER JOIN usuarios u ON u.id_usuario = p.id_usuario
            WHERE u.estado = 'ACTIVO'
        ")->fetchAll();

        foreach ($profesoresActivos as $pa) {
            notificar_crear($conexion, (int) $pa["id_usuario"], "NUEVO_COMUNICADO", "Nuevo comunicado: $titulo", "comunicados.php");
        }

        $mensaje = "Comunicado publicado correctamente.";
        $mensajeTipo = "success";

    }

    // Evita el reenvío del formulario al recargar (PRG)
    if ($mensaje !== null) {
        flash_set($mensaje, $mensajeTipo);
    }
    header("Location: comunicados.php");
    exit;

}

$comunicados = $conexion->query("
    SELECT c.id_comunicado, c.titulo, c.contenido, c.creado_en,
           u.nombres, u.apellidos
    FROM comunicados c
    INNER JOIN usuarios u ON c.id_usuario = u.id_usuario
    ORDER BY c.creado_en DESC
")->fetchAll();

$conteoLecturas = comunicados_conteo_lecturas($conexion);
$totalProfesoresActivos = comunicados_total_profesores_activos($conexion);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comunicados · Panel del Subdirector - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">


<header>
    <div>
        <h1>Comunicados</h1>
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
        <h3>Publicar comunicado</h3>
        <div class="field">
            <label for="titulo">Título</label>
            <input type="text" name="titulo" id="titulo" placeholder="Ej. Suspensión de clases" required>
        </div>
        <div class="field">
            <label for="contenido">Contenido</label>
            <textarea name="contenido" id="contenido" placeholder="Escribe el comunicado…" required></textarea>
        </div>
        <button type="submit" class="btn-submit">Publicar</button>
    </form>

    <h2>Comunicados publicados (<?= count($comunicados) ?>)</h2>

    <?php if (count($comunicados) === 0): ?>
        <p class="placeholder-text">Todavía no hay comunicados publicados.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Título</th><th>Contenido</th><th>Publicado por</th><th>Fecha</th><th>Lecturas</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($comunicados as $c): ?>
                        <?php $leidoPor = $conteoLecturas[(int) $c["id_comunicado"]] ?? 0; ?>
                        <tr>
                            <td><?= htmlspecialchars($c['titulo']) ?></td>
                            <td><?= htmlspecialchars($c['contenido']) ?></td>
                            <td><?= htmlspecialchars($c['nombres'] . ' ' . $c['apellidos']) ?></td>
                            <td><?= htmlspecialchars($c['creado_en']) ?></td>
                            <td>
                                <details>
                                    <summary class="lecturas-resumen"><?= icon("check-circle") ?> <?= $leidoPor ?>/<?= $totalProfesoresActivos ?> profesores</summary>
                                    <div class="lecturas-detalle">
                                        <?php $lectores = comunicado_lectores($conexion, (int) $c["id_comunicado"]); ?>
                                        <?php if (count($lectores) === 0): ?>
                                            Nadie lo ha visto todavía.
                                        <?php else: ?>
                                            <ul>
                                                <?php foreach ($lectores as $l): ?>
                                                    <li><?= htmlspecialchars($l["nombres"] . " " . $l["apellidos"]) ?> — <?= htmlspecialchars($l["leido_en"]) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                </details>
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
