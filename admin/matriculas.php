<?php

session_start();
require_once __DIR__ . "/../backend/config/sesion_inactividad.php";


// ==========================================
// 1. COMPROBAR SESIÓN
// ==========================================

if (!isset($_SESSION["id_usuario"])) {

    header("Location: ../login.html");
    exit;

}


// ==========================================
// 2. COMPROBAR ROL
// ==========================================

if ($_SESSION["rol"] !== "ADMIN") {

    die("Acceso no autorizado.");

}


// ==========================================
// 3. CONECTAR A MYSQL
// ==========================================

require_once "../backend/config/database.php";
require_once "../backend/config/admin_seguimiento.php";
require_once "../backend/config/seguridad_csrf.php";
require_once "../backend/config/flash.php";

[$mensaje, $mensajeTipo] = flash_get();


// ==========================================
// 4. DATOS PARA LOS FORMULARIOS
// ==========================================

$alumnos = $conexion->query("SELECT id_alumno, nombres, apellidos, dni FROM alumnos WHERE estado = 'ACTIVO' ORDER BY apellidos, nombres")->fetchAll();
$gradosSecciones = $conexion->query("SELECT id_grado_seccion, nivel, grado, seccion, nombre FROM grados_secciones ORDER BY nivel, grado, seccion")->fetchAll();
$periodos = $conexion->query("SELECT id_periodo, nombre FROM periodos_academicos ORDER BY id_periodo DESC")->fetchAll();

$alumnoPreseleccionado = isset($_GET["alumno"]) ? (int)$_GET["alumno"] : 0;


// ==========================================
// 5. ACCIONES (crear / editar / eliminar)
// ==========================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["accion"])) {

    csrf_verificar();

    $accion = $_POST["accion"];

    if ($accion === "crear") {

        $id_alumno = (int)($_POST["id_alumno"] ?? 0);
        $id_grado_seccion = (int)($_POST["id_grado_seccion"] ?? 0);
        $id_periodo = (int)($_POST["id_periodo"] ?? 0);
        $fecha_matricula = trim($_POST["fecha_matricula"] ?? "");

        if ($id_alumno === 0 || $id_grado_seccion === 0 || $id_periodo === 0 || $fecha_matricula === "") {

            $mensaje = "Debe completar todos los campos para registrar la matrícula.";
            $mensajeTipo = "error";

        } else {

            try {

                $stmt = $conexion->prepare(
                    "INSERT INTO matriculas (id_alumno, id_grado_seccion, id_periodo, estado, fecha_matricula)
                     VALUES (?, ?, ?, 'ACTIVA', ?)"
                );
                $stmt->execute([$id_alumno, $id_grado_seccion, $id_periodo, $fecha_matricula]);
                auditoria_evento($conexion, (int) $_SESSION["id_usuario"], "CREAR", "MATRICULA", (int) $conexion->lastInsertId(), "Matriculó al alumno #$id_alumno (aula #$id_grado_seccion, período #$id_periodo)");

                $mensaje = "Matrícula registrada correctamente.";
                $mensajeTipo = "success";

            } catch (PDOException $e) {

                $mensaje = "No se pudo registrar: el alumno ya tiene una matrícula en ese período.";
                $mensajeTipo = "error";

            }

        }

    } elseif ($accion === "cambiar_estado") {

        $id_matricula = (int)($_POST["id_matricula"] ?? 0);
        $nuevoEstado = $_POST["nuevo_estado"] ?? "";

        if (!in_array($nuevoEstado, ["ACTIVA", "RETIRADO", "TRASLADADO"], true)) {

            $mensaje = "Estado no válido.";
            $mensajeTipo = "error";

        } else {

            $stmt = $conexion->prepare("UPDATE matriculas SET estado = ? WHERE id_matricula = ?");
            $stmt->execute([$nuevoEstado, $id_matricula]);
            auditoria_evento($conexion, (int) $_SESSION["id_usuario"], "EDITAR", "MATRICULA", $id_matricula, "Cambió el estado de la matrícula #$id_matricula a $nuevoEstado");

            $mensaje = "Estado de la matrícula actualizado.";
            $mensajeTipo = "success";

        }

    } elseif ($accion === "eliminar") {

        $id_matricula = (int)($_POST["id_matricula"] ?? 0);

        $stmt = $conexion->prepare("DELETE FROM matriculas WHERE id_matricula = ?");
        $stmt->execute([$id_matricula]);
        auditoria_evento($conexion, (int) $_SESSION["id_usuario"], "ELIMINAR", "MATRICULA", $id_matricula, "Eliminó la matrícula #$id_matricula");

        $mensaje = "Matrícula eliminada correctamente.";
        $mensajeTipo = "success";

    }

    if ($mensaje !== null) {
        flash_set($mensaje, $mensajeTipo);
    }
    header("Location: matriculas.php" . ($alumnoPreseleccionado ? "?alumno=" . $alumnoPreseleccionado : ""));
    exit;

}


