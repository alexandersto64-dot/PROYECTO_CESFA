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
require_once "../backend/config/admin_seguimiento.php";

$filtroAccion = trim((string) ($_GET["accion"] ?? ""));
$accionesDisponibles = auditoria_acciones($conexion);

if ($filtroAccion !== "" && !in_array($filtroAccion, $accionesDisponibles, true)) {
    $filtroAccion = ""; // solo se filtra por acciones que realmente existen
}

$registros = auditoria_listar($conexion, 200, $filtroAccion !== "" ? $filtroAccion : null);

$etiquetasAccion = [
    "CREAR" => ["texto" => "Creó", "clase" => "is-green"],
    "EDITAR" => ["texto" => "Editó", "clase" => "is-amber"],
    "ELIMINAR" => ["texto" => "Eliminó", "clase" => "is-red"],
    "RESTAURAR" => ["texto" => "Restauró", "clase" => "is-green"],
    "ACTIVAR" => ["texto" => "Activó", "clase" => "is-green"],
    "DESACTIVAR" => ["texto" => "Desactivó", "clase" => "is-amber"],
    "ASIGNAR" => ["texto" => "Asignó", "clase" => "is-green"],
    "QUITAR" => ["texto" => "Retiró", "clase" => "is-amber"],
    "CONFIRMAR" => ["texto" => "Confirmó", "clase" => "is-green"],
    "VER" => ["texto" => "Vio", "clase" => "is-green"],
    "DESCARGAR" => ["texto" => "Descargó", "clase" => "is-amber"],
    "LOGIN_OK" => ["texto" => "Inició sesión", "clase" => "is-green"],
    "LOGIN_FALLIDO" => ["texto" => "Intento de ingreso fallido", "clase" => "is-red"],
    "LOGIN_BLOQUEADO" => ["texto" => "Bloqueo por intentos fallidos", "clase" => "is-red"],
    "LOGOUT" => ["texto" => "Cerró sesión", "clase" => "is-amber"],
    "CAMBIAR_PASSWORD" => ["texto" => "Cambió su contraseña", "clase" => "is-amber"],
    "RESTABLECER_PASSWORD" => ["texto" => "Restableció su contraseña", "clase" => "is-amber"],
    "SOLICITAR_RESTABLECER" => ["texto" => "Pidió restablecer contraseña", "clase" => "is-amber"],
];

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoría · Panel de Administración - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <h1><?= icon("history") ?> Registro de auditoría</h1>
        <p>Panel de Administración · I.E.P. 88044 Abraham Valdelomar</p>
    </div>
    <div class="header-actions">
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <p class="placeholder-text">Últimos <?= count($registros) ?> eventos: quién hizo qué, cuándo y desde qué IP — cambios administrativos, inicios de sesión (incluidos los fallidos), descargas de boletas y cambios de contraseña.</p>

    <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:12px 0;">
        <label for="filtro_accion" class="placeholder-text">Filtrar por acción:</label>
        <select name="accion" id="filtro_accion" onchange="this.form.submit()">
            <option value="">Todas</option>
            <?php foreach ($accionesDisponibles as $a): ?>
                <option value="<?= htmlspecialchars($a) ?>" <?= $a === $filtroAccion ? "selected" : "" ?>>
                    <?= htmlspecialchars($etiquetasAccion[$a]["texto"] ?? $a) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn-mini">Filtrar</button></noscript>
    </form>

    <?php if (count($registros) === 0): ?>
        <p class="placeholder-text">Todavía no hay acciones registradas.</p>
    <?php else: ?>
        <?php $cols = "1.2fr 1.5fr 2.4fr .9fr 1.1fr"; ?>
        <div class="list-rows">
            <div class="list-rows-head" style="grid-template-columns: <?= $cols ?>;">
                <span>Quién</span>
                <span>Acción</span>
                <span>Detalle</span>
                <span>IP</span>
                <span>Fecha</span>
            </div>
            <?php foreach ($registros as $r): $et = $etiquetasAccion[$r["accion"]] ?? ["texto" => $r["accion"], "clase" => "is-amber"]; ?>
                <div class="list-row" style="grid-template-columns: <?= $cols ?>;">
                    <div><span class="list-cell-label">Quién</span><?= $r["nombres"] !== null ? htmlspecialchars($r["nombres"] . " " . $r["apellidos"]) : '<span class="placeholder-text">Visitante (sin cuenta)</span>' ?></div>
                    <div>
                        <span class="list-cell-label">Acción</span>
                        <span class="leyenda-punto <?= $et["clase"] ?>"></span><?= htmlspecialchars($et["texto"]) ?><?= $r["entidad"] !== "SESION" ? " · " . htmlspecialchars(ucfirst(strtolower(str_replace("_", " ", $r["entidad"])))) : "" ?>
                    </div>
                    <div><span class="list-cell-label">Detalle</span><?= htmlspecialchars($r["detalle"]) ?></div>
                    <div><span class="list-cell-label">IP</span><?= isset($r["ip"]) && $r["ip"] !== null ? htmlspecialchars($r["ip"]) : '<span class="placeholder-text">—</span>' ?></div>
                    <div><span class="list-cell-label">Fecha</span><?= htmlspecialchars($r["creado_en"]) ?></div>
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
