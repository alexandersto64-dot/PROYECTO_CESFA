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
require_once "../backend/config/flash.php";
require_once "../backend/config/admin_seguimiento.php";
[$mensaje, $mensajeTipo] = flash_get();

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verificar();

    $accion = $_POST["accion"] ?? "crear";

    if ($accion === "crear") {

        $nombre = trim($_POST["nombre"] ?? "");
        $nivel = strtoupper(trim($_POST["nivel"] ?? ""));

        if ($nombre === "" || !in_array($nivel, ["PRIMARIA", "SECUNDARIA"], true)) {

            $mensaje = "Indique el nivel y el nombre del curso.";
            $mensajeTipo = "error";

        } else {

            try {

                $stmt = $conexion->prepare("INSERT INTO cursos (nombre, nivel) VALUES (?, ?)");
                $stmt->execute([$nombre, $nivel]);

                auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], "CREAR", "CURSO", (int) $conexion->lastInsertId(), "Creó el curso \"$nombre\" ($nivel)");

                $mensaje = "Curso agregado correctamente.";
                $mensajeTipo = "success";

            } catch (PDOException $e) {

                $mensaje = "No se pudo agregar el curso (¿ya existe uno con ese nombre en " . ucfirst(strtolower($nivel)) . "?).";
                $mensajeTipo = "error";

            }

        }

    } elseif ($accion === "guardar_edicion") {

        $id_curso = (int)($_POST["id_curso"] ?? 0);
        $nombre = trim($_POST["nombre"] ?? "");

        if ($id_curso === 0 || $nombre === "") {

            $mensaje = "Debe indicar el nombre del curso.";
            $mensajeTipo = "error";

        } else {

            try {

                $stmt = $conexion->prepare("UPDATE cursos SET nombre = ? WHERE id_curso = ?");
                $stmt->execute([$nombre, $id_curso]);

                auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], "EDITAR", "CURSO", $id_curso, "Editó el curso \"$nombre\"");

                $mensaje = "Curso actualizado correctamente.";
                $mensajeTipo = "success";

            } catch (PDOException $e) {

                $mensaje = "No se pudo actualizar el curso (¿ya existe uno con ese nombre en el mismo nivel?).";
                $mensajeTipo = "error";

            }

        }

        if ($mensaje !== null) {
            flash_set($mensaje, $mensajeTipo);
        }
        header("Location: cursos.php");
        exit;

    } elseif ($accion === "eliminar") {

        $id_curso = (int)($_POST["id_curso"] ?? 0);

        papelera_mover($conexion, "cursos", "id_curso", $id_curso);

        auditoria_registrar($conexion, (int) $_SESSION["id_usuario"], "ELIMINAR", "CURSO", $id_curso, "Envió a la papelera el curso #$id_curso");

        $mensaje = "Curso movido a la papelera. Puede restaurarlo desde Admin › Papelera.";
        $mensajeTipo = "success";

    }

    if ($accion !== "guardar_edicion") {
        if ($mensaje !== null) {
            flash_set($mensaje, $mensajeTipo);
        }
        header("Location: cursos.php");
        exit;
    }

}

$cursoEditar = null;

if (isset($_GET["editar"]) && (int)$_GET["editar"] > 0) {

    $stmt = $conexion->prepare("SELECT id_curso, nombre, nivel FROM cursos WHERE id_curso = ?");
    $stmt->execute([(int)$_GET["editar"]]);
    $cursoEditar = $stmt->fetch();

}

$cursos = $conexion->query("SELECT id_curso, nombre, nivel FROM cursos WHERE eliminado_en IS NULL ORDER BY nivel, nombre")->fetchAll();