// ==========================================
// 6. OBTENER TODAS LAS MATRÍCULAS
// ==========================================

$sql = "
    SELECT
        m.id_matricula, m.estado, m.fecha_matricula,
        a.id_alumno, a.nombres, a.apellidos, a.dni,
        gs.nombre AS grado_seccion, gs.nivel,
        p.nombre AS periodo

    FROM matriculas m

    INNER JOIN alumnos a ON a.id_alumno = m.id_alumno
    INNER JOIN grados_secciones gs ON gs.id_grado_seccion = m.id_grado_seccion
    INNER JOIN periodos_academicos p ON p.id_periodo = m.id_periodo
";

$params = [];

if ($alumnoPreseleccionado > 0) {
    $sql .= " WHERE a.id_alumno = ? ";
    $params[] = $alumnoPreseleccionado;
}

$sql .= " ORDER BY m.creado_en DESC ";

$stmt = $conexion->prepare($sql);
$stmt->execute($params);
$matriculas = $stmt->fetchAll();

?>

<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Matrículas · Panel de Administración - I.E.P. 88044 Abraham Valdelomar
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
        <h1>Gestionar matrículas</h1>
        <p>Panel de Administración · I.E.P. 88044 Abraham Valdelomar</p>
    </div>

    <div class="header-actions">
        <a href="alumnos.php" class="btn-secondary">← Volver a alumnos</a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>

</header>


