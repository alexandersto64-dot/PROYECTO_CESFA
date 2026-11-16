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
require_once "../backend/partials/icons.php";

$mensaje = null;
$mensajeTipo = null;
$tablasListas = true;

// Fase 2: si todavía no se importó fase2_programas_modulos.sql, se avisa
// en pantalla en vez de mostrar un error técnico.
try {
    $conexion->query("SELECT 1 FROM programas LIMIT 1");
    $conexion->query("SELECT 1 FROM ciclos LIMIT 1");
    $conexion->query("SELECT 1 FROM modulos LIMIT 1");
} catch (PDOException $e) {
    $tablasListas = false;
}

function texto_limpio(string $v, int $max): string
{
    return mb_substr(trim($v), 0, $max, "UTF-8");
}

if ($tablasListas && $_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verificar();
    $accion = $_POST["accion"] ?? "";
    $idUsuario = (int) $_SESSION["id_usuario"];

    try {

        if ($accion === "programa_nuevo") {
            $nombre = texto_limpio($_POST["nombre"] ?? "", 120);
            $duracion = texto_limpio($_POST["duracion"] ?? "", 80);
            if ($nombre === "") {
                throw new RuntimeException("Escribe el nombre del programa.");
            }
            $conexion->prepare("INSERT INTO programas (nombre, duracion) VALUES (?, ?)")
                ->execute([$nombre, $duracion !== "" ? $duracion : null]);
            auditoria_registrar($conexion, $idUsuario, "CREAR", "PROGRAMA", (int) $conexion->lastInsertId(), "Creó el programa $nombre");
            $mensaje = "Programa creado."; $mensajeTipo = "success";

        } elseif ($accion === "programa_editar") {
            $id = (int) ($_POST["id_programa"] ?? 0);
            $duracion = texto_limpio($_POST["duracion"] ?? "", 80);
            $estado = ($_POST["estado"] ?? "") === "INACTIVO" ? "INACTIVO" : "ACTIVO";
            $conexion->prepare("UPDATE programas SET duracion = ?, estado = ? WHERE id_programa = ?")
                ->execute([$duracion !== "" ? $duracion : null, $estado, $id]);
            auditoria_registrar($conexion, $idUsuario, "EDITAR", "PROGRAMA", $id, "Actualizó duración/estado del programa");
            $mensaje = "Programa actualizado."; $mensajeTipo = "success";

        } elseif ($accion === "ciclo_nuevo") {
            $id = (int) ($_POST["id_programa"] ?? 0);
            $nombre = texto_limpio($_POST["nombre"] ?? "", 80);
            if ($nombre === "") {
                throw new RuntimeException("Escribe el nombre del ciclo.");
            }
            $orden = (int) $conexion->query("SELECT COALESCE(MAX(orden),0)+1 FROM ciclos WHERE id_programa = " . $id)->fetchColumn();
            $conexion->prepare("INSERT INTO ciclos (id_programa, nombre, orden) VALUES (?, ?, ?)")
                ->execute([$id, $nombre, $orden]);
            auditoria_registrar($conexion, $idUsuario, "CREAR", "CICLO", (int) $conexion->lastInsertId(), "Creó el ciclo $nombre");
            $mensaje = "Ciclo agregado."; $mensajeTipo = "success";

        } elseif ($accion === "modulo_nuevo") {
            $id = (int) ($_POST["id_ciclo"] ?? 0);
            $nombre = texto_limpio($_POST["nombre"] ?? "", 150);
            $horasTxt = trim($_POST["horas"] ?? "");
            if ($nombre === "") {
                throw new RuntimeException("Escribe el nombre del módulo.");
            }
            if ($horasTxt !== "" && (!ctype_digit($horasTxt) || (int) $horasTxt > 5000)) {
                throw new RuntimeException("Las horas deben ser un número entero (máximo 5000) o quedar vacías.");
            }
            $orden = (int) $conexion->query("SELECT COALESCE(MAX(orden),0)+1 FROM modulos WHERE id_ciclo = " . $id)->fetchColumn();
            $conexion->prepare("INSERT INTO modulos (id_ciclo, nombre, horas, orden) VALUES (?, ?, ?, ?)")
                ->execute([$id, $nombre, $horasTxt !== "" ? (int) $horasTxt : null, $orden]);
            auditoria_registrar($conexion, $idUsuario, "CREAR", "MODULO", (int) $conexion->lastInsertId(), "Creó el módulo $nombre");
            $mensaje = "Módulo agregado."; $mensajeTipo = "success";

        } elseif ($accion === "ciclo_eliminar") {
            $id = (int) ($_POST["id_ciclo"] ?? 0);
            $n = (int) $conexion->query("SELECT COUNT(*) FROM modulos WHERE id_ciclo = " . $id)->fetchColumn();
            if ($n > 0) {
                throw new RuntimeException("Primero elimina los módulos de este ciclo.");
            }
            $conexion->prepare("DELETE FROM ciclos WHERE id_ciclo = ?")->execute([$id]);
            auditoria_registrar($conexion, $idUsuario, "ELIMINAR", "CICLO", $id, "Eliminó un ciclo vacío");
            $mensaje = "Ciclo eliminado."; $mensajeTipo = "success";

        } elseif ($accion === "modulo_eliminar") {
            $id = (int) ($_POST["id_modulo"] ?? 0);
            $conexion->prepare("DELETE FROM modulos WHERE id_modulo = ?")->execute([$id]);
            auditoria_registrar($conexion, $idUsuario, "ELIMINAR", "MODULO", $id, "Eliminó un módulo");
            $mensaje = "Módulo eliminado."; $mensajeTipo = "success";
        }

    } catch (RuntimeException $e) {
        $mensaje = $e->getMessage(); $mensajeTipo = "error";
    } catch (PDOException $e) {
        $mensaje = ((int) ($e->errorInfo[1] ?? 0) === 1062)
            ? "Ya existe un registro con ese nombre."
            : "No se pudo guardar. Intenta de nuevo.";
        $mensajeTipo = "error";
    }
}