// Un mismo nombre puede existir en Primaria y en Secundaria (son cursos distintos,
// con sus propias competencias y profesores), así que se muestran separados por nivel.
$cursosPorNivel = ["PRIMARIA" => [], "SECUNDARIA" => []];
foreach ($cursos as $c) {
    $cursosPorNivel[$c["nivel"]][] = $c;
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cursos · Panel de Administración - I.E.P. 88044 Abraham Valdelomar</title>
    <link rel="stylesheet" href="../css/styles.css?v=202609211855">
    <link rel="stylesheet" href="../css/dashboard.css?v=202609300252">
</head>
<body>

<div class="app-shell">
<?php $currentFile = basename(__FILE__); include __DIR__ . "/../backend/partials/sidebar.php"; ?>
<div class="app-content">


<header>
    <div>
        <h1>Gestionar cursos</h1>
        <p>Panel de Administración · I.E.P. 88044 Abraham Valdelomar</p>
    </div>
    <div class="header-actions">
        <a href="papelera.php" class="btn-secondary"><?= icon("trash") ?> Papelera</a>
        <a href="../backend/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<main>

    <?php if ($mensaje): ?>
        <div class="panel-alert panel-alert-<?= $mensajeTipo ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <?php if ($cursoEditar): ?>
        <form class="panel-form" method="POST">
        <?= csrf_field() ?>
            <h3>Editar curso <span class="role-badge"><?= htmlspecialchars(ucfirst(strtolower($cursoEditar["nivel"]))) ?></span></h3>
            <input type="hidden" name="accion" value="guardar_edicion">
            <input type="hidden" name="id_curso" value="<?= (int)$cursoEditar["id_curso"] ?>">
            <div class="field">
                <label for="nombre">Nombre del curso</label>
                <input type="text" name="nombre" id="nombre" value="<?= htmlspecialchars($cursoEditar["nombre"]) ?>" required>
            </div>
            <div class="row-actions">
                <button type="submit" class="btn-submit">Guardar cambios</button>
                <a href="cursos.php" class="btn-secondary">Cancelar</a>
            </div>
        </form>
    <?php else: ?>
        <form class="panel-form" method="POST">
        <?= csrf_field() ?>
            <h3>Agregar curso</h3>
            <input type="hidden" name="accion" value="crear">
            <div class="form-row">
                <div class="field">
                    <label for="nivel">Nivel</label>
                    <select name="nivel" id="nivel" required>
                        <option value="">Seleccione…</option>
                        <option value="PRIMARIA">Primaria</option>
                        <option value="SECUNDARIA">Secundaria</option>
                    </select>
                </div>
                <div class="field">
                    <label for="nombre">Nombre del curso</label>
                    <input type="text" name="nombre" id="nombre" placeholder="Ej. Comunicación" required>
                </div>
            </div>
            <button type="submit" class="btn-submit">Guardar curso</button>
        </form>
    <?php endif; ?>

    <h2>Cursos registrados (<?= count($cursos) ?>)</h2>

    <?php if (count($cursos) === 0): ?>
        <p class="placeholder-text">Todavía no hay cursos registrados.</p>
    <?php else: ?>

        <div class="panel-form" style="margin-bottom:16px">
            <div class="field" style="max-width:260px">
                <label for="filtro-nivel-cursos">Ver cursos de</label>
                <select id="filtro-nivel-cursos">
                    <option value="">Primaria y Secundaria</option>
                    <option value="PRIMARIA">Solo Primaria</option>
                    <option value="SECUNDARIA">Solo Secundaria</option>
                </select>
            </div>
        </div>

        <?php foreach ($cursosPorNivel as $nivelGrupo => $cursosGrupo): ?>
            <section class="grupo-cursos-nivel" data-nivel="<?= htmlspecialchars($nivelGrupo) ?>">
                <h3><?= htmlspecialchars(ucfirst(strtolower($nivelGrupo))) ?> (<?= count($cursosGrupo) ?>)</h3>
                <?php if (count($cursosGrupo) === 0): ?>
                    <p class="placeholder-text">No hay cursos de <?= htmlspecialchars(strtolower($nivelGrupo)) ?>.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead><tr><th>Curso</th><th>Nivel</th><th>Acciones</th></tr></thead>
                            <tbody>
                                <?php foreach ($cursosGrupo as $c): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($c['nombre']) ?></td>
                                        <td><span class="role-badge"><?= htmlspecialchars(ucfirst(strtolower($c['nivel']))) ?></span></td>
                                        <td>
                                            <div class="row-actions">
                                                <a href="cursos.php?editar=<?= (int)$c['id_curso'] ?>" class="btn-mini">Editar</a>
                                                <form method="POST" onsubmit="return confirm('¿Enviar este curso a la papelera? Podrá restaurarlo luego desde Admin › Papelera.');">
                                                <?= csrf_field() ?>
                                                    <input type="hidden" name="accion" value="eliminar">
                                                    <input type="hidden" name="id_curso" value="<?= (int)$c['id_curso'] ?>">
                                                    <button type="submit" class="btn-mini btn-mini-reject"><?= icon("trash") ?> Eliminar</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

    <?php endif; ?>

</main>

</div><!-- /.app-content -->
</div><!-- /.app-shell -->

<script src="../js/panel.js?v=202609211901"></script>
<script>
// Ver solo los cursos de un nivel (esconde secciones ya renderizadas).
(function () {
    var f = document.getElementById("filtro-nivel-cursos");
    if (!f) return;
    f.addEventListener("change", function () {
        Array.prototype.forEach.call(document.querySelectorAll(".grupo-cursos-nivel"), function (sec) {
            sec.style.display = (!f.value || sec.getAttribute("data-nivel") === f.value) ? "" : "none";
        });
    });
})();
</script>

</body>
</html>
