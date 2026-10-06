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
// ==========================================
// APROBAR / RECHAZAR UNA SOLICITUD
// ==========================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["id_solicitud"], $_POST["accion"])) {

    csrf_verificar();

    $id = (int)$_POST["id_solicitud"];
    $nuevoEstado = $_POST["accion"] === "aprobar" ? "APROBADA" : "RECHAZADA";

    $stmt = $conexion->prepare("UPDATE solicitudes SET estado = ? WHERE id_solicitud = ?");
    $stmt->execute([$nuevoEstado, $id]);

    header("Location: solicitudes.php");
    exit;

}

$solicitudes = $conexion->query("
    SELECT s.id_solicitud, s.tipo, s.descripcion, s.estado, s.creado_en,
           u.nombres, u.apellidos
    FROM solicitudes s
    INNER JOIN usuarios u ON s.id_usuario = u.id_usuario
    ORDER BY (s.estado = 'PENDIENTE') DESC, s.creado_en DESC
")->fetchAll();

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitudes · Panel del Subdirector - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">


<header>
    <div>
        <h1>Solicitudes</h1>
        <p>Panel del Subdirector · I.E.P. 88044 Abraham Valdelomar</p>
    </div>
    <div class="header-actions">
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <h2>Solicitudes de los usuarios (<?= count($solicitudes) ?>)</h2>

    <?php if (count($solicitudes) === 0): ?>
        <p class="placeholder-text">Todavía no hay solicitudes registradas.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Solicitante</th><th>Tipo</th><th>Descripción</th><th>Estado</th><th>Fecha</th><th>Acciones</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($solicitudes as $s): ?>
                        <tr>
                            <td><?= htmlspecialchars($s['nombres'] . ' ' . $s['apellidos']) ?></td>
                            <td><?= htmlspecialchars($s['tipo']) ?></td>
                            <td><?= htmlspecialchars($s['descripcion'] ?? '—') ?></td>
                            <td>
                                <span class="status-badge status-<?= strtolower($s['estado']) ?>">
                                    <?= htmlspecialchars($s['estado']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($s['creado_en']) ?></td>
                            <td>
                                <?php if ($s['estado'] === 'PENDIENTE'): ?>
                                    <div class="row-actions">
                                        <form method="POST">
                                        <?= csrf_field() ?>
                                            <input type="hidden" name="id_solicitud" value="<?= (int)$s['id_solicitud'] ?>">
                                            <input type="hidden" name="accion" value="aprobar">
                                            <button type="submit" class="btn-mini btn-mini-approve">Aprobar</button>
                                        </form>
                                        <form method="POST">
                                        <?= csrf_field() ?>
                                            <input type="hidden" name="id_solicitud" value="<?= (int)$s['id_solicitud'] ?>">
                                            <input type="hidden" name="accion" value="rechazar">
                                            <button type="submit" class="btn-mini btn-mini-reject">Rechazar</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="placeholder-text">Resuelta</span>
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