$programas = [];
if ($tablasListas) {
    $programas = $conexion->query("SELECT id_programa, nombre, duracion, estado FROM programas ORDER BY nombre")->fetchAll();
    $ciclos = $conexion->query("SELECT id_ciclo, id_programa, nombre FROM ciclos ORDER BY id_programa, orden, id_ciclo")->fetchAll();
    $modulos = $conexion->query("SELECT id_modulo, id_ciclo, nombre, horas FROM modulos ORDER BY id_ciclo, orden, id_modulo")->fetchAll();
    $ciclosPorPrograma = [];
    foreach ($ciclos as $c) { $ciclosPorPrograma[$c["id_programa"]][] = $c; }
    $modulosPorCiclo = [];
    foreach ($modulos as $m) { $modulosPorCiclo[$m["id_ciclo"]][] = $m; }
}

function h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, "UTF-8"); }

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Programas y módulos · Panel de Administración - CESFA</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">

<header>
    <div>
        <h1>Programas y módulos</h1>
        <p>Panel de Administración · CESFA</p>
    </div>
    <div class="header-actions">
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

<?php if ($mensaje): ?>
    <div class="panel-alert panel-alert-<?= h($mensajeTipo) ?>"><?= h($mensaje) ?></div>
<?php endif; ?>

<?php if (!$tablasListas): ?>
    <div class="panel-alert panel-alert-error">
        Faltan las tablas de la Fase 2. Importa <code>backend/config/fase2_programas_modulos.sql</code> en la base de datos <code>cesfa</code> y recarga esta página.
    </div>