<main>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <?php if (empty($periodos)): ?>

        <div class="panel-alert panel-alert-error">
            No hay períodos académicos registrados. Crea uno primero para poder matricular alumnos.
        </div>

    <?php elseif (empty($alumnos)): ?>

        <div class="panel-alert panel-alert-error">
            No hay alumnos activos registrados. <a href="alumnos.php">Registra un alumno primero</a>.
        </div>

    <?php else: ?>

        <form class="panel-form" method="POST">
        <?= csrf_field() ?>
            <h3>Registrar matrícula</h3>
            <input type="hidden" name="accion" value="crear">
            <div class="form-row">
                <div class="field">
                    <label for="id_alumno">Alumno</label>
                    <select name="id_alumno" id="id_alumno" required>
                        <option value="">Seleccione…</option>
                        <?php foreach ($alumnos as $a): ?>
                            <option value="<?= (int)$a["id_alumno"] ?>" <?= (int)$a["id_alumno"] === $alumnoPreseleccionado ? "selected" : "" ?>>
                                <?= htmlspecialchars($a["apellidos"]) ?>, <?= htmlspecialchars($a["nombres"]) ?> (DNI <?= htmlspecialchars($a["dni"]) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="id_periodo">Período académico</label>
                    <select name="id_periodo" id="id_periodo" required>
                        <option value="">Seleccione…</option>
                        <?php foreach ($periodos as $p): ?>
                            <option value="<?= (int)$p["id_periodo"] ?>"><?= htmlspecialchars($p["nombre"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php
            $opcionesCascada = array_map(fn($gs) => [
                "id" => (int) $gs["id_grado_seccion"], "nivel" => $gs["nivel"],
                "grado" => (int) $gs["grado"], "seccion" => $gs["seccion"],
            ], $gradosSecciones);
            ?>
            <div data-cascada-grados
                 data-name="id_grado_seccion"
                 data-opciones="<?= htmlspecialchars(json_encode($opcionesCascada), ENT_QUOTES) ?>"></div>
            <div class="form-row">
                <div class="field">
                    <label for="fecha_matricula">Fecha de matrícula</label>
                    <input type="date" name="fecha_matricula" id="fecha_matricula" value="<?= date("Y-m-d") ?>" required>
                </div>
            </div>
            <button type="submit" class="btn-submit">Registrar matrícula</button>
        </form>

    <?php endif; ?>

    <h2>
        Matrículas registradas (<?= count($matriculas) ?>)
        <?php if ($alumnoPreseleccionado): ?>
            <a href="matriculas.php" class="btn-mini">Ver todas</a>
        <?php endif; ?>
    </h2>

    <?php if (count($matriculas) === 0): ?>

        <p class="placeholder-text">Todavía no hay matrículas registradas.</p>

    <?php else: ?>

        <?php $cols = "1.8fr 1.2fr 1.1fr .9fr .8fr 2.6fr"; ?>
        <div class="list-rows">
            <div class="list-rows-head" style="grid-template-columns: <?= $cols ?>;">
                <span>Alumno</span>
                <span>Grado / Sección</span>
                <span>Período</span>
                <span>Fecha</span>
                <span>Estado</span>
                <span>Acciones</span>
            </div>
            <?php foreach ($matriculas as $m): ?>
                <div class="list-row" style="grid-template-columns: <?= $cols ?>;">
                    <div>
                        <span class="list-cell-label">Alumno</span>
                        <?= htmlspecialchars($m["nombres"]) ?>
                        <?= htmlspecialchars($m["apellidos"]) ?>
                    </div>
                    <div>
                        <span class="list-cell-label">Grado</span>
                        <?= htmlspecialchars(ucfirst(strtolower($m["nivel"]))) ?> · <?= htmlspecialchars($m["grado_seccion"]) ?>
                    </div>
                    <div><span class="list-cell-label">Período</span><?= htmlspecialchars($m["periodo"]) ?></div>
                    <div><span class="list-cell-label">Fecha</span><?= htmlspecialchars($m["fecha_matricula"]) ?></div>
                    <div>
                        <span class="list-cell-label">Estado</span>
                        <span class="status-badge status-<?= $m["estado"] === "ACTIVA" ? "activo" : "inactivo" ?>">
                            <?= htmlspecialchars($m["estado"]) ?>
                        </span>
                    </div>
                    <div>
                        <div class="row-actions">
                            <?php if ($m["estado"] !== "RETIRADO"): ?>
                                <form method="POST">
                                <?= csrf_field() ?>
                                    <input type="hidden" name="id_matricula" value="<?= (int)$m["id_matricula"] ?>">
                                    <input type="hidden" name="accion" value="cambiar_estado">
                                    <input type="hidden" name="nuevo_estado" value="RETIRADO">
                                    <button type="submit" class="btn-mini btn-mini-reject">Marcar retirado</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($m["estado"] !== "ACTIVA"): ?>
                                <form method="POST">
                                <?= csrf_field() ?>
                                    <input type="hidden" name="id_matricula" value="<?= (int)$m["id_matricula"] ?>">
                                    <input type="hidden" name="accion" value="cambiar_estado">
                                    <input type="hidden" name="nuevo_estado" value="ACTIVA">
                                    <button type="submit" class="btn-mini btn-mini-approve">Reactivar</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" onsubmit="return confirm('¿Eliminar esta matrícula? Esta acción no se puede deshacer.');">
                            <?= csrf_field() ?>
                                <input type="hidden" name="id_matricula" value="<?= (int)$m["id_matricula"] ?>">
                                <input type="hidden" name="accion" value="eliminar">
                                <button type="submit" class="btn-mini btn-mini-reject">Eliminar</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</main>


</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>
<script src="../js/cascada-grados.js?v=1"></script>

</body>

</html>