<?php else: ?>

    <p class="placeholder-text">Aquí se registran los programas (carreras), sus ciclos y módulos. Duración, ciclos, módulos y horas se llenan solo con datos confirmados por CESFA.</p>

    <form class="panel-form" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="programa_nuevo">
        <h3>Nuevo programa</h3>
        <div class="form-row">
            <div class="field"><label for="p_nombre">Nombre</label>
                <input type="text" name="nombre" id="p_nombre" maxlength="120" required></div>
            <div class="field"><label for="p_duracion">Duración (opcional)</label>
                <input type="text" name="duracion" id="p_duracion" maxlength="80"></div>
        </div>
        <button type="submit" class="btn-submit">Crear programa</button>
    </form>

    <?php foreach ($programas as $p): $idp = (int) $p["id_programa"]; ?>
        <div class="panel-form" style="margin-top:18px">
            <h3><?= h($p["nombre"]) ?> <small>(<?= h($p["estado"]) ?>)</small></h3>

            <form method="POST" class="form-row">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="programa_editar">
                <input type="hidden" name="id_programa" value="<?= $idp ?>">
                <div class="field"><label>Duración</label>
                    <input type="text" name="duracion" maxlength="80" value="<?= h($p["duracion"] ?? "") ?>" placeholder="Pendiente"></div>
                <div class="field"><label>Estado</label>
                    <select name="estado">
                        <option value="ACTIVO" <?= $p["estado"] === "ACTIVO" ? "selected" : "" ?>>Activo</option>
                        <option value="INACTIVO" <?= $p["estado"] === "INACTIVO" ? "selected" : "" ?>>Inactivo</option>
                    </select></div>
                <div class="field"><button type="submit" class="btn-mini">Guardar</button></div>
            </form>

            <?php foreach ($ciclosPorPrograma[$idp] ?? [] as $c): $idc = (int) $c["id_ciclo"]; ?>
                <h4 style="margin-top:16px">Ciclo: <?= h($c["nombre"]) ?></h4>
                <?php if (!empty($modulosPorCiclo[$idc])): ?>
                    <div style="overflow-x:auto">
                    <table class="data-table">
                        <thead><tr><th>Módulo</th><th>Horas</th><th>Acciones</th></tr></thead>
                        <tbody>
                        <?php foreach ($modulosPorCiclo[$idc] as $m): ?>
                            <tr>
                                <td><?= h($m["nombre"]) ?></td>
                                <td><?= $m["horas"] !== null ? (int) $m["horas"] : "—" ?></td>
                                <td>
                                    <form method="POST" onsubmit="return confirm('¿Eliminar este módulo?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="accion" value="modulo_eliminar">
                                        <input type="hidden" name="id_modulo" value="<?= (int) $m["id_modulo"] ?>">
                                        <button type="submit" class="btn-mini btn-mini-reject"><?= icon("trash") ?> Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php else: ?>
                    <p class="placeholder-text">Sin módulos todavía.</p>
                <?php endif; ?>

                <form method="POST" class="form-row">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="modulo_nuevo">
                    <input type="hidden" name="id_ciclo" value="<?= $idc ?>">
                    <div class="field"><label>Nuevo módulo</label>
                        <input type="text" name="nombre" maxlength="150" required></div>
                    <div class="field"><label>Horas (opcional)</label>
                        <input type="number" name="horas" min="0" max="5000"></div>
                    <div class="field"><button type="submit" class="btn-mini">Agregar módulo</button></div>
                </form>
                <form method="POST" onsubmit="return confirm('¿Eliminar este ciclo?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="ciclo_eliminar">
                    <input type="hidden" name="id_ciclo" value="<?= $idc ?>">
                    <button type="submit" class="btn-mini btn-mini-reject"><?= icon("trash") ?> Eliminar ciclo</button>
                </form>
            <?php endforeach; ?>

            <form method="POST" class="form-row" style="margin-top:16px">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="ciclo_nuevo">
                <input type="hidden" name="id_programa" value="<?= $idp ?>">
                <div class="field"><label>Nuevo ciclo</label>
                    <input type="text" name="nombre" maxlength="80" required></div>
                <div class="field"><button type="submit" class="btn-mini">Agregar ciclo</button></div>
            </form>
        </div>
    <?php endforeach; ?>

<?php endif; ?>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>

</body>
</html>
